<?php

declare(strict_types = 1);

namespace AgenticVibes\AgentSkills;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Removes files from the install target that no longer exist in the source directory.
 */
final class InstallerPruner
{

    public static function pruneDirectory(string $source, string $targetDir): int
    {
        $pruned = 0;

        foreach (self::findOrphans($source, $targetDir) as $relativePath) {
            $target = $targetDir . '/' . $relativePath;

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
     * @return array<int, string>
     */
    public static function findOrphans(string $source, string $targetDir): array
    {
        if (!is_dir($targetDir)) {
            return [];
        }

        $sourceFiles = array_flip(self::listFiles($source));
        $targetFiles = self::listFiles($targetDir);

        return array_values(array_filter(
            $targetFiles,
            static fn (string $relativePath): bool => !isset($sourceFiles[$relativePath]),
        ));
    }

    private static function removeEmptyDirectories(string $directory, string $stopAt): void
    {
        while ($directory !== $stopAt && is_dir($directory)) {
            $iterator = new FilesystemIterator($directory);

            if ($iterator->valid()) {
                break;
            }

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
    private static function listFiles(string $base): array
    {
        if (!is_dir($base)) {
            return [];
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS),
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
