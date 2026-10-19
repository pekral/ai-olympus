<?php

declare(strict_types = 1);

use Pekral\AiOlympus\GraphEdge;
use Pekral\AiOlympus\GraphNode;
use Pekral\AiOlympus\GraphRecord;
use Pekral\AiOlympus\GraphSummary;
use Pekral\AiOlympus\Slice;
use Pekral\AiOlympus\SliceEdge;
use Pekral\AiOlympus\SliceEntity;
use Pekral\AiOlympus\SliceGraph;
use Pekral\AiOlympus\SliceOmitted;
use Pekral\AiOlympus\SliceRelated;

test('a graph record serializes to the JSON the graph file stores, keys in a fixed order', function (): void {
    $record = new GraphRecord('abc', [new GraphNode('App\Foo', 'class', 3)], [new GraphEdge('App\Foo', 'USES', 'App\Bar', 4)]);

    expect(json_encode($record, JSON_THROW_ON_ERROR))->toBe(
        '{"hash":"abc","nodes":[{"id":"App\\\\Foo","kind":"class","line":3}],"edges":[{"from":"App\\\\Foo","type":"USES","to":"App\\\\Bar","line":4}]}',
    );
});

test('a record of a file that failed to parse carries its error between the hash and the nodes', function (): void {
    $record = new GraphRecord('abc', [], [], 'syntax error on line 3');

    expect(json_encode($record, JSON_THROW_ON_ERROR))->toBe('{"hash":"abc","error":"syntax error on line 3","nodes":[],"edges":[]}');
});

test('a graph record is rebuilt from the decoded graph file', function (): void {
    $record = GraphRecord::fromArray([
        'hash' => 'abc',
        'nodes' => [['id' => 'App\Foo', 'kind' => 'class', 'line' => 3]],
        'edges' => [['from' => 'App\Foo', 'type' => 'USES', 'to' => 'App\Bar', 'line' => 4]],
    ]);

    expect($record)->toEqual(new GraphRecord('abc', [new GraphNode('App\Foo', 'class', 3)], [new GraphEdge('App\Foo', 'USES', 'App\Bar', 4)]));
});

test('a graph record keeps the error of a file that failed to parse when it is rebuilt', function (): void {
    $record = GraphRecord::fromArray(['hash' => 'def', 'error' => 'syntax error on line 3', 'nodes' => [], 'edges' => []]);

    expect($record)->toEqual(new GraphRecord('def', [], [], 'syntax error on line 3'));
});

test('a slice serializes without omitted unless a limit produced it', function (): void {
    $entity = new SliceEntity('App\Foo', 'class', 3, 'src/Foo.php');
    $edge = new SliceEdge('App\Foo', 'USES', 'App\Bar', 4, 'src/Foo.php');
    $related = new SliceRelated('src/Foo.php', 'App\Foo', 'code', 0);

    $unlimited = new Slice('Foo', [$entity], [$edge], [], ['src/Foo.php'], [$related]);
    $limited = new Slice('Foo', [$entity], [$edge], [], ['src/Foo.php'], [$related], new SliceOmitted(files: 0, incoming: 1, outgoing: 2, related: 3));

    expect(array_keys($unlimited->jsonSerialize()))->toBe(['query', 'entities', 'outgoing', 'incoming', 'files', 'related']);
    expect(json_encode($limited, JSON_THROW_ON_ERROR))->toBe(
        '{"query":"Foo","entities":[{"id":"App\\\\Foo","kind":"class","line":3,"file":"src\/Foo.php"}],'
        . '"outgoing":[{"from":"App\\\\Foo","type":"USES","to":"App\\\\Bar","line":4,"file":"src\/Foo.php"}],"incoming":[],"files":["src\/Foo.php"],'
        . '"related":[{"file":"src\/Foo.php","entity":"App\\\\Foo","role":"code","distance":0}],'
        . '"omitted":{"files":0,"incoming":1,"outgoing":2,"related":3}}',
    );
});

test('a trait relation re-attributed to a user keeps every other field of the edge', function (): void {
    $edge = new SliceEdge('App\Concerns\HasItems::items', 'HAS_RELATION', 'App\OrderItem', 7, 'app/Concerns/HasItems.php');

    expect($edge->withFrom('App\Order::items'))->toEqual(
        new SliceEdge('App\Order::items', 'HAS_RELATION', 'App\OrderItem', 7, 'app/Concerns/HasItems.php'),
    );
});

test('a graph summary serializes its counters and the files that failed to parse', function (): void {
    $summary = new GraphSummary(files: 3, parsed: 2, reused: 1, removed: 0, errors: ['src/Broken.php']);

    expect(json_encode($summary, JSON_THROW_ON_ERROR))->toBe('{"files":3,"parsed":2,"reused":1,"removed":0,"errors":["src\/Broken.php"]}');
});

test('the graph freshness block serializes its state, the indexed files and the changed files in that order', function (): void {
    expect(json_encode(new SliceGraph('refreshed', files: 16, changed: 2), JSON_THROW_ON_ERROR))->toBe('{"state":"refreshed","files":16,"changed":2}');
});
