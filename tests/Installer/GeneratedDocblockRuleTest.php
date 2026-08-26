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

    // The prohibition names all three declaration kinds; dropping any one leaves the shape it
    // covers arguable, which is exactly the gap the issue reported.
    expect($standards)->toContain('**Never generate a docblock that describes the logic of a class, a method, or a property.**');

    // The three recurring shapes, each stated so a reviewer can match a real docblock against it.
    expect($standards)->toContain('a **class** docblock telling the reader what the class does');
    expect($standards)->toContain('a **property** docblock describing what the property holds');
    expect($standards)->toContain('a **generated template** that describes logic while carrying no fact the code cannot carry');

    // Without this the rule reads as "shorten the docblock", which is the wrong half — the
    // information belongs in the name, not in fewer lines of prose above it.
    expect($standards)->toContain('The prescribed fix is the **rename**, never the shorter docblock');
    expect($standards)->toContain('the property\'s name what it holds');

    // The exemptions are what keep the rule from firing on facts the code provably cannot carry.
    expect($standards)->toContain('a `@param` / `@return` line carrying a constraint the type system cannot express');
    expect($standards)->toContain('`@see` and the other navigation markers');
    expect($standards)->toContain('a docblock generated or owned by `vendor/`');
    expect($standards)->toContain('a docblock this ruleset itself mandates');
    expect($standards)->toContain('and a *why* comment — a decision, a rejected alternative, an external ticket / CVE / RFC reference');
});

test('the generated-docblock rule extends the existing Documentation section rather than forking it (issue #22)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $standards = (string) file_get_contents($packageDir . '/rules/php/core-standards.md');

    // Exactly one home. A second occurrence would mean the rule was copied into a parallel
    // section, which is the duplication the issue's reuse-first gate exists to prevent.
    expect(substr_count($standards, '**Never generate a docblock that describes the logic'))->toBe(1);

    // It builds on the naming-first bullet instead of restating it.
    expect($standards)->toContain('*Naming comes first* above governs the comment a fact has earned');
    expect($standards)->toContain('**Naming comes first, even for a *why* comment.**');

    // It lives inside `## Documentation`, between the naming-first bullet it extends and the
    // PHPDoc-volume bullet it constrains — not in a section of its own after them.
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

    // The exemptions, so the trigger stays off facts the code cannot carry.
    expect($crRule)->toContain('(d) a `@param` / `@return` line carrying a constraint the type system cannot express');
    expect($crRule)->toContain('(e) a docblock generated or owned by `vendor/`');
    expect($crRule)->toContain('(f) a docblock this ruleset itself mandates');
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

    // The two bullets can both see a generated docblock, so the boundary between them is stated:
    // the volume bullet exempts one the diff did not write, this one flags one the diff adds.
    expect($crRule)->toContain('It does not contradict the volume bullet\'s exemption (f) below either');
    expect($crRule)->toContain('one finding per docblock, never both');

    // The bullet the CR skill already enumerates is the one that grew, so no skill enumeration
    // entry had to be added — this pins that the enumeration still reaches the new shapes.
    $skill = (string) file_get_contents($packageDir . '/skills/code-review/SKILL.md');
    expect($skill)->toContain('explanatory comments / docs that restate the code (issue #53)');
});
