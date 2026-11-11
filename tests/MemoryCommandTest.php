<?php

declare(strict_types = 1);

use Pekral\AiOlympus\Installer;
use Pekral\AiOlympus\MemoryEntry;
use Pekral\AiOlympus\MemoryMarkdown;
use Pekral\AiOlympus\MemoryStore;
use Pekral\AiOlympus\MemoryUnavailable;
use Tests\Support\StderrCapture;

const MEMORY_DATABASE = '.claude/run/code-review-memory.sqlite';

const MEMORY_MARKDOWN = <<<'MD'
# Project memory

### graph-cache-stale — The graph cache goes stale after a rebase
- Trigger: a task edits `src/GraphIndexer.php` and reads the cached graph.
- Rule:    Rebuild the graph before a query; a stale slice hides the files a change touches.
- Example: `src/GraphCommand.php:42` and PR #12.
- Source:  https://example.test/pull/12   Added: 2026-07-01
- Role:    shared

### queue-retry-storm — A retry loop without backoff floods the queue
- Trigger: a job retries on failure.
- Rule:    Define backoff in the job class.
- Source:  local request
- Role:    donatello

### reviewer-only-lesson — Reviewers skip generated files
- Trigger: a review reads a generated file.
- Rule:    Skip generated files.
- Role:    leonardo

### broken entry without fields
MD;

/**
 * @return array{0: int, 1: string, 2: string}
 */
function memoryRun(string $root, string ...$arguments): array
{
    $cwd = (string) getcwd();
    chdir($root);

    try {
        ob_start();
        [$exitCode, $stderr] = StderrCapture::run(static fn (): int => Installer::run(['ai-olympus', 'memory', ...array_values($arguments)]));
        $stdout = (string) ob_get_clean();
    } finally {
        chdir($cwd);
    }

    return [$exitCode, $stdout, $stderr];
}

function memoryRepository(string $markdown = MEMORY_MARKDOWN): string
{
    $root = installerCreateProjectRoot();
    installerWriteFile($root . '/.gitignore', ".claude/run/\n.codex/run/\n");
    installerWriteFile($root . '/docs/memory/PROJECT_MEMORY.md', $markdown);
    exec('git -C ' . escapeshellarg($root) . ' init -q 2>&1');

    return $root;
}

/**
 * @return list<array{kind: string, text: string, source: array{type: string, id: string}, paths: list<string>}>
 */
function memoryItems(string $stdout): array
{
    /** @var array{items: list<array{kind: string, text: string, source: array{type: string, id: string}, paths: list<string>}>} $result */
    $result = json_decode($stdout, associative: true);

    return $result['items'];
}

/**
 * @return list<string>
 */
function memorySlugs(string $stdout): array
{
    return array_map(static fn (array $item): string => $item['source']['id'], memoryItems($stdout));
}

test('migrate moves the Markdown entries into the database once and reports what it skipped', function (): void {
    $root = memoryRepository();

    [$firstExit, $first] = memoryRun($root, 'migrate');
    [$secondExit, $second] = memoryRun($root, 'migrate');

    expect($firstExit)->toBe(0);
    expect($first)->toBe('{"memory":"' . MEMORY_DATABASE . '","source":"docs/memory/PROJECT_MEMORY.md","parsed":3,"inserted":3,"skipped":0}' . PHP_EOL);
    expect($secondExit)->toBe(0);
    expect($second)->toContain('"parsed":3,"inserted":0,"skipped":3');
    expect(is_file($root . '/' . MEMORY_DATABASE))->toBeTrue();

    installerRemoveDirectory($root);
});

test('every entry of this repository memory file parses and migrates', function (): void {
    $memory = (string) file_get_contents(__DIR__ . '/../docs/memory/PROJECT_MEMORY.md');

    $entries = MemoryMarkdown::parse($memory);

    expect(count($entries))->toBe(preg_match_all('/^### /m', $memory));
    expect(array_unique(array_map(static fn (MemoryEntry $entry): string => $entry->slug, $entries)))->toHaveCount(count($entries));
});

