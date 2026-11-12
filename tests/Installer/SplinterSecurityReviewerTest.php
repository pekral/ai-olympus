<?php

declare(strict_types = 1);

// Issue #379 — `splinter` is a separate, independent, read-only, security-only reviewer that runs in
// every code review round beside `leonardo`. The tests below pin the agent, its independence, the way
// `krang` and `leonardo` wire it into the round and the convergence gate, its consent rows, and the
// reuse of the retired orchestrator name.

test('splinter ships on both platforms as a read-only security reviewer with its own tier (issue #379)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $claude = (string) file_get_contents($packageDir . '/agents/splinter.md');
    $codex = (string) file_get_contents($packageDir . '/codex/agents/splinter.toml');

    expect($claude)->toMatch('/^name: splinter$/m')
        ->and($claude)->toMatch('/^tools: Read, Glob, Grep, Bash, WebSearch, WebFetch$/m')
        ->and($claude)->toMatch('/^disallowedTools: Write, Edit$/m')
        ->and($claude)->toMatch('/^model: opus$/m')
        ->and($claude)->toMatch('/^effort: high$/m')
        ->and($claude)->toContain('Read-only — never edits, commits, pushes, or merges.')
        ->and($claude)->toContain('## Model tier')
        ->and($codex)->toContain('name = "splinter"')
        ->and($codex)->toContain('model = "gpt-6.1-sol"')
        ->and($codex)->toContain('model_reasoning_effort = "high"')
        ->and($codex)->toContain('First read `.codex/agent-instructions/splinter.md` in full')
        ->and($codex)->toContain('Escalated tier: the strongest model this session can select');
});

test('splinter reviews for security only, runs every security lens, and anchors each finding to a changed line (issue #379)', function (): void {
    $splinter = (string) file_get_contents(dirname(__DIR__, 2) . '/agents/splinter.md');

    foreach ([
        '@skills/security-review/SKILL.md',
        '@skills/security-audit/SKILL.md` in its *Diff mode*',
        '@skills/security-bounty-hunter/SKILL.md',
        '@skills/security-threat-analysis/SKILL.md',
        '@skills/laravel-security/SKILL.md',
        '@skills/laravel-authorization-review/SKILL.md',
        '@skills/git-history-exposure-audit/SKILL.md` in its *Review mode*',
        'Apply every rule under `rules/security/`.',
        '**Security only.** Report no quality, architecture, optimisation, API, or coverage point.',
        '**A finding anchors to a changed line.**',
    ] as $pin) {
        expect($splinter)->toContain($pin);
    }
});

test('splinter never reads the findings of leonardo for the round and never waits for them (issue #379)', function (): void {
    $splinter = (string) file_get_contents(dirname(__DIR__, 2) . '/agents/splinter.md');
    $krang = krangContractText();

    expect($splinter)->toContain('**Never read the findings of `leonardo` for the round.**')
        ->and($splinter)->toContain('Do not read its `### leonardo — CR round <n>` section of the shared brief')
        ->and($splinter)->toContain('**Never wait for `leonardo`.**')
        ->and($splinter)->toContain('A disagreement with `leonardo` is not yours to resolve.')
        ->and($krang)->toContain('Its dispatch prompt never carries a `leonardo` section or finding.')
        ->and($krang)->toContain('Never pass a `leonardo` finding to `splinter`.');
});

test('splinter publishes nothing and appends only its report to the shared brief (issue #379)', function (): void {
    $splinter = (string) file_get_contents(dirname(__DIR__, 2) . '/agents/splinter.md');

    expect($splinter)->toContain('2. Publish nothing. No comment, no label, no status, and no pull-request update.')
        ->and($splinter)->toContain('Append `### splinter — Security view <head SHA>` to the shared brief under the append lock')
        ->and($splinter)->toContain('You perform no external write: no tracker comment, no label, no status, no push, no PR update.')
        ->and($splinter)->toContain('it is advisory here, not enforced')
        ->and($splinter)->toContain('*Bash capability boundary*')
        ->and($splinter)->toContain('**A security finding is never deferred.**');
});

