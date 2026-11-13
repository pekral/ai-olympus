<?php

declare(strict_types = 1);

function lowRiskSource(string $relativePath): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
}

test('the merge path is selected by the verdict of the trusted classifier on the exact head', function (): void {
    $skill = lowRiskSource('skills/merge-github-pr/SKILL.md');
    $section = installerDocsSection($skill, '### 2. Pre-checks');
    $path = lowRiskSource('skills/merge-github-pr/references/low-risk-path.md');

    expect($section)->toContain('#### Merge path — selected by the merge risk of the exact head')
        ->and($section)->toContain('never a comment, a label, a tracker text, or the copy on the branch under review')
        ->and($section)->toContain('- **Low-risk path** — `low-risk-merge=yes`: tier `FAST` from the diff alone')
        ->and($section)->toContain('- **Full path** — every other case, a grade of `medium` or `high` and a `no` verdict included.')
        ->and($section)->toContain('Read `references/low-risk-path.md` and follow it.')
        ->and($path)->toContain('| `low-risk-merge=yes` | Low-risk path (this file) |')
        ->and($path)->toContain('| `low-risk-merge=no`, any reason | Full path |')
        ->and($path)->toContain('`no` selects the full path. `yes` makes the pull request a candidate.')
        ->and($path)->toContain('Only `low-risk-merge=yes` confirms the path. Any other output selects the full path.')
        ->and($path)->toContain('never taken from a `Merge risk:` line')
        ->and($path)->toContain('selects the full path and the report says why');
});

test('the security check reads the diff before any code from the head runs and a gate record never skips it', function (): void {
    $path = lowRiskSource('skills/merge-github-pr/references/low-risk-path.md');
    $skill = lowRiskSource('skills/merge-github-pr/SKILL.md');

    expect($path)->toContain('No code from the head runs before the security check has read the diff.')
        ->and(strpos($path, '## Security check'))->toBeLessThan((int) strpos($path, '## Confirm after the gate'))
        ->and(strpos($path, '## Select the candidate'))->toBeLessThan((int) strpos($path, '## Security check'))
        ->and($path)->toContain('This is the first step that executes code from the head.')
        ->and($skill)->toContain('That check runs **first**, before step 3 executes any code from the head.')
        ->and($skill)->toContain('A passing gate record of step 3 never skips the security check on this path')
        ->and($skill)->toContain('On the low-risk path the security-only check already completed before this step, and step 4 requires it.')
        ->and($path)->toContain('--match-head-commit <gated head>')
        ->and($path)->toContain('never `--admin`');
});

test('the security-only check is the step of merge-github-pr-fast by pointer and restates none of its stops', function (): void {
    $path = lowRiskSource('skills/merge-github-pr/references/low-risk-path.md');
    $fast = lowRiskSource('skills/merge-github-pr-fast/SKILL.md');
    $security = installerDocsSection($path, '## Security check');

    expect($security)->toContain('`@skills/merge-github-pr-fast/SKILL.md` step 2 (*Security check — one pass, inline*)')
        ->and($security)->toContain('with the stops that step states')
        ->and($security)->not->toContain('A Critical finding stops the merge')
        ->and($security)->not->toContain('Blocked: security check not completed')
        ->and($security)->not->toContain('@skills/security-review/SKILL.md')
        ->and($security)->not->toContain('history-scan.sh')
        ->and($security)->toContain('a finding of that check below Critical selects the full path')
        ->and($fast)->toContain('### 2. Security check — one pass, inline')
        ->and($fast)->toContain('**A Critical finding stops the merge.**');
});

