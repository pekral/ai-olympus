<?php

declare(strict_types = 1);

use Symfony\Component\Process\Process;

const JIRA_SUMMARY_ACLI_FIXTURE = <<<'SH'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >> "$FIXTURE/calls"
case "$1 $2 $3" in
  'jira auth status') printf 'Site: example.atlassian.net\nEmail: qa@example.test\n'; exit ;;
  'jira workitem search') echo '{"issues":[{"fields":{"assignee":{"accountId":"qa"}}}]}'; exit ;;
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

    return ['type' => 'doc', 'version' => 1, 'content' => [['type' => 'expand', 'attrs' => ['title' => 'Technical marker for AI agents'],
        'content' => [['type' => 'paragraph', 'content' => [$marker]]]]]];
}

/**
 * @param array<string,mixed> $page
 * @param array<string,mixed>|null $prior
 * @param array<string,string> $environment
 * @return array{int|null,string,string,string}
 */
function jiraPreviewPublish(array $page, ?array $prior = null, array $environment = [], bool $malformed = false): array
{
    $dir = sys_get_temp_dir() . '/jira-summary-' . bin2hex(random_bytes(8));
    mkdir($dir, 0o700);
    file_put_contents($dir . '/acli', JIRA_SUMMARY_ACLI_FIXTURE);
    chmod($dir . '/acli', 0o700);
    file_put_contents($dir . '/before', json_encode($page, JSON_THROW_ON_ERROR));
    file_put_contents($dir . '/audit', '');
    $arguments = ['--create', '--screenshots', $dir . '/unused', '--slug', 'preview'];
    if ($prior !== null) {
        file_put_contents($dir . '/prior', json_encode($prior, JSON_THROW_ON_ERROR));
        $arguments = ['--comment-id', '17', '--previous-adf', $dir . '/prior'];
    }

    $entry = dirname(__DIR__, 3) . '/skills/code-review-jira/scripts/upsert-comment.sh';
    $body = ($malformed ? 'Broken byline' : "\n🤖 AI Olympus · Modelový výsledek.") . "\n{expand:✅ Findings from the code review}\n"
        . "* Save !example.png|thumbnail!\n{screenshot:f-0123456789abcdef:head}\n{expand}";
    $process = new Process([$entry, '--summary', ...$arguments, 'TEST-1', '-'], $dir, [
        ...$environment, 'FIXTURE' => $dir, 'AUDIT_TRAIL_FILE' => $dir . '/audit', 'AUDIT_TRAIL_ROLE' => 'april',
        'PATH' => $dir . PATH_SEPARATOR . (string) getenv('PATH'),
    ], $body);
    try {
        $process->run();

        return [$process->getExitCode(), $process->getErrorOutput(), (string) file_get_contents($dir . '/calls'),
            is_file($dir . '/sent') ? (string) file_get_contents($dir . '/sent') : ''];
    } finally {
        (new Process(['rm', '-rf', $dir]))->mustRun();
    }
}

test('the screenshot fallback creates only the attributed structured summary and no attachment', function (): void {
    [$rc, $error, $calls, $sent] = jiraPreviewPublish(jiraSummaryPage([]));

    expect($rc)->toBe(0)->and($error)->toContain('action=created id=17', 'text only')
        ->and($calls)->not->toContain('comment update', 'comment delete', 'attachment')
        ->and($sent)->toContain('"expand"')->not->toContain(':actor=', '{screenshot:', '"media"');
});

test('an incomplete or unknown snapshot and an invalid explicit summary permit no write', function (array $page, bool $malformed): void {
    [$rc, , $calls] = jiraPreviewPublish($page, malformed: $malformed);

    expect($rc)->not->toBe(0)->and($calls)->not->toContain('comment create', 'comment update', 'comment delete');
})->with([[jiraSummaryPage([], 1), false], [['results' => []], false], [jiraSummaryPage([]), true]]);

test('a refused or uncertain creation never issues a compensating write', function (array $environment): void {
    [$rc, , $calls] = jiraPreviewPublish(jiraSummaryPage([]), environment: $environment);

    expect($rc)->toBe(3)->and(substr_count($calls, 'comment create'))->toBe(1)
        ->and($calls)->not->toContain('comment delete', 'comment update', 'attachment');
})->with([[['CREATE_RC' => '1']], [['READBACK' => 'missing']]]);

test('migration requires the original exact marker, account and unchanged body', function (string $case): void {
    $marker = 'cr-comment:actor=' . substr(hash('sha256', 'qa@example.test'), 0, 16) . ' lines=1234abcd';
    $body = jiraLegacyPreviewAdf($marker, $case === 'quoted');
    $prior = $case === 'edited' ? jiraLegacyPreviewAdf($marker . ',cafefeed') : $body;
    $author = ['accountId' => $case === 'another account' ? 'stranger' : 'qa', 'emailAddress' => 'qa@example.test'];
    [$rc, , $calls] = jiraPreviewPublish(jiraSummaryPage([['id' => '17', 'author' => $author, 'body' => $body]]), $prior);

    expect($rc)->toBe($case === 'owned' ? 0 : 3)
        ->and(substr_count($calls, 'comment update'))->toBe($case === 'owned' ? 1 : 0)
        ->and($calls)->not->toContain('comment create', 'comment delete');
})->with(['owned', 'another account', 'quoted', 'edited']);
