<?php

declare(strict_types = 1);

use Symfony\Component\Process\Process;

const JIRA_SUMMARY_ACLI_FIXTURE = <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >> "$FIXTURE/calls"
case "$1 $2 $3" in
  'jira auth status')
    printf 'Site: example.atlassian.net\nEmail: qa@example.test\n'
    exit ;;
  'jira workitem search')
    if [[ "${NO_ACTOR:-0}" == 1 ]]; then echo '[]'; exit; fi
    echo '{"issues":[{"fields":{"assignee":{"accountId":"qa"}}}]}'
    exit ;;
  'jira workitem view')
    if [[ -f "$FIXTURE/sent" && "${READBACK:-ok}" == ok ]]; then
      jq -n --slurpfile sent "$FIXTURE/sent" '
        {fields:{comment:{startAt:0,total:1,comments:[{id:"17",author:{accountId:"qa"},body:$sent[0]}]}}}'
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
    $page = ['comments' => $comments, 'startAt' => 0, 'total' => $total ?? count($comments)];

    return [
        'fields' => ['comment' => $page],
        'id' => '123',
        'key' => 'TEST-1',
        'self' => 'https://jira-prod-eu-33-as-3.prod.atl-paas.net/rest/api/3/issue/123',
    ];
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
 * @param array<string,mixed> $page
 * @return array{string, \Symfony\Component\Process\Process, string}
 */
function jiraPreviewCommands(array $page, string $priorIntent): array
{
    $dir = sys_get_temp_dir() . '/jira-summary-' . bin2hex(random_bytes(8));
    mkdir($dir, 0o700);
    $dir = (string) realpath($dir);
    (new Process(['git', 'init', '--quiet', $dir]))->mustRun();
    mkdir($dir . '/.codex/run', 0o700, recursive: true);
    $ledger = $dir . '/.codex/run/preview.audit';
    file_put_contents($ledger, $priorIntent);
    file_put_contents($dir . '/before', json_encode($page, JSON_THROW_ON_ERROR));
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

    return [$dir, $jq, $ledger];
}

/**
 * @param array<string,mixed> $page
 * @param array<string,mixed>|null $prior
 * @param array<string,string> $environment
 * @return array{int|null,string,string,string}
 */
