<?php

declare(strict_types = 1);

use Pekral\AiOlympus\Installer;
use Pekral\AiOlympus\InstallerPath;

test('resolveClaudeMdSource returns path to CLAUDE.md in package', function (): void {
    $source = InstallerPath::resolveClaudeMdSource();

    expect($source)->not->toBeNull();
    expect($source)->toBeString();
    expect($source)->toEndWith('/templates/CLAUDE.md');
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
        $content = file_get_contents($claudeMd);
        expect($content)->toBe(file_get_contents(dirname(__DIR__, 2) . '/templates/CLAUDE.md'));
        expect($content)->not->toContain('## AI Olympus repository maintenance');
    } finally {
        if ($originalCwd !== '') {
            chdir($originalCwd);
        }

        installerRemoveDirectory($root);
    }
});

test('install keeps an existing AGENTS.md as Claude Code instructions when CLAUDE.md is absent (issue #139)', function (): void {
    $root = installerCreateProjectRoot();
    installerWriteFile($root . '/AGENTS.md', 'project-owned AGENTS.md');
    $cwd = getcwd();
    $originalCwd = $cwd !== false ? $cwd : '';

    try {
        chdir($root);
        ob_start();
        $exitCode = Installer::run(['ai-olympus', 'install', '--force']);
        ob_end_clean();

        expect($exitCode)->toBe(0);
        expect(is_file($root . '/CLAUDE.md'))->toBeFalse();
        expect(file_get_contents($root . '/AGENTS.md'))->toBe('project-owned AGENTS.md');
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
    $claudeMd = $packageDir . '/templates/CLAUDE.md';

    expect(is_file($claudeMd))->toBeTrue();
    expect(file_get_contents($claudeMd))->toContain('Behavioral guidelines');
    expect(file_get_contents($claudeMd))->toContain('Think Before Coding');
    expect(file_get_contents($claudeMd))->toContain('Simplicity First');
    expect(file_get_contents($claudeMd))->toContain('Surgical Changes');
    expect(file_get_contents($claudeMd))->toContain('Goal-Driven Execution');
});

test('with Laravel Boost installed and no CLAUDE.md the template becomes a Boost guideline', function (): void {
    $root = installerCreateProjectRoot();
    mkdir($root . '/vendor/laravel/boost', 0777, recursive: true);
    $cwd = getcwd();
    $originalCwd = $cwd !== false ? $cwd : '';

    try {
        chdir($root);
        ob_start();
        Installer::run(['ai-olympus', 'install']);
        ob_end_clean();

        expect(is_file($root . '/CLAUDE.md'))->toBeFalse();
        expect(is_file($root . '/AGENTS.md'))->toBeFalse();
        expect(file_get_contents($root . '/.ai/guidelines/ai-olympus.md'))
            ->toBe(file_get_contents(dirname(__DIR__, 2) . '/templates/CLAUDE.md'));
    } finally {
        if ($originalCwd !== '') {
            chdir($originalCwd);
        }

        installerRemoveDirectory($root);
    }
});

test('with Laravel Boost installed an existing CLAUDE.md stays the only instruction file', function (): void {
    $root = installerCreateProjectRoot();
    mkdir($root . '/vendor/laravel/boost', 0777, recursive: true);
    installerWriteFile($root . '/CLAUDE.md', 'generated by boost');
    $cwd = getcwd();
    $originalCwd = $cwd !== false ? $cwd : '';

    try {
        chdir($root);
        ob_start();
        Installer::run(['ai-olympus', 'install', '--force']);
        ob_end_clean();

        expect(file_get_contents($root . '/CLAUDE.md'))->toBe('generated by boost');
        expect(file_exists($root . '/.ai/guidelines/ai-olympus.md'))->toBeFalse();
    } finally {
        if ($originalCwd !== '') {
            chdir($originalCwd);
        }

        installerRemoveDirectory($root);
    }
});

test('the packaged AGENTS.md carries the deferred gate wording of the template', function (): void {
    $agentsMd = (string) file_get_contents(dirname(__DIR__, 2) . '/AGENTS.md');

    expect($agentsMd)->not->toContain('composer build');
    expect($agentsMd)->toContain('Finalization');
    expect($agentsMd)->toContain('quality-gates.md');
    expect($agentsMd)->not->toContain('Edit the tracked `rules/`');
});
