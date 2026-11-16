<?php

declare(strict_types = 1);

// CI must check every PHP version the package allows. The development tools need a newer PHP than
// `require.php` in composer.json, so the quality matrix starts above the minimum. The syntax lint
// job covers the versions below it, and the installer matrix covers all of them.

/**
 * @return list<string>
 */
function prWorkflowMatrix(string $workflow, string $job): array
{
    expect(preg_match('/^  ' . $job . ':\n(?:    .*\n|\n)*?        php: \[([^\]]+)\]/m', $workflow, $matrix))->toBe(1);

    return array_map(static fn (string $version): string => trim($version, ' \''), explode(',', $matrix[1] ?? ''));
}

test('the CI matrices cover every PHP version from the composer.json minimum', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $composer = (string) file_get_contents($packageDir . '/composer.json');
    $workflow = (string) file_get_contents($packageDir . '/.github/workflows/pr.yml');

    expect(preg_match('/"require": \{\s*"php": "\^(\d+\.\d+)"/', $composer, $constraint))->toBe(1);
    $minimum = $constraint[1] ?? null;

    $lint = prWorkflowMatrix($workflow, 'php-compatibility');
    $quality = prWorkflowMatrix($workflow, 'quality-checks');
    $installer = prWorkflowMatrix($workflow, 'installer-compatibility');

    expect($lint[0])->toBe($minimum);
    expect($installer)->toBe([...$lint, ...$quality]);
    expect(prWorkflowMatrix($workflow, 'quality-checks-result'))->toBe($quality);
});

test('the syntax lint job covers every tracked PHP file, the skill scripts and the CLI included', function (): void {
    $workflow = (string) file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/pr.yml');

    expect($workflow)->toContain('git ls-files -z -- \'*.php\' \'bin/*\' | xargs -0 -n 1 php');
});
