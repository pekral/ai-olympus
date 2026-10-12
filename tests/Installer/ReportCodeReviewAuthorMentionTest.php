<?php

declare(strict_types = 1);

test('a review-only run mentions the author of the changes on each missing-test and DRY finding (issue #175)', function (): void {
    $packageDir = dirname(__DIR__, 2);

    expect((string) file_get_contents($packageDir . '/rules/code-review/general.md'))
        ->toContain('- **Second exception: a missing test or a DRY finding in a review-only run.**')
        ->toContain('2. a test the run\'s missing-tests commit had to add, because the change shipped without it;')
        ->toContain('Every other finding, and every run that is not review-only, names no author.');

    expect((string) file_get_contents($packageDir . '/skills/code-review-github/templates/pr-comment-output.md'))
        ->toContain('- **Author:** `@<login>` — the pull request\'s `author.login`.')
        ->toContain('## Missing tests added')
        ->toContain('Render it on a run with no finding too.');

    expect((string) file_get_contents($packageDir . '/skills/code-review-github/references/cr-wrapper-contract.md'))
        ->toContain('mention the author of the changes on each missing-test and DRY finding')
        ->toContain('`## Missing tests added` on a review-only run whose missing-tests commit added a test,');

    expect((string) file_get_contents($packageDir . '/skills/pr-summary/SKILL.md'))
        ->toContain('End the bullet that retells a missing-test or a DRY finding with the author mention')
        ->toContain('When the review found nothing and the missing-tests commit added no test, render one sentence that says so, and no bullets.');

    expect((string) file_get_contents($packageDir . '/agents/leonardo.md'))
        ->toContain('Pass the `donatello` missing-tests handoff to the wrapper');

    expect((string) file_get_contents($packageDir . '/agents/donatello.md'))
        ->toContain('the test files, the behaviour each one protects, and the result of the tests it ran');

    expect((string) file_get_contents($packageDir . '/commands/report-code-review.md'))
        ->toContain('the report mentions the author of the changes');
});
