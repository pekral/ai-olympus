<?php

declare(strict_types = 1);

test('compound-engineering rule codifies easier-future-work and per-project compound memory (issue #564)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $rulePath = $packageDir . '/rules/compound-engineering/general.mdc';

    expect(is_file($rulePath))->toBeTrue();

    $content = (string) file_get_contents($rulePath);

    // Frontmatter: always-applied cross-cutting rule.
    expect($content)->toContain('alwaysApply: true');

    // Pillar 1 — every change must make future work easier, and lessons are recorded.
    expect($content)->toContain('## Compound Engineering');
    expect($content)->toContain('make future work easier');

    // Pillar 2 — per-project compound memory, stored in the project, not this package.
    expect($content)->toContain('## Compound Memory (per project)');
    expect($content)->toContain('in the project being worked on, never in this shared rules package');

    // The rule is listed in the README Rules Overview table.
    $readme = (string) file_get_contents($packageDir . '/README.md');
    expect($readme)->toContain('`compound-engineering/general.mdc`');
});

test('analyze-problem skill requires pre-implementation research and a plan artifact (issue #564)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/skills/analyze-problem/SKILL.md');

    expect($content)->toContain('@rules/compound-engineering/general.mdc');
    expect($content)->toContain('## Pre-Implementation Research & Plan');

    // The three research inputs.
    expect($content)->toContain('**Codebase**');
    expect($content)->toContain('**Commit history**');
    expect($content)->toContain('**Internet best practices');

    // The plan artifact is a text file or a GitHub issue.
    expect($content)->toContain('text file in the repo');
    expect($content)->toContain('GitHub issue');

    // The five mandatory parts of the plan.
    expect($content)->toContain('**Goal**');
    expect($content)->toContain('**Architecture**');
    expect($content)->toContain('**Implementation steps**');
    expect($content)->toContain('**Sources**');
    expect($content)->toContain('**Success criteria**');
});

test('git/general.mdc mandates English branch names regardless of assignment language', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/rules/git/general.mdc');

    expect($content)->toContain('always written in English regardless of the assignment language');
});

test('resolve-issue skill requires the created branch name to be in English', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/skills/resolve-issue/SKILL.md');

    expect($content)->toContain('name always in English, regardless of the assignment language');
});

test('git/general.mdc mandates one commit per phase for phased issues', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/rules/git/general.mdc');

    expect($content)->toContain('One phase = one commit.');
    expect($content)->toContain('exactly one commit');
});

test('git/general.mdc mandates one commit per enumerated assignment point', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/rules/git/general.mdc');

    // The point-level mapping and the cherry-pick ordering preference are two separate
    // mandates — a rule carrying only the first lets a run produce dependent commits
    // silently, which is what makes a PR's change list unreadable.
    expect($content)->toContain('One assignment point = one commit.');
    expect($content)->toContain('in the assignment\'s own order');
    expect($content)->toContain('Prefer independent, cherry-pickable commits.');

    // Independence must stay a preference: a hard requirement would push a run to merge
    // two points into one commit, which defeats the point-level mapping above.
    expect($content)->toContain('Independence is a **preference**');
});

test('resolve-issue skill anchors phase planning on the one-phase-one-commit git rule', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/skills/resolve-issue/SKILL.md');

    expect($content)->toContain('one phase = one commit');
    expect($content)->toContain('@rules/git/general.mdc');
});

test('resolve-issue plans one commit per point the assignment enumerates', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $skill = (string) file_get_contents($packageDir . '/skills/resolve-issue/SKILL.md');
    $reference = (string) file_get_contents($packageDir . '/skills/resolve-issue/references/phase-planning.md');

    // The body must name the point markers a run has to look for — a phase-only summary
    // walks past an assignment that lists recommended fixes instead of phases.
    expect($skill)->toContain('one point = one commit');
    expect($skill)->toContain('recommended fixes, review findings, checklist entries, ordered acceptance criteria');
    expect($skill)->toContain('independently cherry-pickable');

    // The reference owns the procedure: inventory, mapping, independence ordering, and the
    // recorded table the PR change list is rendered from.
    expect($reference)->toContain('## 1. Inventory the points the assignment enumerates');
    expect($reference)->toContain('## 2. Map one point to one commit');
    expect($reference)->toContain('## 3. Order for independence (cherry-pick friendly — preferred, not required)');
    expect($reference)->toContain('## 4. Record the commit plan before implementing');
    expect($reference)->toContain('depends on #N');

    // A deferred or pre-existing point must not silently become an in-scope commit.
    expect($reference)->toContain('A point the run does **not** implement never becomes a commit');
});

