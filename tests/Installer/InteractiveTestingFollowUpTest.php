<?php

declare(strict_types = 1);

test('the merge skill files an interactive-testing follow-up issue only for a front-end UI change', function (): void {
    $merge = (string) file_get_contents(dirname(__DIR__, 2) . '/skills/merge-github-pr/SKILL.md');
    $reference = (string) file_get_contents(dirname(__DIR__, 2) . '/skills/merge-github-pr/references/interactive-testing-follow-up.md');

    expect($merge)->toContain('**Interactive-testing follow-up issue — only for a front-end UI change.**')
        ->toContain('`@skills/merge-github-pr/references/interactive-testing-follow-up.md`')
        ->toContain('`skipped, no front-end UI change`');

    expect($reference)->toContain('Create that issue only when the merged diff changes the front-end UI.')
        ->toContain('`interactive-testing: skipped, no front-end UI change`')
        ->toContain('`@skills/code-review/references/specialized-reviews.md` *Frontend surface detected in the diff*')
        ->toContain('tests (`tests/**`, a browser test included)')
        ->toContain('**A file on both lists is not a front-end UI change,**')
        ->toContain('**Read the file list from `files[]`** of the `skills/code-review-github/scripts/load-issue.sh` document')
        ->toContain('`@skills/create-issue/SKILL.md`')
        ->toContain('`external-write`')
        ->toContain('**Apply the `interactive-testing` label** in addition to the content label `create-issue` selects')
        ->toContain('`interactive-testing label missing`');

    expect($reference)->not->toContain('gh pr view');
    expect($reference)->not->toContain('gh issue create');
});

test('the frontend surface the follow-up step reuses still exists under its name', function (): void {
    $surface = (string) file_get_contents(dirname(__DIR__, 2) . '/skills/code-review/references/specialized-reviews.md');

    expect($surface)->toContain('**Frontend surface detected in the diff');
});
