<?php
declare(strict_types=1);

namespace Micx\Vcs\Helper\Workspace;

use Micx\Vcs\Exception\ErrorCode;
use Micx\Vcs\Exception\InternalException;

/** Shared-volume registry and lock used by all SDK processes for one repository. */
final class WorkspaceRegistry
{
    private string $base;
    private string $key;

    public function __construct(
        private string $sharedDirectory,
        string $repository,
        string $branch,
    ) {
        $this->key = hash('sha256', json_encode([$repository, $branch], JSON_THROW_ON_ERROR));
        $this->base = rtrim($sharedDirectory, '/') . '/.mix-vcs-sdk';
    }

    /** Look up an existing workspace without contacting RabbitMQ. */
    public function getDirectory(): ?string
    {
        $entry = $this->read();
        return $entry['path'] ?? null;
    }

    /** @return array{path: string, workspace: string}|null */
    public function read(): ?array
    {
        $file = $this->base . '/workspaces/' . $this->key . '.json';
        if (!is_file($file)) {
            return null;
        }

        $data = file_get_contents($file);
        if ($data === false) {
            throw new InternalException(ErrorCode::SHARED_DIRECTORY_INVALID, 'Workspace-Registry nicht lesbar: ' . $file, 'getDirectory');
        }

        try {
            $entry = json_decode($data, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InternalException(ErrorCode::INVALID_RESPONSE, 'Workspace-Registry beschädigt: ' . $file, 'getDirectory', [], $e);
        }

        if (!is_array($entry) || !is_string($entry['path'] ?? null)
            || !is_string($entry['workspace'] ?? null) || $entry['workspace'] === '') {
            throw new InternalException(ErrorCode::INVALID_RESPONSE, 'Workspace-Registry enthält keinen gültigen Pfad: ' . $file, 'getDirectory');
        }

        $directory = realpath($entry['path']);
        $root = realpath($this->sharedDirectory);
        if ($directory === false || $root === false || ($directory !== $root
            && !str_starts_with($directory, rtrim($root, '/') . '/'))) {
            return null;
        }

        return ['path' => $directory, 'workspace' => $entry['workspace']];
    }

    /** Persist successful checkout path for subsequent processes. */
    public function remember(string $directory, string $workspace): void
    {
        $root = realpath($this->sharedDirectory);
        $path = realpath($directory);
        if ($root === false || $path === false || ($path !== $root
            && !str_starts_with($path, rtrim($root, '/') . '/'))) {
            throw new InternalException(ErrorCode::SHARED_DIRECTORY_INVALID, 'Checkout-Pfad fehlt oder liegt außerhalb des Shared Directory: ' . $directory, 'pull');
        }

        $dir = $this->base . '/workspaces';
        $this->ensureDirectory($dir);
        $tmp = tempnam($dir, '.registry-');
        if ($tmp === false) {
            throw new InternalException(ErrorCode::SHARED_DIRECTORY_INVALID, 'Registry-Datei kann nicht erstellt werden: ' . $dir, 'pull');
        }

        try {
            $json = json_encode(['path' => $path, 'workspace' => $workspace], JSON_THROW_ON_ERROR);
            if (file_put_contents($tmp, $json, LOCK_EX) === false || !rename($tmp, $dir . '/' . $this->key . '.json')) {
                throw new InternalException(ErrorCode::SHARED_DIRECTORY_INVALID, 'Registry-Datei konnte nicht gespeichert werden: ' . $dir, 'pull');
            }
        } finally {
            if (file_exists($tmp)) {
                unlink($tmp);
            }
        }
    }

    /**
     * Execute Git operation under the same repository/branch lock across SDK processes.
     * A worker operating without the SDK must coordinate independently.
     *
     * @template T
     * @param callable(): T $action
     * @return T
     */
    public function exclusively(callable $action): mixed
    {
        $dir = $this->base . '/locks';
        $this->ensureDirectory($dir);
        $lockPath = $dir . '/' . $this->key . '.lock';
        $handle = fopen($lockPath, 'c');
        if ($handle === false) {
            throw new InternalException(ErrorCode::SHARED_DIRECTORY_INVALID, 'Repository-Lock kann nicht geöffnet werden: ' . $lockPath, 'lock');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new InternalException(ErrorCode::SHARED_DIRECTORY_INVALID, 'Repository-Lock konnte nicht gesetzt werden: ' . $lockPath, 'lock');
            }

            return $action();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new InternalException(ErrorCode::SHARED_DIRECTORY_INVALID, 'Registry-Verzeichnis konnte nicht erstellt werden: ' . $directory, 'lock');
        }
    }
}