test('the parser reads the fields, the source date, and the quoted paths of an entry', function (): void {
    $entries = MemoryMarkdown::parse(MEMORY_MARKDOWN);

    expect($entries)->toHaveCount(3);
    expect($entries[0])->toEqual(new MemoryEntry(
        'graph-cache-stale',
        'shared',
        'a task edits `src/GraphIndexer.php` and reads the cached graph.',
        'The graph cache goes stale after a rebase',
        'Rebuild the graph before a query; a stale slice hides the files a change touches.',
        '',
        ['src/GraphIndexer.php', 'src/GraphCommand.php'],
        '`src/GraphCommand.php:42` and PR #12.',
        'https://example.test/pull/12',
        '2026-07-01',
    ));
    expect($entries[1]->source)->toBe('local request')->and($entries[1]->added)->toBe('')->and($entries[1]->example)->toBe('');
});

test('the parser skips an entry that lacks a trigger, a rule, or a valid role', function (string $fields): void {
    expect(MemoryMarkdown::parse("### incomplete — Incomplete\n" . $fields))->toBe([]);
})->with([
    'a role in the wrong format' => ["- Trigger: t\n- Rule: r\n- Role: Not A Role\n"],
    'no role' => ["- Trigger: t\n- Rule: r\n"],
    'no rule' => ["- Trigger: t\n- Role: shared\n"],
    'no trigger' => ["- Rule: r\n- Role: shared\n"],
]);

test('the parser ignores a URL and a bare word and caps the paths of one entry', function (): void {
    $quoted = implode(' ', array_map(static fn (int $number): string => '`src/File' . $number . '.php`', range(1, 25)));
    $markdown = "### many-paths — Many paths\n- Trigger: `https://example.test/x.php` and `word`\n- Rule: " . $quoted . "\n- Role: shared\n";

    $entry = MemoryMarkdown::parse($markdown)[0];

    expect($entry->paths)->toHaveCount(20)->and($entry->paths[0])->toBe('src/File1.php');
});

test('a task that touches a file an entry names receives that entry, and another role does not', function (): void {
    $root = memoryRepository();
    memoryRun($root, 'migrate');

    [$exitCode, $stdout] = memoryRun($root, 'query', '--role', 'donatello', '--path', 'src/GraphIndexer.php');
    [, $reviewer] = memoryRun($root, 'query', '--role', 'leonardo', '--path', 'src/GraphIndexer.php');

    expect($exitCode)->toBe(0);
    expect(memorySlugs($stdout))->toBe(['graph-cache-stale']);
    expect(memoryItems($stdout)[0]['paths'])->toBe(['src/GraphIndexer.php', 'src/GraphCommand.php']);
    expect(memoryItems($stdout)[0]['kind'])->toBe('learnings');
    expect(memoryItems($stdout)[0]['text'])->toBe(
        'The graph cache goes stale after a rebase. Trigger: a task edits `src/GraphIndexer.php` and reads the cached graph. '
        . 'Rule: Rebuild the graph before a query; a stale slice hides the files a change touches. Example: `src/GraphCommand.php:42` and PR #12.',
    );
    expect(memorySlugs($reviewer))->toBe(['graph-cache-stale']);

    installerRemoveDirectory($root);
});

test('a symbol finds an entry through the full-text index, ranked, and a role filter applies before the search', function (): void {
    $root = memoryRepository();
    memoryRun($root, 'migrate');

    [, $donatello] = memoryRun($root, 'query', '--role', 'donatello', '--symbol', 'backoff', '--symbol', 'stale');
    [, $leonardo] = memoryRun($root, 'query', '--role', 'leonardo', '--symbol', 'backoff');

    expect(memorySlugs($donatello))->toBe(['queue-retry-storm', 'graph-cache-stale']);
    expect(memorySlugs($leonardo))->toBe([]);

    installerRemoveDirectory($root);
});

test('a task with no match receives no entry, and the same input gives the same output twice', function (): void {
    $root = memoryRepository();
    memoryRun($root, 'migrate');

    [, $none] = memoryRun($root, 'query', '--role', 'donatello', '--path', 'app/Unrelated.php', '--symbol', 'Unrelated');
    [, $first] = memoryRun($root, 'query', '--role', 'donatello', '--symbol', 'backoff', '--symbol', 'stale');
    [, $second] = memoryRun($root, 'query', '--role', 'donatello', '--symbol', 'backoff', '--symbol', 'stale');

    expect($none)->toBe('{"queried":["learnings"],"items":[]}' . PHP_EOL);
    expect($first)->toBe($second);

    installerRemoveDirectory($root);
});

