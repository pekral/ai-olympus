<?php

declare(strict_types = 1);

function trustedRecordParserSource(string $relativePath): string
{
    $path = dirname(__DIR__, 2) . '/' . $relativePath;

    return is_file($path) ? (string) file_get_contents($path) : '';
}

test('the merge gate reads the review record only with the verified default-branch copy of its parser', function (): void {
    $owner = trustedRecordParserSource('skills/merge-github-pr/references/trusted-record-parser.md');

    expect($owner)->toStartWith('# The trusted record parser — the default-branch copy')
        ->and($owner)->toContain('This file is the one owner of the rule.')
        ->and($owner)->toContain('`references/merge-exemptions.md` *The trusted classifier* item 6')
        ->and($owner)->toContain('One clone serves the classifier, the loader, and the parser.')
        ->and($owner)->toContain('`<clone>/skills/code-review-github/scripts/load-issue.sh` and `<clone>/skills/_shared/cr-header.sh`')
        ->and($owner)->toContain('run the copies below `<clone>/.claude/skills/` on Claude Code and below `<clone>/.agents/skills/` on Codex')
        ->and($owner)->toContain('Never run a copy from the main worktree')
        ->and($owner)->toContain(
            'Run the shell call of *The trusted classifier* item 4, with its two `export` lines, its ref check, and its `TIP` check',
        )
        ->and($owner)->toContain(
            '1. The paths the verifier checks are `skills/code-review-github/scripts/load-issue.sh '
            . 'skills/_shared/cr-header.sh skills/_shared/review-trust.jq`.',
        )
        ->and($owner)->toContain(
            'the verdict `installed-copy-verdict=verified` are `"<clone>/skills/code-review-github/scripts/load-issue.sh" "<PR-URL>" > "<loader-file>"'
            . ' && "<clone>/skills/_shared/cr-header.sh" --report "<loader-file>"`',
        )
        ->and($owner)->not->toContain('GIT_CONFIG_COUNT=2')
        ->and($owner)->toContain(
            '`.claude/skills/code-review-github/scripts/load-issue.sh .claude/skills/_shared/cr-header.sh '
            . '.claude/skills/_shared/review-trust.jq .claude/skills/_shared/verify-installed-copy.sh`',
        )
        ->and($owner)->toContain('Keep the output of the parser as text in the context of the run, never in a file.')
        ->and($owner)->toContain('A loader document that another copy of the loader wrote is never parsed and never read for a merge decision.')
        ->and($owner)->toContain('A step after the gate never runs the loader or the parser again.')
        ->and($owner)->toContain('**No verified copy, no document — the merge stops.**')
        ->and($owner)->toContain('The GitHub MCP server never stands in for the trusted copy.')
        ->and($owner)->toContain('do not merge')
        ->and($owner)->not->toContain('<main>')
        ->and(trustedRecordParserSource('skills/merge-github-pr/references/merge-exemptions.md'))
        ->toContain('No command ran in it except the commands of this section and of `references/trusted-record-parser.md`');
});

test('every merge-gate reader of the review record names the trusted copy and its one owner', function (): void {
    $command = '`skills/_shared/cr-header.sh --report <loader-file>`';

    foreach ([
        'skills/merge-github-pr/SKILL.md' => 'references/trusted-record-parser.md',
        'skills/merge-github-pr/references/merge-exemptions.md' => 'references/trusted-record-parser.md',
        'skills/merge-github-pr/references/low-risk-path.md' => 'references/trusted-record-parser.md',
        'skills/verify-merge-readiness/SKILL.md' => '@skills/merge-github-pr/references/trusted-record-parser.md',
        'rules/git/pull-requests.md' => '@skills/merge-github-pr/references/trusted-record-parser.md',
    ] as $file => $pointer) {
        $content = trustedRecordParserSource($file);

        expect($content)->toContain($pointer)
            ->and(substr_count($content, $command))->toBe(substr_count($content, 'the trusted copy of ' . $command));
    }
});

