<?php

declare(strict_types = 1);

/**
 * Issue #22 — a generated docblock above a class, a method, or a property describes the logic the
 * declaration below it already states. The rules already covered redundancy (issue #53) and volume
 * (issue #179); neither named the declaration-level shapes, and neither prescribed the rename that
 * actually fixes them.
 *
 * These tests pin the prohibition, the rename as the prescribed fix, the exemption list that keeps
 * the rule from producing noise, and the single home the rule lives in.
 */
test('the PHP standards forbid a generated docblock that describes logic (issue #22)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $standards = (string) file_get_contents($packageDir . '/rules/php/core-standards.md');

    expect($standards)->toContain('**Never generate a docblock that describes the logic of a class, method, or property.**');
    expect($standards)->toContain('A declaration-level docblock is allowed only for type analysis.');
    expect($standards)->toContain('clearer name or structure, never a shorter description');
    expect($standards)->toContain('Vendor-owned docblocks are outside this rule.');
});

test('the generated-docblock rule extends the existing Documentation section rather than forking it (issue #22)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $standards = (string) file_get_contents($packageDir . '/rules/php/core-standards.md');

    // Exactly one home. A second occurrence would mean the rule was copied into a parallel
    // section, which is the duplication the issue's reuse-first gate exists to prevent.
    expect(substr_count($standards, '**Never generate a docblock that describes the logic'))->toBe(1);

    // It lives inside `## Documentation`, rather than in a parallel rule.
    $documentationStart = strpos($standards, '## Documentation');
    $documentationEnd = strpos($standards, '## Testing');
    $rulePosition = strpos($standards, '**Never generate a docblock that describes the logic');

    expect($rulePosition)->toBeGreaterThan((int) $documentationStart);
    expect($rulePosition)->toBeLessThan((int) $documentationEnd);
});

test('the code-review walk finds the generated docblock on the changed lines (issue #22)', function (): void {
    $crRule = codeReviewRuleContents();

    // The two shapes the #53 bullet never named. Without them a reviewer had to argue the
    // category into an existing pattern instead of matching it.
    expect($crRule)->toContain('a **property docblock** whose prose says what the property holds');
    expect($crRule)->toContain('a **generated docblock template** the diff itself adds');
    expect($crRule)->toContain('that describes the logic below it while carrying no fact the code cannot carry (issue #22)');

    // The fix these three shapes need is the name, which is what separates them from the rest of
    // the bullet, where deleting the comment is already the whole fix.
    expect($crRule)->toContain('the **Suggested Fix** is the **rename itself**, never a shorter docblock');
    expect($crRule)->toContain('(*Never generate a docblock that describes the logic of a class, a method, or a property*)');

    // Type analysis is the sole code-owned PHPDoc exception.
    expect($crRule)->toContain('PHPDoc used for type analysis beyond native declarations');
});

test('the generated-docblock trigger extends the issue #53 bullet instead of forking a rival one (issue #22)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $crRule = codeReviewRuleContents();

    // Reuse-first: the category lives inside the bullet that already owns redundancy. A second
    // bullet would have needed a gating clause; extending the first one needs none, and this
    // assertion is what keeps a later edit from splitting it back out.
    $redundancyBullet = strpos($crRule, '- **Explanatory comments and docs that restate the code (issue #53)**');
    $volumeBullet = strpos($crRule, '- **Extensive PHPDoc / inline commentary standing in for readable code**');
    $newShape = strpos($crRule, 'a **generated docblock template** the diff itself adds');

    expect($newShape)->toBeGreaterThan((int) $redundancyBullet);
    expect($newShape)->toBeLessThan((int) $volumeBullet);
    expect(substr_count($crRule, 'a **generated docblock template** the diff itself adds'))->toBe(1);

    // Vendor-owned docblocks are out of review scope, while a diff-added template is reviewed.
    expect($crRule)->toContain('It does not contradict the volume bullet\'s scope either');
    expect($crRule)->toContain('one finding per docblock, never both');

    // The bullet the CR skill already enumerates is the one that grew, so no skill enumeration
    // entry had to be added — this pins that the enumeration still reaches the new shapes.
    $skill = (string) file_get_contents($packageDir . '/skills/code-review/SKILL.md');
    expect($skill)->toContain('explanatory comments / docs that restate the code (issue #53)');
});
