<?php

declare(strict_types = 1);

use Pekral\AiOlympus\GraphIndexer;
use Pekral\AiOlympus\GraphQuery;
use Pekral\AiOlympus\SliceEdge;
use Pekral\AiOlympus\SliceEntity;
use Pekral\AiOlympus\SliceOmitted;
use Pekral\AiOlympus\SliceRelated;
use Tests\Support\GraphFixture;

test('querying a class returns its parents, implementations, callers and the files that use it', function (): void {
    $root = GraphFixture::project();
    $files = GraphIndexer::index($root, [])->files;

    $slice = GraphQuery::slice($files, 'App\UserRepository');

    expect($slice->entities)->toEqual([new SliceEntity('App\UserRepository', 'class', 10, 'src/UserRepository.php')]);
    expect(array_column($slice->outgoing, 'to', 'type'))->toMatchArray(['EXTENDS' => 'App\BaseRepository', 'IMPLEMENTS' => 'App\Contracts\Repository']);
    expect(array_column($slice->incoming, 'from'))->toContain('App\UserService::find', 'file:tests/UserRepositoryTest.php');
    expect($slice->files)->toBe(['src/UserRepository.php', 'src/UserService.php', 'tests/UserRepositoryTest.php']);

    installerRemoveDirectory($root);
});

test('querying an interface returns the classes that implement it', function (): void {
    $root = GraphFixture::project();
    $files = GraphIndexer::index($root, [])->files;

    $slice = GraphQuery::slice($files, 'App\Contracts\Repository');

    expect(GraphFixture::describe($slice->incoming))->toBe([
        'file:src/UserRepository.php USES App\Contracts\Repository',
        'App\UserRepository IMPLEMENTS App\Contracts\Repository',
    ]);

    installerRemoveDirectory($root);
});

test('a query takes a file path, a short class name, or a method symbol', function (): void {
    $root = GraphFixture::project();
    $files = GraphIndexer::index($root, [])->files;

    $byPath = GraphQuery::slice($files, 'src/UserService.php');
    $byShortName = GraphQuery::slice($files, 'UserService');
    $bySymbol = GraphQuery::slice($files, '\App\UserRepository::empty');

    expect(array_column($byPath->entities, 'id'))->toBe(['file:src/UserService.php', 'App\UserService', 'App\UserService::find']);
    expect(array_column($byShortName->entities, 'id'))->toBe(['App\UserService']);
    expect(array_column($bySymbol->entities, 'id'))->toBe(['App\UserRepository::empty']);
    expect(GraphFixture::describe($bySymbol->incoming))->toBe(['App\UserRepository::all CALLS App\UserRepository::empty']);

    installerRemoveDirectory($root);
});

test('a Laravel request is traversable from its route through the controller and the Action to the model and its table', function (): void {
    $root = GraphFixture::project('graph-laravel');
    $files = GraphIndexer::index($root, [])->files;

    $route = GraphQuery::slice($files, 'route:POST:/orders');
    $controller = GraphQuery::slice($files, 'App\Http\Controllers\OrderController::store');
    $action = GraphQuery::slice($files, 'App\Actions\CreateOrderAction');
    $model = GraphQuery::slice($files, 'App\Models\Order');
    $table = GraphQuery::slice($files, 'table:orders');

    expect(GraphFixture::describe($route->outgoing))->toBe(['route:POST:/orders ROUTE_HANDLED_BY App\Http\Controllers\OrderController::store']);
    expect(GraphFixture::describe($controller->outgoing, 'INJECTS'))->toContain(
        'App\Http\Controllers\OrderController::store INJECTS App\Actions\CreateOrderAction',
    );
    expect(GraphFixture::describe($action->outgoing, 'CALLS'))->toContain('App\Actions\CreateOrderAction::handle CALLS App\Models\Order::create');
    expect(GraphFixture::describe($model->outgoing, 'USES_TABLE'))->toBe(['App\Models\Order USES_TABLE table:orders']);
    expect($table->entities)->toEqual([
        new SliceEntity('table:orders', 'table', 11, 'database/migrations/2024_01_01_000000_create_orders_table.php'),
    ]);
    expect(GraphFixture::describe($table->incoming, 'MIGRATES_TABLE'))->toBe([
        'file:database/migrations/2024_01_01_000000_create_orders_table.php MIGRATES_TABLE table:orders',
        'file:database/migrations/2024_02_01_000000_drop_legacy_from_orders_table.php MIGRATES_TABLE table:orders',
    ]);

    installerRemoveDirectory($root);
});

