<?php

declare(strict_types = 1);

use Symfony\Component\Process\Process;

// `audit-trail.sh verify --issue N` counts a label change on N as recorded only when an `external-write`
// line names the same issue. The fake `gh` prints the rows that `gh api --jq` would print.

const AUDIT_SCOPE_FAKE_GH = <<<'BASH'
#!/usr/bin/env bash
case "$*" in
"api user --jq .login") echo bot ;;
*"issues/5/timeline"*)
  printf 'bot\t2026-11-11T11:00:00Z\tlabeled\tsurprise\n'
  printf 'bot\t2026-11-11T11:00:00Z\tlabeled\tother-tag\n'
  printf 'bot\t2026-11-11T11:00:00Z\tlabeled\tby-number\n'
  ;;
esac
BASH;

/**
 * @param list<string> $lines
 * @return list<string>
 */
function auditScopeVerifyOutput(array $lines, string $issue): array
{
    $ledger = auditLedgerCreate();
    file_put_contents($ledger['file'], implode("\n", $lines) . "\n");
    $bin = $ledger['directory'] . '/fake-bin';
    installerEnsureDirectory($bin);
    file_put_contents($bin . '/gh', AUDIT_SCOPE_FAKE_GH);
    chmod($bin . '/gh', 0o700);
    $path = getenv('PATH');

    try {
        $process = new Process(
            [dirname(__DIR__, 3) . '/skills/_shared/audit-trail.sh', 'verify', '--file', $ledger['file'], '--issue', $issue],
            null,
            ['PATH' => $bin . PATH_SEPARATOR . ($path === false ? '/usr/bin:/bin' : $path)],
        );
        $process->run();

        return explode("\n", trim($process->getOutput()));
    } finally {
        auditLedgerRemove($ledger);
    }
}

test('a label written on another issue is not a record of the same label on the issue under check', function (): void {
    $output = implode("\n", auditScopeVerifyOutput([
        'donatello|2026-11-11T10:00:00Z|external-write|gh issue edit 8 +label surprise -> https://example.test/o/r/issues/8|ok (exit 0)',
        'donatello|2026-11-11T10:01:00Z|external-write|gh issue edit 5 +label other-tag -> https://example.test/o/r/issues/5|ok (exit 0)',
        'donatello|2026-11-11T10:02:00Z|external-write|gh issue edit 5 +label by-number|ok (exit 0)',
    ], '5'));

    expect($output)->toContain("not verified: label 'surprise' labeled")
        ->and($output)->not->toContain("label 'other-tag'")
        ->and($output)->not->toContain("label 'by-number'");
});

test('an issue URL names the issue under check with its repository, and another repository does not', function (): void {
    $lines = [
        'donatello|2026-11-11T10:00:00Z|external-write|gh issue edit 5 +label surprise -> https://example.test/o/other/issues/5|ok (exit 0)',
    ];

    expect(implode("\n", auditScopeVerifyOutput($lines, 'https://example.test/o/r/issues/5')))
        ->toContain("not verified: label 'surprise' labeled");
});
