<?php

declare(strict_types = 1);

use Symfony\Component\Process\Process;

const JIRA_SHOT_ACLI = <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
printf '%q ' "$@" >> "$STUB_DIR/acli.log"
printf '\n' >> "$STUB_DIR/acli.log"

if [[ "$1 $2 $3" == "jira auth status" ]]; then
  printf 'Site: example.atlassian.net\nEmail: tester@example.test\n'
  exit 0
fi
if [[ "$1 $2" == "jira workitem" && "$3" == "search" ]]; then
  echo '{"issues":[{"fields":{"assignee":{"accountId":"acc-1"}}}]}'
  exit 0
fi
if [[ "$1 $2 $3" == "jira workitem view" ]]; then
  if [[ -f "$STUB_DIR/rest.body" ]]; then
    marker="$(grep -o 'cr-comment:actor=[0-9a-f]\{16\}' "$STUB_DIR/rest.body" | head -n1)"
    jq -n --arg m "$marker" '{fields: {comment: {comments: [{id: "555", author: {accountId: "acc-1", emailAddress: "tester@example.test"}, body: {version: 1, type: "doc", content: [{type: "paragraph", content: [{type: "text", text: $m, marks: [{type: "em"}]}]}]}}]}}}'
  elif [[ -f "$STUB_DIR/acli.created" ]]; then
    marker="$(jq -r '[.. | objects | select(.type? == "text") | .text? | strings | select(test("cr-comment:actor="))] | first // ""' "$STUB_DIR/acli.created")"
    jq -n --arg m "$marker" --slurpfile body "$STUB_DIR/acli.created" '{fields: {comment: {comments: [{id: "777", author: {accountId: "acc-1", emailAddress: "tester@example.test"}, body: $body[0]}]}}}'
  else
    echo '{"fields":{"comment":{"comments":[]}}}'
  fi
  exit 0
fi
if [[ "$1 $2 $3 $4" == "jira workitem comment create" ]]; then
  while [[ $# -gt 0 ]]; do
    if [[ "$1" == "--body-file" ]]; then cp "$2" "$STUB_DIR/acli.created"; fi
    shift
  done
  echo '{"id":"777"}'
  exit 0
fi
if [[ "$1 $2 $3 $4" == "jira workitem comment update" ]]; then
  while [[ $# -gt 0 ]]; do
    if [[ "$1" == "--body-adf" ]]; then cp "$2" "$STUB_DIR/acli.created"; fi
    shift
  done
  exit 0
fi
exit 1
BASH;

const JIRA_SHOT_CURL = <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >> "$STUB_DIR/curl.log"
out=""; form=""; data=""; method="GET"; url=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    --output) out="$2"; shift 2 ;;
    --form) form="$2"; shift 2 ;;
    --data) data="${2#@}"; shift 2 ;;
    --request) method="$2"; shift 2 ;;
    https://*) url="$1"; shift ;;
    *) shift ;;
  esac
done
case "$method $url" in
  "POST "*"/attachments")
    name="${form#file=@}"; name="${name%%;*}"
    printf '[{"id":"9%s","filename":"%s"}]' "$(wc -l < "$STUB_DIR/uploads.log" 2>/dev/null | tr -d ' ' || echo 0)" "$name" > "$out"
    printf '%s\n' "$name" >> "$STUB_DIR/uploads.log"
    printf '200' ;;
  "POST "*"/rest/api/2/issue/"*"/comment")
    cp "$data" "$STUB_DIR/rest.json"
    case "${STUB_REST:-created}" in
      created) jq -r .body "$data" > "$STUB_DIR/rest.body"; printf '{"id":"555"}' > "$out"; printf '201' ;;
      refused) printf '{"errorMessages":["no"]}' > "$out"; printf '403' ;;
      broken) printf '' > "$out"; printf '500' ;;
    esac ;;
  "DELETE "*"/attachment/"*) printf '%s\n' "$url" >> "$STUB_DIR/deleted.log"; printf '' > "$out"; printf '204' ;;
  *) printf '{}' > "$out"; printf '404' ;;
esac
BASH;

/**
 * @return array{dir: string, repo: string, png: string, manifest: string}
 */
function jiraShotFixture(): array
{
    $dir = sys_get_temp_dir() . '/ai-olympus-jira-shot-' . bin2hex(random_bytes(6));
    mkdir($dir . '/bin', 0o700, recursive: true);
    mkdir($dir . '/home', 0o700, recursive: true);
    mkdir($dir . '/repo/.claude/run/demo.artifacts', 0o700, recursive: true);
    file_put_contents($dir . '/bin/acli', JIRA_SHOT_ACLI);
    file_put_contents($dir . '/bin/curl', JIRA_SHOT_CURL);
    chmod($dir . '/bin/acli', 0o700);
    chmod($dir . '/bin/curl', 0o700);
    (new Process(['git', 'init', '-q'], $dir . '/repo'))->mustRun();

    $chunk = static fn (string $type, string $data): string => pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    $png = "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', 1, 1, 8, 2, 0, 0, 0)) . $chunk('IDAT', (string) gzcompress(
        "\x00\xff\x00\x00",
    )) . $chunk('IEND', '');
    $realRepo = (string) realpath($dir . '/repo');

    foreach (['head', 'base'] as $state) {
        file_put_contents($realRepo . '/.claude/run/demo.artifacts/step-' . $state . '.png', $png);
    }

    return ['dir' => $dir, 'repo' => $realRepo, 'png' => $realRepo . '/.claude/run/demo.artifacts/step-head.png', 'manifest' => $dir . '/manifest.json'];
}

