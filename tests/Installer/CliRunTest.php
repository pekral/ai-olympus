<?php

declare(strict_types = 1);

use AgenticVibes\AgentSkills\Installer;
use AgenticVibes\AgentSkills\InstallerPath;
use AgenticVibes\AgentSkills\InstallOptions;

test('run shows help when executed without arguments', function (): void {
    ob_start();
    $exitCode = Installer::run(['agent-skills']);
    $output = ob_get_clean();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('Usage:');
    expect($output)->not->toContain('--editor');
});

test('run returns error code for unknown command', function (): void {
    $exitCode = Installer::run(['agent-skills', 'unknown']);

    expect($exitCode)->toBe(1);
});

test('install with any --editor argument returns exit 1 (flag removed, never silently ignored)', function (): void {
    foreach (['claude', 'cursor', 'codex', 'all', 'invalid'] as $editorValue) {
        $exitCode = Installer::run(['agent-skills', 'install', '--editor=' . $editorValue]);

        expect($exitCode)->toBe(1);
    }
});

test('run shows prune option in help output', function (): void {
    ob_start();
    $exitCode = Installer::run(['agent-skills']);
    $output = ob_get_clean();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('--prune');
});

test('help text documents the --allow-bundled-scripts flag', function (): void {
    ob_start();
    $exitCode = Installer::run(['agent-skills']);
    $output = ob_get_clean();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('--allow-bundled-scripts');
    expect($output)->toContain('~/.claude/settings.json');
    expect($output)->toContain('Opt-in');
});

test('normalizeCliArguments splits --allow-bundled-scripts from a concatenated argv blob', function (): void {
    $normalized = InstallerPath::normalizeCliArguments(['agent-skills', 'install', '--editor=claude--allow-bundled-scripts']);

    expect($normalized)->toContain('--editor=claude');
    expect($normalized)->toContain('--allow-bundled-scripts');
});

test('help text documents the --allow-subagent-writes flag', function (): void {
    ob_start();
    $exitCode = Installer::run(['agent-skills']);
    $output = ob_get_clean();

    expect($exitCode)->toBe(0);
    expect($output)->toContain('--allow-subagent-writes');
    expect($output)->toContain('.claude/settings.local.json');
});

test('normalizeCliArguments splits --allow-subagent-writes from a concatenated argv blob', function (): void {
    $normalized = InstallerPath::normalizeCliArguments(['agent-skills', 'install', '--editor=claude--allow-subagent-writes']);

    expect($normalized)->toContain('--editor=claude');
    expect($normalized)->toContain('--allow-subagent-writes');
});

test('readme documents every InstallOptions flag in both the command list and the CLI switches table (issue #102)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $readme = (string) file_get_contents($packageDir . '/README.md');

    $constructor = new ReflectionClass(InstallOptions::class)->getConstructor();
    assert($constructor !== null);

    // Derive the live flag names from the constructor instead of hardcoding them, so a
    // future flag added to InstallOptions without a matching README update fails here.
    $flags = array_map(
        static fn (ReflectionParameter $parameter): string => '--' . strtolower(
            (string) preg_replace('/(?<!^)[A-Z]/', '-$0', $parameter->getName()),
        ),
        $constructor->getParameters(),
    );

    expect($flags)->not->toBeEmpty();

    $commandsStart = strpos($readme, '### Available Commands');
    assert($commandsStart !== false);
    $commandsEnd = strpos($readme, '### Installer Flow', $commandsStart + 1);
    assert($commandsEnd !== false);
    $commandsSection = substr($readme, $commandsStart, $commandsEnd - $commandsStart);

    $switchesStart = strpos($readme, '### CLI Switches');
    assert($switchesStart !== false);
    $switchesEnd = strpos($readme, "\n---", $switchesStart + 1);
    assert($switchesEnd !== false);
    $switchesSection = substr($readme, $switchesStart, $switchesEnd - $switchesStart);

    foreach ($flags as $flag) {
        expect($commandsSection)->toContain($flag);
        expect($switchesSection)->toContain('`' . $flag . '`');
    }
});

test('install without any flags succeeds and targets Claude Code only', function (): void {
    $root = installerCreateProjectRoot();
    $cwd = getcwd();
    $originalCwd = $cwd !== false ? $cwd : '';

    try {
        chdir($root);
        ob_start();
        $exitCode = Installer::run(['agent-skills', 'install']);
        ob_end_clean();

        expect($exitCode)->toBe(0);
        expect(is_dir($root . '/.claude/rules'))->toBeTrue();
        expect(is_dir($root . '/.cursor'))->toBeFalse();
        expect(is_dir($root . '/.codex'))->toBeFalse();
    } finally {
        if ($originalCwd !== '') {
            chdir($originalCwd);
        }

        installerRemoveDirectory($root);
    }
});
