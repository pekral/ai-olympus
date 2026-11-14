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
        ->and($reference)->toContain('Give each input one row: the page, the input, its kind')
        ->and($reference)->toContain('the payload classes sent, the traced handler and its branches, and the result.')
        ->and($reference)->toContain('This paragraph is the one statement of the row fields.');
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
        ->and($reference)->toContain('- negative values, zero, and numbers and dates exactly one step out of range, except a number that sets')
        ->and($reference)->toContain('- unexpected Unicode: control characters, a right-to-left override, and emoji;')
        ->and($reference)->toContain('- a value outside the offered options of a select or a radio group, which names no existing record:');
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
        ->and($reference)->toContain('Every boundary of step 5 of the skill applies to every probe in this file:')
        ->and($reference)->toContain('Keep exploitable details out of a public tracker:')
        ->and($reference)->toContain('`@rules/security/frontend.md` and `@rules/security/backend.md` define what a protected application does.');
});

test('the input probing reference sends a condition-changing payload only to a request proven read-only', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $reference = (string) file_get_contents($packageDir . '/skills/interactive-testing/references/input-probing.md');

    expect($reference)->toContain('A SQL or NoSQL payload that executes a condition goes only to a request that is proven read-only,')
        ->and($reference)->toContain('shows that no data write of any form runs on any branch that the input value or the query result can reach')
        ->and($reference)->toContain('including a branch that runs only when the read returns rows or returns none.')
        ->and($reference)->toContain('The report row names the traced handler and its branches.')
        ->and($reference)->toContain('The local query log of a normal value is supporting evidence and never the proof on its own.')
        ->and($reference)->toContain('A search, a filter, or a sort is not read-only by its role in the page.')
        ->and($reference)->toContain('it never adds a live condition-changing payload to a request that is not proven read-only:');
});

test('a read-only payload stays bounded to the value of the run own record', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $reference = (string) file_get_contents($packageDir . '/skills/interactive-testing/references/input-probing.md');

    expect($reference)->toContain('and it stays bounded to the value of the run\'s own record.')
        ->and($reference)->toContain('No such payload may return or expose a row this run did not create,')
        ->and($reference)->toContain('none may feed a filter or a selection that a later write uses.')
        ->and($reference)->toContain('The bounded SQL pair is `<own value>\' AND \'1\'=\'1` against `<own value>\' AND \'1\'=\'2`.')
        ->and($reference)->toContain('The bounded NoSQL pair is `[$in][]=<own value>` against a value that matches nothing.')
        ->and($reference)->toContain('A tautology, an OR clause, a comment sequence, a sleep call, and a not-equal operator are never sent.')
        ->and($reference)->toContain('Request proven read-only over every branch: the bounded pair of the boundaries; compare the two responses.')
        ->and($reference)->toContain('Request proven read-only over every branch only: the bounded pair of the boundaries; compare the two responses.')
        ->and($reference)->not->toContain('`\' OR \'1\'=\'1`')
        ->and($reference)->not->toContain('SLEEP(0)')
        ->and($reference)->not->toContain('`{"$ne": null}`')
        ->and($reference)->not->toContain('`[$ne]=x`')
        ->and($reference)->not->toContain('`\' AND \'1\'=\'1`');
});

test('a record-selecting input of a request not proven read-only gets nothing', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $reference = (string) file_get_contents($packageDir . '/skills/interactive-testing/references/input-probing.md');

    expect($reference)->toContain('an input that selects or identifies the record that the request writes gets no marker and no invalid value.')
        ->and($reference)->toContain('a hidden id or token, a route or query parameter, a select or radio value that names a record,')
        ->and($reference)->toContain('any key that feeds a WHERE clause or a filter of the write.')
        ->and($reference)->toContain('Exception: the traced source shows that the handler rejects the value before any write')
        ->and($reference)->toContain('(validation, a parse failure that aborts the request, `findOrFail`, or route model binding).')
        ->and($reference)->toContain('Without that proof, the input is `not checked`, and the report row names this reason.')
        ->and($reference)->toContain('markers and invalid values go only to content fields of the run\'s own record.')
        ->and($reference)->toContain(
            'That covers an empty or whitespace-only value, a lone brace, a wrong type, and a quote. Probe one input per request.',
        )
        ->and($reference)->toContain('a select or radio value to the identifier of a record this run did not create.')
        ->and($reference)->toContain('The record-selecting, number, and size limits of the boundaries apply to every class:')
        ->and($reference)->toContain('use the marker, or an identifier that does not exist.');
});

