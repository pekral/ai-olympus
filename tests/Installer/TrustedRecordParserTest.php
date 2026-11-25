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
            . 'skills/_shared/cr-header.sh skills/_shared/review-trust.jq skills/_shared/review-state.sh '
            . 'skills/_shared/state-path.sh skills/_shared/finding-defs.jq`.',
        )
        ->and($owner)->toContain(
            'the verdict `installed-copy-verdict=verified` are `"<clone>/skills/code-review-github/scripts/load-issue.sh" "<PR-URL>" > "<loader-file>"'
            . ' && "<clone>/skills/_shared/cr-header.sh" --report "<loader-file>"'
            . ' && "<clone>/skills/_shared/review-state.sh" known-check --loader "<loader-file>"`',
        )
        ->and($owner)->not->toContain('--report "<loader-file>";')
        ->and($owner)->not->toContain('GIT_CONFIG_COUNT=2')
        ->and($owner)->toContain(
            '`.claude/skills/code-review-github/scripts/load-issue.sh .claude/skills/_shared/cr-header.sh '
            . '.claude/skills/_shared/review-trust.jq .claude/skills/_shared/review-state.sh '
            . '.claude/skills/_shared/state-path.sh .claude/skills/_shared/finding-defs.jq '
            . '.claude/skills/_shared/verify-installed-copy.sh`',
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

test('the merge gate confirms the repository ownership only with the verified copy of the ownership check', function (): void {
    $owner = trustedRecordParserSource('skills/merge-github-pr/references/trusted-record-parser.md');
    $section = installerDocsSection($owner, '## The trusted ownership check');
    $guard = '`skills/_shared/assert-current-repo.sh';

    expect($section)->toContain('The copy of the checkout never stands in for it.')
        ->and($section)->toContain('1. The path the verifier checks is `skills/_shared/assert-current-repo.sh`.')
        ->and($section)->toContain(
            '`( cd "<checkout>" && "<clone>/skills/_shared/assert-current-repo.sh" "<URL>" )`',
        )
        ->and($section)->toContain('No verified copy proves no ownership: handle it like exit `5` and stop.');

    foreach (['skills/merge-github-pr/SKILL.md', 'skills/merge-github-pr-fast/SKILL.md'] as $file) {
        expect(trustedRecordParserSource($file))->toContain('before any clone');
    }

    foreach ([
        'skills/merge-github-pr/SKILL.md',
        'skills/merge-github-pr-fast/SKILL.md',
        'skills/verify-merge-readiness/SKILL.md',
    ] as $file) {
        $content = trustedRecordParserSource($file);

        expect($content)->toContain('*The trusted ownership check*')
            ->and(substr_count($content, $guard))->toBeGreaterThan(0)
            ->and(substr_count($content, $guard))->toBe(substr_count($content, 'the trusted copy of ' . $guard));
    }
});

test('the ownership check compares the URL with the remotes of the checkout before it creates any clone', function (): void {
    $owner = trustedRecordParserSource('skills/merge-github-pr/references/trusted-record-parser.md');
    $section = installerDocsSection($owner, '## The trusted ownership check');
    $compare = strpos($section, '1. **Compare with the remotes of the checkout first, before any clone.**');
    $clone = strpos($section, '2. **Create the clone only after item 1 matched.**');
    $verified = strpos($section, '3. **Confirm with the verified copy.**');

    expect($compare)->toBeInt()
        ->and($clone)->toBeInt()
        ->and($verified)->toBeInt()
        ->and($compare < $clone)->toBeTrue()
        ->and($clone < $verified)->toBeTrue()
        ->and($section)->toContain('`git -C "<checkout>" remote get-url --all "<remote>"`')
        ->and($section)->toContain('`git -C "<checkout>" remote get-url --push --all "<remote>"`')
        ->and($section)->toContain('No URL matches: the pull request belongs to another repository. Stop as exit `4`.')
        ->and($section)->toContain('or a `git` command that failed: the ownership is not resolved. Stop as exit `5`.')
        ->and($section)->toContain('Run no script of the repository, and no code of any other repository, in this item.')
        ->and($section)->toContain('Never create a clone for a pair that item 1 did not match.');
});

test('the remote comparison strips the userinfo of every URL and never quotes a raw URL', function (): void {
    $owner = trustedRecordParserSource('skills/merge-github-pr/references/trusted-record-parser.md');
    $section = installerDocsSection($owner, '## The trusted ownership check');
    $read = strpos($section, '2. List the remotes of `<checkout>`');
    $compare = strpos($section, '3. Count a URL of item 2');

    expect($read)->toBeInt()
        ->and($compare)->toBeInt()
        ->and($section)->toContain('so no raw URL reaches the context of the run')
        ->and($section)->toContain('set -o pipefail; <the git command> 2>/dev/null | sed -E')
        ->and($section)->toContain('\'s#^([A-Za-z][A-Za-z0-9+.-]*://)?[^/]*@#\\1#\'')
        ->and($section)->toContain('The output keeps the scheme and drops the userinfo')
        ->and($section)->toContain(
            'A raw remote URL is never quoted in a report, a note, or a handoff, in the report of an exit `4` or `5` stop included.',
        )
        ->and($read < $compare)->toBeTrue();
});

test('the remote comparison counts an ssh host alias that resolves to github.com, as the ownership script does', function (): void {
    $owner = trustedRecordParserSource('skills/merge-github-pr/references/trusted-record-parser.md');
    $section = installerDocsSection($owner, '## The trusted ownership check');
    $script = trustedRecordParserSource('skills/_shared/assert-current-repo.sh');

    expect($section)->toContain('Use `git` only, and the one `ssh -G` of item 3.')
        ->and($section)->toContain('or an ssh-style URL whose host `ssh -G` resolves to `github.com`')
        ->and($section)->toContain('`ssh -G -- "<host>" 2>/dev/null | awk \'$1 == "hostname" { print tolower($2); exit }\'`')
        ->and($section)->toContain('`^[A-Za-z0-9][A-Za-z0-9._-]*$`, so it never starts with a dash')
        ->and($section)->toContain('The host is counted only when the output is exactly `github.com`.')
        ->and($section)->toContain('opens no connection. A refused or failed `ssh -G`')
        ->and($section)->toContain('No remote, no counted URL, or a `git` command that failed: the ownership is not resolved.')
        ->and($script)->toContain('ssh -G -- "$host"');
});

test('the fast merge loads the pull request and checks the gate record only with the verified copies', function (): void {
    $owner = trustedRecordParserSource('skills/merge-github-pr/references/trusted-record-parser.md');
    $section = installerDocsSection($owner, '## The fast merge');
    $skill = trustedRecordParserSource('skills/merge-github-pr-fast/SKILL.md');
    $steps = installerDocsSection($skill, '### 1. Resolve the pull request')
        . installerDocsSection($skill, '### 2. Security check — one pass, inline')
        . installerDocsSection($skill, '### 3. The gate');
    $loader = '`skills/code-review-github/scripts/load-issue.sh';
    $verifier = '`skills/_shared/verify-gate.sh';

    expect($section)->toContain('the path the verifier checks is `skills/code-review-github/scripts/load-issue.sh`')
        ->and($section)->toContain(
            '`"<clone>/skills/code-review-github/scripts/load-issue.sh" "<PR-URL>" > "<loader-file>"`',
        )
        ->and($section)->toContain('No verified copy, no document: the run stops and merges nothing.')
        ->and($owner)->toContain('the *Fallback gate* of `@skills/merge-github-pr-fast/SKILL.md` run it the same way')
        ->and($steps)->toContain('(`@skills/merge-github-pr/references/trusted-record-parser.md` *The fast merge*)')
        ->and($steps)->toContain(
            '(`@skills/merge-github-pr/references/trusted-record-parser.md` *The trusted gate-record check*)',
        )
        ->and($steps)->toContain('BASE_REF="$(jq -r \'.baseRefName\' "<loader-file>")"')
        ->and($steps)->not->toContain('skills/code-review-github/scripts/load-issue.sh <URL> | jq')
        ->and(substr_count($steps, $loader))->toBe(substr_count($steps, 'the trusted copy of ' . $loader))
        ->and(substr_count($steps, $verifier))->toBe(substr_count($steps, 'the trusted copy of ' . $verifier))
        ->and($steps)->toContain(
            'First compare the URL with the remotes of the checkout using `git` only, before any clone exists. '
            . 'Only after a match, create the untouched clone of `@skills/merge-github-pr/references/merge-exemptions.md` '
            . '*The trusted classifier* item 6',
        )
        ->and(installerDocsSection($skill, '### 6. Merge'))
        ->toContain(
            'After the fallback gate no copy runs again: read `gh pr view <URL> --json state,mergeCommit` instead.',
        );
});

test('the HOTFIX coverage exception reads the fresh verdict that the trusted check took before the gate', function (): void {
    $owner = trustedRecordParserSource('skills/merge-github-pr/references/trusted-record-parser.md');
    $section = installerDocsSection($owner, '## The trusted gate-record check');
    $skill = trustedRecordParserSource('skills/merge-github-pr/SKILL.md');
    $step = installerDocsSection($skill, '### 3. Pre-merge quality gate');

    expect($section)->toContain('4. **The fresh verdict of a HOTFIX.**')
        ->and($section)->toContain('so no copy of `verify-gate.sh` runs after the gate')
        ->and($section)->toContain('A later head, an unavailable copy, and a `null` verdict give no exception.')
        ->and($step)->not->toContain('`verify-gate.sh`, run afterwards')
        ->and($step)->toContain(
            'the trusted check of item 1 reports `fresh_exit_code` `0` before the gate '
            . '(`references/trusted-record-parser.md` *The trusted gate-record check* item 4)',
        );
});
