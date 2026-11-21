<?php
declare(strict_types=1);

namespace Micx\Vcs;

use Micx\Vcs\Exception\ErrorCode;
use Micx\Vcs\Exception\InternalException;

use Micx\Vcs\Dto\BranchInfo;
use Micx\Vcs\Dto\LogEntry;

/** Real repository backend that delegates Git work to the VCS worker. */
final class RemoteRepository implements VcsRepository
{
    public function __construct(
        private RpcClient $client,
        private string $workspace,
        private string $branch,
        private string $directory,
    ) {
    }

    public function getDirectory(): string
    {
        return $this->directory;
    }

    public function pull(): void
    {
        $this->client->call('pull', ['workspace' => $this->workspace]);
    }

    /** Synchronize by pulling first and pushing second; dirty files are not committed. */
    public function sync(): void
    {
        $this->pull();
        $this->push();
    }

    public function commit(string $message = 'Update workspace'): void
    {
        $this->client->call('commit', [
            'workspace' => $this->workspace,
            'message' => $message,
            'push' => false,
        ]);
    }

    public function push(): void
    {
        $this->client->call('push', ['workspace' => $this->workspace, 'branch' => null]);
    }

    public function hasUnpushedChanges(): bool
    {
        // Requires an extended status response from the worker.
        $status = $this->client->call('status', ['workspace' => $this->workspace]);
        if (!is_bool($status['hasUnpushedChanges'] ?? null)) {
            throw new InternalException(ErrorCode::UNSUPPORTED_OPERATION, 'Worker liefert hasUnpushedChanges noch nicht; Worker aktualisieren.', 'status');
        }

        return $status['hasUnpushedChanges'];
    }

    public function getHistory(): array
    {
        $result = $this->client->call('log', ['workspace' => $this->workspace]);
        if (!is_array($result['entries'] ?? null)) {
            throw new InternalException(ErrorCode::INVALID_RESPONSE, 'Worker liefert keine gültige Log-Liste.', 'log');
        }

        return array_map(
            static fn (array $item): LogEntry => LogEntry::fromArray($item),
            $result['entries']
        );
    }

    public function getBranches(): array
    {
        $result = $this->client->call('branches', ['workspace' => $this->workspace]);
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
}
