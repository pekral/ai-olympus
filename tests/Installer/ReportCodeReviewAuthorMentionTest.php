<?php

declare(strict_types = 1);

test('a review-only run mentions the author of the changes on each missing-test and DRY finding (issue #175)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $rule = (string) file_get_contents($packageDir . '/rules/code-review/general.md');
    $template = (string) file_get_contents($packageDir . '/skills/code-review-github/templates/pr-comment-output.md');
    $wrapper = (string) file_get_contents($packageDir . '/skills/code-review-github/references/cr-wrapper-contract.md');
    $prSummary = (string) file_get_contents($packageDir . '/skills/pr-summary/SKILL.md');
    $donatello = (string) file_get_contents($packageDir . '/agents/donatello.md');

    expect($rule)
        ->toContain('- **Second exception: a missing test or a DRY finding in a review-only run.**')
        ->toContain('2. a DRY finding')
        ->toContain('The run never writes the missing test itself; the author of the changes writes it.')
        ->toContain('Every other finding, and every run that is not review-only, names no author.');
    expect($rule)->not->toContain('missing-tests commit');

    expect($template)->toContain('- **Author:** `@<login>` — the pull request\'s `author.login`.');
    expect($template)->not->toContain('## Missing tests added');

    expect($wrapper)->toContain('mention the author of the changes on each missing-test and DRY finding');
    expect($wrapper)->not->toContain('## Missing tests added');

    expect($prSummary)
        ->toContain('End the bullet that retells a missing-test or a DRY finding with the author mention')
        ->toContain('When the review has no point to resolve, render one sentence that says so, and no bullets.');
    expect($prSummary)->not->toContain('## Missing tests added');

    expect((string) file_get_contents($packageDir . '/agents/leonardo.md'))
        ->toContain('Tell the wrapper to mention the author on each missing-test and DRY finding, so the author writes the missing test');

    expect($donatello)->not->toContain('Missing-tests mode');
    expect($donatello)->not->toContain('Tests done (missing)');
});
