<?php

declare(strict_types = 1);

use Symfony\Component\Process\Process;

/**
 * @param list<string>|null $repoPaths
 */
function runCheckVisibleReviewText(string $text, ?array $repoPaths = null, ?string $diffText = null): Process
{
    $packageDir = dirname(__DIR__, 3);
    $arguments = ['php', $packageDir . '/skills/_shared/check-visible-review-text.php', '--text', '-'];
    $pathsFile = null;

    if ($repoPaths !== null) {
        $pathsFile = (string) tempnam(sys_get_temp_dir(), 'ai-olympus-repo-paths-');
        file_put_contents($pathsFile, implode("\n", $repoPaths) . "\n");
        $arguments[] = '--repo-paths';
        $arguments[] = $pathsFile;
    }

    $diffFile = null;

    if ($diffText !== null) {
        $diffFile = (string) tempnam(sys_get_temp_dir(), 'ai-olympus-diff-text-');
        file_put_contents($diffFile, $diffText);
        $arguments[] = '--diff-text';
        $arguments[] = $diffFile;
    }

    $process = new Process($arguments, $packageDir);
    $process->setInput($text);
    $process->run();

    if ($pathsFile !== null) {
        unlink($pathsFile);
    }

    if ($diffFile !== null) {
        unlink($diffFile);
    }

    return $process;
}

test('a visible text that names a shared script of the package by its path is refused', function (): void {
    $process = runCheckVisibleReviewText(
        'Coverage ran through `skills/_shared/run-gate.sh --exclusive --command "vendor/bin/pest"`.',
        ['src/A.php'],
    );

    expect($process->getExitCode())->toBe(3)
        ->and(trim($process->getOutput()))->toBe('{"valid":false,"names":["skills/_shared/run-gate.sh"]}');
});

test('a package path written with HTML entities is refused as the name a reader sees', function (): void {
    $process = runCheckVisibleReviewText('Coverage ran through <code>skills/&#95;shared/run-gate.sh</code>.', ['src/A.php']);

    expect($process->getExitCode())->toBe(3)
        ->and(trim($process->getOutput()))->toBe('{"valid":false,"names":["skills/_shared/run-gate.sh"]}');
});

test('the installed package directories of Claude Code and Codex are refused', function (string $name): void {
    $process = runCheckVisibleReviewText("The step reads {$name}.", ['src/A.php']);

    expect($process->getExitCode())->toBe(3)
        ->and(trim($process->getOutput()))->toBe('{"valid":false,"names":["' . $name . '"]}');
})->with([
    'agents skill' => ['.agents/skills/code-review/SKILL.md'],
    'Claude Code rule' => ['.claude/rules/code-review/general.md'],
    'Claude Code skill' => ['.claude/skills/code-review/SKILL.md'],
    'Codex skill' => ['.codex/skills/code-review/SKILL.md'],
    'run directory' => ['.claude/run/gates/abc.json'],
]);

test('a package rule, skill, or agent reference is refused without its @ prefix in the output', function (string $text, string $name): void {
    $process = runCheckVisibleReviewText($text, ['src/A.php']);

    expect($process->getExitCode())->toBe(3)
        ->and(trim($process->getOutput()))->toBe('{"valid":false,"names":["' . $name . '"]}');
})->with([
    'agent definition' => ['As agents/leonardo.md states.', 'agents/leonardo.md'],
    'Codex agent' => ['As codex/agents/leonardo.toml states.', 'codex/agents/leonardo.toml'],
    'rule reference' => ['See `@rules/security/backend.md` for the rule.', 'rules/security/backend.md'],
    'skill reference' => ['The `@skills/code-review/SKILL.md` walk ran.', 'skills/code-review/SKILL.md'],
]);

test('a bare script name of the package is refused, also inside another directory', function (string $text, string $name): void {
    $process = runCheckVisibleReviewText($text, ['src/A.php']);

    expect($process->getExitCode())->toBe(3)
        ->and(trim($process->getOutput()))->toBe('{"valid":false,"names":["' . $name . '"]}');
})->with([
    'shared script' => ['The merge risk comes from `classify-risk.sh`.', 'classify-risk.sh'],
    'vendor copy' => ['It runs vendor/pekral/ai-olympus/skills/_shared/run-validation.sh.', 'vendor/pekral/ai-olympus/skills/_shared/run-validation.sh'],
    'wrapper script with a line' => ['The publish failed in post-inline-review.sh:42.', 'post-inline-review.sh'],
]);

test('every refused name is listed once, in the order of its first use', function (): void {
    $process = runCheckVisibleReviewText(
        "`classify-risk.sh` and `skills/_shared/run-gate.sh`.\nAgain `classify-risk.sh`.",
        ['src/A.php'],
    );

    expect($process->getExitCode())->toBe(3)
        ->and(trim($process->getOutput()))->toBe('{"valid":false,"names":["classify-risk.sh","skills/_shared/run-gate.sh"]}');
});

