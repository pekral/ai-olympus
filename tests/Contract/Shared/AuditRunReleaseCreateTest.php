<?php

declare(strict_types = 1);

use Symfony\Component\Process\Process;

// `audit-trail.sh run` accepts `gh release create` and no other `gh release` command. The fake `gh`
// records that it ran and prints the release URL, as the real command does.

const AUDIT_RELEASE_FAKE_GH = <<<'BASH'
#!/usr/bin/env bash
touch "$FAKE_GH_RAN"
echo "https://example.test/o/r/releases/tag/v1.2.3"
exit "${FAKE_GH_EXIT:-0}"
BASH;

/**
 * @param list<string> $command
 * @return array{exitCode: int|null, ran: bool, rows: list<list<string>>}
 */
function auditRunReleaseOutcome(array $command, int $ghExit = 0): array
{
    $ledger = auditLedgerCreate();
    $bin = $ledger['directory'] . '/fake-bin';
    installerEnsureDirectory($bin);
    file_put_contents($bin . '/gh', AUDIT_RELEASE_FAKE_GH);
    chmod($bin . '/gh', 0o700);
    $path = getenv('PATH');

    try {
        $process = new Process(
            [dirname(__DIR__, 3) . '/skills/_shared/audit-trail.sh', 'run', '--', ...$command],
            env: [
                ...$ledger['env'],
                'FAKE_GH_EXIT' => (string) $ghExit,
                'FAKE_GH_RAN' => $ledger['directory'] . '/ran',
                'PATH' => $bin . PATH_SEPARATOR . ($path === false ? '/usr/bin:/bin' : $path),
            ],
        );
        $process->run();

        return [
            'exitCode' => $process->getExitCode(),
            'ran' => file_exists($ledger['directory'] . '/ran'),
            'rows' => auditLedgerRows($ledger),
        ];
    } finally {
        auditLedgerRemove($ledger);
    }
}

dataset('release creations', [
    'tag, title and notes' => [['gh', 'release', 'create', 'v1.2.3', '--title', 'Release v1.2.3', '--notes', 'a | b']],
    'tag after a bundle of short flags and --' => [['gh', 'release', 'create', '-dp', '--latest=false', '--', 'v1.2.3']],
    'tag after options and their values' => [['gh', 'release', 'create', '--draft', '--title', 'Release', '--target', 'master', 'v1.2.3', 'dist.zip']],
]);

test('run records a release creation as an external-write line that names the tag and the release URL', function (array $command): void {
    /** @var list<string> $command */
    $outcome = auditRunReleaseOutcome($command);

    expect($outcome['exitCode'])->toBe(0)
        ->and($outcome['ran'])->toBeTrue()
        ->and($outcome['rows'])->toHaveCount(1);

    [$role, , $class, $target] = $outcome['rows'][0];

    expect($outcome['rows'][0])->toHaveCount(5)
        ->and($role)->toBe('donatello')
        ->and($class)->toBe('external-write')
        ->and($target)->toBe('gh release create v1.2.3 -> https://example.test/o/r/releases/tag/v1.2.3');
})->with('release creations');

test('run records a failed release creation as a note line and passes the exit status through', function (): void {
    $outcome = auditRunReleaseOutcome(['gh', 'release', 'create', 'v1.2.3'], ghExit: 4);

    expect($outcome['exitCode'])->toBe(4)
        ->and($outcome['rows'])->toHaveCount(1)
        ->and($outcome['rows'][0][2])->toBe('note')
        ->and($outcome['rows'][0][3])->toBe('gh release create v1.2.3 -> https://example.test/o/r/releases/tag/v1.2.3');
});

dataset('release commands that stay refused', [
    'delete' => [['gh', 'release', 'delete', 'v1.2.3', '--yes']],
    'delete-asset' => [['gh', 'release', 'delete-asset', 'v1.2.3', 'dist.zip']],
    'download' => [['gh', 'release', 'download', 'v1.2.3']],
    'edit' => [['gh', 'release', 'edit', 'v1.2.3', '--draft=false']],
    'list' => [['gh', 'release', 'list']],
    'no subcommand' => [['gh', 'release']],
    'upload' => [['gh', 'release', 'upload', 'v1.2.3', 'dist.zip']],
]);

test('run refuses every other release command, runs nothing, and writes no line', function (array $command): void {
    /** @var list<string> $command */
    expect(auditRunReleaseOutcome($command))->toEqual(['exitCode' => 64, 'ran' => false, 'rows' => []]);
})->with('release commands that stay refused');
