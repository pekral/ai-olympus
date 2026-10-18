<?php

declare(strict_types = 1);

// Wiring of the review feedback into the existing agents, and the verification of a safeguard (issue #189, phase 4 of #185).
//
// Per the project's test-isolation rule a Pest test cannot exec a real .sh, so the behavioural proof lives in the
// script's own `--self-test` (the precedent is `tests/Installer/RecurrenceAnalysisTest.php`). The pins below fix the
// contract that self-test enforces, the wiring that makes it run in the build, the roles that run each step, and the
// promises that no ordinary pipeline waits for the loop and that no agent gains a permission.

/**
 * @return array<int, string>
 */
function feedbackAgentFiles(): array
{
    $packageDir = dirname(__DIR__, 2);
    $claudeAgents = glob($packageDir . '/agents/*.md');
    $codexAgents = glob($packageDir . '/codex/agents/*.toml');

    return array_map(
        static fn (string $path): string => substr($path, strlen($packageDir) + 1),
        [...($claudeAgents !== false ? $claudeAgents : []), ...($codexAgents !== false ? $codexAgents : [])],
    );
}

function feedbackFile(string $relativePath): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
}

test('the loop script is shipped, executable, self-testing, and run by the build', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $script = $packageDir . '/skills/_shared/feedback-loop.sh';

    expect(is_file($script))->toBeTrue();
    expect(is_executable($script))->toBeTrue();

    expect((string) file_get_contents($script))
        ->toStartWith('#!/usr/bin/env bash')
        ->toContain('set -euo pipefail')
        ->toContain('if [[ "${1:-}" == \'--self-test\' ]]; then');

    /** @var array{scripts: array<string, array<int, string>>} $composer */
    $composer = json_decode((string) file_get_contents($packageDir . '/composer.json'), associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);

    expect(implode(' ', $composer['scripts']['shell-self-tests']))->toContain(' skills/_shared/feedback-loop.sh ');
});

test('the self-test covers recording, collecting, analysing, the lookup, and the refusals', function (): void {
    $script = feedbackFile('skills/_shared/feedback-loop.sh');

    expect($script)
        // The canonical repository: one spelling, one store, one record.
        ->toContain('check \'the record carries the canonical repository, whatever the URL spells\'')
        ->toContain('check \'the store of the pull request is named after the lower-cased canonical repository\'')
        ->toContain('check \'a renamed repository and a different spelling write one store and one record\'')
        ->toContain('check \'the collected record carries the canonical repository\'')
        ->toContain('run_case \'a repository that GitHub does not know is refused\' 3')
        ->toContain('check \'an unresolved repository writes no store\'')
        // The records stay data, and a round counts once.
        ->toContain('check \'instruction-like report text is stored as data and never executed\'')
        ->toContain('check \'recording the same round again leaves the store identical\'')
        ->toContain('check \'a refused round leaves the store identical\'')
        ->toContain('check \'collecting again leaves the store identical\'')
        ->toContain('check \'the directory is owner-only\'')
        // The analysis merges the stores of every pull request.
        ->toContain('check \'the same cause in two pull requests is one recurrence of the canonical repository\'')
        ->toContain('check \'the merged store holds every record once\'')
        ->toContain('check \'a second run over unchanged records reports no recurrence\'')
        ->toContain('check \'a flag reaches the analysis\'')
        ->toContain('run_case \'a malformed store is refused\' 3')
        ->toContain('check \'the work files of a run are removed\'')
        // The lookup reads every page and refuses a search that hides the proposal.
        ->toContain('check \'a proposal on the second page is found\'')
        ->toContain('check \'the search is scoped to the repository of the key and to issues\'')
        ->toContain('run_case \'a search that GitHub cut short is refused\' 3')
        ->toContain('check \'a cut search does not allow a second issue\'')
        ->toContain('run_case \'a failed search is refused\' 3')
        // Usage and links.
        ->toContain('run_case \'an issue URL is a usage error\' 1')
        ->toContain('run_case \'a pull request URL with trailing text is a usage error\' 1')
        ->toContain('run_case \'an owner that starts with a hyphen is a usage error\' 1')
        ->toContain('run_case \'a directory that is a symbolic link is refused\' 3');
});

