<?php

declare(strict_types = 1);

use Symfony\Component\Process\Process;

// `acli` stands in for the real CLI. Every call is appended to `$FAKE_ACLI_CALLS`; a successful
// `comment delete` creates `$FAKE_ACLI_DELETED`, after which `view` serves the post-delete state.
const JIRA_DELETE_ACLI_SCRIPT = <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail

printf '%q ' "$@" >> "$FAKE_ACLI_CALLS"
printf '\n' >> "$FAKE_ACLI_CALLS"

if [[ "$1" == "jira" && "$2" == "auth" && "$3" == "status" ]]; then
  printf '%s\n' 'Site: example.atlassian.net' "Email: ${FAKE_ACLI_EMAIL}"
  exit 0
fi

if [[ "$1" == "jira" && "$2" == "workitem" && "$3" == "view" ]]; then
  if [[ -f "$FAKE_ACLI_DELETED" ]]; then
    printf '%s\n' "$FAKE_ACLI_VIEW_AFTER_JSON"
  else
    printf '%s\n' "$FAKE_ACLI_VIEW_JSON"
  fi
  exit 0
fi

if [[ "$1" == "jira" && "$2" == "workitem" && "$3" == "comment" && "$4" == "delete" ]]; then
  : > "$FAKE_ACLI_DELETED"
  exit 0
fi

exit 1
BASH;

const JIRA_DELETE_EMAIL = 'agent@example.com';

function jiraDeleteMarker(): string
{
    return '_cr-comment:actor=' . substr(hash('sha256', JIRA_DELETE_EMAIL), 0, 16) . '_';
}

/**
 * @return array<string, mixed>
 */
function jiraDeleteComment(string $id, string $accountId, string $text, ?string $email = null): array
{
    $author = ['accountId' => $accountId, 'displayName' => 'Agent'];

    if ($email !== null) {
        $author['emailAddress'] = $email;
    }

    return [
        'author' => $author,
        'body' => [
            'content' => [['content' => [['text' => $text, 'type' => 'text']], 'type' => 'paragraph']],
            'type' => 'doc',
            'version' => 1,
        ],
        'id' => $id,
    ];
}

/**
 * @param list<array<string, mixed>> $comments
 */
function jiraDeleteViewJson(array $comments): string
{
    return (string) json_encode(['fields' => ['comment' => ['comments' => $comments, 'total' => count($comments)]], 'key' => 'ACME-1234']);
}

/**
 * @param list<string> $arguments
 * @param list<array<string, mixed>> $before
 * @param list<array<string, mixed>> $after
 * @return array{process: \Symfony\Component\Process\Process, calls: string, deleted: bool}
 */
function runJiraDeleteHelper(array $arguments, array $before, array $after): array
{
    $packageDir = dirname(__DIR__, 3);
    $root = installerCreateProjectRoot();
    $fakeBin = $root . '/bin';
    $calls = $root . '/calls';
    $deleted = $root . '/deleted';
    $path = getenv('PATH');

    installerWriteFile($fakeBin . '/acli', JIRA_DELETE_ACLI_SCRIPT);
    chmod($fakeBin . '/acli', 0755);
    file_put_contents($calls, '');

    try {
        $process = new Process([
            $packageDir . '/skills/code-review-jira/scripts/delete-owned-comment.sh',
            ...$arguments,
        ], $packageDir, [
            'FAKE_ACLI_CALLS' => $calls,
            'FAKE_ACLI_DELETED' => $deleted,
            'FAKE_ACLI_EMAIL' => JIRA_DELETE_EMAIL,
            'FAKE_ACLI_VIEW_AFTER_JSON' => jiraDeleteViewJson($after),
            'FAKE_ACLI_VIEW_JSON' => jiraDeleteViewJson($before),
            'PATH' => $fakeBin . PATH_SEPARATOR . (is_string($path) ? $path : ''),
        ]);
        $process->run();

        return ['calls' => (string) file_get_contents($calls), 'deleted' => is_file($deleted), 'process' => $process];
    } finally {
        installerRemoveDirectory($root);
    }
}

/**
 * The final TL;DR (900) and the orphaned duplicate (123) a failed publish left behind, both
 * published by this actor under its marker.
 *
 * @return list<array<string, mixed>>
 */
function jiraDeleteOwnedComments(string $orphanAccount = 'acc-agent', string $orphanText = 'raw ## Markdown'): array
{
    return [
        jiraDeleteComment('123', $orphanAccount, $orphanText . ' ' . jiraDeleteMarker()),
        jiraDeleteComment('900', 'acc-agent', 'Final TL;DR ' . jiraDeleteMarker()),
    ];
}

