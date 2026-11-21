<?php
declare(strict_types=1);

namespace Micx\Vcs;

use Micx\Vcs\Exception\ErrorCode;
use Micx\Vcs\Exception\InternalException;
use Micx\Vcs\Exception\RepositoryNotFoundException;
use Micx\Vcs\Exception\AccessDeniedException;
use Micx\Vcs\Transport\TransportFactory;

final class MixVcsManager
{
    /** @var array<string, string> */
    private array $mockDirectories = [];
    private bool $allowMockDir = false;
    private ?RpcClient $client = null;
    private ?string $rabbitMqUrl = null;
    private float $timeout;

    /**
     * Manages local mocks and real Git repositories through a single public API.
     * An existing RpcTransport or rabbitmq:// URL enables remote checkout.
     * null, mock, file and mock:// or file:// selectors disable remote access.
     * With a remote connector, sharedDirectory must be explicitly set and exist.
     * The connection from a URL is opened lazily on the first real checkout.
     *
     * Example:
     * $manager = new MixVcsManager();
     * $manager->setAllowMockDir(true);
     * $manager->addMockDir('git@github.com:example/repo1.git', 'main', '/tmp/demo');
     * $repository = $manager->getRepository('git@github.com:example/repo1.git', 'main');
     *
     * @throws InternalException For an unsupported connector URI scheme.
     * @see VcsRepository
     */
    public function __construct(
        private ?string $sharedDirectory = null,
        RpcTransport|string|null $connector = null,
        float $timeout = 60.0,
    ) {
        if ($sharedDirectory !== null) {
            if ($sharedDirectory === '' || $sharedDirectory[0] !== '/') {
                throw new InternalException(
                    ErrorCode::SHARED_DIRECTORY_INVALID,
                    'Shared Directory muss ein absoluter Verzeichnispfad sein.',
                    'configure'
                );
            }
            $this->sharedDirectory = rtrim($sharedDirectory, '/') ?: '/';
        }
        if ($connector instanceof RpcTransport) {
            $this->client = new RpcClient($connector, $timeout);
        } elseif (is_string($connector) && TransportFactory::validate($connector)) {
            // Die Factory prüft die URL hier nur syntaktisch: Mock-Hits bleiben offline.
            $this->rabbitMqUrl = $connector;
        }



        // Remote-Zugriff setzt ein ausdrücklich konfiguriertes, gemeinsames Mount voraus.
        if ($this->client !== null || $this->rabbitMqUrl !== null) {
            $this->assertSharedDirectory();
        }

        $this->timeout = $timeout;
    }

    /** Enable or disable local directory mappings. @see self::addMockDir() */
    public function setAllowMockDir(bool $allowed): void
    {
        $this->allowMockDir = $allowed;
    }

    /**
     * Map a repository identifier and branch to an existing local directory.
     * Mappings never make unregistered local paths accessible.
     *
     * Example: $manager->addMockDir('demo', 'main', '/tmp/demo');
     *
     * @see self::setAllowMockDir()
     */
    public function addMockDir(string $repository, string $branch, string $directory): void
    {
        if ($repository === '' || $branch === '') {
            throw new \InvalidArgumentException('Repository and branch must not be empty');
        }
        $path = realpath($directory);
        if ($path === false || !is_dir($path) || !is_readable($path) || !is_writable($path)) {
            throw !file_exists($directory) ? new RepositoryNotFoundException(ErrorCode::MOCK_DIRECTORY_NOT_FOUND, "Mock-Verzeichnis nicht gefunden: $directory", 'addMockDir') : new AccessDeniedException(ErrorCode::MOCK_DIRECTORY_DENIED, "Mock-Verzeichnis nicht lesbar oder beschreibbar: $directory", 'addMockDir');
        }
        $this->mockDirectories[$this->key($repository, $branch)] = $path;
    }

