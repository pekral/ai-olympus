<?php

declare(strict_types = 1);

test('an issue with several pull requests gets its remarks and findings split per pull request (issue #175)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $owner = '*An issue with several pull requests — remarks and findings split per pull request*';

    expect((string) file_get_contents($packageDir . '/rules/code-review/general.md'))
        ->toContain('## An issue with several pull requests — remarks and findings split per pull request')
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
        ->toContain('changed, and Impact after deployment sit in one h2. group per pull')
        ->toContain('request, below Acceptance criteria, and their headings drop to h3.');

    expect((string) file_get_contents($packageDir . '/skills/pr-summary/SKILL.md'))
        ->toContain('### An issue with several pull requests')
        ->toContain('per `references/several-pull-requests.md`');

    expect((string) file_get_contents($packageDir . '/skills/code-review-github/SKILL.md'))->toContain($owner);
    expect((string) file_get_contents($packageDir . '/skills/code-review-jira/SKILL.md'))->toContain($owner);
    expect((string) file_get_contents($packageDir . '/skills/code-review-github/references/cr-wrapper-contract.md'))
        ->toContain('An issue that links more than one pull request groups the comment per pull request.');
});
