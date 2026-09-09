<?php

declare(strict_types = 1);

use Symfony\Component\Process\Process;

// `acli` stands in for the real CLI so the loader's contract is exercised without reaching a JIRA
// site. Every invocation is appended to `$FAKE_ACLI_CALLS`, so a test can assert which key the
// loader resolved out of the argument it was given.
const JIRA_LOADER_ACLI_SCRIPT = <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail

printf '%q ' "$@" >> "$FAKE_ACLI_CALLS"
printf '\n' >> "$FAKE_ACLI_CALLS"

if [[ "$3" == "view" ]]; then
  if [[ "${FAKE_ACLI_VIEW_OK:-1}" != "1" ]]; then
    echo 'acli: issue not found' >&2
    exit 1
  fi

  printf '%s\n' "$FAKE_ACLI_VIEW_JSON"
  exit 0
fi

if [[ "$3" == "comment" ]]; then
  if [[ "${FAKE_ACLI_COMMENTS_OK:-1}" != "1" ]]; then
    echo 'acli: comment list failed' >&2
    exit 1
  fi

  printf '%s\n' "$FAKE_ACLI_COMMENTS_JSON"
  exit 0
fi

exit 1
BASH;

function jiraLoaderViewJson(): string
{
    return (string) json_encode([
        'fields' => [
            'assignee' => ['displayName' => 'Maintainer'],
            'attachment' => [],
            'components' => [['name' => 'API']],
            'created' => '2026-01-05T09:00:00.000+0100',
            'creator' => ['displayName' => 'Reporter'],
            // The loader flattens this ADF tree into `descriptionText`; the raw tree stays
            // available under `descriptionAdf`.
            'description' => [
                'content' => [
                    ['content' => [['text' => 'A 2 MB PDF is rejected as too large.', 'type' => 'text']], 'type' => 'paragraph'],
                    ['content' => [['text' => 'Expected: the upload succeeds.', 'type' => 'text']], 'type' => 'paragraph'],
                ],
                'type' => 'doc',
                'version' => 1,
            ],
            'fixVersions' => [],
            'issuelinks' => [],
            'issuetype' => ['name' => 'Bug'],
            'labels' => ['upload'],
            'priority' => ['name' => 'High'],
            'reporter' => ['displayName' => 'Reporter'],
            'status' => ['name' => 'In Progress'],
            'subtasks' => [],
            'summary' => 'Upload validation rejects valid PDFs',
            'updated' => '2026-01-06T09:00:00.000+0100',
            'watches' => ['watchCount' => 2],
        ],
        'key' => 'ACME-1234',
    ]);
}

function jiraLoaderCommentsJson(): string
{
    // Keys are written in the order the project's fixers sort them into, so a fix pass has nothing
    // to reorder — it rewrote an earlier revision of this fixture into invalid PHP.
    return (string) json_encode([
        'comments' => [
            [
                'author' => ['displayName' => 'Maintainer'],
                'body' => [
                    'content' => [
                        ['content' => [['text' => 'Reproduced on staging.', 'type' => 'text']], 'type' => 'paragraph'],
                    ],
                    'type' => 'doc',
                    'version' => 1,
                ],
                'created' => '2026-01-06T08:00:00.000+0100',
                // `id` is not decoration: the loader falls back to `$viewCommentIdx[$c.id]` for
                // `created` and `visibility`, and jq aborts the whole document with
                // "Cannot index object with null" when the key is absent. Real `acli` output
                // always carries it.
                'id' => '10001',
            ],
        ],
    ]);
}

/**
 * The real binaries the loader needs on PATH, `acli` excluded — it is always the fake. `gh` is
 * deliberately absent: the loader treats it as optional and must still produce a document without
 * it.
 *
 * @return list<string>
 */
function jiraLoaderRequiredBinaries(): array
{
    return ['bash', 'jq', 'sed', 'awk', 'cat', 'grep', 'head'];
}

/**
 * @return array{bin: string, calls: string, directory: string}
 */
function createJiraLoaderFixture(bool $withAcli = true): array
{
    $directory = sys_get_temp_dir() . '/ai-olympus-jira-loader-' . bin2hex(random_bytes(6));
    $bin = $directory . '/bin';
    $calls = $directory . '/calls';

    mkdir($bin, 0o700, recursive: true);
    file_put_contents($calls, '');

    foreach (jiraLoaderRequiredBinaries() as $binary) {
        $resolved = trim((string) shell_exec('command -v ' . $binary));

        if ($resolved !== '') {
            symlink($resolved, $bin . '/' . $binary);
        }
    }

    if ($withAcli) {
        file_put_contents($bin . '/acli', JIRA_LOADER_ACLI_SCRIPT);
        chmod($bin . '/acli', 0o700);
    }

    return ['bin' => $bin, 'calls' => $calls, 'directory' => $directory];
}

/**
 * @param array{bin: string, calls: string, directory: string} $fixture
 */
