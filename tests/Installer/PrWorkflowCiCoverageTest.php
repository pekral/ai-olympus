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

test('every npx call in the composer scripts names an exact package version, so CI never runs an unreviewed release', function (): void {
    $packageDir = dirname(__DIR__, 2);
    /** @var array{scripts: array<string, string|list<string>>} $composer */
    $composer = json_decode((string) file_get_contents($packageDir . '/composer.json'), associative: true, flags: JSON_THROW_ON_ERROR);
    $npxCalls = 0;

    foreach ($composer['scripts'] as $script => $commands) {
        foreach ((array) $commands as $command) {
            if (preg_match_all('/\bnpx\b((?:\s+-{1,2}[\w-]+)*)\s+(\S+)/', $command, $matches, PREG_SET_ORDER) === 0) {
                continue;
            }

            foreach ($matches as $match) {
                $npxCalls++;

                expect($match[2])->toMatch(
                    '/^(@[\w.-]+\/)?[\w.-]+@\d+\.\d+\.\d+$/',
                    sprintf('composer script "%s" runs npx without an exact version: %s', $script, $command),
                );
            }
        }
    }

    expect($npxCalls)->toBeGreaterThan(0);
});
