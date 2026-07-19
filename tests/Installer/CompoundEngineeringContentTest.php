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

test('resolve-issue skill anchors phase planning on the one-phase-one-commit git rule', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/skills/resolve-issue/SKILL.md');

    expect($content)->toContain('one phase = one commit');
    expect($content)->toContain('@rules/git/general.mdc');
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
    $talos = (string) file_get_contents($packageDir . '/agents/talos.md');

    expect($rule)->toContain('## Blocked delegation is a hard stop');
    expect(substr_count($rule, '## Blocked delegation is a hard stop'))->toBe(1);
    expect($talos)->toContain('*Blocked delegation is a hard stop*');
});

test('compound memory reads are hooked into the context phases (issue #626)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $analyze = (string) file_get_contents($packageDir . '/skills/analyze-problem/SKILL.md');
    $prepare = (string) file_get_contents($packageDir . '/skills/prepare-issue-context/SKILL.md');

    expect($analyze)->toContain('docs/memory/PROJECT_MEMORY.md');
    expect($prepare)->toContain('docs/memory/PROJECT_MEMORY.md');
});

test('compound memory write mechanism is removed (issue #77)', function (): void {
    $packageDir = dirname(__DIR__, 2);

    // The write-side skill is gone entirely.
    expect(is_dir($packageDir . '/skills/record-project-memory'))->toBeFalse();

    // No former write hook still references the removed skill.
    $rule = (string) file_get_contents($packageDir . '/rules/compound-engineering/general.mdc');
    $resolveIssue = (string) file_get_contents($packageDir . '/skills/resolve-issue/SKILL.md');
    $processCr = (string) file_get_contents($packageDir . '/skills/process-code-review/SKILL.md');

    expect($rule)->not->toContain('record-project-memory');
    expect($resolveIssue)->not->toContain('record-project-memory');
    expect($processCr)->not->toContain('record-project-memory');

    // The write-protocol sections of the rule are gone; the read side stays.
    expect($rule)->not->toContain('### Write protocol');
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

    // The rule must name the orchestrating harness as the reference implementation of the cleanup.
    expect($content)->toContain('orchestrating harness');
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

    // resolve-issue carries the per-tracker mechanics and the no-URL-no-done guarantee.
    $resolveIssue = (string) file_get_contents($packageDir . '/skills/resolve-issue/SKILL.md');
    expect($resolveIssue)->toContain('### Deferred-item follow-up issues');
    expect($resolveIssue)->toContain('never report the deferral as handled without a live issue URL');

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
    $resolveIssue = (string) file_get_contents($packageDir . '/skills/resolve-issue/SKILL.md');

    expect($createIssue)->toContain('Label newly created tracker issues');
    expect($createIssuesFromText)->toContain('Label newly created tracker issues');
    expect($resolveIssue)->toContain('Label newly created tracker issues');

    // Additive-only: the pre-existing EPIC structural-label mechanism stays untouched.
    expect($createIssuesFromText)->toContain('EPIC parent & sub-issues');
    expect($createIssuesFromText)->toContain('gh label create EPIC');
});
