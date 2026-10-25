<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

/**
 * @phpstan-import-type RecordData from \Pekral\AiOlympus\GraphRecord
 */
final class GraphCommand
{

    private const string DEFAULT_RUN_DIRECTORY = '.claude/run';

    private const array RUN_DIRECTORIES = [self::DEFAULT_RUN_DIRECTORY, '.codex/run'];

    private const string GRAPH_FILE = 'graph.json';

    private const int JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    /**
     * @param array<int, string> $argv
     */
    public static function run(array $argv): int
    {
        $root = InstallerPath::resolveProjectRoot();
        $action = $argv[2] ?? '';
        $flags = match ($action) {
            'build' => self::flags(array_slice($argv, 3), ['--run-dir']),
            'query' => isset($argv[3]) ? self::flags(array_slice($argv, 4), ['--limit', '--run-dir']) : null,
            default => null,
        };
        $directory = self::runDirectory($flags ?? []);
        $limit = self::limit($flags ?? []);

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

        self::assertLocationIgnored($root, $location);

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

        echo json_encode(['graph' => $location] + $result->summary->jsonSerialize(), self::JSON_FLAGS) . PHP_EOL;

        return 0;
    }

    private static function assertLocationIgnored(string $root, string $location): void
    {
        $git = 'git -C ' . escapeshellarg($root);
        $argument = escapeshellarg($location);

        exec($git . ' check-ignore -q -- ' . $argument . ' 2>&1', result_code: $ignored);

        $tracked = [];

        if ($ignored === 0) {
            exec($git . ' ls-files -- ' . $argument . ' 2>&1', $tracked);
        }

        if ($ignored !== 0 || $tracked !== []) {
            throw InstallerFailure::graphLocationNotIgnored($location);
        }
    }

    /**
     * @param array<string, string> $flags
     */
    private static function limit(array $flags): null|int|false
    {
        if (!isset($flags['--limit'])) {
            return null;
        }

        return preg_match('/^[1-9][0-9]{0,8}$/', $flags['--limit']) === 1 ? (int) $flags['--limit'] : false;
    }

    /**
     * @param array<string, string> $flags
     */
    private static function runDirectory(array $flags): ?string
    {
        $directory = rtrim($flags['--run-dir'] ?? self::DEFAULT_RUN_DIRECTORY, '/');

        return in_array($directory, self::RUN_DIRECTORIES, strict: true) ? $directory : null;
    }

    /**
     * @param array<int, string> $arguments
     * @param list<string> $allowed
     * @return array<string, string>|null
     */
    private static function flags(array $arguments, array $allowed): ?array
    {
        $flags = [];

        foreach (array_chunk($arguments, 2) as $pair) {
            $value = $pair[1] ?? null;

            if (!in_array($pair[0], $allowed, strict: true) || $value === null || isset($flags[$pair[0]])) {
                return null;
            }

            $flags[$pair[0]] = $value;
        }

        return $flags;
    }

    private static function query(string $root, string $location, string $entity, ?int $limit): int
    {
        $files = self::read($root . '/' . $location);

        if ($files === null) {
            fwrite(STDERR, sprintf('No graph at %s. Run `ai-olympus graph build` first.%s', $location, PHP_EOL));

            return 1;
        }

        $current = GraphIndexer::index($root, $files);
        $changed = $current->summary->parsed + $current->summary->removed;
        $graph = new SliceGraph($changed === 0 ? 'fresh' : 'refreshed', $current->summary->files, $changed);

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
