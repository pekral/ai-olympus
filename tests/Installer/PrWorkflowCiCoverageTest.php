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

test('skill-check is pinned to an exact version and locked with an integrity hash for every package', function (): void {
    $packageDir = dirname(__DIR__, 2);
    /** @var array{private: bool, scripts?: array<string, string>, devDependencies: array<string, string>} $package */
    $package = json_decode((string) file_get_contents($packageDir . '/package.json'), associative: true, flags: JSON_THROW_ON_ERROR);
    /** @var array{lockfileVersion: int, packages: array<string, array<string, string>>} $lock */
    $lock = json_decode((string) file_get_contents($packageDir . '/package-lock.json'), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($package['private'])->toBeTrue();
    expect($package)->not->toHaveKey('scripts');
    expect($package['devDependencies']['skill-check'])->toBe('1.2.0');
    expect($lock['lockfileVersion'])->toBeGreaterThanOrEqual(2);
    expect($lock['packages']['node_modules/skill-check']['version'])->toBe('1.2.0');

    $locked = array_filter($lock['packages'], static fn (string $path): bool => $path !== '', ARRAY_FILTER_USE_KEY);

    expect($locked)->not->toBeEmpty();
    expect($locked)->each->toHaveKeys(['resolved', 'integrity']);
});

test('the composer skill-check scripts install the locked tree and run the local binary, never npx', function (): void {
    $packageDir = dirname(__DIR__, 2);
    /** @var array{scripts: array<string, string|list<string>>} $composer */
    $composer = json_decode((string) file_get_contents($packageDir . '/composer.json'), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($composer['scripts']['skill-check-install'])->toBe('npm ci --ignore-scripts');

    foreach (['skill-check', 'skill-check-fix'] as $script) {
        $commands = (array) $composer['scripts'][$script];

        expect($commands)->toContain('@skill-check-install')->not->toContain('npm ci --ignore-scripts');
        $joined = implode("\n", $commands);

        expect($joined)->toContain('node_modules/.bin/skill-check check skills');
        expect($joined)->not->toContain('npx');
    }
});

test('node_modules is git-ignored and the npm manifests are left out of the Composer archive and the GitHub export', function (): void {
    $packageDir = dirname(__DIR__, 2);
    /** @var array{archive: array{exclude: list<string>}} $composer */
    $composer = json_decode((string) file_get_contents($packageDir . '/composer.json'), associative: true, flags: JSON_THROW_ON_ERROR);
    $ignored = preg_split('/\R/', (string) file_get_contents($packageDir . '/.gitignore'));
    $exportIgnored = preg_split('/\R/', (string) file_get_contents($packageDir . '/.gitattributes'));

    expect($ignored)->toContain('/node_modules/');
    expect($exportIgnored)->toContain('/package.json export-ignore')->toContain('/package-lock.json export-ignore');
    expect($composer['archive']['exclude'])->toContain('/node_modules')->toContain('/package.json')->toContain('/package-lock.json');
});

test('the CI skill-check job reuses the composer script and caches npm on the lockfile', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $workflow = (string) file_get_contents($packageDir . '/.github/workflows/pr.yml');

    expect($workflow)->toContain('cache: npm');
    expect($workflow)->toContain('cache-dependency-path: package-lock.json');
    expect($workflow)->not->toContain('npx');
});
