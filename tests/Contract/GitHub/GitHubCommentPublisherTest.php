<?php

declare(strict_types = 1);

use Symfony\Component\Process\Process;

const GITHUB_COMMENT_GH_SCRIPT = <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail

printf '%q ' "$@" >> "$FAKE_GH_CALLS"
printf '\n' >> "$FAKE_GH_CALLS"

if [[ "$1" == "api" && "$2" == "user" ]]; then
  printf '%s\n' "$FAKE_GH_ACTOR"
  exit 0
fi

# Comment lookup: `gh api repos/<nwo>/issues/<n>/comments --paginate`.
if [[ "$1" == "api" && "$2" == *"/comments" && "$*" == *"--paginate"* ]]; then
  if [[ "${FAKE_GH_LIST_OK:-1}" != "1" ]]; then
    echo 'gh: Not Found (HTTP 404)' >&2
    exit 1
  fi

  printf '%s\n' "${FAKE_GH_LIST_JSON:-[]}"
  exit 0
fi

# Publish: either a PATCH on an existing comment or a POST of a new one.
if [[ "$1" == "api" ]]; then
  cat > "$FAKE_GH_BODY"
  printf '%s\n' "$FAKE_GH_RESPONSE"
  exit 0
fi

exit 1
BASH;

/**
 * @return array{id: int, created_at: string, user: array{login: string}, body: string}
 */
function githubComment(int $id, string $createdAt, string $author, string $body): array
{
    return ['id' => $id, 'created_at' => $createdAt, 'user' => ['login' => $author], 'body' => $body];
}

function githubCommentSystemPath(): string
{
    $systemPath = getenv('PATH');

    return $systemPath === false ? '/usr/bin:/bin' : $systemPath;
}

/**
 * @return array{bin: string, body: string, calls: string, directory: string}
 */
function createGitHubCommentPublisherFixture(): array
{
    $directory = sys_get_temp_dir() . '/ai-olympus-github-comment-' . bin2hex(random_bytes(6));
    $bin = $directory . '/bin';
    $body = $directory . '/payload.json';
    $calls = $directory . '/calls';

    mkdir($bin, 0o700, recursive: true);
    file_put_contents($body, '');
    file_put_contents($calls, '');
    file_put_contents($bin . '/gh', GITHUB_COMMENT_GH_SCRIPT);
    chmod($bin . '/gh', 0o700);

    return [
        'bin' => $bin,
        'body' => $body,
        'calls' => $calls,
        'directory' => $directory,
    ];
}

/**
 * @param array{bin: string, body: string, calls: string, directory: string} $fixture
 */
function removeGitHubCommentPublisherFixture(array $fixture): void
{
    unlink($fixture['bin'] . '/gh');
    unlink($fixture['body']);
    unlink($fixture['calls']);
    rmdir($fixture['bin']);
    rmdir($fixture['directory']);
}

/**
 * @param array{bin: string, body: string, calls: string, directory: string} $fixture
 * @param array<string, string> $environment
 */
function runGitHubCommentPublisher(array $fixture, array $environment, string $body): Process
{
    $packageDir = dirname(__DIR__, 3);
    $process = new Process([
        $packageDir . '/skills/code-review-github/scripts/upsert-comment.sh',
        'https://github.com/acme/widgets/pull/42',
        '-',
    ], $packageDir, [
        'FAKE_GH_ACTOR' => 'bot',
        'FAKE_GH_BODY' => $fixture['body'],
        'FAKE_GH_CALLS' => $fixture['calls'],
        'PATH' => $fixture['bin'] . PATH_SEPARATOR . githubCommentSystemPath(),
        ...$environment,
    ], $body);

    $process->run();

    return $process;
}

test('the GitHub publisher PATCHes the comment already carrying this actor\'s marker', function (): void {
    $fixture = createGitHubCommentPublisherFixture();
    $listJson = json_encode([
        githubComment(501, '2026-01-01T00:00:00Z', 'someone', 'unrelated'),
        githubComment(502, '2026-01-02T00:00:00Z', 'bot', "old round\n\n<!-- cr-comment:actor=bot -->"),
        githubComment(503, '2026-01-03T00:00:00Z', 'someone', "other actor\n\n<!-- cr-comment:actor=someone -->"),
    ], JSON_THROW_ON_ERROR);

    try {
        $process = runGitHubCommentPublisher($fixture, [
            'FAKE_GH_LIST_JSON' => $listJson,
            'FAKE_GH_RESPONSE' => '{"id":502,"html_url":"https://github.com/acme/widgets/pull/42#issuecomment-502"}',
        ], 'Round two body');

        $calls = (string) file_get_contents($fixture['calls']);
        $payload = (string) file_get_contents($fixture['body']);

        expect($process->getExitCode())->toBe(0)
            // The newest comment carrying this actor's marker is updated in place.
            ->and($calls)->toContain('repos/acme/widgets/issues/comments/502 -X PATCH')
            ->and($calls)->not->toContain('-X POST')
            ->and($process->getErrorOutput())->toContain('action=updated id=502')
            ->and($process->getOutput())->toContain('#issuecomment-502')
            // The marker travels in the published body, so the next run finds it again.
            ->and($payload)->toContain('<!-- cr-comment:actor=bot -->');
    } finally {
        removeGitHubCommentPublisherFixture($fixture);
    }
});

