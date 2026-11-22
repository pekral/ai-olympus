<?php

declare(strict_types = 1);

use Symfony\Component\Process\Process;

// `audit-trail.sh run` refuses a close that posts a comment: that comment carries no agent marker.
// The fake `gh` records that it ran.

const AUDIT_CLOSE_FAKE_GH = <<<'BASH'
#!/usr/bin/env bash
touch "$FAKE_GH_RAN"
echo "https://example.test/o/r/issues/5"
BASH;

/**
 * @param list<string> $command
 * @return array{exitCode: int|null, ran: bool, lines: int}
 */
function auditRunCloseOutcome(array $command): array
{
    $ledger = auditLedgerCreate();
    $bin = $ledger['directory'] . '/fake-bin';
    installerEnsureDirectory($bin);
    file_put_contents($bin . '/gh', AUDIT_CLOSE_FAKE_GH);
    chmod($bin . '/gh', 0o700);
    $path = getenv('PATH');

    try {
        $process = new Process(
            [dirname(__DIR__, 3) . '/commands/_shared/audit-trail.sh', 'run', '--', ...$command],
            env: [
                ...$ledger['env'],
                'FAKE_GH_RAN' => $ledger['directory'] . '/ran',
                'PATH' => $bin . PATH_SEPARATOR . ($path === false ? '/usr/bin:/bin' : $path),
            ],
        );
        $process->run();

        return [
            'exitCode' => $process->getExitCode(),
            'lines' => count(auditLedgerRows($ledger)),
            'ran' => file_exists($ledger['directory'] . '/ran'),
        ];
    } finally {
        auditLedgerRemove($ledger);
    }
}

dataset('close commands that post a comment', [
    'issue --comment' => [['gh', 'issue', 'close', '5', '--comment', 'x']],
    'issue -c' => [['gh', 'issue', 'close', '5', '-c', 'x']],
    'issue reopen --comment' => [['gh', 'issue', 'reopen', '5', '--comment', 'x']],
    'pr --comment=' => [['gh', 'pr', 'close', '5', '--comment=x']],
    'pr in a short bundle' => [['gh', 'pr', 'close', '5', '-dc', 'x']],
    'pr reopen --comment=' => [['gh', 'pr', 'reopen', '5', '--comment=x']],
    'pr reopen -c' => [['gh', 'pr', 'reopen', '5', '-c', 'x']],
    'pr value stuck to the option' => [['gh', 'pr', 'close', '5', '-cx']],
]);

test('run refuses a close or a reopen that posts a comment, runs nothing, and writes no line', function (array $command): void {
    /** @var list<string> $command */
    expect(auditRunCloseOutcome($command))->toEqual(['exitCode' => 64, 'ran' => false, 'lines' => 0]);
})->with('close commands that post a comment');

dataset('commands that post no comment', [
    'issue reopen' => [['gh', 'issue', 'reopen', '5']],
    'pr close --delete-branch' => [['gh', 'pr', 'close', '5', '--delete-branch']],
    'pr merge with a merge commit body' => [['gh', 'pr', 'merge', '5', '--body', 'x', '--subject', 'y']],
]);

test('run still allows a command that posts no comment', function (array $command): void {
    /** @var list<string> $command */
    expect(auditRunCloseOutcome($command))->toEqual(['exitCode' => 0, 'ran' => true, 'lines' => 1]);
})->with('commands that post no comment');
