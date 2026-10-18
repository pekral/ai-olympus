<?php

declare(strict_types = 1);

use Pekral\AiOlympus\GraphIndexer;
use Pekral\AiOlympus\GraphQuery;
use Tests\Support\GraphFixture;

test('querying a class returns its parents, implementations, callers and the files that use it', function (): void {
    $root = GraphFixture::project();
    $files = GraphIndexer::index($root, [])['graph']['files'];

    $slice = GraphQuery::slice($files, 'App\UserRepository');

    expect($slice['entities'])->toBe([['id' => 'App\UserRepository', 'kind' => 'class', 'line' => 10, 'file' => 'src/UserRepository.php']]);
    expect(array_column($slice['outgoing'], 'to', 'type'))->toMatchArray(['EXTENDS' => 'App\BaseRepository', 'IMPLEMENTS' => 'App\Contracts\Repository']);
    expect(array_column($slice['incoming'], 'from'))->toContain('App\UserService::find', 'file:tests/UserRepositoryTest.php');
    expect($slice['files'])->toBe(['src/UserRepository.php', 'src/UserService.php', 'tests/UserRepositoryTest.php']);

    installerRemoveDirectory($root);
});

test('querying an interface returns the classes that implement it', function (): void {
    $root = GraphFixture::project();
    $files = GraphIndexer::index($root, [])['graph']['files'];

    $slice = GraphQuery::slice($files, 'App\Contracts\Repository');

    expect(GraphFixture::describe($slice['incoming']))->toBe([
        'file:src/UserRepository.php USES App\Contracts\Repository',
        'App\UserRepository IMPLEMENTS App\Contracts\Repository',
    ]);

    installerRemoveDirectory($root);
});

test('a query takes a file path, a short class name, or a method symbol', function (): void {
    $root = GraphFixture::project();
    $files = GraphIndexer::index($root, [])['graph']['files'];

    $byPath = GraphQuery::slice($files, 'src/UserService.php');
    $byShortName = GraphQuery::slice($files, 'UserService');
    $bySymbol = GraphQuery::slice($files, '\App\UserRepository::empty');

    expect(array_column($byPath['entities'], 'id'))->toBe(['file:src/UserService.php', 'App\UserService', 'App\UserService::find']);
    expect(array_column($byShortName['entities'], 'id'))->toBe(['App\UserService']);
    expect(array_column($bySymbol['entities'], 'id'))->toBe(['App\UserRepository::empty']);
    expect(GraphFixture::describe($bySymbol['incoming']))->toBe(['App\UserRepository::all CALLS App\UserRepository::empty']);

    installerRemoveDirectory($root);
});

test('a Laravel request is traversable from its route through the controller and the Action to the model and its table', function (): void {
    $root = GraphFixture::project('graph-laravel');
    $files = GraphIndexer::index($root, [])['graph']['files'];

    $route = GraphQuery::slice($files, 'route:POST:/orders');
    $controller = GraphQuery::slice($files, 'App\Http\Controllers\OrderController::store');
    $action = GraphQuery::slice($files, 'App\Actions\CreateOrderAction');
    $model = GraphQuery::slice($files, 'App\Models\Order');
    $table = GraphQuery::slice($files, 'table:orders');

    expect(GraphFixture::describe($route['outgoing']))->toBe(['route:POST:/orders ROUTE_HANDLED_BY App\Http\Controllers\OrderController::store']);
    expect(GraphFixture::describe($controller['outgoing'], 'INJECTS'))->toContain(
        'App\Http\Controllers\OrderController::store INJECTS App\Actions\CreateOrderAction',
    );
    expect(GraphFixture::describe($action['outgoing'], 'CALLS'))->toContain('App\Actions\CreateOrderAction::handle CALLS App\Models\Order::create');
    expect(GraphFixture::describe($model['outgoing'], 'USES_TABLE'))->toBe(['App\Models\Order USES_TABLE table:orders']);
    expect($table['entities'])->toBe([
        ['id' => 'table:orders', 'kind' => 'table', 'line' => 11, 'file' => 'database/migrations/2024_01_01_000000_create_orders_table.php'],
    ]);
    expect(GraphFixture::describe($table['incoming'], 'MIGRATES_TABLE'))->toBe([
        'file:database/migrations/2024_01_01_000000_create_orders_table.php MIGRATES_TABLE table:orders',
        'file:database/migrations/2024_02_01_000000_drop_legacy_from_orders_table.php MIGRATES_TABLE table:orders',
    ]);

    installerRemoveDirectory($root);
});

test('a relation defined on a trait is attributed to the model that uses the trait', function (): void {
    $root = GraphFixture::project('graph-laravel');
    $files = GraphIndexer::index($root, [])['graph']['files'];

    $slice = GraphQuery::slice($files, 'App\Models\Order');

    $relations = array_filter($slice['outgoing'], static fn (array $edge): bool => $edge['type'] === 'HAS_RELATION');
    expect(array_map(static fn (array $edge): string => $edge['from'] . ' -> ' . $edge['to'] . ' @ ' . $edge['file'], array_values($relations)))->toBe([
        'App\Models\Order::customer -> App\Models\User @ app/Models/Order.php',
        'App\Models\Order::comments -> App\Models\Comment @ app/Models/Order.php',
        'App\Models\Order::items -> App\Models\OrderItem @ app/Models/Concerns/HasItems.php',
    ]);

    installerRemoveDirectory($root);
});