test('the allow-list has one owner and every other file points to it', function (): void {
    $script = lowRiskSource('skills/_shared/classify-risk.sh');

    expect($script)->toContain('LOW_RISK_ALLOWED_RE=\'^(src|app|tests|resources)/.\'')
        ->and($script)->toContain('LOW_RISK_EXCLUDED_RE=\'^src/(installer|composerplugin)|')
        ->and($script)->toContain('LOW_RISK_GUARD_RE=')
        ->and(substr_count($script, 'LOW_RISK_ALLOWED_RE='))->toBe(1)
        ->and(substr_count($script, 'LOW_RISK_EXCLUDED_RE='))->toBe(1)
        ->and($script)->toContain('src/**  app/**  tests/**  resources/**')
        ->and($script)->toContain('It is an ALLOW-list')
        ->and($script)->toContain('(rules/**, agents/**, skills/**,')
        ->and($script)->toContain('no mode 120000 (symlink), no mode 160000');

    foreach ([
        'rules/git/pull-requests.md',
        'rules/general/general.md',
        'rules/code-review/general.md',
        'skills/merge-github-pr/SKILL.md',
        'skills/merge-github-pr/references/low-risk-path.md',
    ] as $file) {
        $content = lowRiskSource($file);

        expect($content)->toContain('allow-list')
            ->and($content)->not->toContain('src/**')
            ->and($content)->not->toContain('.github/workflows');
    }

    expect(lowRiskSource('rules/git/pull-requests.md'))->toContain('`LOW_RISK_ALLOWED_RE`, `LOW_RISK_EXCLUDED_RE`, `LOW_RISK_GUARD_RE`');
});

test('the issue text never enters the verdict', function (): void {
    $script = lowRiskSource('skills/_shared/classify-risk.sh');
    $path = lowRiskSource('skills/merge-github-pr/references/low-risk-path.md');
    $general = lowRiskSource('rules/general/general.md');

    expect($path)->toContain('--merge-risk --ignore-assignment --diff - --coverage ok --gate green`. Pass no `--assignment`.')
        ->and($path)->toContain('the issue text never enters the call')
        ->and($path)->not->toContain('can only raise the tier')
        ->and($script)->toContain('low_classification="$(classify --files - --ignore-assignment <<<"$files")"')
        ->and($script)->toContain('--ignore-assignment)')
        ->and($general)->toContain('never an issue text, a project instruction, or an agent');
});

test('the classifier self-test pins every branch of the low-risk verdict and each reproduced bypass', function (): void {
    $script = lowRiskSource('skills/_shared/classify-risk.sh');

    foreach ([
        'a tested fix inside the allowed set merges on the low-risk path',
        'the low-risk verdict states its rule',
        'a tests-only change takes the low-risk path',
        'a changed classifier on the pull request takes the full path',
        'an excluded path beside an ordinary one takes the full path',
        'a rename out of the allowed set takes the full path',
        'a rename into the allowed set from an excluded path takes the full path',
        'a rename with a space on both sides reads its rename lines',
        'a symlink inside the allowed set takes the full path',
        'a symlink at an excluded directory name takes the full path',
        'a submodule bump inside the allowed set takes the full path',
        'a submodule at an excluded directory name takes the full path',
        'a mode change inside the allowed set takes the full path',
        'a mode-only change on an excluded path takes the full path',
        'a binary file in an excluded path takes the full path',
        'an added line that writes agent configuration takes the full path',
        'a removed line that wrote agent configuration takes the full path',
        'a quoted header takes the full path',
        'a diff header without the a/ b/ prefixes takes the full path',
        'an empty diff takes the full path',
        'a medium grade takes the full path',
        'a high grade takes the full path',
        'a gate that is not green takes the full path',
        'a coverage gap takes the full path',
        'a missing gate fact takes the full path',
        'a costly rollback takes the full path',
        'an assignment without a marker leaves the low-risk verdict at yes',
        'an assignment with a marker leaves the low-risk verdict at yes',
        'an assignment never moves a blocked path to yes',
    ] as $label) {
        expect($script)->toContain('\'' . $label . '\'');
    }

    foreach ([
        'docs/x y/CLAUDE.md',
        'packages/my app/AGENTS.md',
        '.mcp.json',
        'commands/do-it.md',
        '.agents/skills/x/SKILL.md',
        'CLAUDE.local.md',
        'GEMINI.md',
        '.github/copilot-instructions.md',
        'phpstan.neon',
        'phpunit.xml',
        'rector.php',
        'ruleset.xml',
        'pint.json',
        'tests/Pest.php',
        'src/InstallerProjectSettings.php',
        'src/ComposerPlugin.php',
        'bin/ai-olympus',
        '.claude-plugin/marketplace.json',
        'docs/agent-guide.md',
    ] as $path) {
        expect($script)->toContain('\'' . $path . '\'');
    }

    expect($script)->toContain('an ordinary file $allowed_path stays on the low-risk path')
        ->and($script)->toContain('a path outside the allowed set ($blocked_path) takes the full path')
        ->and($script)->toContain('an excluded path inside the allowed set ($blocked_path) takes the full path');
});