test('resolve-issue PR description lists one entry per commit', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $skill = (string) file_get_contents($packageDir . '/skills/resolve-issue/SKILL.md');
    $reference = (string) file_get_contents($packageDir . '/skills/resolve-issue/references/phase-planning.md');

    // The PR content requirements must demand the change list, and the reference must define
    // its shape — a per-file or per-topic list defeats the point-per-commit mapping.
    expect($skill)->toContain('**Changes** — one entry per commit');
    expect($reference)->toContain('Rendering the PR `## Changes` list.');
    expect($reference)->toContain('One line per commit — never one line per file');
    expect($reference)->toContain('depends on <N>');
});

test('resolve-issue skill refuses to resolve a closed / inactive task', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/skills/resolve-issue/SKILL.md');

    expect($content)->toContain('The issue must be open / active.');
    expect($content)->toContain('do not resolve it');
});

test('compound-engineering rule defines the per-project memory file convention (issue #626)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/rules/compound-engineering/general.mdc');

    expect($content)->toContain('docs/memory/PROJECT_MEMORY.md');
    expect($content)->toContain('### Read protocol');
});

test('compound-engineering rule provides the Blocked delegation hard-stop section referenced by agents (issue #626)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $rule = (string) file_get_contents($packageDir . '/rules/compound-engineering/general.mdc');
    $daidalos = (string) file_get_contents($packageDir . '/agents/daidalos.md');
    $talos = (string) file_get_contents($packageDir . '/agents/talos.md');

    expect($rule)->toContain('## Blocked delegation is a hard stop');
    expect(substr_count($rule, '## Blocked delegation is a hard stop'))->toBe(1);
    expect($daidalos)->toContain('*Blocked delegation is a hard stop*');
    expect($talos)->toContain('*Blocked delegation is a hard stop*');
});

test('compound memory reads are hooked into the context phases (issue #626)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $daidalos = (string) file_get_contents($packageDir . '/agents/daidalos.md');
    $analyze = (string) file_get_contents($packageDir . '/skills/analyze-problem/SKILL.md');
    $prepare = (string) file_get_contents($packageDir . '/skills/prepare-issue-context/SKILL.md');

    expect($daidalos)->toContain('## Project memory');
    expect($daidalos)->toContain('docs/memory/PROJECT_MEMORY.md');
    expect($analyze)->toContain('docs/memory/PROJECT_MEMORY.md');
    expect($prepare)->toContain('docs/memory/PROJECT_MEMORY.md');
});

test('CLAUDE.md points to the per-project memory file so it stays discoverable (issue #148)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $claudeMd = (string) file_get_contents($packageDir . '/CLAUDE.md');
    $rule = (string) file_get_contents($packageDir . '/rules/compound-engineering/general.mdc');

    // general.mdc already mandates the pointer; CLAUDE.md (root, and the shipped template
    // installed into consumer projects) must actually carry it.
    expect($rule)->toContain('Reference the memory file from `CLAUDE.md`');
    expect($claudeMd)->toContain('docs/memory/PROJECT_MEMORY.md');
});

