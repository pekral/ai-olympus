<?php

declare(strict_types = 1);

use Pekral\AiOlympus\Installer;
use Pekral\AiOlympus\InstallerAgentMigration;

/**
 * The renamed identifiers whose old definition the installer removes. `splinter` is absent on purpose: the
 * package ships a new `splinter` (the independent security agent) again, so there is nothing to remove.
 *
 * @return array<string, string>
 */
function renamedAgentIdentifiers(): array
{
    return [
        'apollo' => 'michelangelo',
        'argus' => 'raphael',
        'athena' => 'leonardo',
        'daedalus' => 'krang',
        'hephaestus' => 'donatello',
        'hermes' => 'april',
        'metalhead' => 'shredder',
    ];
}

/**
 * @return array<string, string>
 */
function renamedAgentTargets(): array
{
    return ['.claude/agents' => 'md', '.codex/agent-instructions' => 'md', '.codex/agents' => 'toml'];
}

test('a normal upgrade removes known old agents and installs the new identities without pruning custom agents', function (): void {
    $root = installerCreateProjectRoot();
    $originalCwd = (string) getcwd();

    foreach (renamedAgentTargets() as $directory => $extension) {
        installerWriteFile($root . '/' . $directory . '/custom.' . $extension, 'user-owned agent');

        foreach (renamedAgentIdentifiers() as $old => $new) {
            installerWriteFile(
                $root . '/' . $directory . '/' . $old . '.' . $extension,
                (string) file_get_contents(__DIR__ . '/../Fixtures/legacy-agents/' . $old . '.' . $extension),
            );
        }
    }

    try {
        chdir($root);
        ob_start();
        $exitCode = Installer::run(['ai-olympus', 'install', '--force']);
        $output = ob_get_clean();

        expect($exitCode)->toBe(0);
        expect($output)->toContain('21 pruned');

        foreach (renamedAgentTargets() as $directory => $extension) {
            expect(file_get_contents($root . '/' . $directory . '/custom.' . $extension))->toBe('user-owned agent');

            foreach (renamedAgentIdentifiers() as $old => $new) {
                expect(file_exists($root . '/' . $directory . '/' . $old . '.' . $extension))->toBeFalse();
                expect(is_file($root . '/' . $directory . '/' . $new . '.' . $extension))->toBeTrue();
            }
        }
    } finally {
        chdir($originalCwd);
        installerRemoveDirectory($root);
    }
});

test('a normal upgrade preserves customized old agent definitions and reports them for manual review', function (): void {
    $root = installerCreateProjectRoot();
    $originalCwd = (string) getcwd();

    foreach (renamedAgentTargets() as $directory => $extension) {
        foreach (renamedAgentIdentifiers() as $old => $new) {
            installerWriteFile($root . '/' . $directory . '/' . $old . '.' . $extension, 'customized ' . $old);
        }
    }

    try {
        chdir($root);
        ob_start();
        $exitCode = Installer::run(['ai-olympus', 'install', '--force']);
        $output = ob_get_clean();

        expect($exitCode)->toBe(0);
        expect($output)->toContain('21 file(s) across the target directories no longer exist in source');

        foreach (renamedAgentTargets() as $directory => $extension) {
            foreach (renamedAgentIdentifiers() as $old => $new) {
                expect(file_get_contents($root . '/' . $directory . '/' . $old . '.' . $extension))->toBe('customized ' . $old);
                expect(is_file($root . '/' . $directory . '/' . $new . '.' . $extension))->toBeTrue();
            }
        }
    } finally {
        chdir($originalCwd);
        installerRemoveDirectory($root);
    }
});

test('installing the security agent over an unmodified 0.3.1 splinter keeps the new definition', function (): void {
    $root = installerCreateProjectRoot();
    $originalCwd = (string) getcwd();
    $package = dirname(__DIR__, 2);
    $sources = [
        '.claude/agents' => 'agents/splinter.md',
        '.codex/agent-instructions' => 'agents/splinter.md',
        '.codex/agents' => 'codex/agents/splinter.toml',
    ];

    foreach ($sources as $directory => $source) {
        $extension = pathinfo($source, PATHINFO_EXTENSION);
        installerWriteFile($root . '/' . $directory . '/krang.' . $extension, 'orchestrator replacement');
        installerWriteFile(
            $root . '/' . $directory . '/splinter.' . $extension,
            (string) file_get_contents($package . '/tests/Fixtures/legacy-agents/splinter.' . $extension),
        );
    }

    try {
        chdir($root);
        ob_start();
        $exitCode = Installer::run(['ai-olympus', 'install', '--force']);
        ob_end_clean();

        expect($exitCode)->toBe(0);

        foreach ($sources as $directory => $source) {
            $installed = (string) file_get_contents($root . '/' . $directory . '/splinter.' . pathinfo($source, PATHINFO_EXTENSION));

            expect($installed)->toBe((string) file_get_contents($package . '/' . $source));
            expect($installed)->not->toBe(
                (string) file_get_contents($package . '/tests/Fixtures/legacy-agents/splinter.' . pathinfo($source, PATHINFO_EXTENSION)),
            );
        }
    } finally {
        chdir($originalCwd);
        installerRemoveDirectory($root);
    }
});

