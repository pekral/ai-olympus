<?php

declare(strict_types = 1);

use AgenticVibes\AgentSkills\InstallerPath;

test('resolveRulesSource always uses package directory', function (): void {
    $root = installerCreateProjectRoot();
    $packageDir = dirname(__DIR__, 2);

    try {
        $source = InstallerPath::resolveRulesSource($root);

        expect($source)->toBe($packageDir . '/rules');
    } finally {
        installerRemoveDirectory($root);
    }
});

test('resolveRulesSource ignores rules directory in project root', function (): void {
    $root = installerCreateProjectRoot();
    installerWriteFile($root . '/rules/test.mdc', 'foreign content');
    $packageDir = dirname(__DIR__, 2);

    try {
        $source = InstallerPath::resolveRulesSource($root);

        expect($source)->toBe($packageDir . '/rules');
    } finally {
        installerRemoveDirectory($root);
    }
});

test('resolveSkillsSource always uses package directory', function (): void {
    $root = installerCreateProjectRoot();
    $packageDir = dirname(__DIR__, 2);

    try {
        $source = InstallerPath::resolveSkillsSource();

        expect($source)->toBe($packageDir . '/skills');
    } finally {
        installerRemoveDirectory($root);
    }
});

test('resolveSkillsSource ignores skills directory in project root', function (): void {
    $root = installerCreateProjectRoot();
    installerWriteFile($root . '/skills/test/SKILL.md', 'foreign content');
    $packageDir = dirname(__DIR__, 2);

    try {
        $source = InstallerPath::resolveSkillsSource();

        expect($source)->toBe($packageDir . '/skills');
    } finally {
        installerRemoveDirectory($root);
    }
});

test('resolveSkillsSource falls back to package when development directory does not exist', function (): void {
    $root = sys_get_temp_dir() . '/no-skills-' . bin2hex(random_bytes(4));
    installerEnsureDirectory($root);

    try {
        $result = InstallerPath::resolveSkillsSource();
        $packageDir = dirname(__DIR__, 2);

        expect($result)->toBe($packageDir . '/skills');
    } finally {
        installerRemoveDirectory($root);
    }
});

test('resolveProjectRoot returns current working directory', function (): void {
    $result = InstallerPath::resolveProjectRoot();

    expect($result)->toBeString();
    expect(strlen($result))->toBeGreaterThan(0);
});

test('resolveRulesTargetDirectories always returns .claude/rules', function (): void {
    $targets = InstallerPath::resolveRulesTargetDirectories('/project');

    expect($targets)->toBe(['/project/.claude/rules']);
});

test('resolveSkillsTargetDirectories returns only the project path when --global is not requested', function (): void {
    $homeBefore = getenv('HOME');
    $userProfileBefore = getenv('USERPROFILE');
    putenv('HOME=/fake/home');
    putenv('USERPROFILE=/fake/home');

    try {
        // The home copy would shadow the project one (Claude Code: personal overrides project),
        // so it is never installed unless the caller asks for it.
        $targets = InstallerPath::resolveSkillsTargetDirectories('/project');

        expect($targets)->toBe(['/project/.claude/skills']);
    } finally {
        if ($homeBefore !== false) {
            putenv('HOME=' . $homeBefore);
        } else {
            putenv('HOME');
        }

        if ($userProfileBefore !== false) {
            putenv('USERPROFILE=' . $userProfileBefore);
        } else {
            putenv('USERPROFILE');
        }
    }
});

test('resolveSkillsTargetDirectories returns only the project path when --global is requested but HOME is unset', function (): void {
    $homeBefore = getenv('HOME');
    $userProfileBefore = getenv('USERPROFILE');
    putenv('HOME');
    putenv('USERPROFILE');

    try {
        $targets = InstallerPath::resolveSkillsTargetDirectories('/project', global: true);

        expect($targets)->toBe(['/project/.claude/skills']);
    } finally {
        if ($homeBefore !== false) {
            putenv('HOME=' . $homeBefore);
        }

        if ($userProfileBefore !== false) {
            putenv('USERPROFILE=' . $userProfileBefore);
        }
    }
});

