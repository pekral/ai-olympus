<?php

declare(strict_types = 1);

/**
 * A pull request of a one-owner repository is opened under the owner's account, and GitHub does not
 * let an author approve their own pull request. The owner decided on 2026-11-12 that a converged
 * code review with no findings counts as the approval (issue #420). The tests below pin the rule in
 * its one owner, the block that a human reviewer keeps, and the boundary against a branch protection.
 */
test('the merging rule states once that a converged review counts as the approval', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $rule = (string) file_get_contents($packageDir . '/rules/git/pull-requests.md');

    expect($rule)->toContain('**The approval check is met by a GitHub approval or by the converged code review itself.**')
        ->and($rule)->toContain('**Source 1 — a GitHub approval:** `reviewDecision == "APPROVED"`.')
        ->and($rule)->toContain('**Source 2 — the converged code-review comment:**')
        ->and($rule)->toContain('reports `Critical 0` and no open Moderate')
        ->and($rule)->toContain('A Minor finding and a deferred Moderate do not withhold it.')
        ->and($rule)->toContain('an untrusted comment, a stale comment, and a PR body claim never meet it')
        ->and($rule)->toContain('**The merge report names the source:** `GitHub approval` or `converged code review <URL of the comment>`.');
});

test('a changes-requested review decision still blocks and a branch protection is never lifted', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $rule = (string) file_get_contents($packageDir . '/rules/git/pull-requests.md');
    $skill = (string) file_get_contents($packageDir . '/skills/merge-github-pr/SKILL.md');

    expect($rule)->toContain('**`reviewDecision == "CHANGES_REQUESTED"` always blocks.**')
        ->and($rule)->toContain('The request of a human reviewer is never overridden by the agent\'s own review')
        ->and($rule)->toContain('**A GitHub branch protection is never lifted.**')
        ->and($rule)->toContain('the run stops and reports the refusal. It never uses `--admin`')
        ->and($rule)->toContain(
            'The other gates are unchanged: converged code review, pre-merge quality gate, CI, no conflicts, not a Draft, branch up to date.',
        )
        ->and($skill)->toContain('`CHANGES_REQUESTED` blocks, and a GitHub refusal stops the run — never `--admin`')
        ->and($skill)->toContain('and never with `reviewDecision == "CHANGES_REQUESTED"`');
});

test('the skills reference the rule and no longer demand a GitHub approval on its own', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $skill = (string) file_get_contents($packageDir . '/skills/merge-github-pr/SKILL.md');
    $readiness = (string) file_get_contents($packageDir . '/skills/verify-merge-readiness/SKILL.md');
    $fast = (string) file_get_contents($packageDir . '/skills/merge-github-pr-fast/SKILL.md');
    $exemptions = (string) file_get_contents($packageDir . '/skills/merge-github-pr/references/merge-exemptions.md');
    $orchestration = (string) file_get_contents($packageDir . '/rules/compound-engineering/orchestration.md');

    expect($skill)->toContain('- **The approval check is met** — by `reviewDecision == "APPROVED"` or by the converged code-review comment verified above')
        ->and($skill)->toContain('`@rules/git/pull-requests.md` *Merging* owns the rule.')
        ->and($skill)->toContain('or an unmet approval check still blocks')
        ->and($skill)->not->toContain('Required approvals are present')
        ->and($skill)->not->toContain('`reviewDecision != "APPROVED"`')
        ->and($skill)->toContain('List merged PRs, each with the source that met the approval check')
        ->and($readiness)->toContain('`reviewDecision` is not `CHANGES_REQUESTED`. A GitHub approval is not required')
        ->and($fast)->toContain('the approval check of `@rules/git/pull-requests.md` *Merging*')
        ->and($exemptions)->not->toContain('required approvals')
        ->and($orchestration)->not->toContain('required approvals present');
});
