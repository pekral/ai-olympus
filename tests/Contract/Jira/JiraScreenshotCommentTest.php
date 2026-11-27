<?php

declare(strict_types = 1);

use Symfony\Component\Process\Process;

const JIRA_SUMMARY_ACLI_FIXTURE = <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >> "$FIXTURE/calls"
case "$1 $2 $3" in
  'jira auth status') printf 'Site: example.atlassian.net\nEmail: qa@example.test\n'; exit ;;
  'jira workitem search') if [[ "${NO_ACTOR:-0}" == 1 ]]; then echo '[]'; exit; fi; echo '{"issues":[{"fields":{"assignee":{"accountId":"qa"}}}]}'; exit ;;
  'jira workitem view')
    if [[ -f "$FIXTURE/sent" && "${READBACK:-ok}" == ok ]]; then
      jq -n --slurpfile sent "$FIXTURE/sent" '{fields:{comment:{startAt:0,total:1,comments:[{id:"17",author:{accountId:"qa"},body:$sent[0]}]}}}'
    else
      cat "$FIXTURE/before"
    fi
    exit ;;
esac
case "$1 $2 $3 $4" in
  'jira workitem comment create') cp "$8" "$FIXTURE/sent"; echo '{"id":"17"}'; exit "${CREATE_RC:-0}" ;;
  'jira workitem comment update') cp "${10}" "$FIXTURE/sent"; exit ;;
  *) exit 90 ;;
esac
SH;

/**
 * @param list<array<string,mixed>> $comments
 * @return array<string,mixed>
 */
function jiraSummaryPage(array $comments, ?int $total = null): array
{
    return ['fields' => ['comment' => ['startAt' => 0, 'comments' => $comments, 'total' => $total ?? count($comments)]]];
}

/**
 * @return array<string,mixed>
 */
function jiraLegacyPreviewAdf(string $text, bool $quoted = false): array
{
    $marker = ['type' => 'text', 'text' => $text, 'marks' => [['type' => 'em']]];

    if ($quoted) {
        $marker = ['type' => 'text', 'text' => 'See details', 'marks' => [['type' => 'link', 'attrs' => ['href' => $text]]]];
    }

    $paragraph = ['content' => [$marker], 'type' => 'paragraph'];
    $expand = ['attrs' => ['title' => 'Technical marker for AI agents'], 'content' => [$paragraph], 'type' => 'expand'];

    return ['content' => [$expand], 'type' => 'doc', 'version' => 1];
}

/**
 * @return array{string, \Symfony\Component\Process\Process}
 */
function jiraPreviewCommands(): array
{
    $dir = sys_get_temp_dir() . '/jira-summary-' . bin2hex(random_bytes(8));
    mkdir($dir, 0o700);
    file_put_contents($dir . '/acli', JIRA_SUMMARY_ACLI_FIXTURE);
    file_put_contents($dir . '/jq', <<<'SH'
#!/usr/bin/env bash
for arg in "$@"; do
    if [[ "$arg" == *qa@example.test* ]]; then
        touch "$FIXTURE/leaked"
        exit 97
    fi
done
exec "$REAL_JQ" "$@"
SH);
    chmod($dir . '/jq', 0o700);
    $jq = new Process(['sh', '-c', 'command -v jq']);
    $jq->mustRun();
    chmod($dir . '/acli', 0o700);

    return [$dir, $jq];
}

/**
 * @param array<string,mixed> $page
 * @param array<string,mixed>|null $prior
 * @param array<string,string> $environment
 * @return array{int|null,string,string,string}
 */
