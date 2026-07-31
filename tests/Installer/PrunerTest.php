<?php

declare(strict_types = 1);

use AgenticVibes\AgentSkills\Installer;
use AgenticVibes\AgentSkills\InstallerFileCopier;
use AgenticVibes\AgentSkills\InstallerPruner;

test('install with prune removes files from target that no longer exist in source', function (): void {
    $root = installerCreateProjectRoot();
    $cwd = getcwd();
    $originalCwd = $cwd !== false ? $cwd : '';

    try {
        chdir($root);

        ob_start();
        Installer::run(['agent-skills', 'install']);
        ob_end_clean();

        installerWriteFile($root . '/.claude/skills/orphaned-skill/SKILL.md', 'orphaned content');

        ob_start();
        Installer::run(['agent-skills', 'install', '--prune']);
        ob_end_clean();

        expect(is_file($root . '/.claude/skills/code-review/SKILL.md'))->toBeTrue();
        expect(is_file($root . '/.claude/skills/orphaned-skill/SKILL.md'))->toBeFalse();
        expect(is_dir($root . '/.claude/skills/orphaned-skill'))->toBeFalse();
    } finally {
        if ($originalCwd !== '') {
            chdir($originalCwd);
        }

        installerRemoveDirectory($root);
    }
});

test('install without prune keeps orphaned files in target', function (): void {
    $root = installerCreateProjectRoot();
    installerWriteFile($root . '/.claude/skills/orphaned-skill/SKILL.md', 'orphaned content');
    $cwd = getcwd();
    $originalCwd = $cwd !== false ? $cwd : '';

    try {
        chdir($root);
        ob_start();
        Installer::run(['agent-skills', 'install']);
        $output = ob_get_clean();

        expect(is_file($root . '/.claude/skills/orphaned-skill/SKILL.md'))->toBeTrue();
        expect($output)->toContain('1 file(s) across the target directories no longer exist in source. Re-run with --prune to remove them.');
    } finally {
        if ($originalCwd !== '') {
            chdir($originalCwd);
        }

        installerRemoveDirectory($root);
    }
});

test('the orphan report names which target directory the orphan is in (PR #150 CR fix — Minor 2)', function (): void {
    $root = installerCreateProjectRoot();
    installerWriteFile($root . '/.claude/skills/orphaned-skill/SKILL.md', 'orphaned content');
    $cwd = getcwd();
    $originalCwd = $cwd !== false ? $cwd : '';

    try {
        chdir($root);
        ob_start();
        Installer::run(['agent-skills', 'install']);
        $output = ob_get_clean();

        // Appended after the pinned sentence (never replacing it) — the reader no longer has to
        // guess which of the (up to six) target directories to inspect before running --prune.
        // realpath(): `getcwd()` (used internally to resolve the project root) canonicalizes
        // symlinks in the path (e.g. macOS's /tmp -> /private/tmp), so the reported target must
        // be compared against the same resolved form, not the raw temp-dir path.
        expect($output)->toContain(
            '1 file(s) across the target directories no longer exist in source. Re-run with --prune to remove them. (' . realpath(
                $root,
            ) . '/.claude/skills)',
        );
    } finally {
        if ($originalCwd !== '') {
            chdir($originalCwd);
        }

        installerRemoveDirectory($root);
    }
});

test('install with prune also removes rules that no longer exist in source', function (): void {
    $root = installerCreateProjectRoot();
    $cwd = getcwd();
    $originalCwd = $cwd !== false ? $cwd : '';

    try {
        chdir($root);

        ob_start();
        Installer::run(['agent-skills', 'install']);
        ob_end_clean();

        installerWriteFile($root . '/.claude/rules/removed.mdc', 'removed rule');

        ob_start();
        Installer::run(['agent-skills', 'install', '--prune']);
        ob_end_clean();

        expect(is_file($root . '/.claude/rules/php/core-standards.mdc'))->toBeTrue();
        expect(is_file($root . '/.claude/rules/removed.mdc'))->toBeFalse();
    } finally {
        if ($originalCwd !== '') {
            chdir($originalCwd);
        }

        installerRemoveDirectory($root);
    }
});

test('install with prune reports pruned file count in output', function (): void {
    $root = installerCreateProjectRoot();
    $cwd = getcwd();
    $originalCwd = $cwd !== false ? $cwd : '';

    try {
        chdir($root);

        ob_start();
        Installer::run(['agent-skills', 'install']);
        ob_end_clean();

        installerWriteFile($root . '/.claude/skills/drop-skill/SKILL.md', 'drop');

        ob_start();
        Installer::run(['agent-skills', 'install', '--prune']);
        $output = ob_get_clean();

        expect($output)->toContain('1 pruned');
        expect($output)->not->toContain('Re-run with --prune');
    } finally {
        if ($originalCwd !== '') {
            chdir($originalCwd);
        }

        installerRemoveDirectory($root);
    }
});

