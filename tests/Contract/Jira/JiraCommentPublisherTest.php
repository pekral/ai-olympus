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
  while [[ $# -gt 0 ]]; do
    if [[ "$1" == "--body-file" ]]; then
      cp "$2" "$FAKE_ACLI_CREATE_BODY"
      break
    fi
    shift
  done

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

const JIRA_COMMENT_EXPECTED_ADF = <<<'JSON'
{
    "version": 1,
    "type": "doc",
    "content": [
        {
            "type": "heading",
            "attrs": {
                "level": 2
            },
            "content": [
                {
                    "type": "text",
                    "text": "What changed"
                }
            ]
        },
        {
            "type": "paragraph",
            "content": [
                {
                    "type": "text",
                    "text": "Problem:",
                    "marks": [
                        {
                            "type": "strong"
                        }
                    ]
                },
                {
                    "type": "text",
                    "text": " Jira displayed "
                },
                {
                    "type": "text",
                    "text": "h2.",
                    "marks": [
                        {
                            "type": "code"
                        }
                    ]
                },
                {
                    "type": "text",
                    "text": " as plain text."
                }
            ]
        },
        {
            "type": "bulletList",
            "content": [
                {
                    "type": "listItem",
                    "content": [
                        {
                            "type": "paragraph",
                            "content": [
                                {
                                    "type": "text",
                                    "text": "The "
                                },
                                {
                                    "type": "text",
                                    "text": "pull request",
                                    "marks": [
                                        {
                                            "type": "link",
                                            "attrs": {
                                                "href": "https://github.com/pekral/ai-olympus/pull/117"
                                            }
                                        }
                                    ]
                                },
                                {
                                    "type": "text",
                                    "text": " fixes "
                                },
                                {
                                    "type": "text",
                                    "text": "formatting",
                                    "marks": [
                                        {
                                            "type": "em"
                                        }
                                    ]
                                },
                                {
                                    "type": "text",
                                    "text": "."
                                }
                            ]
                        }
                    ]
                }
            ]
        },
        {
            "type": "orderedList",
            "content": [
                {
                    "type": "listItem",
                    "content": [
                        {
                            "type": "paragraph",
                            "content": [
                                {
                                    "type": "text",
                                    "text": "Verify the comment."
                                }
                            ]
                        }
                    ]
                }
            ]
        },
        {
            "type": "blockquote",
            "content": [
                {
                    "type": "paragraph",
                    "content": [
                        {
                            "type": "text",
                            "text": "The comment must render."
                        }
                    ]
                }
            ]
        },
        {
            "type": "codeBlock",
            "content": [
                {
                    "type": "text",
                    "text": "echo 'formatted';"
                }
            ],
            "attrs": {
                "language": "php"
            }
        },
        {
            "type": "rule"
        }
    ]
}
JSON;

function jiraCommentSystemPath(): string
{
    $systemPath = getenv('PATH');

    return $systemPath === false ? '/usr/bin:/bin' : $systemPath;
}

/**
 * @return array{adf: string, bin: string, calls: string, created: string, directory: string}
 */
function createJiraCommentPublisherFixture(): array
{
    $directory = sys_get_temp_dir() . '/ai-olympus-jira-comment-' . bin2hex(random_bytes(6));
    $bin = $directory . '/bin';
    $adf = $directory . '/comment.adf.json';
    $calls = $directory . '/calls';
    $created = $directory . '/created-comment.json';

    mkdir($bin, 0o700, recursive: true);
    file_put_contents($adf, '');
    file_put_contents($calls, '');
    file_put_contents($created, '');
    file_put_contents($bin . '/acli', JIRA_COMMENT_ACLI_SCRIPT);
    chmod($bin . '/acli', 0o700);

    return [
        'adf' => $adf,
        'bin' => $bin,
        'calls' => $calls,
        'created' => $created,
        'directory' => $directory,
    ];
}

/**
 * @param array{adf: string, bin: string, calls: string, created: string, directory: string} $fixture
 */
function removeJiraCommentPublisherFixture(array $fixture): void
{
    unlink($fixture['bin'] . '/acli');
    unlink($fixture['adf']);
    unlink($fixture['calls']);
    unlink($fixture['created']);
    rmdir($fixture['bin']);
    rmdir($fixture['directory']);
}

test('JIRA comments are published as rendered ADF instead of literal Wiki Markup', function (): void {
    $packageDir = dirname(__DIR__, 3);
    $fixture = createJiraCommentPublisherFixture();
    $systemPath = jiraCommentSystemPath();
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
        'FAKE_ACLI_CREATE_BODY' => $fixture['created'],
        'FAKE_ACLI_CREATE_JSON' => '{"id":"10001"}',
        'FAKE_ACLI_UPDATE_OK' => '1',
        'PATH' => $fixture['bin'] . PATH_SEPARATOR . $systemPath,
    ], $body);

    try {
        $process->run();
        $adf = (string) file_get_contents($fixture['adf']);
        $calls = (string) file_get_contents($fixture['calls']);
        $created = (string) file_get_contents($fixture['created']);

        expect($process->getExitCode())->toBe(0)
            ->and($process->getOutput())->toContain('focusedCommentId=10001')
            ->and($calls)->toContain('comment create --key TEAM-42 --body-file')
            ->and($calls)->toContain('comment update --key TEAM-42 --id 10001 --body-adf')
            ->and($created)->toBe(JIRA_COMMENT_EXPECTED_ADF . "\n")
            ->and($adf)->toBe(JIRA_COMMENT_EXPECTED_ADF . "\n");
    } finally {
        removeJiraCommentPublisherFixture($fixture);
    }
});

test('JIRA ADF publishing fails closed when create omits the new comment ID', function (): void {
    $packageDir = dirname(__DIR__, 3);
    $fixture = createJiraCommentPublisherFixture();
    $systemPath = jiraCommentSystemPath();
    $process = new Process([
        $packageDir . '/skills/code-review-jira/scripts/upsert-comment.sh',
        'TEAM-42',
        '-',
    ], $packageDir, [
        'FAKE_ACLI_ADF' => $fixture['adf'],
        'FAKE_ACLI_CALLS' => $fixture['calls'],
        'FAKE_ACLI_CREATE_BODY' => $fixture['created'],
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
    $systemPath = jiraCommentSystemPath();
    $process = new Process([
        $packageDir . '/skills/code-review-jira/scripts/upsert-comment.sh',
        'TEAM-42',
        '-',
    ], $packageDir, [
        'FAKE_ACLI_ADF' => $fixture['adf'],
        'FAKE_ACLI_CALLS' => $fixture['calls'],
        'FAKE_ACLI_CREATE_BODY' => $fixture['created'],
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
