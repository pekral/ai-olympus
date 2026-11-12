<?php

declare(strict_types = 1);

use Symfony\Component\Process\Process;

const JIRA_CLAIM_ACLI_SCRIPT = <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail

if [[ "$1" == "jira" && "$2" == "workitem" && "$3" == "view" ]]; then
  status="$(<"$FAKE_ACLI_STATE")"
  printf '{"fields":{"summary":"Task","status":{"name":"%s"}}}\n' "$status"
  exit 0
fi

if [[ "$1" == "jira" && "$2" == "workitem" && "$3" == "comment" && "$4" == "list" ]]; then
  printf '%s\n' '{"comments":[]}'
  exit 0
fi

if [[ "$1" == "jira" && "$2" == "workitem" && "$3" == "transition" ]]; then
  if [[ "${FAKE_ACLI_TRANSITION_RC:-0}" -ne 0 ]]; then
    exit "$FAKE_ACLI_TRANSITION_RC"
  fi
  printf '%s' "$7" > "$FAKE_ACLI_STATE"
  exit 0
fi

if [[ "$1" == "jira" && "$2" == "workitem" && "$3" == "assign" ]]; then
  printf '%s' "$7" > "$FAKE_ACLI_ASSIGNED"
  exit 0
fi

if [[ "$1" == "jira" && "$2" == "workitem" && "$3" == "search" ]]; then
  if [[ "$FAKE_ACLI_VERIFY" == "error" ]]; then
    exit 1
  fi
  fields=""
  for (( i = 1; i < $#; i++ )); do
    if [[ "${!i}" == "--fields" ]]; then
      next=$((i + 1))
      fields="${!next}"
    fi
  done
  # acli 1.3.39 answers a search whose only requested field is key with [null].
  if [[ "$FAKE_ACLI_VERIFY" != "1" ]]; then
    printf '%s\n' '[]'
  elif [[ "$fields" == "key" ]]; then
    printf '%s\n' '[null]'
  else
    printf '%s\n' '[{"key":"TEAM-42","fields":{"summary":"Task"}}]'
  fi
  exit 0
fi

exit 1
BASH;

function jiraClaimSystemPath(): string
{
    $systemPath = getenv('PATH');

    return $systemPath === false ? '/usr/bin:/bin' : $systemPath;
}

/**
 * @return array{assigned: string, bin: string, directory: string, state: string}
 */
function createJiraClaimFixture(): array
{
    $directory = sys_get_temp_dir() . '/ai-olympus-jira-claim-' . bin2hex(random_bytes(6));
    $bin = $directory . '/bin';
    $state = $directory . '/state';
    $assigned = $directory . '/assigned';

    mkdir($bin, 0o700, recursive: true);
    file_put_contents($state, 'To Do');
    file_put_contents($assigned, '');
    file_put_contents($bin . '/acli', JIRA_CLAIM_ACLI_SCRIPT);
    file_put_contents($bin . '/gh', "#!/usr/bin/env bash\nprintf '%s\\n' '[]'\n");
    chmod($bin . '/acli', 0o700);
    chmod($bin . '/gh', 0o700);

    return [
        'assigned' => $assigned,
        'bin' => $bin,
        'directory' => $directory,
        'state' => $state,
    ];
}

/**
 * @param array{assigned: string, bin: string, directory: string, state: string} $fixture
 */
function removeJiraClaimFixture(array $fixture): void
{
    unlink($fixture['bin'] . '/acli');
    unlink($fixture['bin'] . '/gh');
    unlink($fixture['state']);
    unlink($fixture['assigned']);
    rmdir($fixture['bin']);
    rmdir($fixture['directory']);
}

test('the JIRA In Progress claim assigns and verifies the authenticated acli user', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $fixture = createJiraClaimFixture();
    $systemPath = jiraClaimSystemPath();
    $process = new Process([
        $packageDir . '/skills/code-review-jira/scripts/transition-to-in-progress.sh',
        'TEAM-42',
    ], $packageDir, [
        'FAKE_ACLI_ASSIGNED' => $fixture['assigned'],
        'FAKE_ACLI_STATE' => $fixture['state'],
        'FAKE_ACLI_VERIFY' => '1',
        'JIRA_SITE' => 'example.atlassian.net',
        'PATH' => $fixture['bin'] . PATH_SEPARATOR . $systemPath,
    ]);

    try {
        $process->run();

        expect($process->getExitCode())->toBe(0)
            ->and(file_get_contents($fixture['state']))->toBe('In Progress')
            ->and(file_get_contents($fixture['assigned']))->toBe('@me')
            ->and($process->getErrorOutput())->toContain('assignee=@me');
    } finally {
        removeJiraClaimFixture($fixture);
    }
});

test('an already In Progress JIRA issue owned by the authenticated user is an idempotent no-op', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $fixture = createJiraClaimFixture();
    file_put_contents($fixture['state'], 'In Progress');
    $systemPath = jiraClaimSystemPath();
    $process = new Process([
        $packageDir . '/skills/code-review-jira/scripts/transition-to-in-progress.sh',
        'TEAM-42',
    ], $packageDir, [
        'FAKE_ACLI_ASSIGNED' => $fixture['assigned'],
        'FAKE_ACLI_STATE' => $fixture['state'],
        'FAKE_ACLI_VERIFY' => '1',
        'JIRA_SITE' => 'example.atlassian.net',
        'PATH' => $fixture['bin'] . PATH_SEPARATOR . $systemPath,
    ]);

    try {
        $process->run();

        expect($process->getExitCode())->toBe(0)
            ->and(file_get_contents($fixture['assigned']))->toBe('')
            ->and($process->getErrorOutput())->toContain('action=noop')
            ->and($process->getErrorOutput())->toContain('assignee=@me');
    } finally {
        removeJiraClaimFixture($fixture);
    }
});

