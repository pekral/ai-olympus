<?php

declare(strict_types = 1);

test('an issue with several pull requests gets its remarks and findings split per pull request (issue #175)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $owner = '*An issue with several pull requests — remarks and findings split per pull request*';

    expect((string) file_get_contents($packageDir . '/rules/code-review/general.md'))
        ->toContain('## An issue with several pull requests — remarks and findings split per pull request')
        ->toContain('read and apply `@rules/code-review/several-pull-requests.md` first');
    expect((string) file_get_contents($packageDir . '/rules/code-review/several-pull-requests.md'))
        ->toContain('- **The technical report stays on its own pull request.**')
        ->toContain('- **The tracker comment groups per pull request.**')
        ->toContain('- **A run that reviews one of the pull requests renders only its own group.**');

    $reference = (string) file_get_contents($packageDir . '/skills/pr-summary/references/several-pull-requests.md');
    expect($reference)->toContain('## What stays at the issue level')
        ->and($reference)->toContain('## What goes into the group of a pull request')
        ->and($reference)->toContain('2. Render no group for a pull request this run did not review.')
        ->and($reference)->toContain('3. Never copy a group, or any other line, out of an earlier comment into the new one.')
        ->and($reference)->toContain('- **GitHub:** `## PR #<number> — <title>`')
        ->and($reference)->toContain('- **JIRA:** `h2. [PR #<number>|<url>] — <title>`')
        ->and($reference)->toContain('(`###` on GitHub, `h3.` on JIRA)')
        ->and($reference)->not->toContain('Carry every other pull request\'s group over verbatim.');

    expect((string) file_get_contents($packageDir . '/skills/pr-summary/templates/pr-summary-jira.md'))
        ->toContain('Review findings and TL;DR sit in one h2. group per pull')
        ->toContain('request, below any Acceptance criteria, and their headings drop to h3.');

    expect((string) file_get_contents($packageDir . '/skills/pr-summary/SKILL.md'))
        ->toContain('### An issue with several pull requests')
        ->toContain('per `references/several-pull-requests.md`');

    expect((string) file_get_contents($packageDir . '/skills/code-review-github/SKILL.md'))->toContain($owner);
    expect((string) file_get_contents($packageDir . '/skills/code-review-jira/SKILL.md'))->toContain($owner);
    expect((string) file_get_contents($packageDir . '/skills/code-review-github/references/cr-wrapper-contract.md'))
        ->toContain('An issue that links more than one pull request groups the comment per pull request.');
});

test('a JIRA task delivered as a stack gets one comment for the whole run (issue #683)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $rule = (string) file_get_contents($packageDir . '/rules/code-review/several-pull-requests.md');
    $section = '*A stack of pull requests — one JIRA comment for the whole stack*';

    expect($rule)
        ->toContain('## A stack of pull requests — one JIRA comment for the whole stack')
        ->toContain('- **The code review on GitHub stays as it is.**')
        ->toContain('- **It is written once, after every part finished its review in the run.**')
        ->toContain('A part that the run did not review, or could not review, is named in the comment with the reason')
        ->toContain('- **Each point names its part.**')
        ->toContain('A point that several parts share appears once, above the groups')
        ->toContain('- **Another run adds a comment; it never replaces one.**')
        ->toContain('the stack rule covers JIRA only')
        ->toContain('- **A pull request that is not part of a stack keeps the behaviour');

    $reference = (string) file_get_contents($packageDir . '/skills/pr-summary/references/several-pull-requests.md');
    expect($reference)
        ->toContain('## A stack on JIRA')
        ->toContain('1. **One comment for the run.**')
        ->toContain('3. **A shared point appears once.**')
        ->toContain('4. **A part that was not reviewed is named, not hidden.**')
        ->toContain('While a part of the run is still in review, publish nothing');

    $exceptions = (string) file_get_contents($packageDir . '/skills/_shared/orchestration/reporting-exceptions.md');
    expect($exceptions)
        ->toContain('### A stack of pull requests — one JIRA report')
        ->toContain('the `baseRefName` of one is the `headRefName` of another')
        ->toContain('1. **No part reports on its own.**')
        ->toContain('3. **Dispatch one `april` for the stack**')
        ->toContain('A stack run that published several JIRA comments, or none, is `Blocked`.');

    expect((string) file_get_contents($packageDir . '/agents/krang.md'))
        ->toContain('whose branches chain into a stack (one JIRA report for the stack)');
    expect((string) file_get_contents($packageDir . '/agents/april.md'))
        ->toContain('**A stack of pull requests on JIRA.**')
        ->toContain('you publish one JIRA comment for the whole stack and no other');
    expect((string) file_get_contents($packageDir . '/skills/code-review-jira/SKILL.md'))
        ->toContain('**When those pull requests form a stack, this is the stack\'s one comment**')
        ->toContain($section);
    expect((string) file_get_contents($packageDir . '/skills/pr-summary/SKILL.md'))
        ->toContain('a stack of pull requests is the exception: one comment carries every part the run reviewed');
    expect((string) file_get_contents($packageDir . '/skills/_shared/orchestration/batch-preparation.md'))
        ->toContain('Units whose pull requests form a stack of one JIRA task publish their JIRA report once');
});