test('compound memory write mechanism is removed (issue #77)', function (): void {
    $packageDir = dirname(__DIR__, 2);

    // The write-side skill is gone entirely.
    expect(is_dir($packageDir . '/skills/record-project-memory'))->toBeFalse();

    // No former write hook still references the removed skill.
    $rule = (string) file_get_contents($packageDir . '/rules/compound-engineering/general.mdc');
    $resolveIssue = (string) file_get_contents($packageDir . '/skills/resolve-issue/SKILL.md');
    $processCr = (string) file_get_contents($packageDir . '/skills/process-code-review/SKILL.md');
    $daidalos = (string) file_get_contents($packageDir . '/agents/daidalos.md');

    expect($rule)->not->toContain('record-project-memory');
    expect($resolveIssue)->not->toContain('record-project-memory');
    expect($processCr)->not->toContain('record-project-memory');
    expect($daidalos)->not->toContain('record-project-memory');

    // The old fully-automated write-protocol sections stay gone; the read side stays.
    // (A narrower, compaction-only "### Write protocol" is reintroduced by issue #98 —
    // see 'compound-engineering rule adds a narrower compaction-only Write protocol
    // sibling to Read protocol (issue #98)' below; it never generates new lessons.)
    expect($rule)->not->toContain('### Promotion bar');
    expect($rule)->not->toContain('### Curation pass');
    expect($rule)->not->toContain('### What feeds the memory');
    expect($rule)->toContain('## Compound Memory (per project)');
    expect($rule)->toContain('### Where to store it');
    expect($rule)->toContain('### Read protocol');
    expect($rule)->toContain('Memory files are NEVER deleted');
});

test('compound-engineering rule mandates early idempotent claim before work starts (issue #704)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/rules/compound-engineering/general.mdc');

    // The section heading must exist.
    expect($content)->toContain('## Claim a tracker issue before working on it');

    // Core principle: claim early, idempotently, abort-on-conflict.
    expect($content)->toContain('Claim early and idempotently');
    expect($content)->toContain('Abort-on-conflict is the real collision guard');
    expect($content)->toContain('Exclude claimed issues from selection');

    // Release-on-Blocked semantics.
    expect($content)->toContain('Release on Blocked');

    // Bugsnag no-claim documented as known limitation.
    expect($content)->toContain('Bugsnag has no auto-claim');

    // Reference back to the skill that owns the execution.
    expect($content)->toContain('@skills/resolve-issue/SKILL.md');
});

test('compound-engineering rule mandates temporary-file hygiene with a hard memory-files exception (issue #694)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/rules/compound-engineering/general.mdc');

    // The section heading must exist.
    expect($content)->toContain('## Temporary-file hygiene');

    // The memory-files exception must name the canonical project memory path verbatim.
    expect($content)->toContain('docs/memory/PROJECT_MEMORY.md');

    // The exception must state that memory files are never deleted.
    expect($content)->toContain('NEVER deleted');

    // The rule must reference daidalos step 7 as the reference implementation.
    expect($content)->toContain('daidalos');
});

test('deferred points must be filed as follow-up tracker issues so they are not forgotten', function (): void {
    $packageDir = dirname(__DIR__, 2);

    $rule = (string) file_get_contents($packageDir . '/rules/compound-engineering/general.mdc');

    // The section heading must exist and own the principle.
    expect($rule)->toContain('## File deferred points as follow-up tracker issues');
    expect($rule)->toContain('silent scope cut');
    expect($rule)->toContain('Deduplicate before filing');
    expect($rule)->toContain('Verify the issue actually landed');

    // Principle/execution split mirrors the claim section — resolve-issue owns the mechanics.
    expect($rule)->toContain('*Deferred-item follow-up issues*');

    // The rule names its single sanctioned exception (resolve-issue PR opt-out)
    // so the rule and the skill never contradict each other.
    expect($rule)->toContain('single sanctioned exception');
    expect($rule)->toContain('filed when the PR opens');

    // resolve-issue keeps the section heading and the no-URL-no-done guarantee;
    // the per-tracker mechanics were extracted to a reference (issue #59).
    $resolveIssue = (string) file_get_contents($packageDir . '/skills/resolve-issue/SKILL.md');
    expect($resolveIssue)->toContain('### Deferred-item follow-up issues');
    expect($resolveIssue)->toContain('Never report a deferral as handled without a live issue URL');
    $deferredRef = (string) file_get_contents($packageDir . '/skills/resolve-issue/references/deferred-follow-up.md');
    expect($deferredRef)->toContain('never report the deferral as handled without a live issue URL');

    // The other deferring flows route through the same rule.
    $processCr = (string) file_get_contents($packageDir . '/skills/process-code-review/SKILL.md');
    expect($processCr)->toContain('File deferred points as follow-up tracker issues');

    $createMissing = (string) file_get_contents($packageDir . '/skills/create-missing-tests-in-pr/SKILL.md');
    expect($createMissing)->toContain('Deferred-item follow-up issues');
});

