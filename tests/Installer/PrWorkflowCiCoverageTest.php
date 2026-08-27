<?php

declare(strict_types = 1);

// `composer-normalize-check` and `shell-self-tests` run locally via `composer check` but were
// never wired into the PR workflow, so a green CI run was not proof either gate had run. This
// pins their presence so the gap does not silently reopen.

test('CI runs composer-normalize-check and shell-self-tests, so a green CI is not misleading', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $workflow = (string) file_get_contents($packageDir . '/.github/workflows/pr.yml');

    expect($workflow)->toContain('composer composer-normalize-check');
    expect($workflow)->toContain('composer shell-self-tests');

    // skill-check needs a Node setup step CI does not provide — it stays out of scope.
    expect($workflow)->not->toContain('composer skill-check');
});
