<?php

declare(strict_types = 1);

use Pekral\AiOlympus\GraphCommand;
use Pekral\AiOlympus\GraphIndexer;
use Pekral\AiOlympus\Installer;
use Tests\Support\GraphFixture;
use Tests\Support\StderrCapture;

/**
 * @return array{0: int, 1: string, 2: string}
 */
function graphRun(string $root, string ...$arguments): array
{
    $cwd = (string) getcwd();
    chdir($root);

    try {
        ob_start();
        [$exitCode, $stderr] = StderrCapture::run(static fn (): int => Installer::run(['ai-olympus', 'graph', ...array_values($arguments)]));
        $stdout = (string) ob_get_clean();
    } finally {
        chdir($cwd);
    }

    return [$exitCode, $stdout, $stderr];
}

/**
 * @return array{graph: array<string, int|string>, omitted: array<string, int>, related: list<array{file: string, role: string}>}
 */
function graphSlice(string $json): array
{
    /** @var array{graph: array<string, int|string>, omitted: array<string, int>, related: list<array{file: string, role: string}>} $slice */
    $slice = json_decode($json, associative: true);

    return $slice;
}

test('build stores the graph and reports what it parsed, and query prints one slice as JSON', function (): void {
    $root = GraphFixture::repository();

    [$buildExit, $summary] = graphRun($root, 'build');
    [$queryExit, $slice] = graphRun($root, 'query', 'UserService');

    expect($buildExit)->toBe(0);
    expect($summary)->toBe('{"graph":".claude/run/graph.json","files":8,"parsed":8,"reused":0,"removed":0,"errors":["src/Broken.php"]}' . PHP_EOL);
    expect(file_get_contents($root . '/' . GraphCommand::RELATIVE_PATH))->toStartWith(
        "{\n    \"version\": 2,\n    \"files\": {\n        \"config/app.php\": {",
    );
    expect($queryExit)->toBe(0);
    expect(json_decode($slice, associative: true))->toMatchArray([
        'query' => 'UserService',
        'entities' => [['id' => 'App\UserService', 'kind' => 'class', 'line' => 7, 'file' => 'src/UserService.php']],
        'incoming' => [],
        'files' => [],
        'graph' => ['state' => 'fresh', 'files' => 8, 'changed' => 0],
    ]);

    installerRemoveDirectory($root);
});

test('querying a Laravel route prints only its slice as JSON', function (): void {
    $root = GraphFixture::repository('graph-laravel');
    graphRun($root, 'build');

    [$exitCode, $stdout] = graphRun($root, 'query', 'route:GET:/admin/dashboard');

    expect($exitCode)->toBe(0);
    expect(json_decode($stdout, associative: true))->toBe([
        'query' => 'route:GET:/admin/dashboard',
        'entities' => [['id' => 'route:GET:/admin/dashboard', 'kind' => 'route', 'line' => 11, 'file' => 'routes/web.php']],
        'outgoing' => [
            [
                'from' => 'route:GET:/admin/dashboard',
                'type' => 'ROUTE_HANDLED_BY',
                'to' => 'App\Http\Controllers\ShowDashboard::__invoke',
                'line' => 11,
                'file' => 'routes/web.php',
            ],
        ],
        'incoming' => [],
        'files' => [],
        'related' => [
            ['file' => 'routes/web.php', 'entity' => 'route:GET:/admin/dashboard', 'role' => 'code', 'distance' => 0],
            [
                'file' => 'app/Http/Controllers/ShowDashboard.php',
                'entity' => 'App\\Http\\Controllers\\ShowDashboard::__invoke',
                'role' => 'code',
                'distance' => 1,
            ],
        ],
        'graph' => ['state' => 'fresh', 'files' => 16, 'changed' => 0],
    ]);

    installerRemoveDirectory($root);
});

test('querying an entity that does not exist prints an empty, well-formed result', function (): void {
    $root = GraphFixture::repository();
    graphRun($root, 'build');

    [$exitCode, $stdout, $stderr] = graphRun($root, 'query', 'App\Missing');

    expect($exitCode)->toBe(0);
    expect($stderr)->toBe('');
    expect($stdout)->toBe(
        '{"query":"App\\\\Missing","entities":[],"outgoing":[],"incoming":[],"files":[],"related":[],'
        . '"graph":{"state":"fresh","files":8,"changed":0}}' . PHP_EOL,
    );

    installerRemoveDirectory($root);
});