function jiraPreviewPublish(array $page, ?array $prior = null, array $environment = [], bool $malformed = false, bool $edit = false): array
{
    [$dir, $jq] = jiraPreviewCommands();
    file_put_contents($dir . '/before', json_encode($page, JSON_THROW_ON_ERROR));
    file_put_contents($dir . '/audit', '');
    $arguments = ['--create', '--screenshots', $dir . '/unused', '--slug', 'preview'];

    if ($prior !== null) {
        file_put_contents($dir . '/prior', json_encode($prior, JSON_THROW_ON_ERROR));
        $arguments = ['--comment-id', '17', '--previous-adf', $dir . '/prior'];
    }

    $arguments = $edit ? ['--operator-edit', ...$arguments] : $arguments;
    $entry = dirname(__DIR__, 3) . '/skills/code-review-jira/scripts/upsert-comment.sh';
    $body = ($malformed ? 'Broken byline' : "\nAI Olympus · Modelový výsledek.") . "\n{expand:✅ Findings from the code review}\n"
        . "* Save !example.png|thumbnail!\n{screenshot:f-0123456789abcdef:head}\n{expand}";
    $process = new Process([$entry, '--summary', ...$arguments, 'TEST-1', '-'], $dir, [
        ...$environment,
        'AUDIT_TRAIL_FILE' => $dir . '/audit',
        'AUDIT_TRAIL_ROLE' => 'april',
        'FIXTURE' => $dir,
        'PATH' => $dir . PATH_SEPARATOR . (string) getenv('PATH'),
        'REAL_JQ' => trim($jq->getOutput()),
    ], $body);

    try {
        $process->run();
        expect(is_file($dir . '/leaked'))->toBeFalse();

        return [$process->getExitCode(), $process->getErrorOutput(), (string) file_get_contents($dir . '/calls'),
            is_file($dir . '/sent') ? (string) file_get_contents($dir . '/sent') : '',
        ];
    } finally {
        (new Process(['rm', '-rf', $dir]))->mustRun();
    }
}

test('the screenshot fallback creates only the attributed structured summary and no attachment', function (): void {
    [$rc, $error, $calls, $sent] = jiraPreviewPublish(jiraSummaryPage([]));

    expect($rc)->toBe(0)->and($error)->toContain('action=created id=17', 'text only')
        ->and($calls)->not->toContain('comment update', 'comment delete', 'attachment')
        ->and($sent)->toContain('"expand"');
    expect($sent)->not->toContain(':actor=', '{screenshot:', '"media"');
});

test('an incomplete or unknown snapshot and an invalid explicit summary permit no write', function (string $case): void {
    $page = match ($case) {
        'incomplete' => jiraSummaryPage([], 1),
        'unknown' => ['results' => []],
        default => jiraSummaryPage([]),
    };
    [$rc, , $calls] = jiraPreviewPublish($page, malformed: $case === 'invalid byline');

    expect($rc)->not->toBe(0)->and($calls)->not->toContain('comment create', 'comment update', 'comment delete');
})->with(['incomplete', 'unknown', 'invalid byline']);

test('a refused or uncertain creation never issues a compensating write', function (string $failure): void {
    $environment = $failure === 'refused' ? ['CREATE_RC' => '1'] : ['READBACK' => 'missing'];
    [$rc, , $calls] = jiraPreviewPublish(jiraSummaryPage([]), environment: $environment);

    expect($rc)->toBe(3)->and(substr_count($calls, 'comment create'))->toBe(1)
        ->and($calls)->not->toContain('comment delete', 'comment update', 'attachment');
})->with(['refused', 'unknown']);

