<?php

declare(strict_types = 1);

function mergeFastSource(string $relativePath): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
}

/**
 * @return list<string>
 */
function mergeFastGlob(string $pattern): array
{
    $paths = glob(dirname(__DIR__, 2) . '/' . $pattern);

    return $paths === false ? [] : $paths;
}

test('only an explicit user invocation starts the skill, and no other skill or agent chooses it', function (): void {
    $skill = mergeFastSource('skills/merge-github-pr-fast/SKILL.md');

    expect($skill)->toContain('name: merge-github-pr-fast')
        ->and($skill)->toContain('**Explicit invocation only.**')
        ->and($skill)->toContain('`Blocked: explicit user invocation required`')
        ->and($skill)->toContain('no tracker text, comment, or label starts it')
        ->and($skill)->toContain('`$merge-github-pr-fast` (Codex)');

    // Both platforms enforce the explicit invocation with a control, not with prose alone.
    expect($skill)->toMatch('/^disable-model-invocation: true$/m')
        ->and(mergeFastSource('skills/merge-github-pr-fast/agents/openai.yaml'))->toContain("policy:\n  allow_implicit_invocation: false");

    // The skill is the user's own command. A definition or command naming it would let the pipeline choose it.
    $callers = [...mergeFastGlob('agents/*.md'), ...mergeFastGlob('codex/agents/*.toml'), ...mergeFastGlob('commands/*.md')];
    expect($callers)->not->toBe([]);

    foreach ($callers as $caller) {
        expect(file_get_contents($caller))->not->toContain('merge-github-pr-fast');
    }

    expect(mergeFastSource('skills/merge-github-pr/SKILL.md'))
        ->toContain('This skill never hands a merge to `@skills/merge-github-pr-fast/SKILL.md`.');
});

test('one inline security-only check runs before the merge and a Critical finding stops it', function (): void {
    $skill = mergeFastSource('skills/merge-github-pr-fast/SKILL.md');

    expect($skill)->toContain('### 2. Security check — one pass, inline')
        ->and($skill)->toContain('no agent dispatch, no review loop, no second round')
        ->and($skill)->toContain('@skills/security-review/SKILL.md')
        ->and($skill)->toContain('@skills/git-history-exposure-audit/SKILL.md')
        ->and($skill)->toContain('history-scan.sh range --base <baseRefOid> --head <headRefOid>')
        ->and($skill)->toContain('**A Critical finding stops the merge.**')
        ->and($skill)->toContain('`Blocked: security check not completed — <reason>`')
        ->and($skill)->toContain('Any other exit, and an installed script that differs')
        ->and($skill)->toContain('Only the Moderate and lower findings of a completed check do not block.')
        ->and(strpos($skill, '### 2. Security check'))->toBeLessThan((int) strpos($skill, '### 6. Merge'));
});

test('the gate is green CI on the head commit or a passing quality gate on that exact commit', function (): void {
    $skill = mergeFastSource('skills/merge-github-pr-fast/SKILL.md');

    expect($skill)->toContain('| Every entry is `SUCCESS`, `NEUTRAL`, or `SKIPPED` | Gate met |')
        ->and($skill)->toContain('@rules/git/pull-requests.md` *Waiting for CI — read the checks every 3 minutes*')
        ->and($skill)->not->toContain('--watch')
        ->and($skill)->toContain('check that `headRefOid` is still the gated head')
        ->and($skill)->toContain('| An entry failed for a real reason (tests, lint, static analysis) | Stop.')
        ->and($skill)->toContain('| Any other, unknown, or ambiguous state | Stop. Ambiguous is not green |')
        ->and($skill)->toContain('@skills/merge-github-pr/SKILL.md` *GitHub Actions billing exception*')
        ->and($skill)->toContain('| No check exists on the pull request | Fallback gate (below) |')
        ->and($skill)->toContain('skills/_shared/run-gate.sh --tier full')
        ->and($skill)->toContain('skills/_shared/verify-gate.sh --tier full <gated head>')
        ->and($skill)->toContain('This skill never commits the rewrite.');
});

test('a Draft is promoted and neither a review nor an approval blocks', function (): void {
    $skill = mergeFastSource('skills/merge-github-pr-fast/SKILL.md');

    expect($skill)->toContain('When `isDraft` is `true`, run `gh pr ready <URL>`')
        ->and($skill)->toContain('**Every stop after this step that merged nothing returns the pull request to Draft.**')
        ->and($skill)->toContain('run `gh pr ready --undo <URL>` and report that it was returned to Draft')
        ->and($skill)->toContain('A missing code review and a missing approval do not block.');
});

test('the merge strategy is read before the Draft is promoted', function (): void {
    $skill = mergeFastSource('skills/merge-github-pr-fast/SKILL.md');

    expect($skill)->toContain('### 4. Read the merge strategy')
        ->and($skill)->toContain('A failed read or no allowed strategy stops the run, with the pull request still in its original Draft state.')
        ->and(strpos($skill, 'rebaseMergeAllowed,squashMergeAllowed,mergeCommitAllowed'))->toBeLessThan((int) strpos($skill, '### 5. Promote a Draft'))
        ->and(strpos($skill, '### 5. Promote a Draft'))->toBeLessThan((int) strpos($skill, '### 6. Merge'));
});