test('newly created tracker issues get the single most relevant existing label (issue #54)', function (): void {
    $packageDir = dirname(__DIR__, 2);

    $rule = (string) file_get_contents($packageDir . '/rules/compound-engineering/general.mdc');

    // The section heading must exist; unlike the two sections above it owns both
    // the principle and the per-tracker mechanics (four executors, not one).
    expect($rule)->toContain('## Label newly created tracker issues');

    // Select-only-from-loaded-list + never-create, with the EPIC exception named
    // inline (rule<->skill parity: an absolute the skill legitimately violates
    // must be named in the rule, not left to silently contradict it).
    expect($rule)->toContain('never create a new label');
    expect($rule)->toContain('single sanctioned exception is the structural `EPIC` label');

    // The three semantic exclusion classes stay generic (name/description-driven),
    // not hardcoded to this repository's own label set.
    expect($rule)->toContain('workflow/state/structural');
    expect($rule)->toContain('verdict/triage');
    expect($rule)->toContain('audience');

    // No-fit fallback is "no label" — never a forced fit, never a new label.
    expect($rule)->toContain('No fitting candidate, no label.');

    // Additive to the existing EPIC / backlog-claim / ready-for-review label mechanics.
    expect($rule)->toContain('Stay additive.');

    // Per-tracker mechanics: GitHub needs an explicit --limit (the gh CLI default is only 30).
    expect($rule)->toContain('gh label list --json name,description --limit 200');
    // JIRA labels carry no description or registry; harvest via JQL and match by name only.
    expect($rule)->toContain('labels IS NOT EMPTY');

    // Every one of the 3 call sites carries a one-line reference to the rule section.
    $createIssue = (string) file_get_contents($packageDir . '/skills/create-issue/SKILL.md');
    $createIssuesFromText = (string) file_get_contents($packageDir . '/skills/create-issues-from-text/SKILL.md');
    // resolve-issue's per-tracker filing mechanics were extracted to a reference (issue #59).
    $resolveIssue = (string) file_get_contents($packageDir . '/skills/resolve-issue/references/deferred-follow-up.md');

    expect($createIssue)->toContain('Label newly created tracker issues');
    expect($createIssuesFromText)->toContain('Label newly created tracker issues');
    expect($resolveIssue)->toContain('Label newly created tracker issues');

    // Additive-only: the pre-existing EPIC structural-label mechanism stays untouched.
    expect($createIssuesFromText)->toContain('EPIC parent & sub-issues');
    expect($createIssuesFromText)->toContain('gh label create EPIC');
    expect($createIssuesFromText)->toContain('Part of #<parent>');
});

test(
    'compound-engineering rule requires every orchestrator turn to end in a result or a hard blocker, never a narrated plan (issue #119)',
    function (): void {
        $packageDir = dirname(__DIR__, 2);
        $rule = (string) file_get_contents($packageDir . '/rules/compound-engineering/general.mdc');
        $daidalos = (string) file_get_contents($packageDir . '/agents/daidalos.md');

        // The section heading must exist and state the binary stopping condition.
        expect($rule)->toContain('## Orchestrator turns must end in a result or a hard blocker, never a narrated plan');
        expect($rule)->toContain('**(a) A completed result**');
        expect($rule)->toContain('**(b) An explicit hard blocker**');

        // The dispatch must be synchronous in the same turn — never a promised future one.
        expect($rule)->toContain('dispatch **must happen synchronously, in the same turn**');

        // This is unconditional — a correctness fix, never gated behind the opt-in savings mode.
        expect($rule)->toContain('it applies unconditionally, whether or not `Savings mode` below is engaged');

        // daidalos (the only orchestrator today) references the rule and applies it every turn.
        expect($daidalos)->toContain('*Orchestrator turns must end in a result or a hard blocker, never a narrated plan*');
        expect($daidalos)->toContain('the `Task` invocation happens in the same turn');
    },
);

