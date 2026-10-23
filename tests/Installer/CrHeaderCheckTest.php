<?php

declare(strict_types = 1);

// The header-line check (issue #238, follow-up of #236). Per the project's test-isolation rule a Pest
// test cannot exec a real .sh, so the behavioural proof lives in the script's own `--self-test` (the
// precedent is `skills/_shared/check-handoff.sh`). The pins below fix the contract that self-test
// enforces, the wiring that makes it run in the build, and the publication step that calls the script.

function crHeaderCheckSource(string $relativePath): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
}

test('the header check is shipped, executable, self-testing, and run by the build', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $script = $packageDir . '/skills/_shared/check-cr-header.sh';

    expect(is_file($script))->toBeTrue();
    expect(is_executable($script))->toBeTrue();

    expect(crHeaderCheckSource('skills/_shared/check-cr-header.sh'))
        ->toStartWith('#!/usr/bin/env bash')
        ->toContain('set -euo pipefail')
        ->toContain('if [[ "${1:-}" == "--self-test" ]]; then');

    /** @var array{scripts: array<string, array<int, string>>} $composer */
    $composer = json_decode((string) file_get_contents($packageDir . '/composer.json'), associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);

    expect(substr_count(implode(' ', $composer['scripts']['shell-self-tests']), ' skills/_shared/check-cr-header.sh '))->toBe(1);
});

test('the check reads both header lines against the values the rule and the template define', function (): void {
    $script = crHeaderCheckSource('skills/_shared/check-cr-header.sh');
    $rule = crHeaderCheckSource('rules/code-review/general.md');
    $template = crHeaderCheckSource('skills/code-review-github/templates/pr-comment-output.md');

    expect(substr_count($script, 'NOT_APPLICABLE=\'not applicable — the diff changes no code\''))->toBe(1)
        ->and(substr_count($rule, '`not applicable — the diff changes no code`'))->toBe(1)
        ->and(
            substr_count(
                $script,
                'ASSESSMENT_LABELS=(\'untrusted data:\' \'public exposure:\' \'regression test:\' \'automatic rule:\')',
            ),
        )->toBe(
            1,
        )
        ->and(
            substr_count(
                $script,
                'ASSESSMENT_SHAPE=\'^untrusted data: (.+) · public exposure: (.+) · regression test: (.+) · automatic rule: (.+)$\'',
            ),
        )->toBe(
            1,
        )
        ->and(
            substr_count(
                $script,
                'VERDICT_SHAPE=\'^(yes|no) — assignment met: (yes|no|no linked issue) · open Critical: ([0-9]+)( · (.+))?$\'',
            ),
        )->toBe(
            1,
        )
        ->and(
            substr_count(
                $template,
                '**Merge verdict:** {yes | no} — assignment met: {yes | no | no linked issue} · open Critical: {n} · {when no:',
            ),
        )->toBe(
            1,
        )
        ->and(
            substr_count(
                $template,
                'regression test: {not required | present — the test that pins it | required — each unpinned guarantee}',
            ),
        )->toBe(
            1,
        );
});

test('the self-test covers every scenario of the assignment', function (): void {
    $script = crHeaderCheckSource('skills/_shared/check-cr-header.sh');

    expect(substr_count($script, '  scenario \''))->toBe(26)
        ->and($script)->toContain('scenario \'a body without the Merge verdict line is refused\' 3')
        ->and($script)->toContain('scenario \'a body without the Security assessment line is refused\' 3')
        ->and($script)->toContain('scenario \'a security line with the answers out of order is refused\' 3')
        ->and($script)->toContain('scenario \'a security line with a missing answer is refused\' 3')
        ->and($script)->toContain('scenario \'the not-applicable security value is valid\' 0')
        ->and($script)->toContain('scenario \'a yes verdict with an open Critical is refused\' 3')
        ->and($script)->toContain('scenario \'a no verdict without a blocker is refused\' 3')
        ->and($script)->toContain('scenario \'a line quoted inside a fenced code block is not read\' 3')
        ->and($script)->toContain('scenario \'instruction-like text in an answer is read as data\' 0')
        ->and($script)->toContain('usage_error \'no argument is a usage error\'');
});

test('the publication step checks the header lines of the body it then publishes', function (): void {
    $contract = crHeaderCheckSource('skills/code-review-github/references/cr-wrapper-contract.md');
    $publish = 'skills/code-review-github/scripts/upsert-comment.sh <PR-NUMBER|URL> <body-file>';

    expect(substr_count($contract, '- **Check the header lines before publishing.**'))->toBe(1)
        ->and(substr_count($contract, '`skills/_shared/check-cr-header.sh --file <body-file>`'))->toBe(1)
        ->and(substr_count($contract, 'verifies both the `Merge verdict:` line and the `Security assessment:` line in one pass'))->toBe(1)
        ->and(substr_count($contract, 'Publish only after exit `0`; any other exit code is a refusal.'))->toBe(1)
        ->and(substr_count($contract, 'A body that the check refused is never published.'))->toBe(1)
        ->and(substr_count($contract, '- Publish via `' . $publish . '` (the body file the check accepted).'))->toBe(1)
        ->and(strpos($contract, '- **Check the header lines before publishing.**'))->toBeLessThan((int) strpos($contract, '- Publish via `' . $publish));
});

test('the standalone review publication in the leonardo agent routes both pull request comments through the header check', function (): void {
    $leonardo = crHeaderCheckSource('agents/leonardo.md');
    $check = '`@skills/code-review-github/references/cr-wrapper-contract.md` *Check the header lines before publishing*';
    $publish = 'skills/code-review-github/scripts/upsert-comment.sh <PR-NUMBER|URL> <body-file>';

    expect(substr_count($leonardo, 'skills/code-review-github/scripts/upsert-comment.sh <PR-NUMBER|URL> -'))->toBe(0)
        ->and(substr_count($leonardo, $publish))->toBe(2)
        ->and(substr_count($leonardo, $check))->toBe(1)
        ->and(substr_count($leonardo, 'after the same header check, exactly as on a GitHub source'))->toBe(1)
        ->and(substr_count($leonardo, 'check-cr-header.sh'))->toBe(0);
});
