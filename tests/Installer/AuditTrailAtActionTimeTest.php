<?php

declare(strict_types = 1);

function auditTrailSource(string $relativePath): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
}

test('the audit-trail helper is an executable bash script with a --self-test entry point that the build runs once', function (): void {
    $helper = dirname(__DIR__, 2) . '/skills/_shared/audit-trail.sh';

    expect(is_executable($helper))->toBeTrue()
        ->and(auditTrailSource('skills/_shared/audit-trail.sh'))
        ->toStartWith('#!/usr/bin/env bash')
        ->toContain('--self-test) self_test ;;');

    /** @var array{scripts: array<string, array<int, string>>} $composer */
    $composer = json_decode(auditTrailSource('composer.json'), associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);

    expect(substr_count(implode(' ', $composer['scripts']['shell-self-tests']), ' skills/_shared/audit-trail.sh '))->toBe(1);
});

test('the helper writes the line shape of the rule: role first, UTC time, class, target, result', function (): void {
    $source = auditTrailSource('skills/_shared/audit-trail.sh');
    expect($source)
        ->toContain('line="${role}|$(now_utc)|${class}|${target}|${result}"')
        ->toContain('date -u +%Y-%m-%dT%H:%M:%SZ')
        ->toContain('CLASSES=\'memory-read outbound-request external-write note\'');
    expect($source)->toContain('until mkdir "${file}.lock"');
});

test('the helper takes the exit status from the write command itself, never from a pipe stage', function (): void {
    $source = auditTrailSource('skills/_shared/audit-trail.sh');

    expect($source)
        ->toContain('"$@" >"$out" || rc=$?')
        ->toContain('return "$rc"');
    expect($source)->not->toContain('| tail');
    expect($source)
        ->toContain('append_line "$FILE" "$ROLE" note "no action performed"')
        ->toContain('failed (exit $status)');
});

test('run accepts exactly the write commands the rule names and refuses every other command', function (): void {
    $source = auditTrailSource('skills/_shared/audit-trail.sh');
    $rule = auditTrailSource('rules/compound-engineering/orchestration.md');
    $section = installerDocsSection($rule, '## Audit trail for memory reads, outbound requests, and external writes');

    foreach ([
        '" create ready edit close merge update-branch reopen comment review "',
        '" create edit close reopen comment "',
        '" create edit delete "',
        '"git push")',
    ] as $allowed) {
        expect($source)->toContain($allowed);
    }

    foreach ([
        '`git push`',
        '`gh pr create|ready|edit|close|merge|update-branch|reopen|comment|review`',
        '`gh issue create|edit|close|reopen|comment`',
        '`gh label create|edit|delete`',
    ] as $named) {
        expect($section)->toContain($named);
    }

    expect($source)->toContain('return 64');
});

test('the rule makes the package write the line at the moment of the write and names what stays manual', function (): void {
    $rule = auditTrailSource('rules/compound-engineering/orchestration.md');
    $section = installerDocsSection($rule, '## Audit trail for memory reads, outbound requests, and external writes');

    expect($section)->not->toContain('exports both once');
    expect($section)
        ->toContain('Record the line at the moment of the write')
        ->toContain('`AUDIT_TRAIL_FILE`')
        ->toContain('`AUDIT_TRAIL_ROLE`')
        ->toContain('Every Bash call is a new shell')
        ->toContain('`AUDIT_TRAIL_FILE=<absolute path> AUDIT_TRAIL_ROLE=<role> <wrapper or audit-trail.sh run -- command>`')
        ->toContain('`<target>: write failed (exit N)`')
        ->toContain('`--receive-pack`, `--exec`, `--upload-pack`')
        ->toContain('audit-trail.sh run -- <command>')
        ->toContain('The acting role as the **first** field')
        ->toContain('the **real exit status of the write command**')
        ->toContain('never the status of a `tail` or another pipe stage')
        ->toContain('A failed attempt writes a `note` line')
        ->toContain('A wrapper never changes its interface')
        ->toContain('What stays manual')
        ->toContain('`seed-labels.sh`, `assign-priorities.sh`')
        ->toContain('audit-trail.sh verify --file <.audit> --branch <branch> --pr <PR>')
        ->toContain('check-handoff.sh --audit-file <.audit>');
});

dataset('audit wrappers', [
    'bugsnag comment publisher' => ['upsert-comment.sh', 'skills/code-review-bugsnag/scripts/upsert-comment.sh'],
    'deferred moderate filing' => ['file-deferred-moderate.sh', 'skills/process-code-review/scripts/file-deferred-moderate.sh'],
    'github comment delete' => ['delete-owned-github-comment.sh', 'skills/_shared/delete-owned-github-comment.sh'],
    'github comment publisher' => ['upsert-comment.sh', 'skills/code-review-github/scripts/upsert-comment.sh'],
    'jira code-review transition' => ['transition-to-code-review.sh', 'skills/code-review-jira/scripts/transition-to-code-review.sh'],
    'jira comment delete' => ['delete-owned-comment.sh', 'skills/code-review-jira/scripts/delete-owned-comment.sh'],
    'jira comment publisher' => ['upsert-comment.sh', 'skills/code-review-jira/scripts/upsert-comment.sh'],
    'jira in-progress transition' => ['transition-to-in-progress.sh', 'skills/code-review-jira/scripts/transition-to-in-progress.sh'],
]);

