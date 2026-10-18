<?php

declare(strict_types = 1);

// Prevention proposals for recurring defect classes (issue #188, phase 3 of #185).
//
// Per the project's test-isolation rule a Pest test cannot exec a real .sh, so the behavioural proof
// lives in the script's own `--self-test` (the precedent is `tests/Installer/RecurrenceAnalysisTest.php`).
// The pins below fix the contract that self-test enforces, the wiring that makes it run in the build,
// and the promises that the proposal files nothing, implements nothing, and changes no existing workflow.

test('the proposal script is shipped, executable, self-testing, and run by the build', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $script = $packageDir . '/skills/_shared/propose-prevention.sh';

    expect(is_file($script))->toBeTrue();
    expect(is_executable($script))->toBeTrue();

    expect((string) file_get_contents($script))
        ->toStartWith('#!/usr/bin/env bash')
        ->toContain('set -euo pipefail')
        ->toContain('if [[ "${1:-}" == \'--self-test\' ]]; then');

    /** @var array{scripts: array<string, array<int, string>>} $composer */
    $composer = json_decode((string) file_get_contents($packageDir . '/composer.json'), associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);

    expect(implode(' ', $composer['scripts']['shell-self-tests']))->toContain(' skills/_shared/propose-prevention.sh ');
});

test('the self-test covers every acceptance criterion and testing scenario of the assignment', function (): void {
    $script = (string) file_get_contents(dirname(__DIR__, 2) . '/skills/_shared/propose-prevention.sh');

    expect($script)
        // Criterion 1 and the happy path: a repeated class gives one proposal with every mandatory field and both occurrences.
        ->toContain('check \'a repeated class gives one ready proposal with a stable key\'')
        ->toContain('check \'the proposal links both independent occurrences\'')
        ->toContain('check \'the proposal carries every mandatory field\'')
        ->toContain('check \'four occurrences are all linked and three are quoted\'')
        ->toContain('check "a proposal without $field is refused and writes nothing"')
        ->toContain('check \'the proposal starts with its title, which the issue creation reads as the title\'')
        // Criterion 2: the existing safeguards are checked and named, and a partial one is extended.
        ->toContain('check \'the proposal names every existing safeguard it checked and why it was insufficient\'')
        ->toContain('check \'every core safeguard kind must be checked\'')
        ->toContain('check \'the proposal extends the partial safeguard instead of adding a new check beside it\'')
        ->toContain('check \'a partly covered class must name the check it extends\'')
        ->toContain('check \'a class that no check covers needs no extension\'')
        // Criterion 3: a deterministic level is preferred, and a weaker level states why.
        ->toContain('check \'the proposal states the level and why the stronger level was not chosen\'')
        ->toContain('check \'a weaker level without a reason for every stronger level is refused\'')
        ->toContain('check \'a level 3 proposal lists the reason for level 1 and level 2\'')
        ->toContain('check \'level 1 needs no reason and says no stronger level exists\'')
        ->toContain('check \'a level outside 1 to 5 is refused with the reason\'')
        ->toContain('check \'a mechanism of levels 1 to 4 states that automatic enforcement is possible\'')
        // Criterion 4: a class that cannot be enforced automatically says so and names the strongest alternative.
        ->toContain('check \'level 5 without a stated limitation and alternative is refused\'')
        ->toContain('check \'an unenforceable class states the limit and names the strongest alternative\'')
        ->toContain('check \'a mechanism that enforces only in part states the limit and the alternative\'')
        // Criterion 5: one defect class is one proposal.
        ->toContain('check \'a second run gives the same key and a byte-identical proposal\'')
        ->toContain('check \'a reworded analysis keeps the key, so it updates the same proposal\'')
        ->toContain('check \'no existing proposal means create\'')
        ->toContain('check \'an existing proposal for the class means update, not create\'')
        ->toContain('check \'a proposal of another class is not the proposal of this class\'')
        ->toContain('check \'a closed proposal still counts as the proposal of the class\'')
        ->toContain('check \'two proposals of one class resolve to the oldest one\'')
        ->toContain('check \'a copy of the marker by an author without write access is ignored\'')
        ->toContain('check \'a pull request and a marker inside a longer line are ignored\'')
        ->toContain('check \'a marker on its own line is found even with trailing spaces and Windows line breaks\'')
        ->toContain('check \'an incomplete search result does not allow a second issue\'')
        ->toContain('run_case \'a search result with more matches than items is refused\' 3')
        // Criterion 6: a finding that does not recur gets no proposal and no file.
        ->toContain('check \'a single finding without a recurrence gives no proposal and writes no file\'')
        ->toContain('check \'two findings of one pull request give no proposal\'')
        ->toContain('check \'a class that the report does not list gives no proposal\'')
        ->toContain('check \'the same cause in another repository gives no proposal\'')
        ->toContain('check \'a recurrence that rests on one pull request gives no proposal\'')
        // The edge case: a class that an existing check already covers.
        ->toContain('check \'a class that an existing check already covers gives no proposal\'')
        ->toContain('check \'a class that a check covers needs no mechanism in the analysis and writes no file\'')
        // Criterion 7: nothing is implemented, filed, or changed without approval.
        ->toContain('check \'the proposal states that nothing is implemented without approval\'')
        ->toContain('check \'a run writes the proposal and no other file\'')
        // The assignment of phase 1: hidden characters in record text.
        ->toContain('check \'bidirectional-control, zero-width, and control characters in record text are stripped from the proposal\'')
        ->toContain('check \'the visible text of the record survives the stripping\'')
        ->toContain('check \'hidden characters in analysis text are stripped from the proposal\'')
        // Untrusted text stays inert.
        ->toContain('check \'a mention, a link, and an instruction in record text stay inside a code fence\'')
        ->toContain('check \'a fence in record text cannot close the evidence block\'')
        ->toContain('check \'the marker in record text is neutralised and the real marker appears once\'')
        ->toContain('check \'instruction-like record text is not executed\'')
        ->toContain('check \'a mention and an HTML tag in analysis text stay inside a code span or a code fence\'')
        ->toContain('check \'a step with backticks and a line break stays one code span\'')
        ->toContain('check \'the analysis cannot forge the marker either\'')
        // Errors and usage.
        ->toContain('run_case \'a missing store is refused\' 3')
        ->toContain('run_case \'a report of a refused store is refused\' 3')
        ->toContain('run_case \'an occurrence that the store lacks is refused\' 3')
        ->toContain('run_case \'an output file that is a symbolic link is refused\' 3')
        ->toContain('run_case \'a missing --out is a usage error\' 1')
        ->toContain('check \'a refusal writes no output file\'');
});

