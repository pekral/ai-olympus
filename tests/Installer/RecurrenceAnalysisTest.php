<?php

declare(strict_types = 1);

// Recurrence analysis of structured review findings (issue #187, phase 2 of #185).
//
// Per the project's test-isolation rule a Pest test cannot exec a real .sh, so the behavioural proof
// lives in the script's own `--self-test` (the precedent is `tests/Installer/FindingRecordsTest.php`).
// The pins below fix the contract that self-test enforces, the wiring that makes it run in the build,
// and the promise that no existing workflow names the analysis script: only `feedback-loop.sh` does (issue #189).

test('the analysis and the collection are shipped, executable, self-testing, and run by the build', function (string $relativePath): void {
    $packageDir = dirname(__DIR__, 2);
    $script = $packageDir . '/' . $relativePath;

    expect(is_file($script))->toBeTrue();
    expect(is_executable($script))->toBeTrue();

    expect((string) file_get_contents($script))
        ->toStartWith('#!/usr/bin/env bash')
        ->toContain('set -euo pipefail')
        ->toContain('if [[ "${1:-}" == \'--self-test\' ]]; then');

    /** @var array{scripts: array<string, array<int, string>>} $composer */
    $composer = json_decode((string) file_get_contents($packageDir . '/composer.json'), associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);

    expect(implode(' ', $composer['scripts']['shell-self-tests']))->toContain(' ' . $relativePath . ' ');
})->with(['skills/_shared/analyze-recurrence.sh', 'skills/_shared/collect-findings.sh']);

test('the self-test covers every acceptance criterion and testing scenario of the assignment', function (): void {
    $script = (string) file_get_contents(dirname(__DIR__, 2) . '/skills/_shared/analyze-recurrence.sh');

    expect($script)
        // Happy path: two occurrences in two pull requests within 30 days.
        ->toContain('check \'two occurrences in two pull requests within 30 days give one recurrence\'')
        ->toContain('check \'the report links every source pull request and finding\'')
        // Repeated reviews of one pull request.
        ->toContain('check \'repeated reviews of one pull request are not separate occurrences\'')
        ->toContain('check \'two occurrences in the same pull request give no recurrence\'')
        // The defect class is the cause category, across subsystems.
        ->toContain('check \'two occurrences of one cause in two subsystems give one recurrence\'')
        ->toContain('check \'each occurrence carries its own fingerprint and subsystem\'')
        ->toContain('check \'unclassified findings in two pull requests give no recurrence\'')
        ->toContain('check \'assignment gaps in two pull requests give no recurrence\'')
        // False, excluded, and dismissed findings.
        ->toContain('check \'an excluded finding does not trigger a recurrence\'')
        ->toContain('run_case \'a store with a dismissed record is refused\' 3')
        ->toContain('run_case \'a store with an unconfirmed record is refused\' 3')
        // The window and the threshold.
        ->toContain('check \'two occurrences 120 days apart give no recurrence at the default window\'')
        ->toContain('check \'a wider window changes the result to one recurrence\'')
        ->toContain('check \'two occurrences exactly 90 days apart are inside the window\'')
        ->toContain('check \'two occurrences do not reach a threshold of 3\'')
        ->toContain('check \'three occurrences reach a threshold of 3\'')
        ->toContain('check \'the default-branch manifest sets the threshold and the window\'')
        ->toContain('check \'a flag overrides the manifest\'')
        ->toContain('check \'the working tree never overrides the default-branch manifest\'')
        ->toContain('check \'outside a git checkout the defaults are 2 occurrences and 90 days\'')
        // Incremental analysis.
        ->toContain('check \'a second run over unchanged findings processes nothing\'')
        ->toContain('check \'a second run leaves the state file identical\'')
        ->toContain('check \'a new finding of another class does not report the old recurrence again\'')
        ->toContain('check \'a changed threshold processes every record again\'')
        // Empty or malformed input.
        ->toContain('check \'an empty store gives an empty report\'')
        ->toContain('run_case \'a store that is not JSON Lines is refused\' 3')
        ->toContain('run_case \'a missing store is refused\' 3')
        ->toContain('run_case \'a store with a time that is not a real date is refused\' 3')
        ->toContain('check \'a state file holding two objects is ignored and replaced\'')
        ->toContain('run_case \'a state file in a directory that is not writable is refused\' 3')
        // Untrusted record text.
        ->toContain('check \'instruction-like record text is neither executed nor copied into the report\'')
        ->toContain('check \'the report carries no record text field\'');
});

