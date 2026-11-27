<?php

declare(strict_types = 1);

use Symfony\Component\Process\Process;

const JIRA_SUMMARY_ACLI_FIXTURE = <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >> "$FIXTURE/calls"
case "$1 $2 $3" in
  'jira auth status')
    config="${ACLI_CONFIG_DIR}/jira_config.yaml"
    if grep -Fxq 'current_profile: [' "$config"; then exit 1; fi
    if [[ "$ACLI_CONFIG_DIR" != "$FIXTURE/profile" && "${SWITCHED_SITE:-0}" == 1 ]]; then
      printf 'Site: other.atlassian.net\nEmail: qa@example.test\n'; exit
    fi
    printf 'Site: example.atlassian.net\nEmail: qa@example.test\n'; exit ;;
  'jira workitem search') if [[ "${SWITCHED_ACTOR:-0}" == 1 && "$ACLI_CONFIG_DIR" != "$FIXTURE/profile" ]]; then echo '[{"fields":{"assignee":{"accountId":"foreign"}}}]'; exit; fi; if [[ "${NO_ACTOR:-0}" == 1 ]]; then echo '[]'; exit; fi; echo '{"issues":[{"fields":{"assignee":{"accountId":"qa"}}}]}'; exit ;;
  'jira workitem view')
    if [[ -f "$FIXTURE/sent" && "${READBACK:-ok}" == ok ]]; then
      jq -n --slurpfile sent "$FIXTURE/sent" '{key:"TEST-1",id:"123",self:"https://example.atlassian.net/rest/api/3/issue/123",fields:{comment:{startAt:0,total:1,comments:[{id:"17",author:{accountId:"qa"},body:$sent[0]}]}}}'
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
    return ['id' => '123', 'key' => 'TEST-1', 'self' => 'https://example.atlassian.net/rest/api/3/issue/123', 'fields' => ['comment' => ['startAt' => 0, 'comments' => $comments, 'total' => $total ?? count($comments)]]];
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
    $dir = (string) realpath($dir);
    mkdir($dir . '/profile', 0o700);
    file_put_contents($dir . '/profile/jira_config.yaml', "current_profile: cloud:qa\nprofiles:\n  - site: example.atlassian.net\n    cloud_id: cloud\n    account_id: qa\n");
    chmod($dir . '/profile/jira_config.yaml', 0o600);
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
    file_put_contents($dir . '/preview.audit', '');
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
        'ACLI_CONFIG_DIR' => $dir . '/profile',
        'AUDIT_TRAIL_FILE' => $dir . '/preview.audit',
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

    expect($rc)->toBe(0)->and($error)->toContain('action=created id=17', 'no eligible verified images')
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

test('a changed isolated Jira site or actor stops before any attachment or comment write', function (string $change): void {
    [$rc, , $calls] = jiraPreviewPublish(jiraSummaryPage([]), environment: [$change => '1']);

    expect($rc)->toBe(3)->and($calls)->not->toContain('attachment', 'comment create', 'comment update', 'comment delete');
})->with(['SWITCHED_SITE', 'SWITCHED_ACTOR']);

test('a previously stored summary still blocks retry when the attachment proof becomes unavailable', function (): void {
    [$first, , $originalCalls, $sent] = jiraPreviewPublish(jiraSummaryPage([]), environment: ['READBACK' => 'missing']);
    $stored = json_decode($sent, true, flags: JSON_THROW_ON_ERROR);
    $item = &$stored['content'][1]['content'][0]['content'][0]['content'];
    $caption = 'This change';
    $item[] = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $caption]]];
    $item[] = ['type' => 'mediaSingle', 'content' => [['type' => 'media', 'attrs' => ['alt' => $caption, 'type' => 'file']]]];
    [$retry, , $retryCalls] = jiraPreviewPublish(jiraSummaryPage([['id' => '17', 'author' => ['accountId' => 'qa'], 'body' => $stored]]));

    expect($first)->toBe(3)->and($retry)->toBe(3)
        ->and(substr_count($originalCalls . $retryCalls, 'comment create'))->toBe(1)
        ->and($retryCalls)->not->toContain('comment delete', 'comment update');
});

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

