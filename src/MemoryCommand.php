<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

use JsonException;

final class MemoryCommand
{

    private const string DATABASE_FILE = 'code-review-memory.sqlite';

    private const string DEFAULT_SOURCE = 'docs/memory/PROJECT_MEMORY.md';

    private const int DEFAULT_LIMIT = 5;

    private const int EXAMPLES_FOR_TOP = 3;

    private const string USAGE = 'Usage: ai-olympus memory migrate [--source <file.md>] [--run-dir <dir>]'
        . ' | ai-olympus memory add --entry <file.json> [--run-dir <dir>]'
        . ' | ai-olympus memory query --role <role> (--path <p> | --symbol <s>)... [--limit <n>] [--run-dir <dir>]';

    /**
     * @param array<int, string> $argv
     */
    public static function run(array $argv): int
    {
        $root = InstallerPath::resolveProjectRoot();
        $action = $argv[2] ?? '';
        $flags = match ($action) {
            'migrate' => CommandOptions::flags(array_slice($argv, 3), ['--source', '--run-dir']),
            'add' => CommandOptions::flags(array_slice($argv, 3), ['--entry', '--run-dir']),
            'query' => CommandOptions::flags(array_slice($argv, 3), ['--role', '--path', '--symbol', '--limit', '--run-dir'], ['--path', '--symbol']),
            default => null,
        };
        $directory = CommandOptions::runDirectory($flags ?? []);
        $limit = CommandOptions::limit($flags ?? []);

        if ($flags === null || $directory === null || $limit === false || !self::valid($action, $flags)) {
            fwrite(STDERR, self::USAGE . PHP_EOL);

            return 1;
        }

        $location = $directory . '/' . self::DATABASE_FILE;

        try {
            if (!CommandOptions::isIgnored($root, $location)) {
                throw MemoryUnavailable::locationNotIgnored($location);
            }

            return match ($action) {
                'migrate' => self::migrate($root, $location, $flags['--source'][0] ?? self::DEFAULT_SOURCE),
                'add' => self::add($root, $location, $flags['--entry'][0]),
                default => self::query($root . '/' . $location, $flags, $limit ?? self::DEFAULT_LIMIT),
            };
        } catch (MemoryUnavailable $exception) {
            fwrite(STDERR, $exception->getMessage() . PHP_EOL);

            return 2;
        }
    }

    /**
     * @param array<string, list<string>> $flags
     */
    private static function valid(string $action, array $flags): bool
    {
        return match ($action) {
            'add' => isset($flags['--entry']),
            'query' => self::validQuery($flags),
            default => true,
        };
    }

    /**
     * @param array<string, list<string>> $flags
     */
    private static function validQuery(array $flags): bool
    {
        $terms = [...$flags['--path'] ?? [], ...$flags['--symbol'] ?? []];

        return isset($flags['--role']) && MemoryEntry::isRole($flags['--role'][0]) && $terms !== []
            && array_filter($terms, static fn (string $term): bool => !MemoryEntry::isPath($term)) === [];
    }

    private static function migrate(string $root, string $location, string $source): int
    {
        $file = $root . '/' . $source;

        if (preg_match('~^(?!/)(?!.*\.\.)[\w./-]+\.md$~', $source) !== 1 || !is_file($file)) {
            throw InstallerFailure::memorySourceInvalid($source);
        }

        $entries = MemoryMarkdown::parse((string) file_get_contents($file));
        $inserted = self::store($root, $location)->insertAll($entries);

        echo json_encode(
            ['memory' => $location, 'source' => $source, 'parsed' => count($entries), 'inserted' => $inserted, 'skipped' => count($entries) - $inserted],
            CommandOptions::JSON_FLAGS,
        ) . PHP_EOL;

        return 0;
    }

    private static function add(string $root, string $location, string $entryFile): int
    {
        try {
            $data = is_file($entryFile) ? json_decode((string) file_get_contents($entryFile), associative: true, flags: JSON_THROW_ON_ERROR) : null;
        } catch (JsonException) {
            $data = null;
        }

        if (!is_array($data)) {
            throw InstallerFailure::memoryEntryInvalid('the entry file must hold one JSON object');
        }

        $entry = MemoryEntry::fromArray($data);

        if (self::store($root, $location)->insertAll([$entry]) !== 1) {
            throw InstallerFailure::memoryEntryInvalid(sprintf('the slug "%s" already exists', $entry->slug));
        }

        echo json_encode(['memory' => $location, 'added' => $entry->slug], CommandOptions::JSON_FLAGS) . PHP_EOL;

        return 0;
    }

    /**
     * @param array<string, list<string>> $flags
     */
    private static function query(string $path, array $flags, int $limit): int
    {
        $entries = is_file($path)
            ? MemoryStore::open($path)->search($flags['--role'][0], $flags['--path'] ?? [], $flags['--symbol'] ?? [], $limit)
            : [];
        $items = [];

        foreach ($entries as $position => $entry) {
            $items[] = MemoryCandidate::fromEntry($entry, $position < self::EXAMPLES_FOR_TOP);
        }

        echo json_encode(['queried' => ['learnings'], 'items' => $items], CommandOptions::JSON_FLAGS) . PHP_EOL;

        return 0;
    }

    private static function store(string $root, string $location): MemoryStore
    {
        InstallerPath::ensureDirectory(dirname($root . '/' . $location));

        return MemoryStore::open($root . '/' . $location);
    }

}
