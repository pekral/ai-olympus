<?php

declare(strict_types = 1);

test('an agent-opened pull-request body is capped at 6,500 characters before every publish', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $rule = (string) file_get_contents($packageDir . '/rules/git/pull-requests.md');
    $reference = (string) file_get_contents($packageDir . '/skills/resolve-issue/references/pull-request.md');
    $template = (string) file_get_contents($packageDir . '/skills/resolve-issue/templates/pull-request-body.md');
    $composer = (string) file_get_contents($packageDir . '/skills/composer-update-pr/SKILL.md');

    expect($rule)->toContain('**Keep the body at most 6,500 characters.** This is a hard limit')
        ->and($rule)->toContain('The check above counts the body in code points, so the count does not depend on the host locale.')
        ->and($rule)->toContain('A body over the limit is never published.')
        ->and($rule)->toContain('keep every section of its template, their order, and every required value')
        ->and($rule)->toContain('no truncation in the middle of a sentence');
    expect($reference)->toContain(
        'Before `gh pr create`, and before every `gh pr edit` that rewrites the body, run '
        . '`skills/_shared/check-pr-body.sh --file <body-file>` as `@rules/git/pull-requests.md` *PR Content Requirements* directs',
    )
        ->and($reference)->toContain('never publish a body that fails it');
    expect($template)->toContain('The whole body is at most 6,500 characters');
    expect($composer)->toContain('checks the body against this template before `gh pr create`');
});

test('the body check is the one owner of the count, and no rule or skill counts the body itself', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $script = (string) file_get_contents($packageDir . '/skills/_shared/check-pr-body.sh');

    expect($script)->toContain('MAX_CHARS=6500')
        ->and($script)->toContain('LC_ALL=C tr -d \'\\200-\\277\' | LC_ALL=C wc -c');

    $handRolled = 'mb_strlen((string) file_get_contents($argv[1]))';
    $countsItself = static fn (string $content, string $path): bool => (str_starts_with($path, 'rules/') || str_starts_with($path, 'skills/'))
        && str_ends_with($path, '.md')
        && str_contains($content, $handRolled);

    expect(array_keys(array_filter(packageTextFiles(), $countsItself, ARRAY_FILTER_USE_BOTH)))->toBe([]);
});

test('verbatim audit, security, and package content moves to an overflow comment instead of being shortened', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $rule = (string) file_get_contents($packageDir . '/rules/git/pull-requests.md');
    $reference = (string) file_get_contents($packageDir . '/skills/resolve-issue/references/pull-request.md');

    $verbatim = '**Verbatim content never shrinks; it moves to an overflow comment.** Shortening never rewrites, merges, or drops '
        . 'a line of the Audit block, its incompleteness statement, an item of the Security acceptance checklist, '
        . 'or a package row of a dependency-update body.';
    $publish = '`skills/code-review-github/scripts/upsert-comment.sh <PR-URL> <file> agent-note`, '
        . 'headed `## Pull-request body overflow`';
    $section = 'the count per event class and every distinct host and external-write target), '
        . 'the incompleteness statement verbatim, and a pointer to that comment';

    expect($rule)->toContain($verbatim)
        ->and($rule)->toContain('move that content unchanged into one comment published on the pull request after the body')
        ->and($rule)->toContain($publish)
        ->and($rule)->toContain($section);
    expect($reference)->toContain(
        'the transcript moves unchanged to the overflow comment of `@rules/git/pull-requests.md` *PR Content Requirements*',
    );
});

test('the overflow comment is a durable copy that merge preparation never deletes', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $rule = (string) file_get_contents($packageDir . '/rules/git/pull-requests.md');
    $skill = (string) file_get_contents($packageDir . '/skills/verify-merge-readiness/SKILL.md');
    $helper = (string) file_get_contents($packageDir . '/skills/_shared/delete-owned-github-comment.sh');
    $reports = (string) file_get_contents($packageDir . '/rules/reports/general.md');

    expect($rule)->toContain('It is the durable copy of that content, so merge preparation never deletes it.')
        ->and($skill)->toContain(
            'A `## Pull-request body overflow` comment never enters the manifest: '
            . 'it holds the durable copy of the pull-request body\'s verbatim content',
        )
        ->and($skill)->toContain('and the delete helper refuses it.')
        ->and($helper)->toContain('refusing to delete a pull-request body overflow comment');
    expect($reports)->toContain('whose description carries them under the length limit of `@rules/git/pull-requests.md` *PR Content Requirements*.')
        ->and($reports)->not->toContain('has no length cap');
});
