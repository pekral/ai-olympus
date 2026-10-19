<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

/**
 * @phpstan-import-type RecordData from \Pekral\AiOlympus\GraphRecord
 */
final class GraphCommand
{

    public const string RELATIVE_PATH = '.claude/run/graph.json';

    private const int JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

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

        $limit = self::limit($argv);

        if ($action === 'query' && isset($argv[3]) && $limit !== false) {
            return self::query($root, $path, $argv[3], $limit);
        }

        fwrite(STDERR, 'Usage: ai-olympus graph build | ai-olympus graph query <path|class|symbol> [--limit <n>]' . PHP_EOL);

        return 1;
    }

    private static function build(string $root, string $path): int
    {
        $result = GraphIndexer::index($root, self::read($path) ?? []);

        InstallerPath::ensureDirectory(dirname($path));

        set_error_handler(static fn (): bool => true);
        $written = file_put_contents(
            $path,
            json_encode(['version' => GraphIndexer::VERSION, 'files' => $result->files], self::JSON_FLAGS | JSON_PRETTY_PRINT) . PHP_EOL,
        );
        restore_error_handler();

        if ($written === false) {
            throw InstallerFailure::graphWriteFailed($path);
        }

        echo json_encode(['graph' => self::RELATIVE_PATH] + $result->summary->jsonSerialize(), self::JSON_FLAGS) . PHP_EOL;

        return 0;
    }

    /**
     * @param array<int, string> $argv
     */
    private static function limit(array $argv): null|int|false
    {
        if (!isset($argv[4])) {
            return null;
        }

        return $argv[4] === '--limit' && preg_match('/^[1-9][0-9]{0,8}$/', $argv[5] ?? '') === 1 ? (int) $argv[5] : false;
    }

    private static function query(string $root, string $path, string $entity, ?int $limit): int
    {
        $files = self::read($path);

        if ($files === null) {
            fwrite(STDERR, sprintf('No graph at %s. Run `ai-olympus graph build` first.%s', self::RELATIVE_PATH, PHP_EOL));

            return 1;
        }

        $current = GraphIndexer::index($root, $files);
        $changed = $current->summary->parsed + $current->summary->removed;
        $graph = ['state' => $changed === 0 ? 'fresh' : 'refreshed', 'files' => $current->summary->files, 'changed' => $changed];

        echo json_encode([...GraphQuery::slice($current->files, $entity, $limit)->jsonSerialize(), 'graph' => $graph], self::JSON_FLAGS) . PHP_EOL;

        return 0;
    }

    /**
     * @return array<string, \Pekral\AiOlympus\GraphRecord>|null
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

        /** @var array<string, RecordData> $files */
        $files = $graph['files'];

        return array_map(GraphRecord::fromArray(...), $files);
    }

}
