<?php

declare(strict_types = 1);

use Symfony\Component\Process\Process;

// `gh` stands in for the real CLI so the loader's contract is exercised without a network call.
// Every invocation is appended to `$FAKE_GH_CALLS`, so a test can assert which command the loader
// chose — `gh issue view` or `gh pr view` — and with which repository and number.
const GITHUB_LOADER_GH_SCRIPT = <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail

printf '%q ' "$@" >> "$FAKE_GH_CALLS"
printf '\n' >> "$FAKE_GH_CALLS"

if [[ "$1" == "issue" && "$2" == "view" ]]; then
  if [[ "${FAKE_GH_ISSUE_OK:-1}" != "1" ]]; then
    echo 'gh: Could not resolve to an Issue (HTTP 404)' >&2
    exit 1
  fi

  printf '%s\n' "$FAKE_GH_ISSUE_JSON"
  exit 0
fi

if [[ "$1" == "pr" && "$2" == "view" ]]; then
  printf '%s\n' "$FAKE_GH_PR_JSON"
  exit 0
fi

if [[ "$1" == "api" && "$2" == "graphql" ]]; then
  if [[ "${FAKE_GH_GRAPHQL_OK:-1}" != "1" ]]; then
    echo 'gh: GraphQL error' >&2
    exit 1
  fi

  printf '%s\n' "$FAKE_GH_GRAPHQL_JSON"
  exit 0
fi

exit 1
BASH;

function githubLoaderIssueJson(): string
{
    return (string) json_encode([
        'assignees' => [['login' => 'maintainer']],
        'author' => ['login' => 'reporter'],
        'body' => 'A 2 MB PDF is rejected as too large.',
        'closedAt' => null,
        'closedByPullRequestsReferences' => [],
        'comments' => [
            [
                'author' => ['login' => 'maintainer'],
                'authorAssociation' => 'MEMBER',
                'body' => 'Reproduced on staging.',
                'createdAt' => '2026-01-06T08:00:00Z',
                'updatedAt' => '2026-01-06T08:00:00Z',
                'url' => 'https://github.com/acme/widgets/issues/445#issuecomment-1',
            ],
        ],
        'createdAt' => '2026-01-05T09:00:00Z',
        'labels' => [['name' => 'bug']],
        'milestone' => null,
        'number' => 445,
        'reactionGroups' => [],
        'state' => 'OPEN',
        'stateReason' => null,
        'title' => 'Upload validation rejects valid PDFs',
        'updatedAt' => '2026-01-06T09:00:00Z',
        'url' => 'https://github.com/acme/widgets/issues/445',
    ]);
}

function githubLoaderPullRequestJson(): string
{
    // Only the fields the assertions read. Every other key the loader projects carries a `// null`
    // or `// []` fallback in its jq program, so omitting them keeps this fixture at the length the
    // test actually needs.
    return (string) json_encode([
        'author' => ['login' => 'contributor'],
        'baseRefName' => 'master',
        'body' => 'Closes #445',
        'closingIssuesReferences' => [
            ['number' => 445, 'title' => 'Upload validation rejects valid PDFs', 'url' => 'https://github.com/acme/widgets/issues/445', 'state' => 'OPEN'],
        ],
        'files' => [['path' => 'src/Upload.php', 'additions' => 10, 'deletions' => 2]],
        'headRefName' => 'fix/upload',
        'headRefOid' => 'd4e5f6',
        // The loader special-cases this field: `$p.isDraft // null` would collapse `false` to
        // `null`, which the merge gate reads as "draft state unknown".
        'isDraft' => false,
        'number' => 123,
        'state' => 'OPEN',
        'title' => 'Fix upload validation',
        'url' => 'https://github.com/acme/widgets/pull/123',
    ]);
}

function githubLoaderSubIssuesJson(): string
{
    return (string) json_encode([
        'data' => ['repository' => ['issue' => ['subIssues' => ['nodes' => [
            [
                'author' => ['login' => 'maintainer'],
                'body' => 'Cover the 2 MB boundary.',
                'closedAt' => null,
                'comments' => ['nodes' => []],
                'createdAt' => '2026-01-05T10:00:00Z',
                'labels' => ['nodes' => [['name' => 'test']]],
                'number' => 446,
                'state' => 'OPEN',
                'title' => 'Add the regression test',
                'updatedAt' => '2026-01-05T10:00:00Z',
                'url' => 'https://github.com/acme/widgets/issues/446',
            ],
        ],
        ],
        ],
        ],
        ],
    ]);
}

