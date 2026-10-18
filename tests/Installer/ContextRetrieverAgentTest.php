<?php

declare(strict_types = 1);

// Issue #133 — a dedicated retriever returns one bounded Context Package. Per the project's
// test-isolation rule a Pest test cannot exec a real .sh, so the packing behaviour is proved by
// `pack-context.sh --self-test`, which `composer build` runs; the tests below pin the scenarios
// that self-test must cover and the wiring that makes splinter use the retriever.

test('metalhead ships on both platforms as a read-only agent with its own Codex tier (issue #133)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $claude = (string) file_get_contents($packageDir . '/agents/metalhead.md');
    $codex = (string) file_get_contents($packageDir . '/codex/agents/metalhead.toml');

    expect($claude)->toMatch('/^name: metalhead$/m')
        ->and($claude)->toMatch('/^tools: Read, Glob, Grep, Bash$/m')
        ->and($claude)->toMatch('/^disallowedTools: Write, Edit$/m')
        ->and($codex)->toContain('name = "metalhead"')
        ->and($codex)->toContain('model = "luna"')
        ->and($codex)->toContain('model_reasoning_effort = "medium"')
        ->and($codex)->toContain('First read `.codex/agent-instructions/metalhead.md` in full');
});

test('metalhead never solves the task and runs only on a verdict that asked for context (issue #133)', function (): void {
    $metalhead = (string) file_get_contents(dirname(__DIR__, 2) . '/agents/metalhead.md');

    expect($metalhead)->toContain('**You never solve the task.** You do not implement, review, plan, analyse a problem')
        ->and($metalhead)->toContain('When `gate.context_required` is not `true`, stop with `Blocked: the gate did not require context`')
        ->and($metalhead)->toContain('A source the gate disabled is not queried at all')
        ->and($metalhead)->toContain('give them the same `key`, so the script returns the fact once with every reference')
        ->and($metalhead)->toContain('Do not rank, merge, or cut by hand.');
});

test('splinter requests context through the structured interface instead of reading the sources (issue #133)', function (): void {
    $splinter = splinterContractText();

    expect($splinter)->toContain('### Context retrieval — request a package, never read the sources')
        ->and($splinter)->toContain(
            'Never read that knowledge yourself. When the gate returns `context_required: true`, request it through the structured interface',
        )
        ->and($splinter)->toContain('Never dispatch it when the effective verdict is `context_required: false`.')
        ->and($splinter)->toContain('## Retrieved context  <path to the Context Package')
        ->and($splinter)->toContain('so `donatello`, `leonardo`, `raphael`, and `april` read the same package')
        ->and($splinter)->toContain('Run `date +%s` first and keep the value.')
        ->and($splinter)->toContain(
            '--output ${BRIEF%.md}.context.json --started-at <the value>`, so a cache hit carries the measured retrieval duration.',
        )
        ->and($splinter)->toContain('Exit `3` is a miss: continue. Any other exit is also a miss: continue without a package.')
        ->and($splinter)->toContain('omit `--retrieval-seconds` alone when `metadata.retrieval_seconds` is `null`')
        ->and($splinter)->toContain('**No package is not a blocker.**')
        ->and($splinter)->toMatch('/^Bash is granted for one purpose:.*`skills\/_shared\/pack-context\.sh lookup`/m');
});

test('the context package is ranked, deduplicated, bounded, provenanced, and cached by repository state (issue #133)', function (): void {
    $packer = (string) file_get_contents(dirname(__DIR__, 2) . '/skills/_shared/pack-context.sh');

    foreach ([
        'a bug fix with two symbols and a 1500-token budget is packed',
        'the package stays under the token budget',
        'the package carries architecture, learnings, related code and tests',
        'every returned item keeps its provenance',
        'the task description never reaches the package',
        'results are ranked by relevance',
        'one fact from three sources is returned once with three references',
        'context over budget is truncated by rank, never by input order',
        'the same inputs produce a byte-identical package',
        'the token budget caps the package when the item budget does not',
        'a request whose gate did not require context is refused',
        'a source the gate disabled is refused',
        'an item from a source that was not queried is refused',
        'an output path that is not git-ignored is refused',
        'a lookup on the same repository state is a hit',
        'a cache hit carries the measured retrieval duration',
        'an uncommitted change never reuses the cached package',
        'a new commit never reuses the cached package',
        'the summary carries counts and the measured retrieval duration',
    ] as $label) {
        expect($packer)->toContain('\'' . $label . '\'');
    }

    // The packer reuses the git-tree helpers the machine gate record already owns.
    expect($packer)->toContain('. "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/gate-record.sh"');
    expect($packer)->toContain('gate_tree_of HEAD');
});

test('retrieval telemetry is recorded as counts (issue #133)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $recorder = (string) file_get_contents($packageDir . '/skills/_shared/record-metrics.sh');

    expect($recorder)->toContain('\'retrieval counters are stored as counts\'')
        ->and($recorder)->toContain('--items-inspected) items_inspected="$value" ;;')
        ->and($recorder)->toContain('--context-cache-hits) cache_hits="$value" ;;')
        ->and($recorder)->toContain('--context-cache-misses) cache_misses="$value" ;;')
        ->and($recorder)->toContain('--retrieval-seconds) retrieval_seconds="$value" ;;')
        ->and(splinterContractText())->toContain(
            '`--context-cache-hits` / `--context-cache-misses` count the packages whose `metadata.cache` is `hit` / `miss`',
        );
});