test('an already In Progress JIRA issue not owned by the authenticated user is not stolen', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $fixture = createJiraClaimFixture();
    file_put_contents($fixture['state'], 'In Progress');
    $systemPath = jiraClaimSystemPath();
    $process = new Process([
        $packageDir . '/skills/code-review-jira/scripts/transition-to-in-progress.sh',
        'TEAM-42',
    ], $packageDir, [
        'FAKE_ACLI_ASSIGNED' => $fixture['assigned'],
        'FAKE_ACLI_STATE' => $fixture['state'],
        'FAKE_ACLI_VERIFY' => '0',
        'JIRA_SITE' => 'example.atlassian.net',
        'PATH' => $fixture['bin'] . PATH_SEPARATOR . $systemPath,
    ]);

    try {
        $process->run();

        expect($process->getExitCode())->toBe(4)
            ->and(file_get_contents($fixture['assigned']))->toBe('')
            ->and($process->getErrorOutput())->toContain('claimed by another run');
    } finally {
        removeJiraClaimFixture($fixture);
    }
});

test('the JIRA claim accepts the Czech Rozpracováno status without extra configuration', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $fixture = createJiraClaimFixture();
    $systemPath = jiraClaimSystemPath();
    $process = new Process([
        $packageDir . '/skills/code-review-jira/scripts/transition-to-in-progress.sh',
        'TEAM-42',
        'Rozpracováno',
    ], $packageDir, [
        'FAKE_ACLI_ASSIGNED' => $fixture['assigned'],
        'FAKE_ACLI_STATE' => $fixture['state'],
        'FAKE_ACLI_VERIFY' => '1',
        'JIRA_SITE' => 'example.atlassian.net',
        'PATH' => $fixture['bin'] . PATH_SEPARATOR . $systemPath,
    ]);

    try {
        $process->run();

        expect($process->getExitCode())->toBe(0)
            ->and(file_get_contents($fixture['state']))->toBe('Rozpracováno')
            ->and(file_get_contents($fixture['assigned']))->toBe('@me');
    } finally {
        removeJiraClaimFixture($fixture);
    }
});

