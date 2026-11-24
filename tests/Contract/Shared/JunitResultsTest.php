<?php

declare(strict_types = 1);

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * @param array<int|string, string> $arguments
 * @return array{exit: int|null, lines: list<string>, error: string}
 */
function runJunitResults(array $arguments): array
{
    $packageDir = dirname(__DIR__, 3);
    $process = new Process(['php', $packageDir . '/skills/_shared/junit-results.php', ...array_values($arguments)], $packageDir);
    $process->run();
    $output = rtrim($process->getOutput(), "\n");

    return ['exit' => $process->getExitCode(), 'lines' => $output === '' ? [] : explode("\n", $output), 'error' => $process->getErrorOutput()];
}

function junitReportFile(string $xml): string
{
    $directory = sys_get_temp_dir() . '/ai-olympus-junit-' . bin2hex(random_bytes(6));
    mkdir($directory, 0o700, recursive: true);
    file_put_contents($directory . '/report.xml', $xml);

    return $directory . '/report.xml';
}

test('a Pest report gives one line per test with its status, file, name, and message', function (): void {
    $report = junitReportFile(<<<'XML'
        <?xml version="1.0" encoding="UTF-8"?>
        <testsuites>
          <testsuite name="Tests\Unit\CalcTest" file="tests/Unit/CalcTest.php" tests="4">
            <testcase name="it adds" file="tests/Unit/CalcTest.php::it adds" class="Tests\Unit\CalcTest"/>
            <testcase name="subtracts" file="tests/Unit/CalcTest.php::subtracts" class="Tests\Unit\CalcTest">
              <failure type="PHPUnit\Framework\ExpectationFailedException">subtractsFailed asserting that 1 is identical to 2.
        at tests/Unit/CalcTest.php:9</failure>
            </testcase>
            <testcase name="it divides" file="tests/Unit/CalcTest.php::it divides" class="Tests\Unit\CalcTest">
              <error type="DivisionByZeroError">it dividesDivisionByZeroError: Division by zero
        second line
        third line
        fourth line</error>
            </testcase>
            <testcase name="it rounds" file="tests/Unit/CalcTest.php::it rounds" class="Tests\Unit\CalcTest"><skipped/></testcase>
          </testsuite>
        </testsuites>
        XML);

    $result = runJunitResults([$report]);

    (new Filesystem())->remove(dirname($report));

    expect($result['exit'])->toBe(0)
        ->and($result['lines'])->toBe([
            "passed\ttests/Unit/CalcTest.php\tit adds\t",
            "failed\ttests/Unit/CalcTest.php\tsubtracts\tsubtractsFailed asserting that 1 is identical to 2. | at tests/Unit/CalcTest.php:9",
            "errored\ttests/Unit/CalcTest.php\tit divides\tit dividesDivisionByZeroError: Division by zero | second line | third line",
            "skipped\ttests/Unit/CalcTest.php\tit rounds\t",
        ]);
});

test('an absolute PHPUnit file path becomes relative to the root', function (): void {
    $report = junitReportFile(
        '<testsuites><testcase name="testAdds" file="/work/app/tests/CalcTest.php" class="CalcTest"/></testsuites>',
    );

    $result = runJunitResults(['--root', '/work/app', $report]);

    (new Filesystem())->remove(dirname($report));

    expect($result['lines'])->toBe(["passed\ttests/CalcTest.php\ttestAdds\t"]);
});

test('control characters and tabs never reach a field, and a long message is cut', function (): void {
    $message = "\x7fred&#9;tab " . str_repeat('x', 400);
    $report = junitReportFile(
        '<testsuites><testcase name="it&#9;splits" file="tests/ATest.php"><failure>' . $message . '</failure></testcase></testsuites>',
    );

    $result = runJunitResults([$report]);
    [$status, $file, $name, $text] = explode("\t", $result['lines'][0]);

    (new Filesystem())->remove(dirname($report));

    expect($result['lines'])->toHaveCount(1)
        ->and($status)->toBe('failed')
        ->and($file)->toBe('tests/ATest.php')
        ->and($name)->toBe('it splits')
        ->and($text)->not->toContain("\x7f")
        ->and($text)->not->toContain("\t")
        ->and(mb_strlen($text))->toBe(300);
});

test('a report with a document type declaration is refused, so no entity is expanded', function (): void {
    $report = junitReportFile(
        '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/hosts">]>'
        . '<testsuites><testcase name="&e;" file="tests/ATest.php"/></testsuites>',
    );

    $result = runJunitResults([$report]);

    (new Filesystem())->remove(dirname($report));

    expect($result['exit'])->toBe(2)
        ->and($result['lines'])->toBe([])
        ->and($result['error'])->toContain('document type declaration');
});

test('a missing, empty, or malformed report exits 2 and prints no result', function (string $xml): void {
    $report = junitReportFile($xml);

    $result = runJunitResults([$report]);

    (new Filesystem())->remove(dirname($report));

    expect($result['exit'])->toBe(2)
        ->and($result['lines'])->toBe([]);
})->with([
    'empty' => [''],
    'malformed' => ['<testsuites><testcase name="it adds"'],
]);

test('a report that does not exist exits 2', function (): void {
    expect(runJunitResults(['/nonexistent/report.xml'])['exit'])->toBe(2);
});

test('a call without exactly one report is a usage error', function (string ...$arguments): void {
    expect(runJunitResults($arguments)['exit'])->toBe(1);
})->with([
    'an option as the report' => ['--log'],
    'no report' => [],
    'two reports' => ['a.xml', 'b.xml'],
]);

test('a real Pest run names each test as the report reader reads it', function (): void {
    $packageDir = dirname(__DIR__, 3);
    $report = sys_get_temp_dir() . '/ai-olympus-junit-' . bin2hex(random_bytes(6)) . '.xml';
    $pest = new Process(['vendor/bin/pest', 'tests/Fixtures/junit-probe/RegressionProbe.php', '--log-junit', $report], $packageDir);
    $pest->run();

    $result = runJunitResults(['--root', $packageDir, $report]);

    (new Filesystem())->remove($report);

    expect($pest->getExitCode())->toBe(1)
        ->and(array_map(static fn (string $line): string => implode("\t", array_slice(explode("\t", $line), 0, 3)), $result['lines']))->toBe([
            "failed\ttests/Fixtures/junit-probe/RegressionProbe.php\tit is red",
            "passed\ttests/Fixtures/junit-probe/RegressionProbe.php\tstays green",
        ]);
});
