<?php

declare(strict_types = 1);

use Pekral\AiOlympus\GraphParser;
use Tests\Support\GraphFixture;

test('a class file yields its class, its methods and its EXTENDS, IMPLEMENTS, USES, CALLS and USES_CONFIG relations', function (): void {
    $record = GraphParser::parse('src/UserRepository.php', GraphFixture::source('src/UserRepository.php'));

    expect(array_column($record['nodes'], 'kind', 'id'))->toBe([
        'file:src/UserRepository.php' => 'file',
        'App\UserRepository' => 'class',
        'App\UserRepository::count' => 'method',
        'App\UserRepository::all' => 'method',
        'App\UserRepository::empty' => 'method',
    ]);
    expect(GraphFixture::describe($record['edges']))->toBe([
        'file:src/UserRepository.php USES App\Concerns\Loggable',
        'file:src/UserRepository.php USES App\Contracts\Repository',
        'file:src/UserRepository.php USES App\Support',
        'App\UserRepository EXTENDS App\BaseRepository',
        'App\UserRepository IMPLEMENTS App\Contracts\Repository',
        'App\UserRepository USES App\Concerns\Loggable',
        'App\UserRepository CALLS App\Support\Clock',
        'App\UserRepository::count CALLS App\BaseRepository::boot',
        'App\UserRepository::count USES_CONFIG config:app',
        'App\UserRepository::all CALLS App\UserRepository::log',
        'App\UserRepository::all CALLS App\UserRepository::empty',
        'App\UserRepository::all CALLS App\Helper::run',
        'App\UserRepository::all CALLS App\UserRepository',
    ]);
});

test('an interface extends its parent interfaces and a trait is indexed as a trait', function (): void {
    $interface = GraphParser::parse('src/Contracts/Repository.php', GraphFixture::source('src/Contracts/Repository.php'));
    $trait = GraphParser::parse('src/Concerns/Loggable.php', GraphFixture::source('src/Concerns/Loggable.php'));

    expect(array_column($interface['nodes'], 'kind', 'id'))->toHaveKey('App\Contracts\Repository', 'interface');
    expect(GraphFixture::describe($interface['edges']))->toBe([
        'App\Contracts\Repository EXTENDS Countable',
        'App\Contracts\Repository EXTENDS App\Contracts\Sized',
    ]);
    expect(array_column($trait['nodes'], 'kind', 'id'))->toBe([
        'file:src/Concerns/Loggable.php' => 'file',
        'App\Concerns\Loggable' => 'trait',
        'App\Concerns\Loggable::log' => 'method',
    ]);
});

test('a config file is a config entity and a file under tests is a test file entity', function (): void {
    $config = GraphParser::parse('config/app.php', GraphFixture::source('config/app.php'));
    $test = GraphParser::parse('tests/UserRepositoryTest.php', GraphFixture::source('tests/UserRepositoryTest.php'));

    expect(array_column($config['nodes'], 'kind', 'id'))->toBe(['file:config/app.php' => 'file', 'config:app' => 'config']);
    expect(array_column($test['nodes'], 'kind', 'id'))->toBe(['file:tests/UserRepositoryTest.php' => 'test']);
    expect(GraphFixture::describe($test['edges']))->toBe([
        'file:tests/UserRepositoryTest.php USES App\UserRepository',
        'file:tests/UserRepositoryTest.php CALLS App\UserRepository',
        'file:tests/UserRepositoryTest.php TESTS App\UserRepository',
    ]);
});

test('a call through an untyped variable or a method named config is not recorded', function (): void {
    $record = GraphParser::parse('src/UserService.php', GraphFixture::source('src/UserService.php'));

    expect(GraphFixture::describe($record['edges']))->toBe([
        'file:src/UserService.php USES App\UserRepository',
        'App\UserService::find CALLS App\UserRepository',
    ]);
});

test('a closure declared at class level is not indexed as a method', function (): void {
    $record = GraphParser::parse('src/Factory.php', "<?php\n\nclass Factory\n{\n    const MAKE = static function () {};\n}\n");

    expect(array_column($record['nodes'], 'id'))->toBe(['file:src/Factory.php', 'Factory']);
});
