<?php

declare(strict_types = 1);

/**
 * The Laravel rule section that requires Carbon and the existing framework helpers (issue #460).
 */
function carbonFrameworkHelpersSection(): string
{
    $laravel = (string) file_get_contents(dirname(__DIR__, 2) . '/rules/laravel/laravel.md');
    $start = strpos($laravel, "## Carbon and Framework Helpers\n");
    $end = strpos($laravel, "\n## Collections\n");

    expect($start)->toBeInt()
        ->and($end)->toBeInt();

    return substr($laravel, (int) $start, (int) $end - (int) $start);
}

test('date and time work in a Laravel project uses Carbon or a Laravel date helper, and the section lists the native functions it replaces', function (): void {
    $section = carbonFrameworkHelpersSection();

    expect($section)->toContain('**Date and time work uses Carbon or a Laravel date helper.**')
        ->and($section)->toContain('`Carbon` / `CarbonImmutable`, `now()`, `today()`, the `Date` facade, or an Eloquent date cast')
        ->and($section)->toContain('**The native functions and classes this replaces:**');

    foreach (['date()', 'gmdate()', 'strtotime()', 'mktime()', 'time()', 'strftime()', 'date_create()', 'new DateTime(...)', 'new DateTimeImmutable(...)', 'new DateInterval(...)'] as $native) {
        expect($section)->toContain('`' . $native . '`');
    }
});

test('code reuses a helper the framework already ships instead of a hand-written equivalent', function (): void {
    $section = carbonFrameworkHelpersSection();

    expect($section)->toContain('**Reuse a helper the framework already ships.**')
        ->and($section)->toContain('`Str` / `Stringable`, `Arr`, `Collection`, `Number`, `data_get()` / `data_set()`')
        ->and($section)->toContain('`Str::slug($title)`')
        ->and($section)->toContain('**Use only a helper the installed Laravel version ships.**');
});

test('the section owns neither the timezone rule, the emptiness rule, nor the project DRY rule, and points to each owner', function (): void {
    $section = carbonFrameworkHelpersSection();

    expect($section)->toContain('**The *Time* rule applies unchanged to every Carbon call.**')
        ->and($section)->toContain('This section never restates them.')
        ->and($section)->toContain('`filled()` / `blank()` are owned by *String Emptiness Checks* below.')
        ->and($section)->toContain('`@rules/code-review/general.md` *Reuse Existing Logic* owns logic the *project* already implements')
        ->and($section)->not->toContain('`config(\'app.timezone\')`')
        ->and($section)->not->toContain('`blank()` trims first');
});

test('the review severity is Moderate, the not-findings are named, and the gating is one finding per line', function (): void {
    $section = carbonFrameworkHelpersSection();

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

    expect($entry)->toContain('verify it goes through Carbon or the helper the framework already ships')
        ->and($entry)->toContain('The **Not findings** and the gating against *Time*, *String Emptiness Checks*, and *Reuse Existing Logic* are the ones that section states.')
        ->and($entry)->toContain('Severity: **Moderate**.')
        ->and($entry)->not->toContain('`strtotime()`');
});

test('the Laravel rule keeps its single timezone owner and the reuse rule keeps its own severity', function (): void {
    $files = packageTextFiles();
    $laravel = $files['rules/laravel/laravel.md'];

    expect(substr_count($laravel, '## Time' . "\n"))->toBe(1)
        ->and(substr_count($laravel, '## Carbon and Framework Helpers' . "\n"))->toBe(1)
        ->and($files['rules/code-review/general.md'])->not->toContain('Carbon and Framework Helpers');
});
