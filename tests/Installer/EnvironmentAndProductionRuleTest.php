<?php

declare(strict_types = 1);

test('every agent is barred from reading environment values, with only the env example template readable', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $rule = (string) file_get_contents($packageDir . '/rules/security/general.md');
    $orchestration = (string) file_get_contents($packageDir . '/rules/compound-engineering/orchestration.md');
    $mysql = (string) file_get_contents($packageDir . '/skills/mysql-problem-solver/SKILL.md');

    expect($rule)->toContain('## Environment values are never read')
        ->and($rule)->toContain('**No agent reads a value from the environment.**')
        ->and($rule)->toContain('no `printenv`, no bare `env`, no `echo $VAR`')
        ->and($rule)->toContain('**The one exception is the committed `.env.example` template.**')
        ->and($rule)->toContain('Never fall back to the environment.')
        ->and($rule)->toContain('**No indirect copy of a value.**')
        ->and($rule)->toContain('**Code that uses a variable is not reading it.**')
        ->and($rule)->not->toContain("paths:\n");
    expect($orchestration)->toContain('or an environment-variable value (`@rules/security/general.md` *Environment values are never read*)');
    expect($mysql)->not->toContain("details from:\n- `.env`\n")
        ->and($mysql)->toContain('Never read `.env` or an environment-variable value');
});

test('every agent is barred from touching production', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $rule = (string) file_get_contents($packageDir . '/rules/security/general.md');
    $general = (string) file_get_contents($packageDir . '/rules/general/general.md');

    expect($rule)->toContain('## Production is never touched')
        ->and($rule)->toContain('**No agent touches production.** Every test, walkthrough, diagnosis, query, request, migration, and command')
        ->and($rule)->toContain('counts as production: stop `Blocked` and name what is missing.')
        ->and($rule)->toContain('This section never widens it to a shared test environment.');
    expect($general)->toContain('4. let an agent read an environment value or touch production');
    expect((string) file_get_contents($packageDir . '/rules/code-review/general.md'))
        ->toContain('4. lets an agent read an environment value or touch production');
    expect((string) file_get_contents($packageDir . '/skills/laravel-telescope/SKILL.md'))
        ->not->toContain('production read-only')
        ->toContain('A Telescope URL on production or on an unknown host stops the database steps');
});