test('resolveSkillsTargetDirectories adds the home skills directory when --global is requested and HOME is set', function (): void {
    $homeBefore = getenv('HOME');
    $userProfileBefore = getenv('USERPROFILE');
    putenv('HOME=/fake/home');
    putenv('USERPROFILE=/fake/home');

    try {
        $targets = InstallerPath::resolveSkillsTargetDirectories('/project', global: true);

        expect($targets)->toBe(['/project/.claude/skills', '/fake/home/.claude/skills']);
    } finally {
        if ($homeBefore !== false) {
            putenv('HOME=' . $homeBefore);
        } else {
            putenv('HOME');
        }

        if ($userProfileBefore !== false) {
            putenv('USERPROFILE=' . $userProfileBefore);
        } else {
            putenv('USERPROFILE');
        }
    }
});

test('isFilesystemRoot returns true for root paths', function (): void {
    $reflection = new ReflectionClass(InstallerPath::class);
    $method = $reflection->getMethod('isFilesystemRoot');

    expect($method->invoke(null, ''))->toBeTrue();
    expect($method->invoke(null, DIRECTORY_SEPARATOR))->toBeTrue();
    expect($method->invoke(null, 'C:'))->toBeTrue();
    expect($method->invoke(null, 'D:\\'))->toBeTrue();
    expect($method->invoke(null, '/home/user'))->toBeFalse();
});

test('findProjectRoot traverses directories up', function (): void {
    $root = installerCreateProjectRoot();
    $subdir = $root . '/deep/nested/path';
    installerEnsureDirectory($subdir);
    $cwd = getcwd();
    $originalCwd = $cwd !== false ? $cwd : '';

    try {
        chdir($subdir);

        $reflection = new ReflectionClass(InstallerPath::class);
        $method = $reflection->getMethod('findProjectRoot');

        $result = $method->invoke(object: null);
        $expectedRoot = realpath($root);
        $expectedRoot = $expectedRoot !== false ? $expectedRoot : $root;

        expect($result)->toBe($expectedRoot);
    } finally {
        if ($originalCwd !== '') {
            chdir($originalCwd);
        }

        installerRemoveDirectory($root);
    }
});

test('resolveHomeDirectoryOrNull returns the HOME value when set', function (): void {
    $homeBefore = getenv('HOME');
    $userProfileBefore = getenv('USERPROFILE');
    putenv('HOME=/tmp/fake-home-' . bin2hex(random_bytes(2)));

    try {
        $home = InstallerPath::resolveHomeDirectoryOrNull();

        expect($home)->toBeString();
        expect($home)->toStartWith('/tmp/fake-home-');
    } finally {
        if ($homeBefore !== false && $homeBefore !== '') {
            putenv('HOME=' . $homeBefore);
        } else {
            putenv('HOME');
        }

        if ($userProfileBefore !== false && $userProfileBefore !== '') {
            putenv('USERPROFILE=' . $userProfileBefore);
        }
    }
});

test('resolveHomeDirectoryOrNull returns null when neither HOME nor USERPROFILE is set', function (): void {
    $homeBefore = getenv('HOME');
    $userProfileBefore = getenv('USERPROFILE');
    putenv('HOME');
    putenv('USERPROFILE');

    try {
        $home = InstallerPath::resolveHomeDirectoryOrNull();

        expect($home)->toBeNull();
    } finally {
        if ($homeBefore !== false && $homeBefore !== '') {
            putenv('HOME=' . $homeBefore);
        }

        if ($userProfileBefore !== false && $userProfileBefore !== '') {
            putenv('USERPROFILE=' . $userProfileBefore);
        }
    }
});
