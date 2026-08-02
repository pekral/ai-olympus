<?php

declare(strict_types = 1);

test('code-testing rule bans tautological assertions and names every detectable form', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/rules/code-testing/general.mdc');

    expect($content)->toContain('## No Tautological Assertions');
    expect($content)->toContain('**No tautological assertion belongs in the codebase.**');
    expect($content)->toContain('A literal asserted against itself');
    expect($content)->toContain('A value the test itself just assigned');
    expect($content)->toContain('A configured test double re-asserted');
    expect($content)->toContain('A language or framework guarantee');
    expect($content)->toContain('An expected value computed by the code under test');
    expect($content)->toContain('No assertion at all');
    expect($content)->toContain('**The falsifiability test');
    expect($content)->toContain('CR severity: **Moderate**');
});

test('no test in this suite asserts a literal against itself', function (): void {
    $violations = [];

    foreach (codeTestingSuiteFiles() as $path => $source) {
        // A constant on the left of an equality/truthiness matcher can never fail,
        // whatever the production code does.
        preg_match_all(
            '/expect\(\s*(?:true|false|null|-?\d+(?:\.\d+)?|\'[^\']*\'|"[^"]*")\s*\)\s*->\s*(?:not->)?(?:toBe|toEqual|toBeTrue|toBeFalse|toBeNull)\b/',
            $source,
            $matches,
        );

        foreach ($matches[0] as $match) {
            $violations[] = $path . ': ' . $match;
        }
    }

    expect($violations)->toBe([]);
});

test('every test in this suite carries at least one assertion', function (): void {
    $violations = [];

    foreach (codeTestingSuiteFiles() as $path => $source) {
        foreach (codeTestingTestBlocks($source) as $name => $body) {
            if (str_contains($body, 'expect(') || str_contains($body, 'assert(')) {
                continue;
            }

            $violations[] = $path . ': ' . $name;
        }
    }

    expect($violations)->toBe([]);
});
