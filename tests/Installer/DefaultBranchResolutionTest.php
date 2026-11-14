<?php

declare(strict_types = 1);

test('the installation guide names the repair command for an unset default branch', function (): void {
    $installation = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/installation.md');

    expect($installation)
        ->toContain('The default branch is the branch `refs/remotes/origin/HEAD` names, and never a guessed `main` or `master`.')
        ->toContain('run `git remote set-head origin --auto` once in the project.');
});
