<?php

declare(strict_types = 1);

use Pekral\AiOlympus\Installer;
use Pekral\AiOlympus\InstallerPath;

test('resolveClaudeMdSource returns path to CLAUDE.md in package', function (): void {
    $source = InstallerPath::resolveClaudeMdSource();

    expect($source)->not->toBeNull();
    expect($source)->toBeString();
    expect($source)->toEndWith('/CLAUDE.md');
    expect(is_file((string) $source))->toBeTrue();
});

test('resolveClaudeMdTarget returns CLAUDE.md path in project root', function (): void {
    $target = InstallerPath::resolveClaudeMdTarget('/project');

    expect($target)->toBe('/project/CLAUDE.md');
});

test('install copies CLAUDE.md to project root', function (): void {
    $root = installerCreateProjectRoot();
    $cwd = getcwd();
    $originalCwd = $cwd !== false ? $cwd : '';

    try {
        chdir($root);
        ob_start();
        Installer::run(['ai-olympus', 'install']);
        ob_end_clean();

        $claudeMd = $root . '/CLAUDE.md';
        expect(is_file($claudeMd))->toBeTrue();
        expect(file_get_contents($claudeMd))->toContain('Behavioral guidelines');
    } finally {
        if ($originalCwd !== '') {
            chdir($originalCwd);
        }

        installerRemoveDirectory($root);
    }
});

test('install does not overwrite existing CLAUDE.md without force flag', function (): void {
    $root = installerCreateProjectRoot();
    $claudeMd = $root . '/CLAUDE.md';
    file_put_contents($claudeMd, 'my custom CLAUDE.md');
    $cwd = getcwd();
    $originalCwd = $cwd !== false ? $cwd : '';

    try {
        chdir($root);
        ob_start();
        Installer::run(['ai-olympus', 'install']);
        ob_end_clean();

        expect(file_get_contents($claudeMd))->toBe('my custom CLAUDE.md');
    } finally {
        if ($originalCwd !== '') {
            chdir($originalCwd);
        }

        installerRemoveDirectory($root);
    }
});

test('install never overwrites existing CLAUDE.md even with force flag', function (): void {
    $root = installerCreateProjectRoot();
    $claudeMd = $root . '/CLAUDE.md';
    file_put_contents($claudeMd, 'my custom CLAUDE.md');
    $cwd = getcwd();
    $originalCwd = $cwd !== false ? $cwd : '';

    try {
        chdir($root);
        ob_start();
        Installer::run(['ai-olympus', 'install', '--force']);
        ob_end_clean();

        expect(file_get_contents($claudeMd))->toBe('my custom CLAUDE.md');
    } finally {
        if ($originalCwd !== '') {
            chdir($originalCwd);
        }

        installerRemoveDirectory($root);
    }
});

test('CLAUDE.md source file exists in package', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $claudeMd = $packageDir . '/CLAUDE.md';

    expect(is_file($claudeMd))->toBeTrue();
    expect(file_get_contents($claudeMd))->toContain('Behavioral guidelines');
    expect(file_get_contents($claudeMd))->toContain('Think Before Coding');
    expect(file_get_contents($claudeMd))->toContain('Simplicity First');
    expect(file_get_contents($claudeMd))->toContain('Surgical Changes');
    expect(file_get_contents($claudeMd))->toContain('Goal-Driven Execution');
});
