<?php

declare(strict_types = 1);

use Symfony\Component\Process\Process;

const JIRA_DOWNLOAD_ACLI_SCRIPT = <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail

if [[ "$3" == "view" ]]; then
  printf '%s\n' "$FAKE_ACLI_VIEW_JSON"
  exit 0
fi

if [[ "$3" == "comment" ]]; then
  printf '%s\n' '{"comments": []}'
  exit 0
fi

exit 1
BASH;

const JIRA_DOWNLOAD_CURL_SCRIPT = <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail

out=''
for argument in "$@"; do
  case "$argument" in
    https://*) printf '%s\n' "$argument" >> "$FAKE_CURL_CALLS" ;;
  esac
done

while [[ $# -gt 0 ]]; do
  if [[ "$1" == "--output" ]]; then
    out="$2"
  fi
  shift
done

printf '\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\1\0\0\0\1\x08\x06\0\0\0\x1f\x15\xc4\x89' > "$out"
BASH;

/**
 * @return array{bin: string, calls: string, directory: string}
 */
function createJiraDownloadFixture(): array
{
    $directory = sys_get_temp_dir() . '/ai-olympus-jira-download-' . bin2hex(random_bytes(6));
    $bin = $directory . '/bin';

    mkdir($bin, 0o700, recursive: true);
    file_put_contents($bin . '/acli', JIRA_DOWNLOAD_ACLI_SCRIPT);
    file_put_contents($bin . '/curl', JIRA_DOWNLOAD_CURL_SCRIPT);
    chmod($bin . '/acli', 0o700);
    chmod($bin . '/curl', 0o700);
    file_put_contents($directory . '/calls', '');

    return ['bin' => $bin, 'calls' => $directory . '/calls', 'directory' => $directory];
}

function jiraDownloadViewJson(): string
{
    $attachment = static fn (string $id, string $url): array => [
        'content' => $url,
        'filename' => $id . '.png',
        'id' => $id,
        'mimeType' => 'image/png',
        'size' => 36,
    ];

    return (string) json_encode([
        'fields' => [
            'attachment' => [
                $attachment('1', 'https://acme.atlassian.net/rest/api/3/attachment/content/1'),
                $attachment('2', 'https://evil.example/rest/api/3/attachment/content/2'),
                $attachment('3', 'https://acmexatlassian.net/rest/api/3/attachment/content/3'),
            ],
            'summary' => 'Upload fails',
        ],
        'key' => 'ACME-1234',
    ]);
}

/**
 * @param array{bin: string, calls: string, directory: string} $fixture
 */
function runJiraDownload(array $fixture, string $site): Process
{
    $packageDir = dirname(__DIR__, 3);
    $process = new Process([
        $packageDir . '/skills/code-review-jira/scripts/download-attachments.sh',
        'ACME-1234',
        '--dest',
        $fixture['directory'] . '/out',
    ], $packageDir, [
        'FAKE_ACLI_VIEW_JSON' => jiraDownloadViewJson(),
        'FAKE_CURL_CALLS' => $fixture['calls'],
        'HOME' => $fixture['directory'],
        'JIRA_API_EMAIL' => 'agent@example.com',
        'JIRA_API_TOKEN' => 'token',
        'JIRA_SITE' => $site,
        'PATH' => $fixture['bin'] . ':' . (string) getenv('PATH'),
    ]);

    $process->run();

    return $process;
}

/**
 * @param array{bin: string, calls: string, directory: string} $fixture
 */
function removeJiraDownloadFixture(array $fixture): void
{
    (new Process(['rm', '-rf', $fixture['directory']]))->run();
}

test('the API token is sent only to attachment URLs on the configured site', function (): void {
    $fixture = createJiraDownloadFixture();

    $process = runJiraDownload($fixture, 'acme.atlassian.net');

    $calls = (string) file_get_contents($fixture['calls']);
    $manifest = (string) file_get_contents($fixture['directory'] . '/out/attachments-manifest.json');
    removeJiraDownloadFixture($fixture);

    expect($process->getExitCode())->toBe(0);
    expect($calls)->toBe("https://acme.atlassian.net/rest/api/3/attachment/content/1\n");
    expect(decodedJsonField($manifest, 'attachments.0.status'))->toBe('pass');
    expect(decodedJsonField($manifest, 'attachments.1.status'))->toBe('block');
    expect(decodedJsonField($manifest, 'attachments.2.status'))->toBe('block');
    expect(decodedJsonField($manifest, 'attachments.1.reason'))->toContain('not on the configured site acme.atlassian.net');
});

test('the download stops before any request when no JIRA site is configured', function (): void {
    $fixture = createJiraDownloadFixture();

    $process = runJiraDownload($fixture, '');

    $calls = (string) file_get_contents($fixture['calls']);
    removeJiraDownloadFixture($fixture);

    expect($process->getExitCode())->toBe(2);
    expect($process->getErrorOutput())->toContain('no configured JIRA site');
    expect($calls)->toBe('');
});
