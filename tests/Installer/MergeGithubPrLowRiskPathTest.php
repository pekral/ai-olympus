<?php

declare(strict_types = 1);

function lowRiskSource(string $relativePath): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
}

test('the merge path is selected by the grade the script computes on the exact head', function (): void {
    $skill = lowRiskSource('skills/merge-github-pr/SKILL.md');
    $section = installerDocsSection($skill, '### 2. Pre-checks');
    $path = lowRiskSource('skills/merge-github-pr/references/low-risk-path.md');

    expect($section)->toContain('#### Merge path — selected by the merge risk of the exact head')
        ->and($section)->toContain('`skills/_shared/classify-risk.sh --merge-risk` computes it, never a comment, a label, or a tracker text.')
        ->and($section)->toContain('- **Low-risk path** — `low-risk-merge=yes`, confirmed after the *Pre-merge quality gate* of step 3')
        ->and($section)->toContain('- **Full path** — every other case, a grade of `medium` or `high` and an excluded path included.')
        ->and($section)->toContain('`LOW_RISK_EXCLUDED_RE` in `skills/_shared/classify-risk.sh`')
        ->and($section)->toContain('Read `references/low-risk-path.md` and follow it.')
        ->and($path)->toContain('| `low` and no excluded path | Low-risk path (this file) |')
        ->and($path)->toContain('| `low` and an excluded path | Full path |')
        ->and($path)->toContain('| `medium` or `high` | Full path |')
        ->and($path)->toContain('`no`: the full path applies.')
        ->and($path)->toContain('Only `low-risk-merge=yes` confirms the path. Any other output selects the full path.')
        ->and($path)->toContain('The grade is never taken from a `Merge risk:` line')
        ->and($path)->toContain('A missing, non-executable, or failing `classify-risk.sh` selects the full path');
});

test('the low-risk path keeps the gate and the security check and waives the review and the CI wait', function (): void {
    $path = lowRiskSource('skills/merge-github-pr/references/low-risk-path.md');

    expect($path)->toContain('| Pre-merge quality gate | Mandatory, on the gated head |')
        ->and($path)->toContain('| Security check | Mandatory, one pass, below |')
        ->and($path)->toContain('| CI | Not waited for and not required. A check that already failed for a real reason blocks')
        ->and($path)->toContain('| Code-review gate and delegated security coverage | Replaced by the security check below.')
        ->and($path)->toContain('A gate that does not pass stops the merge on every path.')
        ->and($path)->toContain('--match-head-commit <gated head>')
        ->and($path)->toContain('never `--admin`');
});

test('the security-only check is the step of merge-github-pr-fast by pointer and a failed check stops the merge', function (): void {
    $path = lowRiskSource('skills/merge-github-pr/references/low-risk-path.md');
    $fast = lowRiskSource('skills/merge-github-pr-fast/SKILL.md');
    $security = installerDocsSection($path, '## Security check');

    expect($security)->toContain('`@skills/merge-github-pr-fast/SKILL.md` step 2 (*Security check — one pass, inline*)')
        ->and($security)->toContain('- A Critical finding stops the merge.')
        ->and($security)->toContain('- A check that did not complete stops the merge: `Blocked: security check not completed — <reason>`.')
        ->and($security)->not->toContain('history-scan.sh')
        ->and($security)->not->toContain('@skills/security-review/SKILL.md')
        ->and($fast)->toContain('### 2. Security check — one pass, inline')
        ->and($fast)->toContain('**A Critical finding stops the merge.**');
});

