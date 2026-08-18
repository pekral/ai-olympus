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

test('the issue-form chooser routes questions to Discussions before the tracker (issue #109)', function (): void {
    $config = (string) file_get_contents(dirname(__DIR__, 2) . '/.github/ISSUE_TEMPLATE/config.yml');

    // The chooser is where a question is intercepted — after the reporter has already decided to
    // open an issue. A link that only exists in the README is read by someone who was not about to
    // file one, which is the wrong half of the audience.
    expect($config)->toContain('name: Ask a question');
    expect($config)->toContain('https://github.com/agentic-vibes/laravel-agent-skills/discussions/categories/q-a');

    // The security link predates this one and must survive beside it: a vulnerability report has to
    // stay off the public tracker.
    expect($config)->toContain('https://github.com/agentic-vibes/laravel-agent-skills/security/advisories/new');
});

test('the readme points at Discussions for questions (issue #109)', function (): void {
    $readme = (string) file_get_contents(dirname(__DIR__, 2) . '/README.md');

    expect($readme)->toContain('## Questions');
    expect($readme)->toContain('https://github.com/agentic-vibes/laravel-agent-skills/discussions');
    expect($readme)->toContain('Keep the issue tracker for bugs and feature requests');
});
