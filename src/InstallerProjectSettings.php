<?php

declare(strict_types = 1);

namespace AgenticVibes\AgentSkills;

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
     * idempotently, so a dispatched subagent (e.g. `hefaistos`) may write files without
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

}
