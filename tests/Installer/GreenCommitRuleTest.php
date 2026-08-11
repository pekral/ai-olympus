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

test('every skill that authors or reshapes branch history cites the green-commit rule (issue #233)', function (): void {
    $packageDir = dirname(__DIR__, 2);

    // A rule nothing applies is documentation. These five are the surfaces that actually create a
    // commit or move one: the two that plan the history, the cycle that produces the RED state, and
    // the two that rebase a branch and push it.
    $mustCite = [
        'skills/resolve-issue/SKILL.md',
        'skills/resolve-issue/references/phase-planning.md',
        'skills/test-driven-development/SKILL.md',
        'skills/git-workflow/SKILL.md',
        'skills/process-code-review/SKILL.md',
    ];

    $missing = [];

    foreach ($mustCite as $relativePath) {
        if (!str_contains((string) file_get_contents($packageDir . '/' . $relativePath), 'Every commit is green')) {
            $missing[] = $relativePath;
        }
    }

    expect($missing)->toBe([]);
});

test('every skill that rebases a branch replays the range before pushing (issue #233)', function (): void {
    $packageDir = dirname(__DIR__, 2);

    // The rebase case is the one the issue names. A skill that rebases and pushes without the
    // replay publishes a history whose commits were gated against a base they no longer sit on.
    $mustReplay = [
        'skills/resolve-issue/SKILL.md',
        'skills/resolve-issue/references/phase-planning.md',
        'skills/git-workflow/SKILL.md',
        'skills/process-code-review/SKILL.md',
    ];

    $missing = [];

    foreach ($mustReplay as $relativePath) {
        if (!str_contains((string) file_get_contents($packageDir . '/' . $relativePath), 'git rebase --exec')) {
            $missing[] = $relativePath;
        }
    }

    expect($missing)->toBe([]);
});

test('the TDD cycle keeps RED out of the commit history (issue #233)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $tdd = (string) file_get_contents($packageDir . '/skills/test-driven-development/SKILL.md');

    // The RED step stays mandatory — only the commit boundary moves. A reader who loses that
    // distinction either commits the red state or stops writing the failing test first.
    expect($tdd)->toContain('**RED is a state of the working tree, never a commit.**');
    expect($tdd)->toContain('commit the failing test together with the change that makes it pass');
    expect($tdd)->toContain('The cycle below is unchanged; only the commit boundary is.');
});

test('cherry-pick independence is measured by the gate, not by compilation (issue #233)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $rule = (string) file_get_contents($packageDir . '/rules/git/general.md');

    // "still build" was the weaker bar this issue replaces: a commit can build and still fail every
    // test it ships, which is precisely the commit a cherry-pick breaks on.
    expect($rule)->toContain('cherry-picked onto the default branch on its own and still pass the project\'s gate');
    expect($rule)->not->toContain('on its own and still build');
});
