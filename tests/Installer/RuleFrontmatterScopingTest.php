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
        // Re-baselined once more when the Czech glosses were removed from the four
        // Malicious Code & Supply-Chain Indicators bullet headings.
        // The digest records the current body; it never forbids a deliberate edit to it, only
        // an accidental one.
        'backend.md' => '163d6d5e4b5e926675090079f36554b5e58ed9dd92a4775b7f60a7fcad204600',
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
        'rules/code-review/core-analysis.md',
        'rules/code-review/general.md',
        'rules/code-review/review-process.md',
        'rules/compound-engineering/memory.md',
        'rules/compound-engineering/tracker.md',
        'rules/git/pull-requests.md',
        'rules/jira/general.md',
        'rules/refactoring/general.md',
        'rules/reports/general.md',
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
        // Re-baselined: the file gained `## Time` (the configured zone is read from
        // `config('app.timezone')`, never from a literal or the ambient default), `## String
        // Emptiness Checks` gained the reason whitespace-only input must reach the same branch,
        // and `## Collections` dropped its `foreach` clause and gained the gating bullet saying a
        // `foreach` is never a finding. Nothing else in the file moved.
        // Re-baselined: `## Database and Eloquent` gained the reuse-first gate on query scopes —
        // a new scope is written only once the model, its traits, and its parents were searched
        // for one that already applies the same condition. Nothing else in the file moved.
        // Re-baselined: Controllers must return an explicit HTTP response instead of relying on
        // Laravel to implicitly normalize a domain value. The related Core Analysis bullet is
        // pinned by `tests/Installer/LaravelRulesContentTest.php`.
        'rules/laravel/laravel.md' => '918cb0e742e00412d166fc0b94108dbed460be6487cb860b79c12ee1e979780f',
        // Re-baselined: the Minor bucket is retired, so the misleading-name gating no longer
        // hands a merely-less-descriptive name to a Minor default that no longer exists, and its
        // stratification citation now names the section that carries the default after the retired
        // walk was removed. Re-baselined once more: the >4-parameter rule's exemption list was an
        // absolute that could not accommodate a second category, so it now names exactly two —
        // the signature fixed outside the project, and the data carrier's own constructor, whose
        // prescribed fix was circular. `tests/Installer/DtoConstructorParamExemptionTest.php`
        // pins the new wording.
        // Re-baselined: `## Structure` gained the size ratchet — a file already over the
        // threshold must not gain net lines — and the file gained `## Time`, the
        // framework-agnostic half of the timezone contract. Nothing else in the file moved.
        // Re-baselined: `## Structure` gained the private-visibility rule for a method only its own
        // class calls, `## PHP Practices` gained the unnecessary-local-variable rule, `## Testing`
        // gained the ban on widening visibility for a test, and `## CR Severity Rules` gained their
        // severities. `tests/Installer/VisibilityAndLocalVariableRuleTest.php` pins the wording.
        // The same change states that a string or array callable needs first-class callable syntax
        // before its method turns private, and the reflection bullet in `## Testing` now forbids
        // reaching a private member, so the two Testing bullets no longer disagree.
        // Re-baselined: Documentation now retains comments only for type analysis, security or
        // operational context, and deliberately non-intuitive behaviour. The content policy is
        // pinned by `tests/Installer/SkillsContentTest.php`; the frontmatter scope is unchanged.
        'rules/php/core-standards.md' => '2fa2a4d8d789710c728c07c3bdce141c758002f8e4d8f88f2334d1705de410f1',
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
    // (daidalos -> splinter, hefaistos -> donatello) and the PHP namespace rename
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
        // ...and once more: `## Actions` now opens with the reuse-first gate, so a new Action is
        // written only after `app/Actions/**` was searched for one that already orchestrates the
        // same use case. Nothing else in the file moved.
        // Re-baselined: DTOs gained the `spatie/laravel-data` contract and the `readonly class`
        // fatal-error trap, Data Builders gained the exclusive-suffix and one-shape-per-builder
        // rules, Validation Rules (Traits) gained the `ValidationRules` suffix and the
        // parameterised-trait rule, and CR Severity Rules gained the matching entries.
        // Re-baselined: `## Model Services` now opens with the rule that an Action is the default
        // home for new logic, so a new Model Service is added only once a second flow needs the
        // same single-model operation, and CR Severity Rules gained the matching Moderate entry.
        // Re-baselined: `## Actions` now opens with the rule that an Action is the entry-point
        // contract, so an Action every non-test caller of which is another Action is a finding. The
        // two places that used to license Action-to-Action composition unconditionally now qualify
        // it to an Action an entry point also calls, and CR Severity Rules gained the entry.
        // Re-baselined: `## Data Validators` now states that a Data Validator returns `bool` or
        // throws and never returns data, and CR Severity Rules gained the matching Critical entry.
        // The old single-`validate()` restriction is replaced by named methods of those two shapes.
        'rules/laravel/architecture.md' => '154bf87a09f89097797beef549a50869479eb77d31ada014450c0ad6ecf5dd58',
        'rules/laravel/dynamodb.md' => 'c551d704a405b13d01da74a7be899380907d0f84ccccdfc6c912fc6ed9b9409a',
        'rules/laravel/filament.md' => '25256c6b3ac6f618600ad2047a994e1c8e6c922fd9426f66df74fd37a19a7b0a',
        'rules/laravel/livewire.md' => '33544f8968925e49543216bce85dc98d2e0c4a7d91fa975be49a792504186d61',
        'rules/laravel/queue-debouncing.md' => '4c774f289f7c4a01b7f19637858887ee00053497d412bb505c779147836b3d8b',
        // Re-baselined: the one comment a run publishes is now updated in place through a per-actor
        // marker instead of re-posted, and the rule states what that costs (the comment chain is no
        // longer the cross-run history) and what it preserves (the header block every gate reads).
        // Re-baselined once more, with `rules/jira/general.md`: the JIRA comment marker carries a
        // digest of the account e-mail instead of the address, because that line is visible to
        // everyone who can browse the issue. Nothing else in either file moved.
        // Re-baselined: the file gained `## HOTFIX runs — a narrowed review, declared on the
        // comment` — the two questions the narrowed review still answers, the security carve-out
        // that is never narrowed with them, and the `Mode:` header line the merge gate reads as
        // the mode's only trusted evidence. Nothing else in the file moved.
        // Re-baselined: the file gained three sections — a `foreach` is never a finding, a review
        // comment assigned to somebody else is left alone, and published product documentation is
        // a requirement the assignment need not restate. Nothing else in the file moved.
        // Re-baselined: the file gained `## Answering a question raised during a review`, and the
        // one-comment contract lists `## Answers to reviewer questions` as a conditional section.
        // Re-baselined: its `*Per-dispatch memory slice*` pointer names `compound-engineering/memory.md`,
        // where Compound Memory moved to leave the always-on file. No sentence changed.
        'rules/code-review/general.md' => 'ab7e37f483543b7be51252dc20dbc1708ea47e6486d97e9c8981313a93373034',
        // Re-baselined: `## Jobs` gained the preferred invocation for a job's own test —
        // `app()->call([$job, 'handle'])`, so the container resolves the `handle()` dependencies
        // and the test builds no double just to satisfy the signature. Nothing else in the file
        // moved; the sibling *assert the dispatch, never the payload* bullet is untouched.
        // Re-baselined: `## Testing Rules` gained the one-line pointer to the ban on widening
        // visibility for a test in `rules/php/core-standards.md`. Nothing else in the file moved.
        // Re-baselined: E2E behaviour evidence is preferred, and an isolated test requires a
        // prior failure inventory. `tests/Installer/SkillsContentTest.php` pins the new policy.
        'rules/code-testing/general.md' => '1b4898d6a320da8c1e102418f1088232128dad014216fcb76e338f0281b6505a',
        // Re-baselined: the JIRA publisher now updates the comment it already owns. It appends the
        // visible marker line `_cr-comment:actor=<acli-email>_`, converts the source to ADF, and
        // applies that ADF to the existing marker-carrying comment, creating one only when none
        // exists. The ADF-only write path and the self-assignment rule are unchanged.
        // Re-baselined: a failed helper with no JIRA MCP tool in the session now stops as blocked,
        // quoting the helper's stderr verbatim instead of paraphrasing a cause.
        // Re-baselined: comments are read through the loaders, whose body is rendered from the
        // view ADF, and a comment is deleted only through `delete-owned-comment.sh`.
        // Re-baselined: an issue carries one comment per actor holding the final result, a retry
        // goes through the helper again, and a JIRA MCP fallback updates that comment instead of
        // adding a second one. Nothing else in the file moved.
        'rules/jira/general.md' => '0bdf6cc4c26e5a33bd2b0fa9d4eb5f59ac8b23ad621f19e0aab5aec5c5536a72',
        'rules/php/dependency-selection.md' => '7633700bab79504ebcad864ec106cd3f9f44cc9b46c3740221e435c4d64a5ea6',
        // Re-baselined: the review stopped walking commit history, so the two commit-history
        // steps of the Test Coverage Contract became authoring guidance and the rule now states
        // what that costs — the proof that behaviour was preserved across a refactor.
        'rules/refactoring/general.md' => '6a541ac80dd25fe355284a7b8c9ead0e9371d1888b703941a8f89139182a3fc0',
        // Re-baselined: the file gained `## A JIRA comment is written for a non-technical
        // reader` — the banned-content list, its two exceptions, the 3 000-character cap, and
        // the sentence naming the pull-request comment as where the technical evidence moves
        // to. The language rule above it is unchanged apart from the JIRA section order.
        'rules/reports/general.md' => '83cbe17a46783d1fa48bba736bad03eee0ac57166b318549c90554ce188299ea',
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
    expect($alwaysOn)->toHaveCount(5);
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
        'rules/security/general.md',
    ]);

    foreach (array_keys(ruleScopingExpectedGlobs()) as $relativePath) {
        expect($alwaysOn)->not->toContain($relativePath);
    }
});

