<?php
declare(strict_types=1);

namespace Micx\Vcs;

use Micx\Vcs\Exception\ErrorCode;
use Micx\Vcs\Exception\InternalException;
use Micx\Vcs\Exception\RepositoryNotFoundException;
use Micx\Vcs\Exception\AccessDeniedException;
use Micx\Vcs\Exception\ConnectionException;
use PhpAmqpLib\Connection\AMQPStreamConnection;

final class MixVcsManager
{
    /** @var array<string, string> */
    private array $mockDirectories = [];
    private bool $allowMockDir = false;
    private ?RpcClient $client = null;
    private ?string $rabbitMqUrl = null;
    private float $timeout;

    /**
     * Manage repositories by URL/name and branch. The optional transport is only
     * required for repositories that are not mapped to local mock directories.
     *
     * Example:
     * $manager = new MixVcsManager();
     * $manager->setAllowMockDir(true);
     * $manager->addMockDir('demo', 'main', '/tmp/demo');
     * $repository = $manager->getRepository('demo', 'main');
     *
     * @see VcsRepository
     */
    public function __construct(
        private string $sharedDirectory = '/mix/vcs',
        RpcTransport|string|null $connector = null,
        float $timeout = 60.0,
    ) {
        if ($sharedDirectory === '' || $sharedDirectory[0] !== '/') {
            throw new \InvalidArgumentException('Shared directory must be an absolute path');
        }
        $this->sharedDirectory = rtrim($sharedDirectory, '/') ?: '/';
        if ($connector instanceof RpcTransport) {
            $this->client = new RpcClient($connector, $timeout);
        } elseif (is_string($connector) && !in_array(strtolower(trim($connector)), ['', 'mock', 'file', 'file://'], true)) {
            if (parse_url($connector, PHP_URL_SCHEME) !== 'rabbitmq') {
                throw new InternalException(
                    ErrorCode::WORKER_CONFIGURATION,
                    'Nur rabbitmq://-URLs oder null, mock, file sind als Connector-Konfiguration erlaubt.',
                    'configure'
                );
            }

            // Verbindungsaufbau erst beim tatsächlichen Zugriff auf ein Remote-Repository.
            $this->rabbitMqUrl = $connector;
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
     *
     * Example: $repo = $manager->getRepository('git@github.com:org/app.git', 'main');
     *
     * @throws \LogicException When no connector is supplied for a real repository.
     * @see VcsRepository
     */
    public function getRepository(string $repository, string $branch = 'main'): VcsRepository
    {
        if ($repository === '' || $branch === '') {
            throw new \InvalidArgumentException('Repository and branch must not be empty');
        }

        $key = $this->key($repository, $branch);
        if ($this->allowMockDir && isset($this->mockDirectories[$key])) {
            return new MockRepository($this->mockDirectories[$key], $branch);
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

        return new RemoteRepository($client, $checkout['workspace'], $branch, $directory);
    }

    /**
     * Start a RabbitMQ connection lazily, only if a repository is not mocked.
     * A URL may include percent-encoded user/password and an optional vhost.
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

        $parts = parse_url($this->rabbitMqUrl);
        if ($parts === false || ($parts['scheme'] ?? null) !== 'rabbitmq'
            || !is_string($parts['host'] ?? null) || $parts['host'] === ''
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new InternalException(
                ErrorCode::WORKER_CONFIGURATION,
                'RabbitMQ-URL ungültig. Erwartet: rabbitmq://user:pass@host:5672[/vhost].',
                'configure'
            );
        }

        $port = $parts['port'] ?? 5672;
        $username = rawurldecode($parts['user'] ?? 'guest');
        $password = rawurldecode($parts['pass'] ?? 'guest');
        $path = $parts['path'] ?? '';
        $vhost = $path === '' || $path === '/' ? '/' : rawurldecode(ltrim($path, '/'));

        try {
            $connection = new AMQPStreamConnection($parts['host'], $port, $username, $password, $vhost);
            $this->client = new RpcClient(new RabbitMqTransport($connection), $this->timeout);
        } catch (\Throwable $e) {
            throw new ConnectionException(
                ErrorCode::CONNECTION_FAILED,
                'RabbitMQ-Verbindung fehlgeschlagen. Host, Port, Anmeldedaten und Broker prüfen.',
                'connect',
                [],
                $e
            );
        }

        return $this->client;
    }

    private function key(string $repository, string $branch): string
    {
        return json_encode([$repository, $branch], JSON_THROW_ON_ERROR);
    }
}
