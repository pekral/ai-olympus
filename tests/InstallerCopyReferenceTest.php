<?php

declare(strict_types = 1);

use Pekral\AiOlympus\Installer;
use Symfony\Component\Process\Process;
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
            ->and($lines['.claude/command-support/_shared/classify-risk.sh'])->toBe(
                hash_file('sha256', $root . '/.claude/command-support/_shared/classify-risk.sh'),
            )
            ->and($lines['.codex/commands/_shared/classify-risk.sh'])->toBe(hash_file('sha256', $root . '/.codex/commands/_shared/classify-risk.sh'))
            ->and($lines['.claude/commands/do-it.md'])->toBe(hash_file('sha256', $root . '/.claude/commands/do-it.md'))
            ->and($lines['.claude/skills/code-review/SKILL.md'])->toBe(hash_file('sha256', $root . '/.claude/skills/code-review/SKILL.md'))
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

test('an installed classifier verifies and runs its own libraries in a consumer repository', function (string $commands, string $skills): void {
    $root = installerCreateProjectRoot();

    try {
        (new Process(['git', 'init', '-q'], $root))->mustRun();
        (new Process(
            ['git', '-c', 'user.email=fixture@example.invalid', '-c', 'user.name=Fixture', 'commit', '--allow-empty', '-qm', 'fixture'],
            $root,
        ))->mustRun();
        $tip = trim((new Process(['git', 'rev-parse', 'HEAD'], $root))->mustRun()->getOutput());
        [$status] = installedCopyReferenceInstall($root, '--prune');
        expect($status)->toBe(0);
        $classifier = $commands . '/_shared/classify-risk.sh';
        $delivery = $commands . '/_shared/security-delivery.php';
        $verify = [
            'bash', $skills . '/_shared/verify-installed-copy.sh', '--root', $root, '--commit', $tip, '--installed-reference',
            $delivery, $classifier, $commands . '/_shared/domain-vocabulary.sh', $commands . '/_shared/skill-path.sh',
            $commands . '/_shared/read-manifest.sh', $skills . '/_shared/domain-vocabulary.sh', $skills . '/_shared/verify-installed-copy.sh',
        ];
        expect((new Process($verify, $root))->mustRun()->getOutput())->toContain('installed-copy-verdict=verified');
        $missingSecurity = new Process(
            [PHP_BINARY, $delivery],
            $root,
            input: json_encode(['summary' => '0 Critical · security: owned by leonardo', 'head_date' => '2026-05-28T12:00:00Z'], JSON_THROW_ON_ERROR),
        );
        expect($missingSecurity->run())->toBe(3);

        $classify = new Process(['bash', $classifier, '--files', '-'], $root, input: "README.md\n");
        expect($classify->mustRun()->getOutput())->toContain('tier=FAST');

        installerWriteFile($root . '/' . $skills . '/_shared/domain-vocabulary.sh', "# altered after installation\n");
        $refused = new Process($verify, $root);
        expect($refused->run())->not->toBe(0)
            ->and($refused->getOutput())->toContain('hash-mismatch', 'installed-copy-verdict=refused');
    } finally {
        installerRemoveDirectory($root);
    }
})->with([
    ['.claude/command-support', '.claude/skills'],
    ['.codex/commands', '.agents/skills'],
]);

test('each public command loads a protocol shipped by both the plugin and the installer', function (): void {
    $package = dirname(__DIR__, 2);
    $root = installerCreateProjectRoot();

    try {
        [$status] = installedCopyReferenceInstall($root, '--prune');
        expect($status)->toBe(0);

        $layouts = [
            [$package . '/commands', $package . '/commands'],
            [$root . '/.claude/commands', $root . '/.claude/command-support'],
            [$root . '/.codex/commands', $root . '/.codex/commands'],
        ];

        foreach (['do-it', 'prepare-issue-for-merge', 'redesign-page', 'report-code-review', 'test-assignment'] as $name) {
            foreach ($layouts as [$entryRoot, $supportRoot]) {
                $entry = (string) file_get_contents($entryRoot . '/' . $name . '.md');
                expect(preg_match_all('~`commands/(_shared/[a-z0-9./-]+[.]inc)`~', $entry, $matches))->toBeGreaterThan(0);

                foreach ($matches[1] as $protocol) {
                    expect(is_file($supportRoot . '/' . $protocol))->toBeTrue();
                }
            }
        }
    } finally {
        installerRemoveDirectory($root);
    }
});
