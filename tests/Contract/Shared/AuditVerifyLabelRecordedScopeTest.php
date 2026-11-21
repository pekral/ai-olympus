<?php

declare(strict_types = 1);

use Symfony\Component\Process\Process;

// `audit-trail.sh verify --issue N` counts a label change on N as recorded only when an `external-write`
// line names the same issue. The fake `gh` prints the rows that `gh api --jq` would print.

const AUDIT_SCOPE_FAKE_GH = <<<'BASH'
#!/usr/bin/env bash
case "$*" in
"api user --jq .login") echo bot ;;
"repo view --json nameWithOwner --jq .nameWithOwner") [[ "${FAKE_REPO_RC:-0}" -eq 0 ]] && echo o/r || exit "$FAKE_REPO_RC" ;;
*"issues/5/timeline"*)
  printf 'bot\t2026-11-11T11:00:00Z\tlabeled\tsurprise\n'
  printf 'bot\t2026-11-11T11:00:00Z\tlabeled\tother-tag\n'
  printf 'bot\t2026-11-11T11:00:00Z\tlabeled\tby-number\n'
  ;;
*"issues/7/timeline"*) printf 'bot\t2026-11-11T11:00:00Z\tlabeled\tbug\n' ;;
esac
BASH;

/**
 * @param list<string> $lines
 * @return list<string>
 */
function auditScopeVerifyOutput(array $lines, string $issue, string $option = '--issue', string $repoRc = '0'): array
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
            [dirname(__DIR__, 3) . '/commands/_shared/audit-trail.sh', 'verify', '--file', $ledger['file'], $option, $issue],
            env: [
                'FAKE_REPO_RC' => $repoRc,
                'PATH' => $bin . PATH_SEPARATOR . ($path === false ? '/usr/bin:/bin' : $path),
            ],
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
        'donatello|2026-11-11T10:02:00Z|external-write|gh issue edit 5 +label by-number -> https://example.test/o/r/issues/5|ok (exit 0)',
    ], '5'));

    expect($output)->toContain('not verified: label \'surprise\' labeled')
        ->and($output)->not->toContain('label \'other-tag\'')
        ->and($output)->not->toContain('label \'by-number\'');
});

test('an issue URL names the issue under check with its repository, and another repository does not', function (): void {
    $lines = [
        'donatello|2026-11-11T10:00:00Z|external-write|gh issue edit 5 +label surprise -> https://example.test/o/other/issues/5|ok (exit 0)',
    ];

    expect(implode("\n", auditScopeVerifyOutput($lines, 'https://example.test/o/r/issues/5')))
        ->toContain('not verified: label \'surprise\' labeled');
});

test('a bare issue number names the current repository, so a label written on that number elsewhere is no record', function (): void {
    $other = 'donatello|2026-11-11T10:00:00Z|external-write|gh issue edit 7 +label bug -> https://example.test/o/other/issues/7|ok (exit 0)';
    $here = 'donatello|2026-11-11T10:00:00Z|external-write|gh issue edit 7 +label bug -> https://example.test/o/r/issues/7|ok (exit 0)';
    $without = 'donatello|2026-11-11T10:00:00Z|external-write|gh issue edit 7 +label bug|ok (exit 0)';
    $second = 'donatello|2026-11-11T10:00:00Z|external-write|gh issue edit 3 7 +label bug -> https://example.test/o/other/issues/3|ok (exit 0)';

    expect(implode("\n", auditScopeVerifyOutput([$other], '7')))->toContain('not verified: label \'bug\' labeled')
        ->and(implode("\n", auditScopeVerifyOutput([$without], '7')))->toContain('not verified: label \'bug\' labeled')
        ->and(implode("\n", auditScopeVerifyOutput([$second], '7')))->toContain('not verified: label \'bug\' labeled')
        ->and(implode("\n", auditScopeVerifyOutput([$here], '7')))->not->toContain('label \'bug\'');
});

dataset('an unreadable current repository', [
    'issue, another repository' => ['--issue', 'gh issue edit 7 +label bug -> https://example.test/o/other/issues/7'],
    'issue, second number of another repository' => ['--issue', 'gh issue edit 3 7 +label bug -> https://example.test/o/other/issues/3'],
    'issue, the same repository' => ['--issue', 'gh issue edit 7 +label bug -> https://example.test/o/r/issues/7'],
    'pull request, another repository' => ['--pr', 'gh issue edit 7 +label bug -> https://example.test/o/other/issues/7'],
]);

test('a bare number whose repository cannot be read counts no label as recorded and is reported', function (string $option, string $target): void {
    $line = 'donatello|2026-11-11T10:00:00Z|external-write|' . $target . '|ok (exit 0)';
    $output = implode("\n", auditScopeVerifyOutput([$line], '7', $option, '1'));

    expect($output)->toContain('not verified: the current repository could not be read')
        ->and($output)->toContain('not verified: label \'bug\' labeled');
})->with('an unreadable current repository');