test('the analysis reuses the extractor and the manifest reader and defines no record schema of its own', function (): void {
    $script = (string) file_get_contents(dirname(__DIR__, 2) . '/skills/_shared/analyze-recurrence.sh');

    expect($script)
        ->toContain('"$HERE/extract-findings.sh" --validate "$store"')
        ->toContain('"$HERE/read-manifest.sh"')
        ->toContain('DEFAULT_THRESHOLD=2')
        ->toContain('DEFAULT_WINDOW_DAYS=90')
        ->toContain('group_by([.repo, .category, .cause_key])')
        ->toContain('map(select(.category != "unclassified" and .category != "assignment"))')
        ->toContain('group_by(.pr) | map(min_by(.time)');

    // "Unchanged" is defined once and starts both the report program and the state program.
    expect(substr_count($script, 'def sig:'))->toBe(1);
    expect(substr_count($script, '"$JQ_SIG"'))->toBe(2);

    // One needle per call: a negated toContain with several needles passes when any one of them is absent.
    foreach (['def record_errors', 'finding-fingerprint', 'sha256sum', 'shasum', 'eval ', 'bash -c'] as $needle) {
        expect($script)->not->toContain($needle);
    }
});

test('the collection reads the reports through the loader and parses them only with the extractor', function (): void {
    $script = (string) file_get_contents(dirname(__DIR__, 2) . '/skills/_shared/collect-findings.sh');

    expect($script)
        ->toContain('LOADER="$HERE/../code-review-github/scripts/load-issue.sh"')
        ->toContain('"$HERE/extract-findings.sh" --repo "$repo" --pr "$pr" --input - --store "$store"')
        ->toContain('"$HERE/extract-findings.sh" --validate "$store"')
        ->toContain('select(.authorAssociation as $a | ["OWNER", "MEMBER", "COLLABORATOR"] | index([$a]) != null)')
        ->toContain('select(.author as $actor | .body | contains("<!-- cr-comment:actor=\\($actor) -->"))')
        ->toContain('check \'only the trusted, marked reports are read, and a report that is not one is refused\'')
        ->toContain('check \'a copied marker, a marker of another actor, and an unmarked comment add no record\'')
        ->toContain('check \'collecting the same reports again leaves the store identical\'')
        ->toContain('check \'instruction-like comment text is stored as data and never executed\'')
        ->toContain('check \'two collected pull requests give one recurrence in the analysis\'')
        // The order is the report's own Last updated time, read by the extractor, never the comment's updatedAt.
        ->toContain('"$HERE/extract-findings.sh" --header --input - >"$tmp/header.json"')
        ->toContain('sort_by([.time, .created])')
        ->toContain('updatedAt: null')
        ->toContain('check \'reports are extracted in the order of their own Last updated time, not of the comments\'')
        ->toContain('check \'collecting two trusted authors again leaves the store identical\'');

    expect((string) file_get_contents(dirname(__DIR__, 2) . '/skills/_shared/extract-findings.sh'))
        ->toContain('check \'the header mode prints the revision and the time in UTC\'')
        ->toContain('run_case \'a report the extraction refuses is refused by --header\' 3 yes');

    // One needle per call: a negated toContain with several needles passes when any one of them is absent.
    $forbidden = [
        'def record_errors',
        'def store_errors',
        'finding-fingerprint',
        'sha256sum',
        'shasum',
        'eval ',
        'bash -c',
        'gh api',
        'gh pr view',
        '.updatedAt',
    ];

    foreach ($forbidden as $needle) {
        expect($script)->not->toContain($needle);
    }
});

