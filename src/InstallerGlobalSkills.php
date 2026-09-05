<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Removes this package's skills from the Claude Code and Codex home skill directories.
 *
 * Claude Code resolves a same-name collision as personal-overrides-project, so a leftover
 * copy in ~/.claude/skills shadows the project's own .claude/skills in every project on the
 * machine — including checkouts on a different version. Removing the home copy is what makes
 * the project copy the one that actually loads.
 */
final class InstallerGlobalSkills
{

    /**
     * Removes only the skill directories this package ships; anything else in the home skills
     * directory is another source's and is left untouched.
     *
     * @return array<int, string> names of the removed skills, sorted
     */
    public static function prune(string $skillsSource, string $projectRoot): array
    {
        if (!is_dir($skillsSource)) {
            return [];
        }

        $removed = [];
        $skillNames = self::listShippedSkillNames($skillsSource);

        foreach (InstallerPath::resolveHomeSkillsDirectories() as $homeSkills) {
            if (!is_dir($homeSkills) || self::isProjectSkillsDirectory($homeSkills, $projectRoot)) {
                continue;
            }

            $removed = [...$removed, ...self::pruneDirectory($homeSkills, $skillNames)];
        }

        $removed = array_values(array_unique($removed));
        sort($removed);

        return $removed;
    }

    /**
     * @param array<int, string> $skillNames
     * @return array<int, string>
     */
    private static function pruneDirectory(string $homeSkills, array $skillNames): array
    {
        $removed = [];

        foreach ($skillNames as $name) {
            if (self::removeEntry($homeSkills . '/' . $name)) {
                $removed[] = $name;
            }
        }

        return $removed;
    }

    private static function isProjectSkillsDirectory(string $homeSkills, string $projectRoot): bool
    {
        return self::isSameDirectory($homeSkills, $projectRoot . '/.claude/skills')
            || self::isSameDirectory($homeSkills, $projectRoot . '/.agents/skills');
    }

    /**
     * @return array<int, string>
     */
    private static function listShippedSkillNames(string $skillsSource): array
    {
        $names = [];

        foreach (new FilesystemIterator($skillsSource, FilesystemIterator::SKIP_DOTS) as $entry) {
            if (!$entry instanceof SplFileInfo || !$entry->isDir()) {
                continue;
            }

            $names[] = $entry->getFilename();
        }

        return $names;
    }

    private static function isSameDirectory(string $left, string $right): bool
    {
        $leftReal = realpath($left);
        $rightReal = realpath($right);

        return $leftReal !== false && $leftReal === $rightReal;
    }

    private static function removeEntry(string $path): bool
    {
        // A symlinked install is removed as the link itself — never followed, so the target
        // tree it points at (a checkout elsewhere on disk) survives.
        if (is_link($path)) {
            return self::unlinkQuietly($path);
        }

        if (!is_dir($path)) {
            return false;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $entry) {
            if (!$entry instanceof SplFileInfo) {
                // @codeCoverageIgnoreStart
                continue;
                // @codeCoverageIgnoreEnd
            }

            $entryPath = $entry->getPathname();

            if ($entry->isDir() && !$entry->isLink()) {
                self::rmdirQuietly($entryPath);

                continue;
            }

            self::unlinkQuietly($entryPath);
        }

        return self::rmdirQuietly($path);
    }

    private static function unlinkQuietly(string $path): bool
    {
        // Suppression is intentional (benign-use exception, @rules/security/backend.md
        // Suppressed error output): a permission-denied removal is tolerated — the entry stays
        // in place and is reported as not removed rather than aborting the run.
        set_error_handler(static fn (): bool => true);
        $removed = unlink($path);
        restore_error_handler();

        return $removed;
    }

    private static function rmdirQuietly(string $path): bool
    {
        // Suppression is intentional (benign-use exception, @rules/security/backend.md
        // Suppressed error output): see unlinkQuietly() above.
        set_error_handler(static fn (): bool => true);
        $removed = rmdir($path);
        restore_error_handler();

        return $removed;
    }

}
