<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

final class InstallerCopyReference
{

    private const REFERENCE_FILE = 'ai-olympus/installed-copies.sha256';

    public static function record(string $root): void
    {
        $skillsSource = InstallerPath::resolveSkillsSource();
        $commandsSource = InstallerPath::resolveCommandsSource();
        $gitDirectory = self::resolveGitDirectory($root);

        if ($gitDirectory === null || ($skillsSource === null && $commandsSource === null)) {
            return;
        }

        $lines = '';

        if ($skillsSource !== null) {
            $lines .= self::recordDirectory(
                $root,
                $skillsSource,
                InstallerPath::resolveSkillsTargetDirectories($root),
                InstallerPruner::listSourceFiles($skillsSource),
            );
        }

        foreach (InstallerCommands::payloads($root, $commandsSource) as [$source, $targets, $files]) {
            $lines .= self::recordDirectory($root, $source, $targets, $files);
        }

        self::write($gitDirectory . '/' . self::REFERENCE_FILE, $lines);
    }

    /**
     * @param array<int, string> $targets
     * @param array<int, string> $sourceFiles
     */
    private static function recordDirectory(string $root, string $source, array $targets, array $sourceFiles): string
    {
        $lines = '';

        foreach ($targets as $target) {
            foreach ($sourceFiles as $relativePath) {
                $lines .= self::referenceLine($root, $source . '/' . $relativePath, $target . '/' . $relativePath);
            }
        }

        return $lines;
    }

    private static function referenceLine(string $root, string $source, string $installed): string
    {
        if (!is_file($installed) || is_link($installed)) {
            return '';
        }

        $hash = hash_file('sha256', $installed);

        if ($hash === false || $hash !== hash_file('sha256', $source)) {
            return '';
        }

        return sprintf('%s  %s%s', $hash, substr($installed, strlen($root) + 1), "\n");
    }

    private static function write(string $reference, string $lines): void
    {
        InstallerPath::ensureDirectory(dirname($reference));
        $temporary = $reference . '.tmp';

        set_error_handler(static fn (): bool => true);
        $written = file_put_contents($temporary, $lines) !== false && rename($temporary, $reference);
        restore_error_handler();

        if (!$written) {
            throw InstallerFailure::referenceWriteFailed($reference);
        }
    }

    private static function resolveGitDirectory(string $root): ?string
    {
        $dotGit = $root . '/.git';

        if (is_dir($dotGit)) {
            return $dotGit;
        }

        $contents = is_file($dotGit) ? file_get_contents($dotGit) : false;

        if ($contents === false || preg_match('/^gitdir: (.+)$/m', $contents, $match) !== 1) {
            return null;
        }

        $path = trim($match[1]);

        return preg_match('~^([A-Za-z]:)?[\\\\/]~', $path) === 1 ? $path : $root . '/' . $path;
    }

}