test('the loop script reuses the earlier scripts, reads no network of its own, and names no platform path', function (): void {
    $script = feedbackFile('skills/_shared/feedback-loop.sh');

    expect($script)
        ->toContain('gh repo view "$owner/$name" --json nameWithOwner')
        ->toContain('"$HERE/extract-findings.sh" --repo "$CANONICAL_REPO" --pr "$PR_NUMBER" --input "$INPUT" --store "$DIR/$STORE_NAME"')
        ->toContain('"$HERE/collect-findings.sh" --pr-url "https://github.com/$CANONICAL_REPO/pull/$PR_NUMBER" --store "$DIR/$STORE_NAME"')
        ->toContain('"$HERE/analyze-recurrence.sh" --store "$dir/merged.jsonl" --state "$dir/state.json"')
        ->toContain('"$HERE/propose-prevention.sh" --find --key "$key" --results "$tmp/results.json"')
        ->toContain('gh api -X GET search/issues --paginate');

    // The store check, the report parser, and the fingerprint stay in the earlier scripts. One needle per call:
    // a negated toContain with several needles passes when any one of them is absent.
    $code = preg_replace('/^\s*#.*$/m', '', $script) ?? '';
    $selfTestStart = strpos($code, 'self_test() {');

    expect($selfTestStart)->not->toBeFalse();

    $production = substr($code, 0, (int) $selfTestStart);

    $forbidden = ['curl', 'wget', 'eval ', 'bash -c', 'finding-fingerprint', 'sha256sum', 'shasum', 'git '];

    foreach ([...$forbidden, '.claude/', '.codex/', '.agents/', 'CLAUDE_'] as $needle) {
        expect($production)->not->toContain($needle);
    }
});

test('the lookup refuses a result of another repository and the proposal contract says so', function (): void {
    $script = feedbackFile('skills/_shared/propose-prevention.sh');

    expect($script)
        ->toContain('| select(.html_url | ascii_downcase | startswith("https://github.com/" + ($key_repo | ascii_downcase) + "/issues/"))')
        ->toContain('--arg key_repo "${key%%:*}"')
        ->toContain('check \'a result that points to another owner or repository is not the proposal of this class\'')
        ->toContain('check \'the repository of the result is compared without regard to case\'');

    expect(feedbackFile('skills/code-review/references/prevention-proposal.md'))
        ->toContain('and its URL belongs to the repository of the key (compared without regard to case).')
        ->toContain('3. Run `skills/_shared/feedback-loop.sh lookup --key <key>`.')
        ->toContain('The number of proposals in the repository therefore never blocks the lookup.');
});

test('the contract names the roles, the store, the canonical repository, the approval, the consent, and the limits', function (): void {
    $contract = feedbackFile('skills/code-review/references/feedback-loop.md');

    expect($contract)
        ->toStartWith('# Feedback loop — from a repeated review finding to an enforced safeguard')
        ->toContain('## Who runs what')
        ->toContain('| Write the analysis of one class | `leonardo`, in feedback analysis mode |')
        ->toContain('| Implement the safeguard | `donatello`, through the ordinary pipeline |')
        ->toContain('`splinter` coordinates and decides. It implements nothing, and `leonardo` stays read-only')
        ->toContain('## The store')
        ->toContain('The directory is `.claude/run/feedback`, and `.codex/run/feedback` on Codex')
        ->toContain('- **Every file is derived.** Delete the directory at any time.')
        ->toContain('- **No second database.** The store is plain files.')
        ->toContain('## One spelling of the repository')
        ->toContain('`record` and `collect` resolve the repository of the URL with `gh repo view --json nameWithOwner`')
        ->toContain('## Recording a review round')
        ->toContain('- **Only a tier that reviews records.** A `FAST` run dispatches no review, so it records nothing.')
        ->toContain('## The feedback-analysis run')
        ->toContain('No ordinary run starts it, and no step of the issue-to-pull-request pipeline depends on it.')
        ->toContain('Never reopen or close an issue, and never edit its body.')
        ->toContain('When the search returns an older proposal instead, two proposals exist for one class: report both URLs and close neither.')
        ->toContain('## Writing the analysis')
        ->toContain('5. Return `Feedback analysis done` with the path. Do not file, comment, edit, or commit.')
        ->toContain('## Approval')
        ->toContain('- the user\'s own instruction names the proposal issue as the task, or')
        ->toContain('- a person applied the `Resolve_by_AI` label to the proposal issue.')
        ->toContain('A closed proposal is not implemented.')
        ->toContain('## Consent')
        ->toContain('are **L1** for `splinter` in a feedback-analysis run that the user asked for.')
        ->toContain('A safeguard task is classified with `--floor STANDARD`, so a safeguard never closes without `leonardo` reading it.')
        ->toContain('## When the analysis is unavailable')
        ->toContain('Nothing in the issue-to-pull-request pipeline waits for the loop.')
        ->toContain('## Configuration and limits')
        ->toContain('- **No automatic trigger.** The analysis runs when the user asks for it.')
        ->toContain('- **The freshness of the issue search is not verified.**')
        ->toContain('- **A rule is not changed during a running implementation.**');

    // Words that state a guess are not allowed in a contract that every output reads (rules/general/general.md).
    foreach (['probably', 'likely', 'might', 'presumably', 'seems'] as $needle) {
        expect($contract)->not->toContain($needle);
    }
});

