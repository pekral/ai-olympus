<?php

declare(strict_types = 1);

use Pekral\AiOlympus\Installer;

test('install never generates a CLAUDE.md in the project', function (): void {
    $root = installerCreateProjectRoot();
    $cwd = getcwd();
    $originalCwd = $cwd !== false ? $cwd : '';

    try {
        chdir($root);
        ob_start();
        Installer::run(['ai-olympus', 'install', '--force']);
        ob_end_clean();

        expect(file_exists($root . '/CLAUDE.md'))->toBeFalse();
        expect(is_file($root . '/AGENTS.md'))->toBeTrue();
        expect(file_exists(dirname(__DIR__, 2) . '/templates/CLAUDE.md'))->toBeFalse();
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

test('install never touches an existing CLAUDE.md even with force flag', function (): void {
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

test('with Laravel Boost installed the installer writes no root instruction file and no guideline', function (): void {
    $root = installerCreateProjectRoot();
    mkdir($root . '/vendor/laravel/boost', 0777, recursive: true);
    $cwd = getcwd();
    $originalCwd = $cwd !== false ? $cwd : '';

    try {
        chdir($root);
        ob_start();
        Installer::run(['ai-olympus', 'install', '--force']);
        ob_end_clean();

        expect(file_exists($root . '/CLAUDE.md'))->toBeFalse();
        expect(file_exists($root . '/AGENTS.md'))->toBeFalse();
        expect(file_exists($root . '/.ai/guidelines/ai-olympus.md'))->toBeFalse();
    } finally {
        if ($originalCwd !== '') {
            chdir($originalCwd);
        }

        installerRemoveDirectory($root);
    }
});

test('the packaged AGENTS.md carries the deferred gate wording', function (): void {
    $agentsMd = (string) file_get_contents(dirname(__DIR__, 2) . '/AGENTS.md');

    expect($agentsMd)->not->toContain('composer build');
    expect($agentsMd)->toContain('Finalization');
    expect($agentsMd)->toContain('quality-gates.md');
    expect($agentsMd)->not->toContain('Edit the tracked `rules/`');
});
