<?php

declare(strict_types = 1);

namespace AgenticVibes\AgentSkills;

/**
 * The copy/prune/orphan file counters produced by syncing one payload's directories.
 * Replaces the positional `array{int, int, int}` shape previously threaded through
 * `Installer::syncDirectories()` and `Installer::runAllSyncs()`.
 */
final readonly class SyncCounts
{

    /**
     * @param array<int, string> $orphanedTargets target directories that have at least one
     *                                            orphaned file — never the orphaned files themselves, only the fixed, well-known
     *                                            target root (e.g. `.claude/skills`), so a multi-target orphan count stays traceable to
     *                                            "which target" without ever printing the specific file names inside it.
     */
    public function __construct(public int $copied, public int $pruned, public int $orphaned, public array $orphanedTargets = []) {
    }

    public function add(self $other): self
    {
        return new self(
            copied: $this->copied + $other->copied,
            pruned: $this->pruned + $other->pruned,
            orphaned: $this->orphaned + $other->orphaned,
            orphanedTargets: [...$this->orphanedTargets, ...$other->orphanedTargets],
        );
    }

}
