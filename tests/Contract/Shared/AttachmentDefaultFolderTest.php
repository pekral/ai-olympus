<?php

declare(strict_types = 1);

use Symfony\Component\Process\Process;

/**
 * @return array{bin: string, directory: string, scratch: string}
 */
function createAttachmentFolderFixture(): array
{
    $directory = sys_get_temp_dir() . '/ai-olympus-attachment-folder-' . bin2hex(random_bytes(6));
    $bin = $directory . '/bin';
    mkdir($bin, 0o700, recursive: true);
    mkdir($directory . '/scratch', 0o700);

    foreach (['jq', 'file'] as $binary) {
        $resolved = trim((string) shell_exec('command -v ' . $binary));

        if ($resolved !== '') {
            symlink($resolved, $bin . '/' . $binary);
        }
    }

    foreach (['curl', 'gh'] as $binary) {
        file_put_contents($bin . '/' . $binary, "#!/usr/bin/env bash\nexit 1\n");
        chmod($bin . '/' . $binary, 0o700);
    }

    return ['bin' => $bin, 'directory' => $directory, 'scratch' => $directory . '/scratch'];
}

/**
 * @param array{bin: string, directory: string, scratch: string} $fixture
 * @param array<string, string> $environment
 * @return array{exit: int|null, folders: list<string>}
 */
function runFailedDownloadWithoutDest(array $fixture, string $tracker, string $reference, array $environment): array
{
    $packageDir = dirname(__DIR__, 3);
    $process = new Process([
        $packageDir . '/skills/code-review-' . $tracker . '/scripts/download-attachments.sh',
        $reference,
    ], $packageDir, [
        'CLAUDE_SCRATCHPAD_DIR' => $fixture['scratch'],
        'HOME' => $fixture['directory'],
        'PATH' => $fixture['bin'] . ':/usr/bin:/bin',
    ] + $environment);
    $process->run();
    $globbed = glob($fixture['scratch'] . '/attachments*');

    return ['exit' => $process->getExitCode(), 'folders' => $globbed === false ? [] : $globbed];
}

test('a download that stops before it fetches anything leaves no default folder behind, for every tracker', function (): void {
    $fixture = createAttachmentFolderFixture();

    $results = [
        'jira' => runFailedDownloadWithoutDest($fixture, 'jira', 'ACME-1234', [
            'JIRA_API_EMAIL' => 'bot@acme.test',
            'JIRA_API_TOKEN' => 'secret',
            'JIRA_SITE' => 'acme.atlassian.net',
        ]),
        'github' => runFailedDownloadWithoutDest($fixture, 'github', 'https://github.com/acme/app/issues/1', []),
        'bugsnag' => runFailedDownloadWithoutDest($fixture, 'bugsnag', 'acme/app/0123456789abcdef01234567', [
            'BUGSNAG_TOKEN' => 'stub-token',
        ]),
    ];
    (new Process(['rm', '-rf', $fixture['directory']]))->run();

    expect($results)->toBe([
        'jira' => ['exit' => 3, 'folders' => []],
        'github' => ['exit' => 2, 'folders' => []],
        'bugsnag' => ['exit' => 3, 'folders' => []],
    ]);
});
