<?php

declare(strict_types = 1);

test('an issue with several pull requests gets its remarks and findings split per pull request (issue #175)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $owner = '*An issue with several pull requests — remarks and findings split per pull request*';

    expect((string) file_get_contents($packageDir . '/rules/code-review/general.md'))
        ->toContain('## An issue with several pull requests — remarks and findings split per pull request')
        ->toContain('- **The technical report stays on its own pull request.**')
        ->toContain('- **The tracker comment groups per pull request.**')
        ->toContain('- **A run that reviews one of the pull requests rewrites only its own group.**');

    $reference = (string) file_get_contents($packageDir . '/skills/pr-summary/references/several-pull-requests.md');
    expect($reference)->toContain('## What stays at the issue level')
        ->and($reference)->toContain('## What goes into the group of a pull request')
        ->and($reference)->toContain('2. Carry every other pull request\'s group over verbatim.')
        ->and($reference)->toContain('Never copy a group out of a comment another account wrote.');

    expect((string) file_get_contents($packageDir . '/skills/pr-summary/SKILL.md'))
        ->toContain('### An issue with several pull requests')
        ->toContain('per `references/several-pull-requests.md`');

    expect((string) file_get_contents($packageDir . '/skills/code-review-github/SKILL.md'))->toContain($owner);
    expect((string) file_get_contents($packageDir . '/skills/code-review-jira/SKILL.md'))->toContain($owner);
    expect((string) file_get_contents($packageDir . '/skills/code-review-github/references/cr-wrapper-contract.md'))
        ->toContain('An issue that links more than one pull request keeps one group per pull request in that one comment.');
});
