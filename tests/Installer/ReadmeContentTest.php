<?php

declare(strict_types = 1);

test('readme shows the install command inside the first rendered screen (issue #105)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $lines = (array) file($packageDir . '/README.md');

    $firstScreen = implode('', array_slice($lines, 0, 40));

    expect($firstScreen)->toContain('composer require agentic-vibes/laravel-agent-skills --dev');
    expect($firstScreen)->toContain('vendor/bin/agent-skills install --force');
});

test('readme hero paragraph describes the product, not the installer mechanics (issue #105)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $readme = (string) file_get_contents($packageDir . '/README.md');

    $heroStart = strpos($readme, '**Laravel Agent Skills**');
    assert($heroStart !== false);
    $heroEnd = strpos($readme, "\n\n", $heroStart);
    assert($heroEnd !== false);
    $hero = substr($readme, $heroStart, $heroEnd - $heroStart);

    expect($hero)->not->toContain('composer.json');
    expect($hero)->not->toContain('symlink');
    expect($hero)->not->toContain('mirrors');
    expect($hero)->not->toContain('.claude/rules');
});

test('installation docs carry every operational section moved out of the readme (issue #105)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $docs = (string) file_get_contents($packageDir . '/docs/installation.md');

    expect($docs)->toContain('## Automatic Installation via Composer Plugin');
    expect($docs)->toContain('## Available Commands');
    expect($docs)->toContain('## Installer Flow');
    expect($docs)->toContain('## CLI Switches');

    // The installer mechanics dropped from the README hero must survive here, or the
    // "nothing is lost" contract of the move is broken.
    expect($docs)->toContain('composer.json');
    expect($docs)->toContain('symlink');
    expect($docs)->toContain('vendor/agentic-vibes/laravel-agent-skills/rules');
    expect($docs)->toContain('auto-install');
});

test('every relative markdown link in the readme and the installation docs points at a path that exists (issue #105)', function (string $document): void {
    $packageDir = dirname(__DIR__, 2);
    $source = (string) file_get_contents($packageDir . '/' . $document);
    // Relative links resolve against the directory holding the document, not the repo root.
    $base = dirname($packageDir . '/' . $document);

    preg_match_all('/\]\(([^)\s]+)\)/', $source, $matches);
    $targets = array_filter(
        $matches[1],
        static fn (string $target): bool => !str_starts_with($target, 'http')
            && !str_starts_with($target, '#')
            && !str_starts_with($target, 'mailto:'),
    );

    expect($targets)->not->toBeEmpty();

    foreach ($targets as $target) {
        $path = $base . '/' . strtok($target, '#');
        expect(file_exists($path))->toBeTrue($document . ' links to missing path: ' . $target);
    }
})->with(['README.md', 'docs/installation.md']);

test('every in-page readme anchor resolves to one of its own headings (issue #105)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $readme = (string) file_get_contents($packageDir . '/README.md');

    preg_match_all('/^#{2,6}\s+(.+)$/m', $readme, $headingMatches);
    $slugs = array_map(
        static fn (string $heading): string => trim((string) preg_replace(
            '/[^a-z0-9 -]/',
            '',
            strtolower(str_replace('`', '', $heading)),
        )),
        $headingMatches[1],
    );
    $slugs = array_map(static fn (string $slug): string => str_replace(' ', '-', $slug), $slugs);

    preg_match_all('/\]\(#([^)\s]+)\)/', $readme, $anchorMatches);

    expect($anchorMatches[1])->not->toBeEmpty();

    foreach ($anchorMatches[1] as $anchor) {
        expect($slugs)->toContain($anchor);
    }
});
