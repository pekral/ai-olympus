<?php

declare(strict_types = 1);

use Pekral\AiOlympus\AgentGuardOutcome;
use Symfony\Component\Process\Process;

function agentGuardPackageFile(string $relativePath): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
}

test('every confined agent runs the probe first and reports its protection in the handoff', function (string $agent): void {
    $definition = agentGuardPackageFile('agents/' . $agent . '.md');
    $section = installerDocsSection($definition, '## Runtime protection');

    expect($section)->toContain('Run `skills/_shared/agent-guard.sh probe claude` before any other command')
        ->and($section)->toContain('`**Protection:** inactive (<reason>)`')
        ->and($section)->toContain('keep temporary files in `$TMPDIR`')
        ->and(strpos($definition, '## Runtime protection'))->toBeLessThan((int) strpos($definition, '## Bash boundary'));
})->with(['leonardo', 'splinter', 'april', 'casey', 'shredder', 'krang']);

test('the dispatching session prepares the review worktree and a confined reviewer only reads in it', function (string $file, string $needle): void {
    expect(agentGuardPackageFile($file))->toContain($needle);
})->with([
    'a confined krang' => ['agents/krang.md', 'The top-level session prepares the review worktrees and fetches their head commits.'],
    'krang' => [
        'agents/krang.md',
        'Before the `leonardo` and `splinter` dispatches, record their runtime protection verdicts and prepare their review worktrees',
    ],
    'leonardo' => [
        'agents/leonardo.md',
        '**The dispatching session prepares the worktree, never you, when the dispatch names a prepared worktree.**',
    ],
    'splinter' => ['agents/splinter.md', 'When the dispatch names a prepared worktree, the dispatching session maintains it and you only read in it.'],
    'the batch' => [
        'skills/_shared/orchestration/batch-preparation.md',
        'The root session prepares them before each round and removes them in *Run cleanup*.',
    ],
    'the checkout gate' => [
        'skills/code-review/references/branch-checkout-gate.md',
        '**When the dispatch names a prepared review worktree, the reviewer runs steps 1 and 5 only.**',
    ],
    'the cleanup' => ['skills/_shared/orchestration/stale-run-recovery.md', '**A worktree this session prepared is its own.**'],
]);

test('every confined Codex adapter runs the probe and reports its protection', function (string $agent): void {
    $adapter = agentGuardPackageFile('codex/agents/' . $agent . '.toml');

    expect($adapter)->toContain('runs `.agents/skills/_shared/agent-guard.sh probe codex` and reports `**Protection:**`')
        ->and($adapter)->toContain('The mixed Codex session has no verified all-tool protection.');
})->with(['leonardo', 'splinter', 'april', 'casey', 'shredder', 'krang']);

test('every confined agent stops when strict mode meets an inactive protection', function (string $file): void {
    expect(agentGuardPackageFile($file))->toContain('Blocked: runtime protection inactive — <reason>');
})->with([
    'april' => ['agents/april.md'],
    'casey' => ['agents/casey.md'],
    'krang' => ['agents/krang.md'],
    'krang on Codex' => ['codex/agents/krang.toml'],
    'leonardo' => ['agents/leonardo.md'],
    'leonardo on Codex' => ['codex/agents/leonardo.toml'],
    'shredder' => ['agents/shredder.md'],
    'splinter' => ['agents/splinter.md'],
]);

test('no writing role carries the runtime protection step', function (string $agent): void {
    expect(agentGuardPackageFile('agents/' . $agent . '.md'))->not->toContain('## Runtime protection');
})->with(['donatello', 'michelangelo', 'raphael']);

test('the boundary rule says an agent stopped by the guard reports Blocked and never retries another way', function (): void {
    $boundary = installerDocsSection(agentGuardPackageFile('rules/compound-engineering/orchestration.md'), '## Bash capability boundary');

    expect($boundary)->toContain('**An agent stopped by the guard reports `Blocked` and never retries the action through another tool or interpreter.**');
});

test('the boundary rule names the runtime agent guard and no longer says the package ships no runtime component', function (): void {
    $boundary = installerDocsSection(agentGuardPackageFile('rules/compound-engineering/orchestration.md'), '## Bash capability boundary');

    expect($boundary)->toContain('**The runtime agent guard — the enforced layer for the read-only roles (issue #695).**')
        ->and($boundary)->not->toContain('this instructions-only package deliberately does not ship')
        ->and($boundary)->not->toContain('this package ships instructions, never runtime code');
});