test('the JIRA In Progress claim stops when current-user assignment cannot be verified', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $fixture = createJiraClaimFixture();
    $systemPath = jiraClaimSystemPath();
    $process = new Process([
        $packageDir . '/skills/code-review-jira/scripts/transition-to-in-progress.sh',
        'TEAM-42',
    ], $packageDir, [
        'FAKE_ACLI_ASSIGNED' => $fixture['assigned'],
        'FAKE_ACLI_STATE' => $fixture['state'],
        'FAKE_ACLI_VERIFY' => '0',
        'JIRA_SITE' => 'example.atlassian.net',
        'PATH' => $fixture['bin'] . PATH_SEPARATOR . $systemPath,
    ]);

    try {
        $process->run();

        expect($process->getExitCode())->toBe(3)
            ->and($process->getErrorOutput())->toContain('not assigned to currentUser()');
    } finally {
        removeJiraClaimFixture($fixture);
    }
});

test('a JIRA issue in review owned by the authenticated user keeps its status', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $fixture = createJiraClaimFixture();
    file_put_contents($fixture['state'], 'Code Review');
    $systemPath = jiraClaimSystemPath();
    $process = new Process([
        $packageDir . '/skills/code-review-jira/scripts/transition-to-in-progress.sh',
        'TEAM-42',
        'Rozpracováno',
    ], $packageDir, [
        'FAKE_ACLI_ASSIGNED' => $fixture['assigned'],
        'FAKE_ACLI_STATE' => $fixture['state'],
        'FAKE_ACLI_VERIFY' => '1',
        'JIRA_SITE' => 'example.atlassian.net',
        'PATH' => $fixture['bin'] . PATH_SEPARATOR . $systemPath,
    ]);

    try {
        $process->run();

        expect($process->getExitCode())->toBe(0)
            ->and(file_get_contents($fixture['state']))->toBe('Code Review')
            ->and($process->getErrorOutput())->toContain('action=unchanged status=Code Review');
        expect((string) file_get_contents($packageDir . '/skills/code-review-jira/scripts/transition-to-in-progress.sh'))
            ->toContain('or `action=unchanged` plus the current status');
    } finally {
        removeJiraClaimFixture($fixture);
    }
});

test('a JIRA issue in review not owned by the authenticated user is not taken back', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $fixture = createJiraClaimFixture();
    file_put_contents($fixture['state'], 'Code Review');
    $systemPath = jiraClaimSystemPath();
    $process = new Process([
        $packageDir . '/skills/code-review-jira/scripts/transition-to-in-progress.sh',
        'TEAM-42',
    ], $packageDir, [
        'FAKE_ACLI_ASSIGNED' => $fixture['assigned'],
        'FAKE_ACLI_STATE' => $fixture['state'],
        'FAKE_ACLI_VERIFY' => '0',
        'JIRA_SITE' => 'example.atlassian.net',
        'PATH' => $fixture['bin'] . PATH_SEPARATOR . $systemPath,
    ]);

    try {
        $process->run();

        expect($process->getExitCode())->toBe(4)
            ->and(file_get_contents($fixture['state']))->toBe('Code Review')
            ->and(file_get_contents($fixture['assigned']))->toBe('')
            ->and($process->getErrorOutput())->toContain('not assigned to currentUser()');
    } finally {
        removeJiraClaimFixture($fixture);
    }
});

test('a JIRA issue in review whose ownership lookup fails stops instead of stealing or looping (issue #154)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $fixture = createJiraClaimFixture();
    file_put_contents($fixture['state'], 'Code Review');
    $systemPath = jiraClaimSystemPath();
    $process = new Process([
        $packageDir . '/skills/code-review-jira/scripts/transition-to-in-progress.sh',
        'TEAM-42',
    ], $packageDir, [
        'FAKE_ACLI_ASSIGNED' => $fixture['assigned'],
        'FAKE_ACLI_STATE' => $fixture['state'],
        'FAKE_ACLI_VERIFY' => 'error',
        'JIRA_SITE' => 'example.atlassian.net',
        'PATH' => $fixture['bin'] . PATH_SEPARATOR . $systemPath,
    ]);

    try {
        $process->run();

        expect($process->getExitCode())->toBe(3)
            ->and(file_get_contents($fixture['state']))->toBe('Code Review')
            ->and(file_get_contents($fixture['assigned']))->toBe('')
            ->and($process->getErrorOutput())->toContain('could not verify who owns TEAM-42');
    } finally {
        removeJiraClaimFixture($fixture);
    }
});

