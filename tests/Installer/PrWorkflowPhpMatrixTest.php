<?php

declare(strict_types = 1);

// CI must check the oldest PHP version the package allows. When `require.php` in composer.json
// is raised or lowered, the lowest entry of the quality and installer matrices must follow it.

test('the quality and installer matrices start at the composer.json PHP minimum', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $composer = (string) file_get_contents($packageDir . '/composer.json');
    $workflow = (string) file_get_contents($packageDir . '/.github/workflows/pr.yml');

    expect(preg_match('/"require": \{\s*"php": "\^(\d+\.\d+)"/', $composer, $constraint))->toBe(1);
    $minimum = $constraint[1] ?? null;

    foreach (['quality-checks', 'installer-compatibility'] as $job) {
        expect(preg_match('/^  ' . $job . ':\n(?:    .*\n|\n)*?        php: \[\'(\d+\.\d+)\'/m', $workflow, $matrix))->toBe(1);
        expect($matrix[1] ?? null)->toBe($minimum);
    }
});
