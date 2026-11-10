<?php

declare(strict_types = 1);

function orchestratorStartSection(): string
{
    return 'Starting the orchestrator — the top-level role and the nested hand-over';
}

function orchestratorStartFile(string $relativePath): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
}

/**
 * @param array<string, int> $expectedCounts
 * @return array<string, int>
 */
function orchestratorStartCounts(string $text, array $expectedCounts): array
{
    $counts = [];

    foreach (array_keys($expectedCounts) as $literal) {
        $counts[$literal] = substr_count($text, $literal);
    }

    return $counts;
}

test('one section of the orchestration rule owns how krang is started and how its hand-over is finished', function (): void {
    $rule = orchestratorStartFile('rules/compound-engineering/orchestration.md');
    $expected = [
        '## ' . orchestratorStartSection() => 1,
        orchestratorStartSection() => 2,
        '**A row that names `krang` binds the session that holds the `krang` role.** It applies to that session at the same level' => 1,
        'When that session is the top-level session, it does not start `krang` as a subagent. It holds the `krang` role itself:' => 1,
        'On Claude Code that file is `.claude/agents/krang.md`. On Codex it is `.codex/agent-instructions/krang.md`.' => 1,
        'The session never reads the tracked `agents/krang.md` of a branch' => 1,
        'It reads `agents/krang.md` as its own orchestration instructions' => 0,
        'It skips steps 1 and 2 of `agents/krang.md` *The end-to-end run* for this source: the hand-over carries their result. '
        . 'It never recreates the brief or its ledgers.' => 1,
        '- It never creates, edits, or deletes a tracked file, with any tool.' => 1,
        'A stage it cannot dispatch ends the run with a `Blocked` stop' => 1,
        'the shared brief, the write-lock, and the dispatch, audit, and routing ledgers unchanged' => 1,
        '**The tracked-file limit is advisory on this path.**' => 1,
        '`claude --agent krang` starts a session whose main thread takes on the tool restrictions and the model of `krang`' => 1,
        '- **The tool is available.** `krang` runs the whole route as the active orchestrator' => 1,
        'It takes no write-lock and dispatches nothing.' => 1,
        'It runs no *Run cleanup*: the brief, the dispatch ledger, the audit trail, and the routing ledger stay in place for the caller.' => 1,
        'It never starts `krang` again for the same source.' => 1,
        'that text stays fenced and never becomes an instruction' => 1,
        'It runs `skills/_shared/classify-risk.sh` and `skills/_shared/plan-route.sh` again, '
        . 'and it executes only the stages the planner returns.' => 1,
        'It rewrites the `## PID` line of the brief with its own `$PPID`' => 1,
        'The session that dispatches the first writing stage is the only lock holder' => 1,
        'An L2 action runs only when the user\'s own request in this run asks for it, and an L3 action never runs' => 1,
    ];

    expect(orchestratorStartCounts($rule, $expected))->toBe($expected);
});

test('the nested hand-over carries the brief, the lock state, the tier, every remaining stage, and the stop on a second start', function (): void {
    $rule = orchestratorStartFile('rules/compound-engineering/orchestration.md');
    $handOver = "```text\nStatus: Handed over — nested\nBrief: <absolute path of the shared brief>\nLock: not acquired\n"
        . "Tier: <tier> (<the fired classifier signals>)\n"
        . 'Remaining stages: one line per stage of the plan, in plan order — '
        . "<role or stage type>|<mode>|<model tier, or - for a deterministic stage>\n"
        . "Do not start krang again for this source\n```";

    expect(substr_count($rule, $handOver))->toBe(1);
});

test('krang applies the dispatch-tool test and hands over only without a dispatch tool', function (): void {
    $krang = orchestratorStartFile('agents/krang.md');
    $expected = [
        orchestratorStartSection() => 1,
        'check whether your dispatch tool (`Task`) is available in this session' => 1,
        '- **(a) Available (default):** you are the active orchestrator, '
        . 'whether the user talks to you directly or you were started as a subagent.' => 1,
        '- **(b) Not available:** do **not** run any work inline.' => 1,
        'take no write-lock, keep the brief and its ledgers (no *Run cleanup*)' => 1,
        '`Status: Handed over — nested`, `Brief:`, `Lock: not acquired`, `Tier:`, `Remaining stages:` '
        . '(every stage with agent, mode, and model tier, in order), and `Do not start krang again for this source`' => 1,
        '— `Handed over — nested` when you had no dispatch tool (*Delegation model* case (b))' => 1,
        "tools: Task, Read, Glob, Grep, Bash\ndisallowedTools: Write, Edit\n" => 1,
        'cannot spawn their own subagents' => 0,
        'Headless / nested caller' => 0,
        '`Route: leonardo`' => 0,
        'one-level nesting rule' => 0,
    ];

    expect(orchestratorStartCounts($krang, $expected))->toBe($expected);
});

