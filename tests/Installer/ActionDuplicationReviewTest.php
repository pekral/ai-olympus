<?php

declare(strict_types = 1);

test('the review compares every Action the diff touches with every flow for duplicates', function (): void {
    $packageDir = dirname(__DIR__, 2);

    expect((string) file_get_contents($packageDir . '/rules/code-review/core-analysis.md'))
        ->toContain('First list every place where the diff applies the Action pattern: every Action the diff adds')
        ->toContain('every existing Action whose `__invoke()` the diff changes')
        ->toContain('every entry point the diff moves onto an Action')
        ->toContain('then of every other Action under `app/Actions/**`')
        ->toContain('1. **The whole use case repeats.**')
        ->toContain('2. **A sequence of steps repeats.**')
        ->toContain('- **A sequence that one layer owns.**')
        ->toContain('- **A sequence that an existing entry-point Action already runs.**')
        ->toContain('A sequence that crosses layers and that no such Action runs is **not** a finding.')
        ->toContain('- A repeated sequence is one finding, never one per copy.')
        ->toContain('Raise that bullet once, list every copy, and raise nothing here.')
        ->toContain('A block that crosses layers is the orchestration of the use case, not general logic, and is not this finding')
        ->toContain('This bullet owns the Action\'s existence and every step sequence another flow already repeats.')
        ->toContain('A fragment another flow already repeats is a duplicate, and the *Reuse of existing logic* bullet owns it.');

    expect((string) file_get_contents($packageDir . '/rules/laravel/architecture.md'))
        ->toContain('- **Search every flow for repeated steps, not only the target domain folder for the use case.**')
        ->toContain('A sequence that crosses layers and that no such Action runs is each Action\'s own orchestration and stays.')
        ->toContain('One shared collaborator call is not a repeated sequence.')
        ->toContain('A block that crosses layers — a Data Validator, then a Data Builder, then a Model Service — is the orchestration')
        ->toContain('A fragment another flow already repeats is a duplicate, owned by `@rules/code-review/general.md`');

    expect((string) file_get_contents($packageDir . '/skills/code-review/SKILL.md'))
        ->toContain('On a Laravel project this includes the Action walk of `@rules/code-review/core-analysis.md`');
});
