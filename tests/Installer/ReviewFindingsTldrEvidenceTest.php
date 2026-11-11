<?php

declare(strict_types = 1);

test('pr-summary owns the TL;DR of the solution that every review finding bullet carries (issue #371)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $skill = (string) file_get_contents($packageDir . '/skills/pr-summary/SKILL.md');

    expect($skill)
        ->toContain('- **A finding bullet is a TL;DR of the solution, backed by evidence.** This skill is the one owner of the rule;')
        ->toContain(
            '1. **Three statements per bullet:** what is wrong, what the fix is, and what the fix changes for the user or the business, '
                . 'each in plain language.',
        )
        ->toContain('Retell the fix from the finding\'s suggested fix, or from its expected behaviour when no fix snippet exists.')
        ->toContain('2. **Every bullet names its evidence in plain words:**')
        ->toContain('Example: *an order created without a customer fails.*')
        ->toContain('describe the behaviour, never a test name, a file, or a count.')
        ->toContain('Write one bullet per finding, as a TL;DR of its solution for a human, backed by evidence');
    expect($skill)->not->toContain('Write one bullet per finding: what can go wrong for the user or the business, and what the fix would change.');
});

test('a finding with no evidence is never a problem or a solution and renders under Not verified (issue #371)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $skill = (string) file_get_contents($packageDir . '/skills/pr-summary/SKILL.md');

    expect($skill)
        ->toContain('3. **The caller passes the evidence, and this skill never derives it.**')
        ->toContain('This skill reads no diff and runs no check to fill a gap.')
        ->toContain('4. **A point without evidence is never a problem and never a solution.**')
        ->toContain(
            'every point the review reported as not verified, renders under the heading *Not verified*, '
                . 'as one bullet each: what is unknown, and the step that settles it.',
        )
        ->toContain('It carries no solution sentence and states no problem as fact.');
});

test('the TL;DR keeps every limit of the non-technical comment and the clean review stays one sentence (issue #371)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $skill = (string) file_get_contents($packageDir . '/skills/pr-summary/SKILL.md');

    expect($skill)
        ->toContain('5. **The caps stay.** The bullets add no file path, code name, severity label, count, or test name.')
        ->toContain('a *Not verified* bullet is never dropped, because the reader decides on it.')
        ->toContain(
            '6. **A clean review keeps its one sentence.** '
                . 'When the review found nothing, render the sentence that says so, with no TL;DR and no *Not verified* heading.',
        )
        ->toContain('When the review found nothing, render one sentence that says so, and no bullets.')
        ->toContain(
            'written as a TL;DR of the solution backed by evidence, and a point with no evidence under *Not verified*, both per *Review-only run* below.',
        );
});

test('every target template carries the TL;DR and the Not verified slot (issue #371)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $github = (string) file_get_contents($packageDir . '/skills/pr-summary/templates/pr-summary-github.md');
    $jira = (string) file_get_contents($packageDir . '/skills/pr-summary/templates/pr-summary-jira.md');
    $bugsnag = (string) file_get_contents($packageDir . '/skills/pr-summary/templates/pr-summary-bugsnag.md');

    expect($github)
        ->toContain('the rule that each finding is a TL;DR of its solution')
        ->toContain('A point with no evidence renders under `### Not verified`');
    expect($bugsnag)
        ->toContain('the rule that each finding is a TL;DR of its solution')
        ->toContain('A point with no evidence renders under the line "Not verified"');
    expect($jira)
        ->toContain(
            '* [One finding per bullet, as a TL;DR of its solution: what is wrong, what the fix is, and what the fix changes for the user or the business.',
        )
        ->toContain('for example "an order created without a customer fails"')
        ->toContain('h3. Not verified')
        ->toContain('* [Only when a point has no evidence: what is unknown, and the step that settles it. No solution sentence.')
        ->toContain('"h3. Neověřeno"')
        ->toContain('and is never dropped for length.');

    expect(mb_strpos($jira, 'h3. Not verified'))->toBeLessThan((int) mb_strpos($jira, 'h2. What changed'));
});

test('the wrappers point at the pr-summary owner and pass the evidence (issue #371)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $wrapper = (string) file_get_contents($packageDir . '/skills/code-review-github/references/cr-wrapper-contract.md');
    $jiraWrapper = (string) file_get_contents($packageDir . '/skills/code-review-jira/SKILL.md');

    expect($wrapper)
        ->toContain(
            'Pass each finding with its `Evidence` value, its `Expected behavior`, and its suggested fix, '
                . 'and pass every point the review reported as not verified with the step that settles it.',
        )
        ->toContain('it derives no evidence itself')
        ->toContain('*A finding bullet is a TL;DR of the solution, backed by evidence*');
    expect($jiraWrapper)
        ->toContain(
            'Each finding there is a TL;DR of its solution backed by the evidence the wrapper passes, '
                . 'and a point with no evidence goes under *Not verified*',
        );
});