test('a foreign-owned issue in a synonym review column is not taken over (issue #154)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $fixture = createJiraClaimFixture();
    file_put_contents($fixture['state'], 'Ke kontrole');
    $systemPath = jiraClaimSystemPath();
    $process = new Process([
        $packageDir . '/skills/code-review-jira/scripts/transition-to-in-progress.sh',
        'TEAM-42',
    ], $packageDir, [
        'FAKE_ACLI_ASSIGNED' => $fixture['assigned'],
        'FAKE_ACLI_STATE' => $fixture['state'],
        'FAKE_ACLI_VERIFY' => '0',
        'JIRA_CODE_REVIEW_SYNONYMS' => 'Ke kontrole',
        'JIRA_SITE' => 'example.atlassian.net',
        'PATH' => $fixture['bin'] . PATH_SEPARATOR . $systemPath,
    ]);

    try {
        $process->run();

        expect($process->getExitCode())->toBe(4)
            ->and(file_get_contents($fixture['state']))->toBe('Ke kontrole')
            ->and(file_get_contents($fixture['assigned']))->toBe('');
    } finally {
        removeJiraClaimFixture($fixture);
    }
});

test('a foreign-owned issue in Ready to Merge is not taken over (issue #154)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $fixture = createJiraClaimFixture();
    file_put_contents($fixture['state'], 'Ready to Merge');
    $systemPath = jiraClaimSystemPath();
    $process = new Process([
        $packageDir . '/skills/code-review-jira/scripts/transition-to-in-progress.sh',
        'TEAM-42',
    ], $packageDir, [
        'FAKE_ACLI_ASSIGNED' => $fixture['assigned'],
        'FAKE_ACLI_STATE' => $fixture['state'],
        'FAKE_ACLI_VERIFY' => '0',
        'JIRA_SITE' => 'example.atlassian.net',
        'PATH' => $fixture['bin'] . PATH_SEPARATOR . $systemPath,
    ]);

    try {
        $process->run();

        expect($process->getExitCode())->toBe(4)
            ->and(file_get_contents($fixture['state']))->toBe('Ready to Merge')
            ->and(file_get_contents($fixture['assigned']))->toBe('');
    } finally {
        removeJiraClaimFixture($fixture);
    }
});

test('an owned issue in Ready to Merge is never returned to In Progress (issue #154)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $fixture = createJiraClaimFixture();
    file_put_contents($fixture['state'], 'Ready to Merge');
    $systemPath = jiraClaimSystemPath();
    $process = new Process([
        $packageDir . '/skills/code-review-jira/scripts/transition-to-in-progress.sh',
        'TEAM-42',
    ], $packageDir, [
        'FAKE_ACLI_ASSIGNED' => $fixture['assigned'],
        'FAKE_ACLI_STATE' => $fixture['state'],
        'FAKE_ACLI_VERIFY' => '1',
        'JIRA_SITE' => 'example.atlassian.net',
        'PATH' => $fixture['bin'] . PATH_SEPARATOR . $systemPath,
    ]);

    try {
        $process->run();

        expect($process->getExitCode())->toBe(4)
            ->and(file_get_contents($fixture['state']))->toBe('Ready to Merge')
            ->and(file_get_contents($fixture['assigned']))->toBe('');
    } finally {
        removeJiraClaimFixture($fixture);
    }
});

