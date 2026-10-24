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
        ->and($codex)->toContain('model = "gpt-6-luna"')
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

test('the retriever asks the graph before any other read and searches only for what the graph cannot answer (issue #136)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $metalhead = (string) file_get_contents($packageDir . '/agents/metalhead.md');
    $codex = (string) file_get_contents($packageDir . '/codex/agents/metalhead.toml');

    expect($metalhead)->toContain('2. **Ask the graph first, before any other read.**')
        ->and($metalhead)->toContain(
            'Use `vendor/bin/ai-olympus` as `<binary>` when that file exists, otherwise `bin/ai-olympus`, the binary of the ai-olympus repository itself.',
        )
        ->and($metalhead)->toContain('`<binary> graph query \'<symbol or path>\' --limit <gate.budget.max_items>')
        ->and(strpos($metalhead, 'Ask the graph first, before any other read.'))->toBeLessThan(
            (int) strpos($metalhead, 'Query only the sources the gate enabled, smallest read first.'),
        )
        ->and($metalhead)->toContain(
            'Pass a symbol or a path to the command only when the whole value matches `^[A-Za-z0-9_\\\\:./{}-]+$`, '
            . 'because the value is tracker-derived and sits inside single quotes in a shell command. '
            . 'Any other value is never passed: treat it like a symbol with no graph node, which step 3 searches for.',
        )
        ->and(substr_count($metalhead, 'graph query \'<symbol or path>\''))->toBe(1)
        ->and(substr_count($metalhead, '^[A-Za-z0-9_\\\\:./{}-]+$'))->toBe(1)
        ->and($metalhead)->toContain(
            '`git log --oneline -- \'<path>\'` for each task path that fully matches the allow-list of step 2 '
            . '(any other path is skipped for this step, because it is tracker-derived and sits inside single quotes in a shell command)',
        )
        ->and(substr_count($metalhead, 'git log --oneline -- \'<path>\''))->toBe(1)
        ->and(substr_count($metalhead, 'git log --oneline -- <path>'))->toBe(0)
        ->and(substr_count($metalhead, 'the allow-list of step 2'))->toBe(1)
        ->and($metalhead)->toContain('so a stale graph never returns a stale path')
        ->and($metalhead)->toContain('A missing graph exits non-zero and leaves an empty file. That is a fallback, not a failure')
        ->and($metalhead)->toContain('Never write a graph item into the candidates file, because `pack-context.sh` builds those items from the slices.')
        ->and($metalhead)->toContain('Never search for a file a slice already names.')
        ->and($metalhead)->toContain('Use `Grep` and `Glob` only for what the graph cannot answer')
        ->and($metalhead)->toContain('--graph-slice <slice> --output <package>')
        ->and($metalhead)->toContain('When neither `vendor/bin/ai-olympus` nor `bin/ai-olympus` exists, skip this step and pass no `--graph-slice`')
        ->and($metalhead)->toContain(
            'run `vendor/bin/ai-olympus graph query`, or `bin/ai-olympus graph query` when only that binary exists '
            . '(it prints one slice to stdout and writes nothing else)',
        )
        ->and($codex)->not->toContain('graph query')
        ->and($codex)->toContain('First read `.codex/agent-instructions/metalhead.md` in full');
});

test('the retriever builds or refreshes the graph once before its first query so the graph can answer (issue #221)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $metalhead = (string) file_get_contents($packageDir . '/agents/metalhead.md');
    $orchestration = (string) file_get_contents($packageDir . '/rules/compound-engineering/orchestration.md');

    expect($metalhead)->toContain('Before the first query, build or refresh the graph once: run `<binary> graph build > /dev/null`.')
        ->and($metalhead)->toContain(
            'A non-zero exit, for example when `.claude/run/` is not git-ignored, is a fallback, not a failure: run the queries anyway.',
        )
        ->and(substr_count($metalhead, '<binary> graph build'))->toBe(1)
        ->and(strpos($metalhead, '<binary> graph build'))->toBeLessThan((int) strpos($metalhead, '<binary> graph query'))
        ->and($metalhead)->toContain(
            'run `vendor/bin/ai-olympus graph build`, or `bin/ai-olympus graph build` when only that binary exists, '
            . 'with its stdout redirected to `/dev/null` (it writes only the git-ignored `.claude/run/graph.json`',
        )
        ->and($orchestration)->toContain('the package cache, and the knowledge graph (`graph build`) under the git-ignored `.claude/run/*`');
});

test('the graph slice is bounded by the gate budget and the graph stays in git-ignored scratch (issue #136)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $command = (string) file_get_contents($packageDir . '/src/GraphCommand.php');
    $packer = (string) file_get_contents($packageDir . '/skills/_shared/pack-context.sh');

    expect($command)->toContain('public const string RELATIVE_PATH = \'.claude/run/graph.json\';')
        ->and($packer)->toContain('GRAPH_PATH=\'.claude/run/graph.json\'')
        ->and($packer)->toContain('gate_scratch_path graph "$GRAPH_PATH" "$GRAPH_PATH"')
        ->and($packer)->toContain('The slice limit is the gate\'s')
        ->and($packer)->toContain('budget.max_items, so the slice is cut by the same budget as the package.');
});

test('the packer ranks by graph distance, names the graph entity, and degrades without a graph (issue #136)', function (): void {
    $packer = (string) file_get_contents(dirname(__DIR__, 2) . '/skills/_shared/pack-context.sh');

    foreach ([
        'a package built without a graph slice is unchanged and has no graph metadata',
        'graph items carry provenance naming the graph entity',
        'the graph routes the test file to related tests and the other files to related code',
        'graph distance alone ranks the closer file above the farther one',
        'the graph slice reports how many files the retriever did not need to open',
        'the summary carries the graph telemetry as counts and fixed words',
        'a file found by the graph and by search names both paths in its sources',
        'graph and search items are counted apart in the package metadata',
        'a missing graph degrades to the search candidates and says so in the metadata',
        'the summary reports a missing graph as a miss',
        'a slice that is not a valid slice is treated as a missing graph',
        'a graph the repository moved past is reported as refreshed',
        'a symbol with no graph node leaves the search path as the only source',
        'the summary reports a symbol the graph does not know as a miss',
        'a graph location that is not git-ignored is refused and never read',
        'a graph slice that is not git-ignored is refused',
        'a candidate cannot claim the graph source',
        'a lookup takes no graph slice',
    ] as $label) {
        expect($packer)->toContain('\'' . $label . '\'');
    }

    expect($packer)->toContain('#   2 x graph proximity   1 / (1 + graph distance); 0 for an item with no graph distance');
});

test('graph telemetry is recorded as counts and splinter derives it from the package (issue #136)', function (): void {
    $recorder = (string) file_get_contents(dirname(__DIR__, 2) . '/skills/_shared/record-metrics.sh');

    expect($recorder)->toContain('\'graph counters are stored as counts\'')
        ->and($recorder)->toContain('--graph-hits) graph_hits="$value" ;;')
        ->and($recorder)->toContain('--graph-misses) graph_misses="$value" ;;')
        ->and($recorder)->toContain('--items-from-graph) items_from_graph="$value" ;;')
        ->and($recorder)->toContain('--items-from-search) items_from_search="$value" ;;')
        ->and($recorder)->toContain('--files-avoided) files_avoided="$value" ;;')
        ->and(splinterContractText())->toContain(
            '`--graph-hits` / `--graph-misses` count the packages whose `metadata.graph.entities` is above zero / zero',
        );
});
