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
  printf '%s' "$7" > "$FAKE_ACLI_STATE"
  exit 0
fi

if [[ "$1" == "jira" && "$2" == "workitem" && "$3" == "assign" ]]; then
  printf '%s' "$7" > "$FAKE_ACLI_ASSIGNED"
  exit 0
fi

if [[ "$1" == "jira" && "$2" == "workitem" && "$3" == "search" ]]; then
  if [[ "$FAKE_ACLI_VERIFY" == "1" ]]; then
    printf '%s\n' '{"issues":[{"key":"TEAM-42"}]}'
  else
    printf '%s\n' '{"issues":[]}'
  fi
  exit 0
fi

exit 1
BASH;

/**
 * @return array{assigned: string, bin: string, directory: string, state: string}
 */
function createJiraClaimFixture(): array
{
    $directory = sys_get_temp_dir() . '/ai-olympus-jira-claim-' . bin2hex(random_bytes(6));
    $bin = $directory . '/bin';
    $state = $directory . '/state';
    $assigned = $directory . '/assigned';

    mkdir($bin, 0o700, true);
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
    $systemPath = getenv('PATH') ?: '/usr/bin:/bin';
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

test('an already In Progress JIRA issue is still assigned to the authenticated acli user', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $fixture = createJiraClaimFixture();
    file_put_contents($fixture['state'], 'In Progress');
    $systemPath = getenv('PATH') ?: '/usr/bin:/bin';
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
            ->and(file_get_contents($fixture['assigned']))->toBe('@me')
            ->and($process->getErrorOutput())->toContain('action=noop')
            ->and($process->getErrorOutput())->toContain('assignee=@me');
    } finally {
        removeJiraClaimFixture($fixture);
    }
});

test('the JIRA claim accepts the Czech Rozpracováno status without extra configuration', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $fixture = createJiraClaimFixture();
    $systemPath = getenv('PATH') ?: '/usr/bin:/bin';
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
    $systemPath = getenv('PATH') ?: '/usr/bin:/bin';
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
