<?php

declare(strict_types = 1);

use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * @param array<string, string> $files
 * @return array{exit: int|null, output: string, error: string}
 */
function runCheckCommentProse(array $files, string $diff, bool $withDiff = true, ?string $diffFile = null, bool $writeDiffFile = true): array
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

    if ($diffFile !== null && $writeDiffFile) {
        file_put_contents($root . '/' . $diffFile, $diff);
    }

    if ($withDiff) {
        $arguments[] = '--diff';
        $arguments[] = $diffFile === null ? '-' : $root . '/' . $diffFile;
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

test('a non-PHP file in the diff is not read', function (): void {
    $result = runCheckCommentProse(['readme.md' => "// prose\n"], proseTestDiff('readme.md', 1, 1));

    expect($result['exit'])->toBe(0)
        ->and($result['output'])->toBe('{"valid":true,"findings":[]}');
});

test('a path that leaves the root is refused', function (): void {
    $result = runCheckCommentProse([], proseTestDiff('../outside.php', 1, 1));

    expect($result['exit'])->toBe(1)
        ->and($result['output'])->toBe('')
        ->and($result['error'])->toBe("check-comment-prose.php: the diff names a path outside the root\n");
});

test('an absolute path in the diff is refused', function (): void {
    $result = runCheckCommentProse([], "--- /dev/null\n+++ /etc/outside.php\n@@ -0,0 +1,1 @@\n+line\n");

    expect($result['exit'])->toBe(1)
        ->and($result['output'])->toBe('')
        ->and($result['error'])->toBe("check-comment-prose.php: the diff names a path outside the root\n");
});

test('a git-quoted non-ASCII path is unescaped to the file it names', function (): void {
    $source = "<?php\n\n// prose in a file with an accented name\nfunction accented(): void\n{\n}\n";
    $diff = "--- /dev/null\n+++ \"b/app/caf\\303\\251.php\"\n@@ -0,0 +3,1 @@\n+line\n";

    $result = runCheckCommentProse(["app/caf\u{e9}.php" => $source], $diff);

    expect($result['exit'])->toBe(3)
        ->and($result['output'])->toBe('{"valid":false,"findings":[{"file":"app/caf\\u00e9.php","line":3}]}');
});

test('a PHP path the root does not hold fails closed instead of passing', function (): void {
    $result = runCheckCommentProse([], "--- app/Missing.php\n+++ app/Missing.php\n@@ -0,0 +1,1 @@\n+line\n");

    expect($result['exit'])->toBe(1)
        ->and($result['error'])->toBe("check-comment-prose.php: the diff names a PHP file that is not under the root\n");
});

test('a diff without the b/ prefix and with context lines maps each added line to its file line', function (): void {
    $source = "<?php\n\nfunction kept(): int\n{\n    // keep the total\n    return 1;\n}\n";
    $diff = "--- app/kept.php\n+++ app/kept.php\n@@ -3,3 +3,4 @@\n function kept(): int\n {\n+    // keep the total\n     return 1;\n";

    $result = runCheckCommentProse(['app/kept.php' => $source], $diff);

    expect($result['exit'])->toBe(3)
        ->and($result['output'])->toBe('{"valid":false,"findings":[{"file":"app/kept.php","line":5}]}');
});

test('a diff read from a file is checked like one read from standard input', function (): void {
    $source = "<?php\n\n/* a block comment with prose */\nfunction block(): void\n{\n}\n";

    $result = runCheckCommentProse(['app/block.php' => $source], proseTestDiff('app/block.php', 3, 3), diffFile: 'change.diff');

    expect($result['exit'])->toBe(3)
        ->and($result['output'])->toBe('{"valid":false,"findings":[{"file":"app/block.php","line":3}]}');
});

test('a diff file that cannot be read is an input error', function (): void {
    $result = runCheckCommentProse([], '', diffFile: 'absent.diff', writeDiffFile: false);

    expect($result['exit'])->toBe(1)
        ->and($result['error'])->toBe("check-comment-prose.php: cannot read the diff\n");
});

test('a hash comment with prose is reported', function (): void {
    $source = "<?php\n\n# legacy hash note\nfunction hashed(): void\n{\n}\n";

    $result = runCheckCommentProse(['app/hashed.php' => $source], proseTestDiff('app/hashed.php', 3, 3));

    expect($result['exit'])->toBe(3)
        ->and($result['output'])->toBe('{"valid":false,"findings":[{"file":"app/hashed.php","line":3}]}');
});

test('a run of line comments is one block and one finding', function (): void {
    $source = "<?php\n\nfunction run(): int\n{\n    // first line\n    // second line\n    // third line\n    return 1;\n}\n";

    $result = runCheckCommentProse(['app/run.php' => $source], proseTestDiff('app/run.php', 5, 7));

    expect($result['exit'])->toBe(3)
        ->and($result['output'])->toBe('{"valid":false,"findings":[{"file":"app/run.php","line":5}]}');
});

test('a sentence after an annotation with an unclosed comparison is still prose', function (): void {
    $source = "<?php\n\n/**\n * @param int \$limit must be < 100\n * The limit protects the queue.\n */\nfunction limited(int \$limit): void\n{\n}\n";

    $result = runCheckCommentProse(['app/limited.php' => $source], proseTestDiff('app/limited.php', 3, 6));

    expect($result['exit'])->toBe(3)
        ->and($result['output'])->toBe('{"valid":false,"findings":[{"file":"app/limited.php","line":5}]}');
});

test('a tag that static analysis does not read is prose', function (): void {
    $source = "<?php\n\n/**\n * @todo rewrite this\n */\nfunction pending(): void\n{\n}\n";

    $result = runCheckCommentProse(['app/pending.php' => $source], proseTestDiff('app/pending.php', 3, 5));

    expect($result['exit'])->toBe(3)
        ->and($result['output'])->toBe('{"valid":false,"findings":[{"file":"app/pending.php","line":4}]}');
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

    expect(
        substr_count(
            $standards,
            '`git diff -U0 --diff-filter=AMR "$(git merge-base "origin/$DEFAULT_BRANCH" HEAD)" -- \'*.php\''
            . ' | php skills/_shared/check-comment-prose.php --diff -`',
        ),
    )->toBe(
        1,
    )
        ->and(substr_count($review, '`git diff -U0 --diff-filter=AMR <base>...HEAD -- \'*.php\'`'))->toBe(1)
        ->and(substr_count($review, 'Detect it with `skills/_shared/check-comment-prose.php --diff -`'))->toBe(1)
        ->and(is_executable($packageDir . '/skills/_shared/check-comment-prose.php'))->toBeTrue();
});
