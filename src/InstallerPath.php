<?php

declare(strict_types = 1);

namespace AgenticVibes\AgentSkills;

final class InstallerPath
{

    public static function resolveProjectRoot(): string
    {
        return self::findProjectRoot();
    }

    /**
     * Splits combined CLI flags (e.g. --force--editor=claude) into separate arguments.
     *
     * @param array<int, string> $argv
     * @return array<int, string>
     */
    public static function normalizeCliArguments(array $argv): array
    {
        $rawArguments = implode(' ', $argv);
        $parts = preg_split(
            '/\s+|(?=--(?:force|symlink|prune|allow-bundled-scripts|allow-subagent-writes|deny-network-bash|editor=))/',
            trim($rawArguments),
            -1,
            PREG_SPLIT_NO_EMPTY,
        );

        return is_array($parts) && $parts !== [] ? $parts : $argv;
    }

    /**
     * User home directory resolved from HOME / USERPROFILE, or null when neither is set.
     */
    public static function resolveHomeDirectoryOrNull(): ?string
    {
        $home = self::resolveHomeDirectory();

        return $home === false || $home === '' ? null : $home;
    }

    /**
     * Idempotently create a directory tree. Throws InstallerFailure when the path
     * is occupied by a regular file or when mkdir cannot complete the tree.
     */
    public static function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (is_file($directory)) {
            throw InstallerFailure::directoryCreationFailed($directory);
        }

        set_error_handler(static fn (): bool => true);
        $created = mkdir($directory, 0777, recursive: true);
        restore_error_handler();

        if (!$created && !is_dir($directory)) {
            throw InstallerFailure::directoryCreationFailed($directory);
        }
    }

    public static function resolveRulesSource(string $root): string
    {
        $packageSource = self::getPackageDirectory() . '/rules';

        if (is_dir($packageSource)) {
            return $packageSource;
        }

        // @codeCoverageIgnoreStart
        throw InstallerFailure::missingSource($root . '/rules', $packageSource);
        // @codeCoverageIgnoreEnd
    }

    public static function resolveSkillsSource(): ?string
    {
        $packageSource = self::getPackageDirectory() . '/skills';

        if (is_dir($packageSource)) {
            return $packageSource;
        }

        // @codeCoverageIgnoreStart
        return null;
        // @codeCoverageIgnoreEnd
    }

    public static function resolveClaudeMdSource(): ?string
    {
        $source = self::getPackageDirectory() . '/CLAUDE.md';

        return is_file($source) ? $source : null;
    }

    public static function resolveAgentsSource(): ?string
    {
        $packageSource = self::getPackageDirectory() . '/agents';

        if (is_dir($packageSource)) {
            return $packageSource;
        }

        // @codeCoverageIgnoreStart
        return null;
        // @codeCoverageIgnoreEnd
    }

    /**
     * Claude Code subagents always install to .claude/agents.
     *
     * @return array<int, string>
     */
    public static function resolveAgentsTargetDirectories(string $root): array
    {
        return [$root . '/.claude/agents'];
    }

    /**
     * Target path for CLAUDE.md in the project root.
     */
    public static function resolveClaudeMdTarget(string $root): string
    {
        return $root . '/CLAUDE.md';
    }

    /**
     * Rules target directory: always .claude/rules.
     *
     * @return array<int, string>
     */
    public static function resolveRulesTargetDirectories(string $root): array
    {
        return [$root . '/.claude/rules'];
    }

    /**
     * Skill target directories: always .claude/skills, plus the user home skills
     * directory when $global is requested and HOME or USERPROFILE is set.
     *
     * The project directory is the default because Claude Code resolves a name collision
     * the other way round: personal (~/.claude/skills) overrides project (.claude/skills),
     * so a home copy shadows the checkout's own skills in every project on the machine.
     * Installing locally keeps a project on the version it has checked out.
     *
     * @return array<int, string>
     */
    public static function resolveSkillsTargetDirectories(string $root, bool $global = false): array
    {
        $targets = [$root . '/.claude/skills'];

        if (!$global) {
            return $targets;
        }

        $home = self::resolveHomeSkillsDirectory();

        if ($home !== null) {
            $targets[] = $home;
        }

        return array_values(array_unique($targets));
    }

    /**
     * The user home skills directory, or null when neither HOME nor USERPROFILE is set.
     */
    public static function resolveHomeSkillsDirectory(): ?string
    {
        $home = self::resolveHomeDirectory();

        if ($home === false || $home === '') {
            return null;
        }

        return $home . '/.claude/skills';
    }

    private static function resolveHomeDirectory(): string|false
    {
        $homeEnv = getenv('HOME');

        return $homeEnv !== false && $homeEnv !== '' ? $homeEnv : getenv('USERPROFILE');
    }

    private static function getPackageDirectory(): string
    {
        return dirname(__DIR__);
    }

    private static function findProjectRoot(): string
    {
        $dir = getcwd();

        if ($dir === false) {
            // @codeCoverageIgnoreStart
            return sys_get_temp_dir();
            // @codeCoverageIgnoreEnd
        }

        while ($dir !== '' && !self::isFilesystemRoot($dir) && !file_exists($dir . '/composer.json')) {
            $dir = dirname($dir);
        }

        return $dir;
    }

    private static function isFilesystemRoot(string $path): bool
    {
        if ($path === '' || $path === DIRECTORY_SEPARATOR) {
            return true;
        }

        return preg_match('/^[A-Za-z]:\\\\?$/', $path) === 1;
    }

}