with patch.object(probe, 'run_cli', side_effect=['Site: example.atlassian.net\nEmail: qa@example.test\n', '']), patch.object(probe, 'authorization_for') as credentials:
    try:
        probe.authenticated_identity()
        raise AssertionError('missing acli identity was accepted')
    except probe.Unconfirmed:
        credentials.assert_not_called()

view = {'key': 'TEST-1', 'id': '7', 'self': 'https://example.atlassian.net/rest/api/3/issue/7', 'fields': {'attachment': [
    {'id': '24', 'content': 'https://other.atlassian.net/rest/api/3/attachment/content/24'}
]}}
with patch.object(probe, 'run_cli', return_value=probe.json.dumps(view)):
    try:
        probe.attachment_inventory('TEST-1', 'example.atlassian.net')
        raise AssertionError('foreign attachment inventory was accepted')
    except probe.Unconfirmed:
        pass

PY;
    $process = new Process(['python3', '-B', '-c', $fixture], $dir);
    $process->run();

    expect($process->getErrorOutput())->toBe('')
        ->and($process->getExitCode())->toBe(0);
});

test('only independently verified captures at unambiguous accepted finding locations survive selection', function (): void {
    $dir = dirname(__DIR__, 3) . '/skills/code-review-jira/scripts';
    $fixture = <<<'PY'
import importlib.util
import json
import tempfile
from contextlib import ExitStack
from pathlib import Path
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('prepare', 'prepare-review-media.py')
prepare = importlib.util.module_from_spec(spec)
spec.loader.exec_module(prepare)
pr, head = 'https://github.com/example/project/pull/7', 'a' * 40
binding = {'site': 'example.atlassian.net', 'key': 'TEST-1', 'account': 'qa'}
entries = [{'finding_id': fid, 'finding_type': 'bug', 'state': 'head', 'file': fid + '.png'} for fid in ('unique', 'ambiguous', 'unverified')]
captures = [dict(entry, do_not_file_publicly=False, step='Save', viewport='desktop', verdict='failed', kind='ui', own_pixels=True,
                 visually_verified=entry['finding_id'] != 'unverified', revision=head, sha256='1' * 64) for entry in entries]
records = [dict(finding_id=fid, revision=head, status='new', security=False, file='orders.php', line=line)
           for fid, line in [('unique', 2), ('ambiguous', 3), ('other', 3), ('unverified', 4)]]
qa = dict(jira_source='https://example.atlassian.net/browse/TEST-1', target=pr, reviewed_head=head, captures=captures)
report = '## Interactive testing\n| Save | desktop | Failed | error |\n'
def read(*args):
    if args[:2] == ('git', 'rev-parse'): return '/repo'
    if args[0] == 'python3': return json.dumps(qa)
    if args[0] == 'bash' and 'manifest' in args: return json.dumps({'accepted': entries})
    if args[:2] == ('gh', 'api'): return 'release'
    return ''

with tempfile.TemporaryDirectory() as work, ExitStack() as patching:
    def mock(name, **options):
        return patching.enter_context(patch.object(prepare, name, **options))
    mock('run_cli', side_effect=read)
    mock('current_head')
    mock('current_review', return_value={'body': ''})
    mock('accepted_records', return_value=(records, report))
    mock('finding_item', return_value={})
    mock('canonical_png', return_value=b'crop')
    asset = mock('reviewed_asset')
    *_, default, pairs = prepare.selection(binding, 'run', 'run.screenshots.json', {}, Path(work))
    assert default == 'release' and [p[0] for p in pairs] == ['unique'] and asset.call_count == 1
PY;
    $process = new Process(['python3', '-B', '-c', $fixture], $dir);
    $process->run();

    expect($process->getErrorOutput())->toContain('lacks a unique verified capture')
        ->and($process->getExitCode())->toBe(0);
});