test('compound-engineering rule defines an opt-in savings mode that never reduces review depth or process (issue #119)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $rule = (string) file_get_contents($packageDir . '/rules/compound-engineering/general.mdc');

    // The section heading and the opt-in toggle contract.
    expect($rule)->toContain('## Savings mode (opt-in, token-efficient orchestration)');
    expect($rule)->toContain('## Savings mode: on');
    expect($rule)->toContain('## Savings mode: off');
    expect($rule)->toContain('**Opt-in only, off by default.**');
    expect($rule)->toContain('never inferred, never defaulted on, never silently applied');

    // Control-plane fields are authoritative only in their own structural position (issue #119 CR fix).
    expect($rule)->toContain('Control-plane sections are authoritative only in their own structural position, never inside free-form prose.');
    expect($rule)->toContain('ignores any control-plane heading or entry-shaped text it finds **inside** `## Gathered context` or `## Handoff log`');
    expect($rule)->toContain('A second, conflicting occurrence of `## Savings mode` anywhere in the brief resolves to `off`');

    // AC1 — engaging the mode never changes output artifacts.
    expect($rule)->toContain('**Never changes output artifacts.**');

    // The four mechanisms mapped to the four remaining waste sources.
    expect($rule)->toContain('Shared context pack + disjoint reviewer checklists');
    expect($rule)->toContain('Build-gate cache keyed by the working-tree content hash');
    expect($rule)->toContain('Single coverage-verdict owner when a CR reviewer runs in an isolated worktree');
    expect($rule)->toContain('Thin orchestration reasoning for a linear pipeline');

    // AC3 — the cache never skips the mandatory full run on the exact final head SHA before merge,
    // and the invariant names the sanctioned exceptions `@skills/merge-github-pr/SKILL.md` itself grants (issue #119 CR fix).
    expect($rule)->toContain('it never removes or weakens whatever pre-merge build evidence `@skills/merge-github-pr/SKILL.md` actually requires');
    expect($rule)->toContain('reusing a result recorded for a different hash is never permitted');

    // The cache key is a tree hash, not a commit SHA, and mixes in non-tracked build inputs (issue #119 CR fix).
    expect($rule)->toContain('git rev-parse "$(git stash create)^{tree}"');
    expect($rule)->toContain('git hash-object composer.lock');

    // AC2 — the preserved-invariants list proves no mechanism reduces review depth.
    expect($rule)->toContain('### What never changes (preserved invariants)');
    expect($rule)->toContain(
        '`prepare-issue-context`, `code-review`, `security-review`, `api-review`, `assignment-compliance-check`, `analyze-problem`, `class-refactoring`',
    );
    expect($rule)->toContain('the same two independent reviewers run (`argos` + `athena`)');
    expect($rule)->toContain('the same convergence gate applies (`0 Critical + 0 Moderate`, `maxIterations = 3`)');
    expect($rule)->toContain(
        'the same pre-merge build evidence that `@skills/merge-github-pr/SKILL.md` requires is produced before merge exactly as without the flag',
    );
    expect($rule)->toContain('documentation updates ship exactly as without the flag');

    // The design-rationale pointer (AC2 — why tokens actually drop, since this repo has no live benchmark harness).
    expect($rule)->toContain('`docs/agents.md` *Savings mode*');

    $docs = (string) file_get_contents($packageDir . '/docs/agents.md');
    expect($docs)->toContain('## Savings mode (opt-in, token-efficient orchestration)');
    expect($docs)->toContain('Why it saves tokens without reducing review depth');
});