/**
 * @param array<string, mixed> $override
 * @return array<string, mixed>
 */
function jiraShotEntry(string $repo, string $state, string $findingId = 'f-0123456789abcdef', array $override = []): array
{
    return [...[
        'crop' => 'element',
        'default_branch' => $state === 'base' ? 'master' : null,
        'do_not_file_publicly' => false,
        'file' => $repo . '/.claude/run/demo.artifacts/step-' . $state . '.png',
        'finding_id' => $findingId,
        'finding_type' => 'bug',
        'masked_in_capture_session' => true,
        'masking' => 'verified',
        'not_error_page' => true,
        'own_data_only' => true,
        'state' => $state,
    ], ...$override,
    ];
}

function jiraShotBody(): string
{
    return "Deploy verdict.\n\n{expand:Findings from the code review}\n"
        . "* *(bug):* Save fails with !evil|thumbnail! text. [View in code →|https://github.com/o/r/pull/7/files]\n"
        . "{screenshot:f-0123456789abcdef:base}\n{screenshot:f-0123456789abcdef:head}\n"
        . "* *(security):* A second point. [View in code →|https://github.com/o/r/pull/7/files]\n"
        . "{screenshot:f-1111111111111111:head}\n{expand}\n\n----\n\n_This comment is generated automatically._\n\n{expand:Technical marker for AI agents}\n{agent-marker}\n{expand}\n";
}

/**
 * @param callable(string): list<array<string, mixed>> $entries builds the manifest entries from the repository path of the fixture
 * @param list<string> $arguments
 * @param array<string, string> $env
 * @return array{process: \Symfony\Component\Process\Process, dir: string, log: callable(string): string}
 */
function jiraShotRun(callable $entries, array $arguments = ['--create'], array $env = []): array
{
    $fixture = jiraShotFixture();
    file_put_contents($fixture['manifest'], json_encode($entries($fixture['repo']), JSON_THROW_ON_ERROR));
    $package = dirname(__DIR__, 3);
    $process = new Process(
        [$package . '/skills/code-review-jira/scripts/upsert-comment.sh', ...$arguments, '--screenshots', $fixture['manifest'], '--slug', 'demo', 'ECO-7', '-'],
        $fixture['repo'],
        [...$env,
            'AUDIT_TRAIL_FILE' => $fixture['dir'] . '/run.audit',
            'AUDIT_TRAIL_ROLE' => 'leonardo',
            'HOME' => $fixture['dir'] . '/home',
            'JIRA_API_EMAIL' => 'tester@example.test',
            'JIRA_API_TOKEN' => 'tok-never-printed-123',
            'PATH' => $fixture['dir'] . '/bin' . PATH_SEPARATOR . '/usr/bin' . PATH_SEPARATOR . '/bin' . PATH_SEPARATOR . '/opt/homebrew/bin' . PATH_SEPARATOR . (string) getenv(
    'PATH',
    ),
            'STUB_DIR' => $fixture['dir'],

        ],
        jiraShotBody(),
    );
    file_put_contents($fixture['dir'] . '/run.audit', '');
    $process->run();

    return [
        'dir' => $fixture['dir'],
        'log' => static fn (string $name): string => is_file($fixture['dir'] . '/' . $name) ? (string) file_get_contents(
            $fixture['dir'] . '/' . $name,
        ) : '',
        'process' => $process,
    ];
}

function jiraShotCleanup(string $dir): void
{
    (new Process(['rm', '-rf', $dir]))->run();
}

test('an accepted screenshot is attached to the item and shown inside its finding bullet through a REST v2 wiki comment', function (): void {
    $run = jiraShotRun(static fn (string $repo): array => [jiraShotEntry($repo, 'head'), jiraShotEntry($repo, 'base')]);
    $wiki = $run['log']('rest.body');

    expect($run['process']->getExitCode())->toBe(0)
        ->and($run['process']->getErrorOutput())->toContain('action=created id=555')
        ->and($run['log']('curl.log'))->toContain('https://example.atlassian.net/rest/api/3/issue/ECO-7/attachments')
        ->and($run['log']('curl.log'))->toContain('https://example.atlassian.net/rest/api/2/issue/ECO-7/comment')
        ->and($run['log']('curl.log'))->toContain('--max-redirs 0')
        ->and($run['log']('curl.log'))->not->toContain('tok-never-printed-123')
        ->and($wiki)->toMatch(
            '/^\* \*\(bug\):\* Save fails with \\\\!evil\|thumbnail! text\. \[View in code →\|[^\]]+\] \\\\\\\\ \*Default branch \(master\):\* \\\\\\\\ !step-base-[0-9]{8}t[0-9]{6}z\.png\|thumbnail! \\\\\\\\ \*This change:\* \\\\\\\\ !step-head-[0-9]{8}t[0-9]{6}z\.png\|thumbnail!$/m',
        )
        ->and($wiki)->not->toContain('{screenshot:')
        ->and($run['log']('acli.log'))->not->toContain('comment create')
        ->and($run['log']('run.audit'))->toContain('REST v2, 2 screenshots');

    jiraShotCleanup($run['dir']);
});

