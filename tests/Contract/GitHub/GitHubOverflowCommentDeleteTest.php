<?php

declare(strict_types = 1);

use Symfony\Component\Process\Process;

const GITHUB_OVERFLOW_DELETE_GH_SCRIPT = <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail

if [[ "$1" == "api" && "$2" == "user" ]]; then
  printf '%s\n' 'bot'
  exit 0
fi

if [[ "$1" == "api" && "$2" == "--method" && "$3" == "DELETE" ]]; then
  : > "$FAKE_GH_STATE"
  exit 0
fi

if [[ "$1" == "api" && "$2" == "--include" ]]; then
  printf '%s\n' 'HTTP/2.0 404 Not Found' >&2
  exit 1
fi

# The comment list: `gh api repos/<nwo>/issues/<n>/comments --paginate`.
if [[ "$1" == "api" && "$2" == */issues/42/comments && "$*" == *"--paginate"* ]]; then
  if [[ "${FAKE_GH_LIST_OK:-1}" != "1" ]]; then
    exit 1
  fi

  cat "$FAKE_GH_COMMENTS"
  exit 0
fi

# One comment: `gh api repos/<nwo>/issues/comments/<id>`.
if [[ "$1" == "api" && "$2" == repos/*/issues/comments/* ]]; then
  jq -c --argjson id "${2##*/}" '.[] | select(.id == $id)' "$FAKE_GH_COMMENTS"
  exit 0
fi

exit 90
BASH;

/**
 * @return array{id: int, issue_url: string, user: array{login: string}, body: string}
 */
function overflowDeleteComment(int $id, string $author, string $body): array
{
    return [
        'body' => $body,
        'id' => $id,
        'issue_url' => 'https://api.github.com/repos/pekral/ai-olympus/issues/42',
        'user' => ['login' => $author],
    ];
}

/**
 * @param list<string> $arguments
 * @param array<string, string> $environment
 */
function runOverflowCommentDelete(string $directory, array $arguments, array $environment = []): Process
{
    $packageDir = dirname(__DIR__, 3);
    $systemPath = getenv('PATH');
    $process = new Process([
        $packageDir . '/skills/_shared/delete-owned-github-comment.sh',
        'https://github.com/pekral/ai-olympus/pull/42',
        ...$arguments,
    ], $packageDir, $environment + [
        'FAKE_GH_COMMENTS' => $directory . '/comments.json',
        'FAKE_GH_STATE' => $directory . '/deleted',
        'PATH' => $directory . '/bin' . PATH_SEPARATOR . ($systemPath === false ? '/usr/bin:/bin' : $systemPath),
    ]);
    $process->run();

    return $process;
}

test('the delete helper removes an overflow comment only when a newer overflow comment of the same actor exists', function (): void {
    $directory = installerCreateProjectRoot();
    installerWriteFile($directory . '/bin/gh', GITHUB_OVERFLOW_DELETE_GH_SCRIPT);
    chmod($directory . '/bin/gh', 0755);
    $heading = "## Pull-request body overflow\n\nAudit lines\n\n";
    file_put_contents($directory . '/comments.json', json_encode([
        overflowDeleteComment(801, 'bot', $heading . '<!-- agent-note:actor=bot -->'),
        overflowDeleteComment(802, 'bot', $heading . '<!-- pr-body-overflow:actor=bot -->'),
        overflowDeleteComment(803, 'stranger', $heading . '<!-- pr-body-overflow:actor=stranger -->'),
        overflowDeleteComment(804, 'bot', 'not an overflow comment'),
    ], JSON_THROW_ON_ERROR));

    try {
        $older = runOverflowCommentDelete($directory, ['801']);

        expect($older->getExitCode())->toBe(0)
            ->and($older->getOutput())->toContain('deleted id=801')
            ->and(is_file($directory . '/deleted'))->toBeTrue();
        unlink($directory . '/deleted');

        $newest = runOverflowCommentDelete($directory, ['802']);

        expect($newest->getExitCode())->toBe(4)
            ->and($newest->getErrorOutput())->toContain('refusing to delete a pull-request body overflow comment')
            ->and(is_file($directory . '/deleted'))->toBeFalse();

        $foreign = runOverflowCommentDelete($directory, ['803']);

        expect($foreign->getExitCode())->toBe(4)
            ->and($foreign->getErrorOutput())->toContain('not owned by the authenticated actor')
            ->and(is_file($directory . '/deleted'))->toBeFalse();

        $plain = runOverflowCommentDelete($directory, ['804']);

        expect($plain->getExitCode())->toBe(4)
            ->and($plain->getErrorOutput())->toContain('deletes only a superseded pull-request body overflow comment')
            ->and(is_file($directory . '/deleted'))->toBeFalse();
    } finally {
        installerRemoveDirectory($directory);
    }
});

test('a newer overflow comment of another author never makes the actor\'s newest copy deletable', function (): void {
    $directory = installerCreateProjectRoot();
    installerWriteFile($directory . '/bin/gh', GITHUB_OVERFLOW_DELETE_GH_SCRIPT);
    chmod($directory . '/bin/gh', 0755);
    file_put_contents($directory . '/comments.json', json_encode([
        overflowDeleteComment(901, 'bot', "## Pull-request body overflow\n\nAudit lines\n\n<!-- pr-body-overflow:actor=bot -->"),
        overflowDeleteComment(902, 'stranger', "## Pull-request body overflow\n\nQuoted copy\n\n<!-- pr-body-overflow:actor=stranger -->"),
    ], JSON_THROW_ON_ERROR));

    try {
        $process = runOverflowCommentDelete($directory, ['901']);

        expect($process->getExitCode())->toBe(4)
            ->and($process->getErrorOutput())->toContain('refusing to delete a pull-request body overflow comment')
            ->and(is_file($directory . '/deleted'))->toBeFalse();
    } finally {
        installerRemoveDirectory($directory);
    }
});

test('an overflow comment is never deleted on a quoted heading or on a failed lookup of the newer copy', function (): void {
    $directory = installerCreateProjectRoot();
    installerWriteFile($directory . '/bin/gh', GITHUB_OVERFLOW_DELETE_GH_SCRIPT);
    chmod($directory . '/bin/gh', 0755);
    file_put_contents($directory . '/comments.json', json_encode([
        overflowDeleteComment(911, 'bot', "## Pull-request body overflow\n\nAudit lines\n\n<!-- pr-body-overflow:actor=bot -->"),
        overflowDeleteComment(912, 'bot', "> ## Pull-request body overflow\n\nA reply that quotes the heading"),
        overflowDeleteComment(913, 'bot', 'See the ## Pull-request body overflow comment above'),
    ], JSON_THROW_ON_ERROR));

    try {
        $quoted = runOverflowCommentDelete($directory, ['911']);

        expect($quoted->getExitCode())->toBe(4)
            ->and($quoted->getErrorOutput())->toContain('refusing to delete a pull-request body overflow comment')
            ->and(is_file($directory . '/deleted'))->toBeFalse();

        $failedLookup = runOverflowCommentDelete($directory, ['911'], ['FAKE_GH_LIST_OK' => '0']);

        expect($failedLookup->getExitCode())->toBe(4)
            ->and($failedLookup->getErrorOutput())->toContain('refusing to delete a pull-request body overflow comment')
            ->and(is_file($directory . '/deleted'))->toBeFalse();
    } finally {
        installerRemoveDirectory($directory);
    }
});

test('a fenced quote of the heading never makes a merge-readiness comment deletable in the two-argument form', function (): void {
    $directory = installerCreateProjectRoot();
    installerWriteFile($directory . '/bin/gh', GITHUB_OVERFLOW_DELETE_GH_SCRIPT);
    chmod($directory . '/bin/gh', 0755);
    $fence = "```\n## Pull-request body overflow\n```";
    file_put_contents($directory . '/comments.json', json_encode([
        overflowDeleteComment(1_001, 'bot', "Merge readiness\n\n" . $fence . "\n\n<!-- merge-readiness:actor=bot -->"),
        overflowDeleteComment(1_002, 'bot', "Review\n\n" . $fence . "\n\n<!-- cr-comment:actor=bot -->"),
        overflowDeleteComment(
            1003,
            'bot',
            "## Pull-request body overflow\n\nMoved\n\n<!-- merge-readiness:actor=bot -->\n<!-- pr-body-overflow:actor=bot -->",
        ),
        overflowDeleteComment(1_004, 'bot', "## Pull-request body overflow\n\nNewest\n\n<!-- pr-body-overflow:actor=bot -->"),
    ], JSON_THROW_ON_ERROR));

    try {
        foreach (['1001', '1002', '1003'] as $id) {
            $process = runOverflowCommentDelete($directory, [$id]);

            expect($process->getExitCode())->toBe(4)
                ->and(is_file($directory . '/deleted'))->toBeFalse();
        }
    } finally {
        installerRemoveDirectory($directory);
    }
});

test('a newer comment that only quotes the heading in a fence never supersedes the only overflow copy', function (): void {
    $directory = installerCreateProjectRoot();
    installerWriteFile($directory . '/bin/gh', GITHUB_OVERFLOW_DELETE_GH_SCRIPT);
    chmod($directory . '/bin/gh', 0755);
    $fence = "```\n## Pull-request body overflow\n```";
    file_put_contents($directory . '/comments.json', json_encode([
        overflowDeleteComment(1_101, 'bot', "## Pull-request body overflow\n\nAudit lines\n\n<!-- pr-body-overflow:actor=bot -->"),
        overflowDeleteComment(1_102, 'bot', "Test report\n\n" . $fence . "\n\n<!-- test-report:actor=bot -->"),
        overflowDeleteComment(1_103, 'bot', "Note\n\n" . $fence . "\n\n<!-- pr-body-overflow:actor=bot -->"),
        overflowDeleteComment(1_104, 'bot', "Note\n\n```\n<!-- pr-body-overflow:actor=bot -->\n```\n\n## Pull-request body overflow"),
        overflowDeleteComment(1_105, 'bot', "## Pull-request body overflow\n\nA note\n\n<!-- agent-note:actor=bot -->"),
    ], JSON_THROW_ON_ERROR));

    try {
        $process = runOverflowCommentDelete($directory, ['1101']);

        expect($process->getExitCode())->toBe(4)
            ->and($process->getErrorOutput())->toContain('refusing to delete a pull-request body overflow comment')
            ->and(is_file($directory . '/deleted'))->toBeFalse();
    } finally {
        installerRemoveDirectory($directory);
    }
});

test('an operator comment without a marker is never deleted by the two-argument form, even when it holds the heading line', function (): void {
    $directory = installerCreateProjectRoot();
    installerWriteFile($directory . '/bin/gh', GITHUB_OVERFLOW_DELETE_GH_SCRIPT);
    chmod($directory . '/bin/gh', 0755);
    file_put_contents($directory . '/comments.json', json_encode([
        overflowDeleteComment(1_201, 'bot', "## Pull-request body overflow\n\nThe operator's own note"),
        overflowDeleteComment(1_202, 'bot', "My note\n\n## Pull-request body overflow\n\nMore\n\n<!-- pr-body-overflow:actor=bot -->"),
        overflowDeleteComment(1_203, 'bot', "## Pull-request body overflow\n\nReal copy\n\n<!-- pr-body-overflow:actor=bot -->"),
    ], JSON_THROW_ON_ERROR));

    try {
        foreach (['1201', '1202'] as $id) {
            $process = runOverflowCommentDelete($directory, [$id]);

            expect($process->getExitCode())->toBe(4)
                ->and($process->getErrorOutput())->toContain('deletes only a superseded pull-request body overflow comment')
                ->and(is_file($directory . '/deleted'))->toBeFalse();
        }
    } finally {
        installerRemoveDirectory($directory);
    }
});
