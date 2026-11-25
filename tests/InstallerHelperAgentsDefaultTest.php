<?php

declare(strict_types = 1);

use Pekral\AiOlympus\HelperAgentsDefaultOutcome;
use Pekral\AiOlympus\Installer;
use Pekral\AiOlympus\InstallerHelperAgentsDefault;

function helperAgentsDefaultRoot(?string $composerJson): string
{
    $root = sys_get_temp_dir() . '/ai-olympus-helper-default-' . bin2hex(random_bytes(6));
    installerEnsureDirectory($root);

    if ($composerJson !== null) {
        file_put_contents($root . '/composer.json', $composerJson);
    }

    return $root;
}

/**
 * @return array{0: string, 1: string}
 */
function helperAgentsDefaultInstall(string $root): array
{
    $homeEnv = getenv('HOME');
    $homeBefore = $homeEnv !== false && $homeEnv !== '' ? $homeEnv : getenv('USERPROFILE');
    putenv('HOME=' . $root);
    $cwd = getcwd();
    $originalCwd = $cwd !== false ? $cwd : '';

    try {
        chdir($root);
        ob_start();
        Installer::run(['ai-olympus', 'install']);
        $output = (string) ob_get_clean();
        $composerJson = (string) file_get_contents($root . '/composer.json');
    } finally {
        installerRestoreEnvAndCleanup($homeBefore, $originalCwd, $root);
    }

    return [$output, $composerJson];
}

test('the installer adds helper-agents true when the key is missing and keeps the formatting of the file', function (string $before, string $after): void {
    $root = helperAgentsDefaultRoot($before);

    $outcome = InstallerHelperAgentsDefault::apply($root);

    expect($outcome)->toBe(HelperAgentsDefaultOutcome::Written)
        ->and((string) file_get_contents($root . '/composer.json'))->toBe($after);

    installerRemoveDirectory($root);
})->with([
    'a manifest without the key, indented by two spaces' => [
        "{\n  \"name\": \"acme/app\",\n  \"extra\": {\n    \"ai-olympus\": {\n      \"voice\": \"technical\"\n    }\n  }\n}\n",
        "{\n  \"name\": \"acme/app\",\n  \"extra\": {\n    \"ai-olympus\": {\n      \"voice\": \"technical\",\n      \"helper-agents\": true\n    }\n  }\n}\n",
    ],
    'an empty file object without a trailing newline' => [
        '{}',
        "{\n    \"extra\": {\n        \"ai-olympus\": {\n            \"helper-agents\": true\n        }\n    }\n}",
    ],
    'extra without a manifest, indented by tabs' => [
        "{\n\t\"name\": \"acme/app\",\n\t\"extra\": {\n\t\t\"class\": \"Acme\\\\Plugin\"\n\t}\n}\n",
        "{\n\t\"name\": \"acme/app\",\n\t\"extra\": {\n\t\t\"class\": \"Acme\\\\Plugin\",\n\t\t\"ai-olympus\": {\n\t\t\t\"helper-agents\": true\n\t\t}\n\t}\n}\n",
    ],
    'no extra, with a float and a URL' => [
        "{\n    \"version\": 1.0,\n    \"homepage\": \"https://example.com/app\"\n}\n",
        "{\n    \"version\": 1.0,\n    \"homepage\": \"https://example.com/app\",\n    \"extra\": {\n        \"ai-olympus\": {\n            \"helper-agents\": true\n        }\n    }\n}\n",
    ],
]);

test('the installer never changes a helper-agents value that is present, valid or not', function (string $value): void {
    $before = "{\n    \"extra\": {\n        \"ai-olympus\": {\n            \"helper-agents\": " . $value . "\n        }\n    }\n}\n";
    $root = helperAgentsDefaultRoot($before);

    $outcome = InstallerHelperAgentsDefault::apply($root);

    expect($outcome)->toBe(HelperAgentsDefaultOutcome::Unchanged)
        ->and((string) file_get_contents($root . '/composer.json'))->toBe($before);

    installerRemoveDirectory($root);
})->with([
    'false' => ['false'],
    'null' => ['null'],
    'a string' => ['"false"'],
    'true' => ['true'],
]);