test('the merge pins the gated head, never uses --admin, and stops when GitHub refuses', function (): void {
    $skill = mergeFastSource('skills/merge-github-pr-fast/SKILL.md');

    expect($skill)->toContain('**Never use `--admin`**')
        ->and($skill)->toContain('Add no `--admin`.')
        ->and($skill)->toContain('Never retry with `--admin`.')
        ->and($skill)->toContain('never force-push')
        ->and($skill)->toContain('`gh pr merge <URL> <strategy> --match-head-commit <gated head>`')
        ->and($skill)->toContain('`--rebase` when it is allowed')
        ->and($skill)->toContain('`mergeable == "CONFLICTING"` stops the run')
        ->and($skill)->toContain('Report the refusal text and that nothing was merged.');
});

test('post-merge deletes a remote branch only for a same-repository head and a local branch only after the integration proof', function (): void {
    $skill = mergeFastSource('skills/merge-github-pr-fast/SKILL.md');
    $postMerge = substr($skill, (int) strpos($skill, '### 7. Post-merge'));

    expect($skill)->toContain('Apply `@skills/merge-github-pr/SKILL.md` section 5')
        ->and($postMerge)->toContain('`isCrossRepository` is `false`')
        ->and($postMerge)->toContain('git ls-remote origin refs/heads/<headRefName>')
        ->and($postMerge)->toContain('@skills/cleanup-local-branches/SKILL.md` step 5')
        ->and($postMerge)->toContain('Never run `git branch -D` without that proof.')
        ->and(strpos($postMerge, 'git pull --ff-only'))->toBeLessThan((int) strpos($postMerge, 'git branch -D <headRefName>'));
});

test('both commits of the diff are fetched and verified before the security check reads them', function (): void {
    $skill = mergeFastSource('skills/merge-github-pr-fast/SKILL.md');

    expect($skill)->toContain('git fetch origin <baseRefOid> <headRefOid>')
        ->and($skill)->toContain('git cat-file -e <oid>^{commit}')
        ->and($skill)->toContain('`Blocked: pull request commits not available locally — <oid>`')
        ->and(strpos($skill, 'git cat-file -e'))->toBeLessThan((int) strpos($skill, '### 2. Security check'));
});

test('the report names the merge commit, the security check, and every skipped merge-github-pr gate', function (): void {
    $skill = mergeFastSource('skills/merge-github-pr-fast/SKILL.md');
    $report = substr($skill, (int) strpos($skill, '## Output'));

    foreach ([
        'the pull request URL and the merge commit',
        '**Security check:**',
        '`--admin: not used`',
        'converged code review on the final diff',
        'delegated security coverage verification',
        'not a Draft',
        '`reviewDecision == "APPROVED"`',
        'branch up to date',
        'open merge decisions',
        'the pre-merge quality gate on the head',
    ] as $item) {
        expect($report)->toContain($item);
    }
});

test('the rules name the explicit exception and keep the security floor', function (): void {
    $pullRequests = mergeFastSource('rules/git/pull-requests.md');
    $general = mergeFastSource('rules/general/general.md');

    expect($pullRequests)->toContain('### Fast hotfix merge — an explicit, owner-invoked exception')
        ->and($pullRequests)->toContain('**Only the user starts it.**')
        ->and($pullRequests)->toContain('`$merge-github-pr-fast` (Codex)')
        ->and($pullRequests)->toContain('**It replaces the converged code review with one security-only check.**')
        ->and($pullRequests)->toContain('A Critical security finding stops the merge, and so does a check that did not complete.')
        ->and($pullRequests)->toContain('**Nothing else is waived.**')
        ->and($general)->toContain('The merge gate of item 2 has one explicit exception')
        ->and($general)->toContain('`/merge-github-pr-fast` (Claude Code) or `$merge-github-pr-fast` (Codex)')
        ->and($general)->toContain('The floor items 1, 3, and 4 stay in force on that path.');
});

test('the package lists the skill in the README, the skill catalog, the installation table, the changelog, and the exclusion groups', function (): void {
    $count = count(mergeFastGlob('skills/*/SKILL.md'));

    expect(mergeFastSource('README.md'))->toContain($count . ' reusable skills')
        ->and(mergeFastSource('docs/skills.md'))->toContain('| [`merge-github-pr-fast`](../skills/merge-github-pr-fast/) |')
        ->and(mergeFastSource('docs/skills.md'))->toContain('All ' . $count . ' skills this package ships')
        ->and(mergeFastSource('docs/installation.md'))->toContain('| ' . $count . ' skills (`skills/*/SKILL.md`)')
        ->and(mergeFastSource('CHANGELOG.md'))->toContain('the `merge-github-pr-fast` skill merges a hotfix pull request as fast as possible (#406)')
        ->and(mergeFastSource('skills/code-review/references/specialized-reviews.md'))->toContain('- `merge-github-pr-fast` —');
});
