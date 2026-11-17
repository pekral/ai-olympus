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

    // The script's own list for this template, as `<always|optional>|<name>` lines.
    $block = (string) preg_replace('~\A.*?\n  ' . preg_quote($template, '~') . '\)\n    cat <<\'EOF\'\n(.*?)\nEOF\n.*~s', '$1', $script);
    expect($block)->not->toBe($script);

    $known = [];

    foreach (explode("\n", $block) as $line) {
        [$state, $section] = explode('|', $line, 2);
        expect($state)->toBeIn(['always', 'optional']);
        $known[] = $section;
    }

    expect($known)->not->toBeEmpty();

    // The template renders every section open, as a `## <name>` heading; the check also accepts the
    // folded form of the same name.
    foreach ($known as $name) {
        expect(str_contains(prBodyCheckSource($templatePath), '## ' . $name))->toBeTrue();
    }

    foreach (prBodyTemplateHeadings($templatePath) as $heading) {
        expect($known)->toContain($heading);
    }
})->with([
    'composer-update' => ['composer-update', 'skills/composer-update-pr/templates/pull-request-body.md'],
    'resolve-issue' => ['resolve-issue', 'skills/resolve-issue/templates/pull-request-body.md'],
]);

test('the always-present sections are the ones the resolve-issue template marks as always present', function (): void {
    $script = prBodyCheckSource('skills/_shared/check-pr-body.sh');
    $template = prBodyCheckSource('skills/resolve-issue/templates/pull-request-body.md');

    expect(str_contains($template, 'Merge risk, Summary, Walkthrough, Testing, and Audit are always present.'))->toBeTrue();

    foreach (['Merge risk', 'Summary', 'Walkthrough', '✅ Testing', 'Audit'] as $section) {
        expect(substr_count($script, 'always|' . $section . "\n"))->toBeGreaterThanOrEqual(1);
    }

    foreach (['Open decisions for the operator', 'Security acceptance checklist', 'Safeguard', 'TODO'] as $section) {
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
        ->and(substr_count($reference, 'skills/_shared/check-pr-body.sh --file "$file" --template "$template"'))->toBe(1)
        ->and($reference)->toContain('`composer-update` when the head branch of the pull request')
        ->and($reference)->toContain('starts with `chore/composer-update-`')
        ->and(substr_count($update, 'skills/_shared/check-pr-body.sh --file <file> --template composer-update'))->toBe(1)
        ->and(substr_count($leonardo, 'skills/_shared/check-pr-body.sh --file "$file" --template "$template"'))->toBe(1);
});

test('the fold rule has one owner, and every other file points to it and states no copy of the limit', function (): void {
    $rule = prBodyCheckSource('rules/git/pull-requests.md');
    $script = prBodyCheckSource('skills/_shared/check-pr-body.sh');
    $pointer = '*Fold the long sections, and only those*';

    expect(substr_count($rule, '**Fold the long sections, and only those.**'))->toBe(1)
        ->and($rule)->toContain('**`## Merge risk` is never folded,** whatever its length.')
        ->and($rule)->toContain('**No fixed list of folded sections exists.**')
        ->and($rule)->toContain('**Over 600 characters, the section is a `<details>` block.**')
        ->and($rule)->toContain('**At most 600 characters, the section stays an open `##` section.**')
        ->and($rule)->toContain('The `<details>` opener line above the `<summary>` of a following folded section belongs to that section.')
        ->and($rule)->toContain('a line break between two lines counts as one code point')
        ->and($rule)->toContain('**No text stands between a `</details>` and the next section.**')
        ->and($rule)->toContain('**A folded section is the same section for every reader of the body.**')
        ->and(substr_count($script, 'FOLD_LIMIT=600'))->toBe(1)
        ->and($script)->toContain('rules/git/pull-requests.md *PR Content Requirements*; this')
        ->and(prBodyCheckSource('skills/resolve-issue/SKILL.md'))->toContain('(folded as `@rules/git/pull-requests.md` *PR Content Requirements* states)')
        ->and(str_contains(prBodyCheckSource('skills/resolve-issue/SKILL.md'), '600 characters'))->toBeFalse();

    // Every other file carries a pointer to the owner and no copy of the limit.
    $pointers = [
        'skills/resolve-issue/templates/pull-request-body.md',
        'skills/composer-update-pr/templates/pull-request-body.md',
        'skills/composer-update-pr/SKILL.md',
        'skills/resolve-issue/references/pull-request.md',
        'skills/code-review/references/specialized-reviews.md',
        'skills/resolve-issue/references/safeguard-implementation.md',
    ];

    foreach ($pointers as $path) {
        $text = prBodyCheckSource($path);

        expect(str_contains($text, 'Fold the long sections, and only those'))->toBeTrue($path)
            ->and(str_contains($text, '600'))->toBeFalse($path);
    }

    foreach (['skills/resolve-issue/templates/pull-request-body.md', 'skills/composer-update-pr/templates/pull-request-body.md'] as $template) {
        $text = prBodyCheckSource($template);

        expect(substr_count($text, '<!-- Folding: '))->toBe(1)
            ->and($text)->not->toContain('<summary>')
            ->and($text)->not->toContain("<details>\n");
    }

    expect(prBodyCheckSource('skills/composer-update-pr/SKILL.md'))->toContain($pointer);
});

test('the merge-risk refresh refolds a body that an earlier template rendered, and leonardo names the step', function (): void {
    $reference = prBodyCheckSource('skills/resolve-issue/references/pull-request.md');
    $leonardo = prBodyCheckSource('agents/leonardo.md');

    expect($reference)->toContain('`skills/_shared/check-pr-body.sh --refold "$file" --template "$template"')
        ->and($reference)->toContain('every problem it reports starts with `Folding:`')
        ->and($reference)->toContain('Write with the one named `gh pr edit --body-file` exception of that section only after the check exits `0`.')
        ->and($leonardo)->toContain('`skills/_shared/check-pr-body.sh --refold` repairs as *Refresh after the gate* item 6 states');
});
