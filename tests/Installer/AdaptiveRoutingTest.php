<?php

declare(strict_types = 1);

// Adaptive routing: a deterministic classifier decides how much pipeline a task gets, the
// implementer and the reviewer default to sonnet, and the duplicate pre-PR LLM review is gone.
//
// Per the project's test-isolation rule a Pest test cannot exec a real .sh, so the classifier's
// behavioural proof lives in its own `--self-test` (the precedent is
// `skills/_shared/assert-current-repo.sh`) and the guards below pin the scenarios that self-test
// is required to cover, plus the wiring that makes the verdict actually change what a run does.

test('the deterministic risk classifier is shipped and executable', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $script = $packageDir . '/skills/_shared/classify-risk.sh';

    expect(is_file($script))->toBeTrue();
    expect(is_executable($script))->toBeTrue();

    $content = (string) file_get_contents($script);
    expect($content)->toStartWith('#!/usr/bin/env bash');
    expect($content)->toContain('set -euo pipefail');
    expect($content)->toContain('--self-test');
});

test('the classifier emits a tier, a score, and one attributable signal per point', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/skills/_shared/classify-risk.sh');

    // The whole point of a deterministic router over an LLM one is that the verdict can be argued
    // with. A tier with no printed reason is as unauditable as a model's opinion.
    foreach (['printf \'tier=%s', 'printf \'score=%s', 'printf \'basis=%s', 'printf \'forced=%s', 'printf \'floor=%s'] as $line) {
        expect($content)->toContain($line);
    }

    expect($content)->toContain('signal=${sign}${delta}|${name}|${evidence}');
});

test('classifier self-test covers every routing scenario the contract names', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/skills/_shared/classify-risk.sh');

    // One label per required scenario. Without the self-test actually asserting these, a mutation
    // to a single regex silently re-routes a whole class of change and nothing notices.
    $scenarios = [
        'README typo is FAST',
        'tests-only change is FAST',
        'small fix with its test is FAST',
        'ordinary feature is STANDARD',
        'authorization middleware is CRITICAL',
        'a migration is CRITICAL',
        'a payment path is CRITICAL',
        'grown diff re-classifies to CRITICAL',
        'floor is never lowered by a later small diff',
        '--thorough forces the full pipeline',
        '--fast is refused on an auth change',
        'assignment text alone can force CRITICAL',
    ];

    foreach ($scenarios as $label) {
        expect($content)->toContain('\'' . $label . '\'');
    }
});

test('a sensitive area forces CRITICAL and a lower override is refused, not applied', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/skills/_shared/classify-risk.sh');

    // A force an override can silence is not a force — it would let `--fast` route an
    // authorization change past every reviewer the tier exists to summon.
    expect($content)->toContain('override_refused="$(tier_name "$override_rank")"');
    expect($content)->toContain('\'a refused override is recorded\'');
    expect($content)->toContain('forced=\'auth-security-secrets\'');
    expect($content)->toContain('forced=\'migrations-data-loss\'');
    expect($content)->toContain('forced=\'payments-billing\'');
});

test('deterministic gates are explicitly outside the trade at every tier', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $rule = (string) file_get_contents($packageDir . '/rules/compound-engineering/orchestration.md');

    expect($rule)->toContain('## Adaptive routing — the cheapest reliable execution path');
    expect($rule)->toContain('### Deterministic gates are not part of the trade');
    expect($rule)->toContain('When a classification is genuinely uncertain, the safer tier wins.');

    // Savings mode predates this and claimed "the same reviewer runs" unconditionally, which a
    // FAST run makes false. The two mechanisms must be reconciled, not left contradicting.
    expect($rule)->toContain('Savings mode and adaptive routing are orthogonal');
    expect($rule)->toContain('whenever the tier calls for one');
});

test('the implementer and the reviewer default to sonnet', function (): void {
    $packageDir = dirname(__DIR__, 2);

    // Sonnet-first is the saving. A role permanently pinned to opus pays for the expensive tier on
    // a README typo, which is exactly the fixed overhead adaptive routing exists to remove.
    foreach (['hephaestus', 'athena'] as $agent) {
        $content = (string) file_get_contents($packageDir . '/agents/' . $agent . '.md');
        expect($content)->toContain("\nmodel: sonnet\n");
        expect($content)->toContain('## Model tier — sonnet by default, opus on a recorded escalation');
        expect($content)->toContain('Blocked: needs model escalation');
    }
});

test('daedalus runs the classifier instead of estimating a tier itself', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $daedalus = (string) file_get_contents($packageDir . '/agents/daedalus.md');

    expect($daedalus)->toContain('skills/_shared/classify-risk.sh');
    expect($daedalus)->toContain('## Risk tier');

    // The ad-hoc heuristic this replaced lived only in prose, so two steps of the same run could
    // (and did) read "high-risk" differently.
    expect($daedalus)->not->toContain('the same broad-change heuristic the scoped mode uses');

    // Re-classification against the real diff, with the previous tier as a floor.
    expect($daedalus)->toContain('--floor <the initial tier>');
    expect($daedalus)->toContain('re-classify the change against the actual diff');
});

test('the routing ledger records why the run spent what it spent', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $daedalus = (string) file_get_contents($packageDir . '/agents/daedalus.md');

    expect($daedalus)->toContain('### Routing ledger');
    expect($daedalus)->toContain('.claude/run/<source-slug>.routing');

    foreach (['|tier|initial|', '|tier|final|', '|escalation|', '|stage|executed|', '|stage|skipped|'] as $line) {
        expect($daedalus)->toContain($line);
    }

    // Counts are derived from the ledgers that already exist. A second copy of a number is a
    // second thing that can be wrong.
    expect($daedalus)->toContain('Counts are derived, never tracked twice');

    // The ledger is scratch state and must be cleaned up with its three siblings.
    expect($daedalus)->toContain('rm -f "$BRIEF" "${BRIEF%.md}.dispatches" "${BRIEF%.md}.audit" "${BRIEF%.md}.routing"');
});

test('the implementer no longer runs a full duplicate review before handing off', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $skill = (string) file_get_contents($packageDir . '/skills/resolve-issue/SKILL.md');

    expect($skill)->toContain('## Pre-PR self-check (lightweight, deterministic)');
    expect($skill)->toContain('It does **not** run `code-review` / `security-review` over its own diff');

    // The two inline invocations are what doubled the review bill on every run.
    expect($skill)->not->toContain('Invoke `@skills/security-review/SKILL.md` directly in this skill\'s context');
    expect($skill)->not->toContain('run `@skills/code-review/SKILL.md` inline on the local changes');

    // The reference file follows the skill, and it states what the removal costs.
    $reference = $packageDir . '/skills/resolve-issue/references/pre-pr-self-check.md';
    expect(is_file($reference))->toBeTrue();
    expect(is_file($packageDir . '/skills/resolve-issue/references/code-quality-self-check.md'))->toBeFalse();
    expect((string) file_get_contents($reference))->toContain('What is lost, stated rather than hidden');
});

