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
        $process = new Process([dirname(__DIR__, 3) . '/skills/_shared/audit-trail.sh', 'verify', '--file', $ledger['file']]);
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

test('a bare issue number links only to the one created issue that has that number', function (): void {
    $result = auditVerifyLedgerLines([
        AUDIT_LINK_CREATED,
        'donatello|2026-11-11T10:01:00Z|external-write|gh issue create -> https://example.test/o/s/issues/7|ok (exit 0)',
        'donatello|2026-11-11T10:02:00Z|external-write|gh issue edit 7 +label bug|ok (exit 0)',
    ]);

    expect($result['output'])->toContain(
        'standalone: label write on issue 7 (line 3) is not part of an issue this run created: +label bug',
    )->and(implode("\n", $result['output']))->not->toContain('linked:');
});

test('showing the link rewrites no ledger line and raises no finding', function (): void {
    $result = auditVerifyLedgerLines([AUDIT_LINK_CREATED, AUDIT_LINK_TRIAGE, AUDIT_LINK_OTHER]);

    expect($result['unchanged'])->toBeTrue()
        ->and(implode("\n", $result['output']))->not->toContain('problem:')
        ->and(implode("\n", $result['output']))->not->toContain('not verified:');
});
