<?php

declare(strict_types = 1);

test('report-code-review runs one review-only pass through krang, leonardo, and splinter', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $command = (string) file_get_contents($packageDir . '/commands/report-code-review.md');
    $krang = (string) file_get_contents($packageDir . '/agents/krang.md');
    $leonardo = (string) file_get_contents($packageDir . '/agents/leonardo.md');

    expect($command)->toContain('$ARGUMENTS');
    expect($command)->toContain('review-only mode');
    expect($command)->toContain('$code-review-jira');
    expect($command)->toContain('`agents/krang.md` *Review-only mode — `/report-code-review`* is the one owner of this run.');
    expect($command)->toContain('Do not restate, shorten, or
extend those steps in the dispatch prompt');
    expect($command)->not->toContain('dispatches `raphael`');
    expect($command)->not->toContain('dispatches `leonardo`');
    expect($command)->not->toContain('Missing-tests mode');
    expect($command)->not->toContain('SECURITY_OWNER');

    expect($krang)->toContain(
        'This section is the one owner of its steps: `commands/report-code-review.md` only validates the reference and hands it here',
    );
    expect($krang)->toContain('It changes nothing in the pull request: no fix and no test.');
    expect($leonardo)->toContain('The branch checkout gate takes its worktree case there');
    expect($leonardo)->toContain('Tell the wrapper to update the comments an earlier run left, never to pass `--create`.');
    expect($leonardo)->toContain('When the dispatch carries `tracker-report: deferred`, pass it to `process-code-review`');
    expect((string) file_get_contents($packageDir . '/skills/process-code-review/SKILL.md'))
        ->toContain('When the caller passed `tracker-report: deferred`, pass it to the wrapper too');
    expect((string) file_get_contents($packageDir . '/skills/code-review-jira/SKILL.md'))
        ->toContain('With `tracker-report: deferred`, skip this section and the linked-GitHub-issue mirror below');
    expect($krang)->toContain(
        'When the brief\'s `## Source` carries a GitHub issue URL, a GitHub PR URL, or a JIRA key, state `tracker-report: deferred`.',
    );
    expect($krang)->toContain('rewritten in the brief\'s `## Language` and in the target tracker\'s format');
    expect((string) file_get_contents($packageDir . '/skills/code-review-github/scripts/upsert-comment.sh'))
        ->toContain('No packaged workflow');
    expect($leonardo)->toContain('then run the step-3 wrapper once with `SECURITY_OWNER=splinter`');
    expect($leonardo)->toContain('its findings land in the one review you publish: never a separate security comment.');

    expect($krang)->toContain('## Review-only mode — `/report-code-review`');
    expect($krang)->toContain('`Review report done`');
    expect($krang)->toContain('A missing test is a finding for the author of the changes, never a commit of this run');
    expect($krang)->toContain('Dispatch no `donatello`, no other `raphael`, and no `april`');
    expect($krang)->not->toContain('*Missing-tests mode*');
    expect($krang)->not->toContain('missing-tests commit');
    expect($krang)->not->toContain('(missing tests)');
    expect((string) file_get_contents($packageDir . '/agents/donatello.md'))
        ->not->toContain('## Missing-tests mode');
    expect($leonardo)->toContain('**When the dispatch carries `review_only`**');
    expect($leonardo)->toContain('skip the step-10 fix loop');
    expect($leonardo)->toContain('review the pull request\'s head SHA in your review worktree, because `raphael` owns the main working tree');
    expect($leonardo)->not->toContain('@skills/security-review/SKILL.md');
    expect($krang)->toContain(
        '`splinter` runs every security skill over the current diff of the pull request, so the security review runs on this run too.',
    );
    expect($krang)->toContain('It owns the main working tree for the round: it runs `@skills/code-review/references/branch-checkout-gate.md`');
    expect($leonardo)->toContain('pass `review-only` and the published findings to `pr-summary`');
    $prSummary = (string) file_get_contents($packageDir . '/skills/pr-summary/SKILL.md');
    expect($prSummary)
        ->toContain('### Review-only run — `Review findings` in place of `How to test`')
        ->toContain('**One comment, updated in place.** A review-only run publishes through the GitHub and JIRA helpers like any other run');
    expect($prSummary)->not->toContain('Pass `--create`');
    expect((string) file_get_contents($packageDir . '/skills/code-review-github/references/cr-wrapper-contract.md'))
        ->toContain('**A review-only run updates its comments in place too.**')
        ->toContain('**A deferred tracker report — `tracker-report: deferred`.**')
        ->toContain('after the `prepare-issue-context` pre-flight has returned, `security-review` may run in parallel')
        ->toContain('as parallel tool calls in this same context, never as a subagent.');
    expect((string) file_get_contents($packageDir . '/skills/code-review/references/specialized-reviews.md'))
        ->toContain('after the `prepare-issue-context` pre-flight has returned, `security-review` may run in parallel')
        ->toContain(
            'Without `SECURITY_OWNER` it still runs exactly once over the current diff, and its findings land in the one review comment of this run.',
        );
});