test('a plain install replaces an unmodified 0.3.1 splinter in every target directory', function (): void {
    $root = installerCreateProjectRoot();
    $originalCwd = (string) getcwd();
    $package = dirname(__DIR__, 2);
    $sources = [
        '.claude/agents' => 'agents/splinter.md',
        '.codex/agent-instructions' => 'agents/splinter.md',
        '.codex/agents' => 'codex/agents/splinter.toml',
    ];

    foreach ($sources as $directory => $source) {
        $extension = pathinfo($source, PATHINFO_EXTENSION);
        installerWriteFile(
            $root . '/' . $directory . '/splinter.' . $extension,
            (string) file_get_contents($package . '/tests/Fixtures/legacy-agents/splinter.' . $extension),
        );
    }

    try {
        chdir($root);
        ob_start();
        $exitCode = Installer::run(['ai-olympus', 'install']);
        $output = (string) ob_get_clean();

        expect($exitCode)->toBe(0);
        expect($output)->not->toContain('Kept ');

        foreach ($sources as $directory => $source) {
            $installed = (string) file_get_contents($root . '/' . $directory . '/splinter.' . pathinfo($source, PATHINFO_EXTENSION));

            expect($installed)->toBe((string) file_get_contents($package . '/' . $source));
        }
    } finally {
        chdir($originalCwd);
        installerRemoveDirectory($root);
    }
});

test('a plain install keeps a modified old splinter and names the flag that replaces it', function (): void {
    $root = installerCreateProjectRoot();
    $originalCwd = (string) getcwd();
    $package = dirname(__DIR__, 2);
    $modified = (string) file_get_contents($package . '/tests/Fixtures/legacy-agents/splinter.md') . "\nlocal edit\n";
    installerWriteFile($root . '/.claude/agents/splinter.md', $modified);

    try {
        chdir($root);
        ob_start();
        $exitCode = Installer::run(['ai-olympus', 'install']);
        $output = (string) ob_get_clean();

        expect($exitCode)->toBe(0);
        expect((string) file_get_contents($root . '/.claude/agents/splinter.md'))->toBe($modified);
        expect($output)->toContain('Kept ')->toContain('/.claude/agents/splinter.md: it differs')->toContain('install --force');
    } finally {
        chdir($originalCwd);
        installerRemoveDirectory($root);
    }
});

test('a plain install keeps an up to date splinter without a warning', function (): void {
    $root = installerCreateProjectRoot();
    $originalCwd = (string) getcwd();

    try {
        chdir($root);
        ob_start();
        Installer::run(['ai-olympus', 'install']);
        ob_end_clean();
        ob_start();
        $exitCode = Installer::run(['ai-olympus', 'install']);
        $output = (string) ob_get_clean();

        expect($exitCode)->toBe(0);
        expect($output)->not->toContain('Kept ');
    } finally {
        chdir($originalCwd);
        installerRemoveDirectory($root);
    }
});

test('a symlink named splinter is never treated as a superseded copy', function (): void {
    $directory = sys_get_temp_dir() . '/ai-olympus-superseded-' . bin2hex(random_bytes(4));
    mkdir($directory);
    $legacy = dirname(__DIR__, 2) . '/tests/Fixtures/legacy-agents/splinter.md';
    symlink($legacy, $directory . '/splinter.md');

    try {
        expect(InstallerAgentMigration::isSupersededCopy('splinter.md', $directory . '/splinter.md'))->toBeFalse();
        expect(InstallerAgentMigration::isSupersededCopy('krang.md', $legacy))->toBeFalse();
        expect(InstallerAgentMigration::isSupersededCopy('splinter.md', $directory . '/missing.md'))->toBeFalse();
        expect(InstallerAgentMigration::isSupersededCopy('splinter.md', $legacy))->toBeTrue();
    } finally {
        unlink($directory . '/splinter.md');
        rmdir($directory);
    }
});
