<?php

declare(strict_types = 1);

// Deterministic orchestration: the work that does not need a model no longer buys one.
//
// Per the project's test-isolation rule a Pest test cannot exec a real .sh, so each script's
// behavioural proof lives in its own `--self-test` (the precedent is
// `skills/_shared/assert-current-repo.sh`) and the guards below pin the scenarios those self-tests
// are required to cover, plus the wiring that makes the verdicts actually change what a run does.

test('every deterministic orchestration helper is shipped, executable, and self-testing', function (): void {
    $packageDir = dirname(__DIR__, 2);

    $helpers = ['run-validation', 'plan-route', 'check-handoff', 'render-report', 'record-metrics'];

    foreach ($helpers as $helper) {
        $script = $packageDir . '/skills/_shared/' . $helper . '.sh';

        expect(is_file($script))->toBeTrue();
        expect(is_executable($script))->toBeTrue();

        $content = (string) file_get_contents($script);
        expect($content)->toStartWith('#!/usr/bin/env bash');
        expect($content)->toContain('set -euo pipefail');
        expect($content)->toContain('--self-test');
    }

    // A self-test nothing executes is dead weight: it can be mutated to always-pass and the build
    // stays green. This is the same wiring the shared repository guard already depends on.
    $composer = (string) file_get_contents($packageDir . '/composer.json');

    foreach ($helpers as $helper) {
        expect($composer)->toContain('skills/_shared/' . $helper . '.sh --self-test');
    }
});

test('the validation manifest is executed without a shell and against an allow-list', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $runner = (string) file_get_contents($packageDir . '/skills/_shared/run-validation.sh');

    // The manifest is written by an agent whose context carries tracker text anyone can write. A
    // manifest reaching a shell would be arbitrary code execution in the one step no human reviews.
    expect($runner)->toContain('SECURITY — why the manifest is not a shell script');
    expect($runner)->toContain('ALLOWED_EXECUTABLES');
    expect($runner)->toContain('SHELL_METACHARACTERS');

    // No shell: the command is split into argv and executed directly. Measured on the executable
    // half only — the self-test below legitimately carries `/bin/sh -c uname` as a payload it is
    // required to refuse, and a pin that matched it would fail on the very case it wants covered.
    $executable = substr($runner, 0, (int) strpos($runner, 'self_test() {'));
    $code = (string) preg_replace('/^\s*#.*$/m', '', $executable);
    expect($code)->not->toContain('eval ');
    expect($code)->not->toContain('sh -c');
    expect($code)->not->toContain('bash -c');
    expect($code)->toContain('"${argv[@]}"');

    // Each of these is a real injection shape the self-test is required to refuse.
    foreach ([
        'a chained command is refused',
        'a piped command is refused',
        'command substitution is refused',
        'a backgrounded command is refused',
        'a redirect is refused',
        'a non-allow-listed executable is refused',
        'rm is refused however it is spelled',
        'an absolute path is refused',
        'traversal out of the project is refused',
        'a quoted argument is refused rather than mis-split',
    ] as $label) {
        expect($runner)->toContain('\'' . $label . '\'');
    }

    // A refused manifest must refuse the whole run, before anything executes: half-executed
    // validation is a state nobody can act on.
    expect($runner)->toContain('one bad command refuses the whole manifest');
    expect($runner)->toContain('refusal happens before execution');
});

test('the validation runner separates a pass, a failure, and an undeterminable scope', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $runner = (string) file_get_contents($packageDir . '/skills/_shared/run-validation.sh');

    // The escalate verdict is the contract: it is the script, not the orchestrator's judgment, that
    // decides whether a model is needed.
    expect($runner)->toContain('"escalate"');
    expect($runner)->toContain('\'a passing manifest passes\'');
    expect($runner)->toContain('\'a failing command fails and escalates\'');

    // An empty manifest must escalate, never pass: "nothing to run" and "I could not work out what
    // to run" are indistinguishable to the reader, and the second is the dangerous one.
    expect($runner)->toContain('\'an empty manifest is invalid, never a pass\'');
    expect($runner)->toContain('validation scope could not be determined');
});

test('the route planner produces each tier plan deterministically', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $planner = (string) file_get_contents($packageDir . '/skills/_shared/plan-route.sh');

    // The routing contract used to be prose the orchestrator re-derived every run. As a plan it is
    // asserted instead of read.
    foreach ([
        'FAST runs one agent and validates deterministically',
        'STANDARD adds the review at the default model tier',
        'CRITICAL escalates the model and validates before review',
        'CRITICAL with a security question analyses first',
        'runtime acceptance adds raphael on CRITICAL',
        '--thorough runs the full pipeline over a FAST verdict',
        'no tracker means no reporting stage to run',
    ] as $label) {
        expect($planner)->toContain('\'' . $label . '\'');
    }

    // A post-implementation escalation owes the stages the lower tier skipped — never a replay of
    // the implementation the run already has, and never a lowered tier.
    expect($planner)->toContain('\'FAST escalated to STANDARD owes the review\'');
    expect($planner)->toContain('\'FAST escalated to CRITICAL owes both validations and the review\'');
    expect($planner)->toContain('\'a tier cannot be lowered by re-classification\'');

    // The stage types are what separate an LLM dispatch from a script run.
    foreach (['deterministic_validation', 'deterministic_classification', 'deterministic_reporting'] as $type) {
        expect($planner)->toContain($type);
    }
});

