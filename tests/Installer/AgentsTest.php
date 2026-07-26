<?php

declare(strict_types = 1);

use AgenticVibes\AgentSkills\Installer;
use AgenticVibes\AgentSkills\InstallerPath;

test('resolveAgentsSource returns the package agents directory when it exists', function (): void {
    $packageDir = dirname(__DIR__, 2);

    expect(InstallerPath::resolveAgentsSource())->toBe($packageDir . '/agents');
});

test('resolveAgentsTargetDirectories always returns .claude/agents', function (): void {
    expect(InstallerPath::resolveAgentsTargetDirectories('/project'))
        ->toBe(['/project/.claude/agents']);
});

test('install copies the argos agent to .claude/agents', function (): void {
    $root = installerCreateProjectRoot();
    $homeEnv = getenv('HOME');
    $homeBefore = $homeEnv !== false && $homeEnv !== '' ? $homeEnv : getenv('USERPROFILE');
    putenv('HOME=' . $root);

    if (getenv('USERPROFILE') !== false) {
        putenv('USERPROFILE=' . $root);
    }

    $cwd = getcwd();
    $originalCwd = $cwd !== false ? $cwd : '';

    try {
        chdir($root);
        ob_start();
        Installer::run(['agent-skills', 'install']);
        ob_end_clean();

        expect(is_file($root . '/.claude/agents/argos.md'))->toBeTrue();
        expect(is_file($root . '/.claude/agents/athena.md'))->toBeTrue();
        expect(is_dir($root . '/.cursor/agents'))->toBeFalse();
        expect(is_dir($root . '/.codex/agents'))->toBeFalse();
    } finally {
        installerRestoreEnvAndCleanup($homeBefore, $originalCwd, $root);
    }
});

test('agents directory ships the argos code-review subagent with required frontmatter', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $agentPath = $packageDir . '/agents/argos.md';

    expect(is_file($agentPath))->toBeTrue();

    $content = (string) file_get_contents($agentPath);
    expect($content)->toContain('name: argos');
    expect($content)->toContain('tools: Read, Glob, Grep, Bash');
    expect($content)->toContain('@skills/code-review-github/SKILL.md');
    expect($content)->toContain('@skills/code-review-jira/SKILL.md');
    expect($content)->toContain('@skills/code-review-bugsnag/SKILL.md');
    expect($content)->toContain('@skills/resolve-issue/references/source-detection.md');
    // No resolvable source falls back to the base read-only code-review skill rather than a tracker wrapper.
    expect($content)->toContain('No resolvable source');
    expect($content)->toContain('fall back to the default `@skills/code-review/SKILL.md`');
});

test('agents directory ships the talos code-writing subagent with required frontmatter', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $agentPath = $packageDir . '/agents/talos.md';

    expect(is_file($agentPath))->toBeTrue();

    $content = (string) file_get_contents($agentPath);
    expect($content)->toContain('name: talos');
    expect($content)->toContain('tools: Read, Write, Edit, Glob, Grep, Bash');
    expect($content)->toContain('@skills/resolve-issue/SKILL.md');
    expect($content)->toContain('@skills/resolve-issue/references/source-detection.md');
});

test('the roster ships no general problem-analysis subagent and daidalos routes accordingly', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $daidalos = (string) file_get_contents($packageDir . '/agents/daidalos.md');

    // The analysis subagent and its avatar are gone from the package.
    expect(is_file($packageDir . '/agents/metis.md'))->toBeFalse();
    expect(is_file($packageDir . '/assets/agents/metis.png'))->toBeFalse();

    // Only the security-focused analysis has a specialist (athena); a general analysis request stops.
    expect($daidalos)->toContain('There is no general (non-security) analysis agent in the roster');
    expect($daidalos)->toContain('Blocked: roster nemá agenta pro obecnou analýzu');
    // A subject too broad for one PR is reported back instead of being decomposed by an agent.
    expect($daidalos)->toContain('Too broad for one PR');
});