test('the JIRA comment delete helper refuses a protected id before contacting JIRA', function (): void {
    $result = runJiraDeleteHelper(['ACME-1234', '900', '900', '900'], jiraDeleteOwnedComments(), []);

    expect($result['process']->getExitCode())->toBe(4);
    expect($result['process']->getErrorOutput())->toContain('protected final comment');
    expect($result['calls'])->toBe('');
});

test('the JIRA comment delete helper deletes an actor-owned duplicate and verifies it is gone', function (): void {
    $after = [jiraDeleteComment('900', 'acc-agent', 'Final TL;DR ' . jiraDeleteMarker())];

    $result = runJiraDeleteHelper(['https://acme.atlassian.net/browse/ACME-1234', '123', '900', '900'], jiraDeleteOwnedComments(), $after);

    expect($result['process']->getExitCode())->toBe(0);
    expect($result['process']->getOutput())->toContain('deleted id=123 key=ACME-1234');
    expect($result['calls'])->toContain('workitem comment delete --key ACME-1234 --id 123');
});

test('the JIRA comment delete helper refuses a comment whose copied marker belongs to another account', function (): void {
    $result = runJiraDeleteHelper(['ACME-1234', '123', '900', '900'], jiraDeleteOwnedComments(orphanAccount: 'acc-stranger'), []);

    expect($result['process']->getExitCode())->toBe(4);
    expect($result['process']->getErrorOutput())->toContain('not owned by the authenticated actor');
    expect($result['deleted'])->toBeFalse();
});

test('the JIRA comment delete helper refuses an unmarked comment and an unmarked protected comment', function (): void {
    $unmarkedTarget = [
        jiraDeleteComment('123', 'acc-agent', ''),
        jiraDeleteComment('900', 'acc-agent', 'Final TL;DR ' . jiraDeleteMarker()),
    ];
    $unmarkedProtected = [
        jiraDeleteComment('123', 'acc-agent', 'orphan ' . jiraDeleteMarker()),
        jiraDeleteComment('900', 'acc-agent', 'Final TL;DR without a marker'),
    ];

    $target = runJiraDeleteHelper(['ACME-1234', '123', '900', '900'], $unmarkedTarget, []);
    $protected = runJiraDeleteHelper(['ACME-1234', '123', '900', '900'], $unmarkedProtected, []);

    expect($target['process']->getExitCode())->toBe(4);
    expect($target['process']->getErrorOutput())->toContain('comment 123 is not on ACME-1234 or does not carry');
    expect($target['deleted'])->toBeFalse();
    expect($protected['process']->getExitCode())->toBe(4);
    expect($protected['process']->getErrorOutput())->toContain('protected comment 900');
    expect($protected['deleted'])->toBeFalse();
});

test('the JIRA comment delete helper refuses a comment whose visible e-mail names another account', function (): void {
    $comments = [
        jiraDeleteComment('123', 'acc-agent', 'orphan ' . jiraDeleteMarker(), 'someone@example.com'),
        jiraDeleteComment('900', 'acc-agent', 'Final TL;DR ' . jiraDeleteMarker(), JIRA_DELETE_EMAIL),
    ];

    $result = runJiraDeleteHelper(['ACME-1234', '123', '900', '900'], $comments, []);

    expect($result['process']->getExitCode())->toBe(4);
    expect($result['process']->getErrorOutput())->toContain('authored by another account');
    expect($result['deleted'])->toBeFalse();
});

test('the JIRA comment delete helper fails when the comment is still readable after the delete', function (): void {
    $result = runJiraDeleteHelper(['ACME-1234', '123', '900', '900'], jiraDeleteOwnedComments(), jiraDeleteOwnedComments());

    expect($result['process']->getExitCode())->toBe(3);
    expect($result['process']->getErrorOutput())->toContain('comment 123 is still on ACME-1234');
});

test('the JIRA publisher and the delete helper derive the actor digest from one shared function', function (): void {
    $scripts = dirname(__DIR__, 3) . '/skills/code-review-jira/scripts/';

    foreach (['upsert-comment.sh', 'delete-owned-comment.sh'] as $script) {
        $content = (string) file_get_contents($scripts . $script);

        expect($content)->toContain('source "$SCRIPT_DIR/jira-actor.sh"');
        expect($content)->toContain('jira_actor_digest "$EMAIL"');
    }
});
