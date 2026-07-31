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

test('daidalos repairs an unmet code-review merge gate instead of escalating it', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $daidalos = (string) file_get_contents($packageDir . '/agents/daidalos.md');

    // A merge blocked solely by a missing/stale review is a prerequisite daidalos owns, not a hard stop.
    expect($daidalos)->toContain('An unmet code-review gate reported by the merge step is repairable — repair it, do not stop.');
    expect($daidalos)->toContain('dispatch the step-6 review-and-fix loop on the current diff');
    expect($daidalos)->toContain('re-enter the merge');

    // The repaired gate is re-checked against the post-fix head, since the fixes themselves move it.
    expect($daidalos)->toContain('Re-run the gate against the new head commit, never against the pre-fix one');

    // The repair never becomes a licence to merge unreviewed, and a non-converging loop still escalates.
    expect($daidalos)->toContain('never a reason to merge without one');
    expect($daidalos)->toContain('Escalate to the user only when the repair loop itself cannot converge');
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

test('daidalos sweeps stale briefs and worktrees at startup before writing its own brief (issue #148)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $daidalos = (string) file_get_contents($packageDir . '/agents/daidalos.md');

    expect($daidalos)->toContain('**Startup sweep, then gather context & write the shared brief');
    expect($daidalos)->toContain('other than the file this run is about to write (this run\'s own `<source-slug>.md`)');
    expect($daidalos)->toContain('probe it with `LC_ALL=C kill -0 "$pid" 2>&1`');
    expect($daidalos)->toContain('a live peer run — leave it untouched');
    expect($daidalos)->toContain('Run `git worktree prune` first');
    expect($daidalos)->toContain('a live PID means a peer\'s CR pass is actively using that worktree, **never remove it**');
    expect($daidalos)->toContain('## PID');
});

/**
 * ESRCH ("no such process") is the only confirmed-dead outcome; EPERM ("process exists, no
 * permission") means alive under another UID/namespace. Shared by both the brief-half and the
 * worktree-half sweep mirrors below (PR #150 CR fix). The predicate matches the exact phrase
 * `agents/daidalos.md`'s prose pins (`LC_ALL=C kill -0 "$pid" 2>&1` looking for "not permitted") —
 * previously the prose and this mirror matched on two different substrings (PR #150 CR fix,
 * run-2 Minor 1).
 */
function daidalosPidConfirmedDead(int $pid): bool
{
    if (posix_kill($pid, 0)) {
        return false;
    }

    return stripos(posix_strerror(posix_get_last_error()), 'not permitted') === false;
}

/**
 * A brief is judged confirmed-dead only from the `## PID` field found by FIXED POSITION — the
 * file's second line, immediately below the title — never by scanning for a line that merely
 * looks like `## PID <n>` anywhere else in the file. A fence-based "read only the lines before the
 * first fenced block" boundary is not a trust boundary (measured against the briefs actually on
 * disk, one carries no fenced block at all, making its "header region" the whole file) — position
 * is. The anchor requires digits immediately followed by a space/tab: never `\s+` (crosses a
 * newline onto a value written on the next line) and never `\b` (admits the leading digits of a
 * timestamp written before the PID, e.g. capturing `2026` out of a timestamp-first line) (PR #150
 * CR fix, run-2 Critical 1).
 */
function daidalosBriefConfirmedDead(string $content): bool
{
    $secondLine = explode("\n", $content, 3)[1] ?? '';

    if (preg_match('/^## PID[ \t]+(\d{1,7})[ \t]/', $secondLine, $matches) !== 1) {
        return false;
    }

    return daidalosPidConfirmedDead((int) $matches[1]);
}

/**
 * Mirrors the decision in agents/daidalos.md step 2 *Startup sweep*: fail-safe by construction.
 * A brief file is safe to delete only on POSITIVE PROOF of death (`daidalosBriefConfirmedDead()`
 * above). Everything else — no PID line, a malformed token, a PID found only inside a fenced
 * tracker-payload quote, a live PID, or an EPERM probe — is preserved, never deleted (PR #150 CR
 * fix for issue #148: the original algorithm treated "no PID" as deletable, a fail-unsafe
 * default this mirror must never reproduce again).
 *
 * @return array<int, string> basenames of brief files judged safe to delete
 */
function daidalosStartupSweepDeletableBriefs(string $runDir, string $ownBriefBasename): array
{
    $deletable = [];
    $paths = glob($runDir . '/*.md');
    $paths = $paths !== false ? $paths : [];

    foreach ($paths as $path) {
        $basename = basename($path);

        if ($basename !== $ownBriefBasename && daidalosBriefConfirmedDead((string) file_get_contents($path))) {
            $deletable[] = $basename;
        }
    }

    return $deletable;
}

