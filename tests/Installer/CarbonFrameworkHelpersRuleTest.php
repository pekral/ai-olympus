<?php

declare(strict_types = 1);

test(
    'date and time work in a Laravel project uses Carbon or a Laravel date helper, and the section lists the native functions it replaces',
    function (): void {
        $section = installerDocsSection(
            (string) file_get_contents(dirname(__DIR__, 2) . '/rules/laravel/laravel.md'),
            '## Carbon and Framework Helpers',
        );

        expect($section)->toContain('**Date and time work uses Carbon or a Laravel date helper.**')
            ->and($section)->toContain('`Carbon` / `CarbonImmutable`, `now()`, `today()`, the `Date` facade, or an Eloquent date cast')
            ->and($section)->toContain('**The native functions and classes this replaces:**');

        $natives = [
            'date()',
            'gmdate()',
            'strtotime()',
            'mktime()',
            'time()',
            'strftime()',
            'date_create()',
            'new DateTime(...)',
            'new DateTimeImmutable(...)',
            'new DateInterval(...)',
        ];

        foreach ($natives as $native) {
            expect($section)->toContain('`' . $native . '`');
        }
    },
);

test('code reuses a helper the framework already ships instead of a hand-written equivalent', function (): void {
    $section = installerDocsSection(
        (string) file_get_contents(dirname(__DIR__, 2) . '/rules/laravel/laravel.md'),
        '## Carbon and Framework Helpers',
    );

    expect($section)->toContain('**Reuse a helper the framework already ships.**')
        ->and($section)->toContain('`Str` / `Stringable`, `Arr`, `Collection`, `Number`, `data_get()` / `data_set()`')
        ->and($section)->toContain('`Str::slug($title)`')
        ->and($section)->toContain('**Use only a helper or a class the installed Laravel version ships.**');
});

test('code reuses a class the framework already ships and follows the reuse order', function (): void {
    $laravel = (string) file_get_contents(dirname(__DIR__, 2) . '/rules/laravel/laravel.md');
    $section = installerDocsSection($laravel, '## Carbon and Framework Helpers');

    expect($section)->toContain('**Reuse a class or a service the framework already ships.**')
        ->and($section)->toContain('`Cache::remember()` instead of a get-then-put pair')
        ->and($section)->toContain('**Extend the native class instead of writing a parallel one.**')
        ->and($section)->toContain('`cursorPaginate()` instead of a hand-written offset, per `@rules/sql/optimalize.md`')
        ->and($section)->not->toContain('`Fluent`')
        ->and($section)->toContain('A line that another rule already owns is raised once, by that owner')
        ->and($section)->toContain('**Choose in this order, and stop at the first option that fits.**')
        ->and($section)->toContain('1. The project already implements the behaviour: reuse it.')
        ->and($section)->toContain('2. The framework ships a helper or a class for it')
        ->and($section)->toContain('3. Neither exists: write new code in the layer that owns it')
        ->and($section)->toContain('hand-written equivalent of an existing framework helper or framework class')
        ->and($laravel)->toContain('in the order *Carbon and Framework Helpers* below owns');
});

test('the section owns neither the timezone rule, the emptiness rule, nor the project DRY rule, and points to each owner', function (): void {
    $section = installerDocsSection(
        (string) file_get_contents(dirname(__DIR__, 2) . '/rules/laravel/laravel.md'),
        '## Carbon and Framework Helpers',
    );

    expect($section)->toContain('**The *Time* rule applies unchanged to every Carbon call.**')
        ->and($section)->toContain('This section never restates them.')
        ->and($section)->toContain('`filled()` / `blank()` are owned by *String Emptiness Checks* above.')
        ->and($section)->toContain('`@rules/code-review/general.md` *Reuse Existing Logic* owns logic the *project* already implements')
        ->and($section)->not->toContain('`config(\'app.timezone\')`')
        ->and($section)->not->toContain('`blank()` trims first');
});

test('the review severity is Moderate, the not-findings are named, and the gating is one finding per line', function (): void {
    $section = installerDocsSection(
        (string) file_get_contents(dirname(__DIR__, 2) . '/rules/laravel/laravel.md'),
        '## Carbon and Framework Helpers',
    );

    expect($section)->toContain('Severity in code review: **Moderate** for a native date function or class from the list above')
        ->and($section)->toContain('The scope is the changed lines.')
        ->and($section)->toContain('**Not findings:**')
        ->and($section)->toContain('A vendor or framework contract that requires or returns a native type')
        ->and($section)->toContain('`DateTimeInterface` type hint, which Carbon satisfies')
        ->and($section)->toContain('Elapsed-time measurement with `hrtime()` or `microtime(true)`')
        ->and($section)->toContain('**Gating — one finding per line.**')
        ->and($section)->toContain('A Carbon call with no timezone belongs to *Time*, and this section never raises it.');
});

test('the code review walk points to the section and carries the same severity without restating the native function list', function (): void {
    $review = (string) file_get_contents(dirname(__DIR__, 2) . '/rules/code-review/core-analysis.md');
    $start = strpos($review, '- **Carbon and framework helpers (Laravel, `@rules/laravel/laravel.md` *Carbon and Framework Helpers*)**');

    expect($start)->toBeInt();

    $end = (int) strpos($review, "\n- **`''` is not the only empty case", (int) $start);
    $entry = substr($review, (int) $start, $end - (int) $start);

    expect($entry)->toContain('verify it goes through Carbon or the helper or class the framework already ships')
        ->and($entry)->toContain('a hand-written equivalent of a framework class or service from the list in that section')
        ->and($entry)->not->toContain('`RateLimiter`')
        ->and($entry)->toContain(
            'The **Not findings** and the gating against *Time*, *String Emptiness Checks*, *Reuse Existing Logic*, and the other owners are the ones that section states.',
        )
        ->and($entry)->toContain('Severity: **Moderate**.')
        ->and($entry)->not->toContain('`strtotime()`');
});

test('the Carbon section exists once beside the single Time section and the general review rule does not carry it', function (): void {
    $files = packageTextFiles();
    $laravel = $files['rules/laravel/laravel.md'];

    expect(substr_count($laravel, '## Time' . "\n"))->toBe(1)
        ->and(substr_count($laravel, '## Carbon and Framework Helpers' . "\n"))->toBe(1)
        ->and($files['rules/code-review/general.md'])->not->toContain('Carbon and Framework Helpers');
});

test('the Time walk hands a native date call on a Laravel project to the Carbon walk', function (): void {
    $review = (string) file_get_contents(dirname(__DIR__, 2) . '/rules/code-review/core-analysis.md');

    expect($review)->toContain('on a Laravel project a native date function or class (`new DateTimeImmutable(\'now\')`')
        ->and($review)->toContain('belongs to **Carbon and framework helpers** below, and this bullet does not raise it')
        ->and($review)->toContain('the zone of the Carbon replacement is this bullet\'s.');
});
