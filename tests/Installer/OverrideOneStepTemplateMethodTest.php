<?php

declare(strict_types = 1);

test('a subclass that copies a parent method to change one step is a Moderate finding with Template Method or composition as the fix', function (): void {
    $rule = (string) file_get_contents(dirname(__DIR__, 2) . '/rules/php/core-standards.md');

    expect($rule)->toContain('- **A subclass does not copy a parent method to change one step of it.**')
        ->and($rule)->toContain('  - **Template Method** — when the project owns the parent class.')
        ->and($rule)->toContain('  - **Composition** — when the step varies in more than one way')
        ->and($rule)->toContain('  - **Not this case:** an override that calls `parent::method()` and adds work before or after it')
        ->and($rule)->toContain('  - a subclass method the diff adds or modifies that copies its parent method and changes one step of it')
        ->and($rule)->toContain('This finding is about the copied body, never about the size of the method');
});
