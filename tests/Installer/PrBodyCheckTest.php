<?php

declare(strict_types = 1);

// The pull-request body check. Per the project's test-isolation rule a Pest test cannot exec a real .sh,
// so the behavioural proof lives in the script's own `--self-test` (the precedent is
// `skills/_shared/check-cr-header.sh`). The pins below fix the section lists against the templates they
// come from, the wiring that makes the self-test run in the build, and the steps that call the script.

function prBodyCheckSource(string $relativePath): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
}

/**
 * The `## ` headings of a body template, with the placeholder that follows the Merge risk heading removed.
 *
 * @return list<string>
 */
function prBodyTemplateHeadings(string $relativePath): array
{
    $headings = [];

    foreach (explode("\n", prBodyCheckSource($relativePath)) as $line) {
        if (!str_starts_with($line, '## ')) {
            continue;
        }

        $heading = substr($line, 3);
        $placeholder = strpos($heading, ' <');
        $headings[] = $placeholder === false ? $heading : substr($heading, 0, $placeholder);
    }

    return $headings;
}

test('the body check is shipped, executable, self-testing, and run by the build', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $script = $packageDir . '/skills/_shared/check-pr-body.sh';

    expect(is_file($script))->toBeTrue();
    expect(is_executable($script))->toBeTrue();

    expect(prBodyCheckSource('skills/_shared/check-pr-body.sh'))
        ->toStartWith('#!/usr/bin/env bash')
        ->toContain('set -euo pipefail')
        ->toContain('if [[ "${1:-}" == "--self-test" ]]; then');

    /** @var array{scripts: array<string, array<int, string>>} $composer */
    $composer = json_decode((string) file_get_contents($packageDir . '/composer.json'), associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);

    expect(substr_count(implode(' ', $composer['scripts']['shell-self-tests']), ' skills/_shared/check-pr-body.sh '))->toBe(1);
});

test('the limit and the outline marker the check reads are the ones the rules define', function (): void {
    $script = prBodyCheckSource('skills/_shared/check-pr-body.sh');
    $rule = prBodyCheckSource('rules/git/pull-requests.md');
    $outline = prBodyCheckSource('skills/resolve-issue/templates/pull-request-outline.md');

    expect(substr_count($script, 'MAX_CHARS=6500'))->toBe(1)
        ->and(substr_count($rule, 'at most 6,500 characters'))->toBe(1)
        ->and(substr_count($script, 'OUTLINE_MARKER=\'<!-- ai-olympus:contribution-policy=human-description -->\''))->toBe(1)
        ->and(str_contains($outline, '<!-- ai-olympus:contribution-policy=human-description -->'))->toBeTrue();
});

test('every section the check knows is a section of its template, and every template heading is known', function (
    string $template,
    string $templatePath,
): void {
    $script = prBodyCheckSource('skills/_shared/check-pr-body.sh');

    // The script's own list for this template, as `<always|optional>|<heading|details>:<name>` lines.
    $block = (string) preg_replace('~\A.*?\n  ' . preg_quote($template, '~') . '\)\n    cat <<\'EOF\'\n(.*?)\nEOF\n.*~s', '$1', $script);
    expect($block)->not->toBe($script);

    $known = [];

    foreach (explode("\n", $block) as $line) {
        [$state, $section] = explode('|', $line, 2);
        expect($state)->toBeIn(['always', 'optional']);
        $known[] = $section;
    }

    expect($known)->not->toBeEmpty();

    foreach ($known as $section) {
        [$kind, $name] = explode(':', $section, 2);
        $needle = $kind === 'heading' ? '## ' . $name : '<summary><strong>' . $name . '</strong></summary>';
        expect(str_contains(prBodyCheckSource($templatePath), $needle))->toBeTrue();
    }

    foreach (prBodyTemplateHeadings($templatePath) as $heading) {
        expect($known)->toContain('heading:' . $heading);
    }
})->with([
    'composer-update' => ['composer-update', 'skills/composer-update-pr/templates/pull-request-body.md'],
    'resolve-issue' => ['resolve-issue', 'skills/resolve-issue/templates/pull-request-body.md'],
]);

test('the always-present sections are the ones the resolve-issue template marks as always present', function (): void {
    $script = prBodyCheckSource('skills/_shared/check-pr-body.sh');
    $template = prBodyCheckSource('skills/resolve-issue/templates/pull-request-body.md');

    expect(str_contains($template, 'Merge risk, Summary, Walkthrough, Testing, and Audit are always present.'))->toBeTrue();

    foreach (['heading:Merge risk', 'heading:Summary', 'details:Walkthrough', 'details:✅ Testing', 'details:Audit'] as $section) {
        expect(substr_count($script, 'always|' . $section . "\n"))->toBeGreaterThanOrEqual(1);
    }

    foreach (['heading:Open decisions for the operator', 'heading:Security acceptance checklist', 'heading:Safeguard', 'heading:TODO'] as $section) {
        expect(substr_count($script, 'optional|' . $section . "\n"))->toBe(1);
    }
});

test('every step that writes a pull-request body runs the check first', function (): void {
    $rule = prBodyCheckSource('rules/git/pull-requests.md');
    $reference = prBodyCheckSource('skills/resolve-issue/references/pull-request.md');
    $update = prBodyCheckSource('skills/composer-update-pr/SKILL.md');
    $leonardo = prBodyCheckSource('agents/leonardo.md');

    expect(substr_count($rule, '`skills/_shared/check-pr-body.sh --file <body-file> [--template resolve-issue|composer-update]`'))->toBe(1)
        ->and(str_contains($rule, 'Publish only after exit `0`'))->toBeTrue()
        ->and(substr_count($reference, 'skills/_shared/check-pr-body.sh --file <body-file>'))->toBe(1)
        ->and(substr_count($reference, 'skills/_shared/check-pr-body.sh --file "$file"'))->toBe(1)
        ->and(substr_count($update, 'skills/_shared/check-pr-body.sh --file <file> --template composer-update'))->toBe(1)
        ->and(substr_count($leonardo, 'skills/_shared/check-pr-body.sh --file "$file"'))->toBe(1);
});
