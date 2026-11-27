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

test('the link to a finding outside the pull request opens its file and line at the reviewed commit of the task repository', function (): void {
    $revision = str_repeat('0123456789', 4);

    $process = runPrCodeLink('https://github.com/acme/app/pull/12', 'src/Sync/Order Sync.php', '42', $revision);

    expect($process->getOutput())->toBe('https://github.com/acme/app/blob/' . $revision . "/src/Sync/Order%20Sync.php#L42\n")
        ->and($process->getExitCode())->toBe(0);
});

test('the link builder refuses an address it cannot prove', function (string $error, string ...$arguments): void {
    $process = runPrCodeLink(...$arguments);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toBe('')
        ->and($process->getErrorOutput())->toContain($error);
})->with([
    'a line that is no number' => ['not a line number: 4a', 'https://github.com/acme/app/pull/12', 'src/A.php', '4a'],
    'a line with a trailing line break' => ['not a line number', 'https://github.com/acme/app/pull/12', 'src/A.php', "4\n"],
    'an absolute path' => ['not a relative repository path', 'https://github.com/acme/app/pull/12', '/etc/passwd', '1'],
    'another host' => ['not a github.com pull-request URL', 'https://example.com/acme/app/pull/12'],
    'a path that climbs out' => ['not a relative repository path', 'https://github.com/acme/app/pull/12', 'src/../../secret', '1'],
    'a path that climbs out at a revision' => ['not a relative repository path', 'https://github.com/acme/app/pull/12', 'src/../A.php', '1', str_repeat(
        'a',
        40,
    ),
    ],
    'a path with a dot segment' => ['not a relative repository path', 'https://github.com/acme/app/pull/12', './src/A.php', '1'],
    'a path with a dot segment at a revision' => ['not a relative repository path', 'https://github.com/acme/app/pull/12', './A.php', '1', str_repeat(
        'a',
        40,
    ),
    ],
    'a path with a line break' => ['not a relative repository path', 'https://github.com/acme/app/pull/12', "src/A.php\n[x|https://example.com]", '1'],
    'a path with an inner dot segment' => ['not a relative repository path', 'https://github.com/acme/app/pull/12', 'src/./A.php', '1'],
    'a path without a line' => ['expected a pull-request URL', 'https://github.com/acme/app/pull/12', 'src/A.php'],
    'a repository named by a dot segment' => ['not a github.com pull-request URL', 'https://github.com/acme/../pull/12'],
    'a revision that is no full commit hash' => ['not a full commit hash: abc1234', 'https://github.com/acme/app/pull/12', 'src/A.php', '1', 'abc1234'],
    'a revision with a trailing line break' => ['not a full commit hash', 'https://github.com/acme/app/pull/12', 'src/A.php', '1', str_repeat(
        'a',
        40,
    ) . "\n",
    ],
    'a trailing path after the number' => ['not a github.com pull-request URL', 'https://github.com/acme/app/pull/12/files'],
    'a URL that is no pull request' => ['not a github.com pull-request URL', 'https://github.com/acme/app/issues/12'],
    'a URL with a trailing line break' => ['not a github.com pull-request URL', "https://github.com/acme/app/pull/12\n"],
    'line zero' => ['not a line number: 0', 'https://github.com/acme/app/pull/12', 'src/A.php', '0'],
    'no argument' => ['expected a pull-request URL'],
    'too many arguments' => ['expected a pull-request URL', 'https://github.com/acme/app/pull/12', 'src/A.php', '1', str_repeat('a', 40), 'x'],
]);