test('agents directory ships the daidalos orchestrator subagent with required frontmatter', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $agentPath = $packageDir . '/agents/daidalos.md';

    expect(is_file($agentPath))->toBeTrue();

    $content = (string) file_get_contents($agentPath);
    expect($content)->toContain('name: daidalos');
    expect($content)->toContain('tools: Task, Read, Glob, Grep, Bash');
    expect($content)->toContain('@skills/resolve-issue/references/source-detection.md');
    // Shared task brief: daidalos gathers context into a git-ignored ephemeral brief before dispatching.
    expect($content)->toContain('Shared task brief');
    expect($content)->toContain('.claude/run/');
});

test('agents directory ships the athena security-CR subagent with required frontmatter', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $agentPath = $packageDir . '/agents/athena.md';

    expect(is_file($agentPath))->toBeTrue();

    $content = (string) file_get_contents($agentPath);
    expect($content)->toContain('name: athena');
    expect($content)->toContain('tools: Read, Glob, Grep, Bash');
    expect($content)->toContain('model: opus');
    expect($content)->toContain('@skills/security-review/SKILL.md');
    expect($content)->toContain('@skills/laravel-security/SKILL.md');
    expect($content)->toContain('@skills/security-bounty-hunter/SKILL.md');
    expect($content)->toContain('@skills/security-threat-analysis/SKILL.md');
    expect($content)->toContain('@skills/resolve-issue/references/source-detection.md');
    // Read-only stance: never edits, commits, pushes, or merges.
    expect($content)->toContain('read-only');
});

test('athena also runs a pre-implementation security-analysis mode that feeds talos', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/agents/athena.md');

    // Dual-mode contract: security analysis (pre-implementation) plus security review (post-implementation).
    expect($content)->toContain('Security analysis mode (pre-implementation)');
    expect($content)->toContain('Security review mode (post-implementation)');
    // Analysis mode frames the remediation through analyze-problem so talos can implement it.
    expect($content)->toContain('@skills/analyze-problem/SKILL.md');
    // Both handoff statuses exist so the caller can route the result.
    expect($content)->toContain('Security analysis done');
    expect($content)->toContain('Security CR done');
});

test('athena references the laravel security audit workflow for existing-app audits', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/agents/athena.md');

    // The 7-area audit workflow lives in a references file; athena links to it, not re-implements it.
    expect($content)->toContain('@skills/laravel-security/references/audit-workflow.md');
});

test('athena standalone publishing routes to the tracker-matching CR channel, not always GitHub (issue #691)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/agents/athena.md');

    // Standalone mode must route to the tracker-specific publish channel, not always GitHub.
    expect($content)->toContain('skills/code-review-github/scripts/upsert-comment.sh');
    expect($content)->toContain('skills/code-review-jira/scripts/upsert-comment.sh');
    expect($content)->toContain('@skills/code-review-bugsnag/SKILL.md');
    // Must not hardcode GitHub as the only standalone publish channel.
    expect($content)->not->toContain('a GitHub PR URL is available does it publish directly');
    // The tracker-matching routing must be explicit.
    expect($content)->toContain('tracker-matching');
});

test('laravel-security audit-workflow ships with all 7 areas, severity mapping, and regression-test requirement', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/skills/laravel-security/references/audit-workflow.md');

    // Severity mapping: 5-level audit scale maps to 3-level CR scale.
    expect($content)->toContain('Critical');
    expect($content)->toContain('Moderate');
    expect($content)->toContain('Minor');

    // All 7 audit areas must be present.
    expect($content)->toContain('Authorization');
    expect($content)->toContain('Authentication');
    expect($content)->toContain('Validation');
    expect($content)->toContain('XSS');
    expect($content)->toContain('File upload');
    expect($content)->toContain('Secrets');
    expect($content)->toContain('Dependencies');

    // Every confirmed finding must carry a regression-test sketch.
    expect($content)->toContain('regresní test');
    // Defensive framing: audit, not attack.
    expect($content)->toContain('autorizovaném prostředí');
});

test('every dispatched agent reads and appends to the shared task brief', function (): void {
    $packageDir = dirname(__DIR__, 2);

    foreach (['talos', 'argos', 'apollon', 'athena', 'hermes'] as $agent) {
        $content = (string) file_get_contents($packageDir . '/agents/' . $agent . '.md');
        expect($content)->toContain('Shared task brief');
        expect($content)->toContain('.claude/run/');
    }
});