test('an owned issue in a synonym Ready to Merge column is never returned to In Progress (issue #154)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $fixture = createJiraClaimFixture();
    file_put_contents($fixture['state'], 'Schváleno');
    $systemPath = jiraClaimSystemPath();
    $process = new Process([
        $packageDir . '/skills/code-review-jira/scripts/transition-to-in-progress.sh',
        'TEAM-42',
    ], $packageDir, [
        'FAKE_ACLI_ASSIGNED' => $fixture['assigned'],
        'FAKE_ACLI_STATE' => $fixture['state'],
        'FAKE_ACLI_VERIFY' => '1',
        'JIRA_READY_TO_MERGE_SYNONYMS' => 'Schváleno',
        'JIRA_SITE' => 'example.atlassian.net',
        'PATH' => $fixture['bin'] . PATH_SEPARATOR . $systemPath,
    ]);

    try {
        $process->run();

        expect($process->getExitCode())->toBe(4)
            ->and(file_get_contents($fixture['state']))->toBe('Schváleno')
            ->and(file_get_contents($fixture['assigned']))->toBe('');
    } finally {
        removeJiraClaimFixture($fixture);
    }
});

test('Ready to Merge is refused before any ownership lookup runs (issue #154)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $fixture = createJiraClaimFixture();
    file_put_contents($fixture['state'], 'Ready to Merge');
    $systemPath = jiraClaimSystemPath();
    $process = new Process([
        $packageDir . '/skills/code-review-jira/scripts/transition-to-in-progress.sh',
        'TEAM-42',
    ], $packageDir, [
        'FAKE_ACLI_ASSIGNED' => $fixture['assigned'],
        'FAKE_ACLI_STATE' => $fixture['state'],
        'FAKE_ACLI_VERIFY' => 'error',
        'JIRA_SITE' => 'example.atlassian.net',
        'PATH' => $fixture['bin'] . PATH_SEPARATOR . $systemPath,
    ]);

    try {
        $process->run();

        expect($process->getExitCode())->toBe(4)
            ->and(file_get_contents($fixture['state']))->toBe('Ready to Merge')
            ->and(file_get_contents($fixture['assigned']))->toBe('');
    } finally {
        removeJiraClaimFixture($fixture);
    }
});

test('a finished JIRA issue is never returned to In Progress (issue #154)', function (string $state): void {
    $packageDir = dirname(__DIR__, 2);
    $fixture = createJiraClaimFixture();
    file_put_contents($fixture['state'], $state);
    $systemPath = jiraClaimSystemPath();
    $process = new Process([
        $packageDir . '/skills/code-review-jira/scripts/transition-to-in-progress.sh',
        'TEAM-42',
    ], $packageDir, [
        'FAKE_ACLI_ASSIGNED' => $fixture['assigned'],
        'FAKE_ACLI_STATE' => $fixture['state'],
        'FAKE_ACLI_VERIFY' => '1',
        'JIRA_SITE' => 'example.atlassian.net',
        'PATH' => $fixture['bin'] . PATH_SEPARATOR . $systemPath,
    ]);

    try {
        $process->run();

        expect($process->getExitCode())->toBe(4)
            ->and(file_get_contents($fixture['state']))->toBe($state)
            ->and(file_get_contents($fixture['assigned']))->toBe('');
    } finally {
        removeJiraClaimFixture($fixture);
    }
})->with([
    'Done',
    'Closed',
    'Resolved',
    'Cancelled',
    'Canceled',
    'Merged',
    'Hotovo',
    'Ready to Deploy',
    'Deployed',
    'Testování',
    'testovani',
]);