function removeJiraLoaderFixture(array $fixture): void
{
    foreach (['acli', ...jiraLoaderRequiredBinaries()] as $binary) {
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
function runJiraLoader(array $fixture, string $argument, array $environment = []): Process
{
    $packageDir = dirname(__DIR__, 3);
    $process = new Process([
        $packageDir . '/skills/code-review-jira/scripts/load-issue.sh',
        $argument,
    ], $packageDir, [
        ...$environment,
        'FAKE_ACLI_CALLS' => $fixture['calls'],
        'FAKE_ACLI_COMMENTS_JSON' => jiraLoaderCommentsJson(),
        'FAKE_ACLI_VIEW_JSON' => jiraLoaderViewJson(),
        'HOME' => $fixture['directory'],
        'PATH' => $fixture['bin'],
    ]);

    $process->run();

    return $process;
}

test('a bare issue key is loaded and returned as a stable issue document', function (): void {
    $fixture = createJiraLoaderFixture();

    $process = runJiraLoader($fixture, 'ACME-1234');

    $output = $process->getOutput();
    $calls = (string) file_get_contents($fixture['calls']);
    removeJiraLoaderFixture($fixture);

    expect($process->getExitCode())->toBe(0);
    expect($calls)->toContain('workitem view ACME-1234');
    expect(decodedJsonField($output, 'key'))->toBe('ACME-1234');
    expect(decodedJsonField($output, 'summary'))->toBe('Upload validation rejects valid PDFs');
    expect(decodedJsonField($output, 'status'))->toBe('In Progress');
    expect(decodedJsonField($output, 'assignee'))->toBe('Maintainer');
});

test('a browse URL resolves to the same key as the bare form', function (): void {
    $fixture = createJiraLoaderFixture();

    $process = runJiraLoader($fixture, 'https://acme.atlassian.net/browse/ACME-1234');

    $output = $process->getOutput();
    removeJiraLoaderFixture($fixture);

    expect($process->getExitCode())->toBe(0);
    expect(decodedJsonField($output, 'key'))->toBe('ACME-1234');
    expect(decodedJsonField($output, 'url'))->toContain('acme.atlassian.net');
});

test('a selectedIssue query parameter resolves to the key even with other params present', function (): void {
    $fixture = createJiraLoaderFixture();

    $process = runJiraLoader($fixture, 'https://acme.atlassian.net/jira/software/projects/ACME/boards/7?atlOrigin=abc&selectedIssue=ACME-1234');

    $output = $process->getOutput();
    removeJiraLoaderFixture($fixture);

    expect($process->getExitCode())->toBe(0);
    expect(decodedJsonField($output, 'key'))->toBe('ACME-1234');
});

test('an ADF description is flattened into plain text and the raw tree is preserved', function (): void {
    // The flattening is the loader's own logic, not the CLI's — a consumer reads `descriptionText`
    // and never has to walk an ADF tree itself.
    $fixture = createJiraLoaderFixture();

    $process = runJiraLoader($fixture, 'ACME-1234');

    $output = $process->getOutput();
    removeJiraLoaderFixture($fixture);

    // One newline per ADF block, not the blank line a Markdown reader would expect: `adfText`
    // appends a single "\n" per paragraph and the collapse pass only squeezes runs of two or more.
    expect(decodedJsonField($output, 'descriptionText'))->toBe("A 2 MB PDF is rejected as too large.\nExpected: the upload succeeds.");
    expect(decodedJsonField($output, 'descriptionAdf.type'))->toBe('doc');
    expect(decodedJsonField($output, 'comments.0.author'))->toBe('Maintainer');
    expect(decodedJsonField($output, 'comments.0.body'))->toContain('Reproduced on staging.');
});

test('an argument that is neither a key nor a URL is rejected before any acli call', function (): void {
    $fixture = createJiraLoaderFixture();

    $process = runJiraLoader($fixture, 'not-a-key');

    $calls = (string) file_get_contents($fixture['calls']);
    removeJiraLoaderFixture($fixture);

    expect($process->getExitCode())->toBe(1);
    expect($process->getErrorOutput())->toContain('must be a bare key or a URL');
    expect($calls)->toBe('');
});

test('a missing acli binary exits with the dedicated missing-tool code', function (): void {
    $fixture = createJiraLoaderFixture(withAcli: false);

    $process = runJiraLoader($fixture, 'ACME-1234');

    removeJiraLoaderFixture($fixture);

    expect($process->getExitCode())->toBe(2);
    expect($process->getErrorOutput())->toContain('required tool not found: acli');
});

test('a failed issue fetch exits with the fetch-failure code and emits no partial document', function (): void {
    $fixture = createJiraLoaderFixture();

    $process = runJiraLoader($fixture, 'ACME-1234', ['FAKE_ACLI_VIEW_OK' => '0']);

    removeJiraLoaderFixture($fixture);

    expect($process->getExitCode())->toBe(3);
    expect($process->getErrorOutput())->toContain('failed to fetch JIRA issue ACME-1234');
    expect($process->getOutput())->toBe('');
});

test('a failed comment fetch degrades to an empty list while the issue still loads', function (): void {
    // `@rules/compound-engineering/general.md` *Truncated input is disclosed* rests on this exact
    // behaviour: an empty JIRA `comments[]` is indistinguishable from a failed fetch, so a caller
    // must read it as unverified rather than as "no comments". Pinning it keeps the loader from
    // drifting away from the rule that describes it.
    $fixture = createJiraLoaderFixture();

    $process = runJiraLoader($fixture, 'ACME-1234', ['FAKE_ACLI_COMMENTS_OK' => '0']);

    $output = $process->getOutput();
    removeJiraLoaderFixture($fixture);

    expect($process->getExitCode())->toBe(0);
    expect(decodedJsonField($output, 'comments'))->toBe([]);
    expect(decodedJsonField($output, 'key'))->toBe('ACME-1234');
});
