<?php

declare(strict_types = 1);

/**
 * Issue #251 — the commits inside a PR were carrying dead code. The git rules guarded only the
 * forward direction ("never leave a commit referencing code only a later commit adds") and the
 * cherry-pick bullet actively pushes groundwork into a commit ahead of its consumers, so a commit
 * that ships a symbol nothing in it calls passed every gate: it is green, it is deploy-safe, and
 * the code review reads the final diff where a later commit already made the symbol live.
 *
 * These tests pin the backward direction as a rule and prove the surface that plans a PR's commit
 * split actually applies it.
 */
test('the git rule forbids a commit that ships code nothing in it uses (issue #251)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $rule = (string) file_get_contents($packageDir . '/rules/git/general.md');

    // The claim is about the tree the commit produces, not about the merged result — a rule phrased
    // against the final diff would describe a state no commit in the range is ever measured in.
    expect($rule)->toContain('**No commit ships dead code.**');
    expect($rule)->toContain('Every symbol a commit adds is referenced inside the tree that commit produces');

    // Obligation 1 — the groundwork sentence in the bullet above is what produces the defect.
    expect($rule)->toContain('Move the groundwork, do not split it off.');
    expect($rule)->toContain('**two or more** later points consume it');

    // Obligation 2 — without this, the rule is satisfiable by naming the symbol in a test.
    expect($rule)->toContain('A test counts as a consumer, but only a real one.');
    expect($rule)->toContain('the same defect wearing a test\'s clothes');

    // Obligation 3 — the deletion direction leaves a dead symbol just as reliably.
    expect($rule)->toContain('A removal leaves dead code too.');

    // Obligation 4 — without the exemption the rule fires on every file this package publishes.
    expect($rule)->toContain('Reachable-from-outside code is not dead.');
    expect($rule)->toContain('Cite the specific consumer surface when claiming this exemption.');
});

test('the dead-code-in-commit rule declares its severity and its gating (issue #251)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $rule = (string) file_get_contents($packageDir . '/rules/git/general.md');

    // A history defect, not a defect of the merged result — so it never blocks on correctness.
    expect($rule)->toContain('a defect of the history, not of the merged result');

    // Without the carve-out this finding and the pre-existing Minor dead-code nit both fire on one
    // symbol, and a reviewer reports the same line twice at two severities.
    expect($rule)->toContain('**Gating — never both with the Minor dead-code nit.**');
    expect($rule)->toContain('dead **at the commit that introduces it**');
    expect($rule)->toContain('The same symbol is never reported under both.');
});

test('the surface that plans a commit split cites the rule (issue #251)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $planning = (string) file_get_contents($packageDir . '/skills/resolve-issue/references/phase-planning.md');

    // A rule nothing applies is documentation. This is the surface that decides how a PR's commits
    // are split, and it decides it before any code is written.
    expect($planning)->toContain('No commit ships dead code');
});

test('commit planning folds single-consumer groundwork into its consumer (issue #251)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $planning = (string) file_get_contents($packageDir . '/skills/resolve-issue/references/phase-planning.md');

    // The bullet above this one orders groundwork ahead of its consumers, which is what produced the
    // reported defect. Planning has to read that ordering in both directions.
    expect($planning)->toContain('Groundwork with a single consumer is not groundwork');
    expect($planning)->toContain('**two or more** later points consume it');

    // Planning alone is advice; the pre-PR reconciliation is where the range is actually checked.
    expect($planning)->toContain('Check the same range for dead code');
});