test('the earlier contracts hand their open points to the wiring contract', function (): void {
    $analysis = feedbackFile('skills/code-review/references/recurrence-analysis.md');
    $proposal = feedbackFile('skills/code-review/references/prevention-proposal.md');

    expect($analysis)
        ->toContain('## What the wiring phase decided')
        ->toContain('The `repo` value is the canonical `nameWithOwner` that GitHub reports');
    expect($analysis)->not->toContain('belongs to the wiring phase (#189)');

    expect($proposal)->toContain('Phase 4 (#189) assigned the roles in `feedback-loop.md`');
    expect($proposal)->not->toContain('belongs to the wiring phase (#189)');

    expect(feedbackFile('skills/code-review/references/finding-records.md'))
        ->toContain('Phase 4 wires those steps: `skills/_shared/feedback-loop.sh` runs the extractor');
});

test('leonardo records each round on instruction, writes the analysis file in a third mode, and stays read-only', function (): void {
    $leonardo = feedbackFile('agents/leonardo.md');

    expect($leonardo)
        ->toContain('dispatches you in one of three modes')
        ->toContain('- **Feedback analysis mode (recurring defect class)** — `splinter` hands you one defect class')
        ->toContain('## Feedback analysis mode (recurring defect class)')
        ->toContain(
            '2. Inspect the repository read-only, then write the one file at the path the dispatch names, '
            . 'under `.claude/run/feedback/`, with `cat >`. You write no other file.',
        )
        ->toContain('3. File no issue, publish no comment, and edit and commit nothing.')
        ->toContain('`Feedback analysis done` (feedback analysis mode)')
        ->toContain('Instruct it also to record each round and the published report for the feedback loop')
        ->toContain('The recording is best effort and gates nothing.')
        ->toContain('run `skills/_shared/feedback-loop.sh` (`record` and `collect`) only as')
        ->toContain('write the one analysis file the dispatch names under that directory with `cat >`')
        ->toContain('disallowedTools: Write, Edit');

    expect(substr_count($leonardo, 'Feedback analysis done'))->toBe(3);
    expect(feedbackFile('codex/agents/leonardo.toml'))->not->toContain('feedback');
});

test('splinter runs the feedback analysis as an analysis-only run and never starts an implementation from it', function (): void {
    $splinter = feedbackFile('agents/splinter.md');

    expect($splinter)
        ->toContain('## Feedback-analysis mode — recurring review findings')
        ->toContain('**load `@skills/code-review/references/feedback-loop.md` and follow *The feedback-analysis run* in full.**')
        ->toContain('It is an analysis-only run: you take no write-lock, open no pull request, and never dispatch `donatello`.')
        ->toContain('(ledger role `leonardo:feedback`)')
        ->toContain('Never apply `Resolve_by_AI` to a proposal.')
        ->toContain('`Feedback analysis done` when the run was a feedback-analysis run')
        ->toContain('on a feedback-analysis run, the recurring classes, each proposal that was filed or refreshed with its link')
        ->toContain(
            'When the source issue is a safeguard proposal (`@skills/resolve-issue/references/safeguard-implementation.md`), add `--floor STANDARD`',
        )
        ->toContain('`leonardo:cr` / `leonardo:analysis` / `leonardo:feedback`')
        ->toContain(
            'and the one issue or comment that run files, only through `@skills/create-issue/SKILL.md` '
            . 'and `skills/code-review-github/scripts/upsert-comment.sh`',
        )
        ->toContain('disallowedTools: Write, Edit');

    // The new mode adds to the Status line and to the three sections that omit a field for an analysis-only run.
    expect(substr_count($splinter, 'feedback-analysis run'))->toBe(7);
    expect(feedbackFile('codex/agents/splinter.toml'))->not->toContain('feedback');
});