test(
    'daidalos startup-sweep algorithm is fail-safe: only a confirmed-dead, fixed-position, format-valid PID is deletable (PR #150 CR fix)',
    function (): void {
        $root = installerCreateProjectRoot();
        $runDir = $root . '/.claude/run';
        $livePid = getmypid();
        expect($livePid)->not->toBeFalse();

        installerWriteFile(
            $runDir . '/gh-dead.md',
            "# Task brief — gh-dead\n## PID 9999999 2026-01-01T00:00:00Z\n## Source            https://example.com/issues/1\n",
        );
        installerWriteFile(
            $runDir . '/gh-live.md',
            "# Task brief — gh-live\n## PID {$livePid} 2026-01-01T00:00:00Z\n## Source            https://example.com/issues/2\n",
        );
        installerWriteFile($runDir . '/gh-no-pid.md', "# Task brief — gh-no-pid\n## Source            https://example.com/issues/3\n");
        installerWriteFile($runDir . '/gh-own.md', "# Task brief — gh-own\n## Source            https://example.com/issues/4\n");
        installerWriteFile(
            $runDir . '/gh-malformed.md',
            "# Task brief — gh-malformed\n## PID not-a-number 2026-01-01T00:00:00Z\n## Source            https://example.com/issues/5\n",
        );
        installerWriteFile(
            $runDir . '/gh-fenced.md',
            "# Task brief — gh-fenced\n## Source            https://example.com/issues/6\n\n"
                . "## Gathered context\n```text\n## PID 9999999 2026-01-01T00:00:00Z\n```\n",
        );
        // Run-2 Critical 1 regressions: no attacker needed for either.
        installerWriteFile(
            $runDir . '/gh-timestamp-first.md',
            "# Task brief — gh-timestamp-first\n## PID 2026-07-31T06:59:18Z 11073\n## Source            https://example.com/issues/8\n",
        );
        installerWriteFile(
            $runDir . '/gh-prefixed.md',
            "# Task brief — gh-prefixed\n## PID\nrun 3 — resumed, pid 11073\n"
                . "## Source            https://example.com/issues/9\n## Gathered context\n## PID 9999999\n",
        );

        try {
            $deletable = daidalosStartupSweepDeletableBriefs($runDir, 'gh-own.md');
            sort($deletable);

            // Only the confirmed-dead, fixed-position, format-valid PID is deletable. A missing
            // PID, a malformed token, a PID found only inside a fenced tracker-payload quote, a
            // timestamp written before the PID on the same line, and a brief whose own field is
            // malformed with a PID-looking line later in the file are all fail-safe: preserved,
            // exactly like the genuinely live one.
            expect($deletable)->toBe(['gh-dead.md']);
        } finally {
            installerRemoveDirectory($root);
        }
    },
);

test('daidalos startup-sweep algorithm treats an EPERM probe as alive, never as dead (PR #150 CR fix)', function (): void {
    if (posix_getuid() === 0) {
        expect(value: true)->toBeTrue();

        return;
    }

    $root = installerCreateProjectRoot();
    $runDir = $root . '/.claude/run';

    // PID 1 (init/launchd) exists but is owned by another user — kill(pid, 0) fails with EPERM,
    // not ESRCH, for a non-root caller. EPERM proves the process exists, so it must count as alive.
    installerWriteFile(
        $runDir . '/gh-eperm.md',
        "# Task brief — gh-eperm\n## PID 1 2026-01-01T00:00:00Z\n## Source            https://example.com/issues/7\n",
    );

    try {
        $deletable = daidalosStartupSweepDeletableBriefs($runDir, 'gh-own.md');

        expect($deletable)->toBe([]);
    } finally {
        installerRemoveDirectory($root);
    }
});

/**
 * Mirrors the confirmed-dead-gated steal decision in agents/daidalos.md step 2 *Sweep lock*: a
 * lock is stolen only when it carries a holder file AND that holder's recorded PID probes
 * confirmed-dead — never on a bare timeout, and never when the holder file is missing or its PID
 * is alive/EPERM (PR #150 CR fix, run-2 Critical 2 / Moderate 2).
 *
 * @param array{PID: int}|null $holder the parsed holder file contents, or null when absent/unreadable
 */
function daidalosSweepLockShouldSteal(?array $holder): bool
{
    if ($holder === null || !isset($holder['PID'])) {
        return false;
    }

    return daidalosPidConfirmedDead($holder['PID']);
}

