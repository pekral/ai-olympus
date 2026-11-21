<?php

declare(strict_types = 1);

use Pekral\AiOlympus\Installer;
use Tests\Support\StderrCapture;

/**
 * @return array{0: int, 1: string}
 */
function installedCopyReferenceInstall(string $root, string ...$flags): array
{
    $cwd = getcwd();
    chdir($root);

    try {
        ob_start();
        [$exitCode, $stderr] = StderrCapture::run(static fn (): int => Installer::run(['ai-olympus', 'install', ...array_values($flags)]));
        ob_end_clean();
    } finally {
        chdir($cwd !== false ? $cwd : $root);
    }

    return [$exitCode, $stderr];
}

/**
 * @return array<string, string>
 */
function installedCopyReferenceLines(string $reference): array
{
    $lines = [];
    $fileLines = file($reference, FILE_IGNORE_NEW_LINES);

    foreach ($fileLines === false ? [] : $fileLines as $line) {
        $lines[substr($line, 66)] = substr($line, 0, 64);
    }

    return $lines;
}

test('install records the hash of every installed workflow copy in the git directory, outside the working tree', function (): void {
    $root = installerCreateProjectRoot();
    installerEnsureDirectory($root . '/.git');

    try {
        [$exitCode] = installedCopyReferenceInstall($root);
        $lines = installedCopyReferenceLines($root . '/.git/ai-olympus/installed-copies.sha256');

        expect($exitCode)->toBe(0)
            ->and($lines['.claude/command-support/_shared/classify-risk.sh'])->toBe(hash_file('sha256', $root . '/.claude/command-support/_shared/classify-risk.sh'))
            ->and($lines['.codex/commands/_shared/classify-risk.sh'])->toBe(hash_file('sha256', $root . '/.codex/commands/_shared/classify-risk.sh'))
            ->and($lines['.claude/commands/do-it.md'])->toBe(hash_file('sha256', $root . '/.claude/commands/do-it.md'))
            ->and($lines)->not->toHaveKey('.claude/skills/code-review/SKILL.md')
            ->and(is_file($root . '/.git/ai-olympus/installed-copies.sha256.tmp'))->toBeFalse();
    } finally {
        installerRemoveDirectory($root);
    }
});

test('install records no line for a kept copy that differs from the package source', function (): void {
    $root = installerCreateProjectRoot();
    installerEnsureDirectory($root . '/.git');
    installerWriteFile($root . '/.claude/command-support/_shared/classify-risk.sh', "#!/usr/bin/env bash\necho changed\n");

    try {
        installedCopyReferenceInstall($root);
        $lines = installedCopyReferenceLines($root . '/.git/ai-olympus/installed-copies.sha256');

        expect($lines)->not->toHaveKey('.claude/command-support/_shared/classify-risk.sh')
            ->and($lines)->toHaveKey('.codex/commands/_shared/classify-risk.sh');
    } finally {
        installerRemoveDirectory($root);
    }
});

test('install records no line for a copy installed as a symbolic link', function (): void {
    $root = installerCreateProjectRoot();
    installerEnsureDirectory($root . '/.git');

    try {
        installedCopyReferenceInstall($root, '--symlink');

        expect(is_file($root . '/.git/ai-olympus/installed-copies.sha256'))->toBeTrue()
            ->and(installedCopyReferenceLines($root . '/.git/ai-olympus/installed-copies.sha256'))->toBe([]);
    } finally {
        installerRemoveDirectory($root);
    }
})->skip(installerSymlinkUnsupported(), 'symlinks are not supported on this platform');

test('install replaces the reference of an earlier install', function (): void {
    $root = installerCreateProjectRoot();
    installerWriteFile($root . '/.git/ai-olympus/installed-copies.sha256', str_repeat('a', 64) . "  .claude/skills/_shared/removed.sh\n");

    try {
        installedCopyReferenceInstall($root);

        expect(installedCopyReferenceLines($root . '/.git/ai-olympus/installed-copies.sha256'))
            ->not->toHaveKey('.claude/skills/_shared/removed.sh')
            ->toHaveKey('.claude/commands/do-it.md');
    } finally {
        installerRemoveDirectory($root);
    }
});

test('a linked worktree records the reference in the git directory its .git file names', function (string $gitdir, string $expected): void {
    $root = installerCreateProjectRoot();
    $gitdir = str_replace('{root}', $root, $gitdir);
    installerWriteFile($root . '/.git', 'gitdir: ' . $gitdir . "\n");

    try {
        installedCopyReferenceInstall($root);

        expect(installedCopyReferenceLines(str_replace('{root}', $root, $expected)))->toHaveKey('.claude/command-support/_shared/classify-risk.sh');
    } finally {
        installerRemoveDirectory($root);
    }
})->with([
    'an absolute path' => ['{root}/main-git/worktrees/unit', '{root}/main-git/worktrees/unit/ai-olympus/installed-copies.sha256'],
    'a relative path' => ['main-git/worktrees/unit', '{root}/main-git/worktrees/unit/ai-olympus/installed-copies.sha256'],
]);

test('install writes no reference without a .git entry', function (): void {
    $root = installerCreateProjectRoot();

    try {
        [$exitCode] = installedCopyReferenceInstall($root);

        expect($exitCode)->toBe(0)
            ->and(file_exists($root . '/.git'))->toBeFalse()
            ->and(file_exists($root . '/ai-olympus'))->toBeFalse();
    } finally {
        installerRemoveDirectory($root);
    }
});

test('install writes no reference when the .git file names no git directory', function (): void {
    $root = installerCreateProjectRoot();
    installerWriteFile($root . '/.git', "not a pointer\n");

    try {
        [$exitCode] = installedCopyReferenceInstall($root);

        expect($exitCode)->toBe(0)
            ->and(is_file($root . '/.git'))->toBeTrue()
            ->and(file_exists($root . '/ai-olympus'))->toBeFalse();
    } finally {
        installerRemoveDirectory($root);
    }
});

test('install fails when it cannot write the reference', function (string $blocked): void {
    $root = installerCreateProjectRoot();
    installerWriteFile($root . '/.git/ai-olympus/' . $blocked . '/keep', 'x');

    try {
        [$exitCode, $stderr] = installedCopyReferenceInstall($root);

        expect($exitCode)->toBe(1)
            ->and($stderr)->toContain('Cannot write the installed-copy reference ')
            ->and($stderr)->toContain('/.git/ai-olympus/installed-copies.sha256.');
    } finally {
        installerRemoveDirectory($root);
    }
})->with([
    'the reference' => ['installed-copies.sha256'],
    'the temporary file' => ['installed-copies.sha256.tmp'],
]);
