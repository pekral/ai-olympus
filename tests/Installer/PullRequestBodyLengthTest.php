<?php

declare(strict_types = 1);

test('an agent-opened pull-request body is capped at 6,500 characters before every publish', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $rule = (string) file_get_contents($packageDir . '/rules/git/pull-requests.md');
    $reference = (string) file_get_contents($packageDir . '/skills/resolve-issue/references/pull-request.md');
    $template = (string) file_get_contents($packageDir . '/skills/resolve-issue/templates/pull-request-body.md');
    $composer = (string) file_get_contents($packageDir . '/skills/composer-update-pr/SKILL.md');

    expect($rule)->toContain('**Keep the body at most 6,500 characters.** This is a hard limit')
        ->and($rule)->toContain('[ "$(LC_ALL=en_US.UTF-8 wc -m < <body-file>)" -le 6500 ]')
        ->and($rule)->toContain('A body over the limit is never published.')
        ->and($rule)->toContain('keep every section of its template, their order, and every required value')
        ->and($rule)->toContain('no truncation in the middle of a sentence');
    expect($reference)->toContain('Before `gh pr create`, and before every `gh pr edit` that rewrites the body, check the length limit')
        ->and($reference)->toContain('never publish a body that fails it');
    expect($template)->toContain('The whole body is at most 6,500 characters');
    expect($composer)->toContain('The body keeps the 6,500-character limit of `@rules/git/pull-requests.md` *PR Content Requirements*');
});
