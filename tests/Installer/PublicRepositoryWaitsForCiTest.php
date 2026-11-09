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
        ->and($gates)->toContain('**Push, and let CI be the check.**')
        ->and($gates)->toContain('per `@rules/git/pull-requests.md` *Waiting for CI — read the checks every 3 minutes*')
        ->and($gates)->toContain('On a private repository, or when the visibility read fails')
        ->and($gates)->toContain(
            'except as *Skip the pre-push gate when CI is green* and *A public repository waits for CI instead of a local pre-push gate* state.',
        )
        ->and($gates)->toContain('`pr-gate: skipped — public repository, CI <green | red> on <sha>`');
    expect($manifest)->toContain('On a public GitHub repository it never runs: the run waits for CI instead');
    expect(strpos($gates, '### A public repository waits for CI'))
        ->toBeGreaterThan((int) strpos($gates, 'The rule is one full build at the merge boundary'))
        ->toBeLessThan((int) strpos($gates, '## Machine gate record'));
    expect((string) file_get_contents($packageDir . '/agents/donatello.md'))
        ->toContain('On a repository that is public on GitHub, never run it: push and let CI be the check');
    $donatello = (string) file_get_contents($packageDir . '/agents/donatello.md');
    expect($donatello)->toContain('this repository\'s `composer build`, run only to correct an error a checker or CI reported');
    expect($donatello)->not->toContain('tells you to run before a push');
    expect((string) file_get_contents($packageDir . '/CLAUDE.md'))->toContain('It is never required before a push');
    expect($gates)->toContain('A code review keeps its own rule there and does not wait.')
        ->and($gates)->toContain('This section changes only the pre-push gate.');
    expect((string) file_get_contents($packageDir . '/AGENTS.md'))
        ->toContain('On a repository that is public on GitHub no local pre-push gate runs');
});
