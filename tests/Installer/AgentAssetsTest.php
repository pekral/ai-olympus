<?php

declare(strict_types = 1);

test('the avatar manifest gives every live agent a complete portable portrait and thumbnail', function (): void {
    $package = dirname(__DIR__, 2);
    $assets = $package . '/assets/agents';
    /** @var array{stylesheet: string, preview: string, agents: list<array{id: string, displayName: string, portrait: string, thumbnail: string}>} $manifest */
    $manifest = json_decode((string) file_get_contents($assets . '/manifest.json'), associative: true, flags: JSON_THROW_ON_ERROR);
    $definitions = glob($package . '/agents/*.md');
    assert(is_array($definitions));

    expect(array_column($manifest['agents'], 'id'))->toEqualCanonicalizing(
        array_map(static fn (string $file): string => basename($file, '.md'), $definitions),
    );
    expect(is_file($assets . '/' . $manifest['stylesheet']))->toBeTrue();
    expect(is_file($assets . '/' . $manifest['preview']))->toBeTrue();

    foreach ($manifest['agents'] as $agent) {
        expect($agent['displayName'])->not->toBeEmpty();

        foreach (['portrait' => 1_254, 'thumbnail' => 160] as $field => $dimension) {
            $path = $assets . '/' . $agent[$field];
            expect(is_file($path))->toBeTrue();
            $size = getimagesize($path);
            assert(is_array($size));

            expect([$size[0], $size[1], $size[2]])->toBe([$dimension, $dimension, IMAGETYPE_PNG]);
            // PNG IHDR colour type 6 retains alpha for the light and dark Cockpit surfaces.
            expect(ord(((string) file_get_contents($path))[25]))->toBe(6);
        }
    }
});

test('distribution exclusions keep the agent assets available without shipping the banner artwork', function (): void {
    $package = dirname(__DIR__, 2);
    /** @var array{archive: array{exclude: list<string>}} $composer */
    $composer = json_decode((string) file_get_contents($package . '/composer.json'), associative: true, flags: JSON_THROW_ON_ERROR);
    $attributes = (string) file_get_contents($package . '/.gitattributes');

    expect($composer['archive']['exclude'])->not->toContain('/assets', '/assets/agents');
    expect($composer['archive']['exclude'])->toContain('/assets/social-preview.png', '/assets/logo.png', '/assets/brands');
    expect($attributes)->not->toMatch('~^/?assets(?:/agents)?/?\s+export-ignore~m');
    expect($attributes)->toContain('/assets/social-preview.png export-ignore', '/assets/logo.png export-ignore', '/assets/brands export-ignore');
});
