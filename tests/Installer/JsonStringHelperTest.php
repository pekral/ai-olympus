<?php

declare(strict_types = 1);

function jsonStringSource(string $relativePath): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
}

test('the json-string helper is an executable bash script with a --self-test entry point', function (): void {
    $helper = dirname(__DIR__, 2) . '/skills/_shared/json-string.sh';

    expect(is_executable($helper))->toBeTrue()
        ->and(jsonStringSource('skills/_shared/json-string.sh'))
        ->toStartWith('#!/usr/bin/env bash')
        ->toContain('if [[ "${1:-}" == "--self-test" ]]; then');
});

test('the build runs the json-string helper self-test once', function (): void {
    /** @var array{scripts: array<string, array<int, string>>} $composer */
    $composer = json_decode(jsonStringSource('composer.json'), associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);

    expect(substr_count(implode(' ', $composer['scripts']['shell-self-tests']), ' skills/_shared/json-string.sh '))->toBe(1);
});

test('the shared script sources the json-string helper and defines no copy of json_string', function (string $script): void {
    $source = jsonStringSource('skills/_shared/' . $script . '.sh');

    expect(substr_count($source, 'json-string.sh'))->toBeGreaterThanOrEqual(2)
        ->and($source)->not->toContain('json_string() {');
})->with(['check-handoff', 'run-validation', 'check-cr-header']);

test('exactly one shared script defines json_string', function (): void {
    $files = glob(dirname(__DIR__, 2) . '/skills/_shared/*.sh');
    assert($files !== false);

    $definitions = array_sum(array_map(
        static fn (string $file): int => substr_count((string) file_get_contents($file), 'json_string() {'),
        $files,
    ));

    expect($definitions)->toBe(1);
});
