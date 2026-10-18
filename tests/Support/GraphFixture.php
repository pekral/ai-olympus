<?php

declare(strict_types = 1);

namespace Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class GraphFixture
{

    private const string DIRECTORY = __DIR__ . '/../Fixtures/graph-project';

    public static function source(string $path): string
    {
        return (string) file_get_contents(self::DIRECTORY . '/' . $path . '.fixture');
    }

    public static function project(): string
    {
        $root = installerCreateProjectRoot();
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(self::DIRECTORY, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            assert($file instanceof SplFileInfo);
            $path = substr($file->getPathname(), strlen(self::DIRECTORY));
            installerWriteFile($root . preg_replace('~\.fixture$~', '', $path), (string) file_get_contents($file->getPathname()));
        }

        return $root;
    }

    /**
     * @param list<array{from: string, type: string, to: string, line: int}> $edges
     * @return list<string>
     */
    public static function describe(array $edges): array
    {
        return array_map(static fn (array $edge): string => $edge['from'] . ' ' . $edge['type'] . ' ' . $edge['to'], $edges);
    }

}
