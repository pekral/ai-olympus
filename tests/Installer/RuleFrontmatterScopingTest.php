<?php

declare(strict_types = 1);

test('security/backend.md is scoped to backend paths via Claude Code\'s documented `paths:` field (issue #162)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/rules/security/backend.md');

    expect($content)->toContain("paths:\n");
    expect($content)->toContain('  - "app/**/*.php"');
    expect($content)->toContain('  - "src/**/*.php"');
    expect($content)->toContain('  - "packages/**/*.php"');
    expect($content)->toContain('  - "Modules/**/*.php"');
    expect($content)->toContain('  - "bootstrap/**/*.php"');
    expect($content)->toContain('  - "config/**/*.php"');
    expect($content)->toContain('  - "database/**/*.php"');
    expect($content)->toContain('  - "routes/**/*.php"');
    expect($content)->toContain('  - "tests/**/*.php"');
    // Cursor-only keys (not part of Claude Code's rule schema — see
    // https://code.claude.com/docs/en/memory, "Path-specific rules") must not reappear.
    expect($content)->not->toContain('globs:');
    expect($content)->not->toContain('alwaysApply:');
});

test('security/frontend.md is scoped to frontend paths via Claude Code\'s documented `paths:` field (issue #162)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/rules/security/frontend.md');
    $frontendPaths = [
        'resources/**/*.js', 'resources/**/*.ts', 'resources/**/*.jsx', 'resources/**/*.tsx',
        'resources/**/*.vue', 'resources/**/*.blade.php', 'resources/**/*.css', 'resources/**/*.scss',
        'public/**/*.js',
    ];

    expect($content)->toContain("paths:\n");

    foreach ($frontendPaths as $path) {
        expect($content)->toContain('  - "' . $path . '"');
    }

    expect($content)->not->toContain('globs:');
    expect($content)->not->toContain('alwaysApply:');
});

test('security/mobile.md is scoped to mobile paths via Claude Code\'s documented `paths:` field (issue #162)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/rules/security/mobile.md');

    expect($content)->toContain("paths:\n");
    expect($content)->toContain('  - "mobile/**"');
    expect($content)->toContain('  - "**/*.swift"');
    expect($content)->toContain('  - "**/*.kt"');
    expect($content)->toContain('  - "**/*.dart"');
    expect($content)->not->toContain('globs:');
    expect($content)->not->toContain('alwaysApply:');
});

test('the three security rule bodies stay byte-identical below the frontmatter (issue #162)', function (): void {
    // These SHA-256 digests were computed from the rule bodies (everything after the
    // closing "---" of the frontmatter block) BEFORE this fix touched only the
    // frontmatter. A digest mismatch means the body itself changed, not just scoping
    // metadata — this is the real byte-identity guarantee the test name promises,
    // rather than the weaker `toStartWith($heading)` check iteration 1 shipped.
    // Note: the pre-existing `tests/Installer/SecurityContentTest.php` pins are the
    // durable, human-readable protection against a rule-wording regression; this test
    // is a narrower, exact-byte cross-check specific to this frontmatter-only change.
    $packageDir = dirname(__DIR__, 2);
    $expectedBodyHashes = [
        'backend.md' => '77b3405ea87c2445027d4d9c362e6cc6f8a9791da4fdba763db9bf4eef4a010b',
        'frontend.md' => 'e0e70a6cb2be15e314a933c788a333bb77f98fc00d9149fae9fe11b9d83476cf',
        'mobile.md' => 'f72b824c6f6d23f0db84662ab7de8c54c5126b4d65d5118e44b169d2a4115fea',
    ];

    foreach ($expectedBodyHashes as $file => $expectedHash) {
        $content = (string) file_get_contents($packageDir . '/rules/security/' . $file);
        $afterFrontmatter = ltrim(substr($content, (int) strpos($content, '---', 3) + 3));

        expect(hash('sha256', $afterFrontmatter))->toBe($expectedHash);
    }
});

test('every rule scoped in issue #274 declares exactly the `paths:` list it was scoped to', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $violations = [];

    foreach (ruleScopingExpectedGlobs() as $relativePath => $expectedGlobs) {
        $content = (string) file_get_contents($packageDir . '/' . $relativePath);

        if (ruleScopingGlobs($packageDir . '/' . $relativePath) !== $expectedGlobs) {
            $violations[] = $relativePath . ': `paths:` list is not the one this rule was scoped to';
        }

        // Cursor-only keys (not part of Claude Code's rule schema — see
        // https://code.claude.com/docs/en/memory, "Path-specific rules") must never appear.
        if (str_contains($content, 'globs:') || str_contains($content, 'alwaysApply:')) {
            $violations[] = $relativePath . ': carries a Cursor-only frontmatter key';
        }
    }

    expect($violations)->toBe([]);
    expect(array_keys(ruleScopingExpectedGlobs()))->toBe([
        'rules/api/general.md',
        'rules/laravel/laravel.md',
        'rules/php/core-standards.md',
        'rules/sql/optimalize.md',
    ]);
});

