<?php
declare(strict_types=1);

namespace Micx\Vcs;

use Micx\Vcs\Exception\ErrorCode;
use Micx\Vcs\Exception\AccessDeniedException;
use Micx\Vcs\Exception\RepositoryNotFoundException;
use Micx\Vcs\Exception\OperationFailedException;
use Micx\Vcs\Exception\RepositoryNotReadyException;

use Micx\Vcs\Dto\BranchInfo;
use Micx\Vcs\Dto\LogEntry;

/**
 * Local-directory backend: no Git, SSH, AMQP or remote service required.
 * A small JSON journal records commits, the last pushed commit and file hashes.
 */
final class MockRepository implements VcsRepository
{
    private const JOURNAL = '.mix-vcs.json';

    public function __construct(private string $directory, private string $branch)
    {
        if (!is_dir($directory) || !is_readable($directory) || !is_writable($directory)) {
            throw !file_exists($directory) ? new RepositoryNotFoundException(ErrorCode::MOCK_DIRECTORY_NOT_FOUND, "Mock-Verzeichnis nicht gefunden: $directory", 'open') : new AccessDeniedException(ErrorCode::MOCK_DIRECTORY_DENIED, "Mock-Verzeichnis nicht zugreifbar: $directory", 'open');
        }
    }

    /**
     * Gibt ein existierendes Mock-Verzeichnis sofort ohne Pull zurück.
     * Anders als beim echten Git-Checkout existiert das Verzeichnis bereits.
     *
     * Beispiel: $directory = $repo->getDirectory();
     *
     * @throws RepositoryNotFoundException Wenn der Pfad nicht mehr existiert.
     * @see self::pull()
     */
    public function getDirectory(): string
    {
        if (!is_dir($this->directory)) {
            throw new RepositoryNotFoundException(
                ErrorCode::MOCK_DIRECTORY_NOT_FOUND,
                "Mock-Verzeichnis nicht gefunden: $this->directory",
                'getDirectory'
            );
        }

        return $this->directory;
    }

    /**
     * Aktiviert das vorhandene Mock-Verzeichnis ohne Remote-Zugriff.
     *
     * Beispiel: $repo->pull();
     *
     * @throws RepositoryNotFoundException Wenn der Pfad inzwischen fehlt.
     * @see self::getDirectory()
     */
    public function pull(): void
    {
        if (!is_dir($this->directory)) {
            throw new RepositoryNotFoundException(
                ErrorCode::MOCK_DIRECTORY_NOT_FOUND,
                "Mock-Verzeichnis nicht gefunden: $this->directory",
                'pull'
            );
        }
        if (!is_readable($this->directory) || !is_writable($this->directory)) {
            $this->ready = false;
            throw new AccessDeniedException(
                ErrorCode::MOCK_DIRECTORY_DENIED,
                "Mock-Verzeichnis nicht lesbar oder beschreibbar: $this->directory",
                'pull'
            );
        }

    }

    /** Mirror the remote sequence in mock mode (pull is a local no-op). */
    public function sync(): void
    {
        $this->pull();
        $this->push();
    }

    public function commit(string $message = 'Update workspace'): void
    {
        $this->getDirectory();
        if (trim($message) === '') {
            throw new \InvalidArgumentException('Commit message must not be empty');
        }
        $journal = $this->readJournal();
        $current = $this->fingerprint();

        if ($current === ($journal['committedHash'] ?? null)) {
            return;
        }

        $entry = new LogEntry(bin2hex(random_bytes(16)), $message, gmdate('c'), $this->branch);
        array_unshift($journal['history'], [
            'id' => $entry->id, 'message' => $entry->message,
            'timestamp' => $entry->timestamp, 'branch' => $entry->branch,
        ]);
        $journal['committedHash'] = $current;
        $this->writeJournal($journal);
    }

