<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

use stdClass;

/**
 * The installer's writers for the **project-local** settings file,
 * `<project>/.claude/settings.local.json`.
 *
 * Split from `InstallerClaudeSettings` (issue #202), which keeps the home-level
 * `~/.claude/settings.json` half. The two targets arrived at different times and share nothing
 * but the read/write round-trip and the permission-list handling, both of which live in
 * `InstallerSettingsFile`. Keeping them apart is what lets a reader answer "which file does this
 * write?" from the class name alone — the question that decides whether a permission reaches one
 * project or every project on the machine.
 */
final class InstallerProjectSettings
{

    /**
     * The `PreToolUse` handler the removed `--enforce-agent-bash-boundary` flag used to write. The
     * flag, the validator, and the `bash-guard` subcommand were all deleted in issue #265, and the
     * flag never had an inverse — so a project that opted in still carries a handler pointing at a
     * subcommand the binary no longer has. It falls through to `Installer::run()`, prints
     * `Unknown command: bash-guard`, and exits 1, which Claude Code reprints as a non-blocking
     * `PreToolUse:Bash hook error` on **every** Bash call (issue #6).
     *
     * A pattern rather than one literal tail, because the writer emitted the subcommand in more
     * than one shape and under a binary name this package no longer carries.
     * `InstallerBashGuard::buildCommand()` returned `<binary> bash-guard` and single-quoted any
     * binary path failing `/^[A-Za-z0-9_@%+=:,.\/-]+$/` — that is every path holding a space,
     * ordinary on macOS — which puts the closing quote between the binary name and the subcommand.
     * The binary was `agent-skills`: `bash-guard` was deleted five days before the rebrand renamed
     * it to `ai-olympus`, so no settings file written in the field carries the new name at all.
     * Both names are accepted regardless, because a hand-edited file may carry either.
     *
     * Anchored at the end of the command, and the subcommand must follow this package's own binary
     * name: a command that merely mentions `bash-guard` — a log path, a wrapper, a guard the
     * project wrote itself — is never removed on this package's authority.
     */
    private const string ORPHANED_BASH_GUARD_PATTERN = '/(?:^|\/)(?:agent-skills|ai-olympus)\'?[ \t]+bash-guard$/';

    /**
     * Outbound-network Bash commands denied session-wide when the caller opts in
     * (`--deny-network-bash`).
     *
     * `:*` (not ` *`) is deliberate: the two forms are documented as equivalent trailing
     * wildcards, and `:*` is the form `InstallerClaudeSettings::getBundledScriptPermissions()`
     * already uses — do not "fix" one to match the other. The suffix also enforces a word
     * boundary, which is why `ncat` / `netcat` / `telnet` / `sftp` are listed separately instead
     * of being covered by the shorter names next to them: `Bash(nc:*)` does not match `ncat`.
     *
     * `openssl` is denied only through its network subcommand `s_client`. A deny rule cannot
     * carry allow-list exceptions, so a bare `Bash(openssl:*)` would permanently block
     * `openssl dgst` — the checksum verification `@rules/security/backend.md` requires — along
     * with `rand`, `x509`, and `enc`.
     *
     * @return array<int, string>
     */
    public static function getNetworkBashDenyPermissions(): array
    {
        return [
            'Bash(curl:*)',
            'Bash(wget:*)',
            'Bash(nc:*)',
            'Bash(ncat:*)',
            'Bash(netcat:*)',
            'Bash(telnet:*)',
            'Bash(ssh:*)',
            'Bash(scp:*)',
            'Bash(sftp:*)',
            'Bash(openssl s_client:*)',
        ];
    }

    public static function resolveProjectLocalSettingsPath(string $projectRoot): string
    {
        return $projectRoot . '/.claude/settings.local.json';
    }

    /**
     * Enables dispatched-subagent file writes only when the caller opted in
     * (`--allow-subagent-writes`). Returns true when at least one allow entry
     * was newly written; false in every other case. Opt-in by design — this
     * grants a write permission, so it stays an explicit, human-owned decision.
     */
    public static function applySubagentWritesIfRequested(bool $allowSubagentWrites, string $projectRoot): bool
    {
        if (!$allowSubagentWrites) {
            return false;
        }

        return self::ensureSubagentWritesEnabled($projectRoot);
    }

    /**
     * Prepends scoped `Edit` / `Write` permission entries for the project working
     * tree to `permissions.allow` in the project's `.claude/settings.local.json`,
     * idempotently, so a dispatched subagent (e.g. `hephaestus`) may write files without
     * interactive approval. Existing allow entries and unrelated keys are preserved.
     * The written file is re-read and validated so a malformed file can never be
     * accepted. Returns true only when at least one entry was added.
     */
    public static function ensureSubagentWritesEnabled(string $projectRoot): bool
    {
        $settingsPath = self::resolveProjectLocalSettingsPath($projectRoot);
        $existing = InstallerSettingsFile::read($settingsPath);
        $required = self::buildSubagentWritePermissions($projectRoot);

        if (!self::prependAllowEntries($existing, $required)) {
            return false;
        }

        InstallerPath::ensureDirectory(dirname($settingsPath));
        InstallerSettingsFile::write($settingsPath, $existing);

        self::validateSubagentWritePermissions(InstallerSettingsFile::read($settingsPath), $required, $settingsPath);

        return true;
    }

