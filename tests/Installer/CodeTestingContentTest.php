<?php

declare(strict_types = 1);

test('code-testing rule hands test value to test-audit, which names every tautological form', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $rule = (string) file_get_contents($packageDir . '/rules/code-testing/general.md');
    $skill = (string) file_get_contents($packageDir . '/skills/test-audit/SKILL.md');

    expect($rule)->toContain('## Test Value');
    expect($rule)->toContain('That skill is the single authority on test value');
    expect($rule)->not->toContain('## No Tautological Assertions');
    expect($skill)->toContain(
        'a literal asserted against itself, a value the test just assigned, a configured test double re-asserted, or a language or framework guarantee',
    );
    expect($skill)->toContain('**Expected value produced by the system under test**');
    expect($skill)->toContain('**Assertion-free test**');
    expect($skill)->toContain('apply the **falsifiability test**');
    expect($skill)->toContain('A junk pattern is **Moderate**; it is **Critical** when the junk test is the only test covering a line');
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