test('the limit caps the entries and only the top three carry their example', function (): void {
    $lines = [];

    foreach (range(1, 5) as $number) {
        $lines[] = "### entry-{$number} — Lesson {$number}\n- Trigger: shared trigger word\n- Rule: Rule {$number} about `src/Same.php`\n"
            . "- Example: example-{$number}\n- Role: shared\n";
    }

    $root = memoryRepository(implode("\n", $lines));
    memoryRun($root, 'migrate');

    [, $all] = memoryRun($root, 'query', '--role', 'krang', '--path', 'src/Same.php');
    [, $limited] = memoryRun($root, 'query', '--role', 'krang', '--path', 'src/Same.php', '--limit', '2');

    expect(memorySlugs($all))->toBe(['entry-1', 'entry-2', 'entry-3', 'entry-4', 'entry-5']);
    expect(array_map(static fn (array $item): bool => str_contains($item['text'], 'Example:'), memoryItems($all)))->toBe([true, true, true, false, false]);
    expect(memorySlugs($limited))->toBe(['entry-1', 'entry-2']);

    installerRemoveDirectory($root);
});

test('a query without a database prints no items and creates no file', function (): void {
    $root = memoryRepository();

    [$exitCode, $stdout] = memoryRun($root, 'query', '--role', 'donatello', '--symbol', 'backoff');

    expect($exitCode)->toBe(0);
    expect($stdout)->toBe('{"queried":["learnings"],"items":[]}' . PHP_EOL);
    expect(is_file($root . '/' . MEMORY_DATABASE))->toBeFalse();

    installerRemoveDirectory($root);
});

test('the Codex run directory holds its own database', function (): void {
    $root = memoryRepository();

    [$exitCode, $stdout] = memoryRun($root, 'migrate', '--run-dir', '.codex/run');

    expect($exitCode)->toBe(0);
    expect($stdout)->toContain('"memory":".codex/run/code-review-memory.sqlite"');
    expect(is_file($root . '/.codex/run/code-review-memory.sqlite'))->toBeTrue();
    expect(is_file($root . '/' . MEMORY_DATABASE))->toBeFalse();

    installerRemoveDirectory($root);
});

test('add stores one entry that the next query finds, and refuses a slug it already holds', function (): void {
    $root = memoryRepository();
    installerWriteFile($root . '/.claude/run/entry.json', (string) json_encode([
        'slug' => 'unsafe-path-join', 'role' => 'shared', 'trigger' => 'a path is joined from input', 'lesson' => 'A joined path escaped the project',
        'rule' => 'Resolve the path and compare its prefix.', 'evidence' => 'PR #9, tests/PathTest.php', 'paths' => ['src/PathJoin.php'],
        'source' => 'https://example.test/pull/9', 'added' => '2026-11-11',
    ]));

    [$firstExit, $first] = memoryRun($root, 'add', '--entry', '.claude/run/entry.json');
    [$secondExit, , $second] = memoryRun($root, 'add', '--entry', '.claude/run/entry.json');
    [, $query] = memoryRun($root, 'query', '--role', 'krang', '--path', 'src/PathJoin.php');

    expect($firstExit)->toBe(0);
    expect($first)->toBe('{"memory":"' . MEMORY_DATABASE . '","added":"unsafe-path-join"}' . PHP_EOL);
    expect($secondExit)->toBe(1);
    expect($second)->toBe('Invalid memory entry: the slug "unsafe-path-join" already exists.' . PHP_EOL);
    expect(memoryItems($query)[0]['text'])->toContain('Evidence: PR #9, tests/PathTest.php');

    installerRemoveDirectory($root);
});

/**
 * @return array<string, string>
 */
function memoryValidEntry(): array
{
    return [
        'added' => '2026-11-11',
        'evidence' => 'e',
        'lesson' => 'l',
        'role' => 'shared',
        'rule' => 'r',
        'slug' => 'ok-slug',
        'source' => 's',
        'trigger' => 't',
    ];
}

