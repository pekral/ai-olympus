<?php

declare(strict_types = 1);

use Pekral\AiOlympus\Installer;

/**
 * @return array<string, string>
 */
function renamedAgentIdentifiers(): array
{
    return [
        'daedalus' => 'splinter',
        'hephaestus' => 'donatello',
        'athena' => 'leonardo',
        'argus' => 'raphael',
        'apollo' => 'michelangelo',
        'hermes' => 'april',
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
        expect($output)->toContain('18 pruned');

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
        expect($output)->toContain('18 file(s) across the target directories no longer exist in source');

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
