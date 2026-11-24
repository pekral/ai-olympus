<?php

declare(strict_types = 1);

function reviewVisibleTextSource(string $relativePath): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
}

function reviewVisibleTextRuleSection(): string
{
    return installerDocsSection(
        reviewVisibleTextSource('rules/code-review/general.md'),
        '### Visible review text names none of the package\'s own tooling',
    );
}

test('one rule section owns the visible review text, its allowed names, and its deterministic check', function (): void {
    $section = reviewVisibleTextRuleSection();

    expect($section)->toContain('This section is the one owner of the rule.')
        ->and($section)->toContain('an installed directory: `.claude/`, `.codex/`, or `.agents/`')
        ->and($section)->toContain('**Describe the step in plain words instead.**')
        ->and($section)->toContain('the reviewed project\'s own commands, as the project runs them (`vendor/bin/pest --coverage`, `composer build`)')
        ->and($section)->toContain('a path or a file name that the reviewed repository holds')
        ->and($section)->toContain('the hidden machine record of a GitHub review')
        ->and($section)->toContain('`skills/_shared/check-visible-review-text.php`')
        ->and($section)->toContain('**Claude Code and Codex follow the same rule.**');
});

test('both review templates name the project own command and keep the package wrapper out of the Command line', function (string $template): void {
    $source = reviewVisibleTextSource($template);

    expect($source)->toContain('- **Command:** `<the project\'s own command that ran, without the package wrapper that ran it')
        ->and($source)->toContain('**Quality gate:** {the project\'s gate command as the project runs it, never the package wrapper that ran it}')
        ->and($source)->toContain('Name each step in plain words, never by a script or a path of this package')
        ->and($source)->toContain('*Visible review text names none of the package\'s own tooling*')
        ->and($source)->not->toContain('`<exact command run — e.g. `skills/_shared/run-gate.sh --exclusive');
})->with([
    'code-review' => ['skills/code-review/templates/review-output.md'],
    'code-review-github' => ['skills/code-review-github/templates/pr-comment-output.md'],
]);

test('the review body template and the wrapper contract point to the rule and the refusal', function (): void {
    $body = reviewVisibleTextSource('skills/code-review-github/templates/review-body.md');
    $contract = reviewVisibleTextSource('skills/code-review-github/references/cr-wrapper-contract.md');

    expect($body)->toContain('**Name no tooling of this package.**')
        ->and($body)->toContain('*Visible review text names none of the package\'s own tooling*')
        ->and($contract)->toContain('**The visible text names none of the package\'s own tooling.**')
        ->and($contract)->toContain(
            'refuses the summary, the Merge Risk sentence, the Walkthrough, the Where to test block, or an Actionable comments entry '
                . 'that names a package path or a package script name the repository does not hold',
        );
});

test('the wrapper checks the visible text against the reviewed repository and never the hidden record', function (): void {
    $script = reviewVisibleTextSource('skills/code-review-github/scripts/post-inline-review.sh');

    expect($script)->toContain('CHECK_VISIBLE="$HERE/../../_shared/check-visible-review-text.php"')
        ->and($script)->toContain('gh api "repos/${NWO}/git/trees/${commit}?recursive=1"')
        ->and($script)->toContain(
            'visible_text: ([$walk.tldr, $risk.sentence, $walk_content] + $walk.routes + $entries + $walk.known | join("\n\n"))',
        )
        ->and($script)->toContain('php "$CHECK_VISIBLE" --text "$TMP_ROOT/visible.md" --repo-paths "$TMP_ROOT/repo-paths.txt"');
});
