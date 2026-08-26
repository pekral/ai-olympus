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
    // Re-baselined for the pekral/ai-olympus agent + namespace rename; no sentence moved.
    // `rules/sql/optimalize.md` carries one further re-baseline: issue #20 added the
    // **Deploy-safe schema changes** section, and `rules/php/core-standards.md` one more: issue #22
    // added the **Never generate a docblock that describes the logic** bullet to `## Documentation`.
    // The pin is scoped to the issue #274 scoping change,
    // which was allowed to add frontmatter and nothing else — it is not a freeze on the rule
    // corpus, so a later assignment that deliberately edits a rule body re-baselines it here with
    // the reason, exactly as `rules/reports/general.md` did below.
    $packageDir = dirname(__DIR__, 2);
    $expectedBodyHashes = [
        'rules/api/general.md' => '33b6cd8fce7ced30e90e05f72fde2d1cacf25e7aa37579aac5a3f4c351eed2fc',
        'rules/laravel/laravel.md' => 'bdaad58b083bb0fb2ab27105c8caf5d9b943e5ff296c36d159b57e4ffa997a37',
        'rules/php/core-standards.md' => 'a86f70de146fe62283e3d3b3ea335195520b62f95b4f8b5393d88b5057150f51',
        'rules/sql/optimalize.md' => '1be7ae52b6e7c764c8d631a5ad01c08d3e953d06f3cdf6e21e21a94e771816d7',
    ];

    foreach ($expectedBodyHashes as $relativePath => $expectedHash) {
        expect(ruleBodyDigest($packageDir . '/' . $relativePath))->toBe($expectedHash);
    }
});

test('every rule renamed in issue #277 keeps a byte-identical body below the frontmatter', function (): void {
    // Digests of everything after the closing `---`, taken from each file while it was still a
    // `.mdc`. Issue #277 was allowed to change the extension and the frontmatter keys and nothing
    // else, so a mismatch here means a normative sentence moved during the rename.
    // Re-baselined when the package moved to pekral/ai-olympus: the agent rename
    // (daidalos -> daedalus, hefaistos -> hephaestus) and the PHP namespace rename
    // are the only edits these bodies carry. `rules/reports/general.md` carries one further
    // re-baseline: #11 deleted the skill whose report was the second half of the GitHub-PR English
    // exception, so that exception dropped back to a single one. `rules/code-review/general.md` and
    // `rules/laravel/architecture.md` carry the same kind of re-baseline for issue #20, which added
    // the **Deploy-safe schema changes** Core Analysis bullet and the **Action-to-Action
    // pass-through rule**; `rules/code-review/general.md` carries one more for issue #22, which
    // extended the issue #53 bullet with the declaration-level generated-docblock shapes.
    // `rules/code-testing/general.md` carries a re-baseline for the quality-gate deferral, which
    // moved its **Code Style and Quality Gates** section from per-change fixers to the single
    // pre-merge gate.
    // The pin is scoped to the issue #277 rename, which was allowed to change
    // the extension and the frontmatter keys and nothing else — it is not a freeze on the rule
    // corpus.
    $packageDir = dirname(__DIR__, 2);
    $expectedBodyHashes = [
        'rules/laravel/architecture.md' => '789dfe6021375eb9cc8280be8e41e55ff9f6d9f82a5928763ca3288f255e5f1d',
        'rules/laravel/dynamodb.md' => 'c551d704a405b13d01da74a7be899380907d0f84ccccdfc6c912fc6ed9b9409a',
        'rules/laravel/filament.md' => '25256c6b3ac6f618600ad2047a994e1c8e6c922fd9426f66df74fd37a19a7b0a',
        'rules/laravel/livewire.md' => '33544f8968925e49543216bce85dc98d2e0c4a7d91fa975be49a792504186d61',
        'rules/laravel/queue-debouncing.md' => '4c774f289f7c4a01b7f19637858887ee00053497d412bb505c779147836b3d8b',
        'rules/code-review/general.md' => '40aee457d43fbf31abfacb2d35a498c470912a06d56015ea2461e45d42f438b4',
        'rules/code-testing/general.md' => 'abaa9f22353027e72331b536e5a320a0e172f60b1942f13184293db0053b6391',
        'rules/jira/general.md' => '3c5da06c4fa49351085ec24230d4bf3c2adc5f44f0a03d85bf57b51755eb325a',
        'rules/php/dependency-selection.md' => '7633700bab79504ebcad864ec106cd3f9f44cc9b46c3740221e435c4d64a5ea6',
        'rules/refactoring/general.md' => '6de4456d6cbaf108a7083e407d47bf06d8bf6890ba7e2ae8489fe1e6fef50175',
        'rules/reports/general.md' => 'bdf0e939be095247bb3c9853ae166ea9603938e1bd0f0c9eb234220f99033ac8',
    ];

    foreach ($expectedBodyHashes as $relativePath => $expectedHash) {
        expect(ruleBodyDigest($packageDir . '/' . $relativePath))->toBe($expectedHash);
    }
});