test('every rule the instruction-budget fix scoped to its own path is named by a skill or an agent', function (): void {
    // A rule scoped to its own installed path attaches only when an agent reads that path, and an
    // agent reads it only because a skill or an agent names it. A rule that loses its last
    // `@rules/…` reference in `skills/` or `agents/` is therefore never loaded again, and nothing
    // else in the build would say so. Rule files naming each other do not count: no rule reaches an
    // agent on its own any more once all of them are on demand.
    $walked = packageTextFiles();
    $unreferenced = [];

    foreach (array_keys(ruleScopingGlobsAddedByTotalBudget()) as $relativePath) {
        $referencingFiles = array_filter(
            $walked,
            static fn (string $contents, string $file): bool => preg_match('#^(skills|agents)/#', $file) === 1
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

test('the always-on compound-engineering rule points every tracker run at its on-demand sibling', function (): void {
    // The tracker workflow moved out of the always-on file to keep the total budget; the pointer is
    // what still tells a run that never touches `.claude/run/` that the file exists and applies.
    $packageDir = dirname(__DIR__, 2);
    $general = (string) file_get_contents($packageDir . '/rules/compound-engineering/general.md');
    $tracker = (string) file_get_contents($packageDir . '/rules/compound-engineering/tracker.md');

    expect($general)->toContain('## Tracker workflow — the companion file');
    expect($general)->toContain('reads and applies `@rules/compound-engineering/tracker.md` first');
    expect($general)->not->toContain('## Claim a tracker issue before working on it');

    foreach ([
        '## Analyze every comment before you act on a tracker assignment',
        '## Fix the cause first, then repair the data it already wrote',
        '## Claim a tracker issue before working on it',
        '## Tracker status tracks the phase of work',
        '## Every pull request links back to its tracker issue',
        '## File deferred points as follow-up tracker issues',
        '## Label tracker issues, and keep the labels true',
    ] as $heading) {
        expect($tracker)->toContain($heading);
        expect($general)->toContain('*' . substr($heading, 3) . '*');
    }
});

test('the always-on rules point at every section the second budget pass moved on demand', function (): void {
    // Each pointer is what still tells a session that never read the on-demand file that it exists
    // and applies. Losing one silences the moved sections exactly as deleting them would.
    $packageDir = dirname(__DIR__, 2);
    $read = static fn (string $path): string => (string) file_get_contents($packageDir . '/' . $path);

    $git = $read('rules/git/general.md');
    $pullRequests = $read('rules/git/pull-requests.md');
    expect($git)->toContain('reads and applies `@rules/git/pull-requests.md` first');

    foreach (['## Issue Linking', '## Pull Requests', '## PR Lifecycle', '## Merging'] as $heading) {
        expect($pullRequests)->toContain("\n" . $heading . "\n");
        expect($git)->not->toContain("\n" . $heading . "\n");
    }

    $compound = $read('rules/compound-engineering/general.md');
    expect($compound)->toContain('reads and applies `@rules/compound-engineering/memory.md` first');
    expect($compound)->not->toContain('## Compound Memory (per project)');
    expect($read('rules/compound-engineering/memory.md'))->toContain('## Compound Memory (per project)');

    expect($read('rules/writing/general.md'))->toContain('reads and applies `@rules/reports/general.md` first');
});
