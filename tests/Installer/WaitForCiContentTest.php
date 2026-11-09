<?php

declare(strict_types = 1);

test('a run that needs a CI result reads the checks every 3 minutes until none is pending', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $rule = (string) file_get_contents($packageDir . '/rules/git/pull-requests.md');
    $merge = (string) file_get_contents($packageDir . '/skills/merge-github-pr/SKILL.md');

    expect($rule)->toContain("\n### Waiting for CI — read the checks every 3 minutes\n")
        ->and($rule)->toContain('`gh pr checks <PR-URL> --json name,bucket`, then `sleep 180`')
        ->and($rule)->toContain('While a check is still pending, read again after the next interval; there is no time limit.')
        ->and($rule)->toContain('**Three failed reads in a row end the wait.**')
        ->and($rule)->toContain('**A step with a local substitute does not wait.**')
        ->and($merge)->toContain(
            'the one exception is the wait read in `@rules/git/pull-requests.md` *Waiting for CI — read the checks every 3 minutes*',
        )
        ->and($merge)->toContain(
            'An entry whose `state` is `PENDING`, `EXPECTED`, `QUEUED`, `IN_PROGRESS`, `WAITING`, or `REQUESTED` is still pending',
        );
});