test('every value and number is bounded in size and magnitude', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $reference = (string) file_get_contents($packageDir . '/skills/interactive-testing/references/input-probing.md');

    expect($reference)->toContain('No value exceeds 10,000 characters.')
        ->and($reference)->toContain('A limit above 10,000 characters gets no over-length probe, and the report row records why.')
        ->and($reference)->toContain('by one character, within the 10,000-character cap of the boundaries;')
        ->and($reference)->not->toContain('at most 10,000 characters when no limit exists');
});

test('the limit of a number comes from a validation rule or the client control and work-setting numbers stay inside it', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $reference = (string) file_get_contents($packageDir . '/skills/interactive-testing/references/input-probing.md');

    expect($reference)->toContain(
        'The limit of a number is the one that a validation rule or the client control sets, never a column type or a language range.',
    )
        ->and($reference)->toContain('An out-of-range number or date is exactly one step past that validated limit.')
        ->and($reference)->toContain(
            'A number that sets an amount of work, memory, or time gets no value above that limit plus one, no negative value, and no zero.',
        )
        ->and($reference)->toContain('a dimension, a duration, a delay, a timeout, the span of a date range, and similar.')
        ->and($reference)->toContain('The exception for such a number is a traced source that shows a rejection before any query or loop.')
        ->and($reference)->toContain('it gets no out-of-range probe when no such limit exists.')
        ->and($reference)->not->toContain('exactly one step past the limit.')
        ->and($reference)->not->toContain('gets no value above its limit plus one');
});

test('the agent classifies every input of a write from the traced source before the first probe and fails closed', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $reference = (string) file_get_contents($packageDir . '/skills/interactive-testing/references/input-probing.md');

    expect($reference)->toContain('the agent classifies every input from the traced handler source before it sends any marker or invalid value.')
        ->and($reference)->toContain(
            'An input is a content field only when the source shows that the write uses it solely as a stored value of the run\'s own record:',
        )
        ->and($reference)->toContain(
            'not in a WHERE clause, a filter, a key, or a join, not twice in a string-built query, and not normalized into a selector.',
        )
        ->and($reference)->toContain('An input whose use the source does not show, or that the write uses in a selector, is record-selecting,')
        ->and($reference)->toContain('A write that also changes a record this run did not create, such as a related or counterparty record,')
        ->and($reference)->toContain('gets no marker and no invalid value in any input.')
        ->and($reference)->toContain('When the agent is unsure, the input is `not checked`.');
});

test('the input probing reference restates no boundary of the skill and sets the shared target result', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $reference = (string) file_get_contents($packageDir . '/skills/interactive-testing/references/input-probing.md');

    expect($reference)->not->toContain('every probe is `Blocked`')
        ->and($reference)->not->toContain('The interactive browser drives every probe.')
        ->and($reference)->not->toContain('API endpoints that no page calls')
        ->and($reference)->toContain('On a shared or remote target every input is `not checked`, and the security check is `Blocked`');
});

test('a vulnerable input is a pointer in the input probing row and its proof stays in the security row', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $skill = (string) file_get_contents($packageDir . '/skills/interactive-testing/SKILL.md');
    $reference = (string) file_get_contents($packageDir . '/skills/interactive-testing/references/input-probing.md');

    expect($skill)->toContain('with the fields that `@skills/interactive-testing/references/input-probing.md` section 5 states')
        ->and($skill)->not->toContain('the page, the input, its kind, the payload classes sent, and its result')
        ->and($skill)->toContain('Render a `vulnerable` input as `vulnerable — see Security` and keep its proof in the *Security* row only.')
        ->and($skill)->toContain('A public tracker never receives that proof.')
        ->and($reference)->toContain('names a `vulnerable` input as `vulnerable — see Security`, and the proof stays in the *Security* row.');
});

test('raphael returns the input probing rows to the caller', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $raphael = (string) file_get_contents($packageDir . '/agents/raphael.md');
    $codex = (string) file_get_contents($packageDir . '/codex/agents/raphael.toml');

    expect($raphael)->toContain('- **Input probing:** when the walkthrough reached a page with an input, one row for each input of the inventory')
        ->and($raphael)->toContain('with the fields that `@skills/interactive-testing/references/input-probing.md` section 5 states')
        ->and($raphael)->not->toContain('the page, the input, its kind, the payload classes sent, and its result')
        ->and($raphael)->toContain('The proof of a `vulnerable` input goes only under the security observations for `leonardo`.')
        ->and($codex)->toContain('`.codex/agent-instructions/raphael.md`');
});
