<?php

declare(strict_types = 1);

const TRIAGE_CREATED_ISSUES_SECTION = '## Triage every GitHub issue an agent creates, right after it is created';

function triageCreatedIssuesSource(string $relativePath): string
{
    return (string) preg_replace('/\s+/', ' ', (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath));
}

function triageCreatedIssuesRule(): string
{
    $rule = (string) file_get_contents(dirname(__DIR__, 2) . '/rules/compound-engineering/tracker.md');
    $start = strpos($rule, TRIAGE_CREATED_ISSUES_SECTION);
    assert($start !== false);

    return (string) preg_replace('/\s+/', ' ', substr($rule, $start));
}

test('the tracker rule owns the triage of a created issue once, and runs the script limited to the named issues', function (): void {
    $rule = triageCreatedIssuesRule();
    $tracker = (string) file_get_contents(dirname(__DIR__, 2) . '/rules/compound-engineering/tracker.md');

    expect(substr_count($tracker, TRIAGE_CREATED_ISSUES_SECTION))->toBe(1)
        ->and($rule)->toContain('This section is the single owner of that rule.')
        ->and($rule)->toContain('Exactly the GitHub issues this run created itself, named by the URLs its create calls returned.')
        ->and($rule)->toContain('an issue the run only reused or found, or a JIRA issue.')
        ->and($rule)->toContain('A human who creates an issue by hand keeps the manual triage.')
        ->and($rule)->toContain('Triage those issues only, with the same rules as a manual run:')
        ->and($rule)->toContain('Run `skills/github-issue-triage/scripts/assign-priorities.sh --issue <number>` once, with one `--issue` for each')
        ->and($rule)->toContain('writes its audit line for each label write through `audit-trail.sh run`. It never assigns `priority: critical`.')
        ->and($rule)->toContain('`skills/github-issue-triage/scripts/derive-effort.sh` and writes exactly one `effort:` label in the same write')
        ->and($rule)->toContain('Never run the triage script without `--issue` for this.')
        ->and($rule)->toContain('drops a derived label the repository lacks, names it in its output, and still writes the labels that exist.')
        ->and($rule)->not->toContain('gh issue edit <URL>')
        ->and($rule)->toContain('A withheld item is never triaged.')
        ->and($rule)->toContain('A failed triage never fails the creation.')
        ->and($rule)->toContain('The final report names each untriaged issue with its URL and the reason')
        ->and(triageCreatedIssuesSource('rules/compound-engineering/general.md'))
        ->toContain('and *Triage every GitHub issue an agent creates, right after it is created*.');
});

test('the triage write of a created issue is audited and the consent table carries a row for it', function (): void {
    $rule = triageCreatedIssuesRule();
    $orchestration = triageCreatedIssuesSource('rules/compound-engineering/orchestration.md');
    $row = '| any agent, or the top-level session | Add the triage labels (type, priority, and effort) '
        . 'to a GitHub issue that this same run just created | L1 |';

    expect($rule)->toContain('`AUDIT_TRAIL_FILE=<absolute path of the .audit file> AUDIT_TRAIL_ROLE=<role>`')
        ->and($orchestration)->toContain($row)
        ->and($orchestration)->toContain('which records its own audit line, and a failed write never fails the creation |')
        ->and($orchestration)->toContain(
            '`assign-priorities.sh` writes its own line when `AUDIT_TRAIL_FILE` is set, through `audit-trail.sh run`, so the agent appends none.',
        )
        ->and($orchestration)->not->toContain('(`seed-labels.sh`, `assign-priorities.sh`)')
        ->and($orchestration)->toContain('never `priority: critical`, and never a backlog-wide labelling.');
});

test('every path that creates a GitHub issue points to the one owner and triages only what it created', function (): void {
    $pointer = '`@rules/compound-engineering/tracker.md` *Triage every GitHub issue an agent creates, right after it is created*';
    $paths = [
        'skills/create-issue/SKILL.md',
        'skills/create-issues-from-text/SKILL.md',
        'skills/do-it/SKILL.md',
        'skills/resolve-issue/references/deferred-follow-up.md',
        'skills/process-code-review/references/round-three-deferral.md',
        'skills/github-release-roadmap/references/github-cli-workflow.md',
        'skills/github-issue-triage/SKILL.md',
        'agents/leonardo.md',
    ];

    foreach ($paths as $path) {
        expect(triageCreatedIssuesSource($path))->toContain($pointer);
    }

    expect(triageCreatedIssuesSource('skills/create-issue/SKILL.md'))
        ->toContain('triage the created issue, and only that issue');
    expect(triageCreatedIssuesSource('skills/create-issues-from-text/SKILL.md'))
        ->toContain('triage every issue this run created — flat breakdown, EPIC parent, and sub-issues alike — and no other')
        ->toContain('A withheld item has no issue and is not triaged');
    expect(triageCreatedIssuesSource('skills/resolve-issue/references/deferred-follow-up.md'))
        ->toContain('a failed triage never fails the filing');
    expect(triageCreatedIssuesSource('skills/process-code-review/references/round-three-deferral.md'))
        ->toContain('A failed triage never reverts a deferral that landed');
});