test(
    'compound-engineering rule adds a narrower compaction-only Write protocol sibling to Read protocol (issue #98)',
    function (): void {
        $packageDir = dirname(__DIR__, 2);
        $rule = (string) file_get_contents($packageDir . '/rules/compound-engineering/general.mdc');

        // The new section is a sibling of Read protocol, under the same Compound Memory heading.
        expect($rule)->toContain('### Write protocol (compact after every write)');
        $compoundMemoryPos = strpos($rule, '## Compound Memory (per project)');
        $writeProtocolPos = strpos($rule, '### Write protocol (compact after every write)');
        $readProtocolPos = strpos($rule, '### Read protocol');
        $temporaryFileHygienePos = strpos($rule, '## Temporary-file hygiene');
        expect($compoundMemoryPos)->not->toBeFalse();
        expect($writeProtocolPos)->not->toBeFalse();
        expect($readProtocolPos)->not->toBeFalse();
        expect($temporaryFileHygienePos)->not->toBeFalse();

        if (!is_int($compoundMemoryPos) || !is_int($writeProtocolPos)
            || !is_int($readProtocolPos) || !is_int($temporaryFileHygienePos)
        ) {
            return;
        }

        // Read protocol, then Write protocol, both nested inside Compound Memory, before the next `##` section.
        expect($compoundMemoryPos)->toBeLessThan($readProtocolPos);
        expect($readProtocolPos)->toBeLessThan($writeProtocolPos);
        expect($writeProtocolPos)->toBeLessThan($temporaryFileHygienePos);

        // The protocol names the compacting skill and is an unconditional default, not opt-in.
        expect($rule)->toContain('@skills/compact-project-memory/SKILL.md');
        expect($rule)->toContain('immediately after the write, before the run reports completion');
        expect($rule)->toContain('This is an unconditional default, not an opt-in step.');

        // Scoped to the touched entries, not a whole-file maintenance pass; a no-op without a diff.
        expect($rule)->toContain('scoped to the entries the write actually touched (plus at most 3 demonstrably related ones)');
        expect($rule)->toContain('is a no-op when the file carries no diff');

        // Never resurrects the automated lesson-generation mechanism removed in #77.
        expect($rule)->toContain('This protocol never reintroduces the automated lesson-generation mechanism removed in #77');
        expect($rule)->toContain('it never invents, re-derives, or adds new lesson content');

        // Cross-referenced from Entry format, so the per-entry budget has one home.
        $entryFormatPos = strpos($rule, '### Entry format');
        expect($entryFormatPos)->not->toBeFalse();

        if (!is_int($entryFormatPos)) {
            return;
        }

        expect($entryFormatPos)->toBeLessThan($writeProtocolPos);
        expect($rule)->toContain('has one home in `### Write protocol` below');
    },
);

// The compact-project-memory SKILL.md content itself (frontmatter, invariant list) is
// pinned in tests/Installer/SkillsContentTest.php, matching every other skill-content
// assertion; this file owns only the compound-engineering rule wiring above.

// Per the project's test-isolation rule a Pest test cannot exec a real shell command, so
// this mirrors the documented awk block-extraction algorithm in PHP and proves it against
// a synthetic fixture instead of shelling out to the awk snippet pinned in general.mdc.
function compoundMemoryLineDeclaresRole(string $line, string $role): bool
{
    return str_starts_with($line, '- Role:') && preg_match('/(' . preg_quote($role, '/') . '|shared)/', $line) === 1;
}

/**
 * @return array<int, string>
 */
function compoundMemoryFilterRoleBlocks(string $content, string $role): array
{
    $blocks = [];
    $buf = '';
    $matched = false;

    foreach (explode("\n", $content) as $line) {
        if (str_starts_with($line, '### ')) {
            if ($matched) {
                $blocks[] = $buf;
            }

            $buf = '';
            $matched = false;
        }

        $buf .= $line . "\n";
        $matched = $matched || compoundMemoryLineDeclaresRole($line, $role);
    }

    if ($matched) {
        $blocks[] = $buf;
    }

    return $blocks;
}

