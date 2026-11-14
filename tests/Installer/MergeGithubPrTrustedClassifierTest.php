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
        ->and($section)->toContain(
            '`<main>/.claude/skills/_shared/classify-risk.sh` on Claude Code, `<main>/.agents/skills/_shared/classify-risk.sh` on Codex',
        )
        ->and($section)->toContain('with `<main>` the parent of `git rev-parse --path-format=absolute --git-common-dir`')
        ->and($section)->toContain('Never run `skills/_shared/classify-risk.sh` of the checked-out branch, of the gated head, or of a review worktree.')
        ->and($section)->toContain('`domain-vocabulary.sh` and `read-manifest.sh`')
        ->and($section)->toContain('`git show origin/<default branch>:skills/_shared/<file>` when the default branch ships `skills/_shared/<file>`')
        ->and($section)->toContain('`git show origin/<default branch>:<installed path>` when the default branch tracks the installed path')
        ->and($section)->toContain('no default-branch blob to compare with at either path')
        ->and($section)->toContain('A project whose installed copy is not tracked on the default branch therefore takes the full path')
        ->and($section)->toContain('The `FAST`-tier exemption is then not qualified, the low-risk path is not available');
});

test('the FAST-tier exemption re-runs the trusted classifier', function (): void {
    $exemptions = trustedClassifierSource('skills/merge-github-pr/references/merge-exemptions.md');
    $fastSection = installerDocsSection($exemptions, '## `FAST`-tier PR exemption');

    expect($fastSection)->toContain('1. **Re-run the trusted classifier over the PR\'s own file list.**')
        ->and($fastSection)->toContain('(*The trusted classifier* below)')
        ->and($fastSection)->not->toContain('pipe it into `skills/_shared/classify-risk.sh --files -`');
});