test('krang dispatches splinter in every parallel CR round and converges only when both views are clean (issue #379)', function (): void {
    $krang = krangContractText();

    expect($krang)->toContain('## Parallel CR round — `leonardo`, `splinter`, `raphael`, and `casey` together')
        ->and($krang)->toContain('1. **Dispatch the four agents at once.**')
        ->and($krang)->toContain('`agents/splinter.md` *Parallel CR round*')
        ->and($krang)->toContain('The round converges only when the `leonardo` view and the `splinter` view are both clean under the convergence gate')
        ->and($krang)->toContain('a security finding is never deferred')
        ->and($krang)->toContain('A `splinter` that is `Blocked`, unregistered, or without `Clean` leaves the round not converged')
        ->and($krang)->toContain('it holds only when the handoffs of `leonardo` and `splinter` are both clean')
        ->and($krang)->toContain('`leonardo` and `splinter` review in isolated read-only git worktrees');
});

test('leonardo merges the splinter view into its one comment and the security agenda names the new owner (issue #379)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $leonardo = (string) file_get_contents($packageDir . '/agents/leonardo.md');
    $divergence = (string) file_get_contents($packageDir . '/rules/code-review/review-process.md');

    expect($leonardo)->not->toContain('there is no separate security agent')
        ->and($leonardo)->toContain('`splinter` runs the independent second security view in parallel (`agents/splinter.md`)')
        ->and($leonardo)->toContain('3. **Merge the `splinter` view into your review body.**')
        ->and($leonardo)->toContain('a disagreement on a security point resolves toward the stricter verdict')
        ->and($leonardo)->toContain('`Raised by` field (`splinter`, `leonardo`, or `both`)')
        ->and($leonardo)->toContain('`Independent security view not run — dispatch splinter`')
        ->and($divergence)->toContain('A security finding of the `splinter` view carries the `Raised by` field of the finding template');

    foreach (['skills/code-review/templates/review-output.md', 'skills/code-review-github/templates/pr-comment-output.md'] as $template) {
        expect((string) file_get_contents($packageDir . '/' . $template))->toContain('- **Raised by:** `splinter` | `leonardo` | `both`');
    }
});

test('the convergence gate counts the splinter findings and never defers a security finding (issue #379)', function (): void {
    $packageDir = dirname(__DIR__, 2);

    expect((string) file_get_contents($packageDir . '/skills/process-code-review/SKILL.md'))
        ->toContain('the Critical and Moderate findings of the independent `splinter` security view count in `criticalCount` and `moderateCount`')
        ->and((string) file_get_contents($packageDir . '/rules/code-review/general.md'))
        ->toContain('**A second, independent view runs in every round.**')
        ->toContain('a security finding of either view is never deferred');
});

test('splinter has consent and Bash boundary rows that allow no publish (issue #379)', function (): void {
    $rule = (string) file_get_contents(dirname(__DIR__, 2) . '/rules/compound-engineering/orchestration.md');

    expect($rule)->toContain('| `splinter` | Publish any comment, label, status, or pull-request update | forbidden — no level |')
        ->and($rule)->toContain('| `splinter` | Read the pull-request head, its diff, and its tracker payload for the independent security view')
        ->and($rule)->toContain('| `splinter` | Outbound `WebFetch` / `WebSearch` to public vendor documentation and advisories for the security lenses | L1 |')
        ->and($rule)->toContain('`.claude/worktrees/agent-cr-*-splinter`');
});

test('the name splinter is reused with its history documented and a portrait, a prompt, and a manifest entry (issue #379)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $docs = (string) file_get_contents($packageDir . '/docs/agents.md');
    /** @var array{agents: list<array{id: string, portrait: string, thumbnail: string}>, } $manifest */
    $manifest = json_decode((string) file_get_contents($packageDir . '/assets/agents/manifest.json'), associative: true, flags: JSON_THROW_ON_ERROR);
    /** @var array{prompts: array<string, string>} $prompts */
    $prompts = json_decode((string) file_get_contents($packageDir . '/assets/agents/prompts.json'), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($docs)->toContain('**The identifier `splinter` is reused.**')
        ->and($docs)->toContain('From this release `splinter` is the independent security agent')
        ->and($docs)->toContain('dated before 2026-11-09 in a pull request, brief, or memory entry still means `krang`')
        ->and($docs)->toContain('The installer never removes the new `splinter` definition')
        ->and($docs)->toContain('### <a href="../assets/agents/splinter.png">')
        ->and(array_column($manifest['agents'], 'id'))->toContain('splinter')
        ->and($prompts['prompts'])->toHaveKey('splinter')
        ->and(is_file($packageDir . '/assets/agents/splinter.png'))->toBeTrue()
        ->and(is_file($packageDir . '/assets/agents/thumbnails/splinter.png'))->toBeTrue();
});