function jiraPreviewPublish(array $page, ?array $prior = null, array $environment = [], bool $malformed = false): array
{
    [$dir, $jq, $ledger] = jiraPreviewCommands($page, $environment['PREVIOUS_UPLOAD'] ?? '');
    $arguments = ['--create'];

    if ($prior !== null) {
        file_put_contents($dir . '/prior', json_encode($prior, JSON_THROW_ON_ERROR));
        $arguments = ['--comment-id', '17', '--previous-adf', $dir . '/prior'];
    }

    $entry = dirname(__DIR__, 3) . '/skills/code-review-jira/scripts/upsert-comment.sh';
    $body = ($malformed ? 'Broken byline' : "\nAI Olympus · Modelový výsledek.") . "\n{expand:✅ Findings from the code review}\n"
        . "* Save !example.png|thumbnail!\n{screenshot:f-0123456789abcdef:head}\n{expand}";
    $process = new Process([$entry, '--summary', ...$arguments, 'TEST-1', '-'], $dir, [
        ...$environment,
        'AUDIT_TRAIL_FILE' => $ledger,
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

test('a plain summary omits unused screenshot slots', function (): void {
    [$rc, $error, $calls, $sent] = jiraPreviewPublish(jiraSummaryPage([]));

    expect($rc)->toBe(0)->and($error)->toContain('action=created id=17')
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

test('losing the screenshot source after an uncertain upload blocks even a text-only fallback', function (): void {
    $intent = "april|time|note|jira attachment example.atlassian.net/TEST-1/missing.png|upload intent; no automatic retry\n";
    [$rc, , $calls] = jiraPreviewPublish(jiraSummaryPage([]), environment: ['PREVIOUS_UPLOAD' => $intent]);

    expect($rc)->toBe(3)->and($calls)->not->toContain('comment create', 'attachment', 'comment update', 'comment delete');
});

test('a previously stored summary still blocks retry when the attachment proof becomes unavailable', function (bool $bold): void {
    [$first, , $originalCalls, $sent] = jiraPreviewPublish(jiraSummaryPage([]), environment: ['READBACK' => 'missing']);
    $addMedia = new Process(['jq', '--argjson', 'bold', $bold ? 'true' : 'false', <<<'JQ'
        "This change" as $caption
        | {type:"text", text:$caption} as $text
        | .content[1].content[0].content[0].content += [
            {type:"paragraph", content:[if $bold then ($text + {marks:[{type:"strong"}]}) else $text end]},
            {type:"mediaSingle", content:[{type:"media", attrs:{alt:$caption, type:"file"}}]}
          ]
        JQ
    ], input: $sent);
    $addMedia->mustRun();
    $stored = json_decode($addMedia->getOutput(), associative: true, flags: JSON_THROW_ON_ERROR);
    [$retry, , $retryCalls] = jiraPreviewPublish(jiraSummaryPage([['id' => '17', 'author' => ['accountId' => 'qa'], 'body' => $stored]]));

    expect($first)->toBe(3)->and($retry)->toBe(3)
        ->and(substr_count($originalCalls . $retryCalls, 'comment create'))->toBe(1)
        ->and($retryCalls)->not->toContain('comment delete', 'comment update');
})->with([false, true]);

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

test('the public Jira publisher requires a bound dispatch and confirms its sole API operation', function (string $scenario): void {
    $fixture = dirname(__DIR__, 2) . '/Fixtures/jira-publication/contract.py';
    $process = new Process(['python3', '-B', $fixture, $scenario]);
    $process->setTimeout(90);
    $process->run();

    expect($process->getErrorOutput())->toBe('')
        ->and($process->getExitCode())->toBe(0);
})->with([
    'edit', 'historical', 'edit-lost', 'mismatched-readback', 'target', 'actor', 'issue', 'previous', 'foreign-comment', 'visibility',
    'no-context', 'wiki-change', 'manual', 'media', 'legacy-review', 'text', 'partial', 'head-moved', 'review-moved', 'review-before-comment',
    'sync', 'create-lost', 'upload-lost', 'concurrent',
]);

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

auth = 'Site: example.atlassian.net\nEmail: qa@example.test\n'
with patch.object(probe, 'run_cli', side_effect=[auth, '']), patch.object(probe, 'authorization_for') as credentials:
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

backend = 'jira-prod-eu-33-as-3.prod.atl-paas.net'
view['self'] = f'https://{backend}/rest/api/3/issue/7'
view['fields']['attachment'][0]['content'] = f'https://{backend}/rest/api/3/attachment/content/24'
with patch.object(probe, 'run_cli', return_value=probe.json.dumps(view)):
    assert probe.attachment_inventory('TEST-1', 'example.atlassian.net', '7') == view['fields']['attachment']
    for wrong_number in (None, '8'):
        try:
            probe.attachment_inventory('TEST-1', 'example.atlassian.net', wrong_number)
            raise AssertionError('a shared service origin was used as tenant or issue authority')
        except probe.DestinationChanged:
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

spec = importlib.util.spec_from_file_location('prepare', 'jira_review_selection.py')
prepare = importlib.util.module_from_spec(spec)
spec.loader.exec_module(prepare)
pr, head = 'https://github.com/example/project/pull/7', 'a' * 40
binding = {'site': 'example.atlassian.net', 'key': 'TEST-1', 'account': 'qa'}
entries = [{'finding_id': fid, 'finding_type': 'bug', 'state': 'head', 'file': fid + '.png'} for fid in ('unique', 'ambiguous', 'unverified')]
captures = [dict(entry, do_not_file_publicly=False, step='Save', viewport='desktop', verdict='failed', kind='ui', own_pixels=True, secret_free=True,
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
# A later trusted comment can supersede an otherwise valid, current native review.
from subprocess import check_output
native = dict(id=7,body='<!-- cr-comment:actor=qa -->',state='APPROVED',commit_id=head,
              submitted_at='2026-11-20T10:00:00Z',user={'login':'qa'},author_association='MEMBER')
newer = dict(id=8,body='<!-- cr-comment:actor=qa -->',created_at='2026-11-21T10:00:00Z',user={'login':'qa'},author_association='MEMBER')
def reports(*args):
    if args[0] == 'jq': return check_output(args).decode()
    return json.dumps([[native] if '/reviews?' in args[-1] else [newer]])
with tempfile.TemporaryDirectory() as work, patch.object(prepare, 'run_cli', side_effect=reports):
    try:
        prepare.current_review('example/project','7',head,Path(work))
        raise AssertionError('superseded review accepted')
    except prepare.StaleReview:
        pass
with patch.object(prepare, 'current_head', return_value=None), patch.object(prepare, 'current_review', return_value=newer):
    try:
        prepare.revalidate(pr,'TEST-1',head,'example/project','7',native,Path('.'))
        raise AssertionError('summary from a superseded review was accepted')
    except prepare.StaleReview:
        pass

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
assert matched['content'][1]['content'] == [{'type': 'text', 'text': 'This change', 'marks': [{'type': 'strong'}]}]
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
            out.write(f'april|time|note|{target}|write failed (exit {code}); {detail}; the remote state was not verified\n')
    with patch.dict(os.environ, {'AUDIT_TRAIL_FILE': str(ledger)}), patch.object(media, 'run_cli', return_value=str(root)), \
         patch.object(media, 'authenticated_identity', return_value=('example.atlassian.net', 'qa', 'fixture')), \
         patch.object(media, 'attachment_inventory', return_value=[]), patch.object(media, 'audit_record', side_effect=audit), \
         patch.object(media, 'check_api_actor'), patch.object(media, 'upload', side_effect=TimeoutError) as upload:
        missing = {'id':'17','size':len(data),'filename':'approved.png','mimeType':'image/png','author':{'accountId':'foreign'}}
        for inventory in ([], [], OSError(), [missing]):
            with patch.object(media, 'attachment_inventory', side_effect=[inventory]):
                try:
                    media.confirmed_file('TEST-1', 'example', 'approved.png', data, digest, ('example.atlassian.net', 'qa'), '123')
                    raise AssertionError('unconfirmed upload succeeded')
                except media.UncertainWrite:
                    pass
        upload.assert_called_once_with('example.atlassian.net', '123', 'fixture', 'approved.png', data)

with patch.object(media.http.client, 'HTTPSConnection') as connection:
    for invalid in ('TEST-1', '', '0', None):
        try:
            media.upload('example.atlassian.net', invalid, 'fixture', 'approved.png', b'crop')
            raise AssertionError('invalid issue ID reached the transport')
        except Unconfirmed:
            pass
    connection.assert_not_called()
    connection.return_value.request.side_effect = TimeoutError
    try:
        media.upload('example.atlassian.net', '123', 'fixture', 'approved.png', b'crop')
    except TimeoutError:
        pass
    assert connection.return_value.request.call_args.args == ('POST', '/rest/api/3/issue/123/attachments')
PY;
    $process = new Process(['python3', '-B', '-c', $fixture], $dir);
    $process->run();

    expect($process->getErrorOutput())->toBe('')
        ->and($process->getExitCode())->toBe(0);
});

test('a plain H3 preserves the title text and every other node of a legacy summary', function (): void {
    $fixture = <<<'PY'
import json
import subprocess
from jira_attachment_media import Unconfirmed
from jira_comment_api import heading_change

wiki = 'AI Olympus · Review\n\nh2. PR #7 — {{Historical title}}\n\nh2. PR #8 — Other title\n'
def convert(source):
    return json.loads(subprocess.run(['php', 'wiki-markup-to-adf.php'], input=source.encode(), capture_output=True, check=True).stdout)
original = convert(wiki)
proposed = wiki.replace('h2. PR #7', 'h3. PR #7').replace('{{Historical title}}', 'Historical title')
plan = {'heading_pr': '7', 'plain_h3_heading': True}
assert heading_change(plan, original, proposed.encode()) == convert(proposed)
for other in (proposed.replace('Historical title', '{{Historical title}}'), proposed.replace('Historical title', 'Rewritten title'),
              proposed.replace('h2. PR #8', 'h3. PR #8'), proposed + 'Additional text'):
    try:
        heading_change(plan, original, other.encode())
        raise AssertionError('unapproved heading or body change accepted')
    except Unconfirmed:
        pass
PY;
    $process = new Process(['python3', '-B', '-c', $fixture], dirname(__DIR__, 3) . '/skills/code-review-jira/scripts');
    $process->mustRun();

    expect($process->getOutput())->toBe('');
});

test('an explicit Jira API edit verifies the same comment before and after its only write', function (): void {
    $dir = dirname(__DIR__, 3) . '/skills/code-review-jira/scripts';
    $fixture = <<<'PY'
from contextlib import contextmanager, ExitStack
from pathlib import Path
from tempfile import TemporaryDirectory
from unittest.mock import patch
import os
import jira_comment_api as api

site, key, actor, issue, number = 'example.atlassian.net', 'TEST-1', 'fixture-actor', '123', '17'
plan = dict(url=f'https://{site}/browse/{key}',mode='operator-edit',actor=actor,issue_id=issue,comment_id=number,heading_pr='7',
            previous='private-original',previous_sha='a'*64,wiki='summary',wiki_sha='b'*64,slug='fixture')
old, new = {'private':'before'}, {'private':'after'}
source = dict(id=number,author={'accountId':actor},jsdPublic=True,body=old)
stored = dict(source,body=new)
issue_result = dict(id=issue,key=key,self=f'https://{site}/rest/api/3/issue/{issue}')

write_audit, sync_file = api.audit_record, os.fsync
os.environ['AUDIT_TRAIL_ROLE']='april'
for scenario in ('ok','wrong actor','wrong ticket','changed','private','uncertain','unconfirmed','prior intent','sync failure'):
    calls, events = [], []
    @contextmanager
    def ledger(slug, *, writable):
        assert writable
        previous = f'april|stamp|note|jira operator edit {site}/{key}/{number} '+plan['wiki_sha']+'|unknown\n'
        with TemporaryDirectory() as work:
            path = Path(work)/'fixture.audit'
            path.write_text(previous if scenario=='prior intent' else '')
            with path.open('r+') as stream:
                yield path, stream
    def audit(*args):
        write_audit(*args)
        events.append('append')
    def sync(fd):
        assert b'operator edit intent; no automatic retry' in os.pread(fd,4096,0)
        events.append('sync')
        if scenario=='sync failure': raise OSError
        sync_file(fd)
    before = dict(source)
    if scenario=='private': before['visibility']={'type':'role','value':'internal'}
    if scenario=='changed': before['body']=new
    def get(host,path,header):
        assert host==site and header=='fixture-secret'
        calls.append(('GET',path))
        if path.endswith('?fields=key'):
            return dict(issue_result,key='OTHER-1') if scenario=='wrong ticket' else issue_result
        assert path==f'/rest/api/3/issue/{issue}/comment/{number}'
        return before if len(calls)==2 or scenario=='unconfirmed' else stored
    def put(host,path,header,document):
        assert host==site and path==f'/rest/api/3/issue/{issue}/comment/{number}' and header=='fixture-secret' and document==new
        calls.append(('PUT',path))
        events.append('put')
        if scenario=='uncertain': raise TimeoutError
        return stored
    with ExitStack() as stack:
        def mock(name,**options): return stack.enter_context(patch.object(api,name,**options))
        mock('read_pinned',side_effect=[api.json.dumps(old).encode(),b'private-wiki'])
        mock('heading_change',return_value=new)
        mock('authenticated_identity',return_value=(site,'foreign' if scenario=='wrong actor' else actor,'fixture-secret'))
        mock('locked_ledger',side_effect=ledger)
        audit_calls=mock('audit_record',side_effect=audit)
        stack.enter_context(patch.object(api.os,'fsync',side_effect=sync))
        mock('fetch',side_effect=get)
        mock('public_get',side_effect=lambda host,path,header,limit: (200, [], api.json.dumps(get(host,path,header)).encode()))
        stack.enter_context(patch.object(api.time,'sleep'))
        mock('put_comment',side_effect=put)
        try:
            result=api.edit_summary(plan)
        except Exception as refused:
            assert scenario!='ok' and 'fixture-secret' not in str(refused)
        else:
            assert scenario=='ok' and result==plan['url']+'?focusedCommentId='+number
        assert sum(m=='PUT' for m,_ in calls)==int(scenario in ('ok','uncertain','unconfirmed'))
        if scenario in ('ok','uncertain','unconfirmed'): assert events[:3]==['append','sync','put']
        if scenario=='sync failure': assert events==['append','sync']
        assert 'fixture-secret' not in str(audit_calls.call_args_list)
PY;
    $process = new Process(['python3', '-B', '-c', $fixture], $dir);
    $process->run();

    expect($process->getErrorOutput())->toBe('')
        ->and($process->getExitCode())->toBe(0);
});
