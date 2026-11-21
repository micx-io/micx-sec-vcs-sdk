<?php
declare(strict_types=1);

namespace Micx\Vcs;

use Micx\Vcs\Dto\BranchInfo;
use Micx\Vcs\Dto\LogEntry;
use Micx\Vcs\Exception\ErrorCode;
use Micx\Vcs\Exception\InternalException;
use Micx\Vcs\Exception\RepositoryNotReadyException;

/** Remote-Repository: erst pull() öffnet den Workspace beim VCS-Worker. */
final class RemoteRepository implements VcsRepository
{
    private ?RpcClient $client = null;
    private ?string $workspace = null;
    private ?string $directory = null;
    private bool $ready = false;

    /** @param \Closure(): RpcClient $clientFactory */
    public function __construct(
        private \Closure $clientFactory,
        private string $repository,
        private string $branch,
        private string $sharedDirectory,
    ) {
    }

    /**
     * Gibt den Dateipfad nach einem erfolgreichen Pull zurück.
     * Hier erfolgt nie ein Checkout oder anderer RPC.
     *
     * Beispiel: $repo->pull(); $path = $repo->getDirectory();
     *
     * @throws RepositoryNotReadyException Bei fehlendem Pull oder Verzeichnis.
     * @see self::pull()
     */
    public function getDirectory(): string
    {
        if (!$this->ready || $this->directory === null || !is_dir($this->directory)) {
            throw new RepositoryNotReadyException(
                ErrorCode::REPOSITORY_NOT_READY,
                'Repository ist noch nicht ausgecheckt/aktualisiert oder das Shared-Verzeichnis fehlt. Zuerst pull() ausführen.',
                'getDirectory',
                ['repository' => $this->repository, 'branch' => $this->branch, 'directory' => $this->directory]
            );
        }

        return $this->directory;
    }

    /**
     * Erstellt einen fehlenden Checkout und aktualisiert das Repository.
     * Der Worker führt checkout und pull durch; bei Fehlern bleibt der Handle
     * für getDirectory() nicht freigegeben.
     *
     * Beispiel: $repo->pull();
     *
     * @see self::getDirectory()
     */
    public function pull(): void
    {
        $this->ready = false;
        $client = $this->client ??= ($this->clientFactory)();

        // Checkout darf einen vorhandenen Workspace zurückgeben; kein Push.
        $checkout = $client->call('checkout', [
            'url' => $this->repository,
            'branch' => $this->branch,
            'directory' => null,
            'temporary' => false,
        ]);

        if (!is_string($checkout['workspace'] ?? null) || $checkout['workspace'] === ''
            || !is_string($checkout['path'] ?? null)) {
            throw new InternalException(
                ErrorCode::INVALID_RESPONSE,
                'Checkout-Antwort enthält keinen gültigen Workspace oder Pfad.',
                'checkout'
            );
        }

        $workerPath = $checkout['path'];
        if ($workerPath !== '/data' && !str_starts_with($workerPath, '/data/')) {
            throw new InternalException(
                ErrorCode::INVALID_RESPONSE,
                'Worker-Pfad liegt außerhalb des /data-Volumes.',
                'checkout'
            );
        }

        $relative = substr($workerPath, strlen('/data'));
        $directory = $this->sharedDirectory === '/'
            ? ($relative ?: '/')
            : $this->sharedDirectory . $relative;

        $this->workspace = $checkout['workspace'];
        $this->directory = $directory;
        $client->call('pull', ['workspace' => $this->workspace]);
        $this->ready = true;
    }

    /**
     * Führt erst pull und anschließend push aus, ohne Änderungen zu committen.
     *
     * Beispiel: $repo->sync();
     *
     * @see self::pull()
     * @see self::push()
     */
    public function sync(): void
    {
        $this->pull();
        $this->push();
    }

    public function commit(string $message = 'Update workspace'): void
    {
        $this->getDirectory();
        $this->client->call('commit', [
            'workspace' => $this->workspace,
            'message' => $message,
            'push' => false,
        ]);
    }

    public function push(): void
    {
        $this->getDirectory();
        $this->client->call('push', ['workspace' => $this->workspace, 'branch' => null]);
    }

    public function hasUnpushedChanges(): bool
    {
        $this->getDirectory();
        $status = $this->client->call('status', ['workspace' => $this->workspace]);
        if (!is_bool($status['hasUnpushedChanges'] ?? null)) {
            throw new InternalException(
                ErrorCode::UNSUPPORTED_OPERATION,
                'Worker liefert hasUnpushedChanges noch nicht; Worker aktualisieren.',
                'status'
            );
        }

        return $status['hasUnpushedChanges'];
    }

    public function getHistory(): array
    {
        $this->getDirectory();
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
        $this->getDirectory();
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
