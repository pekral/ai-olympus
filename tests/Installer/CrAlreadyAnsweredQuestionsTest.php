<?php

declare(strict_types = 1);

test('the code review reads every jira and pull request comment and never re-asks an answered question', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $agent = (string) file_get_contents($packageDir . '/agents/leonardo.md');
    $walk = (string) file_get_contents($packageDir . '/skills/code-review-jira/references/clarifying-questions.md');
    $contract = (string) file_get_contents($packageDir . '/skills/code-review-github/references/cr-wrapper-contract.md');
    $rule = (string) file_get_contents($packageDir . '/rules/code-review/general.md');

    expect($agent)->toContain('**Comment agenda:** before you raise any question, read every comment on the tracker item and on the pull request.')
        ->and($agent)->toContain('A question that somebody already answered in any of these comments is never asked again');

    expect($walk)->toContain('**Sources to walk — every comment on the ticket and on the pull request.**')
        ->and($walk)->toContain(
            'its description, its conversation comments, its review summaries, and every line-anchored review thread, resolved and unresolved',
        );

    expect($contract)->toContain(
        'questions already answered in a later comment — a reply on the same thread, another comment on the PR, or a comment on the tracker item',
    );

    expect($rule)->toContain('A question that somebody already answered there is never asked again');
});
