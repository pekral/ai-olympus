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
        'backend.md' => 'a42397cf06990ec81fc62874000e0a77f826f41d9851f5fa1d9ef8734d932521',
        'frontend.md' => 'e0e70a6cb2be15e314a933c788a333bb77f98fc00d9149fae9fe11b9d83476cf',
        'mobile.md' => 'f72b824c6f6d23f0db84662ab7de8c54c5126b4d65d5118e44b169d2a4115fea',
    ];

    foreach ($expectedBodyHashes as $file => $expectedHash) {
        expect(ruleBodyDigest($packageDir . '/rules/security/' . $file))->toBe($expectedHash);
    }
});

test('every rule scoped in issue #274, #275 or #277 declares exactly the `paths:` list it was scoped to', function (): void {
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
        'rules/compound-engineering/orchestration.md',
        'rules/laravel/laravel.md',
        'rules/php/core-standards.md',
        'rules/sql/optimalize.md',
        'rules/laravel/architecture.md',
        'rules/laravel/dynamodb.md',
        'rules/laravel/filament.md',
        'rules/laravel/livewire.md',
        'rules/laravel/queue-debouncing.md',
    ]);
});

test('every glob a rule scoped in issue #274, #275 or #277 declares matches at least one real path', function (): void {
    // A glob that matches nothing silences its rule as completely as deleting the file would,
    // and nothing else in the build would notice. The corpus is this repository's own files plus
    // the consumer-project paths this package writes rules for, since it ships no `app/`,
    // no migrations and no `.sql` file of its own — and, for `.claude/run/**`, a representative
    // dispatch-time scratch path every consuming project gets once it installs this package.
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
        'rules/api/general.md' => '33b6cd8fce7ced30e90e05f72fde2d1cacf25e7aa37579aac5a3f4c351eed2fc',
        'rules/laravel/laravel.md' => 'bdaad58b083bb0fb2ab27105c8caf5d9b943e5ff296c36d159b57e4ffa997a37',
        'rules/php/core-standards.md' => '7b2c860efda73d0233af7a73ac5e979bbf5176b88ada7fbe38425765cc26054f',
        'rules/sql/optimalize.md' => 'dcda4f6d54f0458a9a64ae2657a7422231067b1f6735726852167c81d449ed9c',
    ];

    foreach ($expectedBodyHashes as $relativePath => $expectedHash) {
        expect(ruleBodyDigest($packageDir . '/' . $relativePath))->toBe($expectedHash);
    }
});

test('every rule renamed in issue #277 keeps a byte-identical body below the frontmatter', function (): void {
    // Digests of everything after the closing `---`, taken from each file while it was still a
    // `.mdc`. Issue #277 was allowed to change the extension and the frontmatter keys and nothing
    // else, so a mismatch here means a normative sentence moved during the rename.
    $packageDir = dirname(__DIR__, 2);
    $expectedBodyHashes = [
        'rules/laravel/architecture.md' => '849ef2b359d47b969c434821730f16e8da743d67c4915de043dcdcf2fb89270a',
        'rules/laravel/dynamodb.md' => 'c551d704a405b13d01da74a7be899380907d0f84ccccdfc6c912fc6ed9b9409a',
        'rules/laravel/filament.md' => '25256c6b3ac6f618600ad2047a994e1c8e6c922fd9426f66df74fd37a19a7b0a',
        'rules/laravel/livewire.md' => '33544f8968925e49543216bce85dc98d2e0c4a7d91fa975be49a792504186d61',
        'rules/laravel/queue-debouncing.md' => '4c774f289f7c4a01b7f19637858887ee00053497d412bb505c779147836b3d8b',
    ];

    foreach ($expectedBodyHashes as $relativePath => $expectedHash) {
        expect(ruleBodyDigest($packageDir . '/' . $relativePath))->toBe($expectedHash);
    }
});

test('a rule scoped in issue #274, #275 or #277 is no longer claimed as always-on', function (): void {
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