test('InstallerPruner returns 0 when target directory does not exist', function (): void {
    $result = InstallerPruner::pruneDirectory('/some/source', '/nonexistent/target');

    expect($result)->toBe(0);
});

test('InstallerPruner prunes all files when source directory does not exist', function (): void {
    $root = installerCreateProjectRoot();
    installerWriteFile($root . '/target/some-skill/SKILL.md', 'content');

    try {
        $pruned = InstallerPruner::pruneDirectory(
            $root . '/nonexistent-source',
            $root . '/target',
        );

        expect($pruned)->toBe(1);
        expect(is_file($root . '/target/some-skill/SKILL.md'))->toBeFalse();
    } finally {
        installerRemoveDirectory($root);
    }
});

test('InstallerPruner prune keeps non-orphaned files in nested directory', function (): void {
    $root = installerCreateProjectRoot();
    installerWriteFile($root . '/source/skill-a/SKILL.md', 'source content');
    installerWriteFile($root . '/target/skill-a/SKILL.md', 'target content');
    installerWriteFile($root . '/target/skill-a/extra.md', 'extra content');

    try {
        $pruned = InstallerPruner::pruneDirectory(
            $root . '/source',
            $root . '/target',
        );

        expect($pruned)->toBe(1);
        expect(is_file($root . '/target/skill-a/SKILL.md'))->toBeTrue();
        expect(is_file($root . '/target/skill-a/extra.md'))->toBeFalse();
        expect(is_dir($root . '/target/skill-a'))->toBeTrue();
    } finally {
        installerRemoveDirectory($root);
    }
});

test('InstallerPruner removes empty parent directories after pruning', function (): void {
    $root = installerCreateProjectRoot();
    installerWriteFile($root . '/source/keep/SKILL.md', 'keep');
    installerWriteFile($root . '/target/keep/SKILL.md', 'keep');
    installerWriteFile($root . '/target/orphaned/SKILL.md', 'orphaned');

    try {
        $pruned = InstallerPruner::pruneDirectory(
            $root . '/source',
            $root . '/target',
        );

        expect($pruned)->toBe(1);
        expect(is_dir($root . '/target/orphaned'))->toBeFalse();
        expect(is_dir($root . '/target/keep'))->toBeTrue();
    } finally {
        installerRemoveDirectory($root);
    }
});

test('InstallerPruner handles unwritable file gracefully when pruning', function (): void {
    if (posix_getuid() === 0) {
        expect(value: true)->toBeTrue();

        return;
    }

    $root = installerCreateProjectRoot();
    installerWriteFile($root . '/source/keep/SKILL.md', 'keep');
    installerWriteFile($root . '/target/keep/SKILL.md', 'keep');
    installerWriteFile($root . '/target/locked/SKILL.md', 'locked');
    chmod($root . '/target/locked', 0555);

    try {
        set_error_handler(static fn (): bool => true);
        $pruned = InstallerPruner::pruneDirectory(
            $root . '/source',
            $root . '/target',
        );
        restore_error_handler();

        expect($pruned)->toBe(0);
    } finally {
        chmod($root . '/target/locked', 0755);
        installerRemoveDirectory($root);
    }
});

test('InstallerPruner handles unwritable parent directory when removing empty dirs', function (): void {
    if (posix_getuid() === 0) {
        expect(value: true)->toBeTrue();

        return;
    }

    $root = installerCreateProjectRoot();
    installerWriteFile($root . '/source/keep/SKILL.md', 'keep');
    installerWriteFile($root . '/target/keep/SKILL.md', 'keep');
    installerWriteFile($root . '/target/orphaned/SKILL.md', 'orphaned');
    chmod($root . '/target', 0555);

    try {
        set_error_handler(static fn (): bool => true);
        InstallerPruner::pruneDirectory(
            $root . '/source',
            $root . '/target',
        );
        restore_error_handler();

        expect(value: true)->toBeTrue();
    } finally {
        chmod($root . '/target', 0755);
        installerRemoveDirectory($root);
    }
});

test('findOrphans does not descend through a directory symlink inside the target (PR #150 CR fix)', function (): void {
    if (installerSymlinkUnsupported()) {
        expect(value: true)->toBeTrue();

        return;
    }

    $root = installerCreateProjectRoot();
    installerWriteFile($root . '/source/keep/SKILL.md', 'keep');
    installerWriteFile($root . '/target/keep/SKILL.md', 'keep');
    installerWriteFile($root . '/outside/secret.md', 'secret');
    symlink($root . '/outside', $root . '/target/escape');

    try {
        $orphans = InstallerPruner::findOrphans($root . '/source', $root . '/target');

        // The symlink itself is reported as an orphan leaf — it is never descended into, so no
        // path outside the target (e.g. `escape/secret.md`) is ever enumerated or counted.
        expect($orphans)->toBe(['escape']);
    } finally {
        installerRemoveDirectory($root);
    }
});

