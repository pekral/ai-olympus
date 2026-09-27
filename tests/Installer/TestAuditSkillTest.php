<?php

declare(strict_types = 1);

function testAuditRead(string $relativePath): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
}

test('test-audit ships authoring, cr, and audit modes with the audit on explicit request only', function (): void {
    $skill = testAuditRead('skills/test-audit/SKILL.md');

    expect($skill)->toContain("name: test-audit\n");
    expect($skill)->toContain('**`authoring` (default) — the lightweight gate.**');
    expect($skill)->toContain('**`cr` (read-only lens');
    expect($skill)->toContain('**`audit` (read-only discovery, explicit request only)**');
    expect($skill)->toContain('It is never an automatic step of an issue, implementation, or review workflow.');
    expect($skill)->toContain('It never reads the whole suite.');
    expect($skill)->not->toContain('campaign');
});

test('the authoring gate asks the four questions and refuses coverage as a reason', function (): void {
    $skill = testAuditRead('skills/test-audit/SKILL.md');

    expect($skill)->toContain('1. **What does the test protect?**');
    expect($skill)->toContain('2. **Which real regression makes it fail?**');
    expect($skill)->toContain('3. **Does another test already protect it?**');
    expect($skill)->toContain('4. **Does the test need a test-only production seam?**');
    expect($skill)->toContain('The sentence *"this line would no longer be covered"* is not a regression.');
    expect($skill)->toContain('**Coverage verifies a test; it never justifies one.**');
    expect($skill)->toContain('**An uncovered changed line is a question, never an order to write a test.**');
});

test('test-audit owns the owner boundary, the regression rules, and the retention bar', function (): void {
    $skill = testAuditRead('skills/test-audit/SKILL.md');

    expect($skill)->toContain('**Every business or application contract has one primary test owner: the strongest boundary that owns the behaviour.**');
    expect($skill)->toContain('**Never add a regression test that passes before the fix.**');
    expect($skill)->toContain('**One observable regression, one primary regression test at the owner boundary.**');
    expect($skill)->toContain('**Never delete a test only because** it is slow, old, uses a mock');
    expect($skill)->toContain('**A retained test that fails on the baseline is a possible product bug.**');
});

test('test-audit names every junk pattern', function (): void {
    $skill = testAuditRead('skills/test-audit/SKILL.md');

    foreach ([
        '**Assertion-free test**',
        '**Tautology**',
        '**Expected value produced by the system under test**',
        '**Implementation-coupled mock**',
        '**Reflection on a private member**',
        '**Duplicate layer test**',
        '**Getter / setter test**',
        '**Framework behaviour test**',
        '**Test-only production code**',
        '**Coverage-only test**',
        '**False negative control**',
        '**Misleading test name**',
    ] as $pattern) {
        expect($skill)->toContain($pattern);
    }
});

test('an audit deletes nothing without the recorded evidence and history', function (): void {
    $skill = testAuditRead('skills/test-audit/SKILL.md');
    $report = testAuditRead('skills/test-audit/references/audit-report.md');

    expect($skill)->toContain('**Discovery is read-only.**');
    expect($skill)->toContain('`git log --follow -- <test file>`');
    expect($skill)->toContain('the recommendation is `KEEP` or `NEEDS INVESTIGATION`, never `DELETE`');

    foreach ([
        '- Behavior / contract actually protected:',
        '- Failure it can currently detect:',
        '- Non-test callers:',
        '- Stronger overlapping test:',
        '- Relevant history:',
        '- Focused validation command:',
        '**Recommendation:** KEEP | CONSOLIDATE | MOVE | DELETE | NEEDS INVESTIGATION',
        '- Production LOC removable:',
    ] as $field) {
        expect($report)->toContain($field);
    }
});

test('the authoring skills point to test-audit instead of restating it', function (): void {
    expect(testAuditRead('skills/create-test/SKILL.md'))->toContain('**Coverage alone is never sufficient justification for creating a test.**');
    expect(testAuditRead('skills/create-missing-tests-in-pr/SKILL.md'))->toContain('**Coverage alone is never evidence that a new test is required.**');
    expect(testAuditRead('skills/test-driven-development/SKILL.md'))->toContain(
        'One observable regression should normally have one primary regression test at the strongest owning boundary.',
    );
    expect(testAuditRead('skills/rewrite-tests-pest/SKILL.md'))->toContain('`@skills/test-audit/SKILL.md`');
    expect(testAuditRead('rules/code-testing/general.md'))->toContain('`@skills/test-audit/SKILL.md`');
    expect(testAuditRead('skills/create-test/SKILL.md'))->not->toContain('Prefer minimal tests for maximum coverage');
});

test('code review runs the test-value lens only on a diff that touches tests', function (): void {
    $lenses = testAuditRead('skills/code-review/references/specialized-reviews.md');

    expect($lenses)->toContain('**Test surface detected in the diff → the test-value lens runs.**');
    expect($lenses)->toContain('**a diff matching none of those patterns runs no test-value lens at all**');
    expect($lenses)->toContain('A review never runs the full test audit');
    expect(testAuditRead('skills/code-review/SKILL.md'))->toContain('the test-value lens `test-audit` with `MODE=cr`');
});

test('the implementer runs the diff mode and deletes tests that prove no assignment logic', function (): void {
    $skill = testAuditRead('skills/test-audit/SKILL.md');
    $donatello = testAuditRead('agents/donatello.md');
    $resolveIssue = testAuditRead('skills/resolve-issue/SKILL.md');

    expect($skill)->toContain('**`diff` (automatic, over the current diff)**');
    expect($skill)->toContain('## Diff mode');
    expect($skill)->toContain('A test the diff **adds** is deleted, or merged into the test that proves the criterion.');
    expect($skill)->toContain('A pre-existing test the diff **modifies** keeps the *Retention bar*.');
    expect($skill)->toContain('**Verify 100% coverage** of every changed production line');
    expect($donatello)->toContain('run `@skills/test-audit/SKILL.md` with `MODE=diff` over the current diff');
    expect($resolveIssue)->toContain('16. Run `@skills/test-audit/SKILL.md` with `MODE=diff` over the current diff');
});
