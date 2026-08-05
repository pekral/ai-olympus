<?php

declare(strict_types = 1);

namespace AgenticVibes\AgentSkills;

use Closure;
use stdClass;

/**
 * Writes the `hooks.PreToolUse` entry that turns the per-agent Bash boundary from documentation
 * into a decision the harness asks for before a Bash call runs (issue #185).
 *
 * It mirrors the shape of `InstallerClaudeSettings`'s opt-in writers method for method — an
 * idempotent, additive write followed by a read-back that validates what actually landed, because
 * a security control must never report success it did not apply — and shares that class's
 * settings round-trip through `InstallerSettingsFile` rather than copying it. It is a separate
 * class only because `InstallerClaudeSettings` already sits at the project's maximum class length;
 * splitting that class by concern is issue #202 and is not attempted here.
 */
final class InstallerHookSettings
{

    /**
     * The one `PreToolUse` matcher this package registers. `Bash` is the only tool whose input is
     * a command string the validator can read; `Write` / `Edit` / `WebFetch` and MCP tools are
     * out of its reach and deliberately not matched.
     */
    private const string BASH_MATCHER = 'Bash';

    /**
     * Registers the per-agent Bash boundary validator as a `PreToolUse` hook only when the caller
     * opted in (`--enforce-agent-bash-boundary`). Returns true when the entry was newly written;
     * false in every other case.
     *
     * The binary is resolved and test-run **before** anything is written, so the flag fails loudly
     * instead of leaving behind a hook entry that could never run. Off by default like every other
     * security flag: this one starts running package code ahead of every Bash call in the session,
     * which only the consuming project's own maintainer can decide is worth it.
     *
     * Why the project-local settings file and not the agent frontmatter, which would read more
     * literally as "per agent": frontmatter hooks were reported not to fire for Task-dispatched
     * subagents at all (anthropics/claude-code#18392), they carry a workspace-trust gate of their
     * own, and `agents/*.md` is copied to every consumer unconditionally — a `hooks:` line there
     * would ship this to projects that never asked for it, which is exactly what "the package
     * grants no additional permissions by default" rules out. One entry with `matcher: "Bash"`
     * plus the `agent_type` the payload already carries produces the same per-agent decision from
     * a single, testable place. It is written to `settings.local.json` rather than
     * `~/.claude/settings.json` for the same reason `--deny-network-bash` is: hook and permission
     * scopes merge, so a user-level entry would apply to every project on the machine and could
     * not be relaxed by one that needs it off.
     *
     * @param \Closure(list<string>, bool): \AgenticVibes\AgentSkills\CommandResult|null $processExecutor
     */
    public static function applyAgentBashBoundaryIfRequested(bool $enforceAgentBashBoundary, string $projectRoot, ?Closure $processExecutor): bool
    {
        if (!$enforceAgentBashBoundary) {
            return false;
        }

        $guard = InstallerBashGuard::resolve($projectRoot);
        $guard->smokeTest($processExecutor);

        return self::ensureAgentBashBoundaryHook($projectRoot, $guard->command);
    }

    /**
     * Adds one `PreToolUse` handler for the `Bash` matcher to the project's
     * `.claude/settings.local.json`, idempotently. Foreign matcher groups, foreign handlers inside
     * the `Bash` group, the `permissions` block, and every unrelated key are left in place and in
     * order — nothing is ever overwritten or reordered. One precise exception, mirroring the
     * non-string drop in `permissions.deny`: an item inside `hooks.PreToolUse`, or inside a
     * group's own `hooks` list, that is not a JSON object is dropped when that list is rewritten,
     * because it is not a handler Claude Code could run in the first place. The written file is
     * re-read and validated, so a hook that did not actually land can never be reported as
     * applied. Returns true only when the handler was added.
     */
    public static function ensureAgentBashBoundaryHook(string $projectRoot, string $command): bool
    {
        $settingsPath = InstallerClaudeSettings::resolveProjectLocalSettingsPath($projectRoot);
        $existing = InstallerSettingsFile::read($settingsPath);

        if (!self::appendBashHookHandler($existing, $command)) {
            return false;
        }

        InstallerPath::ensureDirectory(dirname($settingsPath));
        InstallerSettingsFile::write($settingsPath, $existing);

        self::validateAgentBashBoundaryHook(InstallerSettingsFile::read($settingsPath), $command, $settingsPath);

        return true;
    }

    /**
     * Validates that the `Bash` matcher carries a `command` handler with exactly this command and
     * an explicit timeout. The timeout is checked too, not only the command: a handler that lost
     * it falls back to the vendor default of 600 seconds, which would stall every Bash call for
     * ten minutes if the validator ever hung. Throws InstallerFailure on any deviation, so the
     * installer can never report a security control as applied when it was not actually written.
     */
    public static function validateAgentBashBoundaryHook(stdClass $data, string $command, string $path): void
    {
        foreach (self::extractBashHookHandlers($data) as $handler) {
            if (($handler->type ?? null) !== 'command' || ($handler->command ?? null) !== $command) {
                continue;
            }

            if (($handler->timeout ?? null) !== InstallerBashGuard::HOOK_TIMEOUT_SECONDS) {
                throw InstallerFailure::settingsAgentBashBoundaryHookInvalid($path, 'the hook entry carries no explicit timeout');
            }

            return;
        }

        throw InstallerFailure::settingsAgentBashBoundaryHookInvalid($path, sprintf('missing PreToolUse Bash hook command "%s"', $command));
    }