    /**
     * Validates that every required subagent-write permission entry is present in
     * `permissions.allow` as a string. Throws InstallerFailure on any deviation so an
     * invalid config is never written or accepted.
     *
     * @param array<int, string> $required
     */
    public static function validateSubagentWritePermissions(stdClass $data, array $required, string $path): void
    {
        $allow = InstallerSettingsFile::extractPermissionList($data, 'allow');

        foreach ($required as $entry) {
            if (!in_array($entry, $allow, strict: true)) {
                throw InstallerFailure::settingsSubagentWritesInvalid($path, sprintf('missing allow entry "%s"', $entry));
            }
        }
    }

    /**
     * Denies outbound-network Bash commands only when the caller opted in
     * (`--deny-network-bash`). Returns true when at least one deny entry was newly
     * written; false in every other case. Opt-in by design — the restriction applies
     * session-wide (every agent and the human alike, never per agent), so only the
     * consuming project's own maintainer can decide it is worth the trade-off.
     *
     * The target is the project-local settings file, so — unlike
     * `InstallerClaudeSettings::applyIfRequested()` — there is no `HOME` precondition that could
     * turn a security control into a silent no-op, and a deny written here can never reach
     * another project on the machine.
     */
    public static function applyNetworkBashDenyIfRequested(bool $denyNetworkBash, string $projectRoot): bool
    {
        if (!$denyNetworkBash) {
            return false;
        }

        return self::ensureNetworkBashDenyPermissions($projectRoot);
    }

    /**
     * Appends the missing network-command patterns to `permissions.deny` in the project's
     * `.claude/settings.local.json`, idempotently. Existing `permissions.allow` entries and
     * unrelated keys are left untouched; foreign *string* entries already in
     * `permissions.deny` are preserved in place and nothing is ever reordered — rule order
     * is irrelevant for a deny. A non-string item in `permissions.deny` (a number, `null`,
     * an object) is dropped when the list is rewritten: `InstallerSettingsFile::resolvePermissionList()`
     * sanitises the list to strings, and such an item is not a rule the harness could enforce
     * anyway. The written file is re-read and validated so a malformed file can never be accepted.
     * Returns true only when at least one entry was added.
     */
    public static function ensureNetworkBashDenyPermissions(string $projectRoot): bool
    {
        $settingsPath = self::resolveProjectLocalSettingsPath($projectRoot);
        $existing = InstallerSettingsFile::read($settingsPath);
        $required = self::getNetworkBashDenyPermissions();

        if (!self::appendDenyEntries($existing, $required)) {
            return false;
        }

        InstallerPath::ensureDirectory(dirname($settingsPath));
        InstallerSettingsFile::write($settingsPath, $existing);

        self::validateNetworkBashDenyPermissions(InstallerSettingsFile::read($settingsPath), $required, $settingsPath);

        return true;
    }

    /**
     * Validates that every required network-deny pattern is present in `permissions.deny`
     * as a string. Throws InstallerFailure on any deviation, so the installer can never
     * report a security control as applied when it was not actually written.
     *
     * @param array<int, string> $required
     */
    public static function validateNetworkBashDenyPermissions(stdClass $data, array $required, string $path): void
    {
        $deny = InstallerSettingsFile::extractPermissionList($data, 'deny');

        foreach ($required as $entry) {
            if (!in_array($entry, $deny, strict: true)) {
                throw InstallerFailure::settingsNetworkBashDenyInvalid($path, sprintf('missing deny entry "%s"', $entry));
            }
        }
    }

    /**
     * Reads the `permissions.deny` list from a project's `.claude/settings.local.json`,
     * sanitised to strings only. Returns an empty list when the file does not exist or
     * the section is missing.
     *
     * @return array<int, string>
     */
    public static function loadProjectLocalDenyList(string $projectRoot): array
    {
        $settingsPath = self::resolveProjectLocalSettingsPath($projectRoot);

        return InstallerSettingsFile::extractPermissionList(InstallerSettingsFile::read($settingsPath), 'deny');
    }

    /**
     * Deletes every hook handler in the project's `.claude/settings.local.json` whose command
     * points at the removed `bash-guard` subcommand, and returns how many were deleted.
     *
     * Runs on every `install`, with no flag of its own. That is deliberate: the entry being
     * deleted is one **this package wrote itself**, pointing at a command it no longer ships, and
     * the removal in #265 shipped without the inverse the flag never had. Cleaning up its own
     * leftover is not a compatibility layer for `bash-guard` — the subcommand stays gone and
     * `ai-olympus bash-guard` still exits 1 — it is the package taking back a write that now only
     * produces an error on every Bash call.
     *
     * A file the run has nothing to strip is never rewritten, and a project without the file
     * never gets one: the write happens only after a handler was actually found and removed.
     */
    public static function removeOrphanedBashGuardHandlers(string $projectRoot): int
    {
        $settingsPath = self::resolveProjectLocalSettingsPath($projectRoot);
        $existing = InstallerSettingsFile::read($settingsPath);
        $removed = self::stripOrphanedBashGuardHandlers($existing);

        if ($removed === 0) {
            return 0;
        }

        InstallerSettingsFile::write($settingsPath, $existing);

        return $removed;
    }

