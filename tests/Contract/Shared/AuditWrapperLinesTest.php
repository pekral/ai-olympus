<?php

declare(strict_types = 1);

use Symfony\Component\Process\Process;

// The package wrappers that publish through `gh` or `curl` leave their own audit line
// (`skills/_shared/audit-trail.sh`). Each runs against a fake CLI that answers from the shell, so no
// request leaves the machine: one well-formed line for a write that succeeds, one note line for a
// write that fails.

const AUDIT_WRAPPER_FAKE_CURL = <<<'BASH'
#!/usr/bin/env bash
url="${*: -1}"
case "$url" in
*/user/organizations*) printf '%s\n%s' '[{"slug":"o","id":"1"}]' 200 ;;
*/organizations/1/projects*) printf '%s\n%s' '[{"slug":"p","id":"2"}]' 200 ;;
*/comments)
  cat >/dev/null
  printf '%s\n%s' '{"id":"c9"}' "${FAKE_HTTP:-201}"
  ;;
*) exit 7 ;;
esac
BASH;

const AUDIT_WRAPPER_FAKE_GH_DELETE = <<<'BASH'
#!/usr/bin/env bash
case "$*" in
"api user --jq .login") echo bot ;;
*"--method DELETE"*)
  [[ "${FAKE_DELETE_RC:-0}" -eq 0 ]] || exit "$FAKE_DELETE_RC"
  : > "$FAKE_DELETED"
  ;;
"api --include "*)
  if [[ -f "$FAKE_DELETED" ]]; then
    echo 'HTTP/2.0 404 Not Found'
    exit 1
  fi
  echo 'HTTP/2.0 200 OK'
  ;;
"api repos/o/r/issues/comments/"*)
  printf '{"user":{"login":"bot"},"issue_url":"https://api.github.com/repos/o/r/issues/5",'
  printf '"body":"<!-- merge-readiness:actor=bot --> <!-- cr-comment:actor=bot -->"}\n'
  ;;
esac
BASH;

const AUDIT_WRAPPER_FAKE_GH_FILE = <<<'BASH'
#!/usr/bin/env bash
case "$*" in
"issue create "*)
  cat >/dev/null
  [[ "${FAKE_CREATE_RC:-0}" -eq 0 ]] || exit "$FAKE_CREATE_RC"
  echo 'https://github.com/o/r/issues/8'
  ;;
*addSubIssue*)
  [[ "${FAKE_ADD_RC:-0}" -eq 0 ]] || exit "$FAKE_ADD_RC"
  echo '{}'
  ;;
*subIssues*) echo '{"data":{"repository":{"issue":{"subIssues":{"nodes":[{"number":8}]}}}}}' ;;
*graphql*) echo '{"data":{"repository":{"issue":{"id":"NODE"}}}}' ;;
esac
BASH;

function auditWrapperSystemPath(): string
{
    $path = getenv('PATH');

    return $path === false ? '/usr/bin:/bin' : $path;
}

/**
 * @param array<string, string> $files fake executables by name
 */
function auditWrapperFakeBin(string $directory, array $files): string
{
    $bin = $directory . '/fake-bin';
    installerEnsureDirectory($bin);

    foreach ($files as $name => $script) {
        file_put_contents($bin . '/' . $name, $script);
        chmod($bin . '/' . $name, 0o700);
    }

    return $bin;
}

dataset('bugsnag publish outcomes', [
    'created' => ['201', 0, 'external-write', 'bugsnag comment c9 on o/p/abc123', 'created (exit 0)'],
    'rejected with HTTP 500' => ['500', 3, 'note', 'no action performed', 'bugsnag comment on o/p/abc123: write failed (exit 3); HTTP 500'],
]);

test(
    'the Bugsnag publisher leaves one well-formed line for each outcome',
    function (string $http, int $exitCode, string $class, string $target, string $result): void {
        $packageDir = dirname(__DIR__, 3);
        $ledger = auditLedgerCreate();
        $bin = auditWrapperFakeBin($ledger['directory'], ['curl' => AUDIT_WRAPPER_FAKE_CURL]);
        $process = new Process([
            $packageDir . '/skills/code-review-bugsnag/scripts/upsert-comment.sh',
            'o/p/abc123',
            '-',
        ], $packageDir, [
            ...$ledger['env'],
            'BUGSNAG_TOKEN' => 'test-token',
            'FAKE_HTTP' => $http,
            'PATH' => $bin . PATH_SEPARATOR . auditWrapperSystemPath(),
        ], 'a comment body');
    
        try {
            $process->run();
            $rows = auditLedgerRows($ledger);
    
            expect($process->getExitCode())->toBe($exitCode)
                ->and($rows)->toHaveCount(1)
                ->and($rows[0])->toHaveCount(5)
                ->and($rows[0][0])->toBe('donatello')
                ->and($rows[0][1])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
                ->and($rows[0][2])->toBe($class)
                ->and($rows[0][3])->toBe($target)
                ->and($rows[0][4])->toBe($result);
        } finally {
            auditLedgerRemove($ledger);
        }
    },
)->with('bugsnag publish outcomes');

