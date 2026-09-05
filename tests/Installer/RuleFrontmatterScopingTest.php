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
        // Re-baselined by issue #63, which adds the *Scope boundary* paragraph dividing this
        // section from the container lens, and again by its first CR round, which gives that
        // paragraph the *dimensions* half and the same conditionality the CR carrier states.
        // The digest records the current body; it never forbids a deliberate edit to it, only
        // an accidental one.
        'backend.md' => 'bff59725a30dfdc77cf513fcd574caf8223a965a04b76ea8c922aaf7279658f7',
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
        'rules/code-testing/general.md',
        'rules/php/dependency-selection.md',
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
    // `rules/php/core-standards.md` carries one more: the code-review rule set split into three
    // files to get under Claude Code's 150 000-character per-file limit, so the two coverage-gate
    // cross-references in this file now name `code-review/review-process.md`, the file that
    // carries that gate. Only the pointers moved; no sentence of this rule changed.
    // The pin is scoped to the issue #274 scoping change,
    // which was allowed to add frontmatter and nothing else — it is not a freeze on the rule
    // corpus, so a later assignment that deliberately edits a rule body re-baselines it here with
    // the reason, exactly as `rules/reports/general.md` did below.
    $packageDir = dirname(__DIR__, 2);
    $expectedBodyHashes = [
        'rules/api/general.md' => '33b6cd8fce7ced30e90e05f72fde2d1cacf25e7aa37579aac5a3f4c351eed2fc',
        // Re-baselined: the file gained a `## Collections` section — a sequence of collection
        // transformations is chained into one fluent pipeline instead of being reassigned
        // through a single variable. Nothing else in the file moved.
        // Re-baselined again: `## Testing` gained the one-line job counterpart to its
        // `Artisan::call()` sibling — a job under test is invoked through
        // `app()->call([$job, 'handle'])`, pointing at `rules/code-testing/general.md` *Jobs* for
        // the contract. Nothing else in the file moved.
        'rules/laravel/laravel.md' => '49c04940b9853abf0e681c613105b808e2c5d8899916b30c3a2a0fcbe1609915',
        // Re-baselined: the Minor bucket is retired, so the misleading-name gating no longer
        // hands a merely-less-descriptive name to a Minor default that no longer exists, and its
        // stratification citation now names the section that carries the default after the retired
        // walk was removed. Re-baselined once more: the >4-parameter rule's exemption list was an
        // absolute that could not accommodate a second category, so it now names exactly two —
        // the signature fixed outside the project, and the data carrier's own constructor, whose
        // prescribed fix was circular. `tests/Installer/DtoConstructorParamExemptionTest.php`
        // pins the new wording.
        'rules/php/core-standards.md' => 'f10ef28dfe8f11728b295ed7a4810d1a14f8a888ba29701dc617ea7315e394d9',
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
    // `rules/code-testing/general.md` and `rules/php/core-standards.md` carry a re-baseline for the
    // quality-gate deferral, which moved the gate to the end of the work and retired the
    // "pre-push" vocabulary those sections used.
    // `rules/code-testing/general.md` carries one more, for the same three-way split: its coverage
    // cross-reference now names `code-review/review-process.md`. Only the pointer moved.
    // `rules/code-review/general.md` carries one further re-baseline for the project `CLAUDE.md`
    // gate, which added the *Project `CLAUDE.md` as an additional review input* section and then
    // bound its conflict-resolution rule by subject as well as severity, so a project convention can
    // never override a security finding that the S1-S3 carve-out protects at any severity.
    // `rules/code-review/general.md` carries one final re-baseline: the file passed the 150 000-
    // character limit Claude Code enforces per rule file, so the loader stopped loading it and the
    // whole code-review rule set went silently inactive. The fix split it into three files at its
    // own structural seams — `general.md`, `core-analysis.md`, `review-process.md` — and moved no
    // normative sentence: the bytes below the frontmatter of the three files, concatenated in that
    // order, read as the one file did but for a single line — the `Strict rule compliance`
    // cross-reference inside the Core Analysis walk-through, repointed at the file that now carries
    // that section. The digest here therefore covers only what stayed in
    // `general.md` plus the pointer section that names the other two.
    // `rules/code-review/general.md` carries one further re-baseline for the incremental review
    // scope: a pull request under a multi-round review was re-read from its first commit on every
    // round, so each later round re-derived findings an earlier one had already settled. The new
    // *Incremental Review Scope — Diff Since the Last Reviewed Revision* section scopes a later
    // round's detection to the diff since the revision the previous round reviewed, carries every
    // unsettled finding over unconditionally, and makes each finding declare whether it is a
    // regression of this revision or a pre-existing issue. The *Context Awareness* bullet that
    // already said "do not repeat already reported findings" gained the cross-reference that makes
    // it concrete; nothing else in the file moved.
    // `rules/code-review/general.md` carries one last re-baseline for the latency lens: the CR gained a
    // conditional trigger for `latency-critical-systems`, which reads the same hot-path lines the
    // *Bulk Data & Batch Processing (issue #223)* section already walks. That section's own Gating
    // paragraph now names the lens, states which dimension each owner keeps, and states that the two
    // divide the dimensions of a hot-path change rather than its lines — so the hand-over never
    // suppresses a budget or freshness finding no other owner raises. Nothing else in the file moved.
    // `rules/code-review/general.md` carries one more re-baseline: the blanket *Strict rule
    // compliance* walk is retired, so the two sentences that cited it as a live walk were repointed
    // and the severity stratification it defined moved into this file as *Default severity for a
    // rule violation* — surviving bullets still need a default when their own rule file declares
    // none. Nothing else in the file moved.
    // `rules/code-review/general.md` carries one more re-baseline: both refactoring sections of the
    // published review are retired, so the Two-Part output contract no longer lists them and the
    // late-iteration drop list no longer names them. Nothing else in the file moved.
    // `rules/code-review/general.md` carries one last re-baseline: the Minor bucket is retired, so
    // *Late-Iteration Report Scope* — a filter whose whole job was suppressing Minor findings and
    // the two now-retired refactoring sections — is replaced by *Minor findings are not detected*,
    // which keeps the one exception the package holds everywhere: a security-lens finding is
    // published at whatever severity its own scale assigns.
    // `rules/code-review/general.md` carries a final re-baseline from the merge-gate sweep: the two
    // sentences that spelled the convergence gate out as a count now cite the one place that
    // computes it, so the package holds exactly one definition of convergence.
    // `rules/reports/general.md` carries one re-baseline of its own: the bullet naming what falls
    // outside the English CR exception described `pr-summary`'s per-target field list (the four
    // GitHub fields, JIRA's reduced "only How to test" shape), and `pr-summary` now renders one
    // shape on every target. The bullet describes that shape instead, and states the consequence
    // this rule owns — headings and field labels are prose, so they are translated with the rest of
    // the comment. Nothing else in the file moved.
    // `rules/jira/general.md` carries one re-baseline of its own: the status-transition ban listed
    // two sanctioned exceptions, and the tracker phase invariant gained a third phase — ready to
    // merge, written when the code review converges — so the ban now lists three and names
    // `transition-to-ready-to-merge.sh`. The revert direction adds no fourth exception: moving back
    // to the review column is the second transition, run again. Nothing else in the file moved.
    // `rules/code-review/general.md` and `rules/jira/general.md` each carry one final re-baseline
    // from the same sweep's second pass: the JIRA rule still stated the phase-3 trigger in the
    // withdrawn `zero Critical and zero Moderate` words, so the package held two definitions of
    // convergence for one write, and the Two-Part output contract still routed direction-2 findings
    // to the retired strict rule compliance walk. Both now cite the section that survived; nothing
    // else in either file moved.
    // The pin is scoped to the issue #277 rename, which was allowed to change
    // the extension and the frontmatter keys and nothing else — it is not a freeze on the rule
    // corpus.
    $packageDir = dirname(__DIR__, 2);
    $expectedBodyHashes = [
        // Re-baselined: Action Rules gained the one-DTO-per-`__invoke()` cap and CR Severity
        // Rules its matching Moderate entry, so a use case carries one payload in one typed
        // carrier. Nothing else in the file moved.
        // ...and once more: the request now stops at the controller, so an Action `__invoke()`
        // takes neither an `Illuminate\Http\Request` nor a `FormRequest` — the input-side
        // mirror of the HTTP-response ban, at the same Critical severity.
        'rules/laravel/architecture.md' => 'df4fa2803d09bdab6d022c9375c8274030efdce451b3d2465e4762768dbfbdc7',
        'rules/laravel/dynamodb.md' => 'c551d704a405b13d01da74a7be899380907d0f84ccccdfc6c912fc6ed9b9409a',
        'rules/laravel/filament.md' => '25256c6b3ac6f618600ad2047a994e1c8e6c922fd9426f66df74fd37a19a7b0a',
        'rules/laravel/livewire.md' => '33544f8968925e49543216bce85dc98d2e0c4a7d91fa975be49a792504186d61',
        'rules/laravel/queue-debouncing.md' => '4c774f289f7c4a01b7f19637858887ee00053497d412bb505c779147836b3d8b',
        // Re-baselined: *Two-Part CR Output* gained the tracker-side counterpart of the always-
        // rendered `## Functional Review` — the consolidated tracker comment now states the
        // assignment verdict in all three cases, the affirmative one included, so a met
        // assignment and an unchecked one no longer look identical to the ticket owner.
        'rules/code-review/general.md' => 'c38816a4183fd9fc3ce1b5433483e590e77818cce5833fba18b486fc649aa833',
        // Re-baselined: `## Jobs` gained the preferred invocation for a job's own test —
        // `app()->call([$job, 'handle'])`, so the container resolves the `handle()` dependencies
        // and the test builds no double just to satisfy the signature. Nothing else in the file
        // moved; the sibling *assert the dispatch, never the payload* bullet is untouched.
        'rules/code-testing/general.md' => 'acd2885e6a1bbc7aa81e5191a3b47878238d73b302ace17b4e50449af1267c9b',
        'rules/jira/general.md' => '7dcebba2554139310f60b3b69e07fe73be610ae20cf2e1812eea0723b8e3c82e',
        'rules/php/dependency-selection.md' => '7633700bab79504ebcad864ec106cd3f9f44cc9b46c3740221e435c4d64a5ea6',
        // Re-baselined: the review stopped walking commit history, so the two commit-history
        // steps of the Test Coverage Contract became authoring guidance and the rule now states
        // what that costs — the proof that behaviour was preserved across a refactor.
        'rules/refactoring/general.md' => '6a541ac80dd25fe355284a7b8c9ead0e9371d1888b703941a8f89139182a3fc0',
        'rules/reports/general.md' => 'aa0df08a4b77e387717b16c7bfdea1602e8698f565fdbf21322fc67fbb8d1a5f',
    ];

    foreach ($expectedBodyHashes as $relativePath => $expectedHash) {
        expect(ruleBodyDigest($packageDir . '/' . $relativePath))->toBe($expectedHash);
    }
});