test('security is the most important part of every code review', function (): void {
    $packageDir = dirname(__DIR__, 2);

    expect((string) file_get_contents($packageDir . '/rules/code-review/general.md'))
        ->toContain('## Security is the most important part of every review')
        ->toContain('Security ranks above every other check of a review, DRY included.')
        ->toContain('A `FAST`-tier run has no code review, so it has no security review either')
        ->toContain('it shortens another lens and never a security lens')
        ->toContain('In each severity list of the published review, the security findings come first.');
    expect((string) file_get_contents($packageDir . '/rules/code-review/general.md'))
        ->toContain('- **One owner: `splinter`.**');
    expect((string) file_get_contents($packageDir . '/agents/leonardo.md'))
        ->toContain('**Security ownership (`@rules/code-review/general.md` *Security is the most important part of every review*):**');
    expect((string) file_get_contents($packageDir . '/agents/splinter.md'))
        ->toContain('**Security is the most important part of every review**');
});

test('report-code-review adds the interactive-testing result to the review report', function (): void {
    $packageDir = dirname(__DIR__, 2);

    expect((string) file_get_contents($packageDir . '/agents/krang.md'))
        ->toContain('Then run one *Parallel CR round* with `review_only` in all four dispatch prompts.')
        ->toContain(
            'It publishes the one review report, with `splinter`\'s findings and candidate verdicts, '
            . 'with the `interactive-testing` result and the MySQL report in it',
        );
    expect((string) file_get_contents($packageDir . '/agents/leonardo.md'))
        ->toContain('pass the `raphael` walkthrough report from the brief to the wrapper');
    expect((string) file_get_contents($packageDir . '/skills/code-review-github/references/cr-wrapper-contract.md'))
        ->toContain('Render `## Interactive testing` from the `raphael` report the caller passes');
    expect((string) file_get_contents($packageDir . '/skills/interactive-testing/SKILL.md'))
        ->toContain('A review-only caller (`/report-code-review`) fixes nothing: it requests no repair')
        ->toContain('4. Repair nothing on your own initiative.');
});

test('report-code-review runs leonardo, splinter, raphael, and casey in one parallel round', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $krang = (string) file_get_contents($packageDir . '/agents/krang.md');

    expect($krang)
        ->toContain(
            '`leonardo ∥ splinter ∥ raphael ∥ casey (review-only) → '
            . 'splinter (security candidates, when the `raphael` report carries any) → leonardo (publish)`',
        )
        ->toContain('When the `raphael` report carries security candidates, dispatch `splinter` once more with `review_only` and those candidates')
        ->toContain('Then dispatch `leonardo` with `publish: now` and `review_only`');
    expect((string) file_get_contents($packageDir . '/skills/code-review/references/specialized-reviews.md'))
        ->toContain('`agents/krang.md` dispatches `casey` in every *Parallel CR round*, beside `leonardo`, `splinter`, and `raphael`')
        ->toContain('A review-only run (`/report-code-review`) is one such round.');
    expect((string) file_get_contents($packageDir . '/agents/casey.md'))->not->toContain('--create');
});