test('do-it records the triage that create-issues-from-text reported and never triages a second time', function (): void {
    $skill = triageCreatedIssuesSource('skills/do-it/SKILL.md');

    expect($skill)->toContain('Record the triage result that `@skills/create-issues-from-text/SKILL.md` reported for each issue that step 2 created.')
        ->and($skill)->toContain('so this step runs no second triage.')
        ->and($skill)->toContain('A failed triage never makes the item `Blocked`: the item continues, and the report names the untriaged issue.')
        ->and($skill)->not->toContain('The run applies no priority label and runs no labelling script.')
        ->and($skill)->toContain('A created issue also shows its triage labels, or `untriaged` with the reason');
});

test('the agents that write the triage labels name the audited script write and the created-issue limit', function (): void {
    foreach (['krang', 'leonardo', 'donatello'] as $agent) {
        $source = triageCreatedIssuesSource('agents/' . $agent . '.md');
        $call = 'AUDIT_TRAIL_ROLE=' . $agent . ' skills/github-issue-triage/scripts/assign-priorities.sh --issue <number>`, never without `--issue`';

        expect($source)->toContain('the triage write of `@rules/compound-engineering/tracker.md` *Triage every GitHub issue an agent creates')
            ->and($source)->toContain('issue this run created')
            ->and($source)->toContain($call);
    }
});

test('the triage skill sends an agent-created issue to the owner rule and the EPIC flow triages only a parent it created', function (): void {
    $skill = triageCreatedIssuesSource('skills/github-issue-triage/SKILL.md');
    $tracker = triageCreatedIssuesSource('rules/compound-engineering/tracker.md');

    expect($skill)->toContain('After a human opens an issue by hand, run step 3 again.')
        ->and($skill)->toContain('it runs `scripts/assign-priorities.sh --issue <number>`, never step 3 over the backlog.')
        ->and($skill)->not->toContain('After opening an issue (`@skills/create-issue/SKILL.md`')
        ->and($skill)->toContain('A single issue is triaged with `scripts/assign-priorities.sh --issue <number>`')
        ->and($tracker)->not->toContain('a brand-new issue cannot yet carry');
    expect(triageCreatedIssuesSource('skills/create-issues-from-text/SKILL.md'))
        ->toContain('Triage the parent only when step 1 created it, and every sub-issue')
        ->toContain('An existing tracker item that became the parent is not triaged.');
});

test('assign-priorities.sh limits the walk to the issues named with --issue and records its own writes', function (): void {
    $script = triageCreatedIssuesSource('skills/github-issue-triage/scripts/assign-priorities.sh');

    expect($script)->toContain('assign-priorities.sh [--dry-run] [--issue <number>]...')
        ->and($script)->toContain('--issue needs an issue number')
        ->and($script)->toContain('DERIVE_EFFORT="$(cd')
        ->and($script)->toContain('trap \'rm -f "$ISSUES_JSON" "$NAMED_JSON" "$EFFORT_FILE"\' EXIT')
        ->and($script)->not->toContain('effort_file="$(mktemp)"')
        ->and($script)->toContain('gh label list --limit 1000 --json name')
        ->and($script)->toContain('write=(bash "$AUDIT_TRAIL" run -- "${write[@]}")')
        ->and($script)->not->toContain('bash "$AUDIT_TRAIL" record --target "gh issue edit')
        ->and($script)->toContain('e2e \'--issue — a repository without the effort labels still gets the type and the priority\'')
        ->and($script)->toContain('e2e \'--issue — a repository without any derived label gets no write and every label is named\'')
        ->and($script)->toContain('e2e \'--issue — a failed effort derivation is named and the effort label stays\'')
        ->and($script)->toContain('e2e \'--issue — the derived effort replaces an earlier effort label\'')
        ->and($script)->toContain('e2e \'--issue — only the named issue is written\'')
        ->and($script)->toContain('e2e \'--issue — an uppercase prefix derives no type\'')
        ->and($script)->toContain('e2e \'--issue — an existing type label wins over the body\'');
});
