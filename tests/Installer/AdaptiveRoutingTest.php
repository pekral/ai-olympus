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

test('the implementer and the reviewer run at the default model tier', function (): void {
    $packageDir = dirname(__DIR__, 2);

    // Default-tier-first is the saving. A role permanently pinned to the expensive model pays for it
    // on a README typo, which is exactly the fixed overhead adaptive routing exists to remove.
    foreach (['hephaestus', 'athena'] as $agent) {
        $content = (string) file_get_contents($packageDir . '/agents/' . $agent . '.md');
        expect($content)->toContain("\nmodel: sonnet\n");
        expect($content)->toContain('## Model tier — the default tier, escalated only on a recorded reason');
        expect($content)->toContain('Blocked: needs model escalation');

        // The tier is a role, not a model name, or the contract cannot be applied off Claude Code.
        expect($content)->toContain('The tier is a role, not a model name');
    }
});

test('the routing contract binds to OpenAI / Codex, not only to Claude Code', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $rule = (string) file_get_contents($packageDir . '/rules/compound-engineering/orchestration.md');
    $agents = (string) file_get_contents($packageDir . '/AGENTS.md');

    // Writing the contract in vendor model names made it inapplicable on the other platform this
    // package ships to, and wrong on both the first time a vendor renames a model. The rule owns
    // when a step escalates; which model that means belongs to the agent's own definition.
    expect($rule)->toContain('### Default model tier first, escalate with a recorded reason');
    expect($rule)->toContain('**Which model each tier means is declared in the specialist\'s own definition, per platform, and nowhere else.**');
    expect($rule)->not->toContain('model_reasoning_effort');
    expect($agents)->not->toContain('model_reasoning_effort');

    // A platform that cannot switch models for one step records what it did instead. Claiming an
    // escalation that did not happen is a fabricated measurement.
    expect($rule)->toContain('**A platform that offers no per-dispatch model control never fakes one.**');
});

test('every agent declares its own model tiers, per platform, in its own definition', function (): void {
    $packageDir = dirname(__DIR__, 2);

    // Claude Code: the frontmatter plus the tier daedalus dispatches at.
    foreach (['hephaestus', 'athena'] as $agent) {
        $content = (string) file_get_contents($packageDir . '/agents/' . $agent . '.md');
        expect($content)->toContain('**On Claude Code:** default tier `sonnet`');
        expect($content)->toContain('Escalated tier `opus`');
        expect($content)->toContain('codex/agents/' . $agent . '.toml` declares both');
    }

    // Codex / OpenAI: every shipped adapter declares both tiers for its own role, including the
    // three that never escalate — an absent declaration would read as an undecided one.
    foreach (['daedalus', 'hephaestus', 'athena', 'argus', 'hermes'] as $agent) {
        $toml = (string) file_get_contents($packageDir . '/codex/agents/' . $agent . '.toml');
        expect($toml)->toContain('Model tiers for this role on Codex — this file is where they are declared, not the rules:');
        expect($toml)->toContain('- Default tier:');
        expect($toml)->toContain('- Escalated tier:');

        // The bare script paths the classifier is referenced by — `@skills/<path>` never covered those.
        expect($toml)->toContain('skills/_shared/classify-risk.sh');
    }

    // The three roles that derive no new fact must say they never escalate, not leave it open.
    foreach (['daedalus', 'argus', 'hermes'] as $agent) {
        $toml = (string) file_get_contents($packageDir . '/codex/agents/' . $agent . '.toml');
        expect($toml)->toContain('- Escalated tier: none —');
    }
});

test('the routing contract is documented for humans, not only for agents', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $docs = (string) file_get_contents($packageDir . '/docs/agents.md');
    $readme = (string) file_get_contents($packageDir . '/README.md');

    expect($docs)->toContain('## Adaptive routing (always on)');
    expect($docs)->toContain('classify-risk.sh');
    expect($readme)->toContain('### Adaptive routing — how much pipeline a task gets');
    expect($readme)->toContain('`--thorough`');

    // Both surfaces must state the cost of the FAST tier rather than only its benefit.
    expect($docs)->toContain('What this trades away, stated rather than hidden');
    expect($readme)->toContain('Adaptive routing');

    // And both must say the mechanism is not Claude-only, or a Codex reader assumes it is.
    expect($docs)->toContain('It is platform-neutral.');
    expect($readme)->toContain('so Codex / OpenAI sessions route identically');
});

test('no shipped surface claims the implementer reviews its own diff', function (): void {
    // The duplicate review was removed from `resolve-issue`, but `agents/hephaestus.md` kept telling
    // the agent its pre-PR self-check runs `code-review` + `security-review` and gates on the
    // findings — the opposite of what the skill says, three lines below its own "never review your
    // own work". Two more copies sat in `docs/agents.md` and in daedalus's athena-not-registered
    // fallback, which named a security pass nobody performs any more.
    //
    // Pin the class, not the sentence: any phrasing that has the self-check RUN a review skill.
    // Negative statements ("does not run", "runs no review skill") must keep passing, so the
    // patterns match the positive claim only.
    $claimsReviewRuns = [
        'self-check runs `code-review`',
        'self-check runs `security-review`',
        'self-check with `code-review`',
        'self-check still runs `code-review`',
        'pass running `code-review`',
        'runs `code-review` + `security-review`',
        '`code-review` + `security-review` over its own diff',
        '`code-review` + `security-review` once over its own diff',
    ];

    $violations = [];

    foreach (packageTextFiles() as $relativePath => $contents) {
        if (preg_match('#^(rules|skills|agents|docs)/.+\.md$#', $relativePath) !== 1) {
            continue;
        }

        foreach ($claimsReviewRuns as $claim) {
            if (str_contains($contents, $claim)) {
                $violations[] = $relativePath . ' — "' . $claim . '"';
            }
        }
    }

    expect($violations)->toBe([]);
});

test('hephaestus points at the lightweight self-check and nothing else', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $hephaestus = (string) file_get_contents($packageDir . '/agents/hephaestus.md');
    $daedalus = (string) file_get_contents($packageDir . '/agents/daedalus.md');

    // The agent must defer to the skill that owns the pass, and the deferral must be visibly
    // consistent with the "never review your own work" boundary stated above it.
    expect($hephaestus)->toContain('**lightweight pre-PR self-check**');
    expect($hephaestus)->toContain('it runs **no** review skill over your diff');
    expect($hephaestus)->toContain('never review your own work');

    // An unregistered athena must not silently promote the implementer into the reviewer's place.
    expect($daedalus)->toContain('athena is not registered — no pre-implementation security analysis runs');
    expect($daedalus)->toContain('**Do not route the analysis into `hephaestus` instead:**');
});
