<?php

declare(strict_types = 1);

namespace Tests\Support;

use FilesystemIterator;
use Pekral\AiOlympus\GraphEdge;
use Pekral\AiOlympus\SliceEdge;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class GraphFixture
{

    private const string DIRECTORY = __DIR__ . '/../Fixtures/';

    public static function source(string $path, string $fixture = 'graph-project'): string
    {
        return (string) file_get_contents(self::DIRECTORY . $fixture . '/' . $path . '.fixture');
    }

    public static function project(string $fixture = 'graph-project'): string
    {
        $root = installerCreateProjectRoot();
        $directory = self::DIRECTORY . $fixture;
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            assert($file instanceof SplFileInfo);
            $path = substr($file->getPathname(), strlen($directory));
            installerWriteFile($root . preg_replace('~\.fixture$~', '', $path), (string) file_get_contents($file->getPathname()));
        }

        return $root;
    }

    /**
     * A git repository whose `.gitignore` covers the graph location, as `graph build` requires.
     */
    public static function repository(string $fixture = 'graph-project'): string
    {
        $root = self::project($fixture);
        installerWriteFile($root . '/.gitignore', ".claude/run/\n");
        exec('git -C ' . escapeshellarg($root) . ' init -q 2>&1');

        return $root;
    }

    /**
     * @param list<\Pekral\AiOlympus\GraphEdge|\Pekral\AiOlympus\SliceEdge> $edges
     * @return list<string>
     */
    public static function describe(array $edges, string ...$types): array
    {
        $edges = array_filter($edges, static fn (GraphEdge|SliceEdge $edge): bool => $types === [] || in_array($edge->type, $types, strict: true));

        return array_values(array_map(static fn (GraphEdge|SliceEdge $edge): string => $edge->from . ' ' . $edge->type . ' ' . $edge->to, $edges));
    }

}
