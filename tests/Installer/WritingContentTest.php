<?php

declare(strict_types = 1);

test('writing/general.md rule ships in the package and applies to every run', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $rulePath = $packageDir . '/rules/writing/general.md';

    expect(is_file($rulePath))->toBeTrue();

    $content = (string) file_get_contents($rulePath);

    // Frontmatter: no `paths` key, which is how Claude Code expresses "load unconditionally"
    // (issue #187). The Cursor-only `alwaysApply` key this used to pin was never read by
    // Claude Code, so pinning it proved nothing about whether the rule actually loads.
    expect($content)->not->toContain('paths:');
    expect($content)->toContain('Simplified Technical Writing (ASD-STE100)');
});

test('writing/general.md states the style rules as rules, not as advice', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/rules/writing/general.md');

    // Each of these is a distinct failure mode the rule exists to prevent; dropping any
    // one of them turns the rule back into the vague "write clearly" it replaces.
    expect($content)->toContain('**One idea per sentence.**');
    expect($content)->toContain('**Short sentences.**');
    expect($content)->toContain('**Active voice, named actor.**');
    expect($content)->toContain('**Present tense for what is true; past tense only for what happened.**');
    expect($content)->toContain('**One term per concept, always the same term.**');
    expect($content)->toContain('**No telegraphic compression.**');
    expect($content)->toContain('**Short paragraphs.**');
    expect($content)->toContain('**Sequences are numbered lists.**');
    expect($content)->toContain('**No marketing register.**');
    expect($content)->toContain('**No hedging where a fact is available.**');
    expect($content)->toContain('**Nested conditions get unnested.**');
});

test('writing/general.md is language-neutral and never mandates English', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/rules/writing/general.md');

    // The standard is written for English, but this package applies its structural rules to
    // the assignment language — importing the approved-word dictionary would do the opposite.
    expect($content)->toContain('Language neutrality (this rule never forces English)');
    expect($content)->toContain('principles apply to whatever language the output is in');
    expect($content)->toContain('approved-word dictionary');
    expect($content)->toContain('verbatim');
});

test('the writing and reports rules declare each other as scope boundaries', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $writing = (string) file_get_contents($packageDir . '/rules/writing/general.md');
    $reports = (string) file_get_contents($packageDir . '/rules/reports/general.mdc');

    // `reports` owns which language a report is written in; `writing` owns how the sentences
    // are shaped inside it. Without the cross-reference the two read as competing mandates.
    expect($writing)->toContain('Scope boundary — style, not language choice');
    expect($writing)->toContain('@rules/reports/general.mdc');
    expect($reports)->toContain('@rules/writing/general.md');
    expect($reports)->toContain('This rule picks the language');
});

test('readme rules overview lists the writing/general.md rule', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $readme = (string) file_get_contents($packageDir . '/README.md');

    expect($readme)->toContain('`writing/general.md`');
    expect($readme)->toContain('Simplified technical writing (ASD-STE100 principles)');
});
