<?php

declare(strict_types = 1);

test('simplification-audit fires only on an explicit user request and never as a side effect of another workflow', function (): void {
    $skill = (string) file_get_contents(dirname(__DIR__, 2) . '/skills/simplification-audit/SKILL.md');

    // Issue #9 states the trigger as the load-bearing half of the assignment: an audit
    // that starts on its own turns every unrelated run into a whole-repository sweep.
    expect($skill)->toContain('**Runs only on an explicit user request**');
    expect($skill)->toContain('Never start as a side effect of another skill or agent.');
    expect($skill)->toContain('**Never runs when:** no such explicit request was made.');
    expect($skill)->toContain('The user explicitly asks for an audit of the codebase');
    expect($skill)->toContain('The user explicitly decides to refactor a part of the codebase');
});

test('no other skill or agent wires simplification-audit as a step of its own pipeline', function (): void {
    $needle = '@skills/simplification-audit/SKILL.md';
    $callers = [];
    $skillOwnReferences = [];

    // The trigger constraint above is prose; this is the structural half of it. A single
    // `@skills/...` reference from resolve-issue, a review skill, or an agent would make the
    // audit reachable without the user ever asking for one, and the prose would still pass.
    foreach (packageTextFiles() as $path => $content) {
        if (!str_starts_with($path, 'skills/') && !str_starts_with($path, 'agents/')) {
            continue;
        }

        if (!str_contains($content, $needle)) {
            continue;
        }

        if (str_starts_with($path, 'skills/simplification-audit/')) {
            $skillOwnReferences[] = $path;

            continue;
        }

        $callers[] = $path;
    }

    // Guard against a vacuous pass: without this the assertion below would also hold on a
    // tree that carries no such skill at all, so a deletion would read as compliance.
    expect($skillOwnReferences)->not->toBe([]);
    expect($callers)->toBe([]);
});

test('simplification-audit forbids every write, run, and publish an audit could reach for', function (): void {
    $skill = (string) file_get_contents(dirname(__DIR__, 2) . '/skills/simplification-audit/SKILL.md');

    expect($skill)->toContain('**Audit-only and read-only.**');
    expect($skill)->toContain('Never edit, create, or delete a file.');
    expect($skill)->toContain('Never run the test suite, a fixer, a static analyser, or a build.');
    expect($skill)->toContain('Never stage, commit, push, or open a pull request.');
    expect($skill)->toContain('The repository is byte-identical when the audit ends.');
    expect($skill)->toContain('**Publishes nothing.** No tracker comment, no issue, no PR.');
});

test('simplification-audit carries all four audit steps with the coverage contract as the first one', function (): void {
    $skill = (string) file_get_contents(dirname(__DIR__, 2) . '/skills/simplification-audit/SKILL.md');

    expect($skill)->toContain('### 1. Establish the coverage contract');
    expect($skill)->toContain('### 2. Run bounded subsystem reviews');
    expect($skill)->toContain('### 3. Validate and synthesize');
    expect($skill)->toContain('### 4. Audit the audit');
    // A catch-all row that claims a whole tree is how a coverage contract lies about coverage.
    expect($skill)->toContain('A broad catch-all row does not prove coverage');
    expect($skill)->toContain('Never hide an omission by broadening a boundary that is already marked complete.');
});

test('every inventory row and every status value the assignment names is present in step 1', function (): void {
    $skill = (string) file_get_contents(dirname(__DIR__, 2) . '/skills/simplification-audit/SKILL.md');

    expect($skill)->toContain('a stable ID and a descriptive name;');
    expect($skill)->toContain('an exact ownership boundary;');
    expect($skill)->toContain('its key implementation files;');
    expect($skill)->toContain('its relevant public interfaces, major call sites, and tests;');

    foreach (['`queued`', '`in review`', '`recommend`', '`skip`'] as $status) {
        expect($skill)->toContain($status);
    }
});