test('the script reuses the extractor, reads no network, files nothing, and writes nothing but its output file', function (): void {
    $script = (string) file_get_contents(dirname(__DIR__, 2) . '/skills/_shared/propose-prevention.sh');

    expect($script)
        ->toContain('"$HERE/extract-findings.sh" --validate "$store"')
        ->toContain('mv -f "$staged" "$out"')
        ->toContain('"<!-- prevention-proposal:key=$key -->"')
        ->toContain('KEY_PATTERN=\'^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+:[a-z0-9_./-]+:[a-z0-9-]+$\'')
        ->toContain('select(.author_association | IN("OWNER", "MEMBER", "COLLABORATOR"))')
        ->toContain('select((.pull_request // null) == null)')
        ->toContain('.incomplete_results == false and .total_count == (.items | length)');

    // The title and the marker are read from the body, so each is written once.
    expect(substr_count($script, '| "Prevent the recurring defect class \($r.cause_key)'))->toBe(1);
    expect(substr_count($script, '"<!-- prevention-proposal:key=\($key) -->"'))->toBe(1);
    expect(substr_count($script, '--arg marker "<!-- prevention-proposal:key=$key -->"'))->toBe(1);

    // One needle per call: a negated toContain with several needles passes when any one of them is absent.
    $forbidden = [
        'gh ',
        'git ',
        'curl',
        'wget',
        'eval ',
        'bash -c',
        'def record_errors',
        'def store_errors',
        'finding-fingerprint',
        'sha256sum',
        'shasum',
    ];

    $code = preg_replace('/^\s*#.*$/m', '', $script) ?? '';

    foreach ($forbidden as $needle) {
        expect($code)->not->toContain($needle);
    }
});