test('daidalos sweep-lock steal is gated on a confirmed-dead holder, never a bare timeout (PR #150 CR fix)', function (): void {
    $livePid = getmypid();
    expect($livePid)->not->toBeFalse();

    if ($livePid === false) {
        // @codeCoverageIgnoreStart
        return;
        // @codeCoverageIgnoreEnd
    }

    // No holder file at all (an older, pre-holder lock, or an unreadable one) — never steal.
    expect(daidalosSweepLockShouldSteal(holder: null))->toBeFalse();
    // Confirmed-dead holder — the only condition that justifies a steal.
    expect(daidalosSweepLockShouldSteal(['PID' => 999_999_999]))->toBeTrue();
    // Live holder — never steal, no matter how many attempts have elapsed.
    expect(daidalosSweepLockShouldSteal(['PID' => $livePid]))->toBeFalse();
});

/**
 * Parses `ps -o etime=` output (`[[DD-]HH:]MM:SS`) into a duration in seconds, so a process's own
 * start time can be computed as `now - elapsed` without parsing the locale-dependent `lstart`
 * calendar format at all. `etime` is portable across macOS/BSD and Linux ps — empirically verified
 * on this machine: `ps -o etimes=` fails with "keyword not found", `ps -o etime=` succeeds (PR #150
 * CR fix, run-2 Moderate 1).
 */
function daidalosParseEtimeToSeconds(string $etime): int
{
    $etime = trim($etime);
    $days = 0;

    if (str_contains($etime, '-')) {
        [$daysPart, $etime] = explode('-', $etime, 2);
        $days = (int) $daysPart;
    }

    $parts = explode(':', $etime);
    $seconds = (int) array_pop($parts);
    $minutes = $parts !== [] ? (int) array_pop($parts) : 0;
    $hours = $parts !== [] ? (int) array_pop($parts) : 0;

    return ($days * 86_400) + ($hours * 3_600) + ($minutes * 60) + $seconds;
}

test('daidalosParseEtimeToSeconds parses every ps -o etime= shape into seconds (PR #150 CR fix)', function (): void {
    expect(daidalosParseEtimeToSeconds('00:00'))->toBe(0);
    expect(daidalosParseEtimeToSeconds('01:23'))->toBe(83);
    expect(daidalosParseEtimeToSeconds('02:03:04'))->toBe(7_384);
    expect(daidalosParseEtimeToSeconds('1-02:03:04'))->toBe(93_784);
});

/**
 * Mirrors the identity-corroboration probe in agents/daidalos.md *Concurrency & the working-tree
 * write-lock* → *Stale reclaim*: a bare alive PID only proves that PID number is currently in use
 * by *something*, never that it is the same process that wrote the lock's `STARTED` timestamp. The
 * process's own start time (`now - etime`) must fall at or before `STARTED`, allowing a small
 * tolerance for clock/measurement skew — a process cannot have written a timestamp before it
 * existed. A later computed start time means the PID was recycled since the lock was written:
 * identity is not corroborated, and the holder is treated exactly like a confirmed-dead probe
 * (PR #150 CR fix, run-2 Moderate 1 — closing the gap where the fix added `STARTED` to the layout
 * but nothing ever read it).
 */
function daidalosWriteLockIdentityCorroborated(int $recordedStartedEpoch, int $processStartEpoch, int $toleranceSeconds = 60): bool
{
    return $processStartEpoch <= $recordedStartedEpoch + $toleranceSeconds;
}

test(
    'daidalos write-lock identity corroboration treats a PID that started after STARTED as recycled, not the same run (PR #150 CR fix)',
    function (): void {
        $recordedStartedEpoch = 1_800_000_000;

        // The genuinely same process always starts at or before the moment it later writes STARTED.
        expect(daidalosWriteLockIdentityCorroborated($recordedStartedEpoch, $recordedStartedEpoch - 60))->toBeTrue();
        // A little clock/measurement skew right around the same instant is tolerated.
        expect(daidalosWriteLockIdentityCorroborated($recordedStartedEpoch, $recordedStartedEpoch + 30))->toBeTrue();
        // A PID that provably started AFTER the recorded write cannot be the same process — recycled.
        expect(daidalosWriteLockIdentityCorroborated($recordedStartedEpoch, $recordedStartedEpoch + 300))->toBeFalse();
    },
);