test('a relation defined on a trait is attributed to the model that uses the trait', function (): void {
    $root = GraphFixture::project('graph-laravel');
    $files = GraphIndexer::index($root, [])->files;

    $slice = GraphQuery::slice($files, 'App\Models\Order');

    $relations = array_filter($slice->outgoing, static fn (SliceEdge $edge): bool => $edge->type === 'HAS_RELATION');
    expect(array_map(static fn (SliceEdge $edge): string => $edge->from . ' -> ' . $edge->to . ' @ ' . $edge->file, array_values($relations)))->toBe([
        'App\Models\Order::customer -> App\Models\User @ app/Models/Order.php',
        'App\Models\Order::comments -> App\Models\Comment @ app/Models/Order.php',
        'App\Models\Order::items -> App\Models\OrderItem @ app/Models/Concerns/HasItems.php',
    ]);

    installerRemoveDirectory($root);
});

test('a task naming one Action gets its callers, model, job, event and test as related files nearest first', function (): void {
    $root = GraphFixture::project('graph-laravel');
    $files = GraphIndexer::index($root, [])->files;

    $slice = GraphQuery::slice($files, 'App\Actions\CreateOrderAction');

    expect($slice->related)->toEqual([
        new SliceRelated('app/Actions/CreateOrderAction.php', 'App\Actions\CreateOrderAction', 'code', 0),
        new SliceRelated('app/Events/OrderPlaced.php', 'App\Events\OrderPlaced', 'code', 1),
        new SliceRelated('app/Http/Controllers/OrderController.php', 'App\Http\Controllers\OrderController::store', 'code', 1),
        new SliceRelated('app/Jobs/ProcessOrder.php', 'App\Jobs\ProcessOrder', 'code', 1),
        new SliceRelated('app/Models/Order.php', 'App\Models\Order', 'code', 1),
        new SliceRelated('tests/Feature/CreateOrderActionTest.php', 'file:tests/Feature/CreateOrderActionTest.php', 'test', 1),
    ]);
    expect($slice->omitted)->toBeNull();

    installerRemoveDirectory($root);
});

test('a result limit keeps the nearest related files and counts what it left out', function (): void {
    $root = GraphFixture::project('graph-laravel');
    $files = GraphIndexer::index($root, [])->files;

    $slice = GraphQuery::slice($files, 'App\Actions\CreateOrderAction', 3);

    expect(array_column($slice->related, 'file'))->toBe([
        'app/Actions/CreateOrderAction.php',
        'app/Events/OrderPlaced.php',
        'app/Http/Controllers/OrderController.php',
    ]);
    expect(count($slice->outgoing))->toBe(3);
    expect(count($slice->incoming))->toBe(3);
    expect($slice->files)->toBe(['app/Http/Controllers/OrderController.php', 'tests/Feature/CreateOrderActionTest.php']);
    expect($slice->omitted)->toEqual(new SliceOmitted(files: 0, incoming: 2, outgoing: 3, related: 3));

    installerRemoveDirectory($root);
});

test('a symbol with no graph node has no related files', function (): void {
    $root = GraphFixture::project('graph-laravel');
    $files = GraphIndexer::index($root, [])->files;

    $slice = GraphQuery::slice($files, 'App\Actions\Unknown', 5);

    expect($slice->related)->toBe([]);
    expect($slice->omitted)->toEqual(new SliceOmitted(files: 0, incoming: 0, outgoing: 0, related: 0));

    installerRemoveDirectory($root);
});