test('generation is idempotent over an unchanged tree', function (): void {
    $root = GraphFixture::repository();
    $path = $root . '/' . GraphCommand::RELATIVE_PATH;
    graphRun($root, 'build');
    $first = (string) file_get_contents($path);

    [, $summary] = graphRun($root, 'build');

    expect(file_get_contents($path))->toBe($first);
    expect($summary)->toContain('"parsed":0,"reused":8');

    installerRemoveDirectory($root);
});

test('a file with a non-UTF-8 config key is still indexed and the graph is written', function (): void {
    $root = GraphFixture::repository();
    installerWriteFile(
        $root . '/src/Latin1Config.php',
        "<?php\n\nnamespace App;\n\nfunction limit(): int\n{\n    return config(\"kl\xED\xE8.limit\");\n}\n",
    );

    [$exitCode, $summary] = graphRun($root, 'build');

    expect($exitCode)->toBe(0);
    expect($summary)->toContain('"files":9,"parsed":9');
    expect(file_get_contents($root . '/' . GraphCommand::RELATIVE_PATH))->toContain('"src/Latin1Config.php": {');

    installerRemoveDirectory($root);
});

test('a graph that cannot be written fails the build instead of reporting success', function (): void {
    $root = GraphFixture::repository();
    mkdir($root . '/' . GraphCommand::RELATIVE_PATH, recursive: true);

    [$exitCode, $stdout, $stderr] = graphRun($root, 'build');

    expect($exitCode)->toBe(1);
    expect($stdout)->toBe('');
    expect($stderr)->toStartWith('Cannot write the knowledge graph file ')->toEndWith(GraphCommand::RELATIVE_PATH . '.' . PHP_EOL);

    installerRemoveDirectory($root);
});

test('a build refuses to write a graph at a location git does not ignore, and writes nothing', function (string $setup): void {
    $root = GraphFixture::repository();
    $path = $root . '/' . GraphCommand::RELATIVE_PATH;
    putenv('GIT_CONFIG_GLOBAL=/dev/null');
    putenv('GIT_CONFIG_NOSYSTEM=1');

    try {
        match ($setup) {
            'no gitignore entry' => unlink($root . '/.gitignore'),
            'not a git repository' => installerRemoveDirectory($root . '/.git'),
            default => (function () use ($root, $path): void {
                installerWriteFile($path, '{}');
                exec('git -C ' . escapeshellarg($root) . ' add -f ' . escapeshellarg(GraphCommand::RELATIVE_PATH) . ' 2>&1');
            })(),
        };
        $before = is_file($path) ? file_get_contents($path) : null;

        [$exitCode, $stdout, $stderr] = graphRun($root, 'build');
    } finally {
        putenv('GIT_CONFIG_GLOBAL');
        putenv('GIT_CONFIG_NOSYSTEM');
    }

    expect($exitCode)->toBe(1);
    expect($stdout)->toBe('');
    expect($stderr)->toBe(
        'Refusing to write the knowledge graph: .claude/run/graph.json is not git-ignored or is tracked. '
        . 'Add `.claude/run/` to .gitignore, untrack the file, and run `ai-olympus graph build` again.' . PHP_EOL,
    );
    expect(is_file($path) ? file_get_contents($path) : null)->toBe($before);

    installerRemoveDirectory($root);
})->with([
    'a graph file that git tracks' => ['tracked'],
    'a location with no gitignore entry' => ['no gitignore entry'],
    'a project that is not a git repository' => ['not a git repository'],
]);

test('a graph from another version is rebuilt rather than trusted', function (): void {
    $root = GraphFixture::repository();
    installerWriteFile($root . '/' . GraphCommand::RELATIVE_PATH, '{"version": 1, "files": {}}');

    [$queryExit, , $stderr] = graphRun($root, 'query', 'UserService');
    [, $summary] = graphRun($root, 'build');

    expect($queryExit)->toBe(1);
    expect($stderr)->toBe('No graph at .claude/run/graph.json. Run `ai-olympus graph build` first.' . PHP_EOL);
    expect($summary)->toContain('"parsed":8,"reused":0');

    installerRemoveDirectory($root);
});

test('the graph command explains its usage when the action or the entity is missing', function (string ...$arguments): void {
    $root = installerCreateProjectRoot();

    [$exitCode, $stdout, $stderr] = graphRun($root, ...$arguments);

    expect($exitCode)->toBe(1);
    expect($stdout)->toBe('');
    expect($stderr)->toBe('Usage: ai-olympus graph build | ai-olympus graph query <path|class|symbol> [--limit <n>]' . PHP_EOL);

    installerRemoveDirectory($root);
})->with([
    'a limit flag without a value' => ['query', 'UserService', '--limit'],
    'a limit of zero' => ['query', 'UserService', '--limit', '0'],
    'a limit that is not a number' => ['query', 'UserService', '--limit', 'many'],
    'an unknown flag' => ['query', 'UserService', '--depth', '2'],
    'no action' => [],
    'no entity' => ['query'],
    'unknown action' => ['draw'],
]);

