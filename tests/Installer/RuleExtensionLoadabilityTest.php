<?php

declare(strict_types = 1);

/**
 * Issue #187 — Claude Code loads only `.md` files from `.claude/rules/`. Every rule this package
 * declared as always-on lived in a `.mdc` file (Cursor's extension) and therefore never reached a
 * Claude Code session at all, while the frontmatter kept promising it did.
 *
 * These tests pin the fix so it cannot silently regress: the always-on set is `.md`, it expresses
 * "always" the way Claude Code documents it (no `paths` key), and nothing still points a reader at
 * the retired `.mdc` path.
 */
test('every always-on rule ships as .md so Claude Code actually loads it (issue #187)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $missing = [];
    $revived = [];

    foreach (ruleExtensionAlwaysOnFiles() as $relativePath) {
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
    // `CHANGELOG.md` and the project memory are append-only records of what happened; rewriting a
    // path inside a dated entry would falsify the record, so they are excluded here by design and
    // the rename is explained in the changelog entry instead.
    $historicalRecord = ['CHANGELOG.md', 'docs/memory/PROJECT_MEMORY.md'];
    $retiredPaths = array_map(
        static fn (string $rule): string => substr($rule, 0, -3) . '.mdc',
        ruleExtensionAlwaysOnFiles(),
    );

    $stale = [];
    $walked = packageTextFiles();

    // Without this the test passes vacuously the moment the walk stops finding anything — the
    // one failure mode a "nothing matched" assertion cannot tell apart from success.
    expect($walked)->toHaveKey('README.md');
    expect($walked)->toHaveKey('skills/resolve-issue/SKILL.md');
    expect(count($walked))->toBeGreaterThan(200);

    foreach ($walked as $relativePath => $contents) {
        if (in_array($relativePath, $historicalRecord, strict: true)) {
            continue;
        }

        foreach ($retiredPaths as $retired) {
            if (str_contains($contents, $retired)) {
                $stale[] = $relativePath . ' → ' . $retired;
            }
        }
    }

    expect($stale)->toBe([]);
});