test('an approved proposal is implemented by the ordinary pipeline under four proofs and a read-only review', function (): void {
    $reference = feedbackFile('skills/resolve-issue/references/safeguard-implementation.md');

    expect($reference)
        ->toStartWith('# Safeguard implementation')
        ->toContain('**Applies only when the source issue is a prevention proposal.**')
        ->toContain('The marker adds requirements and grants nothing.')
        ->toContain('stop `Blocked: proposal not approved` before the first file write, release the claim, and implement nothing.')
        ->toContain('A closed proposal is not implemented either.')
        ->toContain('Never weaken, disable, or baseline an existing check, and never add a suppression')
        ->toContain('so no rule changes while an implementation runs.')
        ->toContain('1. **Negative.** Reduce the faulty example to the smallest input that the safeguard reads, and show that the safeguard rejects it.')
        ->toContain('2. **Positive.** Give the safeguard code that is equivalent to the faulty example and valid')
        ->toContain('3. **Regression.** Run the safeguard over the existing code in its whole configured scope, not only the diff.')
        ->toContain('4. **Gate.** Name the entry of the project\'s quality gate, or of its CI workflow, that runs the safeguard')
        ->toContain('stops the run as **Blocked**')
        ->toContain('Open it as a Draft with `Closes #<proposal>`')
        ->toContain('The classification of the task uses `--floor STANDARD`')
        ->toContain('Add a `## Safeguard` section with:')
        ->toContain('## 6. Review of a safeguard')
        ->toContain('The review stays read-only: it runs the commands of the proofs, reports, and changes nothing.')
        ->toContain('| The safeguard rejects valid code: the positive proof or the regression run fails, or the proof is absent | Critical |')
        ->toContain('| No negative proof, or the negative test passes without the safeguard | Critical |')
        ->toContain('| The change is a prompt or a document where levels 1 to 4 can enforce the class, or level 5 states no limitation | Critical |');

    expect(feedbackFile('skills/resolve-issue/SKILL.md'))
        ->toContain('Prevention proposal sources also need the `references/safeguard-implementation.md` proofs.');
    expect(feedbackFile('skills/resolve-issue/references/pull-request.md'))
        ->toContain('**`## Safeguard`** — when the source issue is a prevention proposal');
    expect(feedbackFile('skills/code-review/references/specialized-reviews.md'))
        ->toContain(
            '- **Safeguard pull request → the safeguard review runs.** Trigger it whenever the pull request body carries a `## Safeguard` section',
        )
        ->toContain('A pull request without that section never triggers it.');
});

test('filing a proposal has its own consent row and no other row changes', function (): void {
    $orchestration = feedbackFile('rules/compound-engineering/orchestration.md');

    expect($orchestration)->toContain(
        '| `splinter` | File a prevention proposal as a tracker issue, or refresh its `prevention-proposal` comment, during a feedback-analysis run | L1 |',
    );
    expect(substr_count($orchestration, 'feedback-analysis run'))->toBe(1);
    expect($orchestration)->toContain(
        'No other run files a proposal, `Resolve_by_AI` is never applied to one, and implementing it waits for a human approval |',
    );
});

test('no ordinary pipeline waits for the loop: only the review and the feedback run name it', function (): void {
    $files = [
        'skills/resolve-issue/SKILL.md',
        'skills/process-code-review/SKILL.md',
        'skills/merge-github-pr/SKILL.md',
        'skills/verify-merge-readiness/SKILL.md',
        'skills/code-review/templates/review-output.md',
        'skills/code-review-github/templates/pr-comment-output.md',
        'agents/donatello.md',
        'agents/april.md',
        'agents/casey.md',
        'agents/raphael.md',
        'agents/michelangelo.md',
    ];

    foreach ($files as $file) {
        expect(feedbackFile($file))->not->toContain('feedback-loop');
    }

    // The two agents that run it name it, and every adapter stays thin: no adapter and no agent holds a second copy.
    foreach (['agents/leonardo.md', 'agents/splinter.md'] as $file) {
        expect(feedbackFile($file))->toContain('feedback-loop');
    }

    foreach (feedbackAgentFiles() as $file) {
        expect(feedbackFile($file))->not->toContain('feedback-loop.sh --');
    }

    expect(feedbackFile('codex/agents/leonardo.toml'))->toContain('First read `.codex/agent-instructions/leonardo.md` in full');
    expect(feedbackFile('codex/agents/splinter.toml'))->toContain('First read `.codex/agent-instructions/splinter.md` in full');
    expect(feedbackFile('codex/agents/donatello.toml'))->toContain('A bare `skills/<path>` reference to a shipped script');
});

test('the changelog records the wiring once', function (): void {
    $changelog = feedbackFile('CHANGELOG.md');

    expect(substr_count($changelog, 'phase 4 of the recurring-defect epic #185'))->toBe(1);
    expect($changelog)->toContain('`skills/_shared/feedback-loop.sh`');
});