test('a rule scoped to nothing says so with an explicit empty `paths:` list (issue #277)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $violations = [];

    foreach (ruleScopingReferenceOnlyFiles() as $relativePath) {
        $frontmatter = ruleExtensionFrontmatter($packageDir . '/' . $relativePath);

        if (!str_contains($frontmatter, "\npaths: []")) {
            $violations[] = $relativePath . ': does not declare the empty scoping list `paths: []`';
        }

        if (str_contains($frontmatter, 'alwaysApply') || str_contains($frontmatter, 'globs')) {
            $violations[] = $relativePath . ': still carries a Cursor-only frontmatter key';
        }

        if (!str_contains($frontmatter, 'description:')) {
            $violations[] = $relativePath . ': lost its description';
        }
    }

    expect($violations)->toBe([]);
    expect(ruleScopingReferenceOnlyFiles())->toHaveCount(7);
});

test('a rule scoped to nothing is claimed neither as always-on nor as path-scoped (issue #277)', function (): void {
    // The three registries partition every shipped rule, so a rule cannot be silently counted
    // twice — or, worse, moved between them without the move being visible in a diff.
    $referenceOnly = ruleScopingReferenceOnlyFiles();

    expect(array_intersect($referenceOnly, ruleExtensionAlwaysOnFiles()))->toBe([]);
    expect(array_intersect($referenceOnly, array_keys(ruleScopingExpectedGlobs())))->toBe([]);
});

test('every rule scoped to nothing is still reached by an explicit reference (issue #277)', function (): void {
    // `paths: []` means the loader never attaches the rule on its own, so the explicit
    // `@rules/…` references are the only thing keeping it reachable. A rule that loses its last
    // reference is silenced exactly as completely as a glob that matches nothing, and nothing
    // else in the build would say so.
    $walked = packageTextFiles();
    $unreferenced = [];

    foreach (ruleScopingReferenceOnlyFiles() as $relativePath) {
        $referencingFiles = array_filter(
            $walked,
            static fn (string $contents, string $file): bool => $file !== $relativePath
                && str_contains($contents, '@' . $relativePath),
            ARRAY_FILTER_USE_BOTH,
        );

        if ($referencingFiles === []) {
            $unreferenced[] = $relativePath;
        }
    }

    expect($walked)->toHaveKey('skills/code-review/SKILL.md');
    expect($unreferenced)->toBe([]);
});

test('a rule scoped in issue #274, #275 or #277 is no longer claimed as always-on', function (): void {
    $alwaysOn = ruleExtensionAlwaysOnFiles();

    expect($alwaysOn)->toBe([
        'rules/compound-engineering/general.md',
        'rules/general/general.md',
        'rules/git/general.md',
        'rules/writing/general.md',
    ]);

    foreach (array_keys(ruleScopingExpectedGlobs()) as $relativePath) {
        expect($alwaysOn)->not->toContain($relativePath);
    }
});
