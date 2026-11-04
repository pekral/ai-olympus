<?php

declare(strict_types = 1);

test('report-code-review runs one review-only pass through splinter and leonardo', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $command = (string) file_get_contents($packageDir . '/commands/report-code-review.md');
    $splinter = (string) file_get_contents($packageDir . '/agents/splinter.md');
    $leonardo = (string) file_get_contents($packageDir . '/agents/leonardo.md');

    expect($command)->toContain('$ARGUMENTS');
    expect($command)->toContain('review-only mode');
    expect($command)->toContain('Do not change anything in the pull request: no fix and no test.');
    expect($command)->toContain('The run never writes a missing test.');
    expect($command)->not->toContain('Missing-tests mode');
    expect($command)->toContain('Always review the current code of the pull request branch.');
    expect($command)->toContain('confirms that local `HEAD` equals the pull request\'s head SHA');
    expect($command)->toContain('$code-review-jira');
    expect($command)->toContain('call the publish helper with `--create`');
    expect($command)->toContain('The review always
runs `@skills/security-review/SKILL.md` over the current diff of the pull request');
    expect($command)->toContain('but it runs exactly once, and its findings always land in the one final review report');
    expect($command)->toContain('it never sets `SECURITY_OWNER` on the wrapper');

    expect($splinter)->toContain('## Review-only mode — `/report-code-review`');
    expect($splinter)->toContain('`Review report done`');
    expect($splinter)->toContain('A missing test is a finding for the author of the changes, never a commit of this run');
    expect($splinter)->toContain('Dispatch no `donatello`, no other `raphael`, and no `april`');
    expect($splinter)->not->toContain('*Missing-tests mode*');
    expect($splinter)->not->toContain('missing-tests commit');
    expect($splinter)->not->toContain('(missing tests)');
    expect((string) file_get_contents($packageDir . '/agents/donatello.md'))
        ->not->toContain('## Missing-tests mode');
    expect($leonardo)->toContain('**When the dispatch carries `review_only`**');
    expect($leonardo)->toContain('skip the step-10 fix loop');
    expect($leonardo)->toContain('Do not open a review worktree.');
    expect($leonardo)->toContain('That invocation always runs `@skills/security-review/SKILL.md` over the current diff of the pull request');
    expect($splinter)->toContain('The review always runs `@skills/security-review/SKILL.md` over the current diff of the pull request.');
    expect($splinter)->toContain('switch the current working tree to the pull request\'s head branch');
    expect($leonardo)->toContain('pass `review-only` and the published findings to `pr-summary`');
    expect((string) file_get_contents($packageDir . '/skills/pr-summary/SKILL.md'))
        ->toContain('### Review-only run — `Review findings` in place of `How to test`')
        ->toContain('Pass `--create` as the first argument of the GitHub and JIRA publish helpers');
    expect((string) file_get_contents($packageDir . '/skills/code-review-github/references/cr-wrapper-contract.md'))
        ->toContain('**A review-only run always creates new comments.**')
        ->toContain('after the `prepare-issue-context` pre-flight has returned, `security-review` may run in parallel')
        ->toContain('as parallel tool calls in this same context, never as a subagent.');
    expect((string) file_get_contents($packageDir . '/skills/code-review/references/specialized-reviews.md'))
        ->toContain('after the `prepare-issue-context` pre-flight has returned, `security-review` may run in parallel')
        ->toContain('It still runs exactly once over the current diff, and its findings land in the one review comment of this run.');
});

test('security is the most important part of every code review', function (): void {
    $packageDir = dirname(__DIR__, 2);

    expect((string) file_get_contents($packageDir . '/rules/code-review/general.md'))
        ->toContain('## Security is the most important part of every review')
        ->toContain('Security ranks above every other check of a review, DRY included.')
        ->toContain('A `FAST`-tier run has no code review, so it has no security review either')
        ->toContain('it shortens another lens and never a security lens')
        ->toContain('In each severity list of the published review, the security findings come first.');
    expect((string) file_get_contents($packageDir . '/agents/leonardo.md'))
        ->toContain('**Security agenda (the most important part of every review');
});

test('report-code-review adds the interactive-testing result to the review report', function (): void {
    $packageDir = dirname(__DIR__, 2);

    expect((string) file_get_contents($packageDir . '/commands/report-code-review.md'))
        ->toContain('First `splinter` dispatches `raphael` once.')
        ->toContain('carries the `interactive-testing` result in its own `## Interactive testing` section');
    expect((string) file_get_contents($packageDir . '/agents/splinter.md'))
        ->toContain('Then dispatch `raphael` once, blocking, with `review_only` in the dispatch prompt.')
        ->toContain('Pass the `raphael` report, so the published review carries the `interactive-testing` result.');
    expect((string) file_get_contents($packageDir . '/agents/leonardo.md'))
        ->toContain('Pass the `raphael` walkthrough report to the wrapper');
    expect((string) file_get_contents($packageDir . '/skills/code-review-github/references/cr-wrapper-contract.md'))
        ->toContain('Render `## Interactive testing` from the `raphael` report the caller passes');
    expect((string) file_get_contents($packageDir . '/skills/interactive-testing/SKILL.md'))
        ->toContain('A review-only caller (`/report-code-review`) fixes nothing: it requests no repair');
});
