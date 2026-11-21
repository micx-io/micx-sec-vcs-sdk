<?php
declare(strict_types=1);

namespace Micx\Vcs;

final class MixVcsManager
{
    /** @var array<string, string> */
    private array $mockDirectories = [];
    private bool $allowMockDir = false;
    private ?RpcClient $client;

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
        ?RpcTransport $connector = null,
        float $timeout = 60.0,
    ) {
        if ($sharedDirectory === '' || $sharedDirectory[0] !== '/') {
            throw new \InvalidArgumentException('Shared directory must be an absolute path');
        }
        $this->sharedDirectory = rtrim($sharedDirectory, '/') ?: '/';
        $this->client = $connector === null ? null : new RpcClient($connector, $timeout);
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
            throw new \InvalidArgumentException("Mock directory does not exist or is inaccessible: $directory");
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

        if ($this->client === null) {
            throw new \LogicException('A connector is required for a non-mock repository');
        }

        $checkout = $this->client->call('checkout', [
            'url' => $repository,
            'branch' => $branch,
            'directory' => null,
            'temporary' => false,
        ]);

        if (!is_string($checkout['workspace'] ?? null) || !is_string($checkout['path'] ?? null)) {
            throw new RpcException('INVALID_RESPONSE', 'Checkout did not return workspace and path');
        }

        // The worker exposes /data, mounted at sharedDirectory by the application.
        $workerPath = $checkout['path'];
        if ($workerPath !== '/data' && !str_starts_with($workerPath, '/data/')) {
            throw new RpcException('INVALID_RESPONSE', 'Unexpected workspace path outside /data');
        }
        $relative = substr($workerPath, strlen('/data'));
        $directory = $this->sharedDirectory === '/' ? ($relative ?: '/') : $this->sharedDirectory . $relative;

        return new RemoteRepository($this->client, $checkout['workspace'], $branch, $directory);
    }

    private function key(string $repository, string $branch): string
    {
        return json_encode([$repository, $branch], JSON_THROW_ON_ERROR);
    }
}