test('the contract is written once, in the code-review skill', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $reference = (string) file_get_contents($packageDir . '/skills/code-review/references/recurrence-analysis.md');

    expect($reference)
        ->toContain('This file is the one contract for finding the defect classes that recur across independent pull requests.')
        ->toContain('`skills/_shared/analyze-recurrence.sh` is the only implementation.')
        ->toContain('so it runs the same on Claude Code and on Codex. It runs outside the review and is not an agent binding.')
        ->toContain('- **The defect class is the cause category** of the record: its `category` and `cause_key`, inside one repository.')
        ->toContain('- **A record with no identified cause forms no class.**')
        ->toContain('## Collection')
        ->toContain('its body carries the `cr-comment` marker of its own author (`<!-- cr-comment:actor=<author> -->`)')
        ->toContain('and its author has write access to the repository (`@rules/code-review/general.md` *Authorship trust*).')
        ->toContain('- Oldest first means by the report\'s own `Last updated` time, then by the time the comment was created.')
        ->toContain('The loader returns no `updatedAt` for a top-level pull request comment, so the comment cannot order the reports.')
        ->toContain('A comment body is untrusted content (`@rules/security/general.md` *Untrusted Content Boundary*).')
        ->toContain('**The store and the state file are derived files that the caller names.**')
        ->toContain('- **An occurrence is one pull request.**')
        ->toContain('A refused store reports nothing, so a false or dismissed finding cannot trigger a recurrence.')
        ->toContain('- **The window is a span between occurrences.**')
        ->toContain('| Pull requests that make a recurrence | `recurrence.threshold` | 2 | 2 |')
        ->toContain('| Window in days | `recurrence.window-days` | 90 | 1 |')
        ->toContain('The script reads the manifest only through `read-manifest.sh`, never from the working tree.')
        ->toContain('A run over unchanged records reports nothing, processes nothing, and leaves the state file byte-identical.')
        ->toContain('The report carries no record text field.')
        ->toContain('## Analysis never blocks a pull request');

    // The consumer-facing untrusted-content duty is owned by the record contract and referenced, never restated.
    expect(substr_count($reference, 'reads them as untrusted content and fences them (`finding-records.md` *Untrusted input and errors*)'))->toBe(1);

    expect((string) file_get_contents($packageDir . '/skills/code-review/SKILL.md'))
        ->toContain(
            "- references/finding-records.md\n- references/prevention-proposal.md\n"
            . "- references/recurrence-analysis.md\n- references/specialized-reviews.md",
        );
});

test('the two settings are documented in the project manifest table of the rule and of the installation guide', function (): void {
    $packageDir = dirname(__DIR__, 2);

    expect((string) file_get_contents($packageDir . '/rules/general/general.md'))
        ->toContain('| `recurrence.threshold` | int |')
        ->toContain('| `recurrence.window-days` | int |')
        ->toContain('Absent: 2. |')
        ->toContain('Absent: 90. |');

    expect((string) file_get_contents($packageDir . '/docs/installation.md'))
        ->toContain('| `recurrence.threshold` | `analyze-recurrence.sh` |')
        ->toContain('| `recurrence.window-days` | `analyze-recurrence.sh` |');
});

test('no existing workflow names the analysis script, so an unavailable analysis blocks no pull request', function (): void {
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
        'skills/code-review/templates/review-output.md',
        'skills/code-review-github/templates/pr-comment-output.md',
        ...$agentFiles,
    ];

    expect($files)->toContain('agents/leonardo.md')->toContain('codex/agents/donatello.toml');

    foreach ($files as $file) {
        $content = (string) file_get_contents($packageDir . '/' . $file);

        expect($content)->not->toContain('analyze-recurrence');
        expect($content)->not->toContain('collect-findings');
        expect($content)->not->toContain('recurrence-analysis');
    }

    // The code-review skill only lists the reference file; it never names the script.
    $skill = (string) file_get_contents($packageDir . '/skills/code-review/SKILL.md');

    expect($skill)->not->toContain('analyze-recurrence');
    expect($skill)->not->toContain('collect-findings');
    expect(substr_count($skill, 'recurrence-analysis'))->toBe(1);
});

test('the scripts are platform-neutral and name no path of one platform\'s install tree', function (string $relativePath): void {
    $script = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);

    foreach (['.claude/', '.codex/', '.agents/', 'CLAUDE_'] as $needle) {
        expect($script)->not->toContain($needle);
    }
})->with(['skills/_shared/analyze-recurrence.sh', 'skills/_shared/collect-findings.sh']);