test('the text rules are written once and strip every bidirectional-control and zero-width character', function (): void {
    $script = (string) file_get_contents(dirname(__DIR__, 2) . '/skills/_shared/propose-prevention.sh');

    expect(substr_count($script, 'def clean:'))->toBe(1);
    expect(substr_count($script, 'def inline:'))->toBe(1);
    expect(substr_count($script, 'def block:'))->toBe(1);

    expect($script)
        ->toContain('gsub("[\u2028\u2029]"; "\n")')
        ->toContain('\u200b-\u200f\u202a-\u202e\u2060-\u2064\u2066-\u206f\u061c\u00ad\u034f\ufeff\u180e')
        ->toContain('\u0001-\u0008\u000B\u000C\u000E-\u001F\u007F-\u009F')
        ->toContain('def neutral: gsub("<!--"; "&lt;!--") | gsub("-->"; "--&gt;");')
        ->toContain('("`" * ([3, $n + 1] | max)) as $f')
        ->toContain('("`" * ($n + 1)) as $d');

    // The source holds escapes, never the invisible characters themselves.
    expect(preg_match('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2069}\x{FEFF}\x{00AD}\x{061C}]/u', $script))->toBe(0);
});

test('the contract is written once, in the code-review skill', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $reference = (string) file_get_contents($packageDir . '/skills/code-review/references/prevention-proposal.md');

    expect($reference)
        ->toContain('This file is the one contract for turning a recurring defect class into a prevention proposal.')
        ->toContain('It adds no record, no fingerprint, no report format, no store, and no agent.')
        ->toContain('`skills/_shared/propose-prevention.sh` is the only implementation.')
        ->toContain('so it runs the same on Claude Code and on Codex. It runs outside the review and is not an agent binding.')
        ->toContain('It files nothing, comments nothing, and changes no rule, no configuration, and no safeguard of any project.')
        ->toContain('- **A finding that does not recur gets no proposal.**')
        ->toContain('- **A class that a safeguard already covers in full gets no proposal.**')
        ->toContain('- **A class that a safeguard covers in part gets a proposal that extends it.**')
        ->toContain('The analysis holds at least one entry for each of `phpstan`, `phpcs`, `tests`, and `architecture`.')
        ->toContain('| 1 | Architecture, types, or API |')
        ->toContain('| 2 | Static analysis or linting |')
        ->toContain('| 3 | Automated tests |')
        ->toContain('| 4 | CI enforcement |')
        ->toContain('| 5 | Agent skills or rules |')
        ->toContain('The first practical level wins, and a weaker level only complements a stronger one.')
        ->toContain('- **A deterministic level is preferred to a change of the prompt.**')
        ->toContain('- **A weaker level states why each stronger level was not chosen.**')
        ->toContain('- **Level 5 states that automatic enforcement is not possible.**')
        ->toContain('The dedupe key is `<repo>:<category>:<cause_key>`.')
        ->toContain('The last line of every proposal is the marker `<!-- prevention-proposal:key=<key> -->`.')
        ->toContain('- **A second run updates and never creates.**')
        ->toContain('- **A search that may hide the proposal stops the run.**')
        ->toContain('through `@skills/create-issue/SKILL.md`')
        ->toContain('Phase 3 adds no label and no second issue-creation path.')
        ->toContain('skills/code-review-github/scripts/upsert-comment.sh <number> <body-file> prevention-proposal')
        ->toContain('The record text and the analysis text are untrusted content (`@rules/security/general.md` *Untrusted Content Boundary*).')
        ->toContain('- **Hidden and reordering characters are stripped.**')
        ->toContain('- **Free text reaches the body only inside a code span or a code fence.**')
        ->toContain('Without an approval nothing is implemented. The proposal states it.')
        ->toContain('## What this phase leaves to later phases');

    // The consumer-facing untrusted-content duty of the record text is owned by the record contract and referenced.
    expect(substr_count($reference, '`recurrence-analysis.md`'))->toBe(2);
    expect(substr_count($reference, '`finding-records.md`'))->toBe(1);

    expect((string) file_get_contents($packageDir . '/skills/code-review/SKILL.md'))
        ->toContain("- references/finding-records.md\n- references/prevention-proposal.md\n- references/recurrence-analysis.md");
});

