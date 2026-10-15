<?php

declare(strict_types = 1);

test('a refactoring-only change keeps every behaviour, bugs included, and files each discrepancy (issue #175)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $rule = (string) file_get_contents($packageDir . '/rules/refactoring/general.md');

    expect($rule)->toContain('## Behaviour stays identical — bugs included')
        ->and($rule)->toContain('**A bug stays a bug.**')
        ->and($rule)->toContain('not in the refactor commit, and not in a separate commit of the same pull request')
        ->and($rule)->toContain('**Every discrepancy becomes a follow-up issue.**')
        ->and($rule)->toContain('a preserved discrepancy always clears the filing bar there')
        ->and($rule)->toContain('**A security weakness keeps the security floor.**')
        ->and($rule)->toContain('**On a refactoring-only pull request, a behaviour change is a Moderate finding — a bug fix included.**')
        ->and($rule)->toContain('**A discrepancy the refactoring carries over with identical behaviour is not a finding at its own severity.**');

    // The process steps that used to fix bugs and race conditions inside the refactoring are gone.
    expect($rule)->not->toContain('fix critical bugs first');
});

test('the filing bar, the review, and every skill route a preserved discrepancy to a follow-up issue (issue #175)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $owner = '*Behaviour stays identical — bugs included*';

    expect((string) file_get_contents($packageDir . '/rules/compound-engineering/tracker.md'))
        ->toContain('3. **A discrepancy a refactoring-only change found and kept unchanged**');

    expect((string) file_get_contents($packageDir . '/rules/code-review/general.md'))
        ->toContain('The one exception is a discrepancy a refactoring-only pull request carries over unchanged');

    expect((string) file_get_contents($packageDir . '/rules/code-review/core-analysis.md'))
        ->toContain('A refactoring-only pull request preserves bugs too');

    $classRefactoring = (string) file_get_contents($packageDir . '/skills/class-refactoring/SKILL.md');
    expect($classRefactoring)->not->toContain('Fix any obvious pre-existing bugs before refactoring')
        ->and($classRefactoring)->toContain($owner);

    $entryPoint = (string) file_get_contents($packageDir . '/skills/refactor-entry-point-to-action/SKILL.md');
    expect($entryPoint)->not->toContain('Fix only safe, relevant issues')
        ->and($entryPoint)->toContain($owner);

    foreach ([
        'skills/resolve-issue/references/pre-existing-issue-handling.md',
        'skills/process-code-review/SKILL.md',
        'skills/create-test/SKILL.md',
        'skills/create-missing-tests-in-pr/SKILL.md',
    ] as $preExistingHandling) {
        expect((string) file_get_contents($packageDir . '/' . $preExistingHandling))
            ->toContain('refactoring-only')
            ->toContain($owner);
    }
});

test('a behaviour change in a refactoring-only pull request is raised once, not again per affected caller (issue #175)', function (): void {
    $packageDir = dirname(__DIR__, 2);

    expect((string) file_get_contents($packageDir . '/rules/refactoring/general.md'))
        ->toContain('**Gating — one finding per behaviour change.** This bullet raises it once, on the changed line.')
        ->toContain('each affected caller stays an entry in `## Affected behaviour outside the diff`');

    expect((string) file_get_contents($packageDir . '/rules/code-review/core-analysis.md'))
        ->toContain('A behaviour change in a refactoring-only pull request stays with `@rules/refactoring/general.md` *Code Review Application*')
        ->toContain('which raises one Moderate finding on the changed line.');
});

test('a carried-over discrepancy is rendered under Deferred to sub-issues on the publish path (issue #175)', function (): void {
    $packageDir = dirname(__DIR__, 2);

    expect((string) file_get_contents($packageDir . '/skills/process-code-review/SKILL.md'))
        ->toContain('or a refactoring-only pull request carries over a discrepancy: one entry per finding')
        ->toContain('and the sub-issue or follow-up issue URL');
});

test('a review of a refactoring-only change checks first that the logic stayed the original', function (): void {
    $packageDir = dirname(__DIR__, 2);

    $rule = (string) file_get_contents($packageDir . '/rules/refactoring/general.md');
    expect($rule)->toContain('**On a refactoring-only pull request, behaviour preservation is the primary check.** Run it before every other lens')
        ->and($rule)->toContain('It is never deferred to a sub-issue at round 3')
        ->and($rule)->not->toContain('a behaviour change is a Critical finding');

    expect((string) file_get_contents($packageDir . '/rules/code-review/core-analysis.md'))
        ->toContain('behaviour preservation is the primary check, and the review compares the old and the new logic of every restructured unit first')
        ->toContain('a behaviour change in it is a Moderate finding');

    expect((string) file_get_contents($packageDir . '/agents/leonardo.md'))
        ->toContain('**Refactoring agenda:** when the assignment is refactoring-only')
        ->toContain('is a **Moderate** finding whose Suggested Fix restores the old behaviour');

    expect((string) file_get_contents($packageDir . '/skills/process-code-review/references/round-three-deferral.md'))
        ->toContain('A behaviour change in a refactoring-only pull request is such a Moderate too');
});
