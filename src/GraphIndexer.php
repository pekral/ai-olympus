<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

use FilesystemIterator;
use RecursiveArrayIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * @phpstan-import-type GraphRecord from \Pekral\AiOlympus\GraphParser
 * @phpstan-type GraphSummary array{files: int, parsed: int, reused: int, removed: int, errors: list<string>}
 */
final class GraphIndexer
{

    public const int VERSION = 2;

    private const array SKIPPED_DIRECTORIES = ['vendor', 'node_modules'];

    private const array AUTOLOAD_TYPES = ['psr-4', 'psr-0', 'classmap'];

    /**
     * @param array<string, GraphRecord> $previous
     * @return array{graph: array{version: int, files: array<string, GraphRecord>}, summary: GraphSummary}
     */
    public static function index(string $root, array $previous): array
    {
        $files = [];
        $summary = ['files' => 0, 'parsed' => 0, 'reused' => 0, 'removed' => 0, 'errors' => []];

        foreach (self::listPhpFiles($root) as $path) {
            $code = (string) file_get_contents($root . '/' . $path);
            $record = $previous[$path] ?? null;

            if ($record === null || $record['hash'] !== GraphParser::hash($code)) {
                $record = GraphParser::parse($path, $code);
                $summary['parsed']++;
            } else {
                $summary['reused']++;
            }

            if (isset($record['error'])) {
                $summary['errors'][] = $path;
            }

            $files[$path] = $record;
        }

        $summary['files'] = count($files);
        $summary['removed'] = count(array_diff_key($previous, $files));

        return ['graph' => ['version' => self::VERSION, 'files' => $files], 'summary' => $summary];
    }

    /**
     * @return list<string>
     */
    private static function listPhpFiles(string $root): array
    {
        $paths = [];

        foreach (self::sourceDirectories($root) as $directory) {
            $paths = [...$paths, ...self::walk($root, $directory)];
        }

        $paths = array_values(array_unique($paths));
        sort($paths, SORT_STRING);

        return $paths;
    }

    /**
     * @return list<string>
     */
    private static function sourceDirectories(string $root): array
    {
        $composer = is_file($root . '/composer.json') ? json_decode((string) file_get_contents($root . '/composer.json'), associative: true) : null;
        $directories = ['config', 'routes', 'database/migrations'];

        foreach (['autoload', 'autoload-dev'] as $section) {
            foreach (self::AUTOLOAD_TYPES as $type) {
                $mapping = is_array($composer) && is_array($composer[$section] ?? null) ? ($composer[$section][$type] ?? null) : null;
                $directories = [...$directories, ...self::autoloadPaths($mapping)];
            }
        }

        return array_values(array_unique($directories));
    }

    /**
     * @return list<string>
     */
    private static function autoloadPaths(mixed $mapping): array
    {
        if (!is_array($mapping)) {
            return [];
        }

        $paths = [];

        foreach (new RecursiveIteratorIterator(new RecursiveArrayIterator($mapping)) as $path) {
            if (is_string($path)) {
                $paths[] = trim(str_starts_with($path, './') ? substr($path, 2) : $path, '/');
            }
        }

        return $paths;
    }

    /**
     * @return list<string>
     */
    private static function walk(string $root, string $directory): array
    {
        $base = rtrim($root . '/' . $directory, '/');

        if (!is_dir($base)) {
            return [];
        }

        $entries = new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
            static fn (SplFileInfo $entry): bool => !$entry->isDir()
                || (!str_starts_with($entry->getFilename(), '.') && !in_array($entry->getFilename(), self::SKIPPED_DIRECTORIES, strict: true)),
        );

        $paths = [];

        foreach (new RecursiveIteratorIterator($entries) as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                $paths[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            }
        }

        return $paths;
    }

}
