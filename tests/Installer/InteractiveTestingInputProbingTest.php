<?php

declare(strict_types = 1);

test('the interactive testing skill sends the agent to the input probing reference and reports a row for each input', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $skill = (string) file_get_contents($packageDir . '/skills/interactive-testing/SKILL.md');

    expect($packageDir . '/skills/interactive-testing/references/input-probing.md')->toBeFile()
        ->and($skill)->toContain('3. Probe every input the exercised pages expose, as `@skills/interactive-testing/references/input-probing.md` states')
        ->and($skill)->toContain('Build an inventory of every input and give each input a row in the report.')
        ->and($skill)->toContain('Each input ends as `protected`, `vulnerable`, or `not checked`, with evidence or a reason.')
        ->and($skill)->toContain('A `vulnerable` input is a candidate, and the next items apply to it.')
        ->and($skill)->toContain('client-side validation alone counts as unprotected')
        ->and($skill)->toContain('- **Input probing:** one row for each input of the inventory')
        ->and($skill)->toContain('an input that is `vulnerable`, a candidate that still needs validation')
        ->and($skill)->toContain(
            '- Every input of the exercised pages has a row in the inventory and a result of `protected`, `vulnerable`, or `not checked`',
        );
});

test('the input probing reference builds the inventory of every input kind', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $reference = (string) file_get_contents($packageDir . '/skills/interactive-testing/references/input-probing.md');

    expect($reference)->toContain('## 1. Build the input inventory')
        ->and($reference)->toContain('| Text field, textarea, search box |')
        ->and($reference)->toContain('| Select, checkbox, radio |')
        ->and($reference)->toContain('| Hidden field |')
        ->and($reference)->toContain('| File upload |')
        ->and($reference)->toContain('| Rich-text editor |')
        ->and($reference)->toContain('| URL query parameter |')
        ->and($reference)->toContain('| Route parameter |')
        ->and($reference)->toContain('Give each input one row: the page, the input, its kind');
});

test('the input probing reference names every hostile payload class and keeps each payload harmless', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $reference = (string) file_get_contents($packageDir . '/skills/interactive-testing/references/input-probing.md');

    expect($reference)->toContain('## 2. Hostile payloads')
        ->and($reference)->toContain('| HTML and script injection, stored and reflected |')
        ->and($reference)->toContain('| SQL injection marker |')
        ->and($reference)->toContain('| NoSQL injection marker |')
        ->and($reference)->toContain('| Template or expression injection |')
        ->and($reference)->toContain('| Path traversal |')
        ->and($reference)->toContain('| Header and CRLF injection |')
        ->and($reference)->toContain('| Formula injection |')
        ->and($reference)->toContain('| File upload with a mismatched type, extension, or content |')
        ->and($reference)->toContain('Every payload is harmless.')
        ->and($reference)->toContain('with a DOM marker in place of `alert`')
        ->and($reference)->toContain('never causes a denial of service, and never targets a third-party host');
});

test('the input probing reference sends every class of invalid data', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $reference = (string) file_get_contents($packageDir . '/skills/interactive-testing/references/input-probing.md');

    expect($reference)->toContain('## 3. Invalid data')
        ->and($reference)->toContain('- empty and whitespace-only values;')
        ->and($reference)->toContain('- a value over the maximum length')
        ->and($reference)->toContain('- a value of the wrong type, such as text in a number field;')
        ->and($reference)->toContain('- negative values, zero, and out-of-range numbers and dates;')
        ->and($reference)->toContain('- unexpected Unicode: control characters, a right-to-left override, and emoji;')
        ->and($reference)->toContain('- a value outside the offered options of a select or a radio group.');
});

test('the input probing reference bypasses the client-side validation and counts it as unprotected', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $reference = (string) file_get_contents($packageDir . '/skills/interactive-testing/references/input-probing.md');

    expect($reference)->toContain('## 4. Bypass the client-side validation')
        ->and($reference)->toContain('Remove `required`, `maxlength`, `pattern`, `type`, and `disabled` in the DOM')
        ->and($reference)->toContain('or send the same request from the page')
        ->and($reference)->toContain('the application relies on client-side validation alone, and the input counts as unprotected');
});

test('the input probing reference ends each input as protected, vulnerable, or not checked and keeps the existing boundaries', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $reference = (string) file_get_contents($packageDir . '/skills/interactive-testing/references/input-probing.md');

    expect($reference)->toContain('Every input of the inventory ends in exactly one result:')
        ->and($reference)->toContain('| `protected` | The server rejected the value, or the output escaped it |')
        ->and($reference)->toContain('| `vulnerable` | A payload executed, a payload persisted unescaped, or invalid data was stored |')
        ->and($reference)->toContain('| `not checked` | The agent did not probe the input |')
        ->and($reference)->toContain('A `vulnerable` input follows the flow of step 5 of the skill: it is a candidate first')
        ->and($reference)->toContain('A read-only tester returns the proof to the caller and never classifies it.')
        ->and($reference)->toContain('Probe only the authorized local development or test instance')
        ->and($reference)->toContain('Keep exploitable details out of a public tracker.')
        ->and($reference)->toContain('`@rules/security/frontend.md` and `@rules/security/backend.md` define what a protected application does.');
});
