<?php

declare(strict_types = 1);

use Symfony\Component\Process\Process;

function manifestRepositoryGit(string $dir, string ...$arguments): string
{
    $process = new Process(['git', '-c', 'user.name=t', '-c', 'user.email=t@t', ...$arguments], $dir);
    $process->mustRun();

    return trim($process->getOutput());
}

/**
 * Builds a repository whose default branch carries a composer.json in one of four states: `readable`, `unreadable`
 * (its blob is deleted, so git cannot read it), `invalid` (not JSON), or `absent` (no composer.json at all).
 */
function repositoryWithManifest(string $state): string
{
    $dir = sys_get_temp_dir() . '/ai-olympus-manifest-' . bin2hex(random_bytes(6));
    mkdir($dir);
    register_shutdown_function(static fn (): int => (new Process(['rm', '-rf', $dir]))->run());
    manifestRepositoryGit($dir, 'init', '-q');
    file_put_contents($dir . '/README.md', "readme\n");
    manifestRepositoryGit($dir, 'add', 'README.md');

    if ($state !== 'absent') {
        file_put_contents($dir . '/composer.json', $state === 'invalid' ? "{ not json\n" : '{"extra":{"ai-olympus":{"voice":"technical"}}}' . "\n");
        manifestRepositoryGit($dir, 'add', 'composer.json');
    }

    manifestRepositoryGit($dir, 'commit', '-q', '-m', 'manifest');
    manifestRepositoryGit($dir, 'update-ref', 'refs/remotes/origin/master', 'HEAD');
    manifestRepositoryGit($dir, 'symbolic-ref', 'refs/remotes/origin/HEAD', 'refs/remotes/origin/master');

    if ($state === 'unreadable') {
        $blob = manifestRepositoryGit($dir, 'rev-parse', 'HEAD:composer.json');
        unlink($dir . '/.git/objects/' . substr($blob, 0, 2) . '/' . substr($blob, 2));
    }

    return $dir;
}

/**
 * @param string $arguments space-separated arguments
 * @param string $input standard input; `%HEAD%` stands for the head commit of the repository
 */
function runInRepository(string $dir, string $script, string $arguments = '', string $input = ''): Process
{
    $input = str_replace('%HEAD%', manifestRepositoryGit($dir, 'rev-parse', 'HEAD'), $input);
    $process = new Process(
        ['bash', dirname(__DIR__, 3) . '/' . $script, ...($arguments === '' ? [] : explode(' ', $arguments))],
        $dir,
        input: $input,
    );
    $process->run();

    return $process;
}

test('a composer.json that git cannot read is reported by the reader and by a caller that falls back to the default', function (): void {
    $dir = repositoryWithManifest('unreadable');
    $reader = runInRepository($dir, 'skills/_shared/read-manifest.sh');
    $voice = runInRepository($dir, 'skills/agent-voice/scripts/resolve-voice.sh');

    expect($reader->getExitCode())->toBe(3)
        ->and($reader->getOutput())->toBe("{}\n")
        ->and($reader->getErrorOutput())->toContain('exists but cannot be read')
        ->and($voice->getExitCode())->toBe(0)
        ->and(trim($voice->getOutput()))->toBe('relaxed')
        ->and($voice->getErrorOutput())->toContain('exists but cannot be read');
});

test('a project with no composer.json keeps the built-in behaviour without a report', function (): void {
    $dir = repositoryWithManifest('absent');
    $reader = runInRepository($dir, 'skills/_shared/read-manifest.sh');
    $voice = runInRepository($dir, 'skills/agent-voice/scripts/resolve-voice.sh');

    expect($reader->getExitCode())->toBe(0)
        ->and($reader->getOutput())->toBe("{}\n")
        ->and($reader->getErrorOutput())->toBe('')
        ->and(trim($voice->getOutput()))->toBe('relaxed')
        ->and($voice->getErrorOutput())->toBe('');
});

