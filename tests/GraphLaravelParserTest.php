<?php

declare(strict_types = 1);

use Pekral\AiOlympus\GraphParser;
use Tests\Support\GraphFixture;

/**
 * @return array{hash: string, error?: string, nodes: list<array{id: string, kind: string, line: int}>, edges: list<array{from: string, type: string, to: string, line: int}>}
 */
function graphParseLaravel(string $path): array
{
    return GraphParser::parse($path, GraphFixture::source($path, 'graph-laravel'));
}

test('routes resolve to their controllers inside prefixed and middleware groups, as resources and as single-action controllers', function (): void {
    $record = graphParseLaravel('routes/web.php');

    expect(array_column(array_filter($record['nodes'], static fn (array $node): bool => $node['kind'] === 'route'), 'id'))->toBe([
        'route:POST:/orders',
        'route:GET:/admin/dashboard',
        'route:RESOURCE:/api/orders',
        'route:POST:/orders/{order}/retry',
        'route:GET:/health',
    ]);
    expect(GraphFixture::describe($record['edges'], 'ROUTE_HANDLED_BY'))->toBe([
        'route:POST:/orders ROUTE_HANDLED_BY App\Http\Controllers\OrderController::store',
        'route:GET:/admin/dashboard ROUTE_HANDLED_BY App\Http\Controllers\ShowDashboard::__invoke',
        'route:RESOURCE:/api/orders ROUTE_HANDLED_BY App\Http\Controllers\OrderController',
    ]);
});

test('a group that loads another route file prefixes none of the routes that follow it', function (): void {
    $code = "<?php\n\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::prefix('v1')->group(base_path('routes/v1.php'));\n\n"
        . "if (app()->isLocal()) {\n    Route::get('/status', StatusController::class);\n}\n";

    $record = GraphParser::parse('routes/web.php', $code);

    expect(array_column(array_filter($record['nodes'], static fn (array $node): bool => $node['kind'] === 'route'), 'id'))->toBe(['route:GET:/status']);
});

test('a controller is connected to the Actions and Services injected into its constructor and its methods', function (): void {
    $record = graphParseLaravel('app/Http/Controllers/OrderController.php');

    expect(array_column($record['nodes'], 'kind', 'id'))->toHaveKey('App\Http\Controllers\OrderController', 'controller');
    expect(GraphFixture::describe($record['edges'], 'INJECTS'))->toBe([
        'App\Http\Controllers\OrderController::__construct INJECTS App\Services\PaymentService',
        'App\Http\Controllers\OrderController::store INJECTS App\Http\Requests\StoreOrderRequest',
        'App\Http\Controllers\OrderController::store INJECTS App\Actions\CreateOrderAction',
    ]);
});

test('a test class in a directory named like a Laravel layer stays a plain class', function (): void {
    $code = "<?php\n\nnamespace Tests\\Unit\\Jobs;\n\nfinal class ProcessOrderTest\n{\n"
        . "    public function testHandle(PaymentService \$payments): void\n    {\n    }\n}\n";

    $record = GraphParser::parse('tests/Unit/Jobs/ProcessOrderTest.php', $code);

    expect(array_column($record['nodes'], 'kind', 'id'))->toHaveKey('Tests\Unit\Jobs\ProcessOrderTest', 'class');
    expect(GraphFixture::describe($record['edges'], 'INJECTS'))->toBe([]);
});

test('an Eloquent model is connected to its table and to the models of its relations', function (): void {
    $order = graphParseLaravel('app/Models/Order.php');
    $item = graphParseLaravel('app/Models/OrderItem.php');
    $comment = graphParseLaravel('app/Models/Comment.php');
    $trait = graphParseLaravel('app/Models/Concerns/HasItems.php');

    expect(array_column($order['nodes'], 'kind', 'id'))->toHaveKey('App\Models\Order', 'model');
    expect(GraphFixture::describe($order['edges'], 'HAS_RELATION', 'USES_TABLE'))->toBe([
        'App\Models\Order::customer HAS_RELATION App\Models\User',
        'App\Models\Order::comments HAS_RELATION App\Models\Comment',
        'App\Models\Order USES_TABLE table:orders',
    ]);
    expect(GraphFixture::describe($item['edges'], 'USES_TABLE'))->toBe(['App\Models\OrderItem USES_TABLE table:order_lines']);
    expect(GraphFixture::describe($comment['edges'], 'HAS_RELATION'))->toBe(['App\Models\Comment::commentable HAS_RELATION morph:commentable']);
    expect(GraphFixture::describe($trait['edges'], 'HAS_RELATION'))->toBe(['App\Models\Concerns\HasItems::items HAS_RELATION App\Models\OrderItem']);
});