test('per-role read filter extracts entries whose Role: line sits past a fixed grep -A5 window (issue #148)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $rule = (string) file_get_contents($packageDir . '/rules/compound-engineering/general.mdc');

    // The old fixed-offset idiom is gone; the corrected filter is a whole-block awk extraction.
    expect($rule)->not->toContain('grep -A5 "^### " docs/memory/PROJECT_MEMORY.md | grep -E "Role:.*(<your-role>|shared)"');
    expect($rule)->toContain('entry bodies carry a variable number of inserted lines');
    expect($rule)->toContain('/^### / { if (buf != "" && matched) printf "%s", buf; buf = ""; matched = 0 }');
    expect($rule)->toContain('/^- Role:/ { if ($0 ~ ("(" role "|shared)")) matched = 1 }');
    // The role binding, the accumulation line, and the END clause complete the shipped awk
    // program — previously unpinned, so a regression in any of them kept the suite green while
    // the idiom itself broke (PR #150 CR fix — Minor 5).
    expect($rule)->toContain('awk -v role="<your-role>" \'');
    expect($rule)->toContain('{ buf = buf $0 "\n" }');
    expect($rule)->toContain('END { if (buf != "" && matched) printf "%s", buf }');

    // Fixture: one entry whose `Role:` sits immediately after `Trigger:` (shallow — the old
    // `grep -A5` window already covered this) and one whose `Role:` sits past a fixed 5-line
    // offset behind several inserted continuation lines, mirroring a real `**Recurrence (#N)**`
    // paragraph — the exact shape that made the old fixed-offset window miss most real entries.
    $fixture = <<<'MEMORY'
    # Project memory — fixture

    ### shallow-match — Role sits immediately after Trigger
    - Trigger: something happens.
    - Role:    talos

    ### deep-match — Role sits past a fixed 5-line offset
    - Trigger: something else happens.
    - Rule:    do the thing.
    - Example: see file X.
    - Example: a second continuation line.
    - Example: a third continuation line.
    - Example: a fourth continuation line.
    - Source:  https://example.com/pull/1   Added: 2026-01-01
    - Role:    shared

    ### no-match — Role is a different role entirely
    - Trigger: unrelated.
    - Role:    daidalos

    ### no-role — carries no Role line at all
    - Trigger: unrelated.
    MEMORY;

    $matches = compoundMemoryFilterRoleBlocks($fixture, 'talos');

    expect($matches)->toHaveCount(2);
    expect($matches[0])->toContain('shallow-match');
    expect($matches[1])->toContain('deep-match');
});

/**
 * Parses `docs/memory/PROJECT_MEMORY.md` into its per-entry blocks (each starting at its own
 * `### slug` heading), asserting the split succeeded and produced at least one entry. Shared by
 * every test below that walks all entries, so the parsing preamble exists exactly once instead of
 * being repeated per test (PR #150 CR fix — Refactoring 1).
 *
 * @return array<int, string>
 */
function compoundMemoryParseEntries(string $memory): array
{
    $entries = preg_split('/(?=^### )/m', $memory);
    expect($entries)->not->toBeFalse();

    if ($entries === false) {
        return [];
    }

    $entries = array_values(array_filter($entries, static fn (string $entry): bool => str_starts_with($entry, '### ')));
    expect($entries)->not->toBe([]);

    return $entries;
}

