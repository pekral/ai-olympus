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
 * Builds a repository whose default branch carries a composer.json, then removes that file's blob so that git
 * cannot read it, or builds one without a composer.json when $withComposer is false.
 */
function repositoryWithManifest(bool $withComposer, bool $unreadable): string
{
    $dir = sys_get_temp_dir() . '/ai-olympus-manifest-' . bin2hex(random_bytes(6));
    mkdir($dir);
    register_shutdown_function(static fn (): int => (new Process(['rm', '-rf', $dir]))->run());
    manifestRepositoryGit($dir, 'init', '-q');
    file_put_contents($dir . '/README.md', "readme\n");
    manifestRepositoryGit($dir, 'add', 'README.md');

    if ($withComposer) {
        file_put_contents($dir . '/composer.json', '{"extra":{"ai-olympus":{"voice":"technical"}}}' . "\n");
        manifestRepositoryGit($dir, 'add', 'composer.json');
    }

    manifestRepositoryGit($dir, 'commit', '-q', '-m', 'manifest');
    manifestRepositoryGit($dir, 'update-ref', 'refs/remotes/origin/master', 'HEAD');
    manifestRepositoryGit($dir, 'symbolic-ref', 'refs/remotes/origin/HEAD', 'refs/remotes/origin/master');

    if ($unreadable) {
        $blob = manifestRepositoryGit($dir, 'rev-parse', 'HEAD:composer.json');
        unlink($dir . '/.git/objects/' . substr($blob, 0, 2) . '/' . substr($blob, 2));
    }

    return $dir;
}

function runInRepository(string $dir, string $script): Process
{
    $process = new Process(['bash', dirname(__DIR__, 3) . '/' . $script], $dir);
    $process->run();

    return $process;
}

test('a composer.json that git cannot read is reported by the reader and by a caller that falls back to the default', function (): void {
    $dir = repositoryWithManifest(withComposer: true, unreadable: true);
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
    $dir = repositoryWithManifest(withComposer: false, unreadable: false);
    $reader = runInRepository($dir, 'skills/_shared/read-manifest.sh');
    $voice = runInRepository($dir, 'skills/agent-voice/scripts/resolve-voice.sh');

    expect($reader->getExitCode())->toBe(0)
        ->and($reader->getOutput())->toBe("{}\n")
        ->and($reader->getErrorOutput())->toBe('')
        ->and(trim($voice->getOutput()))->toBe('relaxed')
        ->and($voice->getErrorOutput())->toBe('');
});
