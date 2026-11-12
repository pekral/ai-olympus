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
        ->and($rule)->toContain('A deferred Moderate does not withhold it.')
        ->and($rule)->not->toContain('A Minor finding and a deferred Moderate')
        ->and($rule)->toContain('an untrusted comment, a stale comment, and a PR body claim never meet it')
        ->and($rule)->toContain('**The merge report names the source:** `GitHub approval` or `converged code review <URL of the comment>`.');
});

test('a changes-requested review decision still blocks and a branch protection is never lifted', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $rule = (string) file_get_contents($packageDir . '/rules/git/pull-requests.md');
    $skill = (string) file_get_contents($packageDir . '/skills/merge-github-pr/SKILL.md');

    expect($rule)->toContain('It also blocks when the latest review of an author in `reviews[]` has `state == "CHANGES_REQUESTED"`')
        ->and($rule)->toContain('an `authorAssociation` of `OWNER`, `MEMBER`, or `COLLABORATOR`')
        ->and($skill)->toContain('a latest `CHANGES_REQUESTED` review of a trusted author in `reviews[]`')
        ->and($rule)->toContain('**`reviewDecision == "CHANGES_REQUESTED"` always blocks.**')
        ->and($rule)->toContain('The request of a human reviewer is never overridden by the agent\'s own review')
        ->and($rule)->toContain('**A GitHub branch protection is never lifted.**')
        ->and($rule)->toContain('the run stops and reports the refusal. It never uses `--admin`')
        ->and($rule)->toContain(
            'The other gates are unchanged: converged code review, pre-merge quality gate, CI, no conflicts, not a Draft, branch up to date.',
        )
        ->and($skill)->toContain('`CHANGES_REQUESTED` blocks, and a GitHub refusal stops the run — never `--admin`')
        ->and($skill)->toContain('A `CHANGES_REQUESTED` decision, or a latest `CHANGES_REQUESTED` review');
});

test('the skills reference the rule and no longer demand a GitHub approval on its own', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $skill = (string) file_get_contents($packageDir . '/skills/merge-github-pr/SKILL.md');
    $readiness = (string) file_get_contents($packageDir . '/skills/verify-merge-readiness/SKILL.md');
    $fast = (string) file_get_contents($packageDir . '/skills/merge-github-pr-fast/SKILL.md');
    $exemptions = (string) file_get_contents($packageDir . '/skills/merge-github-pr/references/merge-exemptions.md');
    $orchestration = (string) file_get_contents($packageDir . '/rules/compound-engineering/orchestration.md');

    expect($skill)->toContain('- **The approval check is met** — by `reviewDecision == "APPROVED"` or, on a same-repository PR')
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

test('an exemption from the code-review gate is not an exemption from the approval check', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $rule = (string) file_get_contents($packageDir . '/rules/git/pull-requests.md');
    $skill = (string) file_get_contents($packageDir . '/skills/merge-github-pr/SKILL.md');
    $exemptions = (string) file_get_contents($packageDir . '/skills/merge-github-pr/references/merge-exemptions.md');

    expect($rule)->toContain('**An exemption from the code-review gate is not an exemption from the approval check.**')
        ->and($rule)->toContain('meets the check only through Source 2')
        ->and($rule)->toContain('verified with the test of the code-review gate above for the current head')
        ->and($rule)->toContain('A same-repository `FAST`-tier or dependency-only pull request without')
        ->and($rule)->toContain('A fork exempt pull request needs Source 1.')
        ->and($skill)->toContain('On a same-repository exempt PR without a GitHub approval')
        ->and($skill)->toContain('report the unmet check as "approval needs a converged code review"')
        ->and($skill)->toContain('report "approval needs a GitHub approval (fork PR)"')
        ->and(substr_count($exemptions, 'an exemption from the code-review gate is not an exemption from the approval check'))->toBe(2);
});

test('a converged review approves only a same-repository pull request', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $rule = (string) file_get_contents($packageDir . '/rules/git/pull-requests.md');
    $skill = (string) file_get_contents($packageDir . '/skills/merge-github-pr/SKILL.md');
    $readiness = (string) file_get_contents($packageDir . '/skills/verify-merge-readiness/SKILL.md');

    expect($rule)->toContain(
        'A head branch in the repository needs push access, which a fork pull request does not have, so the rule never covers a pull request from a fork.',
    )
        ->and($rule)->not->toContain('The rationale is that self-approval')
        ->and($rule)->toContain('counts as the approval of a same-repository pull request when it has no blocking finding')
        ->and($rule)->toContain('A missing or `null` `isCrossRepository` counts as a cross-repository pull request (fail closed).')
        ->and($skill)->toContain('A missing or `null` `isCrossRepository` counts as a fork PR (fail closed)')
        ->and($skill)->toContain('Read `isDraft`, `isCrossRepository`, `mergeable`, `mergeStateStatus`, `reviewDecision`, `reviews[]`')
        ->and($rule)->toContain('Source 2 applies only to a same-repository pull request (`isCrossRepository == false`).')
        ->and($rule)->toContain('A cross-repository (fork) pull request needs Source 1.')
        ->and($skill)->toContain('on a same-repository PR (`isCrossRepository == false`)')
        ->and($skill)->toContain('A fork PR needs `reviewDecision == "APPROVED"`.**')
        ->and($readiness)->toContain('A fork PR needs a GitHub approval');
});