test('every glob a rule scoped in issue #274 declares matches at least one real path (issue #274)', function (): void {
    // A glob that matches nothing silences its rule as completely as deleting the file would,
    // and nothing else in the build would notice. The corpus is this repository's own files plus
    // the consumer-project paths this package writes rules for, since it ships no `app/`,
    // no migrations and no `.sql` file of its own.
    $corpus = array_merge(array_keys(packageTextFiles()), ruleScopingConsumerProjectPaths());
    $unmatched = [];

    // Without these the test passes vacuously the moment the corpus or the matcher degrades.
    expect($corpus)->toContain('src/Installer.php');
    expect($corpus)->toContain('app/Http/Controllers/OrderController.php');
    expect(ruleScopingGlobMatchesAny('app/Htpp/**/*.php', $corpus))->toBeFalse();
    expect(ruleScopingGlobMatchesAny('routes/*.php', ['routes/nested/api.php']))->toBeFalse();
    // A trailing `**` spans separators where a single `*` stops at one, and `?` matches exactly
    // one character that is not a separator. No glob the four rules declare reaches either
    // translation today, so without these the other half of the matcher is asserted by nothing.
    expect(ruleScopingGlobMatchesAny('src/**', ['src/Installer/Path.php']))->toBeTrue();
    expect(ruleScopingGlobMatchesAny('src/*', ['src/Installer/Path.php']))->toBeFalse();
    expect(ruleScopingGlobMatchesAny('src/Installer?.php', ['src/InstallerX.php']))->toBeTrue();
    expect(ruleScopingGlobMatchesAny('src/Installer?.php', ['src/InstallerXY.php']))->toBeFalse();

    foreach (ruleScopingExpectedGlobs() as $relativePath => $globs) {
        foreach ($globs as $glob) {
            if (!ruleScopingGlobMatchesAny($glob, $corpus)) {
                $unmatched[] = $relativePath . ' → ' . $glob;
            }
        }
    }

    expect($unmatched)->toBe([]);
});

test('a rule scoped to every PHP file still loads over the PHP this repository itself ships (issue #274)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $repositoryPaths = array_keys(packageTextFiles());

    expect($repositoryPaths)->toContain('src/Installer.php');

    foreach (['rules/laravel/laravel.md', 'rules/php/core-standards.md'] as $relativePath) {
        $globs = ruleScopingGlobs($packageDir . '/' . $relativePath);

        expect($globs)->toBe(['**/*.php']);
        expect(ruleScopingGlobMatchesAny($globs[0], $repositoryPaths))->toBeTrue();
    }
});

test('the four rules scoped in issue #274 keep byte-identical bodies below the frontmatter', function (): void {
    // Digests of everything after the closing `---`, taken from the files as they stood before
    // the scoping change. A mismatch means a normative sentence moved, which this change is not
    // allowed to do — it may only add frontmatter.
    $packageDir = dirname(__DIR__, 2);
    $expectedBodyHashes = [
        'rules/api/general.md' => '331939fcee8d3334b3729d9f58b3599ca8482b42a847354174c13d4580741ec2',
        'rules/laravel/laravel.md' => 'f0620445408c1056725feaa5a53f5b1516401b35751a2a60c52e7f9af79ccd38',
        'rules/php/core-standards.md' => '5cd3e891336f8968e4970d3e457f0e5ae51fab8d3b7ec81d1f34237be6dff6a5',
        'rules/sql/optimalize.md' => 'c7ab59fc4f3eaff99bd8dafeb7a176f651731139a1d8c8a964dfd35a9b57173f',
    ];

    foreach ($expectedBodyHashes as $relativePath => $expectedHash) {
        $content = (string) file_get_contents($packageDir . '/' . $relativePath);
        $afterFrontmatter = ltrim(substr($content, (int) strpos($content, '---', 3) + 3));

        expect(hash('sha256', $afterFrontmatter))->toBe($expectedHash);
    }
});

test('a rule scoped in issue #274 is no longer claimed as always-on', function (): void {
    $alwaysOn = ruleExtensionAlwaysOnFiles();

    expect($alwaysOn)->toBe([
        'rules/compound-engineering/general.md',
        'rules/git/general.md',
        'rules/writing/general.md',
    ]);

    foreach (array_keys(ruleScopingExpectedGlobs()) as $relativePath) {
        expect($alwaysOn)->not->toContain($relativePath);
    }
});
