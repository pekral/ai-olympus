<?php

declare(strict_types = 1);

// The triage skill turns the repository's already-established label taxonomy
// into executable scripts. The taxonomy is the contract: a wrong color or a
// renamed label silently rewrites live labels on every repository that runs
// the seed script, so the table is pinned here value by value.
//
// Per the project's test-isolation rule a Pest test cannot exec a real .sh,
// so the behavioural proof lives in the script's own `--self-test`, which
// `composer build` runs via `@shell-self-tests` (the precedent is
// `skills/_shared/assert-current-repo.sh`): it runs the script end-to-end
// against a stubbed `gh` and asserts the add / remove / keep decisions and the
// exact `gh issue edit` argv. These guards pin the scenarios that self-test is
// required to keep covering, so the behavioural suite cannot be gutted without
// a failing test here.

test('label seed script is shipped, executable, and never deletes a label', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $script = $packageDir . '/skills/github-issue-triage/scripts/seed-labels.sh';

    expect(is_file($script))->toBeTrue();
    expect(is_executable($script))->toBeTrue();

    $content = (string) file_get_contents($script);
    expect($content)->toStartWith('#!/usr/bin/env bash');
    expect($content)->toContain('set -euo pipefail');

    // Idempotency: --force updates an existing label instead of failing.
    expect($content)->toContain('--force');

    // The seed step is additive only — deleting a label would destroy the
    // labelling of every issue that carries it.
    expect($content)->not->toContain('gh label delete');
});

test('label seed script pins the exact priority and type taxonomy', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/skills/github-issue-triage/scripts/seed-labels.sh');

    $taxonomy = [
        'priority: critical' => 'b60205',
        'priority: high' => 'd93f0b',
        'priority: medium' => 'fbca04',
        'priority: low' => '0e8a16',
        'bug' => 'd73a4a',
        'enhancement' => 'a2eeef',
        'documentation' => '0075ca',
        'question' => 'd876e3',
        'test' => 'bfd4f2',
        'refactor' => 'c5def5',
        'chore' => 'cfd3d7',
        'security' => 'ee0701',
        'marketing' => 'f9d0c4',
        'plan' => '5319e7',
    ];

    foreach ($taxonomy as $name => $color) {
        expect($content)->toContain('"' . $name . '|' . $color . '|');
    }

    // Nothing beyond the taxonomy is seeded: 14 entries, no more.
    expect(substr_count($content, '"priority: '))->toBe(4);
});

test('triage script is shipped, executable, and offers dry-run plus self-test', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $script = $packageDir . '/skills/github-issue-triage/scripts/assign-priorities.sh';

    expect(is_file($script))->toBeTrue();
    expect(is_executable($script))->toBeTrue();

    $content = (string) file_get_contents($script);
    expect($content)->toStartWith('#!/usr/bin/env bash');
    expect($content)->toContain('set -euo pipefail');
    expect($content)->toContain('--dry-run');
    expect($content)->toContain('--self-test');

    // Only OPEN issues are triaged.
    expect($content)->toContain('--state open');

    // A backlog that fills the listing page is reported, never truncated in
    // silence behind a summary that reads like the whole backlog.
    expect($content)->toContain('the listing hit its page limit of $OPEN_ISSUE_LIMIT open issues');
});

test('triage self-test covers every derivation scenario the taxonomy defines', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/skills/github-issue-triage/scripts/assign-priorities.sh');

    // Each spelling below occurs on a real open issue of this repository, so a
    // derivation change that breaks one of them would mislabel live issues.
    $requiredCases = [
        '[Bug]: ',
        '[Feature]: ',
        'fix(agents): ',
        'fix: ',
        'fix(installer)!: ',
        'feat(installer): ',
        'docs(readme): ',
        'test(security): ',
        'refactor(installer): ',
        'chore(release): ',
        'marketing: ',
        'PLAN (#185): ',
    ];

    foreach ($requiredCases as $case) {
        expect($content)->toContain($case);
    }

    // The conventional-commits scope must not become the type: a
    // `test(security):` issue is a test, not a security issue.
    expect($content)->toContain('test(security): bind getSensitivePathPatterns() to its prose|test|priority: low');

    // A title with no recognized prefix is skipped, never guessed at.
    expect($content)->toContain('perf(installer): speed up the copy loop|-|-');
});

