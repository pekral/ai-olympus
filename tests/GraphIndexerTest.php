<?php

declare(strict_types = 1);

use Pekral\AiOlympus\GraphIndexer;
use Tests\Support\GraphFixture;

test('generation reads the composer autoload directories and config, never vendor, hidden or other directories', function (): void {
    $root = GraphFixture::project();

    $files = GraphIndexer::index($root, [])['graph']['files'];

    expect(array_keys($files))->toBe([
        'config/app.php',
        'src/BaseRepository.php',
        'src/Broken.php',
        'src/Concerns/Loggable.php',
        'src/Contracts/Repository.php',
        'src/UserRepository.php',
        'src/UserService.php',
        'tests/UserRepositoryTest.php',
    ]);

    installerRemoveDirectory($root);
});

test('a file with a syntax error does not abort the whole generation', function (): void {
    $root = GraphFixture::project();

    $result = GraphIndexer::index($root, []);

    expect($result['summary'])->toBe(['files' => 8, 'parsed' => 8, 'reused' => 0, 'removed' => 0, 'errors' => ['src/Broken.php']]);
    expect($result['graph']['files']['src/Broken.php'])->toMatchArray(['nodes' => [], 'edges' => []]);
    expect($result['graph']['files']['src/Broken.php']['error'] ?? '')->toEndWith('on line 3');
    expect($result['graph']['files']['src/UserService.php']['nodes'])->not->toBe([]);

    installerRemoveDirectory($root);
});

test('an incremental run re-parses only changed files and removes deleted or renamed ones', function (): void {
    $root = GraphFixture::project();
    $previous = GraphIndexer::index($root, [])['graph']['files'];
    rename($root . '/src/UserService.php', $root . '/src/AccountService.php');
    unlink($root . '/src/BaseRepository.php');
    file_put_contents($root . '/src/Broken.php', "<?php\n\nnamespace App;\n\nfinal class Fixed\n{\n}\n");

    $result = GraphIndexer::index($root, $previous);

    expect($result['summary'])->toBe(['files' => 7, 'parsed' => 2, 'reused' => 5, 'removed' => 2, 'errors' => []]);
    expect($result['graph']['files'])->not->toHaveKeys(['src/UserService.php', 'src/BaseRepository.php']);
    expect($result['graph']['files']['src/AccountService.php']['nodes'][1]['id'])->toBe('App\UserService');
    expect($result['graph']['files']['src/UserRepository.php'])->toBe($previous['src/UserRepository.php']);

    installerRemoveDirectory($root);
});

test('a project without a composer autoload indexes only its config directory', function (): void {
    $root = GraphFixture::project();
    unlink($root . '/composer.json');

    expect(array_keys(GraphIndexer::index($root, [])['graph']['files']))->toBe(['config/app.php']);

    installerRemoveDirectory($root);
});

test('a Laravel project also indexes its route files and its migrations', function (): void {
    $root = GraphFixture::project('graph-laravel');

    $files = GraphIndexer::index($root, [])['graph']['files'];

    expect(array_keys($files))->toContain(
        'routes/web.php',
        'database/migrations/2024_01_01_000000_create_orders_table.php',
        'database/migrations/2024_02_01_000000_drop_legacy_from_orders_table.php',
    );

    installerRemoveDirectory($root);
});