test('the rule names each wrapper and the wrapper calls the helper at its write', function (string $name, string $path): void {
    $rule = auditTrailSource('rules/compound-engineering/orchestration.md');
    $section = installerDocsSection($rule, '## Audit trail for memory reads, outbound requests, and external writes');
    $source = auditTrailSource($path);

    expect($section)->toContain('`' . $name . '`')
        ->and($source)->toContain('audit-trail.sh')
        ->and($source)->toContain(' record ')
        ->and($source)->toContain('--status');
})->with('audit wrappers');

test('a wrapper never lets a missing ledger block or change the write', function (string $name, string $path): void {
    expect($name)->toEndWith('.sh');

    preg_match_all('/(?:audit-trail\.sh"|"\$AUDIT_TRAIL") record[^\n]*/', auditTrailSource($path), $calls);

    expect($calls[0])->not->toBe([])
        ->and(array_filter($calls[0], static fn (string $call): bool => !str_ends_with($call, '|| true')))->toBe([]);
})->with('audit wrappers');

test('each wrapper captures the status of the write before it writes the audit line', function (): void {
    expect(auditTrailSource('skills/code-review-github/scripts/upsert-comment.sh'))
        ->toContain('--input -)" || WRITE_RC=$?')
        ->toContain('record --target "$NEW_URL" --status 0');
    expect(auditTrailSource('skills/code-review-jira/scripts/transition-to-in-progress.sh'))
        ->toContain('|| TRANSITION_RC=$?')
        ->toContain('|| assign_rc=$?');
    expect(auditTrailSource('skills/code-review-bugsnag/scripts/upsert-comment.sh'))->toContain('exit "$POST_RC"');
    expect(auditTrailSource('skills/_shared/delete-owned-github-comment.sh'))->toContain('[[ "$DELETE_RC" -eq 0 ]] || exit "$DELETE_RC"');
});

test('check-handoff checks the ledger through the helper before the code review sees it', function (): void {
    $source = auditTrailSource('skills/_shared/check-handoff.sh');

    expect($source)
        ->toContain('--audit-file)')
        ->toContain('--audit-branch)')
        ->toContain('--audit-pr)')
        ->toContain('audit-trail.sh" "${audit_args[@]}"')
        ->toContain('\'problem: \'*) problems+=("audit trail: ${audit_line#problem: }")')
        ->toContain('a malformed audit trail line is a handoff problem')
        ->toContain('\'not verified: \'*) problems+=("audit trail not verified: ${audit_line#not verified: }")')
        ->toContain('0 | 3) ;;');
});

test('donatello and krang name the helper in their Bash boundary and run the check before the hand-off', function (): void {
    $donatello = auditTrailSource('agents/donatello.md');
    $krang = auditTrailSource('agents/krang.md');

    expect($donatello)
        ->toContain('`skills/_shared/audit-trail.sh run -- <write command>`')
        ->toContain('Before the hand-off run `skills/_shared/audit-trail.sh verify --file')
        ->toContain('*Record the line at the moment of the write*')
        ->toContain('`AUDIT_TRAIL_FILE=<absolute path of the .audit file> AUDIT_TRAIL_ROLE=donatello`');
    expect($donatello)->not->toContain('exported: it appends');

    expect($krang)
        ->toContain('`skills/_shared/audit-trail.sh run -- <write command>`')
        ->toContain('`AUDIT_TRAIL_FILE=<abs .audit path> AUDIT_TRAIL_ROLE=krang`')
        ->toContain('run `skills/_shared/audit-trail.sh` (`run` only for the `gh` writes above');
});

test('the changelog records the audit line written at the moment of the write', function (): void {
    $changelog = auditTrailSource('CHANGELOG.md');
    $unreleased = substr($changelog, (int) strpos($changelog, '## [Unreleased]'));
    $unreleased = substr($unreleased, 0, (int) strpos($unreleased, "\n## [", 1));

    expect($unreleased)->toContain('(#376)')
        ->and($unreleased)->toContain('audit-trail.sh');
});

test('leonardo runs gh pr ready and the label writes through the helper and appends no hand-written line for them', function (): void {
    $leonardo = auditTrailSource('agents/leonardo.md');
    $skill = auditTrailSource('skills/process-code-review/SKILL.md');
    $signal = auditTrailSource('skills/process-code-review/references/ready-to-merge-signal.md');
    $step = substr($leonardo, (int) strpos($leonardo, '12. **Append your own audit-trail lines.**'));
    $step = substr($step, 0, (int) strpos($step, "\n", 1));

    expect($leonardo)
        ->toContain('`AUDIT_TRAIL_FILE=<abs .audit path> AUDIT_TRAIL_ROLE=leonardo skills/_shared/audit-trail.sh run -- gh pr ready <PR>`')
        ->toContain('each only as `skills/_shared/audit-trail.sh run -- <command>`');
    expect($step)
        ->toContain('Each external write leaves exactly one line, written at the moment of the write by the package and never by hand')
        ->toContain('Append by hand');
    expect($step)->not->toContain('append one line per action you actually performed');
    expect($skill)->toContain(
        '`AUDIT_TRAIL_FILE=<abs .audit path> AUDIT_TRAIL_ROLE=leonardo skills/_shared/audit-trail.sh run -- gh pr ready <PR-NUMBER|URL>`',
    );
    expect($signal)->toContain('`AUDIT_TRAIL_FILE=<abs .audit path> AUDIT_TRAIL_ROLE=leonardo skills/_shared/audit-trail.sh run -- <command>`');
});

test('krang.md stays under the size limit with the per-call ledger form', function (): void {
    expect(strlen(auditTrailSource('agents/krang.md')))->toBeLessThanOrEqual(135_000);
});
