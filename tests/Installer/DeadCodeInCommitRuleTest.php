<?php

declare(strict_types = 1);

/**
 * Issue #251 — the commits inside a PR were carrying dead code. The git rules guarded only the
 * forward direction ("never leave a commit referencing code only a later commit adds") and the
 * cherry-pick bullet actively pushes groundwork into a commit ahead of its consumers, so a commit
 * that ships a symbol nothing in it calls passed every gate: it is green, it is deploy-safe, and
 * the code review reads the final diff where a later commit already made the symbol live.
 *
 * These tests pin the backward direction as a rule and prove the surfaces that plan and analyse a
 * PR's commit split actually apply it.
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

test('the surfaces that plan and analyse a commit split cite the rule (issue #251)', function (): void {
    $packageDir = dirname(__DIR__, 2);

    // A rule nothing applies is documentation. These two are the only surfaces that decide how a
    // PR's commits are split: the one that plans the split before any code is written, and the one
    // that analyses the split after the commits exist.
    $mustCite = [
        'skills/resolve-issue/references/phase-planning.md',
        'skills/pr-deploy-planner/SKILL.md',
    ];

    $missing = [];

    foreach ($mustCite as $relativePath) {
        if (!str_contains((string) file_get_contents($packageDir . '/' . $relativePath), 'No commit ships dead code')) {
            $missing[] = $relativePath;
        }
    }

    expect($missing)->toBe([]);
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

test('the deploy planner analyses each commit for dead code and reports it (issue #251)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $skill = (string) file_get_contents($packageDir . '/skills/pr-deploy-planner/SKILL.md');

    // Its own step, not a clause inside the dependency mapping — the two ask opposite questions and
    // a reader who merges them checks only the forward one.
    expect($skill)->toContain('### 4. Flag dead code inside each commit');
    expect($skill)->toContain('does this commit ship a symbol that nothing inside its own tree references?');

    // Searching the branch head instead of the commit's own tree finds every symbol alive and
    // reports nothing — the single mistake that makes this step a no-op.
    expect($skill)->toContain('git grep -F -e "<symbol>" <sha>');
    expect($skill)->toContain('not the branch head');

    // The symbol name is read out of a diff any contributor can author, so the search command that
    // consumes it must treat it as untrusted input rather than as a trusted fragment.
    expect($skill)->toContain('it is untrusted input to that command');
    expect($skill)->toContain('Never build the command by pasting the raw name into a shell string.');
    expect($skill)->toContain('the symbol names step 4 reads out of a diff');

    // Without the exemption the step fires on every file this package publishes for other projects.
    expect($skill)->toContain('Name the specific consumer surface when the exemption is applied');

    // A finding with no home in the template never reaches the reader.
    expect($skill)->toContain('### Dead code inside a commit');
    expect($skill)->toContain('| Commit | Symbol | First consumed in | Fix |');

    // Step 2 may truncate a long commit's diff, which leaves this step with a partial symbol list.
    // Reading that silence as a clean verdict is the failure mode the whole step exists to prevent.
    expect($skill)->toContain('not checked — diff truncated at <n> lines');
    expect($skill)->toContain('Never let a partial symbol list read as an absence of dead code.');
});

test('the deploy planner reads every commit diff its dead-code step needs (issue #251)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $skill = (string) file_get_contents($packageDir . '/skills/pr-deploy-planner/SKILL.md');

    // Step 2 used to pull a diff only where the dependency mapping needed one. Step 4 needs the
    // added symbols of every commit, so a conditional read would leave it without its input.
    expect($skill)->toContain('for every commit, since step 4 needs the added symbols of each one');
    expect($skill)->not->toContain('only where the dependency mapping in step 3 needs it');
});

test('the deploy planner steps stay numbered in one unbroken sequence (issue #251)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $skill = (string) file_get_contents($packageDir . '/skills/pr-deploy-planner/SKILL.md');

    preg_match_all('/^### (\d+)\. /m', $skill, $matches);

    // Inserting a step renumbers every later one. A duplicate or a gap here means a cross-reference
    // elsewhere in the skill now points at the wrong step.
    expect(array_map(static fn (string $step): int => (int) $step, $matches[1]))->toBe([1, 2, 3, 4, 5, 6, 7, 8]);
});