test('triage script never derives priority: critical and never downgrades it', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/skills/github-issue-triage/scripts/assign-priorities.sh');

    // Only two mappings produce a non-low priority; critical is human-only.
    expect($content)->toContain('bug) printf \'priority: high\' ;;');
    expect($content)->toContain('enhancement) printf \'priority: medium\' ;;');
    expect($content)->not->toContain('printf \'priority: critical\'');

    // An issue a human escalated to critical is left alone. The guard itself is
    // proven by the end-to-end case pinned below, not by these two strings.
    expect($content)->toContain('has_critical=true');
    expect($content)->toContain('"$has_critical" == false');
    expect($content)->toContain('keep — priority: critical survives a title-derived bug');
});

test('the add, remove and keep decisions are proven by running the script, not by reading it', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/skills/github-issue-triage/scripts/assign-priorities.sh');

    // The removal branch is the only place in the package that takes a label
    // away from an issue, so the self-test must exercise it for real: it stubs
    // `gh`, runs this very script, and asserts the recorded `gh issue edit`
    // argv — a source-string assertion cannot tell a live branch from a dead
    // one.
    expect($content)->toContain('"$script" $mode 2>&1');
    expect($content)->toContain('GH_STUB_EDITS');
    expect($content)->toContain('gh issue edit was');

    // One case per outcome of the decision tree, plus the dry-run guarantee.
    $requiredCases = [
        'add — untriaged issue',
        'add — dry-run writes nothing',
        'keep — already triaged issue is a no-op',
        'remove — title prefix corrects a wrong type and priority',
        'keep — priority: critical survives a title-derived bug',
        'remove — security and question topics are never removed',
        'add — body form labels an unlabelled issue',
        'skip — body never overrides a human type label',
        'skip — a body matching both forms is ambiguous',
        'add — a prose mention of a form phrase does not flip the type',
        'skip — an unanchored form phrase derives no type',
        'summary — every bucket is counted separately',
    ];

    foreach ($requiredCases as $case) {
        expect($content)->toContain($case);
    }

    // The exact writes the cases assert: the add-only, the add-plus-remove, and
    // the "no write at all" outcomes.
    expect($content)->toContain('11|--add-label|bug|--add-label|priority: high');
    expect($content)->toContain('13|--add-label|bug|--add-label|priority: high|--remove-label|enhancement|--remove-label|priority: medium');
    expect($content)->toContain('15|--add-label|bug|--remove-label|enhancement');
});

test('body derivation is anchored to the issue-form headings and reports an ambiguous body', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/skills/github-issue-triage/scripts/assign-priorities.sh');

    // Anchored at the start of a line: a form marker quoted in the prose of a
    // feature request must not turn the issue into a bug.
    expect($content)->toContain('### Expected behavior');
    expect($content)->toContain('### What problem does this solve?');
    expect($content)->toContain('body_has_any_heading');

    // Matching both forms is a conflict for a human, not a first-match win.
    expect($content)->toContain('the body matches both the bug and the feature form');

    $skill = (string) file_get_contents($packageDir . '/skills/github-issue-triage/SKILL.md');
    expect($skill)->toContain('| `### Expected behavior` / `### Actual behavior` | `bug` |');
    expect($skill)->toContain('an ambiguous body is a conflict for a human');
});

test('both scripts name the repository they write to before the first write', function (): void {
    $packageDir = dirname(__DIR__, 2);

    // The scripts resolve their target implicitly from the working directory
    // and ship verbatim into foreign checkouts, so an unnamed target makes a
    // label write — including the only label removal in the package —
    // unauditable after the fact. The precedent is `assert-current-repo.sh`,
    // which prints the nameWithOwner it vouched for.
    foreach (['seed-labels.sh', 'assign-priorities.sh'] as $name) {
        $content = (string) file_get_contents($packageDir . '/skills/github-issue-triage/scripts/' . $name);

        expect($content)->toContain('gh repo view --json nameWithOwner --jq \'.nameWithOwner\'');
        expect($content)->toContain('target repository: $repo_nwo');
        expect($content)->toContain('failed to resolve the target repository');
    }

    $skill = (string) file_get_contents($packageDir . '/skills/github-issue-triage/SKILL.md');
    expect($skill)->toContain('target repository: <owner>/<repo>');
});

