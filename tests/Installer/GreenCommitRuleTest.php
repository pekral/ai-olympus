<?php

declare(strict_types = 1);

/**
 * Issue #233 established that a PR's commits must be deployable one at a time, gated per commit.
 * That guarantee was later traded away deliberately: proving every commit green costs one full
 * build per commit, which dominates the delivery time of a larger task for a guarantee almost
 * nothing consumes. The gate now runs once, on the head commit being merged.
 *
 * These tests pin what survived that trade — the obligations that were never about gate placement
 * (no committed failing test, the project's own gate, a reshaped branch never inheriting a verdict)
 * — and pin that the trade-off itself is stated rather than silently dropped.
 */
test('the git rule gates the merged head and states what deferring the gate trades away (issue #233, revised)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $rule = (string) file_get_contents($packageDir . '/rules/git/general.md');

    // The gate is pinned to the one commit that actually ships, and to the skill that runs it.
    expect($rule)->toContain('**The merged head is green; intermediate commits are not gated.**');
    expect($rule)->toContain('once, on the exact head commit being merged');
    expect($rule)->toContain('*Pre-merge quality gate*');

    // The cost of the trade must be stated, not discovered later by whoever runs git bisect.
    expect($rule)->toContain('What this trades away, stated plainly:');
    expect($rule)->toContain('git bisect');
    expect($rule)->toContain('Cherry-pick *independence* — disjoint file sets, groundwork ordered before its consumers — is unaffected');

    // Obligation 1 — the RED state is real but never committed.
    expect($rule)->toContain('A test and the change that makes it pass land in the same commit.');
    expect($rule)->toContain('Never commit a failing test');
    expect($rule)->toContain('never commit a test written to fail');
    expect($rule)->toContain('it is a state of the **working tree**, never a commit');

    // Obligation 2 — a reshaped branch never inherits the verdict of the history it replaced.
    expect($rule)->toContain('A history rewrite re-runs the gate.');
    expect($rule)->toContain('the pre-merge gate runs again on the new head');
    expect($rule)->toContain('never inherits an earlier run\'s verdict');
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
    expect($rule)->toContain('A merge performed without a passing gate run on the merged head commit is **Critical**');

    // The inverse must be explicit: an ungated intermediate commit is now correct, not a finding.
    expect($rule)->toContain('Intermediate commits that do not individually pass the gate are **not** a finding');
});

test('every skill that authors or reshapes branch history cites the merged-head rule (issue #233, revised)', function (): void {
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
        if (!str_contains((string) file_get_contents($packageDir . '/' . $relativePath), 'The merged head is green; intermediate commits are not gated')) {
            $missing[] = $relativePath;
        }
    }

    expect($missing)->toBe([]);
});

test('no skill mandates the per-commit range replay any more (issue #233, revised)', function (): void {
    $packageDir = dirname(__DIR__, 2);

    // The replay runs the whole gate once per commit — exactly the cost the deferral removes. It
    // stays available for a branch that genuinely wants a bisectable history, so each of these may
    // still mention it, but none may present it as required before a push.
    //
    // Pin the sentence each file actually gained, not a bare substring: an earlier version of this
    // test asserted the word "available", which `unavailable` already satisfies in two of the four
    // files, so it would have passed against the fully reverted change.
    $mustNotMandateReplay = [
        'skills/resolve-issue/SKILL.md' => 'that replay is available, not required',
        'skills/resolve-issue/references/phase-planning.md' => 'remains available when a bisectable history is wanted, and is not required by default',
        'skills/git-workflow/SKILL.md' => 'is available when a bisectable history is wanted',
        'skills/process-code-review/SKILL.md' => 'no range replay is required here',
    ];

    // The wordings the replay-mandating versions carried; none may survive anywhere.
    $mandatingWordings = [
        'Step 3 is not optional',
        'requires the replay before a reshaped branch is pushed',
        'requires the check before a reshaped branch is published',
        'whenever the branch is rebased, re-verify the whole range',
    ];

    $violations = [];

    foreach ($mustNotMandateReplay as $relativePath => $optionalWording) {
        $body = (string) file_get_contents($packageDir . '/' . $relativePath);

        if (!str_contains($body, $optionalWording)) {
            $violations[] = $relativePath . ': does not state the replay is optional';
        }

        foreach ($mandatingWordings as $mandating) {
            if (str_contains($body, $mandating)) {
                $violations[] = $relativePath . ': still mandates the replay — "' . $mandating . '"';
            }
        }
    }

    expect($violations)->toBe([]);
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

    // Independence survives the deferral; only the per-commit green guarantee is gone. The rule
    // must say so explicitly, or a reader concludes the ordering discipline was dropped too.
    expect($rule)->toContain('cherry-picked onto the default branch on its own and still pass the project\'s gate');
    expect($rule)->not->toContain('on its own and still build');
    expect($rule)->toContain('only the per-commit green guarantee is gone');
});
