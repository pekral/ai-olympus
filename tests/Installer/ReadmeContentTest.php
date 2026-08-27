<?php

declare(strict_types = 1);

test('readme shows the install command inside the first rendered screen (issue #105)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $lines = (array) file($packageDir . '/README.md');

    $firstScreen = implode('', array_slice($lines, 0, 40));

    expect($firstScreen)->toContain('composer require pekral/ai-olympus --dev');
    expect($firstScreen)->toContain('vendor/bin/ai-olympus install --force');
});

test('readme hero paragraph describes the product, not the installer mechanics (issue #105)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $readme = (string) file_get_contents($packageDir . '/README.md');

    $heroStart = strpos($readme, '**AI Olympus**');
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
    expect($docs)->toContain('vendor/pekral/ai-olympus/rules');
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

test('every Always scope in the rules overview table names a rule without a paths key (issue #282)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $readme = (string) file_get_contents($packageDir . '/README.md');

    // Scope the parse to the Rules Overview table so other markdown tables never leak in.
    $section = installerDocsSection($readme, '## Rules Overview');

    // Data rows quote the rule file in backticks; the header and separator rows do not. The
    // description column may escape a pipe, the scope may be several words, and the row may
    // carry trailing whitespace — a row this misses would silently drop out of the check.
    preg_match_all(
        '/^\|\s*`([^`]+)`\s*\|(?:\\\\.|[^|\\\\])*\|\s*([^|]+?)\s*\|[ \t]*\r?$/m',
        $section,
        $rows,
        PREG_SET_ORDER,
    );

    // Counted off the File cell alone, so a row the strict pattern drops fails the count.
    preg_match_all('/^\|\s*`[^`]+`\s*\|/m', $section, $fileCells);

    $alwaysRules = array_map(
        static fn (array $row): string => $row[1],
        array_filter(
            $rows,
            static fn (array $row): bool => $row[2] === 'Always' && str_ends_with($row[1], '.md'),
        ),
    );

    // A rule loads into every session only when its frontmatter carries no `paths:` key.
    $isScoped = static fn (string $path): bool => preg_match('/^paths:/m', ruleExtensionFrontmatter($path)) === 1;

    expect($rows)->toHaveCount(count($fileCells[0]));
    expect($alwaysRules)->not->toBeEmpty();

    foreach ($alwaysRules as $rule) {
        $path = $packageDir . '/rules/' . $rule;

        expect(file_exists($path))->toBeTrue('Rules Overview names a missing rule file: ' . $rule);
        expect($isScoped($path))->toBeFalse(
            $rule . ' carries a `paths:` key, so its Rules Overview scope cannot be Always',
        );
    }
});

test('every rules/**/*.md file appears as a row in the readme rules overview table (issue #49)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $readme = (string) file_get_contents($packageDir . '/README.md');

    // Scope the parse to the Rules Overview table so other markdown tables never leak in.
    $section = installerDocsSection($readme, '## Rules Overview');

    // Same row-matching regex the neighbouring "Always scope" test uses, so both tests agree on
    // what counts as a row.
    preg_match_all(
        '/^\|\s*`([^`]+)`\s*\|(?:\\\\.|[^|\\\\])*\|\s*([^|]+?)\s*\|[ \t]*\r?$/m',
        $section,
        $rows,
        PREG_SET_ORDER,
    );

    // Counted off the File cell alone, so a row the strict pattern drops fails the count.
    preg_match_all('/^\|\s*`[^`]+`\s*\|/m', $section, $fileCells);

    $tableFiles = array_map(static fn (array $row): string => $row[1], $rows);

    // ruleTreeFiles() already walks rules/ recursively and returns package-relative paths
    // (rules/php/core-standards.md); the table quotes paths relative to rules/ itself.
    $ruleFiles = array_map(
        static fn (string $path): string => substr($path, strlen('rules/')),
        ruleTreeFiles(),
    );

    expect($rows)->toHaveCount(count($fileCells[0]));
    expect($ruleFiles)->not->toBeEmpty();

    foreach ($ruleFiles as $ruleFile) {
        expect(in_array($ruleFile, $tableFiles, true))->toBeTrue(
            'Rules Overview table is missing a row for rule file: ' . $ruleFile,
        );
    }
});
