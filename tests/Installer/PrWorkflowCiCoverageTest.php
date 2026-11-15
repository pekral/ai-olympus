<?php

declare(strict_types = 1);

test('CI runs composer-normalize-check, shell-self-tests, and skill-check, so a green CI covers composer check', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $workflow = (string) file_get_contents($packageDir . '/.github/workflows/pr.yml');

    expect($workflow)->toContain('composer composer-normalize-check');
    expect($workflow)->toContain('composer shell-self-tests');
    expect($workflow)->toContain('composer skill-check');
    expect($workflow)->toContain('uses: actions/setup-node@v4');
});
