<?php

declare(strict_types = 1);

/**
 * The >4-parameter rule prescribes one fix — introduce a DTO — and that fix is circular on a DTO's
 * own constructor: applying it produces the same class a second time. A review fired the finding on
 * exactly that signature, so the rule needed a second exemption category and a definition of a data
 * carrier tight enough that a renamed `Service` cannot claim it.
 *
 * These tests pin the exemption itself, the four conditions that define a data carrier, the named
 * constructors it reaches, the value-object decision, and the decision to leave the code-review
 * bullet a pure delegation rather than forking a second copy of the exemption into it.
 */
test('the >4-parameter rule exempts a data carrier\'s own constructor', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $standards = (string) file_get_contents($packageDir . '/rules/php/core-standards.md');

    // The old wording was an absolute — "The only exempt cases are …" — so a second category could
    // not be added without replacing it. This pins the replacement and the count it fixes at two.
    expect($standards)->toContain('Exactly two categories are exempt, and no other case is.');
    expect($standards)->toContain('**First — a signature fixed outside the project.**');
    expect($standards)->toContain('**Second — the data carrier\'s own constructor.**');

    // The circularity is the whole reason the exemption exists; without it stated, a later edit
    // reads the exemption as a convenience and removes it.
    expect($standards)->toContain('obeying it produces the same class a second time');

    // The exemption covers the count, not the class. A DTO that grew into an unrelated bag of
    // fields stays a finding under the one-responsibility bullet.
    expect($standards)->toContain('This exemption covers the **parameter count only**.');
});

test('a data carrier is defined by four conditions a renamed Service fails', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $standards = (string) file_get_contents($packageDir . '/rules/php/core-standards.md');

    // `rules/php/core-standards.md` is not Laravel-scoped, so the definition is structural rather
    // than the Laravel convention (`final readonly`, `Data` suffix, `app/Dto/{Domain}`) in
    // `rules/laravel/architecture.md`. Each condition below carries its own discriminating power;
    // dropping any one of the four widens the exemption to a class that only looks like a DTO.
    expect($standards)->toContain('**What counts as a data carrier.** All four conditions hold');
    expect($standards)->toContain('every constructor parameter is a promoted property');
    expect($standards)->toContain('the constructor injects no collaborator');
    expect($standards)->toContain('every public method other than the constructor is a named constructor of the same class');
    expect($standards)->toContain('no I/O, no persistence, no dispatching, and no other side effect');

    // The worked counter-example. Without it the four conditions read as a description of a DTO
    // rather than as a test something can fail.
    expect($standards)->toContain('a renamed `Service` injects collaborators and does work in its public methods');
});

test('the exemption reaches named constructors and value objects, not only __construct()', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $standards = (string) file_get_contents($packageDir . '/rules/php/core-standards.md');

    // Without this half the rule fires again one line lower, on the identical field list, with the
    // identical circular fix — the exemption would have moved the defect, not removed it.
    expect($standards)->toContain('So is every named constructor of that same class');
    expect($standards)->toContain('(`fromModel()`, `fromRequest()`, `fromArray()`, `from()`)');

    // The guard that keeps a factory taking five collaborators out of the exemption.
    expect($standards)->toContain('the class\'s own field list or a subset of it');

    // Value objects are in, with the reason stated: same constructor shape, same circularity.
    // Their invariant check is named explicitly so it is not read as the side effect condition
    // four forbids.
    expect($standards)->toContain('A **value object** qualifies on the same four conditions');
    expect($standards)->toContain('throwing on an invalid value is not a side effect');
});

test('the exemption has exactly one home and the code-review bullet stays a pure delegation', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $standards = (string) file_get_contents($packageDir . '/rules/php/core-standards.md');
    $crRule = codeReviewRuleContents();

    // One canonical statement. A second copy anywhere in the package would drift out of sync with
    // this one, which is the failure the reuse-first gate exists to prevent.
    expect(substr_count($standards, '**Second — the data carrier\'s own constructor.**'))->toBe(1);

    // The code-review bullet already delegates the whole exemption list here by reference, so it
    // inherits this category with no edit. It deliberately restates neither exemption category:
    // naming only the newer one would read as the complete list, and naming both would fork the
    // canonical text into a second copy. The delegation is what keeps the reviewer on one source.
    expect($crRule)->toContain('(parameter counting rules, exemption list, and required fix are defined there)');
    expect(mb_strtolower($crRule))->not->toContain('data carrier');

    // `skills/class-refactoring/SKILL.md` delegates identically and is unedited for the same
    // reason; this pins that its delegation is still the reference the inheritance rests on.
    $refactoringSkill = (string) file_get_contents($packageDir . '/skills/class-refactoring/SKILL.md');
    expect($refactoringSkill)->toContain('exemption list, and required fix are defined there');
});