test('pruning never deletes through a directory symlink inside the target — only the link itself is removed (PR #150 CR fix)', function (): void {
    if (installerSymlinkUnsupported()) {
        expect(value: true)->toBeTrue();

        return;
    }

    $root = installerCreateProjectRoot();
    installerWriteFile($root . '/source/keep/SKILL.md', 'keep');
    installerWriteFile($root . '/target/keep/SKILL.md', 'keep');
    installerWriteFile($root . '/outside/secret.md', 'secret');
    symlink($root . '/outside', $root . '/target/escape');

    try {
        $pruned = InstallerPruner::pruneDirectory($root . '/source', $root . '/target');

        expect($pruned)->toBe(1);
        expect(file_exists($root . '/target/escape'))->toBeFalse();
        expect(is_link($root . '/target/escape'))->toBeFalse();
        // The symlink's own directory entry was removed — its target was never touched.
        expect(is_file($root . '/outside/secret.md'))->toBeTrue();
    } finally {
        installerRemoveDirectory($root);
    }
});

test('reportInstallSummary places each counter in its own distinct message (PR #150 CR fix — InstallSummary DTO)', function (): void {
    $root = installerCreateProjectRoot();
    // A distinct HOME (never equal to the project root) keeps `~/.claude/skills` and
    // `<project>/.claude/skills` genuinely separate targets, so the orphaned file created below
    // exists in exactly one of them — an accidental identical-path collision would double-count it.
    $home = installerCreateProjectRoot();
    $homeEnv = getenv('HOME');
    $homeBefore = $homeEnv !== false && $homeEnv !== '' ? $homeEnv : getenv('USERPROFILE');
    putenv('HOME=' . $home);

    if (getenv('USERPROFILE') !== false) {
        putenv('USERPROFILE=' . $home);
    }

    $cwd = getcwd();
    $originalCwd = $cwd !== false ? $cwd : '';

    try {
        chdir($root);
        ob_start();
        Installer::run(['agent-skills', 'install']);
        ob_end_clean();

        // A stale target file makes the orphan count exactly 1, distinct from the pruned count
        // (0, no --prune) and the permission count (2, from --allow-bundled-scripts below) — three
        // simultaneous, distinct counters in one run, each landing in its own sentence. Reverting
        // InstallSummary's named properties back to the old 5-positional-parameter signature would
        // let a reordering silently misreport at least one of these.
        installerWriteFile($root . '/.claude/skills/orphaned-skill/SKILL.md', 'orphaned content');

        ob_start();
        Installer::run(['agent-skills', 'install', '--allow-bundled-scripts']);
        $output = ob_get_clean();

        expect($output)->toContain('0 pruned');
        expect($output)->toContain('1 file(s) across the target directories no longer exist in source. Re-run with --prune to remove them.');
        expect($output)->toContain('Allowed 2 bundled-script permission(s) in ~/.claude/settings.json.');
    } finally {
        installerRestoreEnvAndCleanup($homeBefore, $originalCwd, $root);
        installerRemoveDirectory($home);
    }
});

test('findOrphans reuses a caller-supplied source listing across targets (PR #150 CR fix — Minor 6)', function (): void {
    $root = installerCreateProjectRoot();
    installerWriteFile($root . '/source/skill-a/SKILL.md', 'source content');
    installerWriteFile($root . '/target-a/skill-a/SKILL.md', 'target content');
    installerWriteFile($root . '/target-a/orphan-a/SKILL.md', 'orphan a');
    installerWriteFile($root . '/target-b/skill-a/SKILL.md', 'target content');
    installerWriteFile($root . '/target-b/orphan-b/SKILL.md', 'orphan b');

    try {
        $sourceFiles = InstallerPruner::listSourceFiles($root . '/source');

        expect(InstallerPruner::findOrphans($root . '/source', $root . '/target-a', $sourceFiles))->toBe(['orphan-a/SKILL.md']);
        expect(InstallerPruner::findOrphans($root . '/source', $root . '/target-b', $sourceFiles))->toBe(['orphan-b/SKILL.md']);
    } finally {
        installerRemoveDirectory($root);
    }
});

test(
    'installDirectory copies exactly a caller-supplied source listing instead of re-walking the source tree (PR #150 CR fix — Refactoring 1)',
    function (): void {
        $root = installerCreateProjectRoot();
        installerWriteFile($root . '/source/skill-a/SKILL.md', 'a');
        installerWriteFile($root . '/source/skill-b/SKILL.md', 'b');

        try {
            // Deliberately omits skill-b: if installDirectory() silently re-listed $source itself
            // instead of honouring the supplied listing, skill-b would be copied anyway.
            $copied = InstallerFileCopier::installDirectory(
                $root . '/source',
                $root . '/target',
                force: false,
                symlink: false,
                sourceFiles: ['skill-a/SKILL.md'],
            );

            expect($copied)->toBe(1);
            expect(is_file($root . '/target/skill-a/SKILL.md'))->toBeTrue();
            expect(is_file($root . '/target/skill-b/SKILL.md'))->toBeFalse();
        } finally {
            installerRemoveDirectory($root);
        }
    },
);
