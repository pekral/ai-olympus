<?php

declare(strict_types = 1);

use Symfony\Component\Process\Process;

/**
 * @param array<string, string> $scripts file name => script body, run in this order
 * @param string|null $shard the SHELL_SELF_TESTS_SHARD value, or null to leave it unset
 */
function runShellSelfTestsRunner(array $scripts, ?string $shard = null): Process
{
    $packageDir = dirname(__DIR__, 3);
    $dir = sys_get_temp_dir() . '/ai-olympus-self-tests-' . bin2hex(random_bytes(6));
    mkdir($dir);
    $paths = [];

    foreach ($scripts as $name => $body) {
        $paths[] = $dir . '/' . $name;
        file_put_contents($dir . '/' . $name, $body);
    }

    $process = new Process(
        ['bash', $packageDir . '/scripts/run-shell-self-tests.sh', ...$paths],
        $packageDir,
        ['SHELL_SELF_TESTS_SHARD' => $shard ?? false],
    );

    try {
        $process->run();
    } finally {
        array_map(unlink(...), $paths);
        rmdir($dir);
    }

    return $process;
}

test('the runner passes when every self-test passes and keeps the input order', function (): void {
    $process = runShellSelfTestsRunner([
        'slow.sh' => "sleep 1\necho 'ok    first'\n",
        'fast.sh' => "echo 'ok    second'\n",
    ]);

    $output = $process->getOutput();

    expect($process->getExitCode())->toBe(0)
        ->and($output)->toContain('2 scripts, 2 passed, 0 failed, 2 checks')
        ->and(strpos($output, 'slow.sh'))->toBeLessThan((int) strpos($output, 'fast.sh'));
});

test('the runner fails and prints the output of a self-test that exits non-zero', function (): void {
    $process = runShellSelfTestsRunner([
        'pass.sh' => "echo 'ok    a'\n",
        'fail.sh' => "echo 'boom'\nexit 3\n",
    ]);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toMatch('/FAIL\s+\S*fail\.sh.*exit 3/')
        ->and($process->getOutput())->toContain('│ boom');
});

test('the runner fails when a job is killed before it records its result', function (string $first, string $second): void {
    $scripts = [
        'pass.sh' => "echo 'ok    a'\n",
        'killer.sh' => "kill -9 \$PPID\n",
    ];
    $process = runShellSelfTestsRunner([$first => $scripts[$first], $second => $scripts[$second]]);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toMatch('/FAIL\s+\S*killer\.sh.*exit not run/')
        ->and($process->getOutput())->toContain('1 failed');
})->with([
    'killed job first' => ['killer.sh', 'pass.sh'],
    'killed job last' => ['pass.sh', 'killer.sh'],
]);

test('the shards of a list run every script exactly once and keep the input order', function (): void {
    $scripts = [
        'one.sh' => "echo 'ok    one'\n",
        'two.sh' => "echo 'ok    two'\n",
        'three.sh' => "echo 'ok    three'\n",
    ];

    $first = runShellSelfTestsRunner($scripts, '1/2');
    $second = runShellSelfTestsRunner($scripts, '2/2');

    expect($first->getExitCode())->toBe(0)
        ->and($first->getOutput())->toContain('shard 1/2: 2 of 3 scripts')
        ->and($first->getOutput())->toContain('2 scripts, 2 passed, 0 failed, 2 checks')
        ->and($first->getOutput())->toMatch('/one\.sh.*\n.*three\.sh/')
        ->and($first->getOutput())->not->toContain('two.sh')
        ->and($second->getExitCode())->toBe(0)
        ->and($second->getOutput())->toContain('shard 2/2: 1 of 3 scripts')
        ->and($second->getOutput())->toContain('two.sh')
        ->and($second->getOutput())->not->toContain('one.sh')
        ->and($second->getOutput())->not->toContain('three.sh');
});

test('the runner refuses a shard value it cannot read and a shard that holds no script', function (string $shard, string $message): void {
    $process = runShellSelfTestsRunner(['pass.sh' => "echo 'ok    a'\n"], $shard);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->not->toContain('PASS')
        ->and($process->getErrorOutput())->toContain($message);
})->with([
    'empty shard' => ['2/2', 'shard 2/2 holds no script of the 1 given'],
    'not a fraction' => ['first', 'must be <k>/<n> with 1 <= k <= n'],
    'shard above the count' => ['3/2', 'must be <k>/<n> with 1 <= k <= n'],
    'shard zero' => ['0/2', 'must be <k>/<n> with 1 <= k <= n'],
]);