test('triage script treats security and question as topic labels it never removes', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/skills/github-issue-triage/scripts/assign-priorities.sh');

    expect($content)->toContain('PRIMARY_TYPES="bug enhancement documentation test refactor chore marketing plan"');

    // Removal is allowed only when an explicit title prefix proved the type.
    expect($content)->toContain('$type_source" == \'title\'');
    expect($content)->not->toContain('--delete-label');
});

test('triage script never overrides a human type label with a body guess', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/skills/github-issue-triage/scripts/assign-priorities.sh');

    // The body fallback may add a type to an unlabelled issue, but pinning a
    // second primary type onto an already-classified one is a data-quality
    // regression — the conflict is reported for a human instead.
    expect($content)->toContain('$type_source" == \'body\' && -n "$current_types"');
    expect($content)->toContain('but the issue is labelled');
});

test('both triage scripts report gh failures instead of suppressing them', function (): void {
    $packageDir = dirname(__DIR__, 2);

    foreach (['seed-labels.sh', 'assign-priorities.sh'] as $name) {
        $content = (string) file_get_contents($packageDir . '/skills/github-issue-triage/scripts/' . $name);

        // A silenced gh failure hides a rejected write behind a generic
        // message; stderr is captured and echoed with the failure instead.
        expect($content)->toContain('gh_error');

        // Output is silenced only on the `command -v` tool probes, where a
        // non-zero exit is the answer being asked for, never on a gh call.
        foreach (explode("\n", $content) as $line) {
            if (!str_contains($line, '>/dev/null 2>&1')) {
                continue;
            }

            expect($line)->toContain('command -v');
        }
    }
});

test('triage skill documents the taxonomy, the derivation rules, and both scripts', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $skill = $packageDir . '/skills/github-issue-triage/SKILL.md';

    expect(is_file($skill))->toBeTrue();

    $content = (string) file_get_contents($skill);
    expect($content)->toContain('name: github-issue-triage');

    // The four priority labels and the four derivation rules, in order.
    foreach (['priority: critical', 'priority: high', 'priority: medium', 'priority: low'] as $priority) {
        expect($content)->toContain($priority);
    }

    expect($content)->toContain('`priority: critical`. Neither condition is derivable from an issue title');
    expect($content)->toContain('scripts/seed-labels.sh');
    expect($content)->toContain('scripts/assign-priorities.sh --dry-run');
});

test('triage skill documents how to sort work by priority and why labels beat Projects v2', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/skills/github-issue-triage/SKILL.md');

    expect($content)->toContain('gh issue list --state open --label "priority: critical"');

    // The Projects v2 priority field needs an OAuth scope this repository has
    // no token for — the reason the whole system is label-based.
    expect($content)->toContain('gh auth refresh -s project');
});

test('triage skill references only skills that exist', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/skills/github-issue-triage/SKILL.md');

    expect(preg_match_all('#@skills/([a-z0-9-]+)/SKILL\.md#', $content, $matches))->toBeGreaterThan(0);

    foreach ($matches[1] as $slug) {
        expect(is_file($packageDir . '/skills/' . $slug . '/SKILL.md'))->toBeTrue();
    }
});

test('triage self-test runs as part of the project build', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $composer = (array) json_decode((string) file_get_contents($packageDir . '/composer.json'), associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);

    /** @var array<string, array<int, string>> $scripts */
    $scripts = $composer['scripts'];

    expect($scripts['shell-self-tests'])->toContain('bash skills/github-issue-triage/scripts/assign-priorities.sh --self-test');
    expect($scripts['check'])->toContain('@shell-self-tests');
});