test('the installer writes nothing when the project manifest has no place for the key', function (?string $before): void {
    $root = helperAgentsDefaultRoot($before);

    $outcome = InstallerHelperAgentsDefault::apply($root);

    expect($outcome)->toBe(HelperAgentsDefaultOutcome::Unchanged)
        ->and(is_file($root . '/composer.json') ? (string) file_get_contents($root . '/composer.json') : null)->toBe($before);

    installerRemoveDirectory($root);
})->with([
    'a manifest that is not an object' => ["{\n    \"extra\": {\n        \"ai-olympus\": []\n    }\n}\n"],
    'an extra that is not an object' => ["{\n    \"extra\": []\n}\n"],
    'a file that is not JSON' => ["{ not json\n"],
    'a top-level array' => ["[]\n"],
    'no composer.json' => [null],
]);

test('the installer keeps a file whose formatting it cannot reproduce', function (string $before): void {
    $root = helperAgentsDefaultRoot($before);

    $outcome = InstallerHelperAgentsDefault::apply($root);

    expect($outcome)->toBe(HelperAgentsDefaultOutcome::FormatKept)
        ->and((string) file_get_contents($root . '/composer.json'))->toBe($before);

    installerRemoveDirectory($root);
})->with([
    'a compact list' => ["{\n    \"keywords\": [\"a\", \"b\"]\n}\n"],
    'Windows line endings' => ["{\r\n    \"name\": \"acme/app\"\r\n}\r\n"],
    'an escaped slash' => ["{\n    \"homepage\": \"https:\\/\\/example.com\"\n}\n"],
]);

test('the install reports the written key and the lock hash it leaves out of date', function (): void {
    $root = helperAgentsDefaultRoot('{}');
    file_put_contents($root . '/composer.lock', '{"content-hash": "0123"}');

    [$output, $composerJson] = helperAgentsDefaultInstall($root);

    expect($composerJson)->toContain('"helper-agents": true')
        ->and($output)->toContain(
            'Added "helper-agents": true to extra.ai-olympus in composer.json, so helper agents stay allowed; set it to false to forbid them.'
            . ' Commit the change. composer.lock is now out of date, because its content-hash covers extra:'
            . ' run `composer update --lock` to refresh the hash without updating a package.',
        );
});

test('the install without a lock file names no lock, and a second install changes nothing', function (): void {
    $root = helperAgentsDefaultRoot('{}');

    InstallerHelperAgentsDefault::apply($root);
    $afterFirst = (string) file_get_contents($root . '/composer.json');
    [$output, $composerJson] = helperAgentsDefaultInstall($root);

    expect($composerJson)->toBe($afterFirst)
        ->and($output)->not->toContain('helper-agents')
        ->and($output)->not->toContain('composer.lock');
});

test('the install names the key when it keeps the formatting or cannot write the file', function (string $before, int $mode, string $message): void {
    $root = helperAgentsDefaultRoot($before);
    chmod($root . '/composer.json', $mode);

    [$output, $composerJson] = helperAgentsDefaultInstall($root);

    expect($composerJson)->toBe($before)
        ->and($output)->toContain($message);
})->with([
    'a file it cannot write' => [
        '{}',
        0o444,
        'composer.json has no extra.ai-olympus.helper-agents key, and the installer cannot write the file; add "helper-agents": true there yourself.',
    ],
    'a formatting it keeps' => [
        "{\n    \"keywords\": [\"a\"]\n}\n",
        0o644,
        'composer.json has no extra.ai-olympus.helper-agents key. The installer kept the file unchanged to keep its formatting;'
        . ' add "helper-agents": true there yourself.',
    ],
]);
