<?php

declare(strict_types = 1);

/**
 * Issue #193 — the social-preview asset still advertised `argos`, an agent removed in #179, and
 * never gained `hermes`. Every other surface that names the roster (`README.md`, `docs/agents.md`,
 * the rules, the issue template) was swept in lockstep; this one asset was missed because nothing
 * derived its content from `agents/*.md`.
 *
 * These tests close that gap: the portrait labels are checked against the live roster, and the rendered
 * PNG is bound to the SVG it came from so a future edit cannot ship the vector without the raster.
 */
test('the social preview names exactly the live agent roster (issue #193)', function (): void {
    $packageDir = dirname(__DIR__, 2);

    $globResult = glob($packageDir . '/agents/*.md');
    $agentFiles = $globResult !== false ? $globResult : [];
    expect($agentFiles)->not->toBeEmpty();

    $liveRoster = array_map(static fn (string $path): string => basename($path, '.md'), $agentFiles);

    // Derived from the roster, never pinned as a literal: an agent added to or removed from
    // `agents/` must fail this test rather than leave the asset quietly stale, which is exactly
    // how `argos` survived here for two releases after it was retired.
    preg_match_all(
        '/<text data-agent="[a-z-]+"[^>]*>([a-z-]+)<\/text>/',
        (string) file_get_contents($packageDir . '/assets/social-preview.svg'),
        $matches,
    );

    expect($matches[1])->toEqualCanonicalizing($liveRoster);
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
        ->toBe('5db6981c62d6641b97c43f93a24bf0a4b20cf8259c04b648c5753f87d0174576');

    expect(hash_file('sha256', $packageDir . '/assets/social-preview.png'))
        ->toBe('dbb1a49acb57390f379b6a9e5046c699def16f1298125e4685fe7b6e7e947704');

    // The dimensions GitHub renders a social preview at; a re-render at the wrong size would
    // otherwise pass the digest check the moment someone updated it without looking.
    $size = getimagesize($packageDir . '/assets/social-preview.png');

    // `getimagesize()` returns false on a file it cannot decode, so a truncated or corrupt PNG
    // fails on the empty dimensions below rather than on an offset access.
    $dimensions = $size === false ? [] : [$size[0], $size[1]];
    expect($dimensions)->toBe([1_280, 640]);
});
