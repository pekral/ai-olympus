<?php

declare(strict_types = 1);

test('code-testing rule bans tautological assertions and names every detectable form', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/rules/code-testing/general.md');

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

test('the acceptance-criteria test contract binds criteria, simplest code, exact tests and full coverage', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $rule = (string) file_get_contents($packageDir . '/rules/code-testing/general.md');
    $review = (string) file_get_contents($packageDir . '/rules/code-review/review-process.md');
    $donatello = (string) file_get_contents($packageDir . '/agents/donatello.md');

    expect($rule)->toContain('## Acceptance-Criteria Test Contract')
        ->and($rule)->toContain('**Every criterion has a test.**')
        ->and($rule)->toContain('**Every test proves a criterion.**')
        ->and($rule)->toContain('**Coverage comes from those tests.**')
        ->and($rule)->toContain('code that the criteria do not need — delete the code.')
        ->and($rule)->toContain('**The code is the simplest design that meets every criterion.**')
        ->and($rule)->toContain('a criterion → test table')
        ->and($review)->toContain('**Every test the diff adds proves a criterion — the reverse direction.**')
        ->and($review)->toContain('is a **Moderate** finding. Typical cases:')
        ->and($review)->toContain('`@rules/code-testing/general.md` *Acceptance-Criteria Test Contract*')
        ->and($donatello)->toContain('Apply `@rules/code-testing/general.md` *Acceptance-Criteria Test Contract*');
});