test('krang renders one runtime protection line per dispatched confined role', function (): void {
    $data = json_encode([
        'status' => 'completed',
        'summary' => 'Reviewed and merged.',
        'runtime_protection' => [
            ['role' => 'leonardo', 'protection' => 'active'],
            ['role' => 'splinter', 'platform' => 'codex', 'protection' => 'Blocked (session isolation unverified)'],
            ['role' => 'april', 'platform' => 'claude', 'protection' => 'inactive (tool isolation unverified)'],
        ],
    ], JSON_THROW_ON_ERROR);
    $process = new Process(['bash', dirname(__DIR__, 2) . '/skills/_shared/render-report.sh', '--data', '-']);
    $process->setInput($data);
    $process->run();

    $lines = array_values(
        array_filter(explode("\n", $process->getOutput()), static fn (string $line): bool => str_starts_with($line, '- Runtime protection: ')),
    );

    expect($lines)->toBe([
        '- Runtime protection: leonardo — active',
        '- Runtime protection: splinter (Codex) — Blocked (session isolation unverified)',
        '- Runtime protection: april (Claude Code) — inactive (tool isolation unverified)',
    ])->and(agentGuardPackageFile('agents/krang.md'))->toContain('so the completion report carries one `Runtime protection:` line per role');
});

test('SECURITY.md carries one section per platform and every residual of the guard', function (): void {
    $guard = installerDocsSection(agentGuardPackageFile('SECURITY.md'), '## Runtime agent guard');

    expect($guard)->toContain('### Claude Code — what is enforced')
        ->and($guard)->toContain('### Codex — a mixed session')
        ->and($guard)->toContain('### Strict mode')
        ->and($guard)->toContain('### What stays outside the guard')
        ->and($guard)->toContain('A mixed Codex dispatch reports `inactive`')
        ->and($guard)->toContain('Run `vendor/bin/ai-olympus install --no-agent-guard`.')
        ->and($guard)->toContain('**The hook never allows a command only because no deny rule matched it.**')
        ->and($guard)->toContain('**The cost:** a confined role asks the user for every command that no allow rule of the settings files covers')
        ->and($guard)->toContain('**The git object store is not writable.**')
        ->and($guard)->toContain('**Every worktree is read-only.**')
        ->and($guard)->toContain(
            'also a read-only command that Claude Code itself would run without a prompt, and in `dontAsk` mode such a command is refused',
        )
        ->and($guard)->not->toContain('as the same command would without the guard')
        ->and($guard)->toContain('No all-tool host integration is verified by those fixtures.')
        ->and($guard)->not->toContain('hooks are read at session start');

    $residuals = [
        '1. **Codex**',
        '2. **The main session without `--agent`**',
        '3. **A hook that does not run:**',
        '4. **A host without Bash confinement:**',
        '5. **A nested sandbox:**',
        '6. **The network.**',
        '7. **Inter-process channels',
        '8. **Linux only:**',
        '9. **A writing role or the main session can change the guard.**',
        '10. **A changed `composer.lock`**',
        '11. **macOS only: the here-document file of the system bash.**',
        '12. **`.claude/run/**` stays writable**',
        '13. **A confined `krang` does not finish the local steps after a merge.**',
    ];

    foreach ($residuals as $residual) {
        expect($guard)->toContain($residual);
    }
});

test('the manifest table documents the strict-mode key and that only the default branch sets it', function (): void {
    $manifest = installerDocsSection(agentGuardPackageFile('rules/general/general.md'), '## Project manifest');

    expect($manifest)->toContain('| `security.strict-agent-guard` | bool |')
        ->and($manifest)->toContain('The guard reads it from the default branch only, through `skills/_shared/read-manifest.sh`.');
});

