<?php

declare(strict_types = 1);

namespace AgenticVibes\AgentSkills;

use Closure;
use JsonException;
use stdClass;

/**
 * The `bash-guard` binary a `PreToolUse` hook entry has to point at, resolved and proven to run.
 *
 * Separate from `InstallerHookSettings` on purpose: that class writes the settings file, this one
 * answers "which binary, and does it actually run". Putting a process-spawning caller inside a
 * settings writer would give it a second responsibility it has no reason to carry, and the split
 * keeps the executor injectable so no test ever invokes an external binary.
 */
final readonly class InstallerBashGuard
{

    /**
     * A `command` hook defaults to a 600-second timeout. A validator that hangs would then stall
     * every Bash call for ten minutes, so the entry always carries an explicit, short one — the
     * validator does no I/O and cannot legitimately need more.
     */
    public const int HOOK_TIMEOUT_SECONDS = 10;

    /**
     * Characters that need no shell quoting at all. Anything outside this set (a space in the
     * path, most commonly on macOS) is single-quoted instead.
     */
    private const string SHELL_SAFE_PATTERN = '/^[A-Za-z0-9_@%+=:,.\/-]+$/';

    private function __construct(public string $binary, public string $command)
    {
    }

    /**
     * Finds the `agent-skills` entry point and builds the hook command line for it.
     *
     * Two locations, in this order: the consuming project's `vendor/bin/agent-skills`, and this
     * package's own `bin/agent-skills` for a root checkout — Composer creates no bin proxy for the
     * root package, so `vendor/bin/agent-skills` genuinely does not exist while dogfooding.
     *
     * An absolute path is written rather than `${CLAUDE_PROJECT_DIR}/…`: it is the exact path the
     * smoke test below proves executable, `settings.local.json` is git-ignored and machine-local
     * already (the same reason `--allow-subagent-writes` writes absolute paths there), and an
     * unset `CLAUDE_PROJECT_DIR` would turn the entry into a silent no-op.
     *
     * Throws rather than returning null: a hook entry that cannot run is worse than no hook at
     * all, because the summary line would report a protection the user does not have.
     *
     * `$packageRoot` defaults to this package's own directory and is a parameter only so a test
     * can reach the not-found and quoting paths — the package's real `bin/agent-skills` always
     * exists, so those branches are otherwise unreachable without deleting it.
     */
    public static function resolve(string $projectRoot, ?string $packageRoot = null): self
    {
        $projectCandidate = $projectRoot . '/vendor/bin/agent-skills';
        $packageCandidate = ($packageRoot ?? dirname(__DIR__)) . '/bin/agent-skills';
        $binary = self::firstExistingFile($projectCandidate, $packageCandidate);

        if ($binary === null) {
            throw InstallerFailure::bashGuardBinaryMissing($projectCandidate, $packageCandidate);
        }

        return new self($binary, self::buildCommand($binary));
    }

    /**
     * Runs the resolved binary once, before anything is written.
     *
     * Reading the settings file back proves the JSON landed, never that the command inside it
     * executes — and every failure of a `PreToolUse` hook is fail-open, so a binary that cannot
     * start (no `php` on the hook's PATH, a `--no-dev` vendor tree, a broken autoloader) would
     * leave the user with a reported protection and no actual one. `bash-guard --self-test` feeds
     * the validator a fixed forbidden command, so a healthy binary must answer `deny`.
     *
     * @param \Closure(list<string>, bool): \AgenticVibes\AgentSkills\CommandResult|null $processExecutor
     */
    public function smokeTest(?Closure $processExecutor): void
    {
        if ($processExecutor === null) {
            throw InstallerFailure::bashGuardUnverifiable($this->binary);
        }

        $result = $processExecutor([$this->binary, 'bash-guard', '--self-test'], true);

        if ($result->exitCode !== 0) {
            throw InstallerFailure::bashGuardSmokeTestFailed($this->binary, sprintf('exit code %d', $result->exitCode));
        }

        $decision = $this->decisionOf($result->output);

        if ($decision !== BashBoundaryDecision::Deny->value) {
            throw InstallerFailure::bashGuardSmokeTestFailed(
                $this->binary,
                sprintf('expected a "%s" decision, got %s', BashBoundaryDecision::Deny->value, $decision ?? 'no decision at all'),
            );
        }
    }

    private function decisionOf(string $output): ?string
    {
        try {
            $data = json_decode(trim($output), associative: false, depth: 16, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!$data instanceof stdClass) {
            return null;
        }

        $hookOutput = $data->hookSpecificOutput ?? null;

        if (!$hookOutput instanceof stdClass) {
            return null;
        }

        $decision = $hookOutput->permissionDecision ?? null;

        return is_string($decision) ? $decision : null;
    }

    private static function firstExistingFile(string ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The hook `command` is handed to a shell, so a path carrying a space would otherwise be split
     * into an unrunnable command. A path holding a single quote or a newline cannot be expressed
     * safely this way at all and is refused rather than mangled.
     */
    private static function buildCommand(string $binary): string
    {
        if (preg_match(self::SHELL_SAFE_PATTERN, $binary) === 1) {
            return $binary . ' bash-guard';
        }

        if (str_contains($binary, '\'') || str_contains($binary, "\n")) {
            throw InstallerFailure::bashGuardCommandUnquotable($binary);
        }

        return sprintf('\'%s\' bash-guard', $binary);
    }

}
