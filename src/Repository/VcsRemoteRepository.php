<?php

declare(strict_types=1);

namespace Micx\Vcs\Repository;

use Micx\Vcs\Dto\BranchInfo;
use Micx\Vcs\Dto\LogEntry;
use Micx\Vcs\Exception\ErrorCode;
use Micx\Vcs\Exception\InternalException;
use Micx\Vcs\Exception\RepositoryNotReadyException;
use Micx\Vcs\Helper\Workspace\RpcClient;

/**
 * Stateless repository handle. URL, branch and relative directory travel
 * with every RPC; the client computes the checkout path without a roundtrip.
 */
final class VcsRemoteRepository implements VcsRepository
{
    private ?RpcClient $client = null;
    private readonly string $relativeDirectory;

    /** @param \Closure(): RpcClient $clientFactory */
    public function __construct(
        private \Closure $clientFactory,
        private string $repository,
        private string $branch,
        private string $sharedDirectory,
        ?string $directory = null,
    ) {
        $directory ??= 'repos/' . hash('sha256', json_encode([$repository, $branch], JSON_THROW_ON_ERROR));
        $this->relativeDirectory = self::validateDirectory($directory);
    }

    /**
     * Checks RabbitMQ/worker reachability without opening the checkout.
     *
     * Example: $ready = $repo->ping();
     * @return bool Worker ping result
     * @throws \InvalidArgumentException If timeout is invalid.
     * @see self::pull()
     */
    public function ping(float $timeout = 5.0): bool
    {
        if (!is_finite($timeout) || $timeout <= 0 || $timeout > 300) {
            throw new \InvalidArgumentException('Timeout must be in (0, 300] seconds');
        }

        $result = $this->client()->call('ping', $this->params(), $timeout);
        if (!is_bool($result['pong'] ?? null)) {
            throw new InternalException(ErrorCode::INVALID_RESPONSE, 'Ping-Antwort enthält kein boolesches pong.', 'ping');
        }

        return $result['pong'];
    }

    /**
     * Returns the locally known checkout directory without contacting RabbitMQ.
     *
     * Example: $path = $repo->getDirectory();
     * @return string Absolute application-visible workspace directory.
     * @throws RepositoryNotReadyException Before the first successful pull.
     * @see self::pull()
     */
    public function getDirectory(): string
    {
        $directory = rtrim($this->sharedDirectory, '/') . '/' . $this->relativeDirectory;
        if (!is_dir($directory) || !is_file($directory . '/.git/HEAD')) {
            throw new RepositoryNotReadyException(
                ErrorCode::REPOSITORY_NOT_READY,
                'Repository noch nicht ausgecheckt. Zuerst pull() ausführen.',
                'getDirectory',
                ['repository' => $this->repository, 'branch' => $this->branch]
            );
        }

        $root = realpath($this->sharedDirectory);
        $path = realpath($directory);
        if ($root === false || $path === false || !str_starts_with($path, rtrim($root, '/') . '/')) {
            throw new InternalException(ErrorCode::SHARED_DIRECTORY_INVALID, 'Checkout liegt außerhalb des Shared Directory.', 'getDirectory');
        }

        return $path;
    }

    /** Pulls and initializes the checkout when needed. @see self::getDirectory() */
    public function pull(): void
    {
        $this->client()->call('pull', $this->params());
    }

    /** Pulls then pushes without committing dirty files. @see self::pull() */
    public function sync(): void
    {
        $this->pull();
        $this->push();
    }

    /** Records local changes without a push. @see self::push() */
    public function commit(string $message = 'Update workspace'): void
    {
        $this->client()->call('commit', $this->params() + ['message' => $message]);
    }

    /** Publishes local commits. @see self::commit() */
    public function push(): void
    {
        $this->client()->call('push', $this->params());
    }

    /** Returns true for dirty or unpushed commits. @see self::getHistory() */
    public function hasUnpushedChanges(): bool
    {
        $status = $this->client()->call('status', $this->params());
        if (!is_bool($status['hasUnpushedChanges'] ?? null)) {
            throw new InternalException(ErrorCode::INVALID_RESPONSE, 'Worker liefert keinen gültigen Status.', 'status');
        }

        return $status['hasUnpushedChanges'];
    }

    /** @return list<LogEntry> @see self::getBranches() */
    public function getHistory(): array
    {
        $result = $this->client()->call('log', $this->params());
        if (!is_array($result['entries'] ?? null)) {
            throw new InternalException(ErrorCode::INVALID_RESPONSE, 'Worker liefert keine gültige Log-Liste.', 'log');
        }

        return array_map(static fn (array $item): LogEntry => LogEntry::fromArray($item), $result['entries']);
    }

    /** @return list<BranchInfo> @see self::getHistory() */
    public function getBranches(): array
    {
        $result = $this->client()->call('branches', $this->params());
        if (!is_array($result['branches'] ?? null)) {
            throw new InternalException(ErrorCode::INVALID_RESPONSE, 'Worker liefert keine gültige Branch-Liste.', 'branches');
        }

        return array_map(
            fn (array $item): BranchInfo => new BranchInfo(
                (string) $item['name'],
                isset($item['latestCommit']) ? LogEntry::fromArray($item['latestCommit']) : null,
                (string) $item['name'] === $this->branch
            ),
            $result['branches']
        );
    }

    /** @return array{url:string,branch:string,directory:string} */
    private function params(): array
    {
        return [
            'url' => $this->repository,
            'branch' => $this->branch,
            'directory' => $this->relativeDirectory,
        ];
    }

    /** Relative paths must not access Git internals or leave the shared volume. */
    private static function validateDirectory(string $directory): string
    {
        if ($directory === '' || strlen($directory) > 512 || preg_match('/[\\\\\x00-\x1f\x7f]/', $directory)) {
            throw new \InvalidArgumentException('Ungültiges relatives Repository-Verzeichnis.');
        }

        foreach (explode('/', $directory) as $part) {
            if ($part === '' || $part === '.' || $part === '..'
                || str_starts_with($part, '-') || strcasecmp($part, '.git') === 0) {
                throw new \InvalidArgumentException('Ungültige Repository-Verzeichniskomponente.');
            }
        }

        return $directory;
    }

    private function client(): RpcClient
    {
        return $this->client ??= ($this->clientFactory)();
    }
}
