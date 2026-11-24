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
    $rule = codeReviewRuleContents();

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

test('the bulk-data walk reports in-memory work that grows faster than the data (issue #680)', function (): void {
    $section = packageSection(
        packageSource('rules/code-review/general.md'),
        '## Bulk Data & Batch Processing (issue #223)',
        '## The author of the changes is the account that pushed the pull request',
    );

    // A fourth check of the same walk, never a second walk: the section owns it and states its shape.
    expect($section)->toContain('Four defects belong here');
    expect($section)->toContain('**Work that grows faster than the data.**');
    expect($section)->toContain('a search through one list');
    expect($section)->toContain('issued once per item of another list');
    expect($section)->toContain('two nested passes over the same list');

    // Only data that grows with the customer's data counts.
    expect($section)->toContain('Both lists must grow with the customer\'s data.');
    expect($section)->toContain('A walk over a small, fixed set (three languages, the days of a week, a handful of settings) is never a finding.');

    // The finding gives the growth as a number and proposes a lookup built once.
    expect($section)->toContain('10 times more records means 100 times more work');
    expect($section)->toContain('build a lookup keyed by the matched value once');

    // It blocks like the other findings of the section, reads only the changed lines, and anchors to the search.
    expect($section)->toContain('Severity: **Moderate**, which blocks the merge as the other findings of this section do.');
    expect($section)->toContain('Only the lines the diff adds or changes are checked');

    // One finding per place, and the database cost stays with the database owners.
    expect($section)->toContain('never the cost of a database query or a measured run time');
    expect($section)->toContain('is never reported a second time as work that grows faster than the data');

    // The comparison inside the inner pass carries the finding, because a finding anchored to a loop is dropped by the `foreach` clause.
    expect($section)->toContain('anchored to the search, or to the comparison inside the inner pass of a nested pass, never to a loop');
    expect($section)->toContain('*A `foreach` is never a code-review finding* below drops a finding anchored to a loop');

    $foreachClause = packageSection(
        packageSource('rules/code-review/general.md'),
        '## A `foreach` is never a code-review finding',
        '## A review comment assigned to somebody else is left alone',
    );
    expect($foreachClause)->toContain('work that grows faster than the data stays a finding under the same section');
    expect($foreachClause)->toContain('to the search or to the comparison inside the inner pass');

    // The core-analysis walk names the fourth check, so the review actually reaches it.
    expect(packageSource('rules/code-review/core-analysis.md'))
        ->toContain('Apply the four checks in `@rules/code-review/general.md` *Bulk Data & Batch Processing (issue #223)*')
        ->toContain('and in-memory work that grows faster than the data');
});