test('the worker brief keeps every look-for signal and every do-not-force clause the assignment dictates', function (): void {
    $brief = (string) file_get_contents(dirname(__DIR__, 2) . '/skills/simplification-audit/references/worker-brief.md');

    expect($brief)->toContain('at most two materially useful simplifications');
    expect($brief)->toContain('scattered booleans or nullable fields that permit invalid combinations');
    expect($brief)->toContain('repeated assumptions about object shape that need a shared typed model');
    expect($brief)->toContain('duplicated branching that a small map, registry, reducer, or command model would remove');
    expect($brief)->toContain('unclear state or behavior ownership that a small module boundary would clarify');
    expect($brief)->toContain('repeated scans, transformations, or lookups');
    expect($brief)->toContain('lifecycle, concurrency, or async states whose representation permits stale or contradictory state');
    expect($brief)->toContain('Do not force an abstraction.');
    expect($brief)->toContain(
        'stylistic consistency, hypothetical extensibility, minor line-count reduction, or moving existing branching behind a new type',
    );
    expect($brief)->toContain('If nothing clearly meets the threshold, return skip.');
});

test('the read-only limit sits inside the block a worker is actually handed', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $brief = (string) file_get_contents($packageDir . '/skills/simplification-audit/references/worker-brief.md');
    $skill = (string) file_get_contents($packageDir . '/skills/simplification-audit/SKILL.md');

    // The worker receives the quoted block, nothing else. A read-only limit sitting outside
    // that block reaches the coordinator and never the worker it is meant to constrain.
    preg_match_all('/^>.*$/m', $brief, $quotedLines);
    $quotedBlock = implode("\n", $quotedLines[0]);

    expect($quotedBlock)->toContain('You are read-only, exactly as the coordinator is.');
    expect($quotedBlock)->toContain('Never edit a file, run a test, implement a recommendation, commit, or push.');

    // Both files must name the same thing as "the brief", or the handover splits in two again.
    expect($brief)->toContain('Hand the quoted block below over verbatim');
    expect($brief)->toContain('That block is the whole of what a worker receives');
    expect($skill)->toContain('Hand every worker the quoted brief block in `references/worker-brief.md` verbatim');
    expect($skill)->toContain('That block is the whole of what a worker receives');
});

test('the worker brief numbers all eight required recommendation fields', function (): void {
    $brief = (string) file_get_contents(dirname(__DIR__, 2) . '/skills/simplification-audit/references/worker-brief.md');

    $fields = [
        '1. **Verdict**',
        '2. **Evidence**',
        '3. **Current complexity or invalid states**',
        '4. **Proposed representation and why it is simpler**',
        '5. **Smallest credible implementation scope**',
        '6. **Regression risks and migration concerns**',
        '7. **Existing and additional validation required**',
        '8. **Confidence**',
    ];

    foreach ($fields as $field) {
        expect($brief)->toContain($field);
    }
});

test('the report template holds every section the canonical audit report has to carry', function (): void {
    $template = (string) file_get_contents(dirname(__DIR__, 2) . '/skills/simplification-audit/templates/audit-report.md');

    $sections = [
        '## Subsystem inventory (the coverage contract)',
        '## Accepted recommendations',
        '## Explicit skip decisions',
        '## Cross-cutting patterns',
        '## Duplicates and superseded findings',
        '## Priorities and dependencies',
        '## Audit log',
    ];

    foreach ($sections as $section) {
        expect($template)->toContain($section);
    }
});

test('simplification-audit delegates implementation to the refactoring skills instead of doing it', function (): void {
    $skill = (string) file_get_contents(dirname(__DIR__, 2) . '/skills/simplification-audit/SKILL.md');

    // Reuse-first: the audit owns the verdict, the existing refactoring skills own the edit.
    expect($skill)->toContain('It never implements');
    expect($skill)->toContain('@skills/class-refactoring/SKILL.md');
    expect($skill)->toContain('@skills/refactor-entry-point-to-action/SKILL.md');
    expect($skill)->toContain('@skills/analyze-problem/SKILL.md');
    expect($skill)->toContain('@skills/code-review/SKILL.md');
});

test('every rule and sibling file simplification-audit references resolves to a real path', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $skillDir = $packageDir . '/skills/simplification-audit';
    $skill = (string) file_get_contents($skillDir . '/SKILL.md');

    preg_match_all('/@(rules|skills)\/[A-Za-z0-9._\/-]+\.md/', $skill, $references);
    expect($references[0])->not->toBeEmpty();

    foreach (array_unique($references[0]) as $reference) {
        expect(is_file($packageDir . '/' . ltrim($reference, '@')))->toBeTrue('dangling reference: ' . $reference);
    }

    expect(is_file($skillDir . '/references/worker-brief.md'))->toBeTrue();
    expect(is_file($skillDir . '/templates/audit-report.md'))->toBeTrue();
});