test(
    'daidalos write-lock reclaim documents identity corroboration, not just PID existence (PR #150 CR fix, run-2 Moderate 1)',
    function (): void {
        $packageDir = dirname(__DIR__, 2);
        $daidalos = (string) file_get_contents($packageDir . '/agents/daidalos.md');

        expect($daidalos)->toContain('corroborate identity before trusting it as a live blocker');
        expect($daidalos)->toContain('`ps -o etime= -p "$PID"`');
        expect($daidalos)->toContain('a process cannot have written a timestamp before it existed');
        expect($daidalos)->toContain('the PID has been recycled by an unrelated process since the lock was written');
        expect($daidalos)->toContain('write-lock-staleness-needs-corroborating-evidence-not-bare-pid');
    },
);

/**
 * A worktree porcelain entry is judged confirmed-dead only from a `locked <reason>` line whose
 * reason carries a `pid <N>` token matching `^[0-9]{1,7}$` — no `locked` line at all, or one with
 * no parseable pid token, is never confirmed-dead.
 */
function daidalosWorktreeEntryConfirmedDead(string $entry): bool
{
    if (preg_match('/^locked (.*)$/m', $entry, $lockMatch) !== 1) {
        return false;
    }

    if (preg_match('/\bpid (\d{1,7})\b/', $lockMatch[1], $pidMatch) !== 1) {
        return false;
    }

    return daidalosPidConfirmedDead((int) $pidMatch[1]);
}

/**
 * Mirrors the decision in agents/daidalos.md step 2 *Startup sweep* for the WORKTREE half —
 * previously covered only by string assertions, not an algorithmic fixture (PR #150 CR fix,
 * Critical 4's test gap). A `.claude/worktrees/agent-*` entry from `git worktree list
 * --porcelain` is safe to remove only on positive proof of death (`daidalosWorktreeEntryConfirmedDead()`
 * above) — an entry with no `locked` line at all, or a `locked` line whose reason carries no
 * parseable pid token, is left in place — never removed (fail-safe, inverted from the original
 * "no locked line → treat like a stale lock → remove" behaviour).
 *
 * @return array<int, string> worktree paths judged safe to remove
 */
function daidalosStartupSweepRemovableWorktrees(string $porcelain): array
{
    $removable = [];
    $entries = array_filter(explode("\n\n", trim($porcelain)), static fn (string $entry): bool => $entry !== '');

    foreach ($entries as $entry) {
        if (preg_match('/^worktree (.+)$/m', $entry, $pathMatch) === 1 && daidalosWorktreeEntryConfirmedDead($entry)) {
            $removable[] = $pathMatch[1];
        }
    }

    return $removable;
}

test(
    'daidalos startup-sweep worktree algorithm is fail-safe: only a confirmed-dead, parseable locked pid is removable (PR #150 CR fix)',
    function (): void {
        $livePid = getmypid();
        expect($livePid)->not->toBeFalse();

        $porcelain = 'worktree /repo/.claude/worktrees/agent-cr-dead' . "\n"
            . 'HEAD 1111111111111111111111111111111111111111' . "\n"
            . 'branch refs/heads/talos/gh-1' . "\n"
            . 'locked pid 9999999 slug gh-1' . "\n"
            . "\n"
            . 'worktree /repo/.claude/worktrees/agent-cr-live' . "\n"
            . 'HEAD 2222222222222222222222222222222222222222' . "\n"
            . 'branch refs/heads/talos/gh-2' . "\n"
            . 'locked pid ' . $livePid . ' slug gh-2' . "\n"
            . "\n"
            . 'worktree /repo/.claude/worktrees/agent-cr-unlocked' . "\n"
            . 'HEAD 3333333333333333333333333333333333333333' . "\n"
            . 'branch refs/heads/talos/gh-3' . "\n"
            . "\n"
            . 'worktree /repo/.claude/worktrees/agent-cr-no-pid-token' . "\n"
            . 'HEAD 4444444444444444444444444444444444444444' . "\n"
            . 'branch refs/heads/talos/gh-4' . "\n"
            . 'locked keep — manual bisect in progress' . "\n";

        $removable = daidalosStartupSweepRemovableWorktrees($porcelain);

        // Only the confirmed-dead, parseable-pid locked entry is removable — the unlocked entry
        // and the locked-but-no-pid-token entry are both preserved, exactly like the live one.
        expect($removable)->toBe(['/repo/.claude/worktrees/agent-cr-dead']);
    },
);

test('daidalos startup-sweep worktree algorithm treats a locked EPERM pid as alive, never as dead (PR #150 CR fix)', function (): void {
    if (posix_getuid() === 0) {
        expect(value: true)->toBeTrue();

        return;
    }

    $porcelain = 'worktree /repo/.claude/worktrees/agent-cr-eperm' . "\n"
        . 'HEAD 5555555555555555555555555555555555555555' . "\n"
        . 'branch refs/heads/talos/gh-5' . "\n"
        . 'locked pid 1 slug gh-5' . "\n";

    $removable = daidalosStartupSweepRemovableWorktrees($porcelain);

    expect($removable)->toBe([]);
});

