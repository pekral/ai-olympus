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

test('the link builder refuses an address it cannot prove', function (array $arguments, string $error): void {
    $process = runPrCodeLink(...$arguments);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())->toContain($error);
})->with([
    'a line that is no number' => [['https://github.com/acme/app/pull/12', 'src/A.php', '4a'], 'not a line number: 4a'],
    'an absolute path' => [['https://github.com/acme/app/pull/12', '/etc/passwd', '1'], 'not a relative repository path'],
    'another host' => [['https://example.com/acme/app/pull/12'], 'not a github.com pull-request URL'],
    'a path that climbs out' => [['https://github.com/acme/app/pull/12', 'src/../../secret', '1'], 'not a relative repository path'],
    'a path with a line break' => [['https://github.com/acme/app/pull/12', "src/A.php\n[x|https://example.com]", '1'], 'not a relative repository path'],
    'a path without a line' => [['https://github.com/acme/app/pull/12', 'src/A.php'], 'expected a pull-request URL'],
    'a trailing path after the number' => [['https://github.com/acme/app/pull/12/files'], 'not a github.com pull-request URL'],
    'a URL that is no pull request' => [['https://github.com/acme/app/issues/12'], 'not a github.com pull-request URL'],
    'line zero' => [['https://github.com/acme/app/pull/12', 'src/A.php', '0'], 'not a line number: 0'],
    'no argument' => [[], 'expected a pull-request URL'],
]);
