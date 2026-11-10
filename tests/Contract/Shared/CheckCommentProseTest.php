<?php

declare(strict_types = 1);

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * @param array<string, string> $files
 * @return array{exit: int|null, output: string, error: string}
 */
function runCheckCommentProse(array $files, string $diff, bool $withDiff = true): array
{
    $packageDir = dirname(__DIR__, 3);
    $root = sys_get_temp_dir() . '/ai-olympus-prose-' . bin2hex(random_bytes(6));
    mkdir($root, 0o700, recursive: true);

    foreach ($files as $path => $content) {
        $directory = dirname($root . '/' . $path);

        if (!is_dir($directory)) {
            mkdir($directory, 0o700, recursive: true);
        }

        file_put_contents($root . '/' . $path, $content);
    }

    $arguments = ['php', $packageDir . '/skills/_shared/check-comment-prose.php'];

    if ($withDiff) {
        $arguments[] = '--diff';
        $arguments[] = '-';
        $arguments[] = '--root';
        $arguments[] = $root;
    }

    $process = new Process($arguments, $packageDir);
    $process->setInput($diff);
    $process->run();

    new Filesystem()->remove($root);

    return ['exit' => $process->getExitCode(), 'output' => trim($process->getOutput()), 'error' => $process->getErrorOutput()];
}

function proseTestDiff(string $file, int $firstLine, int $lastLine): string
{
    $added = implode("\n", array_fill(0, $lastLine - $firstLine + 1, '+line'));

    return "--- a/{$file}\n+++ b/{$file}\n@@ -0,0 +{$firstLine},{$lastLine} @@\n{$added}\n";
}

test('a docblock with a sentence on an added line is reported at its first prose line', function (): void {
    $source = "<?php\n\nfinal class Preview\n{\n    /**\n     * The cache holds the preview as an array.\n     */\n    private array \$preview = [];\n}\n";

    $result = runCheckCommentProse(['app/Preview.php' => $source], proseTestDiff('app/Preview.php', 5, 8));

    expect($result['exit'])->toBe(3)
        ->and($result['output'])->toBe('{"valid":false,"findings":[{"file":"app/Preview.php","line":6}]}');
});

test('annotations for static analysis pass, including a multi-line array shape and a tool directive', function (): void {
    $source = implode("\n", [
        '<?php',
        '',
        'final class Shape',
        '{',
        '    /**',
        '     * @return array{',
        '     *     id: int,',
        '     *     name: string,',
        '     * }',
        '     */',
        '    public function shape(): array',
        '    {',
        '        // @phpstan-ignore-line',
        '        return [\'id\' => 1, \'name\' => \'x\'];',
        '    }',
        '',
        '    /** @var list<int> */',
        '    private array $ids = [];',
        '}',
        '',
    ]);

    $result = runCheckCommentProse(['app/Shape.php' => $source], proseTestDiff('app/Shape.php', 5, 18));

    expect($result['exit'])->toBe(0)
        ->and($result['output'])->toBe('{"valid":true,"findings":[]}');
});

test('an inline prose comment on an added line is reported', function (): void {
    $source = "<?php\n\nfunction total(): int\n{\n    // add the tax\n    return 1;\n}\n";

    $result = runCheckCommentProse(['app/total.php' => $source], proseTestDiff('app/total.php', 5, 6));

    expect($result['exit'])->toBe(3)
        ->and($result['output'])->toBe('{"valid":false,"findings":[{"file":"app/total.php","line":5}]}');
});

test('a prose comment the diff does not touch is not reported', function (): void {
    $source = "<?php\n\n/** Old prose nobody changed. */\nfunction untouched(): int\n{\n    return 1;\n}\n";

    $result = runCheckCommentProse(['app/untouched.php' => $source], proseTestDiff('app/untouched.php', 6, 6));

    expect($result['exit'])->toBe(0)
        ->and($result['output'])->toBe('{"valid":true,"findings":[]}');
});

test('a non-PHP file and a path that leaves the root are not read', function (): void {
    $diff = proseTestDiff('readme.md', 1, 1) . proseTestDiff('../outside.php', 1, 1);

    $result = runCheckCommentProse(['readme.md' => "// prose\n"], $diff);

    expect($result['exit'])->toBe(0)
        ->and($result['output'])->toBe('{"valid":true,"findings":[]}');
});

test('a run without a diff is a usage error', function (): void {
    $result = runCheckCommentProse([], '', withDiff: false);

    expect($result['exit'])->toBe(1)
        ->and($result['output'])->toBe('')
        ->and($result['error'])->toContain('Usage: check-comment-prose.php --diff <diff-file|-> [--root <dir>]');
});

test('the PHP standards and the code review run the prose check', function (): void {
    $packageDir = dirname(__DIR__, 3);
    $standards = (string) file_get_contents($packageDir . '/rules/php/core-standards.md');
    $review = (string) file_get_contents($packageDir . '/rules/code-review/core-analysis.md');

    expect(substr_count($standards, '| php skills/_shared/check-comment-prose.php --diff -`'))->toBe(1)
        ->and(substr_count($review, 'Detect it with `skills/_shared/check-comment-prose.php --diff -`'))->toBe(1)
        ->and(is_executable($packageDir . '/skills/_shared/check-comment-prose.php'))->toBeTrue();
});
