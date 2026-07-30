<?php

declare(strict_types = 1);

namespace AgenticVibes\AgentSkills;

/**
 * The install run's outcome counters, reported to the user at the end of `install()`.
 * Replaces the 5-parameter, 4-consecutive-`int` signature previously declared by
 * `Installer::reportInstallSummary()`, which crossed the `@rules/php/core-standards.mdc`
 * "more than 4 parameters" threshold and made a positional argument swap undetectable.
 */
final readonly class InstallSummary
{

    /**
     * @param array<int, string> $orphanedTargets target directories that have at least one
     *                                            orphaned file (never the orphaned files themselves — see `SyncCounts`)
     */
    public function __construct(
        public int $copied,
        public int $pruned,
        public int $orphaned,
        public int $permissionsAdded,
        public bool $coAuthoredByDisabled,
        public array $orphanedTargets = [],
    ) {
    }

}
