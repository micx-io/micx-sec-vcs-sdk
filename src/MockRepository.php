<?php
declare(strict_types=1);

namespace Micx\Vcs;

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
            throw new \RuntimeException("Mock directory is inaccessible: $directory");
        }
    }

    public function getDirectory(): string
    {
        return $this->directory;
    }

    public function pull(): void
    {
        // A mapped local directory is already the working copy. No remote exists.
    }

    public function commit(string $message = 'Update workspace'): void
    {
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
        $journal = $this->readJournal();
        if ($this->fingerprint() !== ($journal['committedHash'] ?? null)) {
            throw new \LogicException('Commit working changes before pushing');
        }
        $journal['pushedCommit'] = $journal['history'][0]['id'] ?? null;
        $this->writeJournal($journal);
    }

    public function hasUnpushedChanges(): bool
    {
        $journal = $this->readJournal();

        return $this->fingerprint() !== ($journal['committedHash'] ?? null)
            || ($journal['history'][0]['id'] ?? null) !== ($journal['pushedCommit'] ?? null);
    }

    public function getHistory(): array
    {
        return array_map(
            static fn (array $entry): LogEntry => LogEntry::fromArray($entry),
            $this->readJournal()['history']
        );
    }

    public function getBranches(): array
    {
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
            throw new \RuntimeException("Mock journal is unreadable: $file");
        }
        $content = file_get_contents($file);
        if ($content === false) {
            throw new \RuntimeException("Unable to read mock journal: $file");
        }
        $data = json_decode($content, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !is_array($data['history'] ?? null)) {
            throw new \RuntimeException("Invalid mock journal: $file");
        }

        return $data;
    }

    /** @param array<string, mixed> $journal */
    private function writeJournal(array $journal): void
    {
        $file = $this->directory . '/' . self::JOURNAL;
        $temporary = tempnam($this->directory, '.mix-vcs-');
        if ($temporary === false) {
            throw new \RuntimeException("Cannot create mock journal in: $this->directory");
        }

        try {
            $json = json_encode($journal, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if (file_put_contents($temporary, $json . "\n", LOCK_EX) === false || !rename($temporary, $file)) {
                throw new \RuntimeException("Cannot write mock journal: $file");
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
                throw new \RuntimeException("Symlinks are not supported in mock directory: $relative");
            }
            if (!$file->isFile()) {
                continue;
            }
            $hash = hash_file('sha256', $file->getPathname());
            if ($hash === false) {
                throw new \RuntimeException("Cannot hash mock file: $relative");
            }
            $paths[$relative] = $hash;
        }

        ksort($paths, SORT_STRING);
        return hash('sha256', json_encode($paths, JSON_THROW_ON_ERROR));
    }
}
