<?php

declare(strict_types = 1);

use Symfony\Component\Process\Process;

// `gh` stands in for the real CLI. `$FAKE_GH_COMMENTS` holds the comments of issue 42 as the REST list returns them: the list call, the
// single-comment read, and the delete all answer from it. A PATCH records its arguments and its payload; a delete creates `$FAKE_GH_STATE`.
// `$FAKE_GH_ITEM_JSON` is the REST issue object that tells an issue from a pull request; `FAKE_GH_ITEM_OK=0` fails that read.
const REVIEW_MERGE_GH_SCRIPT = <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail

printf '%q ' "$@" >> "$FAKE_GH_CALLS"
printf '\n' >> "$FAKE_GH_CALLS"

if [[ "$1" == "api" && "$2" == "user" ]]; then
  printf '%s\n' "${FAKE_GH_LOGIN:-bot}"
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

if [[ "$1" == "api" && "$2" == */issues/42/comments && "$*" == *"--paginate"* ]]; then
  [[ "${FAKE_GH_LIST_OK:-1}" == "1" ]] || exit 1
  cat "$FAKE_GH_COMMENTS"
  exit 0
fi

if [[ "$1" == "api" && "$2" == repos/*/issues/comments/* && "$*" == *"PATCH"* ]]; then
  cat > "$FAKE_GH_PATCH_BODY"
  printf '%s\n' "{\"html_url\":\"https://github.com/pekral/ai-olympus/issues/42#issuecomment-${2##*/}\",\"id\":${2##*/}}"
  exit 0
fi

if [[ "$1" == "api" && "$2" == repos/*/issues/comments/* ]]; then
  jq -c --argjson id "${2##*/}" '.[] | select(.id == $id)' "$FAKE_GH_COMMENTS"
  exit 0
fi

if [[ "$1" == "api" && "$2" =~ ^repos/[^/]+/[^/]+/issues/[0-9]+$ ]]; then
  [[ "${FAKE_GH_ITEM_OK:-1}" == "1" ]] || exit 1
  issue='{"number":42,"title":"Upload validation"}'
  printf '%s\n' "${FAKE_GH_ITEM_JSON:-$issue}"
  exit 0
fi

exit 90
BASH;

const REVIEW_MERGE_ITEM = 'https://github.com/pekral/ai-olympus/issues/42';

/**
 * @return array{id: int, issue_url: string, user: array{login: string}, body: string}
 */
function reviewMergeComment(int $id, string $author, string $body, string $issue = '42'): array
{
    return [
        'body' => $body,
        'id' => $id,
        'issue_url' => 'https://api.github.com/repos/pekral/ai-olympus/issues/' . $issue,
        'user' => ['login' => $author],
    ];
}

function reviewMergeMarker(string $namespace = 'cr-comment', string $actor = 'bot'): string
{
    return '<!-- ' . $namespace . ':actor=' . $actor . ' -->';
}

function reviewMergeFingerprint(string $line): string
{
    return substr(hash('sha256', trim((string) preg_replace('/\s+/u', ' ', $line))), 0, 8);
}

// A review comment as the GitHub upsert helper publishes it: the agent line, an optional line the operator added later,
// the hidden fingerprint of the agent line, and the marker.
function reviewMergeFingerprinted(string $agentLine, string $operatorLine = ''): string
{
    return $agentLine . "\n\n" . ($operatorLine === '' ? '' : $operatorLine . "\n\n")
        . '<!-- cr-comment:lines=' . reviewMergeFingerprint($agentLine) . " -->\n" . reviewMergeMarker();
}

/**
 * @param array<mixed> $values
 * @return list<string>
 */
function reviewMergeStrings(array $values): array
{
    return array_values(array_filter($values, is_string(...)));
}

/**
 * @param array<mixed> $values
 * @return array<string, string>
 */
function reviewMergeEnvironment(array $values): array
{
    $environment = [];

    foreach ($values as $name => $value) {
        if (is_string($name) && is_string($value)) {
            $environment[$name] = $value;
        }
    }

    return $environment;
}

/**
 * @param array<mixed> $comments
 * @param array<mixed> $arguments
 * @param array<mixed> $environment
 * @return array{process: \Symfony\Component\Process\Process, deleted: bool, calls: string, patch: string}
 */
function runReviewMergeGitHub(string $script, array $comments, array $arguments, array $environment = [], string $stdin = ''): array
{
    $packageDir = dirname(__DIR__, 3);
    $directory = installerCreateProjectRoot();
    installerWriteFile($directory . '/bin/gh', REVIEW_MERGE_GH_SCRIPT);
    chmod($directory . '/bin/gh', 0755);
    file_put_contents($directory . '/comments.json', json_encode($comments, JSON_THROW_ON_ERROR));
    file_put_contents($directory . '/calls', '');
    $systemPath = getenv('PATH');

    try {
        $process = new Process([$packageDir . '/' . $script, ...reviewMergeStrings($arguments)], $packageDir, reviewMergeEnvironment($environment) + [
            'FAKE_GH_CALLS' => $directory . '/calls',
            'FAKE_GH_COMMENTS' => $directory . '/comments.json',
            'FAKE_GH_PATCH_BODY' => $directory . '/patch',
            'FAKE_GH_STATE' => $directory . '/deleted',
            'PATH' => $directory . '/bin' . PATH_SEPARATOR . ($systemPath === false ? '/usr/bin:/bin' : $systemPath),
        ]);
        $process->setInput($stdin);
        $process->run();

        return [
            'calls' => (string) file_get_contents($directory . '/calls'),
            'deleted' => is_file($directory . '/deleted'),
            'patch' => is_file($directory . '/patch') ? (string) file_get_contents($directory . '/patch') : '',
            'process' => $process,
        ];
    } finally {
        installerRemoveDirectory($directory);
    }
}

test('the upsert helper updates exactly the comment that --comment-id names, not the newest one', function (): void {
    $result = runReviewMergeGitHub('skills/code-review-github/scripts/upsert-comment.sh', [
        reviewMergeComment(801, 'bot', "Round 1.\n\n" . reviewMergeMarker()),
        reviewMergeComment(803, 'bot', "Round 3.\n\n" . reviewMergeMarker()),
    ], ['--comment-id', '801', REVIEW_MERGE_ITEM, '-'], stdin: "Merged text.\n");

    expect($result['process']->getExitCode())->toBe(0)
        ->and($result['process']->getErrorOutput())->toContain('action=updated id=801')
        ->and($result['calls'])->toContain('repos/pekral/ai-olympus/issues/comments/801')
        ->and($result['calls'])->not->toContain('issues/comments/803')
        ->and($result['patch'])->toContain('Merged text.')
        ->and($result['patch'])->toContain('cr-comment:actor=bot');
});

dataset('merge writer refusals', [
    'a comment of another account that copies the marker' => [[reviewMergeComment(802, 'stranger', "Fake.\n\n" . reviewMergeMarker())], '802'],
    'a comment of another issue' => [[reviewMergeComment(806, 'bot', "Round.\n\n" . reviewMergeMarker(), '43')], '806'],
    'a comment of another namespace' => [[reviewMergeComment(805, 'bot', "Ready.\n\n" . reviewMergeMarker('merge-readiness'))], '805'],
    'a comment of the actor without the marker' => [[reviewMergeComment(804, 'bot', 'A plain note.')], '804'],
    'a comment that does not exist' => [[], '807'],
]);

test('the upsert helper refuses a comment id that is not the actor\'s own review comment of this item', function (array $comments, string $id): void {
    $result = runReviewMergeGitHub(
        'skills/code-review-github/scripts/upsert-comment.sh',
        $comments,
        ['--comment-id', $id, REVIEW_MERGE_ITEM, '-'],
        stdin: "Merged text.\n",
    );

    expect($result['process']->getExitCode())->toBe(3)
        ->and($result['patch'])->toBe('');
})->with('merge writer refusals');

test('the upsert helper refuses a malformed --comment-id and a combination with --create or agent-note', function (array $arguments): void {
    $result = runReviewMergeGitHub('skills/code-review-github/scripts/upsert-comment.sh', [], $arguments, stdin: "Text.\n");

    expect($result['process']->getExitCode())->toBe(1)
        ->and($result['patch'])->toBe('');
})->with([
    'an id of zero' => [['--comment-id', '0', REVIEW_MERGE_ITEM, '-']],
    'a non-numeric id' => [['--comment-id', 'abc', REVIEW_MERGE_ITEM, '-']],
    'no id' => [['--comment-id']],
    'the agent-note namespace' => [['--comment-id', '801', REVIEW_MERGE_ITEM, '-', 'agent-note']],
    'with --create' => [['--create', '--comment-id', '801', REVIEW_MERGE_ITEM, '-']],
]);

test('the GitHub delete helper removes a review comment that no later comment points at', function (): void {
    $result = runReviewMergeGitHub('skills/_shared/delete-owned-github-comment.sh', [
        reviewMergeComment(801, 'bot', "Round 1: the limit is 2 MB.\n\n" . reviewMergeMarker()),
        reviewMergeComment(802, 'reporter', 'Thanks, I will check it tomorrow.'),
        reviewMergeComment(803, 'bot', "Round 2: merged.\n\n" . reviewMergeMarker()),
        reviewMergeComment(804, 'bot', "Carried over: Round 1: the limit is 2 MB.\n\n" . reviewMergeMarker('merge-readiness')),
    ], ['--review-merge', REVIEW_MERGE_ITEM, '801', '803']);

    expect($result['process']->getExitCode())->toBe(0)
        ->and($result['process']->getOutput())->toContain('deleted id=801')
        ->and($result['deleted'])->toBeTrue();
});

dataset('merge delete refusals', [
    'a later comment links the review comment' => [
        [
            reviewMergeComment(801, 'bot', "Round 1: the limit is 2 MB.\n\n" . reviewMergeMarker()),
            reviewMergeComment(802, 'reporter', 'See https://github.com/pekral/ai-olympus/issues/42#issuecomment-801 for the limit.'),
            reviewMergeComment(803, 'bot', "Round 2: merged.\n\n" . reviewMergeMarker()),
        ],
        '801', '803', 4,
    ],
    'a later comment quotes a line of the review comment' => [
        [
            reviewMergeComment(801, 'bot', "Round 1: the limit is 2 MB.\n\n" . reviewMergeMarker()),
            reviewMergeComment(802, 'reporter', "> Round 1: the limit is 2 MB.\n\nWhy 2 MB?"),
            reviewMergeComment(803, 'bot', "Round 2: merged.\n\n" . reviewMergeMarker()),
        ],
        '801', '803', 4,
    ],
    'the merged comment carries no review marker' => [
        [
            reviewMergeComment(801, 'bot', "Round 1.\n\n" . reviewMergeMarker()),
            reviewMergeComment(803, 'bot', 'Merged, but without the marker.'),
        ],
        '801', '803', 4,
    ],
    'the merged comment is of another account' => [
        [
            reviewMergeComment(801, 'bot', "Round 1.\n\n" . reviewMergeMarker()),
            reviewMergeComment(803, 'stranger', "Round 2.\n\n" . reviewMergeMarker()),
        ],
        '801', '803', 4,
    ],
    'the merged comment quotes the marker only inside a code block' => [
        [
            reviewMergeComment(801, 'bot', "Round 1.\n\n" . reviewMergeMarker()),
            reviewMergeComment(803, 'bot', "Round 2: merged.\n\n```\n" . reviewMergeMarker() . "\n```"),
        ],
        '801', '803', 4,
    ],
    'the merged comment sits on another issue of the repository' => [
        [
            reviewMergeComment(801, 'bot', "Round 1.\n\n" . reviewMergeMarker()),
            reviewMergeComment(803, 'bot', "Round 2: merged.\n\n" . reviewMergeMarker(), '43'),
        ],
        '801', '803', 4,
    ],
    'the operator answers under the same account without a marker' => [
        [
            reviewMergeComment(801, 'bot', "Round 1: the limit is 2 MB.\n\n" . reviewMergeMarker()),
            reviewMergeComment(802, 'bot', 'Round 1: the limit is 2 MB. Agreed, raising it.'),
            reviewMergeComment(803, 'bot', "Round 2: merged.\n\n" . reviewMergeMarker()),
        ],
        '801', '803', 4,
    ],
    'the target carries another agent marker as well' => [
        [
            reviewMergeComment(801, 'bot', "Round 1.\n\n" . reviewMergeMarker() . "\n" . reviewMergeMarker('merge-readiness')),
            reviewMergeComment(803, 'bot', "Round 2: merged.\n\n" . reviewMergeMarker()),
        ],
        '801', '803', 4,
    ],
    'the target has no review marker' => [
        [
            reviewMergeComment(801, 'bot', 'A note of the operator.'),
            reviewMergeComment(803, 'bot', "Round 2: merged.\n\n" . reviewMergeMarker()),
        ],
        '801', '803', 4,
    ],
    'the target holds a line the operator added that the merged comment lacks' => [
        [
            reviewMergeComment(801, 'bot', reviewMergeFingerprinted('Round 1: the limit is 2 MB.', 'Decision: keep the 2 MB limit.')),
            reviewMergeComment(803, 'bot', "Round 2: merged.\n\n" . reviewMergeMarker()),
        ],
        '801', '803', 4,
    ],
    'the target holds an operator line that the merged comment only paraphrases' => [
        [
            reviewMergeComment(801, 'bot', reviewMergeFingerprinted('Round 1: the limit is 2 MB.', 'Decision: keep the 2 MB limit.')),
            reviewMergeComment(803, 'bot', "Round 2: merged. The operator decided to keep the limit.\n\n" . reviewMergeMarker()),
        ],
        '801', '803', 4,
    ],
    'the target is a comment of another account' => [
        [
            reviewMergeComment(801, 'stranger', "Round 1.\n\n" . reviewMergeMarker()),
            reviewMergeComment(803, 'bot', "Round 2: merged.\n\n" . reviewMergeMarker()),
        ],
        '801', '803', 4,
    ],
    'the target is a merge-readiness comment' => [
        [
            reviewMergeComment(801, 'bot', "Ready.\n\n" . reviewMergeMarker('merge-readiness')),
            reviewMergeComment(803, 'bot', "Round 2: merged.\n\n" . reviewMergeMarker()),
        ],
        '801', '803', 4,
    ],
    'the target is the merged comment itself' => [
        [reviewMergeComment(803, 'bot', "Round 2: merged.\n\n" . reviewMergeMarker())],
        '803', '803', 4,
    ],
]);

test(
    'the GitHub delete helper refuses to remove anything that is not a review comment without a reply',
    function (array $comments, string $target, string $merged, int $exit): void {
        $result = runReviewMergeGitHub(
            'skills/_shared/delete-owned-github-comment.sh',
            $comments,
            ['--review-merge', REVIEW_MERGE_ITEM, $target, $merged],
        );
    
        expect($result['process']->getExitCode())->toBe($exit)
            ->and($result['deleted'])->toBeFalse();
    },
)->with('merge delete refusals');

test('the GitHub delete helper deletes nothing when the comments cannot be listed', function (): void {
    $result = runReviewMergeGitHub('skills/_shared/delete-owned-github-comment.sh', [
        reviewMergeComment(801, 'bot', "Round 1.\n\n" . reviewMergeMarker()),
        reviewMergeComment(803, 'bot', "Round 2: merged.\n\n" . reviewMergeMarker()),
    ], ['--review-merge', REVIEW_MERGE_ITEM, '801', '803'], ['FAKE_GH_LIST_OK' => '0']);

    expect($result['process']->getExitCode())->toBe(3)
        ->and($result['process']->getErrorOutput())->toContain('nothing is deleted')
        ->and($result['deleted'])->toBeFalse();
});

dataset('merge delete pull requests', [
    'a pull request URL' => ['https://github.com/pekral/ai-olympus/pull/42', [], 1],
    'a pull request named through an issue URL' => [REVIEW_MERGE_ITEM, ['FAKE_GH_ITEM_JSON' => '{"number":42,"pull_request":{"url":"x"}}'], 4],
    'a pull request named with a trailing slash' => [REVIEW_MERGE_ITEM . '/', ['FAKE_GH_ITEM_JSON' => '{"number":42,"pull_request":{}}'], 4],
    'an answer with a null pull_request field' => [REVIEW_MERGE_ITEM, ['FAKE_GH_ITEM_JSON' => '{"number":42,"pull_request":null}'], 4],
    'an answer of another number' => [REVIEW_MERGE_ITEM, ['FAKE_GH_ITEM_JSON' => '{"number":43}'], 3],
    'an answer that is no issue' => [REVIEW_MERGE_ITEM, ['FAKE_GH_ITEM_JSON' => '{"message":"Not Found"}'], 3],
    'an answer that is not JSON' => [REVIEW_MERGE_ITEM, ['FAKE_GH_ITEM_JSON' => 'not json'], 3],
    'a failed read' => [REVIEW_MERGE_ITEM, ['FAKE_GH_ITEM_OK' => '0'], 3],
]);

test(
    'the GitHub delete helper deletes nothing unless GitHub reports the target as an issue',
    function (string $target, array $environment, int $exit): void {
        $result = runReviewMergeGitHub('skills/_shared/delete-owned-github-comment.sh', [
            reviewMergeComment(801, 'bot', "Round 1: the limit is 2 MB.\n\n" . reviewMergeMarker()),
            reviewMergeComment(803, 'bot', "Round 2: merged.\n\n" . reviewMergeMarker()),
        ], ['--review-merge', $target, '801', '803'], $environment);

        expect($result['process']->getExitCode())->toBe($exit)
            ->and($result['process']->getErrorOutput())->toContain('nothing is deleted')
            ->and($result['calls'])->not->toContain('DELETE')
            ->and($result['deleted'])->toBeFalse();
    },
)->with('merge delete pull requests');

test('the --review-merge form needs exactly three arguments', function (): void {
    $result = runReviewMergeGitHub('skills/_shared/delete-owned-github-comment.sh', [], ['--review-merge', REVIEW_MERGE_ITEM, '801']);

    expect($result['process']->getExitCode())->toBe(1)
        ->and($result['deleted'])->toBeFalse();
});

// `acli` stands in for the real CLI. A successful `comment delete` creates `$FAKE_ACLI_STATE`; `view` serves the post-delete state after it.
const REVIEW_MERGE_ACLI_SCRIPT = <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail

printf '%q ' "$@" >> "$FAKE_ACLI_CALLS"
printf '\n' >> "$FAKE_ACLI_CALLS"

if [[ "$1" == "jira" && "$2" == "auth" && "$3" == "status" ]]; then
  printf '%s\n' 'Site: example.atlassian.net' 'Email: agent@example.com'
  exit 0
fi

if [[ "$1" == "jira" && "$2" == "workitem" && "$3" == "search" ]]; then
  if [[ "${FAKE_ACLI_ACCOUNT_OK:-1}" == "1" ]]; then
    printf '%s\n' '{"issues":[{"fields":{"assignee":{"accountId":"acc-agent"}}}]}'
  else
    printf '%s\n' '{"issues":[]}'
  fi
  exit 0
fi

if [[ "$1" == "jira" && "$2" == "workitem" && "$3" == "view" ]]; then
  if [[ -f "$FAKE_ACLI_STATE" ]]; then
    printf '%s\n' "$FAKE_ACLI_VIEW_AFTER_JSON"
  else
    printf '%s\n' "$FAKE_ACLI_VIEW_JSON"
  fi
  exit 0
fi

if [[ "$1" == "jira" && "$2" == "workitem" && "$3" == "comment" && "$4" == "delete" ]]; then
  : > "$FAKE_ACLI_STATE"
  exit 0
fi

if [[ "$1" == "jira" && "$2" == "workitem" && "$3" == "comment" && "$4" == "update" ]]; then
  : > "$FAKE_ACLI_UPDATED"
  exit 0
fi

exit 1
BASH;

function reviewMergeJiraMarker(): string
{
    return '_cr-comment:actor=' . substr(hash('sha256', 'agent@example.com'), 0, 16) . '_';
}

/**
 * @param list<array<string, mixed>> $extraNodes
 * @return array<string, mixed>
 */
function reviewMergeJiraComment(string $id, string $accountId, string $text, array $extraNodes = []): array
{
    $paragraphs = [];

    $parts = preg_split('/(?=_[a-z-]+:actor=)/', $text);

    foreach ($parts === false ? [$text] : $parts as $index => $part) {
        $paragraphs[] = ['content' => [...($index === 0 ? $extraNodes : []), ['text' => trim($part), 'type' => 'text']], 'type' => 'paragraph'];
    }

    return [
        'author' => ['accountId' => $accountId, 'displayName' => 'Agent'],
        'body' => ['content' => $paragraphs, 'type' => 'doc', 'version' => 1],
        'id' => $id,
    ];
}

/**
 * @param list<string> $paragraphs
 * @return array<string, mixed>
 */
function reviewMergeJiraDocument(string $id, string $accountId, array $paragraphs): array
{
    return [
        'author' => ['accountId' => $accountId, 'displayName' => 'Agent'],
        'body' => [
            'content' => array_map(
                static fn (string $text): array => ['content' => [['text' => $text, 'type' => 'text']], 'type' => 'paragraph'],
                $paragraphs,
            ),
            'type' => 'doc',
            'version' => 1,
        ],
        'id' => $id,
    ];
}

/**
 * @param array<mixed> $comments
 */
function reviewMergeJiraView(array $comments, int $hidden = 0): string
{
    return (string) json_encode(['fields' => ['comment' => ['comments' => $comments, 'total' => count($comments) + $hidden]], 'key' => 'ACME-1234']);
}

/**
 * @param array<mixed> $before
 * @param array<mixed> $after
 * @param array<mixed> $arguments
 * @param array<mixed> $environment
 * @return array{process: \Symfony\Component\Process\Process, deleted: bool, updated: bool}
 */
function runReviewMergeJira(
    string $script,
    array $before,
    array $after,
    array $arguments,
    string $stdin = '',
    int $hidden = 0,
    array $environment = [],
): array {
    $packageDir = dirname(__DIR__, 3);
    $directory = installerCreateProjectRoot();
    installerWriteFile($directory . '/bin/acli', REVIEW_MERGE_ACLI_SCRIPT);
    chmod($directory . '/bin/acli', 0755);
    file_put_contents($directory . '/calls', '');
    $systemPath = getenv('PATH');

    try {
        $process = new Process([$packageDir . '/' . $script, ...reviewMergeStrings($arguments)], $packageDir, reviewMergeEnvironment($environment) + [
            'FAKE_ACLI_CALLS' => $directory . '/calls',
            'FAKE_ACLI_STATE' => $directory . '/deleted',
            'FAKE_ACLI_UPDATED' => $directory . '/updated',
            'FAKE_ACLI_VIEW_AFTER_JSON' => reviewMergeJiraView($after, $hidden),
            'FAKE_ACLI_VIEW_JSON' => reviewMergeJiraView($before, $hidden),
            'PATH' => $directory . '/bin' . PATH_SEPARATOR . ($systemPath === false ? '/usr/bin:/bin' : $systemPath),
        ]);
        $process->setInput($stdin);
        $process->run();

        return ['deleted' => is_file($directory . '/deleted'), 'process' => $process, 'updated' => is_file($directory . '/updated')];
    } finally {
        installerRemoveDirectory($directory);
    }
}

test('the JIRA delete helper removes a review comment that no later comment points at', function (): void {
    $marker = reviewMergeJiraMarker();
    $before = [
        reviewMergeJiraComment('10001', 'acc-agent', 'Round 1: the limit is 2 MB. ' . $marker),
        reviewMergeJiraComment('10002', 'acc-other', 'Thanks, I will check it tomorrow.'),
        reviewMergeJiraComment('10003', 'acc-agent', 'Round 2: merged. ' . $marker),
    ];
    $result = runReviewMergeJira(
        'skills/code-review-jira/scripts/delete-owned-comment.sh',
        $before,
        [$before[1], $before[2]],
        ['--review-merge', 'ACME-1234', '10001', '10003'],
    );

    expect($result['process']->getExitCode())->toBe(0)
        ->and($result['process']->getOutput())->toContain('deleted id=10001')
        ->and($result['deleted'])->toBeTrue();
});

test(
    'the JIRA delete helper refuses a review comment that another comment mentions, quotes, or that carries another marker',
    function (string $kind): void {
        $marker = reviewMergeJiraMarker();
        $reply = match ($kind) {
            'a mention of the account' => reviewMergeJiraComment(
                '10002',
                'acc-other',
                ' please look again.',
                [['attrs' => ['id' => 'acc-agent', 'text' => '@Agent'], 'type' => 'mention']],
            ),
            'a quoted line' => reviewMergeJiraComment('10002', 'acc-other', 'Round 1: the limit is 2 MB. Why?'),
            default => reviewMergeJiraComment('10002', 'acc-other', 'Fine.'),
        };
        $target = $kind === 'another marker'
            ? reviewMergeJiraComment('10001', 'acc-agent', 'Round 1: the limit is 2 MB. ' . $marker . ' _merge-readiness:actor=abcdef0123456789_')
            : reviewMergeJiraComment('10001', 'acc-agent', 'Round 1: the limit is 2 MB. ' . $marker);
        $before = [$target, $reply, reviewMergeJiraComment('10003', 'acc-agent', 'Round 2: merged. ' . $marker)];
        $result = runReviewMergeJira(
            'skills/code-review-jira/scripts/delete-owned-comment.sh',
            $before,
            $before,
            ['--review-merge', 'ACME-1234', '10001', '10003'],
        );
    
        expect($result['process']->getExitCode())->toBe(4)
            ->and($result['deleted'])->toBeFalse();
    },
)->with(['a mention of the account', 'a quoted line', 'another marker']);

test('the JIRA delete helper refuses a comment of another account and the merged comment itself', function (array $arguments): void {
    $marker = reviewMergeJiraMarker();
    $before = [
        reviewMergeJiraComment('10001', 'acc-other', 'Copied. ' . $marker),
        reviewMergeJiraComment('10003', 'acc-agent', 'Round 2: merged. ' . $marker),
    ];
    $result = runReviewMergeJira('skills/code-review-jira/scripts/delete-owned-comment.sh', $before, $before, $arguments);

    expect($result['process']->getExitCode())->toBe(4)
        ->and($result['deleted'])->toBeFalse();
})->with([
    'a copied marker of another account' => [['--review-merge', 'ACME-1234', '10001', '10003']],
    'the merged comment' => [['--review-merge', 'ACME-1234', '10003', '10003']],
]);

test(
    'the JIRA upsert helper updates exactly the comment that --comment-id names and refuses a comment it cannot prove',
    function (string $id, int $exit, bool $updated): void {
        $marker = reviewMergeJiraMarker();
        $comments = [
            reviewMergeJiraComment('10001', 'acc-agent', 'Round 1. ' . $marker),
            reviewMergeJiraComment('10002', 'acc-other', 'Copied. ' . $marker),
            reviewMergeJiraComment('10003', 'acc-agent', 'Round 3. ' . $marker),
            reviewMergeJiraComment('10004', 'acc-agent', 'A note.'),
        ];
        $result = runReviewMergeJira(
            'skills/code-review-jira/scripts/upsert-comment.sh',
            $comments,
            $comments,
            ['--comment-id', $id, 'ACME-1234', '-'],
            "h2. Current state\n\nMerged.\n",
        );
    
        expect($result['process']->getExitCode())->toBe($exit)
            ->and($result['updated'])->toBe($updated);
    
        if ($exit === 0) {
            expect($result['process']->getErrorOutput())->toContain('action=updated id=' . $id);
        }
    },
)->with([
    'a comment of another account with the marker' => ['10002', 3, false],
    'a comment of the actor without the marker' => ['10004', 3, false],
    'a comment that is not on the issue' => ['10099', 3, false],
    'the older review comment of the actor' => ['10001', 0, true],
]);

dataset('merge delete mention replies', [
    'a hexadecimal reference that reads further letters as digits' => ['<div>&#x40bot finding 2 is wrong.</div>', 0, true],
    'a mention after a closing double underscore' => ['__Note__@bot finding 2 is wrong.', 4, false],
    'a mention after a closing underscore' => ['_Note_@bot finding 2 is wrong.', 4, false],
    'a mention after a fence closed by its own character only' => ["~~~\n```\n~~~\n@bot finding 2 is wrong.", 4, false],
    'a mention after a fence of a list item' => ["- Finding 2:\n\n  ```\n@bot this is wrong.", 4, false],
    'a mention after a fence of a quote' => ["> ```\n    ```\n@bot finding 2 is wrong.", 4, false],
    'a mention after an escaped backtick' => ['Type \\`@bot, finding 2 is wrong `.', 4, false],
    'a mention after an unmatched backtick' => ['Type `@bot to look again.', 4, false],
    'a mention after a shorter fence line inside a block' => ["````\n```\n````\n@bot finding 2 is wrong.", 4, false],
    'a mention after a slash' => ['Ask a/@bot again.', 4, false],
    'a mention at the end of a later line' => ["Finding 2 is wrong.\nPlease check, @bot", 4, false],
    'a mention between code spans of unequal length' => ['``a`b`` @bot `c`', 4, false],
    'a mention between fence lines indented by four spaces' => ["    ```\n@bot finding 2 is wrong.\n    ```", 4, false],
    'a mention in another letter case' => ['@BOT finding 2 is wrong.', 4, false],
    'a mention in brackets after a comma' => ['Look again,(@bot) please.', 4, false],
    'a mention inside a fenced code block' => ["```\n@bot\n```", 4, false],
    'a mention inside a fence that never closes' => ["```\n@bot finding 2 is wrong.", 4, false],
    'a mention inside an inline code span' => ['Type `@bot` to call it.', 4, false],
    'a mention of a login that holds a named underscore reference' => ['@acme&lowbar;bot finding 2 is wrong.', 4, false, 'acme_bot'],
    'a mention of a login that holds an escaped underscore' => ['@acme\\_bot finding 2 is wrong.', 4, false, 'acme_bot'],
    'a mention of a login that holds an underscore' => ['@acme_bot finding 2 is wrong.', 4, false, 'acme_bot'],
    'a mention of a login that holds an underscore in another letter case' => ['@ACME_BOT finding 2 is wrong.', 4, false, 'acme_bot'],
    'a mention of a login that holds an underscore reference' => ['@acme&#95bot finding 2 is wrong.', 4, false, 'acme_bot'],
    'a mention of a longer login' => ['@bot-helper finding 2 is wrong.', 4, false],
    'a mention of the operator account' => ['@bot finding 2 is wrong.', 4, false],
    'a mention whose at sign is a decimal reference' => ['&#64;bot finding 2 is wrong.', 4, false],
    'a mention whose at sign is a hexadecimal reference' => ['&#x40;bot finding 2 is wrong.', 4, false],
    'a mention whose at sign is a named reference' => ['&commat;bot finding 2 is wrong.', 4, false],
    'a mention whose at sign is a named reference without a semicolon' => ['&commatbot finding 2 is wrong.', 4, false],
    'a mention whose at sign is an upper-case hexadecimal reference' => ['&#X0040;bot finding 2 is wrong.', 4, false],
    'a mention whose at sign is a zero-padded decimal reference' => ['&#0000064;bot finding 2 is wrong.', 4, false],
    'a mention whose login holds a backslash escape' => ['@ops\\-bot finding 2 is wrong.', 4, false, 'ops-bot'],
    'a mention whose login holds a decimal reference' => ['@&#98;ot finding 2 is wrong.', 4, false],
    'a mention whose login holds a hexadecimal reference' => ['@b&#x6F;t finding 2 is wrong.', 4, false],
    'a mention whose login holds a Kelvin sign' => ["@\u{212A}bot finding 2 is wrong.", 4, false, 'kbot'],
    'a mention whose login holds a Kelvin sign reference' => ['<div>@&#8490bot finding 2 is wrong.</div>', 4, false, 'kbot'],
    'a mention whose login holds a ligature reference' => ['@&fjlig;bot finding 2 is wrong.', 4, false, 'fjbot'],
    'an address that contains the login' => ['Write to ops@bot.example please.', 4, false],
    'an at sign of another login with a less-than reference' => ['&lt;@alice&gt; finding 2 is wrong.', 0, true],
    'an at sign of another login without a tag' => ['@alice finding 2 is wrong.', 0, true],
    'an HTML-block mention after a digit reference without a semicolon' => ['<div>&#33@bot x</div>', 4, false],
    'an HTML-block mention after a less-than sign without a semicolon' => ['<div>&lt@bot x</div>', 4, false],
    'an HTML-block mention after a named reference without a semicolon' => ['<div>&amp@bot finding 2 is wrong.</div>', 4, false],
    'an HTML-block mention after a no-break space without a semicolon' => ['<div>&nbsp@bot x</div>', 4, false],
    'an HTML-block mention whose at sign is a decimal reference' => ['<div>&#64bot finding 2 is wrong.</div>', 4, false],
    'an HTML-block mention whose at sign is a hexadecimal reference' => ['<div>&#x40ops_bot finding 2 is wrong.</div>', 4, false, 'ops_bot'],
    'an HTML-block mention whose login holds a reference without a semicolon' => ['<div>@b&#111t finding 2 is wrong.</div>', 4, false],
    'an HTML paragraph mention whose at sign is a zero-padded reference' => ['<p>&#0064bot finding 2 is wrong.</p>', 4, false],
    'an HTML table mention whose at sign is a reference without a semicolon' => ['<table><tr><td>&#64bot finding 2 is wrong.</td></tr></table>', 4, false],
    'a tag reply that mentions another login' => ['<b>@alice</b> finding 2 is wrong.', 4, false],
    'a tag reply whose at sign is a decimal reference' => ['&#64;b</td>ot finding 2 is wrong.', 4, false],
    'a tag reply whose at sign is a named reference' => ['&commat;</b>bot finding 2 is wrong.', 4, false],
    'a tag reply whose less-than sign is a decimal reference' => ['@b&#60;/b>ot finding 2 is wrong.', 4, false],
    'a tag reply with a dropped end tag in the login' => ['@b</span>ot finding 2 is wrong.', 4, false],
    'a tag reply with a dropped start tag in the login' => ['@b<body>ot finding 2 is wrong.', 4, false],
    'a tag reply with a dropped tag after the at sign' => ['@</b>bot finding 2 is wrong.', 4, false],
    'a tag reply with a quoted greater-than sign in the tag' => ['<div>@b</span title="a>b">ot x</div>', 4, false],
    'a tag without an at sign' => ['<b>Finding 2</b> is wrong.', 0, true],
    'a mention after a leading NUL' => ["\u{0}@bot finding 2 is wrong.", 4, false],
    'a tag reply after a leading NUL' => ["\u{0}<b>@alice</b> finding 2 is wrong.", 4, false],
    'a NUL inside the login of the operator account' => ["@b\u{0}ot finding 2 is wrong.", 0, true],
    'a quote after a leading NUL' => ["\u{0}> Round 1: the limit is 2 MB.", 4, false],
]);

test(
    'the GitHub delete helper counts every mention of the operator account as a reply, inside code too',
    function (string $reply, int $exit, bool $deleted, string $login = 'bot'): void {
        $result = runReviewMergeGitHub('skills/_shared/delete-owned-github-comment.sh', [
            reviewMergeComment(801, $login, "Round 1: the limit is 2 MB.\n\n" . reviewMergeMarker(actor: $login)),
            reviewMergeComment(802, 'reporter', $reply),
            reviewMergeComment(803, $login, "Round 2: merged.\n\n" . reviewMergeMarker(actor: $login)),
        ], ['--review-merge', REVIEW_MERGE_ITEM, '801', '803'], ['FAKE_GH_LOGIN' => $login]);

        expect($result['process']->getExitCode())->toBe($exit)
            ->and($result['deleted'])->toBe($deleted);
    },
)->with('merge delete mention replies');

dataset('merge delete code-held markers', [
    'a fence after a lone carriage return opens a fence' => ["Note\r```\n%s\n```"],
    'a fence line indented by four spaces does not close the fence' => ["```\n    ```\n%s\n```"],
    'a fence line inside an HTML block shifts the fences' => ["<div>\n```\n</div>\n\n```\n%s"],
    'a fence of a list item is followed by a fence the reading cannot place' => ["- Finding 2:\n\n  ```\n```\n%s\n```"],
    'a fence of the other character does not close the fence' => ["```\n~~~\n%s\n```"],
    'a shorter fence does not close the fence' => ["````\n```\n%s\n````"],
]);

test(
    'the GitHub delete helper never takes a marker that a code block can hold as the marker of the merged comment',
    function (string $shape): void {
        $result = runReviewMergeGitHub('skills/_shared/delete-owned-github-comment.sh', [
            reviewMergeComment(801, 'bot', "Round 1.\n\n" . reviewMergeMarker()),
            reviewMergeComment(803, 'bot', "Round 2: merged.\n\n" . sprintf($shape, reviewMergeMarker())),
        ], ['--review-merge', REVIEW_MERGE_ITEM, '801', '803']);

        expect($result['process']->getExitCode())->toBe(4)
            ->and($result['process']->getErrorOutput())->toContain('does not carry this actor\'s cr-comment marker')
            ->and($result['deleted'])->toBeFalse();
    },
)->with('merge delete code-held markers');

test(
    'the GitHub delete helper never takes a marker that a code block can hold as the marker of the target',
    function (string $shape): void {
        $result = runReviewMergeGitHub('skills/_shared/delete-owned-github-comment.sh', [
            reviewMergeComment(801, 'bot', "Round 1.\n\n" . sprintf($shape, reviewMergeMarker())),
            reviewMergeComment(803, 'bot', "Round 2: merged.\n\n" . reviewMergeMarker()),
        ], ['--review-merge', REVIEW_MERGE_ITEM, '801', '803']);

        expect($result['process']->getExitCode())->toBe(4)
            ->and($result['process']->getErrorOutput())->toContain('the comment does not carry this actor\'s cr-comment marker')
            ->and($result['deleted'])->toBeFalse();
    },
)->with('merge delete code-held markers');

test('the GitHub delete helper still takes a marker that follows a closed fenced code block', function (): void {
    $result = runReviewMergeGitHub('skills/_shared/delete-owned-github-comment.sh', [
        reviewMergeComment(801, 'bot', "Round 1: fix it so.\n\n```php\n\$limit = 2;\n```\n\n" . reviewMergeMarker()),
        reviewMergeComment(803, 'bot', "Round 2: merged.\n\n~~~\nkept\n~~~~\n" . reviewMergeMarker()),
    ], ['--review-merge', REVIEW_MERGE_ITEM, '801', '803']);

    expect($result['process']->getExitCode())->toBe(0)
        ->and($result['deleted'])->toBeTrue();
});

test('the GitHub delete helper removes a review comment whose operator line the merged comment carries verbatim', function (): void {
    $result = runReviewMergeGitHub('skills/_shared/delete-owned-github-comment.sh', [
        reviewMergeComment(801, 'bot', reviewMergeFingerprinted('Round 1: the limit is 2 MB.', 'Decision: keep the 2 MB limit.')),
        reviewMergeComment(803, 'bot', "Round 2: merged.\n\n  Decision:  keep the 2 MB limit.\n\n" . reviewMergeMarker()),
    ], ['--review-merge', REVIEW_MERGE_ITEM, '801', '803']);

    expect($result['process']->getExitCode())->toBe(0)
        ->and($result['deleted'])->toBeTrue();
});

test('the JIRA delete helper refuses a review comment when the view embeds fewer comments than the issue carries', function (): void {
    $marker = reviewMergeJiraMarker();
    $before = [
        reviewMergeJiraComment('10001', 'acc-agent', 'Round 1: the limit is 2 MB. ' . $marker),
        reviewMergeJiraComment('10003', 'acc-agent', 'Round 2: merged. ' . $marker),
    ];
    $result = runReviewMergeJira(
        'skills/code-review-jira/scripts/delete-owned-comment.sh',
        $before,
        [$before[1]],
        ['--review-merge', 'ACME-1234', '10001', '10003'],
        hidden: 1,
    );

    expect($result['process']->getExitCode())->toBe(3)
        ->and($result['process']->getErrorOutput())->toContain('embeds fewer comments')
        ->and($result['deleted'])->toBeFalse();
});

/**
 * @return array<string, mixed>
 */
function reviewMergeJiraLinkNode(string $type): array
{
    $url = 'https://example.atlassian.net/browse/ACME-1234?focusedCommentId=10001';

    return match ($type) {
        'link' => ['marks' => [['attrs' => ['href' => $url], 'type' => 'link']], 'text' => 'this finding', 'type' => 'text'],
        default => ['attrs' => ['url' => $url], 'type' => $type],
    };
}

dataset('merge delete JIRA link replies', [
    'a block card of the comment' => ['blockCard'],
    'a linked text that points at the comment' => ['link'],
    'a smart link to the comment' => ['inlineCard'],
]);

test('the JIRA delete helper counts a link to the comment in a document node as a reply', function (string $type): void {
    $marker = reviewMergeJiraMarker();
    $before = [
        reviewMergeJiraComment('10001', 'acc-agent', 'Round 1: the limit is 2 MB. ' . $marker),
        reviewMergeJiraComment('10002', 'acc-other', ' is wrong.', [reviewMergeJiraLinkNode($type)]),
        reviewMergeJiraComment('10003', 'acc-agent', 'Round 2: merged. ' . $marker),
    ];
    $result = runReviewMergeJira(
        'skills/code-review-jira/scripts/delete-owned-comment.sh',
        $before,
        $before,
        ['--review-merge', 'ACME-1234', '10001', '10003'],
    );

    expect($result['process']->getExitCode())->toBe(4)
        ->and($result['deleted'])->toBeFalse();
})->with('merge delete JIRA link replies');

dataset('merge delete JIRA carriers', [
    'a carrier and a target of another account that quotes the marker' => ['acc-stranger', 'acc-stranger', 'Merged. {marker}'],
    'a carrier of the operator account without the marker' => ['acc-agent', 'acc-agent', 'A note of the operator.'],
]);

test(
    'the JIRA delete helper proves the merged comment against the authenticated account',
    function (string $targetAccount, string $carrierAccount, string $carrierText): void {
        $marker = reviewMergeJiraMarker();
        $before = [
            reviewMergeJiraComment('20001', $targetAccount, 'Round 1. ' . $marker),
            reviewMergeJiraComment('20002', $carrierAccount, str_replace('{marker}', $marker, $carrierText)),
        ];
        $result = runReviewMergeJira(
            'skills/code-review-jira/scripts/delete-owned-comment.sh',
            $before,
            $before,
            ['--review-merge', 'ACME-1234', '20001', '20002'],
        );

        expect($result['process']->getExitCode())->toBe(4)
            ->and($result['deleted'])->toBeFalse();
    },
)->with('merge delete JIRA carriers');

test('the JIRA delete helper deletes nothing when the account ID of the acli user cannot be resolved', function (): void {
    $marker = reviewMergeJiraMarker();
    $before = [
        reviewMergeJiraComment('10001', 'acc-agent', 'Round 1. ' . $marker),
        reviewMergeJiraComment('10003', 'acc-agent', 'Round 2: merged. ' . $marker),
    ];
    $result = runReviewMergeJira(
        'skills/code-review-jira/scripts/delete-owned-comment.sh',
        $before,
        [$before[1]],
        ['--review-merge', 'ACME-1234', '10001', '10003'],
        environment: ['FAKE_ACLI_ACCOUNT_OK' => '0'],
    );

    expect($result['process']->getExitCode())->toBe(3)
        ->and($result['deleted'])->toBeFalse();
});

test('the JIRA delete helper keeps a review comment until the merged comment carries its operator line', function (string $merged, int $exit): void {
    $marker = reviewMergeJiraMarker();
    $before = [
        reviewMergeJiraDocument('10001', 'acc-agent', [
            'Round 1: the limit is 2 MB.',
            'Decision: keep the 2 MB limit.',
            $marker . ' lines=' . reviewMergeFingerprint('Round 1: the limit is 2 MB.'),
        ]),
        reviewMergeJiraDocument('10003', 'acc-agent', ['Round 2: merged.', ...($merged === '' ? [] : [$merged]), $marker]),
    ];
    $result = runReviewMergeJira(
        'skills/code-review-jira/scripts/delete-owned-comment.sh',
        $before,
        [$before[1]],
        ['--review-merge', 'ACME-1234', '10001', '10003'],
    );

    expect($result['process']->getExitCode())->toBe($exit)
        ->and($result['deleted'])->toBe($exit === 0);
})->with([
    'the merged comment carries the line' => ['Decision: keep the 2 MB limit.', 0],
    'the merged comment lacks the line' => ['', 4],
    'the merged comment rewords the line' => ['The operator kept the limit.', 4],
]);