test('a newer comment from another author quoting this actor\'s marker is never the PATCH target', function (): void {
    $fixture = createGitHubCommentPublisherFixture();
    // The marker is text: a quote reply, or a hand-written comment, carries it
    // verbatim under a different author. Newest-match-wins on the marker alone
    // would make that comment the target and overwrite a stranger's content.
    $listJson = json_encode([
        githubComment(401, '2026-02-01T00:00:00Z', 'bot', "round one\n\n<!-- cr-comment:actor=bot -->"),
        githubComment(402, '2026-02-02T00:00:00Z', 'impostor', "> round one\n>\n> <!-- cr-comment:actor=bot -->\n\nMy reply."),
    ], JSON_THROW_ON_ERROR);

    try {
        $process = runGitHubCommentPublisher($fixture, [
            'FAKE_GH_LIST_JSON' => $listJson,
            'FAKE_GH_RESPONSE' => '{"id":401,"html_url":"https://github.com/acme/widgets/pull/42#issuecomment-401"}',
        ], 'Round two body');

        $calls = (string) file_get_contents($fixture['calls']);

        expect($process->getExitCode())->toBe(0)
            // This actor's own older comment is the match.
            ->and($calls)->toContain('repos/acme/widgets/issues/comments/401 -X PATCH')
            // The impostor's newer comment is never touched.
            ->and($calls)->not->toContain('repos/acme/widgets/issues/comments/402')
            ->and($process->getErrorOutput())->toContain('action=updated id=401');
    } finally {
        removeGitHubCommentPublisherFixture($fixture);
    }
});

test('the GitHub publisher POSTs a new comment when no marker-carrying comment exists', function (): void {
    $fixture = createGitHubCommentPublisherFixture();
    $listJson = json_encode([
        githubComment(601, '2026-01-01T00:00:00Z', 'someone', "other actor\n\n<!-- cr-comment:actor=someone -->"),
    ], JSON_THROW_ON_ERROR);

    try {
        $process = runGitHubCommentPublisher($fixture, [
            'FAKE_GH_LIST_JSON' => $listJson,
            'FAKE_GH_RESPONSE' => '{"id":700,"html_url":"https://github.com/acme/widgets/pull/42#issuecomment-700"}',
        ], 'Round one body');

        $calls = (string) file_get_contents($fixture['calls']);

        expect($process->getExitCode())->toBe(0)
            // Another actor's marker is never a match, so this run posts its own comment.
            ->and($calls)->toContain('repos/acme/widgets/issues/42/comments -X POST')
            ->and($calls)->not->toContain('-X PATCH')
            ->and($process->getErrorOutput())->toContain('action=created id=700');
    } finally {
        removeGitHubCommentPublisherFixture($fixture);
    }
});

test('a failed GitHub comment lookup warns and falls back to a new comment', function (): void {
    $fixture = createGitHubCommentPublisherFixture();

    try {
        $process = runGitHubCommentPublisher($fixture, [
            'FAKE_GH_LIST_OK' => '0',
            'FAKE_GH_RESPONSE' => '{"id":800,"html_url":"https://github.com/acme/widgets/pull/42#issuecomment-800"}',
        ], 'Lookup down body');

        $calls = (string) file_get_contents($fixture['calls']);

        expect($process->getExitCode())->toBe(0)
            // The error is surfaced, never swallowed, and the publish still happens.
            ->and($process->getErrorOutput())->toContain('comment lookup failed on acme/widgets#42, publishing a new comment instead')
            ->and($process->getErrorOutput())->toContain('gh: Not Found (HTTP 404)')
            ->and($process->getErrorOutput())->toContain('action=created id=800')
            ->and($calls)->toContain('repos/acme/widgets/issues/42/comments -X POST');
    } finally {
        removeGitHubCommentPublisherFixture($fixture);
    }
});

test('a marker namespace other than cr-comment matches only its own comment', function (): void {
    $fixture = createGitHubCommentPublisherFixture();
    $listJson = json_encode([
        githubComment(901, '2026-01-01T00:00:00Z', 'bot', "cr\n\n<!-- cr-comment:actor=bot -->"),
    ], JSON_THROW_ON_ERROR);

    $packageDir = dirname(__DIR__, 3);
    $process = new Process([
        $packageDir . '/skills/code-review-github/scripts/upsert-comment.sh',
        'https://github.com/acme/widgets/pull/42',
        '-',
        'other-namespace',
    ], $packageDir, [
        'FAKE_GH_ACTOR' => 'bot',
        'FAKE_GH_BODY' => $fixture['body'],
        'FAKE_GH_CALLS' => $fixture['calls'],
        'FAKE_GH_LIST_JSON' => $listJson,
        'FAKE_GH_RESPONSE' => '{"id":950,"html_url":"https://github.com/acme/widgets/pull/42#issuecomment-950"}',
        'PATH' => $fixture['bin'] . PATH_SEPARATOR . githubCommentSystemPath(),
    ], 'Namespaced body');

    try {
        $process->run();
        $payload = (string) file_get_contents($fixture['body']);

        expect($process->getExitCode())->toBe(0)
            // The cr-comment comment belongs to a different namespace, so it is not the match.
            ->and($process->getErrorOutput())->toContain('action=created id=950')
            ->and($payload)->toContain('<!-- other-namespace:actor=bot -->');
    } finally {
        removeGitHubCommentPublisherFixture($fixture);
    }
});
