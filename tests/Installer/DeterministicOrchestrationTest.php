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

    $helpers = [
        'run-validation',
        'plan-route',
        'check-handoff',
        'render-report',
        'record-metrics',
        'read-manifest',
        'run-gate',
        'verify-gate',
        'gate-context',
        'pack-context',
    ];

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
    /** @var array{scripts: array<string, array<int, string>>} $composer */
    $composer = json_decode((string) file_get_contents($packageDir . '/composer.json'), associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
    $selfTests = implode(' ', $composer['scripts']['shell-self-tests']);

    foreach ($helpers as $helper) {
        expect($selfTests)->toContain(' skills/_shared/' . $helper . '.sh');
    }
});

test('the validation manifest is executed without a shell and against an allow-list', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $runner = (string) file_get_contents($packageDir . '/skills/_shared/run-validation.sh');
    // The command checks and the executor are shared with the gate scripts (issue #169), so the
    // runner sources them rather than carrying its own copy.
    $commands = (string) file_get_contents($packageDir . '/skills/_shared/project-commands.sh');

    // The manifest is written by an agent whose context carries tracker text anyone can write. A
    // manifest reaching a shell would be arbitrary code execution in the one step no human reviews.
    expect($runner)->toContain('SECURITY — why the manifest is not a shell script');
    expect($runner)->toContain('. "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/project-commands.sh"');
    expect($runner)->toContain('run_command "$command" "$log"');
    expect($commands)->toContain('ALLOWED_EXECUTABLES=(');
    expect($commands)->toContain('SHELL_METACHARACTERS=');

    // No shell: the command is split into argv and executed directly. Measured on the executable
    // half only — the self-test below legitimately carries `/bin/sh -c uname` as a payload it is
    // required to refuse, and a pin that matched it would fail on the very case it wants covered.
    $executable = substr($runner, 0, (int) strpos($runner, 'self_test() {')) . $commands;
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

test('the validation runner extends its allow-list only from the default-branch project manifest', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $runner = (string) file_get_contents($packageDir . '/skills/_shared/run-validation.sh');
    $commands = (string) file_get_contents($packageDir . '/skills/_shared/project-commands.sh');
    $reader = (string) file_get_contents($packageDir . '/skills/_shared/read-manifest.sh');

    expect($runner)->toContain('load_project_manifest || refuse "$PROJECT_REFUSAL"');
    expect($commands)->toContain('reader="$PROJECT_COMMANDS_DIR/read-manifest.sh"');
    expect($commands)->toContain('"$reader" --env');
    expect($reader)->toContain('PROTECTED_ENV_RE');
    expect($commands)->toContain('^vendor/bin/[A-Za-z0-9_][A-Za-z0-9._-]*$');

    foreach ([
        'a manifest executable runs with the manifest env',
        'without the manifest env the same command fails',
        'an executable only the working tree lists is refused',
        'a manifest executable outside vendor/bin refuses the run',
        'a manifest env that redirects PATH refuses the run',
        'a manifest env value with a space refuses the run',
        'a manifest env that injects git configuration refuses the run',
    ] as $label) {
        expect($runner)->toContain('\'' . $label . '\'');
    }

    expect($reader)->toContain('git show "$ref:./composer.json"');

    foreach ([
        'without a default-branch ref the manifest is empty',
        'the working tree never overrides the default branch',
        'invalid composer.json yields {} and exit 3',
        'composer.json resolves relative to the current directory',
        '--env prints validated NAME=value lines',
    ] as $label) {
        expect($reader)->toContain('\'' . $label . '\'');
    }
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
        'runtime acceptance adds raphael on FAST',
        'runtime acceptance adds raphael on STANDARD',
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
    expect($planner)->toContain('\'an escalation never replays the acceptance pass\'');

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

test('splinter may run the context relevance gate it is told to run first', function (): void {
    $splinter = (string) file_get_contents(dirname(__DIR__, 2) . '/agents/splinter.md');

    expect($splinter)->toMatch('/^Bash is granted for one purpose:.*`skills\/_shared\/gate-context\.sh`/m');
});

test('the deterministic helpers are mapped for Codex, not only for Claude Code', function (): void {
    $packageDir = dirname(__DIR__, 2);

    foreach (['splinter', 'donatello', 'leonardo', 'raphael', 'april'] as $agent) {
        $toml = (string) file_get_contents($packageDir . '/codex/agents/' . $agent . '.toml');
        expect($toml)->toContain('run-validation.sh');
        // The shared machine gate record (issue #169) runs through the same helpers on Codex.
        expect($toml)->toContain('`run-gate.sh`, `verify-gate.sh`');
        expect($toml)->toContain('`gate-context.sh`');
        expect($toml)->toContain('platform-neutral by construction');
    }

    // AGENTS.md is the entry point for a Codex session with no custom agent registered, so it has to
    // carry the same instruction.
    $agents = (string) file_get_contents($packageDir . '/AGENTS.md');
    expect($agents)->toContain('Deterministic work is not an LLM\'s job.');
    expect($agents)->toContain('a green verdict needs no session');
});

test('the context relevance gate decides cheaply and defaults to false', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $gate = (string) file_get_contents($packageDir . '/skills/_shared/gate-context.sh');

    expect($gate)->toContain('context_required');
    expect($gate)->toContain('confidence');
    expect($gate)->toContain('domains');
    expect($gate)->toContain('sources');
    expect($gate)->toContain('budget');
    expect($gate)->toContain('reason');

    foreach ([
        'default outcome skips retrieval with context_required false',
        'default verdict turns every source off with an empty budget',
        'ambiguous task without paths, symbols, or labels skips retrieval',
        'a concrete class in a known domain returns context_required true',
        'symbol match enables relevant sources and sets domains',
        'each knowledge source can be enabled or disabled independently',
        'deterministic detection is preferred over LLM',
        'insufficient deterministic signals escalate to LLM classification',
        'budget smaller than available evidence still produces valid verdict',
        'task type docs or formatting skips retrieval',
        'quotes and control characters in input still give valid JSON',
        'mixed-case auth words in keywords map to the security domain',
        'a symbol that maps to no domain escalates to LLM classification',
        'a documentation label with changed files does not skip retrieval',
        'reason never carries a caller input value',
    ] as $label) {
        expect($gate)->toContain('\'' . $label . '\'');
    }

    // One vocabulary for the sensitive domains: the gate and the risk classifier source the same
    // word lists, so a word added for one verdict cannot silently miss the other.
    $vocabulary = (string) file_get_contents($packageDir . '/skills/_shared/domain-vocabulary.sh');
    $classifier = (string) file_get_contents($packageDir . '/skills/_shared/classify-risk.sh');

    foreach (['AUTH_RE', 'DATA_RE', 'QUEUE_RE'] as $name) {
        $definition = '/^' . $name . '=/m';
        expect($vocabulary)->toMatch($definition);
        expect($gate)->not->toMatch($definition);
        expect($classifier)->not->toMatch($definition);
    }

    foreach ([$gate, $classifier] as $script) {
        expect($script)->toContain('. "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/domain-vocabulary.sh"');
    }

    $recorder = (string) file_get_contents($packageDir . '/skills/_shared/record-metrics.sh');
    expect($recorder)->toContain('\'context gate counters are stored as counts\'');
    expect(splinterContractText())->toContain('`--context-gate-executed`, `--context-requested`, `--context-skipped`');
});
