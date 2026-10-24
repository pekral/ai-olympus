<?php

declare(strict_types = 1);

// One shared json_string (issue #241, follow-up of #238). Per the project's test-isolation rule a Pest
// test cannot exec a real .sh, so the behavioural proof lives in the helper's own `--self-test`. The
// pins below fix the wiring: one definition, three scripts that source it, and the build that runs it.

function jsonStringSource(string $relativePath): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
}

test('json_string has one definition, in a shipped, executable, self-testing helper run by the build', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $helper = $packageDir . '/skills/_shared/json-string.sh';

    expect(is_file($helper))->toBeTrue();
    expect(is_executable($helper))->toBeTrue();

    expect(jsonStringSource('skills/_shared/json-string.sh'))
        ->toStartWith('#!/usr/bin/env bash')
        ->toContain('if [[ "${1:-}" == "--self-test" ]]; then');

    /** @var array{scripts: array<string, array<int, string>>} $composer */
    $composer = json_decode((string) file_get_contents($packageDir . '/composer.json'), associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);

    expect(substr_count(implode(' ', $composer['scripts']['shell-self-tests']), ' skills/_shared/json-string.sh '))->toBe(1);
});

test('the three scripts source the helper and define no copy of json_string', function (): void {
    $definitions = 0;

    foreach (['check-handoff', 'run-validation', 'check-cr-header'] as $script) {
        $source = jsonStringSource('skills/_shared/' . $script . '.sh');

        expect(substr_count($source, 'json-string.sh'))->toBeGreaterThanOrEqual(2)
            ->and($source)->not->toContain('json_string() {');
    }

    $files = glob(dirname(__DIR__, 2) . '/skills/_shared/*.sh');
    $files = $files === false ? [] : $files;

    foreach ($files as $file) {
        $definitions += substr_count((string) file_get_contents($file), 'json_string() {');
    }

    expect($definitions)->toBe(1);
});
