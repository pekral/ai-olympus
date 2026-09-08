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
  if [[ -n "${FAKE_ACLI_EMAIL:-}" ]]; then
    printf '%s\n' "Email: ${FAKE_ACLI_EMAIL}"
  fi
  exit 0
fi

if [[ "$1" == "jira" && "$2" == "workitem" && "$3" == "comment" && "$4" == "list" ]]; then
  if [[ "${FAKE_ACLI_LIST_OK:-1}" != "1" ]]; then
    exit 1
  fi

  printf '%s\n' "${FAKE_ACLI_LIST_JSON:-[]}"
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

/**
 * @return array{id: string, created: string, author: array{emailAddress: string}, body: array{content: array<int, array{text: string}>}}
 */
function jiraComment(string $id, string $created, string $author, string $text): array
{
    return ['id' => $id, 'created' => $created, 'author' => ['emailAddress' => $author], 'body' => ['content' => [['text' => $text]]]];
}

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

test('JIRA comments are published as rendered ADF instead of literal Wiki Markup (unmarked fallback, no acli e-mail)', function (): void {
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
        'FAKE_ACLI_EMAIL' => '',
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
        'FAKE_ACLI_EMAIL' => '',
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
        'FAKE_ACLI_EMAIL' => '',
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

test('the JIRA publisher updates the comment already carrying this actor\'s marker', function (): void {
    $packageDir = dirname(__DIR__, 3);
    $fixture = createJiraCommentPublisherFixture();
    $systemPath = jiraCommentSystemPath();
    $listJson = json_encode([
        jiraComment('9001', '2026-01-01T00:00:00.000+0000', 'other@example.com', 'someone else'),
        jiraComment('9002', '2026-01-02T00:00:00.000+0000', 'bot@example.com', 'cr-comment:actor=bot@example.com'),
        jiraComment('9003', '2026-01-03T00:00:00.000+0000', 'other@example.com', 'cr-comment:actor=other@example.com'),
    ], JSON_THROW_ON_ERROR);

    $process = new Process([
        $packageDir . '/skills/code-review-jira/scripts/upsert-comment.sh',
        'TEAM-42',
        '-',
    ], $packageDir, [
        'FAKE_ACLI_ADF' => $fixture['adf'],
        'FAKE_ACLI_CALLS' => $fixture['calls'],
        'FAKE_ACLI_CREATE_BODY' => $fixture['created'],
        'FAKE_ACLI_CREATE_JSON' => '{"id":"10001"}',
        'FAKE_ACLI_EMAIL' => 'bot@example.com',
        'FAKE_ACLI_LIST_JSON' => $listJson,
        'FAKE_ACLI_UPDATE_OK' => '1',
        'PATH' => $fixture['bin'] . PATH_SEPARATOR . $systemPath,
    ], 'h2. Round two');

    try {
        $process->run();
        $calls = (string) file_get_contents($fixture['calls']);
        $adf = (string) file_get_contents($fixture['adf']);

        expect($process->getExitCode())->toBe(0)
            // The existing comment is updated in place; no second comment is created.
            ->and($calls)->toContain('comment list --key TEAM-42 --json --paginate')
            ->and($calls)->toContain('comment update --key TEAM-42 --id 9002 --body-adf')
            ->and($calls)->not->toContain('comment create')
            ->and($calls)->not->toContain('comment delete')
            ->and($process->getErrorOutput())->toContain('action=updated id=9002')
            ->and($process->getOutput())->toContain('focusedCommentId=9002')
            // The marker travels in the published ADF, so the next run finds this comment again.
            ->and($adf)->toContain('cr-comment:actor=bot@example.com');
    } finally {
        removeJiraCommentPublisherFixture($fixture);
    }
});

test('a newer comment from another author carrying this account\'s marker is never the update target', function (): void {
    $packageDir = dirname(__DIR__, 3);
    $fixture = createJiraCommentPublisherFixture();
    $systemPath = jiraCommentSystemPath();
    // The JIRA marker is a visible line anyone can copy into their own comment.
    // Matching on the marker alone would make the newer foreign comment the
    // target and overwrite a stranger's content.
    $listJson = json_encode([
        jiraComment('9101', '2026-03-01T00:00:00.000+0000', 'bot@example.com', 'round one _cr-comment:actor=bot@example.com_'),
        jiraComment('9102', '2026-03-02T00:00:00.000+0000', 'impostor@example.com', 'quoting _cr-comment:actor=bot@example.com_ back at you'),
    ], JSON_THROW_ON_ERROR);

    $process = new Process([
        $packageDir . '/skills/code-review-jira/scripts/upsert-comment.sh',
        'TEAM-42',
        '-',
    ], $packageDir, [
        'FAKE_ACLI_ADF' => $fixture['adf'],
        'FAKE_ACLI_CALLS' => $fixture['calls'],
        'FAKE_ACLI_CREATE_BODY' => $fixture['created'],
        'FAKE_ACLI_CREATE_JSON' => '{"id":"10011"}',
        'FAKE_ACLI_EMAIL' => 'bot@example.com',
        'FAKE_ACLI_LIST_JSON' => $listJson,
        'FAKE_ACLI_UPDATE_OK' => '1',
        'PATH' => $fixture['bin'] . PATH_SEPARATOR . $systemPath,
    ], 'h2. Round two');

    try {
        $process->run();
        $calls = (string) file_get_contents($fixture['calls']);

        expect($process->getExitCode())->toBe(0)
            // This account's own older comment is the match.
            ->and($calls)->toContain('comment update --key TEAM-42 --id 9101 --body-adf')
            // The impostor's newer comment is never touched.
            ->and($calls)->not->toContain('--id 9102')
            ->and($process->getErrorOutput())->toContain('action=updated id=9101');
    } finally {
        removeJiraCommentPublisherFixture($fixture);
    }
});

test('the JIRA publisher creates a marked comment when no marker-carrying comment exists', function (): void {
    $packageDir = dirname(__DIR__, 3);
    $fixture = createJiraCommentPublisherFixture();
    $systemPath = jiraCommentSystemPath();
    $listJson = json_encode([
        'comments' => [
            jiraComment('9001', '2026-01-01T00:00:00.000+0000', 'other@example.com', 'cr-comment:actor=other@example.com'),
        ],
    ], JSON_THROW_ON_ERROR);

    $process = new Process([
        $packageDir . '/skills/code-review-jira/scripts/upsert-comment.sh',
        'TEAM-42',
        '-',
    ], $packageDir, [
        'FAKE_ACLI_ADF' => $fixture['adf'],
        'FAKE_ACLI_CALLS' => $fixture['calls'],
        'FAKE_ACLI_CREATE_BODY' => $fixture['created'],
        'FAKE_ACLI_CREATE_JSON' => '{"id":"10007"}',
        'FAKE_ACLI_EMAIL' => 'bot@example.com',
        'FAKE_ACLI_LIST_JSON' => $listJson,
        'FAKE_ACLI_UPDATE_OK' => '1',
        'PATH' => $fixture['bin'] . PATH_SEPARATOR . $systemPath,
    ], 'h2. Round one');

    try {
        $process->run();
        $calls = (string) file_get_contents($fixture['calls']);
        $created = (string) file_get_contents($fixture['created']);

        expect($process->getExitCode())->toBe(0)
            // Another actor's marker is never a match, so this run creates its own comment.
            ->and($calls)->toContain('comment list --key TEAM-42 --json --paginate')
            ->and($calls)->toContain('comment create --key TEAM-42 --body-file')
            ->and($calls)->toContain('comment update --key TEAM-42 --id 10007 --body-adf')
            ->and($process->getErrorOutput())->toContain('action=created id=10007')
            ->and($created)->toContain('cr-comment:actor=bot@example.com');
    } finally {
        removeJiraCommentPublisherFixture($fixture);
    }
});

test('a failed JIRA comment lookup falls back to creating a comment instead of blocking the publish', function (): void {
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
        'FAKE_ACLI_CREATE_JSON' => '{"id":"10009"}',
        'FAKE_ACLI_EMAIL' => 'bot@example.com',
        'FAKE_ACLI_LIST_OK' => '0',
        'FAKE_ACLI_UPDATE_OK' => '1',
        'PATH' => $fixture['bin'] . PATH_SEPARATOR . $systemPath,
    ], 'h2. Lookup down');

    try {
        $process->run();
        $calls = (string) file_get_contents($fixture['calls']);

        expect($process->getExitCode())->toBe(0)
            ->and($process->getErrorOutput())->toContain('comment lookup failed on TEAM-42, publishing a new comment instead')
            ->and($process->getErrorOutput())->toContain('action=created id=10009')
            ->and($calls)->toContain('comment create --key TEAM-42 --body-file');
    } finally {
        removeJiraCommentPublisherFixture($fixture);
    }
});
