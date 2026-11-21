<?php

declare(strict_types = 1);

use Symfony\Component\Process\Process;

// `audit-trail.sh verify` shows, from the ledger alone, which label writes belong to an issue the run
// created. The ledger is only read: no line is rewritten, and no write becomes allowed or refused.

const AUDIT_LINK_CREATED = 'donatello|2026-11-11T10:00:00Z|external-write|gh issue create -> https://example.test/o/r/issues/7|ok (exit 0)';

const AUDIT_LINK_TRIAGE = 'donatello|2026-11-11T10:01:00Z|external-write|'
    . 'gh issue edit 7 +label bug +label priority: medium -> https://example.test/o/r/issues/7|ok (exit 0)';

const AUDIT_LINK_OTHER = 'donatello|2026-11-11T10:02:00Z|external-write|'
    . 'gh issue edit 9 +label stale -> https://example.test/o/r/issues/9|ok (exit 0)';

/**
 * @param list<string> $lines
 * @return array{exitCode: int|null, output: list<string>, unchanged: bool}
 */
function auditVerifyLedgerLines(array $lines): array
{
    $ledger = auditLedgerCreate();
    $content = implode("\n", $lines) . "\n";
    file_put_contents($ledger['file'], $content);

    try {
        $process = new Process([dirname(__DIR__, 3) . '/commands/_shared/audit-trail.sh', 'verify', '--file', $ledger['file']]);
        $process->run();

        return [
            'exitCode' => $process->getExitCode(),
            'output' => explode("\n", trim($process->getOutput())),
            'unchanged' => file_get_contents($ledger['file']) === $content,
        ];
    } finally {
        auditLedgerRemove($ledger);
    }
}

test('the label writes on an issue the run created are shown as part of its creation and triage', function (): void {
    $result = auditVerifyLedgerLines([AUDIT_LINK_CREATED, AUDIT_LINK_TRIAGE]);

    expect($result['exitCode'])->toBe(0)
        ->and($result['output'])->toBe([
            'linked: https://example.test/o/r/issues/7 was created by this run (line 1); '
                . 'label writes on line(s) 2 belong to its creation and triage: +label bug; +label priority: medium',
            'audit trail ok: 2 line(s)',
        ]);
});

test('a label write on an issue the run did not create is shown on its own', function (): void {
    $result = auditVerifyLedgerLines([AUDIT_LINK_CREATED, AUDIT_LINK_TRIAGE, AUDIT_LINK_OTHER]);

    expect($result['output'])->toContain(
        'standalone: label write on https://example.test/o/r/issues/9 (line 3) is not part of an issue this run created: +label stale',
    )->and(implode("\n", $result['output']))->not->toContain('issues/9 was created');
});

test('a label write on an issue is shown on its own when it comes before the issue creation', function (): void {
    $result = auditVerifyLedgerLines([AUDIT_LINK_TRIAGE, AUDIT_LINK_CREATED]);

    expect($result['output'])->toContain(
        'standalone: label write on https://example.test/o/r/issues/7 (line 1) '
            . 'is not part of an issue this run created: +label bug; +label priority: medium',
    )->and(implode("\n", $result['output']))->not->toContain('linked:');
});

test('a bare issue number links to no created issue, whatever repository it comes from', function (): void {
    $result = auditVerifyLedgerLines([
        AUDIT_LINK_CREATED,
        'donatello|2026-11-11T10:01:00Z|external-write|gh issue edit 7 +label bug|ok (exit 0)',
        'donatello|2026-11-11T10:02:00Z|external-write|gh issue edit 7 +label bug -> https://example.test/o/elsewhere/issues/7|ok (exit 0)',
    ]);

    expect($result['output'])->toContain(
        'standalone: label write on issue 7 (line 2) is not part of an issue this run created: +label bug',
    )->and(implode("\n", $result['output']))->not->toContain('linked:');
});

test('a created issue that has no label write prints no linked line', function (): void {
    expect(auditVerifyLedgerLines([AUDIT_LINK_CREATED])['output'])->toBe(['audit trail ok: 1 line(s)']);
});

