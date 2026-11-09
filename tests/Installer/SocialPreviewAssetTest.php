<?php

declare(strict_types = 1);

/**
 * Issue #193 — the social-preview asset still advertised `argos`, an agent removed in #179, and
 * never gained `april`. Every other surface that names the roster (`README.md`, `docs/agents.md`,
 * the rules, the issue template) was swept in lockstep; this one asset was missed because nothing
 * derived its content from `agents/*.md`.
 *
 * These tests close that gap: the portrait labels are checked against the live roster, and the rendered
 * PNG is bound to the SVG it came from so a future edit cannot ship the vector without the raster.
 */
test('the social preview shows each available portrait and includes every live agent (issue #193)', function (): void {
    $packageDir = dirname(__DIR__, 2);

    $globResult = glob($packageDir . '/agents/*.md');
    $agentFiles = $globResult !== false ? $globResult : [];
    $liveRoster = array_map(static fn (string $path): string => basename($path, '.md'), $agentFiles);
    $globResult = glob($packageDir . '/assets/agents/*.png');
    $portraitFiles = $globResult !== false ? $globResult : [];
    $portraits = array_map(static fn (string $path): string => basename($path, '.png'), $portraitFiles);
    $svg = (string) file_get_contents($packageDir . '/assets/social-preview.svg');

    preg_match_all('/<text data-agent="[a-z-]+"[^>]*>([a-z-]+)<\/text>/', $svg, $labels);
    preg_match_all('/<image [^>]*xlink:href="agents\/([a-z-]+)\.png"/', $svg, $images);

    expect($liveRoster)->not->toBeEmpty()
        ->and(array_diff($liveRoster, $portraits))->toBeEmpty()
        ->and($labels[1])->toEqualCanonicalizing($portraits)
        ->and($images[1])->toEqualCanonicalizing($portraits);
});

test('the rendered PNG is bound to the SVG it was rendered from (issue #193)', function (): void {
    $packageDir = dirname(__DIR__, 2);

    // A raster cannot be derived from its source at test time without a renderer in CI, so the
    // binding is a digest pair instead. Both digests are updated together, by exactly one
    // procedure: edit the SVG, run
    //
    //     rsvg-convert -w 1280 -h 640 assets/social-preview.svg -o assets/social-preview.png
    //
    // then paste both new digests here. Changing the SVG alone fails this test, which is the
    // failure mode issue #193 reports — the vector was corrected and the raster never re-rendered.
    // The SVG is hashed with its line endings normalised to LF. The archive-only
    // `.gitattributes` does not set line endings, so a Windows checkout with
    // `core.autocrlf=true` can write CRLF into text files — a raw `hash_file()` would then
    // fail there on a byte the contributor never touched. The PNG needs no such treatment: git
    // detects it as binary and converts nothing.
    $svg = (string) file_get_contents($packageDir . '/assets/social-preview.svg');

    expect(hash('sha256', str_replace("\r\n", "\n", $svg)))
        ->toBe('63a0c6ac9b83394a2d9671fffb7ff9f62351103c63a95a1d9c23e504428ec3f9');

    expect(hash_file('sha256', $packageDir . '/assets/social-preview.png'))
        ->toBe('f518af6eccaeda01087f985f3d5e7d00e2d815ec2b4ea2710e9ccd440a7016d9');

    // The dimensions GitHub renders a social preview at; a re-render at the wrong size would
    // otherwise pass the digest check the moment someone updated it without looking.
    $size = getimagesize($packageDir . '/assets/social-preview.png');

    // `getimagesize()` returns false on a file it cannot decode, so a truncated or corrupt PNG
    // fails on the empty dimensions below rather than on an offset access.
    $dimensions = $size === false ? [] : [$size[0], $size[1]];
    expect($dimensions)->toBe([1_280, 640]);
});
