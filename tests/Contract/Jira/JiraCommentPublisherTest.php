<?php

declare(strict_types = 1);

use Symfony\Component\Process\Process;

const JIRA_COMMENT_ACLI_SCRIPT = <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail

printf '%q ' "$@" >> "$FAKE_ACLI_CALLS"
printf '\n' >> "$FAKE_ACLI_CALLS"

if [[ "$1" == "jira" && "$2" == "auth" && "$3" == "status" ]]; then
  printf '%s\n' 'Site: example.atlassian.net'
  exit 0
fi

if [[ "$1" == "jira" && "$2" == "workitem" && "$3" == "comment" && "$4" == "create" ]]; then
  printf '%s\n' "$FAKE_ACLI_CREATE_JSON"
  exit 0
fi

if [[ "$1" == "jira" && "$2" == "workitem" && "$3" == "comment" && "$4" == "update" ]]; then
  if [[ "$FAKE_ACLI_UPDATE_OK" != "1" ]]; then
    exit 1
  fi

  while [[ $# -gt 0 ]]; do
    if [[ "$1" == "--body-adf" ]]; then
      cp "$2" "$FAKE_ACLI_ADF"
      exit 0
    fi
    shift
  done
fi

exit 1
BASH;

/**
 * @return array{adf: string, bin: string, calls: string, directory: string}
 */
function createJiraCommentPublisherFixture(): array
{
    $directory = sys_get_temp_dir() . '/ai-olympus-jira-comment-' . bin2hex(random_bytes(6));
    $bin = $directory . '/bin';
    $adf = $directory . '/comment.adf.json';
    $calls = $directory . '/calls';

    mkdir($bin, 0o700, true);
    file_put_contents($adf, '');
    file_put_contents($calls, '');
    file_put_contents($bin . '/acli', JIRA_COMMENT_ACLI_SCRIPT);
    chmod($bin . '/acli', 0o700);

    return [
        'adf' => $adf,
        'bin' => $bin,
        'calls' => $calls,
        'directory' => $directory,
    ];
}

/**
 * @param array{adf: string, bin: string, calls: string, directory: string} $fixture
 */
function removeJiraCommentPublisherFixture(array $fixture): void
{
    unlink($fixture['bin'] . '/acli');
    unlink($fixture['adf']);
    unlink($fixture['calls']);
    rmdir($fixture['bin']);
    rmdir($fixture['directory']);
}

test('JIRA comments are published as rendered ADF instead of literal Wiki Markup', function (): void {
    $packageDir = dirname(__DIR__, 3);
    $fixture = createJiraCommentPublisherFixture();
    $systemPath = getenv('PATH') ?: '/usr/bin:/bin';
    $body = <<<'WIKI'
h2. What changed

*Problem:* Jira displayed {{h2.}} as plain text.

* The [pull request|https://github.com/pekral/ai-olympus/pull/117] fixes _formatting_.
# Verify the comment.

{quote}The comment must render.{quote}

{code:php}
echo 'formatted';
{code}

----
WIKI;
    $process = new Process([
        $packageDir . '/skills/code-review-jira/scripts/upsert-comment.sh',
        'TEAM-42',
        '-',
    ], $packageDir, [
        'FAKE_ACLI_ADF' => $fixture['adf'],
        'FAKE_ACLI_CALLS' => $fixture['calls'],
        'FAKE_ACLI_CREATE_JSON' => '{"id":"10001"}',
        'FAKE_ACLI_UPDATE_OK' => '1',
        'PATH' => $fixture['bin'] . PATH_SEPARATOR . $systemPath,
    ], $body);

    try {
        $process->run();
        $adf = json_decode((string) file_get_contents($fixture['adf']), true, 512, JSON_THROW_ON_ERROR);
        $calls = (string) file_get_contents($fixture['calls']);

        expect($process->getExitCode())->toBe(0)
            ->and($process->getOutput())->toContain('focusedCommentId=10001')
            ->and($calls)->toContain('comment create --key TEAM-42 --body-file')
            ->and($calls)->toContain('comment update --key TEAM-42 --id 10001 --body-adf')
            ->and($adf['version'])->toBe(1)
            ->and($adf['type'])->toBe('doc')
            ->and(array_column($adf['content'], 'type'))->toBe([
                'heading',
                'paragraph',
                'bulletList',
                'orderedList',
                'blockquote',
                'codeBlock',
                'rule',
            ])
            ->and($adf['content'][0]['attrs']['level'])->toBe(2)
            ->and($adf['content'][0]['content'][0]['text'])->toBe('What changed')
            ->and($adf['content'][1]['content'][0]['marks'][0]['type'])->toBe('strong')
            ->and($adf['content'][2]['content'][0]['content'][0]['content'][1]['marks'][0]['type'])->toBe('link')
            ->and($adf['content'][2]['content'][0]['content'][0]['content'][1]['marks'][0]['attrs']['href'])
            ->toBe('https://github.com/pekral/ai-olympus/pull/117')
            ->and($adf['content'][4]['content'][0]['content'][0]['text'])->toBe('The comment must render.')
            ->and($adf['content'][5]['attrs']['language'])->toBe('php')
            ->and($adf['content'][5]['content'][0]['text'])->toBe("echo 'formatted';");
    } finally {
        removeJiraCommentPublisherFixture($fixture);
    }
});

test('JIRA ADF publishing fails closed when create omits the new comment ID', function (): void {
    $packageDir = dirname(__DIR__, 3);
    $fixture = createJiraCommentPublisherFixture();
    $systemPath = getenv('PATH') ?: '/usr/bin:/bin';
    $process = new Process([
        $packageDir . '/skills/code-review-jira/scripts/upsert-comment.sh',
        'TEAM-42',
        '-',
    ], $packageDir, [
        'FAKE_ACLI_ADF' => $fixture['adf'],
        'FAKE_ACLI_CALLS' => $fixture['calls'],
        'FAKE_ACLI_CREATE_JSON' => '{}',
        'FAKE_ACLI_UPDATE_OK' => '1',
        'PATH' => $fixture['bin'] . PATH_SEPARATOR . $systemPath,
    ], 'h2. Fallback');

    try {
        $process->run();
        $calls = (string) file_get_contents($fixture['calls']);

        expect($process->getExitCode())->toBe(3)
            ->and($process->getErrorOutput())->toContain('ID is missing; ADF update aborted')
            ->and($calls)->not->toContain('comment list')
            ->and($calls)->not->toContain('comment update');
    } finally {
        removeJiraCommentPublisherFixture($fixture);
    }
});

test('JIRA publication fails when the newly created comment cannot receive ADF', function (): void {
    $packageDir = dirname(__DIR__, 3);
    $fixture = createJiraCommentPublisherFixture();
    $systemPath = getenv('PATH') ?: '/usr/bin:/bin';
    $process = new Process([
        $packageDir . '/skills/code-review-jira/scripts/upsert-comment.sh',
        'TEAM-42',
        '-',
    ], $packageDir, [
        'FAKE_ACLI_ADF' => $fixture['adf'],
        'FAKE_ACLI_CALLS' => $fixture['calls'],
        'FAKE_ACLI_CREATE_JSON' => '{"id":"10003"}',
        'FAKE_ACLI_UPDATE_OK' => '0',
        'PATH' => $fixture['bin'] . PATH_SEPARATOR . $systemPath,
    ], 'h2. Must render');

    try {
        $process->run();

        expect($process->getExitCode())->toBe(3)
            ->and($process->getErrorOutput())->toContain('ADF update failed');
    } finally {
        removeJiraCommentPublisherFixture($fixture);
    }
});