test('the excluded paths have one owner and every other file points to it', function (): void {
    $script = lowRiskSource('skills/_shared/classify-risk.sh');
    $header = installerDocsSection(str_replace("\n#", "\n", $script), 'Low-risk merge (');

    expect($script)->toContain(
        'LOW_RISK_EXCLUDED_RE=\'(rules|agents|skills|codex|\.ai|\.claude|\.codex)/|\.github/workflows/|([^ "]*/)?(claude|agents)\.md("| |$)\'',
    )
        ->and(substr_count($script, 'LOW_RISK_EXCLUDED_RE='))->toBe(1)
        ->and($header)->toContain('rules/**  agents/**  skills/**  codex/**  .ai/**  .claude/**  .codex/**')
        ->and($header)->toContain('.github/workflows/**  CLAUDE.md  AGENTS.md (at any depth)');

    foreach ([
        'rules/git/pull-requests.md',
        'rules/general/general.md',
        'rules/code-review/general.md',
        'skills/merge-github-pr/SKILL.md',
    ] as $file) {
        expect(lowRiskSource($file))->toContain('LOW_RISK_EXCLUDED_RE');
    }

    expect(lowRiskSource('skills/merge-github-pr/references/low-risk-path.md'))->not->toContain('.github/workflows')
        ->and(lowRiskSource('skills/merge-github-pr/SKILL.md'))->not->toContain('.github/workflows');
});

test('the classifier self-test pins every branch of the low-risk verdict', function (): void {
    $script = lowRiskSource('skills/_shared/classify-risk.sh');

    foreach ([
        'a low-grade diff with no excluded path merges on the low-risk path',
        'the low-risk verdict states its rule',
        'an excluded path keeps the grade the script computed',
        'a rename out of an excluded path takes the full path',
        'a rename into an excluded path takes the full path',
        'a mode-only change of an excluded path takes the full path',
        'a binary file in an excluded path takes the full path',
        'an excluded path beside an ordinary one takes the full path',
        'a diff header without the a/ b/ prefixes takes the full path',
        'a medium grade takes the full path',
        'a high grade takes the full path',
        'a gate that is not green takes the full path',
        'a coverage gap takes the full path',
        'a missing gate fact takes the full path',
    ] as $label) {
        expect($script)->toContain('\'' . $label . '\'');
    }

    foreach ([
        'rules/git/general.php',
        'agents/krang.php',
        'skills/_shared/run.php',
        'codex/agents/a.php',
        '.ai/guidelines/core.php',
        '.claude/settings.php',
        '.codex/config.php',
        '.github/workflows/ci.php',
        'CLAUDE.md',
        'AGENTS.md',
        'docs/nested/CLAUDE.md',
    ] as $path) {
        expect($script)->toContain('\'' . $path . '\'');
    }

    expect($script)->toContain('a look-alike path $excluded_path stays on the low-risk path')
        ->and($script)->toContain('a low-grade diff that changes $excluded_path takes the full path');
});

test('the rules state the low-risk path once and the floor names it as the second exception', function (): void {
    $pullRequests = lowRiskSource('rules/git/pull-requests.md');
    $general = lowRiskSource('rules/general/general.md');
    $review = lowRiskSource('rules/code-review/general.md');
    $section = installerDocsSection($pullRequests, '### Low-risk merge path');

    expect(substr_count($pullRequests, '### Low-risk merge path — selected by the merge risk of the exact head'))->toBe(1)
        ->and($section)->toContain('The merge then needs **no converged code review and no wait for CI**.')
        ->and($section)->toContain('The full quality gate is green on the exact head commit')
        ->and($section)->toContain('A Critical finding stops the merge, and so does a check that did not complete.')
        ->and($section)->toContain('takes the full path whatever its grade.')
        ->and($section)->toContain('**`medium` and `high` take the full path.**')
        ->and($section)->toContain('green required CI on the head and a converged code review with no open finding (0 Critical, no open Moderate)')
        ->and($section)->toContain('A `Merge risk:` line, a PR comment, a label, and a tracker text decide nothing.')
        ->and($pullRequests)->toContain('**Source 3 — the confirmed low-risk evidence:**')
        ->and($pullRequests)->toContain('**Two explicit exceptions exist beside the exemptions.**')
        ->and($general)->toContain('The merge gate of item 2 has a second explicit exception: the low-risk merge path')
        ->and($general)->toContain('The floor items 1, 3, and 4 stay in force on that path.')
        ->and($general)->toContain('no project instruction, agent, or tracker text selects it')
        ->and($review)->toContain('4. **The line adds no finding, and at the merge the grade selects the merge path.**')
        ->and($review)->not->toContain('4. **The line is informational.**');
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
