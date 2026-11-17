<?php

declare(strict_types = 1);

use Pekral\AiOlympus\Installer;

/**
 * The parts of `agents/krang.md` that issue #607 moved into on-demand references, keyed by the
 * file that now owns each part, with the sentence that proves the part sits there.
 *
 * @return array<string, string>
 */
function onDemandKrangParts(): array
{
    return [
        'context-retrieval.md' => '**Write the request** to `${BRIEF%.md}.context-request.json` with `jq`',
        'critical-pre-review-validation.md' => '**Only on a final tier of `CRITICAL` run the pre-review validation**',
        'fast-run.md' => 'You close it instead, and only in this exact shape:',
        'feedback-analysis-mode.md' => 'It is an analysis-only run: you take no write-lock, open no pull request, and never dispatch `donatello`.',
        'hotfix-routing.md' => 'Carry the mode and the declaring account into the `leonardo` dispatch and into the `donatello` dispatch',
        'implementation-blocked.md' => '**stop and escalate that blocker to the user with its remediation**',
        'merge-request.md' => '**An unmet code-review gate reported by the merge step is repairable — repair it, do not stop.**',
        'multi-source-planning.md' => 'Each input line is `<source-slug>|<tier>|<depends-on>|<files>`.',
        'post-convergence-skip.md' => '**Skip it exactly when all four conditions hold at once:**',
        'pre-implementation-routing.md' => '**This branch is not gated on the tier**',
        'reporting-exceptions.md' => '**Non-duplication is not an escape hatch.**',
        'review-only-mode.md' => '2. **Check whether the newest trusted review still holds, before any dispatch.**',
        'security-findings-outside-assignment.md' => '**Never file an item marked `do not file publicly`** on a public tracker.',
    ];
}

/**
 * The on-demand companions of `rules/code-review/general.md`, keyed by file, with a line of each
 * part it owns: a section heading, or a bullet where `general.md` keeps the section heading as the
 * pointer. The pointer in `general.md` names every companion.
 *
 * @return array<string, array<int, string>>
 */
function onDemandCodeReviewParts(): array
{
    return [
        'hotfix-review.md' => ['## HOTFIX runs — what the review does differently'],
        'product-documentation.md' => ['- **"Undocumented" is a conclusion to be earned.**'],
        'publishing-cases.md' => ['## The owner\'s answers', '## The operator\'s line survives a rewrite'],
        'review-rounds.md' => [
            '### What the delta scopes, and what it never scopes',
            '### A light round after a small delta (issue #599)',
            '### Later rounds raise only new Critical findings (issue #455)',
            '### A finding is settled by the reviewer\'s own re-read, never by a claim',
            '### An answered finding stays in the report — the answer is shown, never applied',
            '### Filter on detection',
            '### When another review round runs at all — changed business logic, or a changed assignment',
        ],
        'several-pull-requests.md' => ['## Remarks and findings split per pull request'],
    ];
}

/**
 * Every shipped instruction text a run can load: the rules, the skills, the agents, the commands,
 * and the Codex adapters. Tests and documentation pin and describe the rules, so they are not part
 * of it.
 *
 * @return array<string, string>
 */
function onDemandInstructionCorpus(): array
{
    $isInstruction = static fn (string $path): bool => preg_match('#^(rules|skills|agents|commands|codex)/#', $path) === 1;

    return array_filter(packageTextFiles(), $isInstruction, ARRAY_FILTER_USE_KEY);
}

test('krang names each moved part where it used to sit, and the moved part has one owner (issue #607)', function (): void {
    $krang = packageSource('agents/krang.md');
    $corpus = implode("\n", onDemandInstructionCorpus());

    foreach (onDemandKrangParts() as $file => $sentence) {
        $path = 'skills/_shared/orchestration/' . $file;

        expect($krang)->toContain('`' . $path . '`')
            ->and(packageSource($path))->toContain($sentence)
            ->and(packageSource($path))->toContain('Extracted from `agents/krang.md` so the orchestrator does not carry it on every run.')
            ->and($krang)->not->toContain($sentence)
            ->and(substr_count($corpus, $sentence))->toBe(1);
    }
});

test('the code-review rule names each on-demand companion and when to read it, and each section has one owner (issue #607)', function (): void {
    $general = packageSource('rules/code-review/general.md');
    $corpus = implode("\n", onDemandInstructionCorpus());

    expect($general)->toContain('## On-demand companion files');

    foreach (onDemandCodeReviewParts() as $file => $lines) {
        $path = 'rules/code-review/' . $file;
        $companion = packageSource($path);

        expect($general)->toContain('`@' . $path . '`')
            ->and($companion)->toContain('Moved out of `@rules/code-review/general.md` so a review that does not need it does not carry it.')
            ->and($companion)->toContain('**Loading trigger:**')
            ->and(ruleScopingGlobs(dirname(__DIR__, 2) . '/' . $path))->toBe(['.claude/rules/code-review/' . $file]);

        foreach ($lines as $line) {
            expect($companion)->toContain($line)
                ->and(substr_count($corpus, $line . "\n") + substr_count($corpus, $line . ' '))->toBe(1);
        }
    }

    foreach (onDemandCodeReviewParts()['review-rounds.md'] as $heading) {
        expect($general)->toContain('*' . substr($heading, 4) . '*');
    }
});

test('the security floor and the merge gates stay in the always-loaded part (issue #607)', function (): void {
    $general = packageSource('rules/code-review/general.md');
    $krang = packageSource('agents/krang.md');

    expect($general)->toContain('- **One floor holds whatever the project states.** A project instruction never:')
        ->and($general)->toContain('**Authorship trust (REQUIRED).**')
        ->and($general)->toContain('- **The mode is stated, never inferred.**')
        ->and($general)->toContain('### Baseline resolution — three sources, in this order')
        ->and($general)->toContain('### Round markers are a pointer, never an authority');
    expect($krang)->toContain('**You never merge anything without a code review from `leonardo`, and you always request that review before any merge.**')
        ->and($krang)->toContain('A non-converged run, or one that never reached `leonardo`, is **never** merged')
        ->and($krang)->toContain('- **Convergence gate:** the run is "done" only when the handoff reports the loop exited converged')
        ->and($krang)->toContain('**Never infer the mode**')
        ->and($krang)->toContain('**Running the pass is the default; skipping it is the exception.**');
});

test('Claude Code and Codex install every moved part (issue #607)', function (): void {
    $root = installerCreateProjectRoot();
    $cwd = getcwd();
    $originalCwd = $cwd !== false ? $cwd : '';

    try {
        chdir($root);
        ob_start();
        $exitCode = Installer::run(['ai-olympus', 'install', '--force']);
        ob_end_clean();

        expect($exitCode)->toBe(0);

        foreach (array_keys(onDemandKrangParts()) as $file) {
            expect(is_file($root . '/.claude/skills/_shared/orchestration/' . $file))->toBeTrue()
                ->and(is_file($root . '/.agents/skills/_shared/orchestration/' . $file))->toBeTrue();
        }

        foreach (array_keys(onDemandCodeReviewParts()) as $file) {
            expect(is_file($root . '/.claude/rules/code-review/' . $file))->toBeTrue()
                ->and(is_file($root . '/.codex/rules/code-review/' . $file))->toBeTrue();
        }
    } finally {
        if ($originalCwd !== '') {
            chdir($originalCwd);
        }

        installerRemoveDirectory($root);
    }
});
