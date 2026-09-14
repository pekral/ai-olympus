<?php

declare(strict_types = 1);

/**
 * A defect that ran in production wrote its result somewhere. Resolving it is therefore two pieces
 * of work — the cause and the records the cause already wrote — and the order between them is
 * causal: a repair executed while the cause is still live is re-corrupted by the next request that
 * takes the broken path.
 *
 * Nothing in the package used to say either half. These tests pin the principle, the two executors
 * that carry it (the analysis that finds the damage, the resolution that repairs it), and the
 * review bullet that turns it from advice into a gate.
 */
test('the compound-engineering rule states the ordering and why it is causal', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $rule = (string) file_get_contents($packageDir . '/rules/compound-engineering/general.md');

    expect($rule)->toContain('## Fix the cause first, then repair the data it already wrote');

    // The ordering is the whole point, and the reason is what keeps it from reading as taste.
    expect($rule)->toContain('**The cause is fixed first, and the order is causal, never a preference.**');
    expect($rule)->toContain('re-corrupts what it just repaired');

    // Both halves are owed; delivering one of them is the failure mode the section exists to stop.
    expect($rule)->toContain('A run that delivers only one of the two has not resolved the issue.');

    // A damage claim read off the code rather than the storage is a guess wearing an assessment's
    // clothes, and the repair needs the predicate anyway.
    expect($rule)->toContain('**A damage claim is verified against the storage, never assumed from the code.**');

    // The repair is assignment work, so it cannot quietly become nice-to-have cleanup.
    expect($rule)->toContain('**The repair belongs to the assignment, not to a follow-up.**');
    expect($rule)->toContain('**Deliberately leaving data unrepaired is a stated decision.**');

    // One home for the principle, named executors for the mechanics.
    expect($rule)->toContain('This section owns the principle; those own the execution.');
});

test('analyze-problem assesses the damage and proposes the two ordered halves', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $skill = (string) file_get_contents($packageDir . '/skills/analyze-problem/SKILL.md');
    $template = (string) file_get_contents($packageDir . '/skills/analyze-problem/templates/analysis-report.md');

    // The damage is settled in the impact step, where the analysis already looks at what the cause
    // touches — not in a step that runs after somebody has written the fix.
    expect($skill)->toContain('This step also settles the **data damage**');
    expect($skill)->toContain('a count plus a predicate is an assessment');
    expect($skill)->toContain('two ordered halves');
    expect($skill)->toContain('Never propose the repair first or alone');

    // The report is where the next agent reads it, so both fields have to exist in the template or
    // the requirement has nowhere to land.
    expect($template)->toContain('### Data Damage Already Written');
    expect($template)->toContain('**Data repair (only when section 6 found damaged data):**');
});

test('resolve-issue repairs the damaged records after the cause fix, never before', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $skill = (string) file_get_contents($packageDir . '/skills/resolve-issue/SKILL.md');
    $reference = (string) file_get_contents($packageDir . '/skills/resolve-issue/references/data-repair.md');

    // The skill body sits against its own token budget, so the ordering is stated on the GREEN
    // step itself and the procedure lives in the reference — the extraction pattern this skill
    // already uses nine times over.
    expect($skill)->toContain('Then repair the records the defect already wrote — after this fix, never before (`references/data-repair.md`)');
    expect($skill)->toContain('- references/data-repair.md');
    expect($skill)->toContain('records the defect already wrote are repaired, filed, or stated disposable');

    // The damage is read off the storage, and the finding is the repair's own WHERE clause.
    expect($reference)->toContain('## 1. Decide whether damage exists — from the storage, not from the code');
    expect($reference)->toContain('**count plus the predicate that identifies a damaged record**');
    expect($reference)->toContain('**No damaged record found is an answer, and it is recorded as one.**');

    // The repair is a bounded, re-runnable operation with its own test, and it is reported.
    expect($reference)->toContain('## 2. Ship the repair with the fix');
    expect($reference)->toContain('**Bounded and re-runnable.**');
    expect($reference)->toContain('**Covered by its own test.**');
    expect($reference)->toContain('## 3. Report it');

    // The two ways out are explicit, so neither becomes silence.
    expect($reference)->toContain('## 4. The two ways out — both are stated, neither is silence');
    expect($reference)->toContain('Skipping both is the one outcome that is never available.');
});

test('the code review raises the gap, in both orderings, with its gating', function (): void {
    $rule = codeReviewRuleContents();

    expect($rule)->toContain('- **Cause fixed, damaged data left behind** —');

    // Shipping the repair without the cause fix is the same defect, not half of one.
    expect($rule)->toContain('a backfill with no accompanying cause fix is the same finding');

    // Severity turns on whether the application still acts on the damaged records.
    expect($rule)->toContain('Severity: **Critical** when the application still reads the damaged records');

    // Without the gating this would double-report lines the three neighbouring data bullets own.
    expect($rule)->toContain('this bullet owns *the output a defect wrote*');

    // A walk absent from the skill's enumeration is a walk the review never reaches.
    $packageDir = dirname(__DIR__, 2);
    $reviewSkill = (string) file_get_contents($packageDir . '/skills/code-review/SKILL.md');
    expect($reviewSkill)->toContain('**cause fixed, damaged data left behind**');
});