/**
 * The real binaries the loader needs on PATH, `gh` excluded — it is always the fake.
 *
 * @return list<string>
 */
function githubLoaderRequiredBinaries(): array
{
    return ['bash', 'jq', 'sed', 'awk', 'cat'];
}

/**
 * A bin directory holding the fake `gh` plus links to the real tools the loader shells out to, and
 * nothing else. `PATH` is replaced with this directory alone, so a test that omits the fake `gh`
 * reproduces a host where the tool is genuinely absent — rather than depending on where the host
 * happens to install the real one.
 *
 * @return array{bin: string, calls: string, directory: string}
 */
function createGitHubLoaderFixture(bool $withGh = true): array
{
    $directory = sys_get_temp_dir() . '/ai-olympus-github-loader-' . bin2hex(random_bytes(6));
    $bin = $directory . '/bin';
    $calls = $directory . '/calls';

    mkdir($bin, 0o700, recursive: true);
    file_put_contents($calls, '');

    // `bash` is resolved by the shebang's `env` through PATH, and the loader itself calls out to
    // `jq`, `sed`, `awk` and `cat`. Everything else it uses is a shell builtin.
    foreach (githubLoaderRequiredBinaries() as $binary) {
        $resolved = trim((string) shell_exec('command -v ' . $binary));

        if ($resolved !== '') {
            symlink($resolved, $bin . '/' . $binary);
        }
    }

    if ($withGh) {
        file_put_contents($bin . '/gh', GITHUB_LOADER_GH_SCRIPT);
        chmod($bin . '/gh', 0o700);
    }

    return ['bin' => $bin, 'calls' => $calls, 'directory' => $directory];
}

/**
 * @param array{bin: string, calls: string, directory: string} $fixture
 */
function removeGitHubLoaderFixture(array $fixture): void
{
    foreach (['gh', ...githubLoaderRequiredBinaries()] as $binary) {
        if (file_exists($fixture['bin'] . '/' . $binary) || is_link($fixture['bin'] . '/' . $binary)) {
            unlink($fixture['bin'] . '/' . $binary);
        }
    }

    unlink($fixture['calls']);
    rmdir($fixture['bin']);
    rmdir($fixture['directory']);
}

/**
 * @param array{bin: string, calls: string, directory: string} $fixture
 * @param array<string, string> $environment
 */
function runGitHubLoader(array $fixture, string $url, array $environment = []): Process
{
    $packageDir = dirname(__DIR__, 3);
    $process = new Process([
        $packageDir . '/skills/code-review-github/scripts/load-issue.sh',
        $url,
    ], $packageDir, [
        ...$environment,
        'FAKE_GH_CALLS' => $fixture['calls'],
        'FAKE_GH_GRAPHQL_JSON' => githubLoaderSubIssuesJson(),
        'FAKE_GH_ISSUE_JSON' => githubLoaderIssueJson(),
        'FAKE_GH_PR_JSON' => githubLoaderPullRequestJson(),
        'PATH' => $fixture['bin'],
    ]);

    $process->run();

    return $process;
}

test('an issue URL is loaded through gh issue view and returned as a stable issue document', function (): void {
    $fixture = createGitHubLoaderFixture();

    $process = runGitHubLoader($fixture, 'https://github.com/acme/widgets/issues/445');

    $output = $process->getOutput();
    $calls = (string) file_get_contents($fixture['calls']);
    removeGitHubLoaderFixture($fixture);

    expect($process->getExitCode())->toBe(0);
    expect($calls)->toContain('issue view 445');
    expect($calls)->toContain('acme/widgets');
    expect(decodedJsonField($output, 'kind'))->toBe('issue');
    expect(decodedJsonField($output, 'number'))->toBe(445);
    expect(decodedJsonField($output, 'repo.nameWithOwner'))->toBe('acme/widgets');
    expect(decodedJsonField($output, 'author'))->toBe('reporter');
    expect(decodedJsonField($output, 'comments.0.authorAssociation'))->toBe('MEMBER');
});

