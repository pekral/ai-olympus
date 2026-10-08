<?php

declare(strict_types = 1);

test('a run that needs a CI result reads the checks every 3 minutes until none is pending', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $rule = (string) file_get_contents($packageDir . '/rules/git/pull-requests.md');
    $merge = (string) file_get_contents($packageDir . '/skills/merge-github-pr/SKILL.md');

    expect($rule)->toContain("\n### Waiting for CI — read the checks every 3 minutes\n")
        ->and($rule)->toContain('`gh pr checks <PR-URL> --json name,bucket`, then `sleep 180`')
        ->and($rule)->toContain('While a check is still pending, read again after the next interval; there is no time limit.')
        ->and($rule)->toContain('**A failed read is retried after the next interval**, never a stop.')
        ->and($merge)->toContain(
            'A check that is still pending is waited for per `@rules/git/pull-requests.md` *Waiting for CI — read the checks every 3 minutes*',
        );
});