test(
    'daidalos startup sweep documents the fail-safe default, single PID source, format validation, and a dedicated sweep lock (PR #150 CR fix)',
    function (): void {
        $packageDir = dirname(__DIR__, 2);
        $daidalos = (string) file_get_contents($packageDir . '/agents/daidalos.md');

        // Fail-safe by construction — absence of a signal is never proof of death.
        expect($daidalos)->toContain('it deletes only on positive proof of death, never on the mere absence of a liveness signal');

        // A single, run-stable PID source — $$ is explicitly ruled out everywhere it is prescribed.
        expect($daidalos)->toContain('**never `$$`**, the ephemeral PID of the single Bash subshell one tool call runs in');
        expect($daidalos)->toContain('two consecutive Bash calls in this environment report two different `$$` values but an identical, stable `$PPID`');

        // Fixed-position parsing (never a fence-based "header region") and format validation guard
        // against attacker-influenced tracker text (PR #150 CR fix, run-2 Critical 1).
        expect($daidalos)->toContain('`## PID` is always the file\'s fixed **second line**');
        expect($daidalos)->toContain('read **only that one line, by position**, and never anything else in the file');
        expect($daidalos)->toContain('^## PID[ \t]+([0-9]{1,7})[ \t]');
        expect($daidalos)->toContain('require the captured token to match `^[0-9]{1,7}$`');
        expect($daidalos)->toContain('always double-quote it when it reaches a command (`kill -0 "$pid"`)');

        // ESRCH vs EPERM is distinguished everywhere a `kill -0` probe is prescribed.
        expect($daidalos)->toContain('conflates "no such process" (ESRCH) with "process exists, no permission" (EPERM)');

        // The sweep itself runs under a dedicated, short-lived lock distinct from the write-lock,
        // keyed on the repository-wide common git dir (PR #150 CR fix, run-2 Critical 2 / Moderate 2).
        expect($daidalos)->toContain('git rev-parse --git-common-dir');
        expect($daidalos)->toContain('.daidalos-sweep.lock');
        expect($daidalos)->toContain('mkdir -p "$LOCKROOT/agent-run"');
        expect($daidalos)->toContain('This is separate from, and much shorter-lived than, the write-lock above');

        // An unlocked worktree entry (or one with no parseable pid token) is left in place, not removed —
        // inverted from the original "no locked line → treat like a stale lock → remove" default.
        expect($daidalos)->toContain('is left in place — never removed');

        // The brief is created atomically, `## PID` first, closing the mid-write observation window.
        expect($daidalos)->toContain('Atomic, PID-first brief creation');
        expect($daidalos)->toContain('so no peer\'s sweep can ever observe the file between its creation and the `## PID` line landing');
    },
);

test(
    'argos and athena lock every CR worktree at creation with a parseable pid token, under the required path convention (PR #150 CR fix)',
    function (): void {
        $packageDir = dirname(__DIR__, 2);

        foreach (['argos', 'athena'] as $agent) {
            $content = (string) file_get_contents($packageDir . '/agents/' . $agent . '.md');

            expect($content)->toContain('.claude/worktrees/agent-cr-<slug>-' . $agent);
            expect($content)->toContain('lock it immediately on creation');
            expect($content)->toContain('git worktree lock --reason "pid $PPID agent ' . $agent . ' slug <slug>"');
            expect($content)->toContain('never `$$`, the ephemeral per-Bash-call subshell PID');
            expect($content)->toContain('git worktree unlock <path>');
            // Moderate 3 (path/reason collide between the two peers) — per-agent suffix disambiguates both.
            expect($content)->toContain('the `-' . $agent . '` suffix keeps this path and lock reason distinct from');
            // Moderate 4 (unvalidated <slug> reaching a shell/path) — slug format guard, reject-and-stay-shared.
            expect($content)->toContain('^[A-Za-z0-9._-]{1,64}$');
            expect($content)->toContain('do not create a worktree** — continue the review in the shared tree instead');
            // Minor 2 (`<ref>` fails on an already-checked-out PR head branch) — --detach + head SHA instead.
            expect($content)->toContain('git worktree add --detach .claude/worktrees/agent-cr-<slug>-' . $agent . ' <head-sha>');
            // Standalone cleanup removes only this agent's own path, never a peer's.
            expect($content)->toContain('remove **only your own** worktree after the review — never a peer\'s');
        }
    },
);
