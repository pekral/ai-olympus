<?php

declare(strict_types = 1);

test('report-code-review runs one review-only pass through splinter and leonardo', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $command = (string) file_get_contents($packageDir . '/commands/report-code-review.md');
    $splinter = (string) file_get_contents($packageDir . '/agents/splinter.md');
    $leonardo = (string) file_get_contents($packageDir . '/agents/leonardo.md');

    expect($command)->toContain('$ARGUMENTS');
    expect($command)->toContain('review-only mode');
    expect($command)->toContain('Do not fix anything.');
    expect($command)->toContain('$code-review-jira');

    expect($splinter)->toContain('## Review-only mode — `/report-code-review`');
    expect($splinter)->toContain('`Review report done`');
    expect($leonardo)->toContain('**When the dispatch carries `review_only`**');
    expect($leonardo)->toContain('skip the step-10 fix loop');
    expect($leonardo)->toContain('pass `without How to test` to `pr-summary`');
    expect((string) file_get_contents($packageDir . '/skills/pr-summary/SKILL.md'))
        ->toContain('- **A review-only run omits the section.** When the caller passes `without How to test`');
});
