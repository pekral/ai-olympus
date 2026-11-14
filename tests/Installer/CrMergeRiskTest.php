<?php

declare(strict_types = 1);

function mergeRiskSource(string $relativePath): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
}

test(
    'every code review template renders one merge risk header line between the security assessment and the reviewed revision',
    function (string $template): void {
        $content = mergeRiskSource($template);
        $line = '**Merge risk:** {low | medium | high} — {one plain-language sentence on why, derived from the script output and the diff}'
            . ' · impact: {tier} ({each `signal=` name})'
            . ' · verification: {ok | poor} ({each `verification-signal=`})'
            . ' · rollback: {cheap | costly} ({each `rollback-signal=` with its file})'
            . ' · error detection: {ok | poor — each blanket or empty catch with its `file:line` | not checked — <reason>}'
            . ' · up to {short SHA}';

        expect(substr_count($content, $line))->toBe(1)
            ->and(substr_count($content, '**Merge risk:**'))->toBe(1)
            ->and(strpos($content, '**Security assessment:**'))->toBeLessThan((int) strpos($content, '**Merge risk:**'))
            ->and(strpos($content, '**Merge risk:**'))->toBeLessThan((int) strpos($content, '**Reviewed revision:**'))
            ->and(stripos($line, 'alignment'))->toBeFalse();
    },
)->with([
    'core review' => ['skills/code-review/templates/review-output.md'],
    'GitHub wrapper' => ['skills/code-review-github/templates/pr-comment-output.md'],
]);

test('the JIRA and Bugsnag wrappers inherit the merge risk line from the GitHub template', function (string $template): void {
    $content = mergeRiskSource($template);

    expect($content)->toContain('This wrapper renders `@skills/code-review-github/templates/pr-comment-output.md` verbatim')
        ->and($content)->not->toContain('**Merge risk:**');
})->with([
    'Bugsnag' => ['skills/code-review-bugsnag/templates/github-output.md'],
    'JIRA' => ['skills/code-review-jira/templates/github-output.md'],
]);

test('the code review rule states that a script computes the merge risk and that the line never blocks', function (): void {
    $rule = mergeRiskSource('rules/code-review/general.md');

    expect(substr_count($rule, '**Merge risk — one triage grade for a person who reads many pull requests.**'))->toBe(1)
        ->and($rule)->toContain('`Security assessment:`, `Merge risk:`, `Reviewed revision:`')
        ->and($rule)->toContain('1. **A script computes the grade, never the reviewer.** Run `skills/_shared/classify-risk.sh --merge-risk')
        ->and($rule)->toContain('3. **Production monitoring is never claimed.** When it matters whether a new failure path alerts in production')
        ->and($rule)->toContain('that point goes on the `Not verified:` line with the step that settles it.')
        ->and($rule)->toContain(
            '4. **The line is informational.** It never blocks, adds no finding and no severity, and never replaces the `Merge verdict:` line',
        )
        ->and($rule)->toContain('No label carries the grade.');
});

test('error detection is the result of the existing blanket or empty catch check and nothing else', function (): void {
    $rule = mergeRiskSource('rules/code-review/general.md');
    $analysis = mergeRiskSource('rules/code-review/core-analysis.md');

    expect($rule)->toContain('It is the catch-all half of `@rules/code-review/core-analysis.md` *Entry-point error handling for known failures (Laravel)*')
        ->and(substr_count($analysis, '**Entry-point error handling for known failures (Laravel)**'))->toBe(1)
        ->and($analysis)->toContain('flag a blanket `catch (\Throwable)` / empty `catch {}` that hides a failure from Bugsnag');
});

test('the reviewer, the skill, and the wrapper contract render the merge risk line from the script', function (): void {
    expect(mergeRiskSource('agents/leonardo.md'))->toContain(
        '**Merge-risk agenda:** every review you publish carries one triage grade in its header.',
    )
        ->and(mergeRiskSource('agents/leonardo.md'))->toContain('Compute it with `skills/_shared/classify-risk.sh --merge-risk`, never by judgment')
        ->and(mergeRiskSource('skills/code-review/SKILL.md'))->toContain(
            '- **`Merge risk:` header line.** Render it under `Security assessment:` from `skills/_shared/classify-risk.sh --merge-risk`',
        )
        ->and(mergeRiskSource('skills/code-review-github/references/cr-wrapper-contract.md'))->toContain(
            '- **`Merge risk:` header line.** The posted PR comment always carries it, directly under `Security assessment:`',
        );
});

test('the risk classifier grades the merge risk in its own mode and self-tests every grade and signal', function (): void {
    $classifier = mergeRiskSource('skills/_shared/classify-risk.sh');

    expect($classifier)->toContain('if [[ "${1:-}" == "--merge-risk" ]]; then')
        ->and($classifier)->toContain('classification="$(classify --files - ${pass[@]+"${pass[@]}"} <<<"$files")"')
        ->and($classifier)->toContain('CORE_RE="$INFRA_RE"')
        ->and($classifier)->toContain('printf \'merge-risk=%s\n\'')
        ->and($classifier)->toContain('printf \'merge-risk-rule=%s: tier %s, verification %s, rollback %s\n\'');

    foreach ([
        'a small tested fix with green checks is low risk',
        'a destructive migration with poor verification is high risk',
        'a verified CRITICAL change that is cheap to roll back is medium risk',
        'a FAST change with costly rollback is medium risk',
        'a dropColumn in up() is a destructive migration',
        'an empty down() is a destructive migration',
        'a drop inside down() is the rollback, not a signal',
        'a persisted-data write makes rollback costly',
        'a queue dispatch is an external side effect',
        'a mail send is an external side effect',
        'a configuration file makes rollback costly',
        'a write in a test file is no rollback signal',
        'a coverage result that was not supplied is poor verification',
        'a red quality gate is poor verification',
        'a walkthrough scenario that was not met is poor verification',
        'untrusted diff paths are rendered safely',
    ] as $label) {
        expect(substr_count($classifier, '\'' . $label . '\''))->toBe(1);
    }
});

test('no merge risk label is defined anywhere in the package', function (string $directory): void {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/' . $directory, FilesystemIterator::SKIP_DOTS));
    $labelled = [];

    foreach ($files as $file) {
        if ($file instanceof SplFileInfo && str_contains((string) file_get_contents($file->getPathname()), 'merge-risk:')) {
            $labelled[] = $file->getPathname();
        }
    }

    expect($labelled)->toBe([]);
})->with(['rules', 'skills', 'agents', 'commands', 'codex']);