test('a model without a table property uses the Laravel table naming convention', function (string $model, string $table): void {
    $code = "<?php\n\nnamespace App\\Models;\n\nuse Illuminate\\Foundation\\Auth\\User;\n\nfinal class {$model} extends User\n{\n}\n";

    $record = GraphParser::parse("app/Models/{$model}.php", $code);

    expect(GraphFixture::describe($record['edges'], 'USES_TABLE'))->toBe(["App\\Models\\{$model} USES_TABLE table:{$table}"]);
})->with([
    'consonant and y' => ['Category', 'categories'],
    'regular plural' => ['OrderItem', 'order_items'],
    'sibilant ending' => ['Address', 'addresses'],
]);

test('jobs and events are linked to their dispatchers, and a dispatch in a closure is recorded only as far as it is visible', function (): void {
    $action = graphParseLaravel('app/Actions/CreateOrderAction.php');
    $routes = graphParseLaravel('routes/web.php');
    $job = graphParseLaravel('app/Jobs/ProcessOrder.php');

    expect(GraphFixture::describe($action['edges'], 'DISPATCHES'))->toBe([
        'App\Actions\CreateOrderAction::handle DISPATCHES App\Events\OrderPlaced',
        'App\Actions\CreateOrderAction::handle DISPATCHES App\Jobs\ProcessOrder',
    ]);
    expect(GraphFixture::describe($routes['edges'], 'DISPATCHES'))->toBe(['file:routes/web.php DISPATCHES App\Jobs\ProcessOrder']);
    expect(array_column($job['nodes'], 'kind', 'id'))->toHaveKey('App\Jobs\ProcessOrder', 'job');
    expect(GraphFixture::describe($job['edges'], 'INJECTS'))->toBe([
        'App\Jobs\ProcessOrder::__construct INJECTS App\Models\Order',
        'App\Jobs\ProcessOrder::handle INJECTS App\Services\PaymentService',
    ]);
});

test('a listener is linked to its event by discovery, by the listen property and by a manual registration', function (): void {
    $discovered = graphParseLaravel('app/Listeners/SendOrderConfirmation.php');
    $invokable = graphParseLaravel('app/Listeners/ReserveStock.php');
    $provider = graphParseLaravel('app/Providers/EventServiceProvider.php');
    $event = graphParseLaravel('app/Events/OrderPlaced.php');

    expect(array_column($discovered['nodes'], 'kind', 'id'))->toHaveKey('App\Listeners\SendOrderConfirmation', 'listener');
    expect(array_column($event['nodes'], 'kind', 'id'))->toHaveKey('App\Events\OrderPlaced', 'event');
    expect(GraphFixture::describe($discovered['edges'], 'LISTENS_TO'))->toBe(['App\Listeners\SendOrderConfirmation LISTENS_TO App\Events\OrderPlaced']);
    expect(GraphFixture::describe($invokable['edges'], 'LISTENS_TO'))->toBe(['App\Listeners\ReserveStock LISTENS_TO App\Events\OrderPlaced']);
    expect(GraphFixture::describe($provider['edges'], 'LISTENS_TO'))->toBe([
        'App\Listeners\NotifyWarehouse LISTENS_TO App\Events\OrderPlaced',
        'App\Listeners\AuditOrder LISTENS_TO App\Events\OrderPlaced',
    ]);
});

test('a migration is connected to the tables it creates, changes and drops', function (): void {
    $create = graphParseLaravel('database/migrations/2024_01_01_000000_create_orders_table.php');
    $change = graphParseLaravel('database/migrations/2024_02_01_000000_drop_legacy_from_orders_table.php');

    expect(array_column($create['nodes'], 'kind', 'id'))->toBe([
        'file:database/migrations/2024_01_01_000000_create_orders_table.php' => 'migration',
        'table:orders' => 'table',
    ]);
    expect(GraphFixture::describe($create['edges'], 'MIGRATES_TABLE'))->toBe([
        'file:database/migrations/2024_01_01_000000_create_orders_table.php MIGRATES_TABLE table:orders',
    ]);
    expect(GraphFixture::describe($change['edges'], 'MIGRATES_TABLE'))->toBe([
        'file:database/migrations/2024_02_01_000000_drop_legacy_from_orders_table.php MIGRATES_TABLE table:orders',
    ]);
});

test('a migration whose table name is not spelled out in the source records no table', function (): void {
    $code = "<?php\n\nuse Illuminate\\Support\\Facades\\Schema;\n\nSchema::create(config('tables.orders'), function () {\n});\n";

    $record = GraphParser::parse('database/migrations/2024_03_01_000000_create_configured_table.php', $code);

    expect(array_column($record['nodes'], 'kind'))->toBe(['migration']);
    expect(GraphFixture::describe($record['edges'], 'MIGRATES_TABLE'))->toBe([]);
});

test('a test is connected to the production classes it instantiates or calls', function (): void {
    $record = graphParseLaravel('tests/Feature/CreateOrderActionTest.php');

    expect(GraphFixture::describe($record['edges'], 'TESTS'))->toBe([
        'file:tests/Feature/CreateOrderActionTest.php TESTS App\Actions\CreateOrderAction',
        'file:tests/Feature/CreateOrderActionTest.php TESTS App\Models\Order',
    ]);
});