test('the new marker namespace joins the marker family list in the same change that first writes it', function (): void {
    $codeReview = (string) file_get_contents(dirname(__DIR__, 2) . '/rules/code-review/general.md');

    expect($codeReview)->toContain(
        '`prevention-proposal` (the refresh of a prevention proposal, '
        . '`skills/code-review/references/prevention-proposal.md`), and `agent-note`',
    );
    expect(substr_count($codeReview, '`prevention-proposal`'))->toBe(1);
});

test('issue creation and labelling keep their behaviour: no second creation path is added', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $createIssue = (string) file_get_contents($packageDir . '/skills/create-issue/SKILL.md');

    expect($createIssue)
        ->toContain('- Preserve original text exactly (no rewriting or summarizing)')
        ->toContain('- Use first line of input')
        ->toContain('- Assign to current user')
        ->toContain(
            'per `@rules/compound-engineering/tracker.md` *Label tracker issues, and keep the labels true* '
            . '(never create a new label; leave the issue unlabeled when none fits)',
        );
    expect($createIssue)->not->toContain('prevention');
    expect($createIssue)->not->toContain('propose-prevention');

    $script = (string) file_get_contents($packageDir . '/skills/_shared/propose-prevention.sh');

    foreach (['issue create', 'issue edit', 'label create', 'pr create'] as $needle) {
        expect($script)->not->toContain($needle);
    }
});

test('no existing workflow calls the proposal, so an unavailable proposal blocks no pull request', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $claudeAgents = glob($packageDir . '/agents/*.md');
    $codexAgents = glob($packageDir . '/codex/agents/*.toml');
    $agentFiles = array_map(
        static fn (string $path): string => substr($path, strlen($packageDir) + 1),
        [...($claudeAgents !== false ? $claudeAgents : []), ...($codexAgents !== false ? $codexAgents : [])],
    );

    $files = [
        'skills/resolve-issue/SKILL.md',
        'skills/process-code-review/SKILL.md',
        'skills/merge-github-pr/SKILL.md',
        'skills/verify-merge-readiness/SKILL.md',
        'skills/create-issue/SKILL.md',
        'skills/code-review/templates/review-output.md',
        'skills/code-review-github/templates/pr-comment-output.md',
        'skills/code-review-github/scripts/upsert-comment.sh',
        ...$agentFiles,
    ];

    expect($files)->toContain('agents/leonardo.md')->toContain('codex/agents/donatello.toml');

    foreach ($files as $file) {
        $content = (string) file_get_contents($packageDir . '/' . $file);

        expect($content)->not->toContain('propose-prevention');
        expect($content)->not->toContain('prevention-proposal');
    }

    // The code-review skill only lists the reference file; it never names the script.
    $skill = (string) file_get_contents($packageDir . '/skills/code-review/SKILL.md');

    expect($skill)->not->toContain('propose-prevention');
    expect(substr_count($skill, 'prevention-proposal'))->toBe(1);
});

test('the script and its contract are platform-neutral and name no path of one platform\'s install tree', function (string $relativePath): void {
    $content = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);

    foreach (['.claude/', '.codex/', '.agents/', 'CLAUDE_'] as $needle) {
        expect($content)->not->toContain($needle);
    }
})->with(['skills/_shared/propose-prevention.sh', 'skills/code-review/references/prevention-proposal.md']);