test(
    'the boundary rule, SECURITY.md, and the merge skill hand the local post-merge steps to an unconfined session',
    function (string $file, string $needle): void {
        expect(agentGuardPackageFile($file))->toContain($needle);
    },
)->with([
    'SECURITY.md' => ['SECURITY.md', 'The top-level session without `--agent`, which the guard does not confine, runs those steps and the branch cleanup'],
    'the boundary rule' => [
        'rules/compound-engineering/orchestration.md',
        '**The local steps after a merge belong to an unconfined session.**',
    ],
    'the merge skill' => [
        'skills/merge-github-pr/SKILL.md',
        'A confined `krang` hands the local steps to the session without `--agent`',
    ],
]);

test('the install summary of the guard makes no claim about when a session reads its hooks', function (): void {
    expect(AgentGuardOutcome::Registered->installMessage())->not->toContain('session start');
});

test(
    'a review in a worktree keeps its audit run directory outside the worktree and the cleanup ignores nested repositories',
    function (string $file, string $needle): void {
        expect(agentGuardPackageFile($file))->toContain($needle);
    },
)->with([
    'leonardo' => ['agents/leonardo.md', '**The audit run directory stays outside the worktree.**'],
    'SECURITY.md' => [
        'SECURITY.md',
        'keeps its security-audit run directory under `.claude/run/security-audit/<slug>-<role>/` of the project, outside the worktree',
    ],
    'splinter' => ['agents/splinter.md', '`.claude/run/security-audit/<slug>-splinter/<run-id>/` of the checkout that holds the shared brief'],
    'the clean check' => ['agents/krang.md', 'skills/_shared/branch-worktree.sh remove'],
    'the head check' => ['agents/leonardo.md', 'It checks the head with `skills/_shared/agent-guard.sh review-head <head-sha>`.'],
    'the skill' => ['skills/security-audit/SKILL.md', 'every `scripts/audit-artifacts.sh` call names the worktree with `--tree <path>`'],
]);

test('no confined role keeps a write exception inside a review worktree', function (): void {
    expect(agentGuardPackageFile('skills/_shared/agent-guard.sh'))->not->toContain('AUDIT_RE')
        ->and(agentGuardPackageFile('SECURITY.md'))->not->toContain('of their own review worktree')
        ->and(agentGuardPackageFile('agents/leonardo.md'))->not->toContain('the one directory below the worktree that you may write');
});

test('SECURITY.md states that the macOS confinement creates no symbolic link anywhere', function (): void {
    $guard = installerDocsSection(agentGuardPackageFile('SECURITY.md'), '## Runtime agent guard');

    expect($guard)->toContain('On macOS a confined command creates no symbolic link anywhere')
        ->and($guard)->toContain('8. **Linux only:** `bwrap` binds by directory, never by name.')
        ->and(agentGuardPackageFile('skills/_shared/agent-guard.sh'))->toContain('profile+=\'(deny file-write-create (vnode-type SYMLINK))\'');
});

test('SECURITY.md names the settings sources the hook reads and the ones it does not', function (): void {
    $guard = installerDocsSection(agentGuardPackageFile('SECURITY.md'), '## Runtime agent guard');

    expect($guard)->toContain('the user `settings.json` in `$CLAUDE_CONFIG_DIR` when the variable is set and in `~/.claude` otherwise')
        ->and($guard)->toContain('A settings file the hook cannot read, and a user file it cannot locate, make it ask.')
        ->and($guard)->toContain('and no managed setting outside the two `managed-settings.json` files above');
});

test('the dispatching session refreshes the default-branch ref with a prepared review worktree', function (string $file, string $needle): void {
    expect(agentGuardPackageFile($file))->toContain($needle);
})->with([
    'krang' => ['agents/krang.md', 'The top-level session prepares the review worktrees and fetches their head commits.'],
    'leonardo' => ['agents/leonardo.md', 'It runs `git fetch origin` in every round, which refreshes `origin/<default branch>` for the review.'],
    'the checkout gate' => [
        'skills/code-review/references/branch-checkout-gate.md',
        'The dispatching session has already run `git fetch origin` in this round, which refreshes `origin/$DEFAULT_BRANCH`',
    ],
]);

test('SECURITY.md distinguishes the native Codex shell policy from whole-role isolation', function (): void {
    expect(installerDocsSection(agentGuardPackageFile('SECURITY.md'), '## Runtime agent guard'))
        ->toContain('The native `--sandbox read-only` option governs shell execution.');
});