dataset('github delete outcomes', [
    'deleted' => ['0', 0, 'external-write', 'delete comment 123 on https://github.com/o/r/issues/5', 'ok (exit 0)'],
    'delete request fails' => ['9', 9, 'note', 'no action performed', 'delete comment 123 on https://github.com/o/r/issues/5: write failed (exit 9)'],
]);

test(
    'the GitHub comment delete helper leaves one well-formed line for each outcome',
    function (string $deleteRc, int $exitCode, string $class, string $target, string $result): void {
        $packageDir = dirname(__DIR__, 3);
        $ledger = auditLedgerCreate();
        $repository = $ledger['directory'] . '/repository';
        installerEnsureDirectory($repository);
        new Process(['git', 'init', '-q', $repository])->mustRun();
        new Process(['git', '-C', $repository, 'remote', 'add', 'origin', 'https://github.com/o/r.git'])->mustRun();
        $bin = auditWrapperFakeBin($ledger['directory'], ['gh' => AUDIT_WRAPPER_FAKE_GH_DELETE]);
        $process = new Process([
            $packageDir . '/skills/_shared/delete-owned-github-comment.sh',
            'https://github.com/o/r/issues/5',
            '123',
            '900',
            '901',
        ], $repository, [
            ...$ledger['env'],
            'FAKE_DELETE_RC' => $deleteRc,
            'FAKE_DELETED' => $ledger['directory'] . '/deleted',
            'PATH' => $bin . PATH_SEPARATOR . auditWrapperSystemPath(),
        ]);
    
        try {
            $process->run();
            $rows = auditLedgerRows($ledger);
    
            expect($process->getExitCode())->toBe($exitCode)
                ->and($rows)->toHaveCount(1)
                ->and($rows[0])->toHaveCount(5)
                ->and($rows[0][0])->toBe('donatello')
                ->and($rows[0][2])->toBe($class)
                ->and($rows[0][3])->toBe($target)
                ->and($rows[0][4])->toBe($result);
        } finally {
            auditLedgerRemove($ledger);
        }
    },
)->with('github delete outcomes');

const AUDIT_WRAPPER_CHILD = 'https://github.com/o/r/issues/8';

const AUDIT_WRAPPER_RELATION = 'addSubIssue ' . AUDIT_WRAPPER_CHILD . ' under https://github.com/o/r/issues/5';

dataset('deferred moderate outcomes', [
    'issue and relation written' => ['0', '0', ['external-write', 'external-write'], AUDIT_WRAPPER_CHILD, AUDIT_WRAPPER_RELATION],
    'issue create fails' => ['5', '0', ['note'], 'no action performed', 'gh issue create on o/r: write failed (exit 5)'],
    'relation write fails' => ['0', '6', ['external-write', 'note'], AUDIT_WRAPPER_CHILD, AUDIT_WRAPPER_RELATION . ': write failed (exit 6)'],
]);

test(
    'the deferred moderate filing leaves one well-formed line for each write',
    function (string $createRc, string $addRc, array $classes, string $firstTarget, string $lastText): void {
        $packageDir = dirname(__DIR__, 3);
        $ledger = auditLedgerCreate();
        $bin = auditWrapperFakeBin($ledger['directory'], ['gh' => AUDIT_WRAPPER_FAKE_GH_FILE]);
        $process = new Process([
            $packageDir . '/skills/process-code-review/scripts/file-deferred-moderate.sh',
            'https://github.com/o/r/issues/5',
            'A deferred finding',
            '-',
        ], $packageDir, [
            ...$ledger['env'],
            'FAKE_ADD_RC' => $addRc,
            'FAKE_CREATE_RC' => $createRc,
            'PATH' => $bin . PATH_SEPARATOR . auditWrapperSystemPath(),
        ], 'The finding body');
    
        try {
            $process->run();
            $rows = auditLedgerRows($ledger);
    
            expect(array_column($rows, 2))->toBe($classes)
                ->and(array_unique(array_map('count', $rows)))->toBe([5])
                ->and(array_unique(array_column($rows, 0)))->toBe(['donatello'])
                ->and($rows[0][3])->toBe($firstTarget)
                ->and(implode(' ', $rows[count($rows) - 1]))->toContain($lastText);
        } finally {
            auditLedgerRemove($ledger);
        }
    },
)->with('deferred moderate outcomes');