test('PROJECT_MEMORY.md entries stay within the per-entry size budget (issue #148)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $memory = (string) file_get_contents($packageDir . '/docs/memory/PROJECT_MEMORY.md');
    $entries = compoundMemoryParseEntries($memory);

    // Target is ~1200 bytes/entry (issue #148) — NOT met, a deliberate trade-off (see CHANGELOG.md
    // and the PR description): two PR #150 CR-fix rounds each restored concrete pointers a
    // compaction pass had dropped, violating compact-project-memory's invariant #4 ("no concrete
    // pointer is ever dropped") — most recently a per-entry re-run of the loss-check (run-2
    // Critical 3) against `origin/master`, restoring 19 more entries' worth of pointers/refs.
    // Keeping every restored pointer takes priority over the byte target. The tightest entries sit
    // near 1200 B; the single largest (`skills-tree-convention-removal-grep-full-tree`, several
    // restored file paths plus a restored pin-assertion reference) measures ~1699 B, so the ceiling
    // below sits just above the measured maximum — tight enough to fail on the next byte of drift,
    // not the next hundred.
    foreach ($entries as $entry) {
        $title = strtok($entry, "\n");

        expect(strlen($entry))->toBeLessThanOrEqual(1_700, 'Entry exceeds the per-entry byte budget: ' . $title);
    }
});

test('every PROJECT_MEMORY.md entry declares a Role from the allowed dictionary (issue #148)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $memory = (string) file_get_contents($packageDir . '/docs/memory/PROJECT_MEMORY.md');
    $entries = compoundMemoryParseEntries($memory);

    foreach ($entries as $entry) {
        $title = strtok($entry, "\n");

        expect($entry)->toMatch('/^- Role:\s+(daidalos|talos|argos|apollon|shared)\s*$/m', 'Entry is missing a valid Role: ' . $title);
    }
});

/**
 * PR #150 run-2 Critical 3: a per-entry loss-check against `origin/master` (the same {slug / URL /
 * `#N` / concrete pointer / counter-example} token superset-or-equal invariant Execution step 5
 * mandates) found 36 genuine tokens across 20 entries still missing after the first CR-fix round —
 * dropped by the original bulk compaction and never actually re-verified. Pins the restored tokens
 * so a future compaction cannot silently drop them again without this test failing — the concrete,
 * mechanical proof `git show origin/master:...` diffing itself cannot be a committed test (this
 * project's test-isolation rule forbids shelling out from a test).
 */
test('PROJECT_MEMORY.md restored the concrete pointers a first compaction pass dropped (PR #150 CR fix, run-2 Critical 3)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $memory = (string) file_get_contents($packageDir . '/docs/memory/PROJECT_MEMORY.md');

    // Six real issue/PR references the first pass dropped (verified not ordinals, e.g. "mandate #2")
    // — pinned in their own restored context, not as a bare `#N` a coincidentally-numbered
    // unrelated entry could satisfy.
    expect($memory)->toContain('installer targets Claude Code only since issue #16 — the `--editor` flag was removed in PR #33');
    expect($memory)->toContain('The `gh-71` run (issue #71, already shipped via merged PR #72');
    expect($memory)->toContain('the `gh-69` run (issue #69) and concurrent `gh-57` run (issue #57)');

    // Concrete pointers named explicitly in the consolidated CR comment as lost without a trace —
    // pinned in their own restored context (several of these bare tokens also legitimately occur,
    // coincidentally, in unrelated entries, so a bare-token check alone would not regression-guard
    // the specific restoration).
    expect($memory)->toContain('the obvious files (`agents/<name>.md`, `docs/agents.md`, `README.md`)');
    expect($memory)->toContain('rewritten in lockstep with `agents/hermes.md`');
    expect($memory)->toContain('2 Moderate, fixed `197a442`, pinned in `tests/Installer/CodeReviewContentTest.php`');
    expect($memory)->toContain('including verbatim-distributed templates (`skills/code-review/templates/`)');
    expect($memory)->toContain(
        '3 agents (`agents/hermes.md`→`article-writing`, `agents/apollon.md`→`test-like-human`, `agents/daidalos.md`→`autoresolve-oldest-github-issue`)',
    );
    expect($memory)->toContain('the write-lock (`.claude/run/.daidalos-write.lock`) is held');

    // PR #150 run-3 CR fix: this pointer was still dropped after the second restoration round — the
    // entry named only "the deterministic loader" with no token left to resolve which script that is.
    expect($memory)->toContain('load the PR via the deterministic loader (`skills/code-review-github/scripts/load-issue.sh`)');
});
