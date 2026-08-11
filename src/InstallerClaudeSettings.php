<?php

declare(strict_types = 1);

namespace AgenticVibes\AgentSkills;

use stdClass;

/**
 * The installer's writers for the **home-level** settings file, `~/.claude/settings.json`.
 *
 * The project-local half — everything targeting `<project>/.claude/settings.local.json` — lives in
 * `InstallerProjectSettings` since issue #202. Both halves share `InstallerSettingsFile` for the
 * read/write round-trip and the `permissions` list handling, and nothing else.
 */
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

        return InstallerSettingsFile::extractPermissionList($data, 'allow');
    }

    /**
     * Adds the bundled-script permission entries to the user's Claude settings file
     * idempotently. Returns the number of entries newly added (0 when nothing changed).
     */
    public static function ensureBundledScriptPermissions(string $home): int
    {
        $settingsPath = self::resolveSettingsPath($home);
        $existing = InstallerSettingsFile::read($settingsPath);
        $existingAllow = InstallerSettingsFile::extractPermissionList($existing, 'allow');
        $merged = self::mergePermissions($existing);
        $mergedAllow = InstallerSettingsFile::extractPermissionList($merged, 'allow');

        $added = count($mergedAllow) - count($existingAllow);

        if ($added === 0) {
            return 0;
        }

        InstallerPath::ensureDirectory(dirname($settingsPath));
        InstallerSettingsFile::write($settingsPath, $merged);

        return $added;
    }

    private static function mergePermissions(stdClass $existing): stdClass
    {
        [$permissions, $allow] = InstallerSettingsFile::resolvePermissionList($existing, 'allow');

        foreach (self::getBundledScriptPermissions() as $pattern) {
            if (!in_array($pattern, $allow, strict: true)) {
                $allow[] = $pattern;
            }
        }

        $permissions->allow = $allow;
        $existing->permissions = $permissions;

        return $existing;
    }

}
