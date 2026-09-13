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

