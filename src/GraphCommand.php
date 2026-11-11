<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

/**
 * @phpstan-import-type RecordData from \Pekral\AiOlympus\GraphRecord
 */
final class GraphCommand
{

    private const string GRAPH_FILE = 'graph.json';

    /**
     * @param array<int, string> $argv
     */
    public static function run(array $argv): int
    {
        $root = InstallerPath::resolveProjectRoot();
        $action = $argv[2] ?? '';
        $flags = match ($action) {
            'build' => CommandOptions::flags(array_slice($argv, 3), ['--run-dir']),
            'query' => isset($argv[3]) ? CommandOptions::flags(array_slice($argv, 4), ['--limit', '--run-dir']) : null,
            default => null,
        };
        $directory = CommandOptions::runDirectory($flags ?? []);
        $limit = CommandOptions::limit($flags ?? []);

        if ($flags !== null && $directory !== null && $limit !== false) {
            $location = $directory . '/' . self::GRAPH_FILE;

            return $action === 'build' ? self::build($root, $location) : self::query($root, $location, $argv[3], $limit);
        }

        fwrite(
            STDERR,
            'Usage: ai-olympus graph build [--run-dir <dir>] | ai-olympus graph query <path|class|symbol> [--limit <n>] [--run-dir <dir>]' . PHP_EOL,
        );

        return 1;
    }

    private static function build(string $root, string $location): int
    {
        $path = $root . '/' . $location;

        if (!CommandOptions::isIgnored($root, $location)) {
            throw InstallerFailure::graphLocationNotIgnored($location);
        }

        $result = GraphIndexer::index($root, self::read($path) ?? []);

        InstallerPath::ensureDirectory(dirname($path));

        set_error_handler(static fn (): bool => true);
        $written = file_put_contents(
            $path,
            json_encode(['version' => GraphIndexer::VERSION, 'files' => $result->files], CommandOptions::JSON_FLAGS | JSON_PRETTY_PRINT) . PHP_EOL,
        );
        restore_error_handler();

        if ($written === false) {
            throw InstallerFailure::graphWriteFailed($path);
        }

        echo json_encode(['graph' => $location] + $result->summary->jsonSerialize(), CommandOptions::JSON_FLAGS) . PHP_EOL;

        return 0;
    }

    private static function query(string $root, string $location, string $entity, ?int $limit): int
    {
        $files = self::read($root . '/' . $location);

        if ($files === null) {
            fwrite(STDERR, sprintf('No graph at %s. Run `ai-olympus graph build --run-dir %s` first.%s', $location, dirname($location), PHP_EOL));

            return 1;
        }

        $current = GraphIndexer::index($root, $files);
        $changed = $current->summary->parsed + $current->summary->removed;
        $graph = new SliceGraph($changed === 0 ? 'fresh' : 'refreshed', $current->summary->files, $changed);

        echo json_encode(
            [...GraphQuery::slice($current->files, $entity, $limit)->jsonSerialize(), 'graph' => $graph],
            CommandOptions::JSON_FLAGS,
        ) . PHP_EOL;

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
