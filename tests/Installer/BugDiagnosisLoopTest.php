<?php

declare(strict_types = 1);

/**
 * A bug fix starts from evidence: one command that fails on the symptom, built before any search for
 * the cause. `skills/resolve-issue/references/bug-diagnosis.md` owns the six-step procedure, and the
 * skill body, the TDD skill, and the self-check point to it. These pins fix the owner, the fixed
 * order, and the one gate that keeps a temporary debug tag out of the pull request.
 */
test('the diagnosis reference owns the six steps in fixed order', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $reference = (string) file_get_contents($packageDir . '/skills/resolve-issue/references/bug-diagnosis.md');

    $headings = [
        '## 1. Feedback loop first',
        '## 2. Minimisation',
        '## 3. Hypotheses',
        '## 4. Instrumentation',
        '## 5. Fix and regression test',
        '## 6. Cleanup',
    ];

    $offsets = array_map(static fn (string $heading): int|false => strpos($reference, $heading), $headings);

    expect($offsets)->each->not->toBeFalse();
    expect($offsets)->toBe(array_values(array_unique($offsets)));
    expect($offsets)->toBe((static function () use ($offsets): array {
        $sorted = $offsets;
        sort($sorted);

        return $sorted;
    })());
});

test('the feedback loop lists its options in order and blocks the analysis until one command fails', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $reference = (string) file_get_contents($packageDir . '/skills/resolve-issue/references/bug-diagnosis.md');

    expect($reference)->toContain('Build **one command** that reliably fails on the reported symptom.');
    expect($reference)->toContain('A feature (HTTP) test comes before a unit test.');
    expect($reference)->toContain('`git bisect run vendor/bin/pest --filter=<test>`');
    expect($reference)->toContain('`@skills/laravel-telescope/SKILL.md`');
    expect($reference)->toContain('The analysis does not continue without it.');

    // Cause is searched only after the loop exists, and only the verified cause is published.
    expect($reference)->toContain('Never publish a ranked list of candidate causes.');
    expect($reference)->toContain('Log::debug(\'[DEBUG-a3f9] …\')');
    expect($reference)->toContain('`@skills/mysql-problem-solver/SKILL.md`');
});

test('the cleanup step greps the debug tag and the self-check runs the same command', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $reference = (string) file_get_contents($packageDir . '/skills/resolve-issue/references/bug-diagnosis.md');
    $skill = (string) file_get_contents($packageDir . '/skills/resolve-issue/SKILL.md');

    expect($reference)->toContain('git grep --untracked -n \'\\[DEBUG-\'');
    expect($reference)->toContain('State the verified cause in the commit body and in the PR description.');
    expect($skill)->toContain('no `[DEBUG-` tag (`git grep` of `references/bug-diagnosis.md` step 6 is empty)');
});

test('the bug path, the TDD skill and the read-only analysis point to the one owner', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $skill = (string) file_get_contents($packageDir . '/skills/resolve-issue/SKILL.md');
    $tdd = (string) file_get_contents($packageDir . '/skills/test-driven-development/SKILL.md');
    $analysis = (string) file_get_contents($packageDir . '/skills/analyze-problem/SKILL.md');

    expect($skill)->toContain('**Mandatory: diagnose first** per `references/bug-diagnosis.md`');
    expect($skill)->toContain('- references/bug-diagnosis.md');
    expect($tdd)->toContain('the RED test comes out of the diagnosis loop of `@skills/resolve-issue/references/bug-diagnosis.md`');
    expect($analysis)->toContain('implementation-time bug diagnosis lives in `@skills/resolve-issue/references/bug-diagnosis.md`');
});