test('migration requires the original exact marker, account and unchanged body', function (string $case): void {
    $marker = 'cr-comment:actor=' . substr(hash('sha256', 'qa@example.test'), 0, 16) . ' lines=1234abcd';
    $body = jiraLegacyPreviewAdf($marker, $case === 'quoted');
    $prior = $case === 'edited' ? jiraLegacyPreviewAdf($marker . ',cafefeed') : $body;
    $account = match ($case) {
        'another account' => 'stranger',
        'missing account' => '',
        default => 'qa',
    };
    $author = ['accountId' => $account, 'emailAddress' => $case === 'another email' ? 'other@example.test' : 'qa@example.test'];
    [$rc, , $calls] = jiraPreviewPublish(
        jiraSummaryPage([['id' => '17', 'author' => $author, 'body' => $body]]),
        $prior,
        ['NO_ACTOR' => $case === 'missing authenticated account' ? '1' : '0'],
    );

    expect($rc)->toBe($case === 'owned' ? 0 : 3)
        ->and(substr_count($calls, 'comment update'))->toBe($case === 'owned' ? 1 : 0)
        ->and($calls)->not->toContain('comment create', 'comment delete');
})->with(['owned', 'another account', 'another email', 'missing account', 'missing authenticated account', 'quoted', 'edited']);

test('only an explicit operator edit updates the named unchanged attributed comment of the same account', function (string $case): void {
    $paragraph = ['content' => [['text' => ($case === 'historical' ? '🤖 ' : '') . 'AI Olympus · Preview.', 'type' => 'text']], 'type' => 'paragraph'];
    $prior = ['content' => [$paragraph], 'type' => 'doc', 'version' => 1];
    $body = $prior;

    if ($case === 'edited') {
        $body['content'][0]['content'][0]['text'] .= ' Manual change.';
    }

    $author = ['accountId' => $case === 'foreign' ? 'another' : 'qa'];
    $page = jiraSummaryPage([['author' => $author, 'body' => $body, 'id' => '17']]);
    [$rc, , $calls] = jiraPreviewPublish($page, $prior, edit: $case !== 'implicit');
    expect($rc)->toBe(in_array($case, ['explicit', 'historical'], strict: true) ? 0 : 3)
        ->and(substr_count($calls, 'comment update'))->toBe(in_array($case, ['explicit', 'historical'], strict: true) ? 1 : 0)
        ->and($calls)->not->toContain('comment create', 'comment delete');
})->with(['explicit', 'historical', 'implicit', 'edited', 'foreign']);

test('the Jira media probe binds the public attachment bytes and accepts only the exact media redirect', function (): void {
    $dir = dirname(__DIR__, 3) . '/skills/code-review-jira/scripts';
    $fixture = <<<'PY'
import hashlib
import struct
import zlib
from unittest.mock import patch
import jira_attachment_media as probe

def chunk(kind, data):
    return struct.pack('>I', len(data)) + kind + data + struct.pack('>I', zlib.crc32(kind + data))

image = (b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', 32, 16, 8, 2, 0, 0, 0))
         + chunk(b'IDAT', zlib.compress(bytes((1 + 32 * 3) * 16))) + chunk(b'IEND', b''))
digest = hashlib.sha256(image).hexdigest()
item = {'id': '24', 'size': len(image)}
identity = 'da9acf8d-e3cf-468f-9910-0da5385562ed'
location = 'https://api.media.atlassian.com/file/' + identity + '/binary?token=private'
cases = [
    (image, 303, [location], True),
    (image[:-1], 303, [location], False),
    (image, 200, [location], False),
    (image, 303, [location, location], False),
    (image, 303, [location.replace('api.media.atlassian.com', 'outside.example')], False),
    (image, 303, [location.replace(identity, '24')], False),
]
for content, code, targets, accepted in cases:
    with patch.object(probe, 'public_get', side_effect=[(200, [], content), (code, targets, b'')]) as get:
        try:
            proof = probe.attachment_identity('example.atlassian.net', 'fake', item, digest)
            assert accepted and proof == {'id': identity, 'width': 32, 'height': 16}
        except probe.Unconfirmed:
            assert not accepted
        assert all(call.args[0] == 'example.atlassian.net' and call.args[1].startswith('/rest/api/3/attachment/content/24?') for call in get.call_args_list)
PY;
    $process = new Process(['python3', '-B', '-c', $fixture], $dir);
    $process->run();

    expect($process->getErrorOutput())->toBe('')
        ->and($process->getExitCode())->toBe(0);
});