test('the rules state the low-risk path once and the floor names it as the second exception', function (): void {
    $pullRequests = lowRiskSource('rules/git/pull-requests.md');
    $general = lowRiskSource('rules/general/general.md');
    $review = lowRiskSource('rules/code-review/general.md');
    $section = installerDocsSection($pullRequests, '### Low-risk merge path');

    expect(substr_count($pullRequests, '### Low-risk merge path — selected by the merge risk of the exact head'))->toBe(1)
        ->and($section)->toContain('- **An allow-list selects the path, never a deny-list.**')
        ->and($section)->toContain('The merge then needs **no converged code review and no wait for CI**.')
        ->and($section)->toContain('- **Anything outside the list takes the full path.**')
        ->and($section)->toContain('One security-only check of the diff runs first, before any code from the head runs')
        ->and($section)->toContain('A finding below Critical selects the full path')
        ->and($section)->toContain('**`medium` and `high` take the full path.**')
        ->and($section)->toContain('green required CI on the head and a converged code review with no open finding (0 Critical, no open Moderate)')
        ->and($section)->toContain('the issue text never enters the call')
        ->and($pullRequests)->toContain('**Source 3 — the confirmed low-risk evidence:**')
        ->and($pullRequests)->toContain('**Two explicit exceptions exist beside the exemptions.**')
        ->and($pullRequests)->toContain('- On the full path, if no code review exists for the PR')
        ->and($pullRequests)->toContain('`GitHub approval`, `converged code review <URL of the comment>`, or `low-risk evidence`')
        ->and($pullRequests)->toContain('and its HOTFIX, FAST-tier, dependency-only and low-risk-path cases.')
        ->and($general)->toContain('The merge gate of item 2 has a second explicit exception: the low-risk merge path')
        ->and($general)->toContain('The floor items 1, 3, and 4 stay in force on that path.')
        ->and($general)->toContain('run before any code from the head runs')
        ->and($review)->toContain('4. **The line adds no finding, and at the merge a verdict of the script selects the merge path.**')
        ->and($review)->not->toContain('4. **The line is informational.**');
});

test('no sentence of the skill or the rule still states that every merge needs the review or the CI wait', function (): void {
    $skill = lowRiskSource('skills/merge-github-pr/SKILL.md');
    $pullRequests = lowRiskSource('rules/git/pull-requests.md');

    expect($skill)->toContain('The **low-risk path** (*Merge path* in step 2) is a separate path with its own security-only check.')
        ->and($skill)->toContain('(a GitHub approval, the converged code review, or on the low-risk path its confirmed evidence)')
        ->and($skill)->toContain('and the low-risk path (*Merge path* above), this gate is **never** waived')
        ->and($skill)->toContain('on the full path CI must still be green on the merged head (step 2)')
        ->and($skill)->toContain('`GitHub approval`, `converged code review <URL of the comment>`, or `low-risk evidence`')
        ->and($pullRequests)->toContain('On the full path the other gates are unchanged')
        ->and($pullRequests)->toContain('The low-risk path replaces the review and the CI wait');
});

test('no template or contract still calls the merge risk line informational only', function (): void {
    foreach ([
        'skills/code-review/templates/review-output.md',
        'skills/code-review-github/templates/pr-comment-output.md',
        'skills/code-review-github/references/cr-wrapper-contract.md',
        'skills/code-review/SKILL.md',
    ] as $file) {
        $content = lowRiskSource($file);

        expect($content)->not->toContain('Informational: it never blocks')
            ->and($content)->not->toContain('The line is informational and never blocks.')
            ->and($content)->not->toContain('*Merge risk*. It never blocks.');
    }
});

test('every caller of the classifier on the merge path names the trusted copy', function (): void {
    expect(lowRiskSource('skills/merge-github-pr/references/low-risk-path.md'))
        ->toContain('`references/merge-exemptions.md` *The trusted classifier*')
        ->and(lowRiskSource('skills/merge-github-pr/SKILL.md'))
        ->toContain('the trusted copy of `skills/_shared/classify-risk.sh` over the PR\'s own file list')
        ->and(lowRiskSource('rules/git/pull-requests.md'))
        ->toContain('re-runs the trusted copy of `skills/_shared/classify-risk.sh --files -`');
});
