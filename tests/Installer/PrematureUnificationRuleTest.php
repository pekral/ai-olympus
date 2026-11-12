<?php

declare(strict_types = 1);

test('one design rule states that code is unified only when it changes for the same reason and warns about a shared base class', function (): void {
    $owner = (string) file_get_contents(dirname(__DIR__, 2) . '/rules/php/core-standards.md');

    expect(substr_count($owner, '- **Unify code only when it changes for the same reason.** A similar shape is not a reason.'))->toBe(1)
        ->and(substr_count($owner, '  - **A shared base class couples every subclass.**'))->toBe(1)
        ->and($owner)->toContain('One base class for different factories, builders, handlers, or other unrelated subclasses')
        ->and($owner)->toContain('a change to one behaviour in the base class a change to every other subclass, and any of them can break')
        ->and(substr_count($owner, '  - **Prefer composition or a small helper.**'))->toBe(1)
        ->and($owner)->toContain('A base class is justified only when every subclass shares one invariant')
        ->and(substr_count($owner, '  - **This rule never overrides DRY.**'))->toBe(1)
        ->and($owner)->toContain('applies to the same rule implemented twice. This rule applies to different rules that only look alike.');
});

test('the rule is stated once and the neighbouring rules are referenced, not restated', function (): void {
    $files = packageTextFiles();
    $statements = array_filter(
        $files,
        static fn (string $contents, string $path): bool => preg_match('#^(rules|skills|agents|commands)/#', $path) === 1
            && str_contains($contents, 'A shared base class couples every subclass.'),
        ARRAY_FILTER_USE_BOTH,
    );

    expect(array_keys($statements))->toBe(['rules/php/core-standards.md']);

    $owner = $files['rules/php/core-standards.md'];

    expect($owner)->toContain('`@rules/code-review/general.md` *Reuse Existing Logic*')
        ->and($owner)->toContain('*Structure* → *A subclass does not copy a parent method to change one step of it*');
});

test('the review entry is Moderate, names its evidence and exemptions, and is gated against the neighbouring findings', function (): void {
    $owner = (string) file_get_contents(dirname(__DIR__, 2) . '/rules/php/core-standards.md');
    $entryStart = strpos($owner, '  - a shared abstraction the diff adds or extends');

    expect($entryStart)->toBeInt();

    $entryEnd = (int) strpos($owner, '- Mark as **Critical**:');
    $entry = substr($owner, (int) $entryStart, $entryEnd - (int) $entryStart);

    expect($entry)->toContain('a base class, an abstract class, a trait, or a generic helper with flags')
        ->and($entry)->toContain('The evidence is a concrete difference between the callers on the changed lines')
        ->and($entry)->toContain('**Not findings:** an abstraction whose callers share one invariant')
        ->and($entry)->toContain('**Gating — one finding per line:**')
        ->and($entry)->toContain('an override that copies its parent method keeps its own finding above')
        ->and($entry)->toContain('an abstraction with a single call site keeps *Simplicity First*')
        ->and($entry)->toContain('a duplicate rule keeps *Reuse Existing Logic*');

    $moderateStart = (int) strpos($owner, '- Mark as **Moderate**:');
    $criticalStart = (int) strpos($owner, '- Mark as **Critical**:');

    expect((int) $entryStart)->toBeGreaterThan($moderateStart)
        ->and((int) $entryStart)->toBeLessThan($criticalStart);
});

test('Reuse Existing Logic leaves different rules that only look alike to the unification rule', function (): void {
    $dry = (string) file_get_contents(dirname(__DIR__, 2) . '/rules/code-review/general.md');

    expect(substr_count($dry, '- **Different rules that only look alike are not a duplicate.**'))->toBe(1)
        ->and($dry)->toContain('DRY applies to the same rule implemented twice.')
        ->and($dry)->toContain('`@rules/php/core-standards.md` CR Severity Rules (*Unify code only when it changes for the same reason*)')
        ->and($dry)->toContain('this rule raises nothing on that code');
});
