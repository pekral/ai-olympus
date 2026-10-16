<?php

declare(strict_types = 1);

test('the review compares every Action the diff touches with every Action for duplicates', function (): void {
    $packageDir = dirname(__DIR__, 2);

    expect((string) file_get_contents($packageDir . '/rules/code-review/core-analysis.md'))
        ->toContain('First list every place where the diff applies the Action pattern: every Action the diff adds')
        ->toContain('every existing Action whose `__invoke()` the diff changes')
        ->toContain('every entry point the diff moves onto an Action')
        ->toContain('then of every other Action under `app/Actions/**`')
        ->toContain('1. **The whole use case repeats.**')
        ->toContain('2. **A sequence of steps repeats.**')
        ->toContain('This bullet owns the Action\'s existence and every step sequence another Action already repeats.')
        ->toContain('A fragment another Action already repeats is a duplicate, and the *Reuse of existing logic* bullet owns it.');

    expect((string) file_get_contents($packageDir . '/rules/laravel/architecture.md'))
        ->toContain('- **Search every Action for repeated steps, not only the target domain folder for the use case.**')
        ->toContain('One shared collaborator call is not a repeated sequence.')
        ->toContain('A fragment another Action already repeats is a duplicate, owned by `@rules/code-review/general.md`');

    expect((string) file_get_contents($packageDir . '/skills/code-review/SKILL.md'))
        ->toContain('On a Laravel project this includes the Action walk of `@rules/code-review/core-analysis.md`');
});
