<?php

declare(strict_types = 1);

test('a subclass that copies a parent method to change one step is a Moderate finding with Template Method or composition as the fix', function (): void {
    $rule = (string) file_get_contents(dirname(__DIR__, 2) . '/rules/php/core-standards.md');

    expect($rule)->toContain('- **A subclass does not copy a parent method to change one step of it.**')
        ->and($rule)->toContain('  - **Template Method** — when the project owns the parent class. It keeps the inheritance that already exists')
        ->and($rule)->toContain('  - **Composition** — when the step varies in more than one way')
        ->and($rule)->toContain('  - **Not this case:** an override that calls `parent::method()` and adds work before or after it')
        ->and($rule)->toContain('  - a subclass method the diff adds or modifies that copies its parent method and changes one step of it')
        ->and($rule)->toContain('This finding is about the copied body, never about the size of the method');
});

test('the review catalog runs the copied-override check once, and DRY does not raise it again', function (): void {
    $packageDir = dirname(__DIR__, 2);

    expect((string) file_get_contents($packageDir . '/rules/code-review/core-analysis.md'))
        ->toContain('- **Override that copies its parent method to change one step:** flag every subclass method the diff adds or modifies')
        ->toContain('Severity: **Moderate** (declared in `@rules/php/core-standards.md` CR Severity Rules).
**Gating — one finding per override, never two:** this bullet owns the override.');

    expect((string) file_get_contents($packageDir . '/rules/code-review/general.md'))
        ->toContain('- **A subclass override that copies its parent method and changes one step of it is not this finding.**');

    expect((string) file_get_contents($packageDir . '/rules/php/core-standards.md'))
        ->toContain('*File and method size is never a code-review finding* does not suppress it');
});
