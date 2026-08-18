<?php

declare(strict_types = 1);

function issueFormTemplate(string $name): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/.github/ISSUE_TEMPLATE/' . $name);
}

test('no issue form asks the reporter to confirm the issue is not a duplicate (issue #257)', function (string $name): void {
    expect(issueFormTemplate($name))->not->toContain('not a duplicate');
})->with(['bug_report.yml', 'feature_request.yml']);

test('removing the duplicate checkbox took its whole checkboxes block with it (issue #257)', function (string $name): void {
    // It was the only option in that block. Deleting the option alone would leave a
    // `checkboxes` element with an empty `options:` list, which GitHub rejects as an
    // invalid issue form — the template then stops rendering entirely.
    $template = issueFormTemplate($name);

    expect($template)->not->toContain('type: checkboxes');

    // `options:` is legitimate on a `dropdown` element, so the guard is not its absence —
    // it is that no `options:` key is left standing with nothing listed under it.
    preg_match_all('/^\s*options:\s*\n(\s*)(-\s|\S)/m', $template, $matches, PREG_SET_ORDER);

    expect($matches)->toHaveCount(substr_count($template, 'options:'));

    foreach ($matches as $match) {
        expect($match[2])->toBe('- ');
    }
})->with(['bug_report.yml', 'feature_request.yml']);

test('every issue form still declares a non-empty body (issue #257)', function (string $name): void {
    // The guard above deletes blocks; this one proves the deletion stopped at the block
    // it was aimed at and did not empty the form.
    $template = issueFormTemplate($name);
    $lines = array_values(array_filter(explode("\n", $template), static fn (string $line): bool => trim($line) !== ''));

    expect($template)->toContain('body:')
        ->and(substr_count($template, '- type: '))->toBeGreaterThan(1)
        // A key with nothing under it is what a half-finished deletion leaves behind.
        ->and(end($lines))->not->toMatch('/:\s*$/');
})->with(['bug_report.yml', 'feature_request.yml']);
