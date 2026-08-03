<?php

declare(strict_types = 1);

test('php/examples/named-arguments.md gains scoping frontmatter and stays reachable from core-standards.mdc (issue #162)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/rules/php/examples/named-arguments.md');

    expect($content)->toStartWith("---\n");
    expect($content)->toContain("paths:\n");
    expect($content)->toContain('  - "**/*.php"');
    expect($content)->toContain('# Named Arguments Examples');
    // Cursor-only keys must not reappear — Claude Code has no `globs`/`alwaysApply`
    // field (see https://code.claude.com/docs/en/memory, "Path-specific rules").
    expect($content)->not->toContain('globs:');
    expect($content)->not->toContain('alwaysApply:');

    $coreStandards = (string) file_get_contents($packageDir . '/rules/php/core-standards.mdc');

    expect($coreStandards)->toContain('See `rules/php/examples/named-arguments.md` for usage examples.');
});