    /**
     * @return array<int, string>
     */
    private static function buildSubagentWritePermissions(string $projectRoot): array
    {
        return [
            sprintf('Edit(/%s/**)', $projectRoot),
            sprintf('Write(/%s/**)', $projectRoot),
        ];
    }

    /**
     * Prepends the missing entries to `permissions.allow` (preserving order and
     * existing entries) and recovers when `permissions` / `allow` carry the wrong
     * shape. Returns true only when at least one entry was added.
     *
     * @param array<int, string> $entries
     */
    private static function prependAllowEntries(stdClass $existing, array $entries): bool
    {
        [$permissions, $allow] = InstallerSettingsFile::resolvePermissionList($existing, 'allow');
        $missing = array_values(array_filter($entries, static fn (string $entry): bool => !in_array($entry, $allow, strict: true)));

        if ($missing === []) {
            return false;
        }

        $permissions->allow = [...$missing, ...$allow];
        $existing->permissions = $permissions;

        return true;
    }

    /**
     * Appends the missing entries to `permissions.deny`, preserving order and existing
     * entries. Appended rather than prepended because rule order carries no meaning for a
     * deny — the harness evaluates deny before ask before allow regardless of position.
     *
     * @param array<int, string> $entries
     */
    private static function appendDenyEntries(stdClass $existing, array $entries): bool
    {
        [$permissions, $deny] = InstallerSettingsFile::resolvePermissionList($existing, 'deny');
        $missing = array_values(array_filter($entries, static fn (string $entry): bool => !in_array($entry, $deny, strict: true)));

        if ($missing === []) {
            return false;
        }

        $permissions->deny = [...$deny, ...$missing];
        $existing->permissions = $permissions;

        return true;
    }

    /**
     * Walks every hook event, not only `PreToolUse` where the removed flag wrote its handler: the
     * predicate identifies a handler pointing at a subcommand the binary no longer has, and such a
     * handler is dead — and prints the same error — under whichever event it sits.
     */
    private static function stripOrphanedBashGuardHandlers(stdClass $existing): int
    {
        $hooks = $existing->hooks ?? null;

        if (!$hooks instanceof stdClass) {
            return 0;
        }

        $removed = 0;

        foreach (get_object_vars($hooks) as $event => $groups) {
            $removed += is_array($groups) ? self::stripEventGroups($hooks, $event, $groups) : 0;
        }

        // The `hooks` key outliving its last handler would leave the package's own leftover behind
        // in a different shape, so it goes with the handler it existed to hold.
        if ($removed > 0 && get_object_vars($hooks) === []) {
            unset($existing->hooks);
        }

        return $removed;
    }

    /**
     * @param array<array-key, mixed> $groups
     */
    private static function stripEventGroups(stdClass $hooks, string $event, array $groups): int
    {
        $removed = 0;
        $retained = [];

        foreach ($groups as $group) {
            [$keepGroup, $removedFromGroup] = self::stripGroupHandlers($group);
            $removed += $removedFromGroup;
            $retained = $keepGroup ? [...$retained, $group] : $retained;
        }

        if ($removed === 0) {
            return 0;
        }

        if ($retained === []) {
            unset($hooks->{$event});

            return $removed;
        }

        $hooks->{$event} = $retained;

        return $removed;
    }

    /**
     * Strips the orphaned handlers from one matcher group, in place. Returns whether the group
     * itself survives — a group emptied by this run is dropped, while a group that was already
     * empty before it is left exactly as the project wrote it.
     *
     * @return array{0: bool, 1: int}
     */
    private static function stripGroupHandlers(mixed $group): array
    {
        if (!$group instanceof stdClass) {
            return [true, 0];
        }

        $handlers = $group->hooks ?? null;

        if (!is_array($handlers)) {
            return [true, 0];
        }

        $retained = self::retainedHandlers($handlers);
        $removed = count($handlers) - count($retained);

        if ($removed === 0) {
            return [true, 0];
        }

        if ($retained === []) {
            return [false, $removed];
        }

        $group->hooks = $retained;

        return [true, $removed];
    }

    /**
     * @param array<array-key, mixed> $handlers
     * @return array<int, mixed>
     */
    private static function retainedHandlers(array $handlers): array
    {
        $isForeign = static fn (mixed $handler): bool => !self::isOrphanedBashGuardHandler($handler);

        return array_values(array_filter($handlers, $isForeign));
    }

    private static function isOrphanedBashGuardHandler(mixed $handler): bool
    {
        $command = $handler instanceof stdClass ? $handler->command ?? null : null;

        return is_string($command) && preg_match(self::ORPHANED_BASH_GUARD_PATTERN, trim($command)) === 1;
    }

}