test('add refuses an entry that is not valid', function (string $content, string $reason): void {
    $root = memoryRepository();
    installerWriteFile($root . '/.claude/run/entry.json', $content);

    [$exitCode, $stdout, $stderr] = memoryRun($root, 'add', '--entry', '.claude/run/' . ($content === '' ? 'missing.json' : 'entry.json'));

    expect($exitCode)->toBe(1);
    expect($stdout)->toBe('');
    expect($stderr)->toBe('Invalid memory entry: ' . $reason . '.' . PHP_EOL);
    expect(is_file($root . '/' . MEMORY_DATABASE))->toBeFalse();

    installerRemoveDirectory($root);
})->with(static function (): Generator {
    $reason = 'the entry file must hold one JSON object';
    yield 'a file that is not JSON' => ['not json', $reason];
    yield 'a file that is missing' => ['', $reason];

    foreach ([
        'a missing rule' => [['rule' => null], '"rule" must be a non-empty string'],
        'a slug in the wrong format' => [['slug' => 'Bad Slug'], '"slug" has the wrong format'],
        'a date in the wrong format' => [['added' => 'yesterday'], '"added" has the wrong format'],
        'a role in the wrong format' => [['role' => 'Not A Role'], '"role" has the wrong format'],
        'paths that are not a list of paths' => [['paths' => ['a b']], '"paths" must be a list of file paths'],
        'paths that are not a list' => [['paths' => 'src/A.php'], '"paths" must be a list of file paths'],
        'an example that is not text' => [['example' => 5], '"example" must be a non-empty string'],
    ] as $name => [$override, $message]) {
        /** @var array<string, mixed> $entry */
        $entry = [...memoryValidEntry(), ...$override];

        yield $name => [(string) json_encode(array_filter($entry, static fn (mixed $value): bool => $value !== null)), $message];
    }
});

test('read and write fail safely, and name the fix, when git does not ignore the database', function (string $setup, string ...$arguments): void {
    $root = memoryRepository();
    putenv('GIT_CONFIG_GLOBAL=/dev/null');
    putenv('GIT_CONFIG_NOSYSTEM=1');

    try {
        match ($setup) {
            'no gitignore entry' => unlink($root . '/.gitignore'),
            default => installerRemoveDirectory($root . '/.git'),
        };

        [$exitCode, $stdout, $stderr] = memoryRun($root, ...$arguments);
    } finally {
        putenv('GIT_CONFIG_GLOBAL');
        putenv('GIT_CONFIG_NOSYSTEM');
    }

    expect($exitCode)->toBe(2);
    expect($stdout)->toBe('');
    expect($stderr)->toBe(
        'Code-review memory unavailable: ' . MEMORY_DATABASE . ' is not git-ignored or is tracked. Add `.claude/run/` to .gitignore '
        . 'and untrack the file. Continue without memory.' . PHP_EOL,
    );
    expect(is_file($root . '/' . MEMORY_DATABASE))->toBeFalse();

    installerRemoveDirectory($root);
})->with([
    'a migration without a gitignore entry' => ['no gitignore entry', 'migrate'],
    'a query outside a git repository' => ['no repository', 'query', '--role', 'donatello', '--symbol', 'backoff'],
    'a query without a gitignore entry' => ['no gitignore entry', 'query', '--role', 'donatello', '--symbol', 'backoff'],
]);

test('a database that SQLite cannot open makes the memory unavailable instead of failing the run', function (): void {
    $root = memoryRepository();
    installerWriteFile($root . '/' . MEMORY_DATABASE, str_repeat('not a database ', 40));

    [$exitCode, $stdout, $stderr] = memoryRun($root, 'query', '--role', 'donatello', '--symbol', 'backoff');

    expect($exitCode)->toBe(2);
    expect($stdout)->toBe('');
    expect($stderr)->toStartWith('Code-review memory unavailable: SQLite with FTS5 could not open the database (')->toEndWith(
        'Continue without memory.' . PHP_EOL,
    );

    installerRemoveDirectory($root);
});

