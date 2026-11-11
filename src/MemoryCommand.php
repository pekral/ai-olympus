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
            self::assertLocation($root, $location);

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

    private static function assertLocation(string $root, string $location): void
    {
        if (CommandOptions::containsLink($root, $location)) {
            throw MemoryUnavailable::locationLinked($location);
        }

        if (!CommandOptions::isIgnored($root, $location)) {
            throw MemoryUnavailable::locationNotIgnored($location);
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
        if (preg_match('~^(?!/)(?!.*\.\.)[\w./-]+\.md$~', $source) !== 1) {
            throw InstallerFailure::memorySourceInvalid($source);
        }

        $parsed = MemoryMarkdown::parse(self::defaultBranchFile($root, $source));
        $inserted = self::store($root, $location)->insertAll($parsed->entries);

        echo json_encode(
            new MemoryMigration($location, $source, count($parsed->entries), $inserted, $parsed->rejected),
            CommandOptions::JSON_FLAGS,
        ) . PHP_EOL;

        return 0;
    }

    /**
     * The source comes from the default branch, never from the working tree: a branch under review can edit that file,
     * and every entry it adds would reach an agent as a lesson to apply. The ref comes from the one resolver the package
     * shares with the manifest read, as a full ref name that a local branch or tag cannot shadow.
     */
    private static function defaultBranchFile(string $root, string $source): string
    {
        $lines = [];
        $code = 1;
        $reference = self::defaultReference($root);

        $argument = escapeshellarg($reference . ':./' . $source);
        exec('git -C ' . escapeshellarg($root) . ' show ' . $argument . ' 2>/dev/null', $lines, $code);

        if ($code !== 0) {
            throw InstallerFailure::memorySourceInvalid($source);
        }

        return implode("\n", $lines);
    }

    /**
     * The ref is read from the resolver's stdout alone. Its stderr is a diagnostic, never a ref: a shell warning such as a
     * `setlocale` notice exits 0 on stderr, and a short name made of its first word would resolve to a local branch or tag.
     */
    private static function defaultReference(string $root): string
    {
        $process = proc_open(
            ['bash', __DIR__ . '/../skills/_shared/read-manifest.sh', '--default-ref'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
        );
        $stdout = is_resource($process) ? (string) stream_get_contents($pipes[1]) : '';
        $stderr = is_resource($process) ? (string) stream_get_contents($pipes[2]) : 'the resolver did not start';
        $code = is_resource($process) ? proc_close($process) : 1;
        $reference = trim($stdout);

        if ($code !== 0 || preg_match('~\Arefs/remotes/origin/[A-Za-z0-9._/-]+\z~', $reference) !== 1) {
            throw InstallerFailure::memoryDefaultBranchUnresolved(trim((string) preg_replace('~\s+~', ' ', $stderr)));
        }

        return $reference;
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
