<?php

declare(strict_types = 1);

test('report-code-review runs one review-only pass through splinter and leonardo', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $command = (string) file_get_contents($packageDir . '/commands/report-code-review.md');
    $splinter = (string) file_get_contents($packageDir . '/agents/splinter.md');
    $leonardo = (string) file_get_contents($packageDir . '/agents/leonardo.md');

    expect($command)->toContain('$ARGUMENTS');
    expect($command)->toContain('review-only mode');
    expect($command)->toContain('Do not fix anything in the code under review.');
    expect($command)->toContain('**exactly one commit**');
    expect($command)->toContain('It never
changes the business logic of the change under review');
    expect($command)->toContain('Always review the current code of the pull request branch.');
    expect($command)->toContain('confirms that local `HEAD` equals the pull request\'s head SHA');
    expect($command)->toContain('$code-review-jira');
    expect($command)->toContain('call the publish helper with `--create`');

    expect($splinter)->toContain('## Review-only mode — `/report-code-review`');
    expect($splinter)->toContain('`Review report done`');
    expect($splinter)->toContain('dispatch `donatello` once, blocking, in *Missing-tests mode*');
    expect((string) file_get_contents($packageDir . '/agents/donatello.md'))
        ->toContain('## Missing-tests mode — `/report-code-review`')
        ->toContain('**Commit and push exactly one commit.**')
        ->toContain('**Never change the business logic of the change under review.**');
    expect($leonardo)->toContain('**When the dispatch carries `review_only`**');
    expect($leonardo)->toContain('skip the step-10 fix loop');
    expect($leonardo)->toContain('Do not open a review worktree.');
    expect($splinter)->toContain('switch the current working tree to the pull request\'s head branch');
    expect($leonardo)->toContain('pass `review-only` and the published findings to `pr-summary`');
    expect((string) file_get_contents($packageDir . '/skills/pr-summary/SKILL.md'))
        ->toContain('### Review-only run — `Review findings` in place of `How to test`')
        ->toContain('Pass `--create` as the first argument of the GitHub and JIRA publish helpers');
    expect((string) file_get_contents($packageDir . '/skills/code-review-github/references/cr-wrapper-contract.md'))
        ->toContain('**A review-only run always creates new comments.**');
});
