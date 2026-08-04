<?php

declare(strict_types = 1);

namespace AgenticVibes\AgentSkills;

use stdClass;

final class InstallerClaudeSettings
{

    /**
     * Bundled scripts that are safe to run without per-call confirmation.
     * Patterns match both project-local (`.claude/skills/.../scripts/...`) and
     * home (`~/.claude/skills/.../scripts/...`) install locations.
     *
     * @return array<int, string>
     */
    public static function getBundledScriptPermissions(): array
    {
        return [
            'Bash(*skills/code-review-github/scripts/load-issue.sh:*)',
            'Bash(*skills/code-review-jira/scripts/load-issue.sh:*)',
        ];
    }

    /**
     * Outbound-network Bash commands denied session-wide when the caller opts in
     * (`--deny-network-bash`).
     *
     * `:*` (not ` *`) is deliberate: the two forms are documented as equivalent trailing
     * wildcards, and `:*` is the form `getBundledScriptPermissions()` above already uses —
     * do not "fix" one to match the other. The suffix also enforces a word boundary, which
     * is why `ncat` / `netcat` / `telnet` / `sftp` are listed separately instead of being
     * covered by the shorter names next to them: `Bash(nc:*)` does not match `ncat`.
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

    public static function resolveSettingsPath(string $home): string
    {
        return $home . '/.claude/settings.json';
    }

    /**
     * Applies bundled-script permissions only when the caller opted in
     * (`--allow-bundled-scripts`) and a usable home directory is available.
     * Returns the number of entries newly added; 0 in every other case.
     */
    public static function applyIfRequested(bool $allowBundledScripts): int
    {
        if (!$allowBundledScripts) {
            return 0;
        }

        $home = InstallerPath::resolveHomeDirectoryOrNull();

        if ($home === null) {
            return 0;
        }

        return self::ensureBundledScriptPermissions($home);
    }

    /**
     * Disables AI co-author attribution in Claude Code commits/PRs by writing
     * `includeCoAuthoredBy: false` into the user's settings, when a usable home
     * directory is available. Returns true when the setting was newly written;
     * false in every other case.
     */
    public static function applyCoAuthoredByPreference(): bool
    {
        $home = InstallerPath::resolveHomeDirectoryOrNull();

        if ($home === null) {
            return false;
        }

        return self::ensureCoAuthoredByDisabled($home);
    }

    /**
     * Sets `includeCoAuthoredBy: false` in `<home>/.claude/settings.json` idempotently.
     * Leaves an existing value untouched so a user who opted back in keeps their choice.
     * Returns true only when the key was absent and is now written.
     */
    public static function ensureCoAuthoredByDisabled(string $home): bool
    {
        $settingsPath = self::resolveSettingsPath($home);
        $existing = InstallerSettingsFile::read($settingsPath);

        if (property_exists($existing, 'includeCoAuthoredBy')) {
            return false;
        }

        $existing->includeCoAuthoredBy = false;

        InstallerPath::ensureDirectory(dirname($settingsPath));
        InstallerSettingsFile::write($settingsPath, $existing);

        return true;
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
     * idempotently, so a dispatched subagent (e.g. `talos`) may write files without
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
        $allow = self::extractAllow($data);

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
     * The target is the project-local settings file, so — unlike `applyIfRequested()`
     * above — there is no `HOME` precondition that could turn a security control into a
     * silent no-op, and a deny written here can never reach another project on the machine.
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
     * an object) is dropped when the list is rewritten: `resolvePermissionList()` sanitises
     * the list to strings, and such an item is not a rule the harness could enforce anyway.
     * The written file is re-read and validated so a malformed file can never be accepted.
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
        $deny = self::extractPermissionList($data, 'deny');

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

        return self::extractPermissionList(InstallerSettingsFile::read($settingsPath), 'deny');
    }

    /**
     * Reads the `permissions.allow` list from `<home>/.claude/settings.json`,
     * sanitised to strings only. Returns an empty list when the file does not
     * exist or the section is missing.
     *
     * @return array<int, string>
     */
    public static function loadAllowList(string $home): array
    {
        $settingsPath = self::resolveSettingsPath($home);
        $data = InstallerSettingsFile::read($settingsPath);

        return self::extractAllow($data);
    }

    /**
     * Adds the bundled-script permission entries to the user's Claude settings file
     * idempotently. Returns the number of entries newly added (0 when nothing changed).
     */
    public static function ensureBundledScriptPermissions(string $home): int
    {
        $settingsPath = self::resolveSettingsPath($home);
        $existing = InstallerSettingsFile::read($settingsPath);
        $existingAllow = self::extractAllow($existing);
        $merged = self::mergePermissions($existing);
        $mergedAllow = self::extractAllow($merged);

        $added = count($mergedAllow) - count($existingAllow);

        if ($added === 0) {
            return 0;
        }

        InstallerPath::ensureDirectory(dirname($settingsPath));
        InstallerSettingsFile::write($settingsPath, $merged);

        return $added;
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
        [$permissions, $allow] = self::resolvePermissionList($existing, 'allow');
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
        [$permissions, $deny] = self::resolvePermissionList($existing, 'deny');
        $missing = array_values(array_filter($entries, static fn (string $entry): bool => !in_array($entry, $deny, strict: true)));

        if ($missing === []) {
            return false;
        }

        $permissions->deny = [...$deny, ...$missing];
        $existing->permissions = $permissions;

        return true;
    }

    /**
     * Resolves the settings object's `permissions` container (creating it when absent
     * or the wrong shape) and one of its lists (`allow` / `deny`) sanitised to strings only.
     *
     * @return array{0: \stdClass, 1: array<int, string>}
     */
    private static function resolvePermissionList(stdClass $existing, string $key): array
    {
        $permissions = $existing->permissions ?? null;

        if (!$permissions instanceof stdClass) {
            $permissions = new stdClass();
        }

        $entries = $permissions->{$key} ?? null;

        if (!is_array($entries)) {
            $entries = [];
        }

        return [$permissions, array_values(array_filter($entries, static fn (mixed $entry): bool => is_string($entry)))];
    }

    private static function mergePermissions(stdClass $existing): stdClass
    {
        [$permissions, $allow] = self::resolvePermissionList($existing, 'allow');

        foreach (self::getBundledScriptPermissions() as $pattern) {
            if (!in_array($pattern, $allow, strict: true)) {
                $allow[] = $pattern;
            }
        }

        $permissions->allow = $allow;
        $existing->permissions = $permissions;

        return $existing;
    }

    /**
     * @return array<int, string>
     */
    private static function extractAllow(stdClass $data): array
    {
        return self::extractPermissionList($data, 'allow');
    }

    /**
     * Reads one `permissions` list (`allow` / `deny`) off an already-decoded settings object,
     * sanitised to strings only. Unlike `resolvePermissionList()` this never creates the
     * container — it is the read-only projection used by the load and validate paths.
     *
     * @return array<int, string>
     */
    private static function extractPermissionList(stdClass $data, string $key): array
    {
        $permissions = $data->permissions ?? null;

        if (!$permissions instanceof stdClass) {
            return [];
        }

        $entries = $permissions->{$key} ?? null;

        if (!is_array($entries)) {
            return [];
        }

        return array_values(array_filter($entries, static fn (mixed $entry): bool => is_string($entry)));
    }

}
