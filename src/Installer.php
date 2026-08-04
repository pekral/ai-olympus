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
        echo "  vendor/bin/agent-skills install [--force] [--symlink] [--prune] [--global] [--prune-global]\n";
        echo "                                 [--allow-bundled-scripts] [--allow-subagent-writes] [--deny-network-bash]\n";
        echo "                                 [--enforce-agent-bash-boundary]\n";
        echo "  vendor/bin/agent-skills resolve-next [--label=NAME] [--repo=OWNER/NAME] [--merge] [--dry-run]\n\n";
        echo "Commands:\n";
        echo "  install                 Install rules, skills, and agents for Claude Code.\n";
        echo "  resolve-next            Hand the oldest unclaimed labelled issue to Claude Code as one agent run.\n\n";
        self::showInstallOptions();
        self::showResolveNextOptions();

        return 0;
    }

    private static function showInstallOptions(): void
    {
        echo "Options:\n  --force                 Overwrite existing files.\n";
        echo "  --symlink               Create symlinks instead of copying (falls back to copy on Windows).\n";
        echo "  --prune                 Remove files in target that no longer exist in source.\n";
        echo "  --global                Also install skills to ~/.claude/skills. Off by default: Claude Code lets a personal\n";
        echo "                          skill override a project one, so a home copy shadows this checkout everywhere.\n";
        echo "  --prune-global          Remove this package's skills from ~/.claude/skills so the project copy loads.\n";
        echo "                          Leaves skills from other sources untouched. Cannot be combined with --global.\n";
        echo "  --allow-bundled-scripts Whitelist bundled scripts (load-issue.sh) in ~/.claude/settings.json. Opt-in.\n";
        echo "  --allow-subagent-writes Allow dispatched-subagent file writes by adding scoped Edit/Write entries for the project\n";
        echo "                          tree to permissions.allow in .claude/settings.local.json. Opt-in.\n";
        echo "  --deny-network-bash     Deny outbound-network Bash commands (curl, wget, nc, ssh, scp, openssl s_client, ...)\n";
        echo "                          via permissions.deny in .claude/settings.local.json. Opt-in. The rule is session-wide\n";
        echo "                          and project-scoped: within this project it applies to every agent AND to your own\n";
        echo "                          interactive Bash, never per agent. Not an egress control - see SECURITY.md.\n";
        echo "  --enforce-agent-bash-boundary\n";
        echo "                          Register a PreToolUse hook in .claude/settings.local.json that runs this package's\n";
        echo "                          per-agent Bash boundary validator before every Bash call. Opt-in. Restart your\n";
        echo "                          Claude Code session afterwards - hooks are read once at session start. Fails open\n";
        echo "                          on an untrusted workspace and in several other cases - see SECURITY.md.\n";
    }

    private static function showResolveNextOptions(): void
    {
        echo "  --label=NAME            resolve-next: only consider issues carrying this label. Repeatable (all must match).\n";
        echo '                          Defaults to ' . AgenticOptions::DEFAULT_LABEL . ".\n";
        echo "  --repo=OWNER/NAME       resolve-next: target another repository instead of the current checkout.\n";
        echo "  --merge                 resolve-next: merge the pull request once review converges. Off by default, so an\n";
        echo "                          unattended run leaves it for a human.\n";
        echo "  --dry-run               resolve-next: print the issue and the prompt without starting an agent run.\n";
    }

    private static function install(InstallOptions $options): int
    {
        if ($options->global && $options->pruneGlobal) {
            fwrite(STDERR, '--global and --prune-global are mutually exclusive: one installs the home skills copy, the other removes it.' . PHP_EOL);

            return 1;
        }

        $root = InstallerPath::resolveProjectRoot();
        $syncCounts = self::runAllSyncs(self::collectSyncPayloads($root, $options->global), $options->force, $options->symlink, $options->prune);

        $copied = $syncCounts->copied + InstallerFileCopier::installSingleFile(
            InstallerPath::resolveClaudeMdSource(),
            InstallerPath::resolveClaudeMdTarget($root),
        );
        $permissionsAdded = InstallerClaudeSettings::applyIfRequested($options->allowBundledScripts);
        $coAuthoredByDisabled = InstallerClaudeSettings::applyCoAuthoredByPreference();
        $subagentWritesEnabled = InstallerClaudeSettings::applySubagentWritesIfRequested($options->allowSubagentWrites, $root);
        $networkBashDenied = InstallerClaudeSettings::applyNetworkBashDenyIfRequested($options->denyNetworkBash, $root);

        self::reportInstallSummary(new InstallSummary(
            copied: $copied,
            pruned: $syncCounts->pruned,
            orphaned: $syncCounts->orphaned,
            permissionsAdded: $permissionsAdded,
            coAuthoredByDisabled: $coAuthoredByDisabled,
            orphanedTargets: $syncCounts->orphanedTargets,
        ));

        self::reportProjectLocalSettings($subagentWritesEnabled, $networkBashDenied);
        self::reportGlobalInstall($options->global);
        self::pruneGlobalSkillsIfRequested($options->pruneGlobal, $root);

        return 0;
    }

    /**
     * Reports the two opt-in writes to the project's `.claude/settings.local.json`.
     * Each line is printed only when that write actually happened, never on the mere
     * presence of its flag: an installer that reports a permission change it did not
     * apply leaves the user believing in a restriction that does not exist.
     */
    private static function reportProjectLocalSettings(bool $subagentWritesEnabled, bool $networkBashDenied): void
    {
        if ($subagentWritesEnabled) {
            echo sprintf('Allowed subagent file writes (Edit/Write on the working tree) in .claude/settings.local.json.%s', PHP_EOL);
        }

        if ($networkBashDenied) {
            echo sprintf(
                'Denied outbound-network Bash commands (curl, wget, nc, ssh, scp, openssl s_client, ...) session-wide in .claude/settings.local.json.%s',
                PHP_EOL,
            );
        }
    }

    private static function reportGlobalInstall(bool $global): void
    {
        if (!$global) {
            return;
        }

        $homeSkills = InstallerPath::resolveHomeSkillsDirectory();

        // Reporting the home install unconditionally would claim a copy that was never written:
        // with neither HOME nor USERPROFILE set there is no home skills directory to install to.
        echo $homeSkills === null
            ? sprintf('--global had no effect: neither HOME nor USERPROFILE is set, so there is no home skills directory.%s', PHP_EOL)
            : sprintf('Skills also installed to %s; a home skill overrides the project copy in every project.%s', $homeSkills, PHP_EOL);
    }

    private static function pruneGlobalSkillsIfRequested(bool $pruneGlobal, string $root): void
    {
        $skillsSource = InstallerPath::resolveSkillsSource();

        if (!$pruneGlobal || $skillsSource === null) {
            return;
        }

        $removed = InstallerGlobalSkills::prune($skillsSource, $root);

        echo $removed === []
            ? sprintf('No skills from this package were left in the home skills directory.%s', PHP_EOL)
            : sprintf('Removed %d shadowing skill(s) from the home skills directory: %s.%s', count($removed), implode(', ', $removed), PHP_EOL);
    }

    /**
     * @return array<int, array{0: string, 1: array<int, string>}>
     */
    private static function collectSyncPayloads(string $root, bool $global): array
    {
        $payloads = [
            [InstallerPath::resolveRulesSource($root), InstallerPath::resolveRulesTargetDirectories($root)],
        ];

        $skillsSource = InstallerPath::resolveSkillsSource();

        if ($skillsSource !== null) {
            $payloads[] = [$skillsSource, InstallerPath::resolveSkillsTargetDirectories($root, $global)];
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
