<?php
declare(strict_types=1);

namespace Micx\Vcs;

use Micx\Vcs\Dto\BranchInfo;
use Micx\Vcs\Dto\LogEntry;
use Micx\Vcs\Exception\ErrorCode;
use Micx\Vcs\Exception\InternalException;
use Micx\Vcs\Exception\RepositoryNotReadyException;

/**
 * Ein passiver Repository-Handle. Das gemeinsame Verzeichnis und der
 * Workspace-Schlüssel werden nach Pull im Shared Directory registriert.
 */
final class RemoteRepository implements VcsRepository
{
    private ?RpcClient $client = null;
    private WorkspaceRegistry $registry;

    /** @param \Closure(): RpcClient $clientFactory */
    public function __construct(
        private \Closure $clientFactory,
        private string $repository,
        private string $branch,
        private string $sharedDirectory,
    ) {
        $this->registry = new WorkspaceRegistry($sharedDirectory, $repository, $branch);
    }

    /**
     * Gibt das bereits vorhandene Checkout-Verzeichnis ohne RPC zurück.
     * Ein erfolgreicher Pull eines anderen SDK-Prozesses genügt.
     *
     * Beispiel: $dir = $repo->getDirectory();
     *
     * @throws RepositoryNotReadyException Wenn noch kein Checkout registriert ist.
     * @see self::pull()
     */
    public function getDirectory(): string
    {
        $directory = $this->registry->getDirectory();
        if ($directory === null) {
            throw new RepositoryNotReadyException(
                ErrorCode::REPOSITORY_NOT_READY,
                'Kein vorhandener Checkout für Repository/Branch registriert. Ein separater Prozess muss zuerst pull() ausführen.',
                'getDirectory',
                ['repository' => $this->repository, 'branch' => $this->branch]
            );
        }

        return $directory;
    }

    /**
     * Holt den Checkout bei Bedarf und aktualisiert ihn über den Worker.
     * Alle SDK-Prozesse sperren währenddessen dieselbe Repo/Branch-Kennung.
     *
     * Beispiel: $repo->pull();
     *
     * @see self::getDirectory()
     */
    public function pull(): void
    {
        $this->registry->exclusively(fn () => $this->pullUnlocked());
    }

    /**
     * Serialisiert Pull und Push als einen Ablauf; kein automatischer Commit.
     *
     * Beispiel: $repo->sync();
     *
     * @see self::pull()
     * @see self::push()
     */
    public function sync(): void
    {
        $this->registry->exclusively(function (): void {
            $this->pullUnlocked();
            $this->pushUnlocked();
        });
    }

    public function commit(string $message = 'Update workspace'): void
    {
        $this->registry->exclusively(function () use ($message): void {
            $workspace = $this->workspace();
            $this->client()->call('commit', [
                'workspace' => $workspace,
                'message' => $message,
                'push' => false,
            ]);
        });
    }

    public function push(): void
    {
        $this->registry->exclusively(fn () => $this->pushUnlocked());
    }

    public function hasUnpushedChanges(): bool
    {
        $status = $this->client()->call('status', ['workspace' => $this->workspace()]);
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
        $result = $this->client()->call('log', ['workspace' => $this->workspace()]);
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
        $result = $this->client()->call('branches', ['workspace' => $this->workspace()]);
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

    private function pullUnlocked(): void
    {
        $client = $this->client();
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

        // Ein fehlgeschlagener Pull darf keinen neuen Checkout als bereit melden.
        $client->call('pull', ['workspace' => $checkout['workspace']]);
        $this->registry->remember($directory, $checkout['workspace']);
    }

    private function pushUnlocked(): void
    {
        $this->client()->call('push', ['workspace' => $this->workspace(), 'branch' => null]);
    }

    private function workspace(): string
    {
        $entry = $this->registry->read();
        if ($entry === null) {
            throw new RepositoryNotReadyException(
                ErrorCode::REPOSITORY_NOT_READY,
                'Workspace noch nicht registriert. Zuerst pull() ausführen.',
                'workspace',
                ['repository' => $this->repository, 'branch' => $this->branch]
            );
        }

        return $entry['workspace'];
    }

    private function client(): RpcClient
    {
        return $this->client ??= ($this->clientFactory)();
    }
}
