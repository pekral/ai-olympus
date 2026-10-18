<?php

declare(strict_types = 1);

use Pekral\AiOlympus\GraphCommand;
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

test('build stores the graph and reports what it parsed, and query prints one slice as JSON', function (): void {
    $root = GraphFixture::project();

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
    ]);

    installerRemoveDirectory($root);
});

test('querying a Laravel route prints only its slice as JSON', function (): void {
    $root = GraphFixture::project('graph-laravel');
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
    ]);

    installerRemoveDirectory($root);
});

test('querying an entity that does not exist prints an empty, well-formed result', function (): void {
    $root = GraphFixture::project();
    graphRun($root, 'build');

    [$exitCode, $stdout, $stderr] = graphRun($root, 'query', 'App\Missing');

    expect($exitCode)->toBe(0);
    expect($stderr)->toBe('');
    expect($stdout)->toBe('{"query":"App\\\\Missing","entities":[],"outgoing":[],"incoming":[],"files":[]}' . PHP_EOL);

    installerRemoveDirectory($root);
});

test('generation is idempotent over an unchanged tree', function (): void {
    $root = GraphFixture::project();
    $path = $root . '/' . GraphCommand::RELATIVE_PATH;
    graphRun($root, 'build');
    $first = (string) file_get_contents($path);

    [, $summary] = graphRun($root, 'build');

    expect(file_get_contents($path))->toBe($first);
    expect($summary)->toContain('"parsed":0,"reused":8');

    installerRemoveDirectory($root);
});

test('a file with a non-UTF-8 config key is still indexed and the graph is written', function (): void {
    $root = GraphFixture::project();
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
    $root = GraphFixture::project();
    mkdir($root . '/' . GraphCommand::RELATIVE_PATH, recursive: true);

    [$exitCode, $stdout, $stderr] = graphRun($root, 'build');

    expect($exitCode)->toBe(1);
    expect($stdout)->toBe('');
    expect($stderr)->toStartWith('Cannot write the knowledge graph file ')->toEndWith(GraphCommand::RELATIVE_PATH . '.' . PHP_EOL);

    installerRemoveDirectory($root);
});

test('a graph from another version is rebuilt rather than trusted', function (): void {
    $root = GraphFixture::project();
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
    expect($stderr)->toBe('Usage: ai-olympus graph build | ai-olympus graph query <path|class|symbol>' . PHP_EOL);

    installerRemoveDirectory($root);
})->with([
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