test('the help text advertises the graph command', function (): void {
    ob_start();
    Installer::run(['ai-olympus']);
    $output = (string) ob_get_clean();

    expect($output)->toContain('ai-olympus graph build | graph query <path|class|symbol>');
    expect($output)->toContain('Build the repository knowledge graph, or query one slice of it as JSON.');
});

test('a query over a graph the repository has moved past answers from the current files and says it refreshed', function (): void {
    $root = GraphFixture::repository('graph-laravel');
    graphRun($root, 'build');
    unlink($root . '/tests/Feature/CreateOrderActionTest.php');
    installerWriteFile(
        $root . '/app/Console/ReprocessOrder.php',
        "<?php\n\nnamespace App\\Console;\n\nuse App\\Actions\\CreateOrderAction;\n\n"
        . "final class ReprocessOrder\n{\n    public function __construct(private CreateOrderAction \$action)\n    {\n    }\n}\n",
    );
    $stored = (string) file_get_contents($root . '/' . GraphCommand::RELATIVE_PATH);

    [$exitCode, $stdout] = graphRun($root, 'query', 'App\Actions\CreateOrderAction');

    $slice = graphSlice($stdout);
    expect($exitCode)->toBe(0);
    expect($slice['graph'])->toBe(['state' => 'refreshed', 'files' => 16, 'changed' => 2]);
    expect(array_column($slice['related'], 'file'))->toBe([
        'app/Actions/CreateOrderAction.php',
        'app/Console/ReprocessOrder.php',
        'app/Events/OrderPlaced.php',
        'app/Http/Controllers/OrderController.php',
        'app/Jobs/ProcessOrder.php',
        'app/Models/Order.php',
    ]);
    expect(file_get_contents($root . '/' . GraphCommand::RELATIVE_PATH))->toBe($stored);

    installerRemoveDirectory($root);
});

test('a query with a limit prints the bounded slice and what it omitted', function (): void {
    $root = GraphFixture::repository('graph-laravel');
    graphRun($root, 'build');

    [$exitCode, $stdout] = graphRun($root, 'query', 'App\Actions\CreateOrderAction', '--limit', '2');

    $slice = graphSlice($stdout);
    expect($exitCode)->toBe(0);
    expect(array_column($slice['related'], 'file'))->toBe(['app/Actions/CreateOrderAction.php', 'app/Events/OrderPlaced.php']);
    expect($slice['omitted'])->toBe(['files' => 0, 'incoming' => 3, 'outgoing' => 4, 'related' => 4]);

    installerRemoveDirectory($root);
});

test('the graph names the files to read for one Action instead of the whole repository', function (): void {
    $root = GraphFixture::repository('graph-laravel');
    graphRun($root, 'build');
    $indexed = array_keys(GraphIndexer::index($root, [])->files);

    [, $stdout] = graphRun($root, 'query', 'App\Actions\CreateOrderAction');

    $slice = graphSlice($stdout);
    $related = array_column($slice['related'], 'file');
    $textMatches = array_values(array_filter(
        $indexed,
        static fn (string $path): bool => str_contains((string) file_get_contents($root . '/' . $path), 'CreateOrderAction'),
    ));
    expect($slice['graph']['files'])->toBe(16);
    expect(count($indexed))->toBe(16);
    expect($textMatches)->toBe([
        'app/Actions/CreateOrderAction.php',
        'app/Http/Controllers/OrderController.php',
        'tests/Feature/CreateOrderActionTest.php',
    ]);
    expect($related)->toBe([
        'app/Actions/CreateOrderAction.php',
        'app/Events/OrderPlaced.php',
        'app/Http/Controllers/OrderController.php',
        'app/Jobs/ProcessOrder.php',
        'app/Models/Order.php',
        'tests/Feature/CreateOrderActionTest.php',
    ]);
    expect(array_column(array_filter($slice['related'], static fn (array $entry): bool => $entry['role'] === 'test'), 'file'))->toBe([
        'tests/Feature/CreateOrderActionTest.php',
    ]);
    expect(count($indexed) - count($related))->toBe(10);

    installerRemoveDirectory($root);
});