test('the quality-gate record of step 3 comes from the report that step 2 read with the trusted parser', function (): void {
    $skill = trustedRecordParserSource('skills/merge-github-pr/SKILL.md');
    $step = installerDocsSection($skill, '### 3. Pre-merge quality gate');

    expect($step)->toContain('from the output that step 2 recorded with the trusted parser (`references/trusted-record-parser.md`)')
        ->and($step)->not->toContain('then `skills/_shared/cr-header.sh --report <loader-file>`');
});

test('the verifier names the trusted record parser as a caller of the installed reference', function (): void {
    $script = trustedRecordParserSource('skills/_shared/verify-installed-copy.sh');

    expect($script)->toContain('`skills/merge-github-pr/references/trusted-record-parser.md`')
        ->and($script)->not->toContain('Only the review');
});

test('the merge gate loads the pull request only with the verified copy of the loader', function (): void {
    $skill = trustedRecordParserSource('skills/merge-github-pr/SKILL.md');
    $loader = '`skills/code-review-github/scripts/load-issue.sh';
    $exemptions = trustedRecordParserSource('skills/merge-github-pr/references/merge-exemptions.md');

    expect($skill)->toContain(
        'load PR context by running the trusted copy of `skills/code-review-github/scripts/load-issue.sh <URL>` (`references/trusted-record-parser.md`)',
    )
        ->and($skill)->toContain('No trusted copy, no merge (`references/trusted-record-parser.md` item 6); the GitHub MCP server never stands in for it.')
        ->and($skill)->not->toContain('fall back to the GitHub MCP server')
        ->and(substr_count($skill, $loader))->toBe(substr_count($skill, 'the trusted copy of ' . $loader))
        ->and($exemptions)->toContain('with `<owner>` and `<name>` from the pull-request URL')
        ->and($exemptions)->not->toContain('from the deterministic loader output')
        ->and(substr_count($exemptions, 'the JSON document that the trusted copy of the loader wrote in step 1'))->toBe(2)
        ->and(substr_count($exemptions, 'the JSON document loaded in step 1'))->toBe(0)
        ->and(trustedRecordParserSource('skills/merge-github-pr/references/low-risk-path.md'))
        ->toContain('from the payload that the trusted copy of the loader wrote in step 1')
        ->and(trustedRecordParserSource('skills/verify-merge-readiness/SKILL.md'))
        ->toContain('read from the loader document that the trusted copy of the loader wrote in the same call');
});

test('the merge gate checks the gate record only with the verified copy of the record verifier', function (): void {
    $owner = trustedRecordParserSource('skills/merge-github-pr/references/trusted-record-parser.md');
    $section = installerDocsSection($owner, '## The trusted gate-record check');

    expect($section)->toContain('never the copy of the checked-out head')
        ->and($section)->toContain(
            '`skills/_shared/verify-gate.sh skills/_shared/gate-record.sh skills/_shared/project-commands.sh '
            . 'skills/_shared/read-manifest.sh skills/_shared/gate-lock.sh`',
        )
        ->and($section)->toContain(
            '`( cd "<checked-out head>" && "<clone>/skills/_shared/verify-gate.sh" --tier full "<headRefOid>" )`',
        )
        ->and($section)->toContain('No verified copy accepts no record: handle it like exit `10`')
        ->and(trustedRecordParserSource('skills/merge-github-pr/SKILL.md'))->toContain(
            'Run the trusted copy of `skills/_shared/verify-gate.sh --tier full <headRefOid>` '
            . '(`references/trusted-record-parser.md`) on the checked-out head',
        )
        ->and(trustedRecordParserSource('skills/verify-merge-readiness/SKILL.md'))->toContain(
            'run the trusted copy of `skills/_shared/verify-gate.sh --tier full <head SHA>`',
        );
});