test('the reviewed project own commands, paths, and prose stay allowed', function (): void {
    $text = implode("\n", [
        '🔴 **Problem:** The test suite with coverage (`vendor/bin/pest --coverage`) leaves `src/A.php:12` uncovered.',
        '**Impact:** `composer build` passes while the branch in app/Rules/Price.php stays untested.',
        'The merge-risk check graded the change; the quality gate ran on 0123456789abcdef.',
        'Docs: https://github.com/o/r/blob/main/README.md and resources/skills/list.blade.php.',
        '<!-- cr-inline:actor=bot -->',
    ]);

    $process = runCheckVisibleReviewText($text, ['src/A.php', 'app/Rules/Price.php']);

    expect($process->getExitCode())->toBe(0)
        ->and(trim($process->getOutput()))->toBe('{"valid":true,"names":[]}');
});

test('a name that the reviewed repository holds is the project own and stays allowed', function (): void {
    $text = 'This repeats `skills/_shared/run-gate.sh:40`, see `@rules/security/backend.md`, `skills/_shared/` and `classify-risk.sh`.';

    $process = runCheckVisibleReviewText($text, [
        'rules/security/backend.md',
        'skills/_shared/run-gate.sh',
        'skills/_shared/classify-risk.sh',
    ]);

    expect($process->getExitCode())->toBe(0)
        ->and(trim($process->getOutput()))->toBe('{"valid":true,"names":[]}');
});

test('a name that a changed line of the reviewed change carries is the project own text, any other name is refused', function (): void {
    $diffText = "\$path = '.claude/run/state.json';\nrequire 'skills/_shared/run-gate.sh';\n";
    $text = 'The line writes `.claude/run/state.json` and the step runs `classify-risk.sh`.';

    $process = runCheckVisibleReviewText($text, ['src/A.php'], $diffText);

    expect($process->getExitCode())->toBe(3)
        ->and(trim($process->getOutput()))->toBe('{"valid":false,"names":["classify-risk.sh"]}')
        ->and(runCheckVisibleReviewText('The line writes `.claude/run/state.json`.', ['src/A.php'], $diffText)->getExitCode())->toBe(0)
        ->and(runCheckVisibleReviewText('The line writes `.claude/run/state.json`.', ['src/A.php'])->getExitCode())->toBe(3);
});

test('without the reviewed repository paths every package name is refused', function (): void {
    $process = runCheckVisibleReviewText('It runs `skills/_shared/run-gate.sh`.');

    expect($process->getExitCode())->toBe(3)
        ->and(trim($process->getOutput()))->toBe('{"valid":false,"names":["skills/_shared/run-gate.sh"]}');
});

test('a missing --text is a usage error and an unreadable input is refused', function (): void {
    $packageDir = dirname(__DIR__, 3);
    $script = $packageDir . '/skills/_shared/check-visible-review-text.php';

    $usage = new Process(['php', $script], $packageDir);
    $usage->run();

    $missingText = new Process(['php', $script, '--text', $packageDir . '/does-not-exist.md'], $packageDir);
    $missingText->run();

    $missingPaths = new Process(['php', $script, '--text', '-', '--repo-paths', $packageDir . '/does-not-exist.txt'], $packageDir);
    $missingPaths->setInput('text');
    $missingPaths->run();

    expect($usage->getExitCode())->toBe(1)
        ->and($usage->getOutput())->toBe('')
        ->and($usage->getErrorOutput())->toContain('Usage: check-visible-review-text.php')
        ->and($missingText->getExitCode())->toBe(1)
        ->and($missingText->getOutput())->toBe('')
        ->and($missingText->getErrorOutput())->toBe("check-visible-review-text.php: cannot read the visible text\n")
        ->and($missingPaths->getExitCode())->toBe(1)
        ->and($missingPaths->getOutput())->toBe('')
        ->and($missingPaths->getErrorOutput())->toBe("check-visible-review-text.php: cannot read the repository paths\n");
});

test('a text file given by path is read', function (): void {
    $packageDir = dirname(__DIR__, 3);
    $file = (string) tempnam(sys_get_temp_dir(), 'ai-olympus-visible-');
    file_put_contents($file, 'It runs `run-gate.sh`.');

    $process = new Process(['php', $packageDir . '/skills/_shared/check-visible-review-text.php', '--text', $file], $packageDir);
    $process->run();
    unlink($file);

    expect($process->getExitCode())->toBe(3)
        ->and(trim($process->getOutput()))->toBe('{"valid":false,"names":["run-gate.sh"]}');
});
