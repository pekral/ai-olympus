<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Removes files from the install target that no longer exist in the source directory.
 */
final class InstallerPruner
{

    /**
     * @param array<int, string>|null $sourceFiles pre-listed source files for this payload
     *                                             (avoids re-walking the same source tree once per target — see `Installer::syncDirectories()`)
     */
    public static function pruneDirectory(string $source, string $targetDir, ?array $sourceFiles = null): int
    {
        $pruned = 0;

        foreach (self::findOrphans($source, $targetDir, $sourceFiles) as $relativePath) {
            $target = $targetDir . '/' . $relativePath;

            // Suppression is intentional (benign-use exception, @rules/security/backend.md
            // Suppressed error output): a permission-denied or racing removal is tolerated —
            // the file is simply skipped and stays reported as an orphan on the next run.
            set_error_handler(static fn (): bool => true);
            $deleted = unlink($target);
            restore_error_handler();

            if (!$deleted) {
                continue;
            }

            $pruned++;
            self::removeEmptyDirectories(dirname($target), $targetDir);
        }

        return $pruned;
    }

    /**
     * Relative paths that exist in the target directory but no longer exist in the source
     * directory. Pure lookup — never deletes or otherwise mutates the filesystem.
     *
     * Scope is regular files only (leaf entries) — an orphaned target directory that no longer
     * exists in the source but holds no regular file of its own is neither counted here nor
     * removable by `pruneDirectory()` (which only removes an empty directory as a side effect of
     * deleting the last file it held). This mirrors the CLI report's own "file(s)" wording.
     *
     * @param array<int, string>|null $sourceFiles pre-listed source files for this payload
     *                                             (avoids re-walking the same source tree once per target — see `Installer::syncDirectories()`)
     * @return array<int, string>
     */
    public static function findOrphans(string $source, string $targetDir, ?array $sourceFiles = null): array
    {
        if (!is_dir($targetDir)) {
            return [];
        }

        $sourceFileSet = array_flip($sourceFiles ?? self::listSourceFiles($source));
        // The target may contain user-controlled content (e.g. `~/.claude/skills`), so its walk
        // never follows a directory symlink — following one would let an orphan path traverse
        // outside the target tree, both inflating the count and letting `--prune` delete foreign
        // files through it (empirically verified: with FOLLOW_SYMLINKS a symlinked target
        // subdirectory is descended into and yields paths outside the target; without it, the
        // symlink stays a leaf entry, and unlinking a leaf symlink only removes the link itself).
        $targetFiles = self::listFiles($targetDir, followSymlinks: false);

        return array_values(array_filter(
            $targetFiles,
            static fn (string $relativePath): bool => !isset($sourceFileSet[$relativePath])
                && !self::isProjectOwnedSymlink($targetDir . '/' . $relativePath, $source),
        ));
    }

    /**
     * @return array<int, string>
     */
    public static function listSourceFiles(string $source): array
    {
        return self::listFiles($source);
    }

    private static function isProjectOwnedSymlink(string $path, string $source): bool
    {
        $linkTarget = is_link($path) ? readlink($path) : false;

        if ($linkTarget === false) {
            return false;
        }

        $absoluteTarget = str_starts_with($linkTarget, '/') ? $linkTarget : dirname($path) . '/' . $linkTarget;
        $sourceRoots = self::pathSpellings($source);

        foreach (self::pathSpellings($absoluteTarget) as $candidate) {
            if (self::isWithinAny($candidate, $sourceRoots)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array<int, string>
     */
    private static function pathSpellings(string $path): array
    {
        $resolved = realpath($path);

        return $resolved === false ? [self::normalizePath($path)] : [self::normalizePath($path), $resolved];
    }

    /**
     * @param array<int, string> $roots
     */
    private static function isWithinAny(string $candidate, array $roots): bool
    {
        foreach ($roots as $root) {
            if ($candidate === $root || str_starts_with($candidate, $root . '/')) {
                return true;
            }
        }

        return false;
    }

    private static function normalizePath(string $path): string
    {
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return '/' . implode('/', $segments);
    }

    private static function removeEmptyDirectories(string $directory, string $stopAt): void
    {
        while ($directory !== $stopAt && is_dir($directory)) {
            $iterator = new FilesystemIterator($directory);

            if ($iterator->valid()) {
                break;
            }

            // Suppression is intentional (benign-use exception, @rules/security/backend.md
            // Suppressed error output): a permission-denied removal is tolerated — the directory
            // is simply left in place.
            set_error_handler(static fn (): bool => true);
            $removed = rmdir($directory);
            restore_error_handler();

            if (!$removed) {
                break;
            }

            $directory = dirname($directory);
        }
    }

    /**
     * @return array<int, string>
     */
    private static function listFiles(string $base, bool $followSymlinks = true): array
    {
        if (!is_dir($base)) {
            return [];
        }

        $flags = FilesystemIterator::SKIP_DOTS;

        if ($followSymlinks) {
            $flags |= FilesystemIterator::FOLLOW_SYMLINKS;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, $flags),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );
        $files = [];

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo) {
                // @codeCoverageIgnoreStart
                continue;
                // @codeCoverageIgnoreEnd
            }

            $pathname = $file->getPathname();
            $files[] = ltrim(str_replace($base, '', $pathname), '/');
        }

        sort($files);

        return $files;
    }

}
