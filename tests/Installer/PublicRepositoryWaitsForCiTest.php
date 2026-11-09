<?php

declare(strict_types = 1);

test('a public github repository waits for ci instead of running a local pre-push gate', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $gates = (string) file_get_contents($packageDir . '/skills/resolve-issue/references/quality-gates.md');
    $manifest = (string) file_get_contents($packageDir . '/rules/general/general.md');

    expect($gates)->toContain('### A public repository waits for CI instead of a local pre-push gate')
        ->and($gates)->toContain('**Read the visibility once per run:** `gh repo view --json isPrivate`.')
        ->and($gates)->toContain('When the call fails, treat the repository as private')
        ->and($gates)->toContain('Do not run `pr-gate` before a push, whatever the CI result on the previous head.')
        ->and($gates)->toContain('**Push, then wait for CI on the pushed head:** confirm that')
        ->and($gates)->toContain('then run `gh pr checks <PR> --watch`.')
        ->and($gates)->toContain('`pr-gate: skipped — public repository, CI <green | red> on <sha>`');
    expect($manifest)->toContain('On a public GitHub repository it never runs: the run waits for CI instead');
    expect($gates)->toContain('**No CI signal on the pushed head**')
        ->and($gates)->toContain('confirm that the pull request\'s `headRefOid` equals `git rev-parse HEAD`')
        ->and(strpos($gates, '### A public repository waits for CI'))->toBeGreaterThan(
            (int) strpos($gates, 'The rule is one full build at the merge boundary'),
        );
    expect((string) file_get_contents($packageDir . '/AGENTS.md'))
        ->toContain('On a repository that is public on GitHub no local pre-push gate runs');
});
