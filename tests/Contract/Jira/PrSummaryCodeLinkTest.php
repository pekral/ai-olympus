<?php

declare(strict_types = 1);

use Symfony\Component\Process\Process;

function runPrCodeLink(string ...$arguments): Process
{
    $packageDir = dirname(__DIR__, 3);
    $process = new Process(['php', $packageDir . '/skills/pr-summary/scripts/pr-code-link.php', ...$arguments], $packageDir);
    $process->run();

    return $process;
}

test('the link to the whole change of a pull request opens its changes', function (): void {
    $process = runPrCodeLink('https://github.com/acme/app/pull/12');

    expect($process->getOutput())->toBe("https://github.com/acme/app/pull/12/files\n")
        ->and($process->getExitCode())->toBe(0);
});

test('the link to a finding opens its file and line in the changes of the pull request', function (): void {
    $process = runPrCodeLink('https://github.com/acme/app/pull/12', 'src/Sync/OrderSync.php', '42');

    expect($process->getOutput())->toBe('https://github.com/acme/app/pull/12/files#diff-' . hash('sha256', 'src/Sync/OrderSync.php') . "R42\n")
        ->and($process->getExitCode())->toBe(0);
});

test('the link builder refuses an address it cannot prove', function (string $error, string ...$arguments): void {
    $process = runPrCodeLink(...$arguments);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())->toContain($error);
})->with([
    'a line that is no number' => ['not a line number: 4a', 'https://github.com/acme/app/pull/12', 'src/A.php', '4a'],
    'an absolute path' => ['not a relative repository path', 'https://github.com/acme/app/pull/12', '/etc/passwd', '1'],
    'another host' => ['not a github.com pull-request URL', 'https://example.com/acme/app/pull/12'],
    'a path that climbs out' => ['not a relative repository path', 'https://github.com/acme/app/pull/12', 'src/../../secret', '1'],
    'a path with a line break' => ['not a relative repository path', 'https://github.com/acme/app/pull/12', "src/A.php\n[x|https://example.com]", '1'],
    'a path without a line' => ['expected a pull-request URL', 'https://github.com/acme/app/pull/12', 'src/A.php'],
    'a trailing path after the number' => ['not a github.com pull-request URL', 'https://github.com/acme/app/pull/12/files'],
    'a URL that is no pull request' => ['not a github.com pull-request URL', 'https://github.com/acme/app/issues/12'],
    'line zero' => ['not a line number: 0', 'https://github.com/acme/app/pull/12', 'src/A.php', '0'],
    'no argument' => ['expected a pull-request URL'],
    'a URL with a trailing line break' => ['not a github.com pull-request URL', "https://github.com/acme/app/pull/12\n"],
    'a line with a trailing line break' => ['not a line number', 'https://github.com/acme/app/pull/12', 'src/A.php', "4\n"],
    'a path with a dot segment' => ['not a relative repository path', 'https://github.com/acme/app/pull/12', './src/A.php', '1'],
    'a path with an inner dot segment' => ['not a relative repository path', 'https://github.com/acme/app/pull/12', 'src/./A.php', '1'],
    'a repository named by a dot segment' => ['not a github.com pull-request URL', 'https://github.com/acme/../pull/12'],
]);