test('every agent definition declares a model in frontmatter', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $globResult = glob($packageDir . '/agents/*.md');
    $agentFiles = $globResult !== false ? $globResult : [];

    expect($agentFiles)->not->toBeEmpty();

    foreach ($agentFiles as $agentFile) {
        $content = (string) file_get_contents($agentFile);
        // Anchor to a frontmatter line starting with `model:` so a stray substring
        // (e.g. the prose "## Delegation model") cannot satisfy the assertion,
        // and restrict the value to the aliases Claude Code accepts so a typo
        // cannot ship and silently fall back at dispatch time.
        expect($content)->toMatch('/^model:\s*(opus|sonnet|haiku|fable)$/m');
    }
});

test('every agent definition sets the model effort to max in frontmatter, except apollon which runs at the lowest effort (issue #40)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $globResult = glob($packageDir . '/agents/*.md');
    $agentFiles = $globResult !== false ? $globResult : [];

    expect($agentFiles)->not->toBeEmpty();

    foreach ($agentFiles as $agentFile) {
        // Anchor to a frontmatter line starting with `effort:` so a stray prose substring
        // cannot satisfy the assertion.
        $content = (string) file_get_contents($agentFile);

        if (basename($agentFile) === 'apollon.md') {
            // apollon runs fast, cheap validation on sonnet at the lowest effort.
            expect($content)->toMatch('/^effort:\s*low$/m');
        } else {
            // Every other agent runs at maximum reasoning depth (issue #40).
            expect($content)->toMatch('/^effort:\s*max$/m');
        }
    }
});

test('daidalos delegates the end-to-end run by dispatching talos and argos to convergence', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/agents/daidalos.md');

    // True delegation: each step is dispatched as the matching specialist agent through the Task tool.
    expect($content)->toContain('Dispatch `talos` through the Task tool');
    expect($content)->toContain('Dispatch `argos` through the Task tool');
    // The implementation step still routes through resolve-issue (owned by talos), and the convergence gate is named.
    expect($content)->toContain('@skills/resolve-issue');
    expect($content)->toContain('0 Critical');
});

test('daidalos dispatches athena for a pre-implementation security-risk analysis that feeds talos', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/agents/daidalos.md');

    // Security-focused tasks are analysed by athena before talos implements them.
    expect($content)->toContain('dispatch `athena` through the Task tool');
    expect($content)->toContain('security analysis mode');
    expect($content)->toContain('Security analysis done');
});

test(
    'daidalos gates the pre-convergence apollon validation on high-risk changes and keeps the post-convergence pass mandatory (issue #62)',
    function (): void {
        $packageDir = dirname(__DIR__, 2);
        $daidalos = (string) file_get_contents($packageDir . '/agents/daidalos.md');
    
        // The pre-convergence scoped validation runs only for a high-risk change; low-risk runs skip it.
        expect($daidalos)->toContain('Only for a high-risk change dispatch `apollon` through the Task tool');
        expect($daidalos)->toContain('the post-convergence `apollon` pass in step 6 stays mandatory for every run');
    
        // apollon documents the same conditionality in its scoped-mode contract.
        $apollon = (string) file_get_contents($packageDir . '/agents/apollon.md');
        expect($apollon)->toContain('only when `daidalos` classified the change as high-risk');
    },
);

test('daidalos processes multiple resolved sources sequentially and never fans them out in parallel', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/agents/daidalos.md');

    // The concurrency section processes a single request's multiple sources strictly one at a time.
    expect($content)->toContain('Sequential processing of multiple sources');
    expect($content)->toContain('one at a time, strictly sequentially — never in parallel');
    // The analysis-only branch dispatches athena sequentially, not as a parallel fan-out.
    expect($content)->toContain('dispatch their `athena` runs one after another — strictly sequentially, never in parallel');
    // No fan-out across sources in one message.
    expect($content)->toContain('Do **not** fan work out across sources');
    // Each source still gets its own per-source brief.
    expect($content)->toContain('own** shared brief');
    // Step 3 classifies each resolved source independently when several were resolved.
    expect($content)->toContain('classify **each one independently**');
});

test('daidalos keeps the writing path on the shared tree but lets read-only CR agents isolate in a worktree, and cleans them up', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/agents/daidalos.md');

    // The writing path (talos) still never uses worktrees — concurrent writers serialise on the shared tree.
    expect($content)->toContain('The writing path never uses git worktrees');
    expect($content)->toContain('single shared git working tree');
    expect($content)->toContain('there is no isolated-worktree escape for the writing path');
    // Read-only CR agents may isolate in a worktree for parallel review.
    expect($content)->toContain('read-only code-review agents (`argos`, `athena`) may use a git worktree');
    // Daidalos owns worktree cleanup so the repo stays clean after the run / merge.
    expect($content)->toContain('git worktree remove');
    expect($content)->toContain('git worktree prune');
});

test('the read-only CR agents document an optional parallel-review worktree they hand back for daidalos cleanup', function (): void {
    $packageDir = dirname(__DIR__, 2);

    foreach (['argos', 'athena'] as $agent) {
        $content = (string) file_get_contents($packageDir . '/agents/' . $agent . '.md');
        // The CR agent may isolate its review in a read-only worktree when needed.
        expect($content)->toContain('Parallel review worktree');
        expect($content)->toContain('git worktree add');
        // It hands the path back so daidalos removes it during cleanup.
        expect($content)->toContain('Record the worktree path in your handoff');
        // Standalone runs clean up after themselves.
        expect($content)->toContain('git worktree remove');
    }
});

test('agents directory ships the apollon test-engineer subagent with required frontmatter', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $agentPath = $packageDir . '/agents/apollon.md';

    expect(is_file($agentPath))->toBeTrue();

    $content = (string) file_get_contents($agentPath);
    expect($content)->toContain('name: apollon');
    // Write-capable test engineer: authors PHPUnit/Pest tests, so the tools line grants Write and Edit.
    expect($content)->toContain('tools: Read, Write, Edit, Glob, Grep, Bash');
    expect($content)->toContain('model: sonnet');
    expect($content)->toContain('@skills/create-test/SKILL.md');
    expect($content)->toContain('@skills/e2e-testing/SKILL.md');
    expect($content)->toContain('@skills/resolve-issue/references/source-detection.md');
});

test('agents directory ships the hermes release-announcer subagent with required frontmatter', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $agentPath = $packageDir . '/agents/hermes.md';

    expect(is_file($agentPath))->toBeTrue();

    $content = (string) file_get_contents($agentPath);
    expect($content)->toContain('name: hermes');
    expect($content)->toContain('tools: Read, Glob, Grep, Bash');
    expect($content)->toContain('model: haiku');
    expect($content)->toContain('no-hollow-AI-phrasing contract');
    expect($content)->toContain('@skills/resolve-issue/references/source-detection.md');
    // Read-only stance: never edits, commits, pushes, or merges.
    expect($content)->toContain('read-only');
    // Publishes only via the canonical wrapper, never raw gh commands.
    expect($content)->toContain('upsert-comment');
});

test('parallel agents share their split output through the brief under an append lock with a barrier before consolidation', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $daidalos = (string) file_get_contents($packageDir . '/agents/daidalos.md');

    // The brief is the rendezvous where parallel agents' split output becomes available to peers.
    expect($daidalos)->toContain('Parallel handoff sharing');
    // Concurrency-safe append: a per-brief append lock guards every `cat >>` so parallel writes never interleave.
    expect($daidalos)->toContain('Concurrency-safe append');
    expect($daidalos)->toContain('$BRIEF.lock');
    // Barrier: a peer's parallel output is only consolidated after every parallel handoff has landed in the brief.
    expect($daidalos)->toContain('Barrier before consolidation');

    // The two parallel CR agents reference the append lock so their handoffs never clobber each other.
    foreach (['argos', 'athena'] as $agent) {
        $content = (string) file_get_contents($packageDir . '/agents/' . $agent . '.md');
        expect($content)->toContain('$BRIEF.lock');
    }
});

test('every agent keeps commit messages and PR titles in English regardless of the assignment language', function (): void {
    $packageDir = dirname(__DIR__, 2);

    foreach (['daidalos', 'talos', 'argos', 'athena', 'apollon', 'hermes'] as $agent) {
        $content = (string) file_get_contents($packageDir . '/agents/' . $agent . '.md');
        expect($content)->toContain('commit messages and PR titles are always English');
    }
});

test('daidalos decides the opt-in savings mode once during gather and never narrates an undispatched plan (issue #119)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $daidalos = (string) file_get_contents($packageDir . '/agents/daidalos.md');

    // Decided once, during gather, only on an explicit user request.
    expect($daidalos)->toContain('**Savings mode (opt-in).** Decide once, here');
    expect($daidalos)->toContain('## Savings mode: on` or `## Savings mode: off`');

    // The dedicated section explains what the dispatcher does differently.
    expect($daidalos)->toContain('## Savings mode (opt-in)');
    expect($daidalos)->toContain('## Orchestration mode: thin');
    expect($daidalos)->toContain('The dispatch sequence is unchanged.');

    // Brief layout carries the new fields alongside the pre-existing ones.
    expect($daidalos)->toContain('## Context pack');
    expect($daidalos)->toContain('## Build gate cache');

    // The cache is written by talos / apollon only — argos/athena stay read-only and never run a
    // full build, so they never write this section (issue #119 CR fix for the cross-file contradiction
    // with argos.md's "the only write you perform" clause).
    expect($daidalos)->toContain('`argos` and `athena` never write this section');
});

test(
    'argos and athena read the shared context pack and defer an isolated-worktree coverage verdict to apollon when savings mode is on (issue #119)',
    function (): void {
        $packageDir = dirname(__DIR__, 2);
    
        foreach (['argos', 'athena'] as $agent) {
            $content = (string) file_get_contents($packageDir . '/agents/' . $agent . '.md');
            expect($content)->toContain('@rules/compound-engineering/general.mdc` *Savings mode*');
            expect($content)->toContain('read the brief\'s `## Context pack`');
            expect($content)->toContain('do not assert an *executed* coverage-gate verdict from a static read of the diff');
            expect($content)->toContain('otherwise report the coverage gate as deferred to `apollon`');
            // The CI-reuse escape hatch requires the actually-checked-out SHA, not just "the exact head
            // SHA" (a pull_request-triggered run may check out a merge ref instead) (issue #119 CR fix).
            expect($content)->toContain('a `pull_request`-triggered run may check out a merge ref instead of the head SHA — verify, never assume');
            // Coverage ownership is a first-class handoff field, not folded into a status string
            // (issue #119 CR fix — agent-new-mode-status-result-parity).
            expect($content)->toContain('**Coverage:** `executed`');
        }

        // Each reviewer's own exclusive lens is preserved — only the shared middle ground is split.
        $argos = (string) file_get_contents($packageDir . '/agents/argos.md');
        expect($argos)->toContain('your own quality / architecture / optimisation lens is never split');

        $athena = (string) file_get_contents($packageDir . '/agents/athena.md');
        expect($athena)->toContain('security-exclusive findings are never split');
    },
);

test('apollon owns the executed coverage verdict and reuses the cached build gate when savings mode is on (issue #119)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $apollon = (string) file_get_contents($packageDir . '/agents/apollon.md');

    expect($apollon)->toContain('**Savings-mode build-gate cache (opt-in).**');
    expect($apollon)->toContain('check the brief\'s `## Build gate cache`');
    expect($apollon)->toContain('**Own the coverage verdict when savings mode is on.**');
    expect($apollon)->toContain('you are the sole authoritative source for the executed coverage number in this run');

    // Coverage is a dedicated handoff field, and the scoped status definition states whether the
    // coverage gate is included (issue #119 CR fix — agent-new-mode-status-result-parity).
    expect($apollon)->toContain('- **Coverage:** the executed changed-lines coverage result and the command that produced it');
    expect($apollon)->toContain('coverage gate either executed here or explicitly taken over from a CR pass that deferred it');
});