test('the JIRA In Progress claim leaves one well-formed external-write line for the assignment and one for the transition', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $fixture = createJiraClaimFixture();
    $ledger = auditLedgerCreate();
    $process = new Process([
        $packageDir . '/skills/code-review-jira/scripts/transition-to-in-progress.sh',
        'TEAM-42',
    ], $packageDir, [
        ...$ledger['env'],
        'FAKE_ACLI_ASSIGNED' => $fixture['assigned'],
        'FAKE_ACLI_STATE' => $fixture['state'],
        'FAKE_ACLI_VERIFY' => '1',
        'JIRA_SITE' => 'example.atlassian.net',
        'PATH' => $fixture['bin'] . PATH_SEPARATOR . jiraClaimSystemPath(),
    ]);

    try {
        $process->run();
        $rows = auditLedgerRows($ledger);
        $targets = array_column($rows, 3);

        expect($process->getExitCode())->toBe(0)
            ->and($rows)->toHaveCount(2)
            ->and(array_column($rows, 2))->toBe(['external-write', 'external-write'])
            ->and(array_column($rows, 0))->toBe(['donatello', 'donatello'])
            ->and($targets)->toContain('jira assign TEAM-42 to the current acli user')
            ->and($targets)->toContain('jira transition TEAM-42 to In Progress')
            ->and(array_column($rows, 4))->toBe(['ok (exit 0)', 'ok (exit 0)']);
    } finally {
        removeJiraClaimFixture($fixture);
        auditLedgerRemove($ledger);
    }
});

test('a failed JIRA transition leaves a note line with the exit status and no external-write line for it', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $fixture = createJiraClaimFixture();
    $ledger = auditLedgerCreate();
    $process = new Process([
        $packageDir . '/skills/code-review-jira/scripts/transition-to-in-progress.sh',
        'TEAM-42',
    ], $packageDir, [
        ...$ledger['env'],
        'FAKE_ACLI_ASSIGNED' => $fixture['assigned'],
        'FAKE_ACLI_STATE' => $fixture['state'],
        'FAKE_ACLI_TRANSITION_RC' => '4',
        'FAKE_ACLI_VERIFY' => '1',
        'JIRA_SITE' => 'example.atlassian.net',
        'PATH' => $fixture['bin'] . PATH_SEPARATOR . jiraClaimSystemPath(),
    ]);

    try {
        $process->run();
        $notes = array_values(array_filter(auditLedgerRows($ledger), static fn (array $row): bool => $row[2] === 'note'));

        expect($process->getExitCode())->not->toBe(0)
            ->and($notes)->toHaveCount(1)
            ->and($notes[0][3])->toBe('jira transition TEAM-42 to In Progress')
            ->and($notes[0][4])->toBe('write failed (exit 4); the remote state was not verified');
    } finally {
        removeJiraClaimFixture($fixture);
        auditLedgerRemove($ledger);
    }
});

dataset('code review transition outcomes', [
    'transition fails' => ['4', 'note', 'jira transition TEAM-42 to Code Review', 'write failed (exit 4); the remote state was not verified'],
    'transition succeeds' => ['0', 'external-write', 'jira transition TEAM-42 to Code Review', 'ok (exit 0)'],
]);

test(
    'the JIRA Code Review transition leaves one well-formed line for each outcome',
    function (string $transitionRc, string $class, string $target, string $result): void {
        $packageDir = dirname(__DIR__, 2);
        $fixture = createJiraClaimFixture();
        file_put_contents($fixture['state'], 'In Progress');
        $ledger = auditLedgerCreate();
        $process = new Process([
            $packageDir . '/skills/code-review-jira/scripts/transition-to-code-review.sh',
            'TEAM-42',
        ], $packageDir, [
            ...$ledger['env'],
            'FAKE_ACLI_ASSIGNED' => $fixture['assigned'],
            'FAKE_ACLI_STATE' => $fixture['state'],
            'FAKE_ACLI_TRANSITION_RC' => $transitionRc,
            'FAKE_ACLI_VERIFY' => '1',
            'JIRA_SITE' => 'example.atlassian.net',
            'PATH' => $fixture['bin'] . PATH_SEPARATOR . jiraClaimSystemPath(),
        ]);
    
        try {
            $process->run();
            $rows = auditLedgerRows($ledger);
    
            expect($rows)->toHaveCount(1)
                ->and($rows[0])->toHaveCount(5)
                ->and($rows[0][0])->toBe('donatello')
                ->and($rows[0][2])->toBe($class)
                ->and($rows[0][3])->toBe($target)
                ->and($rows[0][4])->toBe($result);
        } finally {
            removeJiraClaimFixture($fixture);
            auditLedgerRemove($ledger);
        }
    },
)->with('code review transition outcomes');