    /**
     * Open an existing or checkout a remote repository and return one interface.
     * Mock registration takes priority only when mock mode is enabled.
     * A missing remote checkout is created by the worker. Existing checkouts
     * are refreshed with pull() by default; pass pull: false to skip the refresh.
     * This method never pushes local commits.
     *
     * Example: $repo = $manager->getRepository('git@github.com:org/app.git', 'main');
     * Example without refresh: $repo = $manager->getRepository('git@github.com:org/app.git', 'main', pull: false);
     *
     * @throws InternalException When no connector is configured for a real repository.
     * @see VcsRepository
     */
    public function getRepository(string $repository, string $branch = 'main', bool $pull = true): VcsRepository
    {
        if ($repository === '' || $branch === '') {
            throw new \InvalidArgumentException('Repository and branch must not be empty');
        }

        $key = $this->key($repository, $branch);
        if ($this->allowMockDir && isset($this->mockDirectories[$key])) {
            $mock = new MockRepository($this->mockDirectories[$key], $branch);
            if ($pull) {
                $mock->pull();
            }
            return $mock;
        }

        if ($this->client === null && $this->rabbitMqUrl === null) {
            throw new RepositoryNotFoundException(
                ErrorCode::MOCK_REPOSITORY_NOT_FOUND,
                "Kein Mock-Repository für '$repository' auf Branch '$branch' registriert.",
                'getRepository',
                ['repository' => $repository, 'branch' => $branch]
            );
        }

        $client = $this->getClient();

        $checkout = $client->call('checkout', [
            'url' => $repository,
            'branch' => $branch,
            'directory' => null,
            'temporary' => false,
        ]);

        if (!is_string($checkout['workspace'] ?? null) || !is_string($checkout['path'] ?? null)) {
            throw new InternalException(ErrorCode::INVALID_RESPONSE, 'Checkout-Antwort enthält keinen Workspace oder Pfad.', 'checkout');
        }

        // The worker exposes /data, mounted at sharedDirectory by the application.
        $workerPath = $checkout['path'];
        if ($workerPath !== '/data' && !str_starts_with($workerPath, '/data/')) {
            throw new InternalException(ErrorCode::INVALID_RESPONSE, 'Worker-Pfad liegt außerhalb des erwarteten /data-Verzeichnisses.', 'checkout');
        }
        $relative = substr($workerPath, strlen('/data'));
        $directory = $this->sharedDirectory === '/' ? ($relative ?: '/') : $this->sharedDirectory . $relative;

        $remote = new RemoteRepository($client, $checkout['workspace'], $branch, $directory);
        if ($pull) {
            // Checkout creates a missing repository; pull refreshes an existing one.
            $remote->pull();
        }

        return $remote;
    }

    /**
     * Erstellt den Remote-Transport beim ersten realen Repository-Zugriff.
     * Die Factory prüft Broker-Verbindung und Channel vor dem Checkout.
     *
     * @throws ConnectionException When the broker cannot be reached.
     * @throws InternalException When no remote connector is configured.
     * @see self::getRepository()
     */
    private function getClient(): RpcClient
    {
        if ($this->client !== null) {
            return $this->client;
        }

        if ($this->rabbitMqUrl === null) {
            throw new InternalException(
                ErrorCode::WORKER_CONFIGURATION,
                'Für ein echtes Repository ist eine rabbitmq://-URL oder ein RpcTransport erforderlich.',
                'getRepository'
            );
        }

        $this->client = new RpcClient(
            TransportFactory::connect($this->rabbitMqUrl),
            $this->timeout
        );

        return $this->client;
    }

    /**
     * Validiert das gemeinsame Dateisystem, bevor ein Remote-Connector genutzt wird.
     * Das Volume muss am selben Mount-Pfad für den aufrufenden Service verfügbar sein.
     *
     * @throws InternalException Wenn das Shared Directory fehlt oder nicht lesbar ist.
     * @see self::getRepository()
     */
    private function assertSharedDirectory(): void
    {
        if ($this->sharedDirectory === null || !is_dir($this->sharedDirectory)
            || !is_readable($this->sharedDirectory)) {
            throw new InternalException(
                ErrorCode::SHARED_DIRECTORY_INVALID,
                'Shared Directory für Remote-VCS fehlt oder ist nicht lesbar: '
                    . ($this->sharedDirectory ?? '(nicht angegeben)'),
                'configure'
            );
        }
    }

    private function key(string $repository, string $branch): string
    {
        return json_encode([$repository, $branch], JSON_THROW_ON_ERROR);
    }
}
