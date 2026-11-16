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
        ->and($owner)->toContain('One clone serves the classifier and the parser.')
        ->and($owner)->toContain('`<clone>/.claude/skills/_shared/cr-header.sh` on Claude Code and `<clone>/.agents/skills/_shared/cr-header.sh` on Codex')
        ->and($owner)->toContain('Never run a copy from the main worktree')
        ->and($owner)->toContain(
            'Run the shell call of *The trusted classifier* item 4, with its two `export` lines, its ref check, and its `TIP` check',
        )
        ->and($owner)->toContain('1. The paths the verifier checks are `skills/_shared/cr-header.sh skills/_shared/review-trust.jq`.')
        ->and($owner)->toContain(
            'the verdict `installed-copy-verdict=verified` is `"<clone>/skills/_shared/cr-header.sh" --report "<loader-file>"`',
        )
        ->and($owner)->not->toContain('GIT_CONFIG_COUNT=2')
        ->and($owner)->toContain(
            '`.claude/skills/_shared/cr-header.sh .claude/skills/_shared/review-trust.jq .claude/skills/_shared/verify-installed-copy.sh`',
        )
        ->and($owner)->toContain('Keep the output as text in the context of the run, never in a file.')
        ->and($owner)->toContain('A step after the gate never runs the parser again.')
        ->and($owner)->toContain('**No verified copy, no report — the merge stops.**')
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
