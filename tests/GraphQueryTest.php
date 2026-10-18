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
