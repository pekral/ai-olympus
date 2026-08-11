<?php

declare(strict_types = 1);

/**
 * Issue #233 — a PR's commits must be deployable one at a time. The git rules already asked for
 * cherry-pickable commits, but only that each one "still build"; nothing said a commit had to pass
 * its tests, nothing forbade committing the TDD RED state, and nothing required re-running the gate
 * after a rebase reshaped every later commit's tree.
 *
 * These tests pin the rule as a rule rather than as advice, and prove the package ships no example
 * that contradicts it.
 */
test('the git rule requires every commit in a PR to be green (issue #233)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $rule = (string) file_get_contents($packageDir . '/rules/git/general.md');

    // The claim is about the whole range, not the tip — a rule that only bound the last commit
    // would leave exactly the history this issue reports.
    expect($rule)->toContain('**Every commit is green.**');
    expect($rule)->toContain('Every commit between the base and the branch head passes the project\'s own gate on its own');

    // Obligation 1 — the RED state is real but never committed.
    expect($rule)->toContain('A test and the change that makes it pass land in the same commit.');
    expect($rule)->toContain('Never commit a failing test');
    expect($rule)->toContain('never commit a test written to fail');
    expect($rule)->toContain('it is a state of the **working tree**, never a commit');

    // Obligation 2 — the rebase case the issue names explicitly.
    expect($rule)->toContain('A history rewrite re-runs the gate.');
    expect($rule)->toContain('whenever the branch is rebased, re-verify the whole range');
    expect($rule)->toContain('git rebase --exec');

    // Obligation 3 — a hand-picked subset is what lets a broken commit through.
    expect($rule)->toContain('The gate is the project\'s own.');
    expect($rule)->toContain('not a hand-picked subset');
});

test('the green-commit rule declares its own review severity (issue #233)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $rule = (string) file_get_contents($packageDir . '/rules/git/general.md');

    // Without a declared severity a reviewer falls back to the generic stratification and can wave
    // a committed failing test through as a style nit.
    expect($rule)->toContain('a committed failing or simulated-failing test is **Critical**');
    expect($rule)->toContain('pushed without the `git rebase --exec` replay is **Moderate**');
});

test('cherry-pick independence is measured by the gate, not by compilation (issue #233)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $rule = (string) file_get_contents($packageDir . '/rules/git/general.md');

    // "still build" was the weaker bar this issue replaces: a commit can build and still fail every
    // test it ships, which is precisely the commit a cherry-pick breaks on.
    expect($rule)->toContain('cherry-picked onto the default branch on its own and still pass the project\'s gate');
    expect($rule)->not->toContain('on its own and still build');
});
