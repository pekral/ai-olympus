<?php

declare(strict_types = 1);

test('distribution exclusions keep this repository\'s X style record out of the package archive', function (): void {
    $package = dirname(__DIR__, 2);
    /** @var array{archive: array{exclude: list<string>}} $composer */
    $composer = json_decode((string) file_get_contents($package . '/composer.json'), associative: true, flags: JSON_THROW_ON_ERROR);
    $exportIgnored = preg_split('/\R/', (string) file_get_contents($package . '/.gitattributes'));

    // `.x-brand-writing/style.md` holds the preferences of this repository only. A consuming
    // project keeps its own record, so neither archive path may ship this one.
    expect($composer['archive']['exclude'])->toContain('/.x-brand-writing');
    expect($exportIgnored)->toContain('/.x-brand-writing export-ignore');
});