test('no rule declares the empty `paths: []` list the loader reads as always-on (issue #45)', function (): void {
    // Measured in a live session: a rule with `paths: []` is present in the system prompt from the
    // first turn, exactly like a rule with no `paths:` key, while a rule with a real glob is absent
    // until the session touches a matching file. The empty list is therefore always-on wearing
    // path-scoping's spelling — the one state that reads as a third option and is not one.
    $packageDir = dirname(__DIR__, 2);
    $ruleFiles = ruleTreeFiles();
    $violations = [];

    foreach ($ruleFiles as $relativePath) {
        $frontmatter = ruleExtensionFrontmatter($packageDir . '/' . $relativePath);

        if (str_contains($frontmatter, 'paths: []')) {
            $violations[] = $relativePath . ': declares `paths: []`, which loads it into every session rather than none';
        }
    }

    // Without this the walk could stop finding rules and the assertion would pass vacuously.
    expect($ruleFiles)->toContain('rules/security/general.md');
    expect($violations)->toBe([]);
});

test('every rule declares exactly one of the two scopes the loader has (issue #45)', function (): void {
    // No key means "load everywhere" and a `paths:` list means "load when a file matches". A rule
    // that omits the key without being claimed as always-on is loading everywhere unnoticed, and a
    // rule claimed as always-on while carrying a key is not always-on at all.
    $packageDir = dirname(__DIR__, 2);
    $alwaysOn = ruleExtensionAlwaysOnFiles();
    $violations = [];

    foreach (ruleTreeFiles() as $relativePath) {
        $declaresPaths = preg_match('/^paths:/m', ruleExtensionFrontmatter($packageDir . '/' . $relativePath)) === 1;
        $claimedAlwaysOn = in_array($relativePath, $alwaysOn, strict: true);

        if ($declaresPaths === $claimedAlwaysOn) {
            $violations[] = $relativePath . ($declaresPaths
                ? ': is claimed always-on yet declares a `paths:` list'
                : ': declares no `paths:` key, so it loads everywhere, yet is not claimed always-on');
        }
    }

    expect($violations)->toBe([]);
    expect($alwaysOn)->toHaveCount(11);
    expect(array_intersect($alwaysOn, array_keys(ruleScopingExpectedGlobs())))->toBe([]);
});