test('a pull request URL is loaded through gh pr view and keeps a non-draft PR reported as false', function (): void {
    $fixture = createGitHubLoaderFixture();

    $process = runGitHubLoader($fixture, 'https://github.com/acme/widgets/pull/123');

    $output = $process->getOutput();
    $calls = (string) file_get_contents($fixture['calls']);
    removeGitHubLoaderFixture($fixture);

    expect($process->getExitCode())->toBe(0);
    expect($calls)->toContain('pr view 123');
    expect(decodedJsonField($output, 'kind'))->toBe('pr');
    // Not `toBeFalsy()`: the whole point of the loader's special case is that `false` survives as
    // `false` instead of collapsing to `null`, and the merge gate distinguishes the two.
    expect(decodedJsonField($output, 'isDraft'))->toBeFalse();
    expect(decodedJsonField($output, 'headRefOid'))->toBe('d4e5f6');
    expect(decodedJsonField($output, 'closingIssues.0.number'))->toBe(445);
});

test('a www-prefixed URL resolves to the same issue as the bare host', function (): void {
    $fixture = createGitHubLoaderFixture();

    $process = runGitHubLoader($fixture, 'https://www.github.com/acme/widgets/issues/445');

    $output = $process->getOutput();
    removeGitHubLoaderFixture($fixture);

    expect($process->getExitCode())->toBe(0);
    expect(decodedJsonField($output, 'kind'))->toBe('issue');
    expect(decodedJsonField($output, 'number'))->toBe(445);
});

test('a bare issue number is rejected before any gh call is made', function (): void {
    // A bare number would resolve against the caller's cwd git remote and silently load the wrong
    // repository, so the guard has to fire before the fetch, not after it.
    $fixture = createGitHubLoaderFixture();

    $process = runGitHubLoader($fixture, '#445');

    $calls = (string) file_get_contents($fixture['calls']);
    removeGitHubLoaderFixture($fixture);

    expect($process->getExitCode())->toBe(1);
    expect($process->getErrorOutput())->toContain('bare issue/PR numbers are rejected');
    expect($calls)->toBe('');
});

test('a missing gh binary exits with the dedicated missing-tool code', function (): void {
    $fixture = createGitHubLoaderFixture(withGh: false);

    $process = runGitHubLoader($fixture, 'https://github.com/acme/widgets/issues/445');

    removeGitHubLoaderFixture($fixture);

    expect($process->getExitCode())->toBe(2);
    expect($process->getErrorOutput())->toContain('required tool not found: gh');
});

test('a failed issue fetch exits with the fetch-failure code and emits no partial document', function (): void {
    $fixture = createGitHubLoaderFixture();

    $process = runGitHubLoader($fixture, 'https://github.com/acme/widgets/issues/445', ['FAKE_GH_ISSUE_OK' => '0']);

    removeGitHubLoaderFixture($fixture);

    expect($process->getExitCode())->toBe(3);
    expect($process->getErrorOutput())->toContain('failed to fetch issue #445');
    expect($process->getOutput())->toBe('');
});

test('sub-issues are returned when the GraphQL call succeeds', function (): void {
    $fixture = createGitHubLoaderFixture();

    $process = runGitHubLoader($fixture, 'https://github.com/acme/widgets/issues/445');

    $output = $process->getOutput();
    removeGitHubLoaderFixture($fixture);

    expect(decodedJsonField($output, 'subIssues'))->toHaveCount(1);
    expect(decodedJsonField($output, 'subIssues.0.number'))->toBe(446);
    expect(decodedJsonField($output, 'subIssues.0.labels'))->toBe(['test']);
});

test('a failed sub-issue GraphQL call degrades to an empty list while the issue still loads', function (): void {
    // This degradation is deliberate — a repository without the sub-issue feature must still load
    // — but it makes an empty `subIssues` indistinguishable from a failed fetch, which
    // `@rules/compound-engineering/general.md` requires callers to disclose as unverified. Pinning
    // it here keeps the behaviour the rule describes from drifting away from the rule.
    $fixture = createGitHubLoaderFixture();

    $process = runGitHubLoader($fixture, 'https://github.com/acme/widgets/issues/445', ['FAKE_GH_GRAPHQL_OK' => '0']);

    $output = $process->getOutput();
    removeGitHubLoaderFixture($fixture);

    expect($process->getExitCode())->toBe(0);
    expect(decodedJsonField($output, 'subIssues'))->toBe([]);
    expect(decodedJsonField($output, 'number'))->toBe(445);
});
