<?php

declare(strict_types = 1);

/**
 * Issue #187 — Claude Code loads only `.md` files from `.claude/rules/`. Every rule this package
 * declared as always-on lived in a `.mdc` file (Cursor's extension) and therefore never reached a
 * Claude Code session at all, while the frontmatter kept promising it did.
 *
 * These tests pin the fix so it cannot silently regress: every renamed rule ships as `.md`, the
 * ones that are still always-on express "always" the way Claude Code documents it (no `paths`
 * key), and nothing still points a reader at a retired `.mdc` path.
 */
test('every rule renamed away from .mdc ships as .md so Claude Code actually loads it (issue #187)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $missing = [];
    $revived = [];

    foreach (ruleExtensionRenamedFromMdcFiles() as $relativePath) {
        if (!is_file($packageDir . '/' . $relativePath)) {
            $missing[] = $relativePath;
        }

        $retired = substr($relativePath, 0, -3) . '.mdc';

        if (is_file($packageDir . '/' . $retired)) {
            $revived[] = $retired;
        }
    }

    expect($missing)->toBe([]);
    expect($revived)->toBe([]);
});

test('the rules tree ships one extension and one scoping key (issue #277)', function (): void {
    // Two dialects side by side meant the author of a new rule had to choose, and half the
    // choices were wrong: a `.mdc` file is one Claude Code never loads, and `globs:` /
    // `alwaysApply:` are keys it never reads. Neither may come back.
    $packageDir = dirname(__DIR__, 2);
    $ruleFiles = ruleTreeFiles();
    $violations = [];

    foreach ($ruleFiles as $relativePath) {
        if (!str_ends_with($relativePath, '.md')) {
            $violations[] = $relativePath . ': is not a `.md` file, so Claude Code never loads it';

            continue;
        }

        $frontmatter = ruleExtensionFrontmatter($packageDir . '/' . $relativePath);

        if (str_contains($frontmatter, 'globs') || str_contains($frontmatter, 'alwaysApply')) {
            $violations[] = $relativePath . ': carries a Cursor-only frontmatter key';
        }
    }

    // Without this the test passes vacuously the moment the walk stops finding anything.
    expect($ruleFiles)->toContain('rules/php/core-standards.md');
    expect(count($ruleFiles))->toBeGreaterThan(20);
    expect($violations)->toBe([]);
});

test('an always-on rule declares "always" the way Claude Code documents it (issue #187)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $violations = [];

    foreach (ruleExtensionAlwaysOnFiles() as $relativePath) {
        $frontmatter = ruleExtensionFrontmatter($packageDir . '/' . $relativePath);

        // Per Claude Code's memory documentation, a rule with no `paths` field is loaded
        // unconditionally; a `paths` list scopes it. There is no third state, so "always" is the
        // absence of the key — never a Cursor `alwaysApply: true` the loader has no field for.
        if (str_contains($frontmatter, 'paths:')) {
            $violations[] = $relativePath . ': scopes itself, so it is no longer always-on';
        }

        if (str_contains($frontmatter, 'alwaysApply') || str_contains($frontmatter, 'globs')) {
            $violations[] = $relativePath . ': still carries a Cursor-only frontmatter key';
        }

        if (!str_contains($frontmatter, 'description:')) {
            $violations[] = $relativePath . ': lost its description';
        }
    }

    expect($violations)->toBe([]);
});

test('nothing outside the historical record still points at a retired .mdc rule path (issue #187)', function (): void {
    $walked = packageTextFiles();

    // Without this the test passes vacuously the moment the walk stops finding anything — the
    // one failure mode a "nothing matched" assertion cannot tell apart from success.
    expect($walked)->toHaveKey('README.md');
    expect($walked)->toHaveKey('skills/resolve-issue/SKILL.md');
    expect(count($walked))->toBeGreaterThan(200);

    expect(ruleExtensionStaleMdcReferences($walked))->toBe([]);
});

test('the retired path of every rule scoping took off the always-on list is still swept (issue #274)', function (): void {
    // Issue #274 scoped four of the seven rules issue #187 renamed, which took them off
    // `ruleExtensionAlwaysOnFiles()`. Driving the sweep off that list would have narrowed it to
    // three paths, so a revived reference to one of the four would pass the whole suite unnoticed.
    // The references are assembled here rather than written out, so the sweep over this
    // repository's own files does not read this fixture as a real one.
    //
    // Issue #275 added an entry to `ruleScopingExpectedGlobs()` — a brand-new file that was never
    // `.mdc` and never on the always-on list, so it has no retired path to sweep for. Issue #277
    // added five more that do: the Laravel rules it renamed out of `.mdc` and scoped in the same
    // move. This test's concern is narrower than "every scoped rule": only the ones renamed from
    // `.mdc` and also scoped have a retired `.mdc` path at all.
    $retiredPath = static fn (string $rule): string => substr($rule, 0, -3) . '.mdc';
    $scopedRules = array_values(array_intersect(array_keys(ruleScopingExpectedGlobs()), ruleExtensionRenamedFromMdcFiles()));
    $corpus = ['CHANGELOG.md' => 'Renamed ' . $retiredPath('rules/php/core-standards.md') . ' to .md.'];
    $expected = [];

    foreach ($scopedRules as $rule) {
        $file = 'docs/' . str_replace('/', '-', $rule);
        $corpus[$file] = 'See @' . $retiredPath($rule) . ' for details.';
        $expected[] = $file . ' → ' . $retiredPath($rule);
    }

    expect($scopedRules)->toHaveCount(11);
    expect(ruleExtensionStaleMdcReferences($corpus))->toBe($expected);
});