    public function push(): void
    {
        $this->getDirectory();
        $journal = $this->readJournal();
        if ($this->fingerprint() !== ($journal['committedHash'] ?? null)) {
            throw new OperationFailedException(ErrorCode::CONFLICT, 'Vor dem Push müssen Änderungen committet werden.', 'push');
        }
        $journal['pushedCommit'] = $journal['history'][0]['id'] ?? null;
        $this->writeJournal($journal);
    }

    public function hasUnpushedChanges(): bool
    {
        $this->getDirectory();
        $journal = $this->readJournal();

        return $this->fingerprint() !== ($journal['committedHash'] ?? null)
            || ($journal['history'][0]['id'] ?? null) !== ($journal['pushedCommit'] ?? null);
    }

    public function getHistory(): array
    {
        $this->getDirectory();
        return array_map(
            static fn (array $entry): LogEntry => LogEntry::fromArray($entry),
            $this->readJournal()['history']
        );
    }

    public function getBranches(): array
    {
        $this->getDirectory();
        return [new BranchInfo($this->branch, $this->getHistory()[0] ?? null, true)];
    }

    /** @return array{history: list<array<string,mixed>>, committedHash: ?string, pushedCommit: ?string} */
    private function readJournal(): array
    {
        $file = $this->directory . '/' . self::JOURNAL;
        if (!is_file($file)) {
            return ['history' => [], 'committedHash' => null, 'pushedCommit' => null];
        }
        if (!is_readable($file)) {
            throw new AccessDeniedException(ErrorCode::MOCK_DIRECTORY_DENIED, "Mock-Journal nicht lesbar: $file", 'history');
        }
        $content = file_get_contents($file);
        if ($content === false) {
            throw new OperationFailedException(ErrorCode::MOCK_IO_FAILED, "Mock-Journal konnte nicht gelesen werden: $file", 'history');
        }
        $data = json_decode($content, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !is_array($data['history'] ?? null)) {
            throw new OperationFailedException(ErrorCode::MOCK_JOURNAL_INVALID, "Mock-Journal ungültig: $file", 'history');
        }

        return $data;
    }

    /** @param array<string, mixed> $journal */
    private function writeJournal(array $journal): void
    {
        $file = $this->directory . '/' . self::JOURNAL;
        $temporary = tempnam($this->directory, '.mix-vcs-');
        if ($temporary === false) {
            throw new OperationFailedException(ErrorCode::MOCK_IO_FAILED, "Mock-Journal konnte nicht erstellt werden: $this->directory", 'commit');
        }

        try {
            $json = json_encode($journal, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if (file_put_contents($temporary, $json . "\n", LOCK_EX) === false || !rename($temporary, $file)) {
                throw new OperationFailedException(ErrorCode::MOCK_IO_FAILED, "Mock-Journal konnte nicht geschrieben werden: $file", 'commit');
            }
        } finally {
            if (file_exists($temporary)) {
                unlink($temporary);
            }
        }
    }

    /** Snapshot of file contents and names, excluding internal metadata and .git. */
    private function fingerprint(): string
    {
        $paths = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            $relative = substr($file->getPathname(), strlen($this->directory) + 1);
            if ($relative === self::JOURNAL || $relative === '.git' || str_starts_with($relative, '.git/')
                || str_starts_with($relative, '.mix-vcs-')) {
                continue;
            }
            if ($file->isLink()) {
                throw new OperationFailedException(ErrorCode::MOCK_IO_FAILED, "Symlink im Mock-Verzeichnis nicht unterstützt: $relative", 'status');
            }
            if (!$file->isFile()) {
                continue;
            }
            $hash = hash_file('sha256', $file->getPathname());
            if ($hash === false) {
                throw new OperationFailedException(ErrorCode::MOCK_IO_FAILED, "Mock-Datei konnte nicht gelesen werden: $relative", 'status');
            }
            $paths[$relative] = $hash;
        }

        ksort($paths, SORT_STRING);
        return hash('sha256', json_encode($paths, JSON_THROW_ON_ERROR));
    }
}