test('the two rules issue #45 scoped carry the globs that name their trigger', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $testing = ruleExtensionFrontmatter($packageDir . '/rules/code-testing/general.md');
    $dependency = ruleExtensionFrontmatter($packageDir . '/rules/php/dependency-selection.md');

    expect($testing)->toContain('  - "tests/**"');
    expect($testing)->toContain('  - "**/*Test.php"');
    expect($dependency)->toContain('  - "composer.json"');
    expect($dependency)->toContain('  - "**/composer.json"');
    expect(ruleScopingGlobsAddedByIssue45())->toHaveCount(2);
});

test('a rule scoped to a narrow glob is still reached by an explicit reference (issue #45)', function (): void {
    // `tests/**` and `composer.json` do not match every session that needs these two rules, so the
    // explicit `@rules/…` references are what carry them into a run the glob misses. A rule that
    // loses its last reference is silenced exactly as completely as a glob that matches nothing,
    // and nothing else in the build would say so.
    $walked = packageTextFiles();
    $unreferenced = [];

    foreach (array_keys(ruleScopingGlobsAddedByIssue45()) as $relativePath) {
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
        'rules/code-review/core-analysis.md',
        'rules/code-review/general.md',
        'rules/code-review/review-process.md',
        'rules/jira/general.md',
        'rules/refactoring/general.md',
        'rules/reports/general.md',
        'rules/security/general.md',
    ]);

    foreach (array_keys(ruleScopingExpectedGlobs()) as $relativePath) {
        expect($alwaysOn)->not->toContain($relativePath);
    }
});
