<?php

declare(strict_types = 1);

test('pr-summary owns the TL;DR of the solution that every review finding bullet carries (issue #371)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $skill = (string) file_get_contents($packageDir . '/skills/pr-summary/SKILL.md');

    expect($skill)
        ->toContain(
            '- **A finding bullet is a TL;DR of the solution, backed by evidence.** '
                . 'This skill is the one owner of the rule; the wrappers and the templates only point here.',
        )
        ->toContain(
            '1. **Three statements per bullet:** what is wrong, what the fix is, and what the fix changes for the user or the business, '
                . 'each in plain language.',
        )
        ->toContain('Retell the fix from the finding\'s suggested fix, or from its expected behaviour when no fix snippet exists.')
        ->toContain('2. **Every bullet names its evidence in plain words:**')
        ->toContain('Example: *an order created without a customer fails.*')
        ->toContain('describe the behaviour, never a test name, a file, or a count.')
        ->toContain('Write one bullet per listed point, as a TL;DR of its solution for a human, backed by evidence');
    expect($skill)->not->toContain('the rule file and the wrappers only point here');
    expect($skill)->not->toContain('Write one bullet per finding: what can go wrong for the user or the business, and what the fix would change.');
});

test('a finding with no evidence is never listed in the review findings (issue #455)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $skill = (string) file_get_contents($packageDir . '/skills/pr-summary/SKILL.md');

    expect($skill)
        ->toContain('3. **The caller passes the evidence, and this skill never derives it.**')
        ->toContain('This skill reads no diff and runs no check to fill a gap.')
        ->toContain('4. **A point without evidence is never listed.**')
        ->toContain(
            'every point the review reported as not verified, is left out of this section. '
                . 'The caller still passes them, and this skill renders no heading for them.',
        );
    expect($skill)->not->toContain('renders under the heading *Not verified*');
});

test('the TL;DR keeps every limit of the non-technical comment and the clean review stays one sentence (issue #371)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $skill = (string) file_get_contents($packageDir . '/skills/pr-summary/SKILL.md');

    expect($skill)
        ->toContain('5. **The caps stay.** The bullets add no file path, code name, severity label, count, or test name.')
        ->toContain('a listed point is never dropped, because the reader decides on it.')
        ->toContain(
            '6. **A clean review keeps its one sentence.** '
                . 'When the review has no point to resolve, render the sentence that says so, with no TL;DR.',
        )
        ->toContain('When the review has no point to resolve, render one sentence that says so, and no bullets.');
});

test('every target template carries the TL;DR and lists only the points to resolve (issue #455)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $github = (string) file_get_contents($packageDir . '/skills/pr-summary/templates/pr-summary-github.md');
    $jira = (string) file_get_contents($packageDir . '/skills/pr-summary/templates/pr-summary-jira.md');
    $bugsnag = (string) file_get_contents($packageDir . '/skills/pr-summary/templates/pr-summary-bugsnag.md');

    expect($github)
        ->toContain('the rule that each finding is a TL;DR of its solution')
        ->toContain('lists only the points that must be resolved')
        ->toContain('and never an assumption, a hypothesis, or a point with no evidence.');
    expect($github)->not->toContain('### Not verified');
    expect($bugsnag)
        ->toContain('the rule that each finding is a TL;DR of its solution')
        ->toContain('lists only the points that must be resolved')
        ->toContain('defect or an open question. It lists every such point, and never an');
    expect($bugsnag)->not->toContain('"Not verified"');
    expect($jira)
        ->toContain(
            '* [One point that must be resolved before the merge, per bullet, as a TL;DR of its solution: '
                . 'what is wrong, what the fix is, and what the fix changes for the user or the business.',
        )
        ->toContain('for example "an order created without a customer fails"')
        ->toContain('never an assumption, a hypothesis, or a point that needs no action.')
        ->toContain('Every such point of the technical')
        ->toContain('"h2. Nálezy z kontroly');
    expect($jira)->not->toContain('h3. Not verified');
    expect($jira)->not->toContain('Neověřeno');
});

test('the wrappers point at the pr-summary owner and pass the evidence (issue #371)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $wrapper = (string) file_get_contents($packageDir . '/skills/code-review-github/references/cr-wrapper-contract.md');
    $jiraWrapper = (string) file_get_contents($packageDir . '/skills/code-review-jira/SKILL.md');

    expect($wrapper)
        ->toContain(
            'Pass each finding with its `Evidence` value, its `Expected behavior`, and its suggested fix, '
                . 'and pass every point of the technical report that must be resolved, each open question included, so that none is left out.',
        )
        ->toContain('it derives no evidence itself')
        ->toContain('*A finding bullet is a TL;DR of the solution, backed by evidence*');
    expect($jiraWrapper)
        ->toContain(
            'Each point there is a TL;DR of its solution backed by the evidence the wrapper passes, '
                . 'and a point with no evidence is left out',
        );
});

test('an open decision keeps the caller shape and the TL;DR rule covers code-review findings only (issue #371)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $skill = (string) file_get_contents($packageDir . '/skills/pr-summary/SKILL.md');

    expect($skill)
        ->toContain(
            '- An open decision that `verify-merge-readiness` or `april` passes keeps the question-and-recommendation shape its caller defines',
        )
        ->toContain('7. **The rule covers the findings of a code review only.**')
        ->toContain('keeps the question-and-recommendation shape its caller defines. It carries no `Evidence`, and it is listed as an open question.');
});

test('a JIRA body that overflows the cap shortens each finding to two sentences and keeps every listed point (issue #455)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $skill = (string) file_get_contents($packageDir . '/skills/pr-summary/SKILL.md');
    $jira = (string) file_get_contents($packageDir . '/skills/pr-summary/templates/pr-summary-jira.md');

    expect($skill)
        ->toContain('8. **When a JIRA body still overflows 3 000 characters, shorten each finding bullet to two sentences:**')
        ->toContain('then shorten the findings per item 8 above. Never drop a `Review findings` bullet.')
        ->toContain('then shorten the findings per item 8 of *A finding bullet is a TL;DR of the solution, backed by evidence*;');
    expect($skill)->not->toContain('is carrying content the banned list already forbids');
    expect($skill)->not->toContain('*Not verified* point');
    expect($jira)->toContain('then write each Review findings bullet in two sentences');
});

test('review findings list only what must be resolved and every such point (issue #455)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $skill = (string) file_get_contents($packageDir . '/skills/pr-summary/SKILL.md');

    expect($skill)
        ->toContain('- **List only what must be resolved before the merge.** This skill is the one owner of the rule;')
        ->toContain(
            '1. **Two kinds of point are listed:** a defect (a finding of the technical report with evidence that blocks the merge) '
                . 'and an open question that needs an answer.',
        )
        ->toContain(
            '2. **Every such point is listed.** Read the whole technical report, '
                . 'and leave out none of the points that must be resolved.',
        )
        ->toContain(
            '3. **A point that needs no action is not listed:** a resolved point, a deferred point, and an informational point.',
        )
        ->toContain('This holds on every target.')
        ->toContain(
            '4. **An assumption, a hypothesis, or an unverified suspicion is never listed** '
                . '(`@rules/general/general.md` *Verified statements only*).',
        )
        ->toContain(
            'Such a point stays in the technical report, under `## Needs verification` and on the `Not verified:` line.',
        )
        ->toContain('3. **`Review findings` is the second section** — only the points that must be resolved before the merge');
});
