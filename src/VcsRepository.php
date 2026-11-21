<?php
declare(strict_types=1);

namespace Micx\Vcs;

use Micx\Vcs\Dto\BranchInfo;
use Micx\Vcs\Dto\LogEntry;

interface VcsRepository
{
    /** Absolute application-visible directory holding this repository's files. */
    public function getDirectory(): string;

    /** Synchronize from the remote; overlapping changes prefer the local version. */
    public function pull(): void;

    /** Record working tree changes without publishing them. */
    public function commit(string $message = 'Update workspace'): void;

    /** Publish local commits to the remote. */
    public function push(): void;

    /** True for dirty files OR committed changes not yet published. */
    public function hasUnpushedChanges(): bool;

    /** @return list<LogEntry> Most recent commits first. */
    public function getHistory(): array;

    /** @return list<BranchInfo> */
    public function getBranches(): array;
}