test('a missing PDO driver makes the memory unavailable', function (): void {
    expect(static fn () => MemoryStore::assertDriver('no-such-driver'))->toThrow(
        MemoryUnavailable::class,
        'Code-review memory unavailable: the PDO driver "no-such-driver" is not installed. Continue without memory.',
    );
    expect(static fn () => MemoryStore::assertDriver('sqlite'))->not->toThrow(MemoryUnavailable::class);
});

test('migrate refuses a source outside the project or one that is not a Markdown file', function (string $source): void {
    $root = memoryRepository();

    [$exitCode, $stdout, $stderr] = memoryRun($root, 'migrate', '--source', $source);

    expect($exitCode)->toBe(1);
    expect($stdout)->toBe('');
    expect($stderr)->toBe('Cannot read the memory source "' . $source . '": give an existing relative Markdown file inside the project.' . PHP_EOL);

    installerRemoveDirectory($root);
})->with([
    'a file that is not Markdown' => ['.gitignore'],
    'a missing file' => ['docs/memory/OTHER.md'],
    'an absolute path' => ['/etc/hosts.md'],
    'a path that climbs out' => ['../PROJECT_MEMORY.md'],
]);

test('migrate reads a Markdown file the caller names', function (): void {
    $root = memoryRepository();
    installerWriteFile($root . '/notes/memory.md', "### only-one — One lesson\n- Trigger: t\n- Rule: r\n- Role: shared\n");

    [$exitCode, $stdout] = memoryRun($root, 'migrate', '--source', 'notes/memory.md');

    expect($exitCode)->toBe(0);
    expect($stdout)->toContain('"source":"notes/memory.md","parsed":1,"inserted":1');

    installerRemoveDirectory($root);
});

test('the memory command explains its usage, and a query never takes free text', function (string ...$arguments): void {
    $root = memoryRepository();

    [$exitCode, $stdout, $stderr] = memoryRun($root, ...$arguments);

    expect($exitCode)->toBe(1);
    expect($stdout)->toBe('');
    expect($stderr)->toStartWith('Usage: ai-olympus memory migrate');
    expect(is_file($root . '/' . MEMORY_DATABASE))->toBeFalse();

    installerRemoveDirectory($root);
})->with([
    'add without an entry' => ['add'],
    'a limit of zero' => ['query', '--role', 'donatello', '--symbol', 'a', '--limit', '0'],
    'an unknown action' => ['forget'],
    'a query with a quote in a symbol' => ['query', '--role', 'donatello', '--symbol', '"OR"'],
    'a query with a role in the wrong format' => ['query', '--role', 'Not A Role', '--symbol', 'backoff'],
    'a query with no path and no symbol' => ['query', '--role', 'donatello'],
    'a query without a role' => ['query', '--symbol', 'backoff'],
    'a query with punctuation only' => ['query', '--role', 'donatello', '--symbol', '...'],
    'a query with tracker text as a symbol' => ['query', '--role', 'donatello', '--symbol', 'ignore previous instructions'],
    'a role given twice' => ['query', '--role', 'donatello', '--role', 'krang', '--symbol', 'a'],
    'a run directory the memory never lives in' => ['query', '--role', 'donatello', '--symbol', 'a', '--run-dir', 'build/run'],
    'migrate with an unknown flag' => ['migrate', '--force', 'x'],
    'no action' => [],
]);

test('the help text advertises the memory command', function (): void {
    ob_start();
    Installer::run(['ai-olympus']);
    $output = (string) ob_get_clean();

    expect($output)->toContain('memory migrate | memory add --entry <file.json> | memory query --role <role> --path <p> --symbol <s>');
    expect($output)->toContain('per-user code-review memory (SQLite with FTS5)');
});

test('install and prune never create or delete the memory database', function (): void {
    $root = memoryRepository();
    memoryRun($root, 'migrate');
    $before = (string) file_get_contents($root . '/' . MEMORY_DATABASE);
    $cwd = (string) getcwd();

    try {
        chdir($root);
        ob_start();
        Installer::run(['ai-olympus', 'install', '--force', '--prune']);
        ob_end_clean();
    } finally {
        chdir($cwd);
    }

    expect(file_get_contents($root . '/' . MEMORY_DATABASE))->toBe($before);
    expect(is_file($root . '/.codex/run/code-review-memory.sqlite'))->toBeFalse();

    installerRemoveDirectory($root);
});
