<?php

declare(strict_types = 1);

/**
 * Issue #223 — a code review that only checks correctness passes code that works on ten rows and
 * falls over on a million, because ten rows is all the reviewer ever sees.
 *
 * The existing rules already owned DB round-trips issued per row inside a loop. What they did not
 * own is how much data is held at once, and per-item work outside the database. These tests pin
 * the added rules, their severities, the gating that keeps them from double-reporting a line the
 * neighbouring rules already own, and the skill enumeration that makes the walk actually run.
 */
test('the SQL rule requires bounded reads instead of materialising a growing set (issue #223)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $rule = (string) file_get_contents($packageDir . '/rules/sql/optimalize.md');

    expect($rule)->toContain('## Bounded reads over unbounded materialisation');

    // The three facts that make the section actionable rather than a preference.
    expect($rule)->toContain('`chunkById()`');
    expect($rule)->toContain('`lazyById()`');
    expect($rule)->toContain('`cursor()`');

    // The keyset-vs-offset point is a correctness argument, not a speed one; losing it turns the
    // section into "prefer chunkById because it is faster", which it is not.
    expect($rule)->toContain('silently skips rows');
    expect($rule)->toContain('This is a correctness bug, not only a performance one');

    // The carve-out keeps the rule from flagging every `->get()` in the codebase.
    expect($rule)->toContain('hard, small upper bound');

    // An IN list is the other unbounded statement shape the batching section did not cover.
    expect($rule)->toContain('max_allowed_packet');
});

test('the code-review rule carries the bulk-data walk with its severities and gating (issue #223)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $rule = (string) file_get_contents($packageDir . '/rules/code-review/general.mdc');

    expect($rule)->toContain('## Bulk Data & Batch Processing (issue #223)');

    // Three distinct defects, each with its own fix — collapsing them into one "performance"
    // bullet is what let the gap exist in the first place.
    expect($rule)->toContain('**Unbounded materialisation.**');
    expect($rule)->toContain('**Offset paging while writing to the set being read.**');
    expect($rule)->toContain('**Per-item work in a loop that the platform can do in bulk**');

    // The bulk primitives are named, so a Suggested Fix cannot degrade into "batch this".
    foreach (['`Http::pool()`', '`Notification::send($collection, …)`', '`Bus::batch([...])`', '`Cache::putMany()`'] as $primitive) {
        expect($rule)->toContain($primitive);
    }

    // Severity is declared per defect; the skipped-rows one is Critical because it is a
    // correctness defect, not a slow one.
    expect($rule)->toContain('Severity: **Critical** — this silently processes a subset and reports success');

    // Without the gating these checks would double-report lines the neighbouring rules own.
    expect($rule)->toContain('**Gating — raise one finding per violation, never two.**');
    expect($rule)->toContain('**Every finding here states the volume it fails at.**');
});

test('the code-review skill runs the bulk-data walk it inherits (issue #223)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $skill = (string) file_get_contents($packageDir . '/skills/code-review/SKILL.md');

    // The skill enumerates the Core Analysis walk-through by name, so a rule section absent from
    // that list is a rule the review never reaches.
    expect($skill)->toContain('**Bulk data & batch processing (issue #223)**');
});
