<?php

declare(strict_types = 1);

/**
 * Issue #181 — a job-dispatch assertion proves the caller dispatched the job, nothing more. A
 * closure passed to `Queue::assertPushed()` turns it into a payload assertion bound to the job's
 * own properties, so a rename inside the job breaks a test that was never about its internals.
 *
 * These tests pin the rule as a rule rather than as advice, pin the CR walk that makes it
 * enforceable, and prove no example this package ships contradicts it.
 */
test('the testing rule forbids a payload closure on a job-dispatch assertion (issue #181)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $rule = (string) file_get_contents($packageDir . '/rules/code-testing/general.mdc');

    // The rule states the prohibition, the reason, and the place the payload belongs instead —
    // dropping any of the three turns it back into a style preference a reviewer can wave through.
    expect($rule)->toContain('Assert the dispatch, never the payload');
    expect($rule)->toContain('`Queue::assertPushed()` takes the job class and nothing else');
    expect($rule)->toContain('Never pass a closure');
    expect($rule)->toContain('belongs in the job\'s own test');

    // The sibling assertions accept the same closure, so naming only `Queue::assertPushed` would
    // leave the rule defeatable by spelling the assertion through another facade.
    $siblings = [
        'Queue::assertNotPushed()',
        'Queue::assertPushedOn()',
        'Bus::assertDispatched()',
        'Bus::assertNotDispatched()',
        'Bus::assertDispatchedSync()',
    ];

    foreach ($siblings as $sibling) {
        expect($rule)->toContain($sibling);
    }

    // The integer-count carve-out keeps the rule from banning an assertion that is still a fact
    // about the dispatch rather than about the job's contents.
    expect($rule)->toContain('`Queue::assertPushed(ProcessPayment::class, 2)`');
    expect($rule)->toContain('stays allowed');
});

test('the code-review rule carries the walk that makes the job-assertion rule enforceable (issue #181)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $rule = (string) file_get_contents($packageDir . '/rules/code-review/general.mdc');

    expect($rule)->toContain('Job-dispatch assertions carry no payload closure (issue #181)');
    expect($rule)->toContain('An integer second argument is not a finding');
    expect($rule)->toContain('Severity: **Moderate**');

    // Both Suggested Fix templates are literal so `process-code-review` can extract them without
    // re-deriving the fix; a vague "drop the closure" would break that contract.
    expect($rule)->toContain('**Drop the closure** —');
    expect($rule)->toContain('**Move the payload check** —');
});

test('no example this package ships passes a closure to a job-dispatch assertion (issue #181)', function (): void {
    // These three quote the forbidden form in order to forbid or record it: the two rule files
    // state the prohibition, and the changelog entry explains it. Everything else in the package
    // is a place where the pattern would be an example to copy.
    $quotesToForbid = ['rules/code-testing/general.mdc', 'rules/code-review/general.mdc', 'CHANGELOG.md'];
    $assertions = ['assertPushed', 'assertNotPushed', 'assertPushedOn', 'assertDispatched', 'assertNotDispatched', 'assertDispatchedSync'];
    $violations = [];

    foreach (packageTextFiles() as $relativePath => $contents) {
        if (in_array($relativePath, $quotesToForbid, strict: true)) {
            continue;
        }

        foreach ($assertions as $assertion) {
            if (preg_match('/' . $assertion . '\([^)]*,\s*(?:static\s+)?(?:fn|function)\s*\(/', $contents) === 1) {
                $violations[] = $relativePath . ': ' . $assertion;
            }
        }
    }

    expect($violations)->toBe([]);
});