test('the created issue is the URL that ends the creation line, never another URL in it', function (): void {
    $result = auditVerifyLedgerLines([
        'donatello|2026-11-11T10:00:00Z|external-write|'
            . 'gh issue create follow-up of https://example.test/o/r/issues/99 -> https://example.test/o/r/issues/100|ok (exit 0)',
        'donatello|2026-11-11T10:01:00Z|external-write|gh issue edit 99 +label wontfix -> https://example.test/o/r/issues/99|ok (exit 0)',
        'donatello|2026-11-11T10:02:00Z|external-write|gh issue edit 100 +label bug -> https://example.test/o/r/issues/100|ok (exit 0)',
    ]);

    expect($result['output'])->toContain(
        'standalone: label write on https://example.test/o/r/issues/99 (line 2) '
            . 'is not part of an issue this run created: +label wontfix',
    )->and(implode("\n", $result['output']))->toContain('linked: https://example.test/o/r/issues/100 was created by this run (line 1)')
        ->and(implode("\n", $result['output']))->not->toContain('issues/99 was created');
});

test('an edit that names two issues is shown on its own, also when only its output names the second', function (): void {
    $result = auditVerifyLedgerLines([
        AUDIT_LINK_CREATED,
        'donatello|2026-11-11T10:01:00Z|external-write|gh issue edit 7 3 +label bug -> https://example.test/o/r/issues/7|ok (exit 0)',
        'donatello|2026-11-11T10:02:00Z|external-write|'
            . 'gh issue edit 7 +label bug -> https://example.test/o/r/issues/7 https://example.test/o/r/issues/3|ok (exit 0)',
    ]);

    expect($result['output'])->toContain(
        'standalone: label write on issue 7, 3 (line 2) is not part of an issue this run created: +label bug',
    )->and($result['output'])->toContain(
        'standalone: label write on issue 7, https://example.test/o/r/issues/3 (line 3) '
            . 'is not part of an issue this run created: +label bug',
    )->and(implode("\n", $result['output']))->not->toContain('linked:');
});

test('a control character, a carriage return, or a tab in a label name never reaches the output', function (): void {
    $line = "donatello|2026-11-11T10:00:00Z|external-write|gh issue edit 9 +label x\033[2Jy\rz\tw -> https://example.test/o/r/issues/9|ok (exit 0)";
    $output = implode("\n", auditVerifyLedgerLines([$line])['output']);

    expect($output)->toContain('+label x[2Jy z w')
        ->and(preg_match('/[\x00-\x09\x0b-\x1f\x7f]/', $output))->toBe(0);
});

test('the rule describes the linked line the way the check prints it', function (): void {
    $rule = (string) file_get_contents(dirname(__DIR__, 3) . '/rules/compound-engineering/orchestration.md');

    expect($rule)->toContain(
        'prints a `linked:` line for each issue that an earlier creation line names and that has at least one later label write',
    )->toContain('or a wrapper line (`file-deferred-moderate.sh`) whose target is the issue URL and whose result says `created`');
});

test('the issue that a wrapper created is linked by the URL that is the target of its line', function (): void {
    $result = auditVerifyLedgerLines([
        'donatello|2026-11-11T10:00:00Z|external-write|https://example.test/o/r/issues/20|'
            . 'sub-issue created under https://example.test/o/r/issues/1 (exit 0)',
        'donatello|2026-11-11T10:01:00Z|external-write|gh issue edit 20 +label tech-debt -> https://example.test/o/r/issues/20|ok (exit 0)',
    ]);

    expect($result['output'])->toContain(
        'linked: https://example.test/o/r/issues/20 was created by this run (line 1); '
            . 'label writes on line(s) 2 belong to its creation and triage: +label tech-debt',
    );
});

test('showing the link rewrites no ledger line and raises no finding', function (): void {
    $result = auditVerifyLedgerLines([AUDIT_LINK_CREATED, AUDIT_LINK_TRIAGE, AUDIT_LINK_OTHER]);

    expect($result['unchanged'])->toBeTrue()
        ->and(implode("\n", $result['output']))->not->toContain('problem:')
        ->and(implode("\n", $result['output']))->not->toContain('not verified:');
});
