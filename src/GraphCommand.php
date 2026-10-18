<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

/**
 * @phpstan-import-type GraphRecord from \Pekral\AiOlympus\GraphParser
 */
final class GraphCommand
{

    public const string RELATIVE_PATH = '.claude/run/graph.json';

    private const int JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    /**
     * @param array<int, string> $argv
     */
    public static function run(array $argv): int
    {
        $root = InstallerPath::resolveProjectRoot();
        $path = $root . '/' . self::RELATIVE_PATH;
        $action = $argv[2] ?? '';

        if ($action === 'build') {
            return self::build($root, $path);
        }

        if ($action === 'query' && isset($argv[3])) {
            return self::query($path, $argv[3]);
        }

        fwrite(STDERR, 'Usage: ai-olympus graph build | ai-olympus graph query <path|class|symbol>' . PHP_EOL);

        return 1;
    }

    private static function build(string $root, string $path): int
    {
        $result = GraphIndexer::index($root, self::read($path) ?? []);

        InstallerPath::ensureDirectory(dirname($path));
        file_put_contents($path, json_encode($result['graph'], self::JSON_FLAGS | JSON_PRETTY_PRINT) . PHP_EOL);

        echo json_encode(['graph' => self::RELATIVE_PATH] + $result['summary'], self::JSON_FLAGS) . PHP_EOL;

        return 0;
    }

    private static function query(string $path, string $entity): int
    {
        $files = self::read($path);

        if ($files === null) {
            fwrite(STDERR, sprintf('No graph at %s. Run `ai-olympus graph build` first.%s', self::RELATIVE_PATH, PHP_EOL));

            return 1;
        }

        echo json_encode(GraphQuery::slice($files, $entity), self::JSON_FLAGS) . PHP_EOL;

        return 0;
    }

    /**
     * @return array<string, GraphRecord>|null
     */
    private static function read(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $graph = json_decode((string) file_get_contents($path), associative: true);

        if (!is_array($graph) || ($graph['version'] ?? null) !== GraphIndexer::VERSION || !is_array($graph['files'] ?? null)) {
            return null;
        }

        /** @var array<string, GraphRecord> $files */
        $files = $graph['files'];

        return $files;
    }

}