test('handoffs are bounded, structured, and keep evidence out of the document', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $validator = (string) file_get_contents($packageDir . '/skills/_shared/check-handoff.sh');

    expect($validator)->toContain('DEFAULT_BUDGET=600');
    expect($validator)->toContain('\'an over-budget handoff is rejected\'');
    expect($validator)->toContain('\'a raised budget accepts the same handoff\'');

    // The budget alone does not catch a short handoff that inlines the wrong kind of content — and
    // the next one will be longer.
    expect($validator)->toContain('\'an inlined diff is rejected\'');
    expect($validator)->toContain('\'inlined test output is rejected\'');
    expect($validator)->toContain('reference the artifact path instead');

    // The implementer's own contract points at the validator rather than restating the budget.
    $donatello = (string) file_get_contents($packageDir . '/agents/donatello.md');
    expect($donatello)->toContain('skills/_shared/check-handoff.sh --role implementation');
    expect($donatello)->toContain('never paste a diff, never paste test output');
});

test('the routine report is rendered from run data, and april is reserved for real writing', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $renderer = (string) file_get_contents($packageDir . '/skills/_shared/render-report.sh');
    $april = (string) file_get_contents($packageDir . '/agents/april.md');
    $splinter = splinterContractText();

    expect($renderer)->toContain('\'a completed run renders every known field\'');
    expect($renderer)->toContain('\'a failed run reports the failure, not a success\'');

    // A line reading "PR: none" is noise; an absent fact is simply absent.
    expect($renderer)->toContain('\'absent fields render no empty lines\'');

    // The report follows the assignment's language. An unsupported language escalates rather than
    // silently emitting English onto a tracker — which is what @rules/reports/general.md forbids.
    expect($renderer)->toContain('\'the report renders in the assignment language\'');
    expect($renderer)->toContain('\'an unsupported language escalates rather than guessing\'');

    // april states where the boundary is, and splinter routes around it.
    expect($april)->toContain('## When a model is warranted — and when a template is');
    expect($april)->toContain('you are not dispatched for it');
    expect($splinter)->toContain('skills/_shared/render-report.sh');
    expect($splinter)->toContain('**Dispatch `april` only when the renderer cannot do the job**');
});

test('metrics are persisted as counts only, outside the repository', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $recorder = (string) file_get_contents($packageDir . '/skills/_shared/record-metrics.sh');

    expect($recorder)->toContain('PRIVACY — what is never written');
    expect($recorder)->toContain('AI_OLYMPUS_HOME');

    // Asserted rather than promised: the self-test greps the written store for anything that could
    // identify the work, and refuses an unknown flag so a caller cannot smuggle a field in.
    expect($recorder)->toContain('\'no task content is persisted\'');
    expect($recorder)->toContain('\'an unknown flag is refused\'');
    expect($recorder)->toContain('\'token fields are optional\'');

    // The store is JSONL so two runs finishing at once cannot lose each other's entry.
    expect($recorder)->toContain('\'every stored line is valid JSON\'');
});

test('splinter records the run metrics that make the next tuning decision evidence-based', function (): void {
    $splinter = splinterContractText();

    expect($splinter)->toContain('skills/_shared/record-metrics.sh');
    expect($splinter)->toContain('It writes counts only — never source, diffs, prompts, tracker text, branch names, or URLs');
    expect($splinter)->toContain('ai-olympus stats --last=7d');
});

test('the deterministic helpers are mapped for Codex, not only for Claude Code', function (): void {
    $packageDir = dirname(__DIR__, 2);

    foreach (['splinter', 'donatello', 'leonardo', 'raphael', 'april'] as $agent) {
        $toml = (string) file_get_contents($packageDir . '/codex/agents/' . $agent . '.toml');
        expect($toml)->toContain('run-validation.sh');
        expect($toml)->toContain('platform-neutral by construction');
    }

    // AGENTS.md is the entry point for a Codex session with no custom agent registered, so it has to
    // carry the same instruction.
    $agents = (string) file_get_contents($packageDir . '/AGENTS.md');
    expect($agents)->toContain('Deterministic work is not an LLM\'s job.');
    expect($agents)->toContain('a green verdict needs no session');
});
