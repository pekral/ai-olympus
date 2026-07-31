<?php

declare(strict_types = 1);

namespace AgenticVibes\AgentSkills;

final class Installer
{

    /**
     * @param array<int, string> $argv
     */
    public static function run(array $argv): int
    {
        $normalizedArgv = InstallerPath::normalizeCliArguments($argv);
        $command = $normalizedArgv[1] ?? 'help';
        $options = InstallOptions::fromArgv($normalizedArgv);

        try {
            if ($command === 'help') {
                return self::showHelp();
            }

            if ($command !== 'install') {
                fwrite(STDERR, sprintf('Unknown command: %s%s', $command, PHP_EOL));

                return 1;
            }

            if (self::hasEditorArgument($normalizedArgv)) {
                fwrite(STDERR, 'The --editor option has been removed; the installer now targets Claude Code only. Re-run without --editor.' . PHP_EOL);

                return 1;
            }

            return self::install($options);
        } catch (InstallerFailure $exception) {
            fwrite(STDERR, $exception->getMessage() . PHP_EOL);

            return 1;
        }
    }

    /**
     * @param array<int, string> $argv
     */
    private static function hasEditorArgument(array $argv): bool
    {
        foreach ($argv as $arg) {
            if (str_starts_with($arg, '--editor=')) {
                return true;
            }
        }

        return false;
    }

    private static function showHelp(): int
    {
        echo "Usage:\n";
        echo "  vendor/bin/agent-skills install [--force] [--symlink] [--prune] [--allow-bundled-scripts] [--allow-subagent-writes]\n\n";
        echo "Options:\n  --force                 Overwrite existing files.\n";
        echo "  --symlink               Create symlinks instead of copying (falls back to copy on Windows).\n";
        echo "  --prune                 Remove files in target that no longer exist in source.\n";
        echo "  --allow-bundled-scripts Whitelist bundled scripts (load-issue.sh) in ~/.claude/settings.json. Opt-in.\n";
        echo "  --allow-subagent-writes Allow dispatched-subagent file writes by adding scoped Edit/Write entries for the project\n";
        echo "                          tree to permissions.allow in .claude/settings.local.json. Opt-in.\n";

        return 0;
    }

    private static function install(InstallOptions $options): int
    {
        $root = InstallerPath::resolveProjectRoot();
        $syncCounts = self::runAllSyncs(self::collectSyncPayloads($root), $options->force, $options->symlink, $options->prune);

        $copied = $syncCounts->copied + InstallerFileCopier::installSingleFile(
            InstallerPath::resolveClaudeMdSource(),
            InstallerPath::resolveClaudeMdTarget($root),
        );
        $permissionsAdded = InstallerClaudeSettings::applyIfRequested($options->allowBundledScripts);
        $coAuthoredByDisabled = InstallerClaudeSettings::applyCoAuthoredByPreference();
        $subagentWritesEnabled = InstallerClaudeSettings::applySubagentWritesIfRequested($options->allowSubagentWrites, $root);

        self::reportInstallSummary(new InstallSummary(
            copied: $copied,
            pruned: $syncCounts->pruned,
            orphaned: $syncCounts->orphaned,
            permissionsAdded: $permissionsAdded,
            coAuthoredByDisabled: $coAuthoredByDisabled,
            orphanedTargets: $syncCounts->orphanedTargets,
        ));

        if ($subagentWritesEnabled) {
            echo sprintf('Allowed subagent file writes (Edit/Write on the working tree) in .claude/settings.local.json.%s', PHP_EOL);
        }

        return 0;
    }

    /**
     * @return array<int, array{0: string, 1: array<int, string>}>
     */
    private static function collectSyncPayloads(string $root): array
    {
        $payloads = [
            [InstallerPath::resolveRulesSource($root), InstallerPath::resolveRulesTargetDirectories($root)],
        ];

        $skillsSource = InstallerPath::resolveSkillsSource();

        if ($skillsSource !== null) {
            $payloads[] = [$skillsSource, InstallerPath::resolveSkillsTargetDirectories($root)];
        }

        $agentsSource = InstallerPath::resolveAgentsSource();

        if ($agentsSource !== null) {
            $payloads[] = [$agentsSource, InstallerPath::resolveAgentsTargetDirectories($root)];
        }

        return $payloads;
    }

    /**
     * @param array<int, array{0: string, 1: array<int, string>}> $payloads
     */
    private static function runAllSyncs(array $payloads, bool $force, bool $symlink, bool $prune): SyncCounts
    {
        $total = new SyncCounts(copied: 0, pruned: 0, orphaned: 0);

        foreach ($payloads as [$source, $targets]) {
            $total = $total->add(self::syncDirectories($source, $targets, $force, $symlink, $prune));
        }

        return $total;
    }

    private static function reportInstallSummary(InstallSummary $summary): void
    {
        echo sprintf('Rules and skills installed (%d files, %d pruned).%s', $summary->copied, $summary->pruned, PHP_EOL);

        if ($summary->orphaned > 0) {
            $targetsSuffix = $summary->orphanedTargets === [] ? '' : sprintf(' (%s)', implode(', ', $summary->orphanedTargets));
            // "across the target directories", not "in target": $summary->orphaned sums the count
            // across every target of every payload, so the same relative path orphaned in more
            // than one target (e.g. both `.claude/skills` and `~/.claude/skills`) is counted once
            // per target — a singular "in target" would misleadingly imply exactly one directory.
            echo sprintf(
                '%d file(s) across the target directories no longer exist in source. Re-run with --prune to remove them.%s%s',
                $summary->orphaned,
                $targetsSuffix,
                PHP_EOL,
            );
        }

        if ($summary->permissionsAdded > 0) {
            echo sprintf('Allowed %d bundled-script permission(s) in ~/.claude/settings.json.%s', $summary->permissionsAdded, PHP_EOL);
        }

        if ($summary->coAuthoredByDisabled) {
            echo sprintf('Disabled AI co-author attribution (includeCoAuthoredBy: false) in ~/.claude/settings.json.%s', PHP_EOL);
        }
    }

    /**
     * @param array<int, string> $targets
     */
    private static function syncDirectories(string $source, array $targets, bool $force, bool $symlink, bool $prune): SyncCounts
    {
        $copied = 0;
        $pruned = 0;
        $orphaned = 0;
        $orphanedTargets = [];
        // Listed once per payload instead of once per target — `installDirectory()`,
        // `pruneDirectory()`, and `findOrphans()` all reuse this single listing instead of each
        // re-walking the identical source tree for every target of this payload.
        $sourceFiles = InstallerPruner::listSourceFiles($source);

        foreach ($targets as $target) {
            $copied += InstallerFileCopier::installDirectory($source, $target, $force, $symlink, $sourceFiles);

            if ($prune) {
                $pruned += InstallerPruner::pruneDirectory($source, $target, $sourceFiles);
            } else {
                $targetOrphanCount = count(InstallerPruner::findOrphans($source, $target, $sourceFiles));
                $orphaned += $targetOrphanCount;

                if ($targetOrphanCount > 0) {
                    $orphanedTargets[] = $target;
                }
            }
        }

        return new SyncCounts(copied: $copied, pruned: $pruned, orphaned: $orphaned, orphanedTargets: $orphanedTargets);
    }

}
