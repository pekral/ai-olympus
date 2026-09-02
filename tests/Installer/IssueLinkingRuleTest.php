<?php

declare(strict_types = 1);

/**
 * `rules/git/general.md` *Issue Linking* used to read "always link it in commits", which is the
 * wrong surface: GitHub derives `closingIssuesReferences` from the pull request body, and every
 * skill in this package reads `closingIssues[]` off the PR long before a merge lands the commit.
 * PR #43 proved the second half of the same defect — a translated keyword parses as nothing.
 *
 * These tests pin the corrected mechanic and its single owner: the general invariant lives in
 * `rules/compound-engineering/general.md`, and this section implements it for GitHub only.
 */
test('the GitHub issue-linking mechanic keys off the PR body, not only the commit (issue #43 lesson)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $rule = (string) file_get_contents($packageDir . '/rules/git/general.md');

    // The old conditional, commit-only wording is gone — it is what left the PR unlinked.
    expect($rule)->not->toContain('If a GitHub issue is provided, always link it in commits');

    // The body is the surface GitHub actually parses.
    expect($rule)->toContain('**Write the literal `Closes #<N>` into the pull request body.**');
    expect($rule)->toContain('GitHub derives `closingIssuesReferences`');
    expect($rule)->toContain('A reference that lives only in a commit message leaves the pull request unlinked');

    // The commit reference stays, as the additive half that closes the issue on a rebase merge.
    expect($rule)->toContain('**Keep the reference in the commit too.**');
    expect($rule)->toContain('It never substitutes for the reference in the pull request body.');

    // Apply-then-verify, through the deterministic loader, exactly as every other tracker write.
    expect($rule)->toContain('**Verify the link landed.**');
    expect($rule)->toContain('skills/code-review-github/scripts/load-issue.sh <PR-URL>');
    expect($rule)->toContain('`gh pr create` exiting zero is not evidence');
});

test('the closing keyword is pinned as English and named where the language rule states it (issue #43)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $rule = (string) file_get_contents($packageDir . '/rules/git/general.md');

    // A translated keyword parses as nothing, so the exemption has to be stated, not assumed.
    expect($rule)->toContain('**The keyword is English, always.**');
    expect($rule)->toContain('A translated keyword is not parsed');
    expect($rule)->toContain('exempt from the assignment-language rule for the PR body');

    // The mandate it excepts names the exception inline, so a reader of that line sees it.
    expect($rule)->toContain(
        'PR description must be written in the same language as the assignment. '
        . 'The literal `Closes #<N>` keyword is the one exception',
    );
});

test('the git rule implements the general linking invariant instead of restating it', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $rule = (string) file_get_contents($packageDir . '/rules/git/general.md');

    // One owner for the principle, one owner for the GitHub mechanic — the split the two
    // sibling tracker-write sections already use.
    expect($rule)->toContain(
        'This section is the GitHub mechanic for `@rules/compound-engineering/general.md` '
        . '*Every pull request links back to its tracker issue*.',
    );
    expect($rule)->toContain('That section owns the invariant across every tracker; this one owns how GitHub carries it.');

    // The general invariant's own wording must not be duplicated here.
    expect($rule)->not->toContain('**The tracker gains a pointer back to the pull request.**');
});