/**
 * Every script that reads the manifest through read-manifest.sh and carries on after exit 3. Each row is the script,
 * its arguments, its standard input, the stream that carries the report, and the text the report holds.
 *
 * @return array<string, array{0: string, 1: string, 2: string, 3: string, 4: string}>
 */
function manifestCallers(): array
{
    $pullRequest = '{"kind":"pr","url":"u","title":"t","body":"b","comments":[],"reviewComments":[],"reviews":[],"reviewCommentsComplete":true}';
    $diff = "diff --git a/src/a.php b/src/a.php\n--- a/src/a.php\n+++ b/src/a.php\n@@ -1 +1 @@\n-a\n+b\n";

    return [
        'analyze-recurrence' => ['skills/_shared/analyze-recurrence.sh', '--store /dev/null', '', 'stderr', 'read-manifest.sh'],
        'classify-risk' => ['skills/_shared/classify-risk.sh', '--merge-risk --diff -', $diff, 'stdout', 'project-paths=unavailable'],
        'discover-fixers' => ['skills/_shared/discover-fixers.sh', '', '', 'stderr', 'discover-fixers.sh: composer.json on'],
        'history-scan text' => [
            'skills/git-history-exposure-audit/scripts/history-scan.sh',
            'text',
            $pullRequest,
            'stdout',
            'not-verified: default-branch manifest',
        ],
        'plan-parallel' => ['skills/_shared/plan-parallel.sh', '', "a|STANDARD||f\n", 'stderr', 'read-manifest.sh'],
        'resolve-interactive-testing' => ['skills/_shared/resolve-interactive-testing.sh', '', '', 'stderr', 'read-manifest.sh'],
        'resolve-voice' => ['skills/agent-voice/scripts/resolve-voice.sh', '', '', 'stderr', 'read-manifest.sh'],
        'run-fast-checks' => ['skills/_shared/run-fast-checks.sh', '', '', 'stdout', 'cannot be read as a manifest'],
        'run-validation (project-commands)' => [
            'skills/_shared/run-validation.sh',
            '--manifest - --dry-run',
            '{"tests":["vendor/bin/pest tests/x.php"],"head_sha":"%HEAD%"}',
            'stderr',
            'read-manifest.sh',
        ],
    ];
}

test(
    'every caller that carries on after a manifest it cannot read says so',
    function (string $script, string $arguments, string $input, string $stream, string $needle, string $state): void {
        $dir = repositoryWithManifest($state);
        $process = runInRepository($dir, $script, $arguments, $input);
        $report = $stream === 'stderr' ? $process->getErrorOutput() : $process->getOutput();
    
        expect($report)->toContain($needle);
    },
)->with(function (): Generator {
    foreach (manifestCallers() as $name => $caller) {
        foreach (['unreadable', 'invalid'] as $state) {
            yield $name . ' / ' . $state => [...$caller, $state];
        }
    }
});

test(
    'no caller reports a project with no composer.json',
    function (string $script, string $arguments, string $input, string $stream, string $needle): void {
        $dir = repositoryWithManifest('absent');
        $process = runInRepository($dir, $script, $arguments, $input);
        $report = $stream === 'stderr' ? $process->getErrorOutput() : $process->getOutput();
    
        expect($report)->not->toContain($needle);
    },
)->with(manifestCallers());

test('the fixer discovery and the history scan stop with exit 3 on a composer.json git cannot read', function (): void {
    $discovery = runInRepository(repositoryWithManifest('unreadable'), 'skills/_shared/discover-fixers.sh');
    $scan = runInRepository(
        repositoryWithManifest('unreadable'),
        'skills/git-history-exposure-audit/scripts/history-scan.sh',
        'text',
        manifestCallers()['history-scan text'][2],
    );

    expect($discovery->getExitCode())->toBe(3)
        ->and($discovery->getOutput())->toBe('')
        ->and($scan->getExitCode())->toBe(3);
});
