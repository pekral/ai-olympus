<?php

declare(strict_types = 1);

namespace AgenticVibes\AgentSkills;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Copies a source directory tree (or a single file) into an install target, honouring
 * `--force` / `--symlink` and the always-forced security-rule exception. Extracted out of
 * `Installer` to keep that class within the project's class-length limit.
 */
final class InstallerFileCopier
{

    /**
     * @param array<int, string>|null $sourceFiles pre-listed source files for this payload
     *                                             (avoids re-walking the same source tree once per target — see `Installer::syncDirectories()`)
     */
    public static function installDirectory(string $source, string $targetDir, bool $force, bool $symlink, ?array $sourceFiles = null): int
    {
        InstallerPath::ensureDirectory($targetDir);
        self::replicateDirectories($source, $targetDir);

        $files = $sourceFiles ?? InstallerPruner::listSourceFiles($source);

        return self::processFiles($files, $source, $targetDir, $force, $symlink);
    }

    public static function installSingleFile(?string $source, string $target): int
    {
        if ($source === null || file_exists($target)) {
            return 0;
        }

        return self::installFile($source, $target, symlink: false) ? 1 : 0;
    }

    /**
     * @param array<int, string> $files
     */
    private static function processFiles(array $files, string $source, string $targetDir, bool $force, bool $symlink): int
    {
        return array_reduce(
            $files,
            static fn (int $copied, string $relativePath): int => $copied + (self::shouldProcessFile(
                $relativePath,
                $source,
                $targetDir,
                $force,
                $symlink,
            ) ? 1 : 0),
            0,
        );
    }

    private static function shouldProcessFile(string $relativePath, string $source, string $targetDir, bool $force, bool $symlink): bool
    {
        $src = $source . '/' . $relativePath;
        $dst = $targetDir . '/' . $relativePath;
        $dirName = dirname($dst);

        InstallerPath::ensureDirectory($dirName);

        $effectiveForce = $force || self::isSecurityRule($relativePath);

        if (file_exists($dst) && !$effectiveForce) {
            return false;
        }

        return self::installFile($src, $dst, $symlink);
    }

    private static function isSecurityRule(string $relativePath): bool
    {
        return str_starts_with($relativePath, 'security/') || str_starts_with($relativePath, 'security\\');
    }

    private static function installFile(string $src, string $dst, bool $symlink): bool
    {
        self::removeExistingTarget($dst);

        if ($symlink && self::canSymlink()) {
            if (!symlink($src, $dst)) {
                // @codeCoverageIgnoreStart
                self::copy($src, $dst);
                // @codeCoverageIgnoreEnd
            }
        } else {
            self::copy($src, $dst);
            self::preserveExecutableBit($src, $dst);
        }

        InstallerHumanizer::appendIfNeeded($dst);

        return true;
    }

    private static function preserveExecutableBit(string $src, string $dst): void
    {
        $mode = fileperms($src);

        if ($mode === false || ($mode & 0111) === 0) {
            return;
        }

        set_error_handler(static fn (): bool => true);
        chmod($dst, ($mode & 0777) | 0111);
        restore_error_handler();
    }

    private static function removeExistingTarget(string $destination): void
    {
        if (!file_exists($destination)) {
            return;
        }

        if (is_dir($destination)) {
            throw InstallerFailure::removalFailed($destination);
        }

        set_error_handler(static fn (): bool => true);
        $deleted = unlink($destination);
        restore_error_handler();

        // @codeCoverageIgnoreStart
        if ($deleted === false) {
            throw InstallerFailure::removalFailed($destination);
        }
        // @codeCoverageIgnoreEnd
    }

    private static function replicateDirectories(string $source, string $targetDir): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $source,
                FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS,
            ),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $directory) {
            if (!$directory instanceof SplFileInfo || !$directory->isDir()) {
                continue;
            }

            $relativePath = self::extractFilePath($directory, $source);

            InstallerPath::ensureDirectory($targetDir . '/' . $relativePath);
        }
    }

    private static function extractFilePath(SplFileInfo $file, string $base): string
    {
        $pathname = $file->getPathname();

        return ltrim(str_replace($base, '', $pathname), '/');
    }

    private static function copy(string $src, string $dst): void
    {
        if (!copy($src, $dst)) {
            throw InstallerFailure::fileCopyFailed($src, $dst);
        }
    }

    private static function canSymlink(): bool
    {
        if (stripos(PHP_OS, 'WIN') === 0) {
            // @codeCoverageIgnoreStart
            return false;
            // @codeCoverageIgnoreEnd
        }

        return function_exists('symlink');
    }

}