    /**
     * Reads the commands of every `PreToolUse` `command` handler registered for the `Bash` matcher
     * in a project's `.claude/settings.local.json`. Returns an empty list when the file does not
     * exist or the section is missing.
     *
     * @return array<int, string>
     */
    public static function loadProjectLocalBashHookCommands(string $projectRoot): array
    {
        $settingsPath = InstallerClaudeSettings::resolveProjectLocalSettingsPath($projectRoot);
        $commands = [];

        foreach (self::extractBashHookHandlers(InstallerSettingsFile::read($settingsPath)) as $handler) {
            $command = $handler->command ?? null;

            if (($handler->type ?? null) === 'command' && is_string($command)) {
                $commands[] = $command;
            }
        }

        return $commands;
    }

    /**
     * Appends this package's handler to the `Bash` matcher group, creating the group (and the
     * `hooks` / `hooks.PreToolUse` containers) when absent or of the wrong shape. Returns false —
     * leaving the settings object untouched — only when a handler equal to the one this package
     * writes is already there, timeout included.
     *
     * "Already there" deliberately means all three of `type`, `command`, and `timeout`, not the
     * command alone. Matching on the command alone would let a `{"type":"prompt","command":"…
     * bash-guard"}` entry — or one whose timeout had been dropped, silently restoring the vendor
     * default of 600 seconds — count as this package's own handler, and the installer would then
     * report nothing while the protection was not actually registered. A handler that carries this
     * package's command with the wrong timeout is its own entry, identified by that command, so its
     * timeout is corrected in place rather than being duplicated.
     */
    private static function appendBashHookHandler(stdClass $existing, string $command): bool
    {
        [$hooks, $groups] = self::resolvePreToolUseGroups($existing);
        $group = self::findBashMatcherGroup($groups);

        if ($group === null) {
            $group = new stdClass();
            $group->matcher = self::BASH_MATCHER;
            $groups[] = $group;
        }

        $handlers = self::objectItems($group->hooks ?? null);
        $handler = self::findOwnHandler($handlers, $command);

        if ($handler !== null && ($handler->timeout ?? null) === InstallerBashGuard::HOOK_TIMEOUT_SECONDS) {
            return false;
        }

        if ($handler === null) {
            $handlers[] = self::buildAgentBashBoundaryHookEntry($command);
        } else {
            $handler->timeout = InstallerBashGuard::HOOK_TIMEOUT_SECONDS;
        }

        $group->hooks = $handlers;
        $hooks->PreToolUse = $groups;
        $existing->hooks = $hooks;

        return true;
    }

    /**
     * @param array<int, \stdClass> $handlers
     */
    private static function findOwnHandler(array $handlers, string $command): ?stdClass
    {
        foreach ($handlers as $handler) {
            if (($handler->type ?? null) === 'command' && ($handler->command ?? null) === $command) {
                return $handler;
            }
        }

        return null;
    }

    private static function buildAgentBashBoundaryHookEntry(string $command): stdClass
    {
        $entry = new stdClass();
        $entry->type = 'command';
        $entry->command = $command;
        $entry->timeout = InstallerBashGuard::HOOK_TIMEOUT_SECONDS;

        return $entry;
    }

    /**
     * Resolves the settings object's `hooks` container (creating it when absent or of the wrong
     * shape) and its `PreToolUse` list sanitised to objects only.
     *
     * @return array{0: \stdClass, 1: array<int, \stdClass>}
     */
    private static function resolvePreToolUseGroups(stdClass $existing): array
    {
        $hooks = $existing->hooks ?? null;

        if (!$hooks instanceof stdClass) {
            $hooks = new stdClass();
        }

        return [$hooks, self::objectItems($hooks->PreToolUse ?? null)];
    }

    /**
     * @param array<int, \stdClass> $groups
     */
    private static function findBashMatcherGroup(array $groups): ?stdClass
    {
        foreach ($groups as $group) {
            if (($group->matcher ?? null) === self::BASH_MATCHER) {
                return $group;
            }
        }

        return null;
    }

    /**
     * Reads every `PreToolUse` handler registered for the `Bash` matcher off an already-decoded
     * settings object. Unlike `resolvePreToolUseGroups()` this never creates a container — it is
     * the read-only projection the load and validate paths use.
     *
     * @return array<int, \stdClass>
     */
    private static function extractBashHookHandlers(stdClass $data): array
    {
        $hooks = $data->hooks ?? null;

        if (!$hooks instanceof stdClass) {
            return [];
        }

        $group = self::findBashMatcherGroup(self::objectItems($hooks->PreToolUse ?? null));

        return $group === null ? [] : self::objectItems($group->hooks ?? null);
    }

    /**
     * @return array<int, \stdClass>
     */
    private static function objectItems(mixed $value): array
    {
        return is_array($value)
            ? array_values(array_filter($value, static fn (mixed $item): bool => $item instanceof stdClass))
            : [];
    }

}