test('attachment credential files reject exposed permissions and symbolic links before reading', function (): void {
    $dir = dirname(__DIR__, 3) . '/skills/code-review-jira/scripts';
    $fixture = <<<'PY'
import os
import subprocess
import sys
import tempfile
from pathlib import Path

with tempfile.TemporaryDirectory() as temp:
    base = Path(temp).resolve()
    private = base / 'profile'
    private.mkdir(mode=0o700)
    token = private / 'credential'
    token.write_bytes(b'mock-token\n')
    token.chmod(0o600)
    link = base / 'link'
    link.symlink_to(token)
    linked_dir = base / 'linked-dir'
    linked_dir.symlink_to(private, target_is_directory=True)
    for path, mode, allowed in [(token, 0o600, True), (token, 0o640, False), (link, 0o600, False), (linked_dir / token.name, 0o600, False)]:
        token.chmod(mode)
        result = subprocess.run([sys.executable, '-B', 'read-attachment-token.py', str(path)], capture_output=True)
        assert (result.returncode == 0) == allowed and result.stdout == (b'mock-token' if allowed else b'')
        assert b'mock-token' not in result.stderr
PY;
    $process = new Process(['python3', '-B', '-c', $fixture], $dir);
    $process->run();

    expect($process->getErrorOutput())->toBe('')
        ->and($process->getExitCode())->toBe(0);
});

test('only the matching actionable finding receives verified media and an uncertain upload is never repeated', function (): void {
    $dir = dirname(__DIR__, 3) . '/skills/code-review-jira/scripts';
    $fixture = <<<'PY'
import hashlib
import os
import tempfile
from pathlib import Path
from unittest.mock import patch
import jira_review_media as media
from jira_attachment_media import Unconfirmed

def item(name):
    return {'type': 'listItem', 'content': [{'type': 'paragraph', 'content': [{'type': 'text', 'text': name}]}]}

bug, security = item('(bug): Save'), item('(security): Save')
doc = {'content': [{'type': 'expand', 'attrs': {'title': '⚠️ Findings from the code review'},
                    'content': [{'type': 'bulletList', 'content': [security, bug]}]}]}
matched = media.find_item(doc, lambda p: p['content'][0]['text'].endswith('Save'))
assert matched is bug
verified = {'id': 'da9acf8d-e3cf-468f-9910-0da5385562ed', 'attachment_id': '17', 'width': 120, 'height': 80}
media.attach_media(matched, verified, 'This change')
assert len(security['content']) == 1
assert matched['content'][2]['content'][0]['attrs']['id'] == verified['id']
assert 'attachment_id' not in matched['content'][2]['content'][0]['attrs']

with tempfile.TemporaryDirectory() as temp:
    root = Path(temp).resolve()
    run = root / '.codex/run'
    run.mkdir(parents=True)
    ledger = run / 'example.audit'
    ledger.touch(mode=0o600)
    data = b'canonical-crop'
    digest = hashlib.sha256(data).hexdigest()
    def audit(file, target, code, detail):
        with file.open('a') as out:
            out.write(f'april|time|note|{target}|{code} {detail}\n')
    with patch.dict(os.environ, {'AUDIT_TRAIL_FILE': str(ledger)}), patch.object(media, 'run_cli', return_value=str(root)), \
         patch.object(media, 'authenticated_identity', return_value=('example.atlassian.net', 'qa', 'fixture')), \
         patch.object(media, 'attachment_inventory', return_value=[]), patch.object(media, 'audit_record', side_effect=audit), \
         patch.object(media, 'upload', side_effect=TimeoutError) as upload:
        for _ in range(2):
            try:
                media.confirmed_file('TEST-1', 'example', 'approved.png', data, digest, ('example.atlassian.net', 'qa'))
                raise AssertionError('unconfirmed upload succeeded')
            except media.UncertainWrite:
                pass
        assert upload.call_count == 1
PY;
    $process = new Process(['python3', '-B', '-c', $fixture], $dir);
    $process->run();

    expect($process->getErrorOutput())->toBe('')
        ->and($process->getExitCode())->toBe(0);
});