test('a screenshot of a security finding is never attached and the comment is published as ADF without slot lines', function (): void {
    $run = jiraShotRun(static fn (string $repo): array => [jiraShotEntry($repo, 'head', 'f-1111111111111111', ['finding_type' => 'security'])]);

    expect($run['process']->getExitCode())->toBe(0)
        ->and($run['process']->getErrorOutput())->toContain('a security finding never gets a screenshot')
        ->and($run['log']('curl.log'))->toBe('')
        ->and($run['log']('acli.log'))->toContain('comment create')
        ->and($run['log']('acli.created'))->not->toContain('screenshot:')
        ->and($run['log']('acli.created'))->not->toContain('step-head-');

    jiraShotCleanup($run['dir']);
});

test('a finding flagged do_not_file_publicly gets no attachment', function (): void {
    $run = jiraShotRun(static fn (string $repo): array => [jiraShotEntry($repo, 'head', 'f-0123456789abcdef', ['do_not_file_publicly' => true])]);

    expect($run['process']->getExitCode())->toBe(0)
        ->and($run['log']('curl.log'))->toBe('')
        ->and($run['process']->getErrorOutput())->toContain('do_not_file_publicly');

    jiraShotCleanup($run['dir']);
});

test('a slot line is removed from a comment that is published without screenshots', function (): void {
    $fixture = jiraShotFixture();
    $package = dirname(__DIR__, 3);
    $process = new Process([$package . '/skills/code-review-jira/scripts/upsert-comment.sh', '--create', 'ECO-7', '-'], $fixture['repo'], [
        'STUB_DIR' => $fixture['dir'], 'HOME' => $fixture['dir'] . '/home', 'AUDIT_TRAIL_FILE' => $fixture['dir'] . '/run.audit', 'AUDIT_TRAIL_ROLE' => 'leonardo',
        'PATH' => $fixture['dir'] . '/bin' . PATH_SEPARATOR . '/usr/bin' . PATH_SEPARATOR . '/bin' . PATH_SEPARATOR . '/opt/homebrew/bin' . PATH_SEPARATOR . (string) getenv(
            'PATH',
        ),
    ], jiraShotBody());
    file_put_contents($fixture['dir'] . '/run.audit', '');
    $process->run();

    expect($process->getExitCode())->toBe(0)
        ->and((string) file_get_contents($fixture['dir'] . '/acli.created'))->not->toContain('screenshot:')
        ->and(file_exists($fixture['dir'] . '/curl.log'))->toBeFalse();

    jiraShotCleanup($fixture['dir']);
});

test('a refused REST write removes the attachments of the run and publishes the text comment', function (): void {
    $run = jiraShotRun(static fn (string $repo): array => [jiraShotEntry($repo, 'head')], ['--create'], ['STUB_REST' => 'refused']);

    expect($run['process']->getExitCode())->toBe(0)
        ->and($run['log']('deleted.log'))->toContain('/rest/api/3/attachment/')
        ->and($run['process']->getErrorOutput())->toContain('shows text only')
        ->and($run['log']('acli.log'))->toContain('comment create');

    jiraShotCleanup($run['dir']);
});

test('a REST write with an unknown result stops and writes nothing else', function (): void {
    $run = jiraShotRun(static fn (string $repo): array => [jiraShotEntry($repo, 'head')], ['--create'], ['STUB_REST' => 'broken']);

    expect($run['process']->getExitCode())->toBe(3)
        ->and($run['process']->getErrorOutput())->toContain('the comment may exist')
        ->and($run['log']('acli.log'))->not->toContain('comment create');

    jiraShotCleanup($run['dir']);
});

test('the screenshot options need --create and each other', function (): void {
    $run = jiraShotRun(static fn (string $repo): array => [jiraShotEntry($repo, 'head')], []);

    expect($run['process']->getExitCode())->toBe(1)
        ->and($run['process']->getErrorOutput())->toContain('--screenshots and --slug go together and need --create')
        ->and($run['log']('curl.log'))->toBe('');

    jiraShotCleanup($run['dir']);
});

test('the converter keeps an image form in finding text as plain text', function (): void {
    $package = dirname(__DIR__, 3);
    $process = new Process(
        ['php', $package . '/skills/code-review-jira/scripts/wiki-markup-to-adf.php'],
        $package,
        input: "* A point !x.png|thumbnail! here\n",
    );
    $process->run();
    $types = json_encode(json_decode($process->getOutput(), associative: true), JSON_THROW_ON_ERROR);

    expect($process->getExitCode())->toBe(0)
        ->and($types)->toContain('!x.png|thumbnail!')
        ->and($types)->not->toContain('"media"');
});