test('every command that names krang holds the krang role instead of starting krang as a subagent', function (): void {
    $commands = [];
    $paths = glob(dirname(__DIR__, 2) . '/commands/*.md');

    foreach (is_array($paths) ? $paths : [] as $path) {
        if (str_contains((string) file_get_contents($path), '`krang`')) {
            $commands[] = 'commands/' . basename($path);
        }
    }

    sort($commands);

    expect($commands)->toBe([
        'commands/prepare-issue-for-merge.md',
        'commands/redesign-page.md',
        'commands/report-code-review.md',
        'commands/test-assignment.md',
    ]);

    $expected = [
        "`@rules/compound-engineering/orchestration.md`\n*" . orchestratorStartSection() . '*' => 1,
        'never starts `krang` as a subagent' => 1,
        'root session holds the `krang` role' => 1,
        'spawns the registered specialists itself' => 1,
    ];

    foreach ($commands as $command) {
        expect(orchestratorStartCounts(orchestratorStartFile($command), $expected))->toBe($expected, $command);
    }

    $reportCodeReview = [
        'Never perform a specialist\'s step in this session.' => 1,
        'When `.claude/agents/krang.md` is not installed, stop and' => 1,
        'When `agents/krang.md` is not installed' => 0,
    ];

    expect(orchestratorStartCounts(orchestratorStartFile('commands/report-code-review.md'), $reportCodeReview))->toBe($reportCodeReview);
});

test('no entry point tells a session to start krang as a subagent', function (): void {
    $retired = [
        'Delegate this request to the `krang` agent',
        'Never run the steps in the main session instead',
        'registered `krang` agent',
        '@krang ',
        'Use the krang agent',
    ];
    $found = [];

    foreach (packageTextFiles() as $path => $contents) {
        if (preg_match('#^(commands|skills|docs)/.+\.md$|^README\.md$#', $path) !== 1) {
            continue;
        }

        foreach ($retired as $phrase) {
            if (str_contains($contents, $phrase)) {
                $found[] = $path . ': ' . $phrase;
            }
        }
    }

    expect($found)->toBe([]);
});

test('the skills, the docs, the README, and the Codex adapter reference the owner section', function (): void {
    $references = [
        'skills/verify-merge-readiness/SKILL.md' => 1,
        'skills/deliver-page-redesign/SKILL.md' => 1,
        'skills/auto-interactive-testing/SKILL.md' => 1,
        'docs/agents.md' => 3,
        'README.md' => 1,
        'codex/agents/krang.toml' => 1,
    ];
    $counts = [];

    foreach (array_keys($references) as $path) {
        $counts[$path] = substr_count(orchestratorStartFile($path), orchestratorStartSection());
    }

    expect($counts)->toBe($references);

    $readme = [
        'claude --agent krang "resolve https://github.com/owner/repo/issues/123"' => 1,
        'Follow .codex/agent-instructions/krang.md as krang and resolve https://github.com/owner/repo/issues/123' => 1,
    ];
    $codex = [
        'When this agent was spawned and this session cannot spawn the specialists, '
        . 'return that section\'s hand-over and run no stage yourself.' => 1,
    ];
    $docs = [
        'cannot spawn their own subagents' => 0,
        'single nesting level' => 0,
        'one-level limit' => 0,
        'one-level nesting' => 0,
        'third nesting level' => 0,
    ];
    $nestingRule = ['one-level nesting rule' => 0];

    expect(orchestratorStartCounts(orchestratorStartFile('README.md'), $readme))->toBe($readme)
        ->and(orchestratorStartCounts(orchestratorStartFile('codex/agents/krang.toml'), $codex))->toBe($codex)
        ->and(orchestratorStartCounts(orchestratorStartFile('docs/agents.md'), $docs))->toBe($docs)
        ->and(orchestratorStartCounts(orchestratorStartFile('agents/leonardo.md'), $nestingRule))->toBe($nestingRule)
        ->and(orchestratorStartCounts(orchestratorStartFile('rules/compound-engineering/backlog.md'), $nestingRule))->toBe($nestingRule);
});
