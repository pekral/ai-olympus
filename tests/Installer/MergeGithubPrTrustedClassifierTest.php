<?php

declare(strict_types = 1);

function trustedClassifierSource(string $relativePath): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
}

test('the merge gate runs the classifier from the default branch, never from the branch under review', function (): void {
    $exemptions = trustedClassifierSource('skills/merge-github-pr/references/merge-exemptions.md');
    $section = installerDocsSection($exemptions, '## The trusted classifier — the default-branch copy');

    expect($section)->toContain('Every call of `classify-risk.sh` in this skill runs the trusted copy, never the copy of the branch under review')
        ->and($section)->toContain('1. Create the untouched clone of item 6 before any other command of the run uses the repository.')
        ->and($section)->not->toContain('`git fetch origin <default branch>`')
        ->and($section)->toContain('`<clone>/skills/_shared/classify-risk.sh` when the default branch ships `skills/`')
        ->and($section)->toContain(
            '`<clone>/.claude/skills/_shared/classify-risk.sh` on Claude Code or `<clone>/.agents/skills/_shared/classify-risk.sh` on Codex',
        )
        ->and($section)->toContain(
            'Never run a copy from the main worktree of the project, from the checked-out branch, from the gated head, or from a review worktree.',
        )
        ->and($section)->toContain('`domain-vocabulary.sh` and `read-manifest.sh`')
        ->and($section)->toContain('`git symbolic-ref --quiet refs/remotes/origin/HEAD` prints the fully qualified `refs/remotes/origin/<default branch>`')
        ->and($section)->toContain('Accept the output only when it matches `refs/remotes/origin/?*`')
        ->and($section)->toContain('any other output and a failed command leave the ref empty, and an empty ref is a failed resolution')
        ->and($section)->toContain('Never compare with the short `origin/<default branch>`')
        ->and($section)->toContain(
            '`skills/_shared/<file>` when the default branch ships it, otherwise the installed path when the default branch tracks it',
        )
        ->and($section)->not->toContain('`git show origin/<default branch>:')
        ->and($section)->toContain('no default-branch blob to compare with at either path')
        ->and($section)->toContain('A project whose default branch neither ships nor tracks the copy therefore takes the full path')
        ->and($section)->toContain('The `FAST`-tier exemption is then not qualified, the low-risk path is not available');
});

test('the FAST-tier exemption re-runs the trusted classifier', function (): void {
    $exemptions = trustedClassifierSource('skills/merge-github-pr/references/merge-exemptions.md');
    $fastSection = installerDocsSection($exemptions, '## `FAST`-tier PR exemption');

    expect($fastSection)->toContain('1. **Re-run the trusted classifier over the PR\'s own file list.**')
        ->and($fastSection)->toContain('(*The trusted classifier* below)')
        ->and($fastSection)->not->toContain('pipe it into `skills/_shared/classify-risk.sh --files -`');
});
