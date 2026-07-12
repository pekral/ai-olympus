<?php

declare(strict_types = 1);

use AgenticVibes\AgentSkills\Installer;
use AgenticVibes\AgentSkills\InstallerPath;

test('package directory points to correct location', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $rulesDir = $packageDir . '/rules';
    $skillsDir = $packageDir . '/skills';
    $securityRulesDir = $packageDir . '/rules/security';

    expect(is_dir($rulesDir))->toBeTrue();
    expect(is_dir($skillsDir))->toBeTrue();
    expect(is_dir($securityRulesDir))->toBeTrue();
});

test('gitignore ignores local cursor and claude directories', function (): void {
    $gitignore = file_get_contents(dirname(__DIR__, 2) . '/.gitignore');

    expect($gitignore)->toContain('/.cursor/');
    expect($gitignore)->toContain('/.claude/');
});

test('install ignores rules directory in project root and uses package source', function (): void {
    $root = installerCreateProjectRoot();
    installerWriteFile($root . '/rules/example.mdc', 'foreign content');
    $cwd = getcwd();
    $originalCwd = $cwd !== false ? $cwd : '';

    try {
        chdir($root);
        ob_start();
        $exitCode = Installer::run(['agent-skills', 'install']);
        ob_end_clean();

        expect($exitCode)->toBe(0);

        $foreignFile = $root . '/.claude/rules/example.mdc';
        expect(is_file($foreignFile))->toBeFalse();

        $installedDir = $root . '/.claude/rules';
        expect(is_dir($installedDir))->toBeTrue();
    } finally {
        if ($originalCwd !== '') {
            chdir($originalCwd);
        }

        installerRemoveDirectory($root);
    }
});

test('install copies rules from package when no development directory', function (): void {
    $root = installerCreateProjectRoot();
    $cwd = getcwd();
    $originalCwd = $cwd !== false ? $cwd : '';

    try {
        chdir($root);
        ob_start();
        $exitCode = Installer::run(['agent-skills', 'install']);
        ob_end_clean();

        expect($exitCode)->toBe(0);

        $installedDir = $root . '/.claude/rules';
        expect(is_dir($installedDir))->toBeTrue();

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($installedDir, FilesystemIterator::SKIP_DOTS),
        );
        $files = [];

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'mdc') {
                $files[] = $file->getPathname();
            }
        }

        expect(count($files))->toBeGreaterThan(0);
    } finally {
        if ($originalCwd !== '') {
            chdir($originalCwd);
        }

        installerRemoveDirectory($root);
    }
});

test('install respects force flag', function (): void {
    $root = installerCreateProjectRoot();
    $installedFile = $root . '/.claude/rules/php/core-standards.mdc';
    $cwd = getcwd();
    $originalCwd = $cwd !== false ? $cwd : '';

    try {
        chdir($root);

        ob_start();
        Installer::run(['agent-skills', 'install']);
        ob_end_clean();
        $originalContent = file_get_contents($installedFile);
        expect($originalContent)->toBeString();

        file_put_contents($installedFile, 'modified content');

        ob_start();
        Installer::run(['agent-skills', 'install']);
        ob_end_clean();
        expect(file_get_contents($installedFile))->toBe('modified content');

        ob_start();
        Installer::run(['agent-skills', 'install', '--force']);
        ob_end_clean();
        expect(file_get_contents($installedFile))->toBe($originalContent);
    } finally {
        if ($originalCwd !== '') {
            chdir($originalCwd);
        }

        installerRemoveDirectory($root);
    }
});

test('install never overwrites existing project.mdc in target', function (): void {
    $root = installerCreateProjectRoot();
    installerWriteFile($root . '/rules/project.mdc', 'package default content');
    $installedFile = $root . '/.claude/rules/project.mdc';
    installerWriteFile($installedFile, 'my project-specific content');
    $cwd = getcwd();
    $originalCwd = $cwd !== false ? $cwd : '';

    try {
        chdir($root);

        ob_start();
        Installer::run(['agent-skills', 'install']);
        ob_end_clean();
        expect(file_get_contents($installedFile))->toBe('my project-specific content');

        ob_start();
        Installer::run(['agent-skills', 'install', '--force']);
        ob_end_clean();
        expect(file_get_contents($installedFile))->toBe('my project-specific content');
    } finally {
        if ($originalCwd !== '') {
            chdir($originalCwd);
        }

        installerRemoveDirectory($root);
    }
});

test('install creates symlinks when requested', function (): void {
    if (installerSymlinkUnsupported()) {
        expect(value: true)->toBeTrue();

        return;
    }

    $root = installerCreateProjectRoot();
    $cwd = getcwd();
    $originalCwd = $cwd !== false ? $cwd : '';

    try {
        chdir($root);
        ob_start();
        Installer::run(['agent-skills', 'install', '--symlink']);
        ob_end_clean();

        $target = $root . '/.claude/rules/php/core-standards.mdc';

        expect(is_link($target))->toBeTrue();
        expect(file_get_contents($target))->not->toBeEmpty();
    } finally {
        if ($originalCwd !== '') {
            chdir($originalCwd);
        }

        installerRemoveDirectory($root);
    }
});

test('install without --symlink installs regular files via copy fallback, not symlinks', function (): void {
    $root = installerCreateProjectRoot();
    $cwd = getcwd();
    $originalCwd = $cwd !== false ? $cwd : '';

    try {
        chdir($root);
        ob_start();
        Installer::run(['agent-skills', 'install']);
        ob_end_clean();

        $target = $root . '/.claude/rules/php/core-standards.mdc';
        $packageSource = dirname(__DIR__, 2) . '/rules/php/core-standards.mdc';

        expect(is_file($target))->toBeTrue();
        expect(is_link($target))->toBeFalse();
        expect(file_get_contents($target))->toBe(file_get_contents($packageSource));

        $script = $root . '/.claude/skills/code-review-github/scripts/load-issue.sh';
        expect(is_file($script))->toBeTrue();
        expect(is_link($script))->toBeFalse();
        expect(is_executable($script))->toBeTrue();
    } finally {
        if ($originalCwd !== '') {
            chdir($originalCwd);
        }

        installerRemoveDirectory($root);
    }
});

test('install creates regular files (copy fallback), never symlinks, when symlinks are unsupported (Windows-like)', function (): void {
    $root = installerCreateProjectRoot();
    $cwd = getcwd();
    $originalCwd = $cwd !== false ? $cwd : '';

    try {
        chdir($root);
        ob_start();
        Installer::run(['agent-skills', 'install', '--symlink']);
        ob_end_clean();

        $target = $root . '/.claude/rules/php/core-standards.mdc';

        expect(is_file($target))->toBeTrue();
        expect(file_get_contents($target))->not->toBeEmpty();

        if (installerSymlinkUnsupported()) {
            expect(is_link($target))->toBeFalse();
        } else {
            expect(is_link($target))->toBeTrue();
        }
    } finally {
        if ($originalCwd !== '') {
            chdir($originalCwd);
        }

        installerRemoveDirectory($root);
    }
});

test('install copies nested directories', function (): void {
    $root = installerCreateProjectRoot();
    $cwd = getcwd();
    $originalCwd = $cwd !== false ? $cwd : '';

    try {
        chdir($root);
        ob_start();
        Installer::run(['agent-skills', 'install']);
        ob_end_clean();

        $installedFile = $root . '/.claude/rules/laravel/architecture.mdc';

        expect(is_file($installedFile))->toBeTrue();
        expect(file_get_contents($installedFile))->not->toBeEmpty();
    } finally {
        if ($originalCwd !== '') {
            chdir($originalCwd);
        }

        installerRemoveDirectory($root);
    }
});

test('install never creates .cursor or .codex directories', function (): void {
    $root = installerCreateProjectRoot();
    $cwd = getcwd();
    $originalCwd = $cwd !== false ? $cwd : '';

    try {
        chdir($root);
        ob_start();
        Installer::run(['agent-skills', 'install']);
        ob_end_clean();

        expect(is_file($root . '/.claude/rules/php/core-standards.mdc'))->toBeTrue();
        expect(is_file($root . '/.claude/skills/code-review/SKILL.md'))->toBeTrue();
        expect(is_dir($root . '/.cursor'))->toBeFalse();
        expect(is_dir($root . '/.codex'))->toBeFalse();
    } finally {
        if ($originalCwd !== '') {
            chdir($originalCwd);
        }

        installerRemoveDirectory($root);
    }
});

test('install copies skills to project and home target directories', function (): void {
    $root = installerCreateProjectRoot();
    $homeEnv = getenv('HOME');
    $homeBefore = $homeEnv !== false && $homeEnv !== '' ? $homeEnv : getenv('USERPROFILE');
    putenv('HOME=' . $root);

    if (getenv('USERPROFILE') !== false) {
        putenv('USERPROFILE=' . $root);
    }

    $cwd = getcwd();
    $originalCwd = $cwd !== false ? $cwd : '';

    try {
        chdir($root);
        ob_start();
        Installer::run(['agent-skills', 'install']);
        ob_end_clean();

        foreach (InstallerPath::resolveSkillsTargetDirectories($root) as $targetDir) {
            $installedSkill = $targetDir . '/code-review/SKILL.md';
            expect(is_file($installedSkill))->toBeTrue('Skills should be installed to ' . $targetDir);
            expect(file_get_contents($installedSkill))->not->toBeEmpty();
        }
    } finally {
        installerRestoreEnvAndCleanup($homeBefore, $originalCwd, $root);
    }
});

test('install appends output humanization directive to installed skill', function (): void {
    $root = installerCreateProjectRoot();
    $cwd = getcwd();
    $originalCwd = $cwd !== false ? $cwd : '';

    try {
        chdir($root);
        ob_start();
        Installer::run(['agent-skills', 'install']);
        ob_end_clean();

        $installedSkill = $root . '/.claude/skills/code-review/SKILL.md';
        $contents = file_get_contents($installedSkill);

        expect($contents)->toContain('## Output Humanization');
        expect($contents)->toContain(
            '- Use [blader/humanizer](https://github.com/blader/humanizer) for all skill outputs to keep the text natural and human-friendly.',
        );
    } finally {
        if ($originalCwd !== '') {
            chdir($originalCwd);
        }

        installerRemoveDirectory($root);
    }
});

test('install does not duplicate output humanization directive in installed skill', function (): void {
    $root = installerCreateProjectRoot();
    $cwd = getcwd();
    $originalCwd = $cwd !== false ? $cwd : '';

    try {
        chdir($root);
        ob_start();
        Installer::run(['agent-skills', 'install']);
        ob_end_clean();

        $installedSkill = $root . '/.claude/skills/code-review/SKILL.md';
        $contents = file_get_contents($installedSkill);

        expect(substr_count((string) $contents, '## Output Humanization'))->toBe(1);
        expect(substr_count((string) $contents, '[blader/humanizer](https://github.com/blader/humanizer)'))->toBe(1);
    } finally {
        if ($originalCwd !== '') {
            chdir($originalCwd);
        }

        installerRemoveDirectory($root);
    }
});

test('install copies all files to every rule, skill, and agent directory', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $rulesSource = $packageDir . '/rules';
    $skillsSource = $packageDir . '/skills';
    $expectedRulesCount = installerCountFiles($rulesSource);
    $expectedSkillsCount = installerCountFiles($skillsSource);
    $root = installerCreateProjectRoot();
    $homeEnv = getenv('HOME');
    $homeBefore = $homeEnv !== false && $homeEnv !== '' ? $homeEnv : getenv('USERPROFILE');
    putenv('HOME=' . $root);

    if (getenv('USERPROFILE') !== false) {
        putenv('USERPROFILE=' . $root);
    }

    $rulesTargets = InstallerPath::resolveRulesTargetDirectories($root);
    $skillTargets = InstallerPath::resolveSkillsTargetDirectories($root);
    $agentTargets = InstallerPath::resolveAgentsTargetDirectories($root);
    $expectedAgentsCount = installerCountFiles($packageDir . '/agents');
    $claudeMdCount = InstallerPath::resolveClaudeMdSource() !== null ? 1 : 0;
    $expectedTotalFiles = $expectedRulesCount * count($rulesTargets)
        + $expectedSkillsCount * count($skillTargets)
        + $expectedAgentsCount * count($agentTargets)
        + $claudeMdCount;
    $cwd = getcwd();
    $originalCwd = $cwd !== false ? $cwd : '';

    try {
        chdir($root);
        ob_start();
        $exitCode = Installer::run(['agent-skills', 'install']);
        $output = ob_get_clean();

        expect($exitCode)->toBe(0);

        foreach ($rulesTargets as $rulesTarget) {
            $actualRulesCount = installerCountFiles($rulesTarget);
            expect($actualRulesCount)->toBe($expectedRulesCount, 'Rules: all source files in ' . $rulesTarget);
        }

        foreach ($skillTargets as $skillsTarget) {
            $actualSkillsCount = installerCountFiles($skillsTarget);
            expect($actualSkillsCount)->toBe($expectedSkillsCount, 'Skills: all source files in ' . $skillsTarget);
        }

        foreach ($agentTargets as $agentsTarget) {
            $actualAgentsCount = installerCountFiles($agentsTarget);
            expect($actualAgentsCount)->toBe($expectedAgentsCount, 'Agents: all source files in ' . $agentsTarget);
        }

        expect($output)->toContain(sprintf('(%d files,', $expectedTotalFiles));
    } finally {
        installerRestoreEnvAndCleanup($homeBefore, $originalCwd, $root);
    }
});

test('install fails when target path is a file instead of directory', function (): void {
    $root = installerCreateProjectRoot();
    installerWriteFile($root . '/rules/test.mdc', 'content');
    file_put_contents($root . '/.claude', 'blocking file');
    $cwd = getcwd();
    $originalCwd = $cwd !== false ? $cwd : '';

    try {
        chdir($root);
        ob_start();
        $exitCode = Installer::run(['agent-skills', 'install']);
        ob_get_clean();

        expect($exitCode)->toBe(1);
    } finally {
        if ($originalCwd !== '') {
            chdir($originalCwd);
        }

        installerRemoveDirectory($root);
    }
});

test('install fails when destination is directory that cannot be removed', function (): void {
    $root = installerCreateProjectRoot();
    $targetDir = $root . '/.claude/rules/php/core-standards.mdc';
    installerEnsureDirectory($targetDir);
    $cwd = getcwd();
    $originalCwd = $cwd !== false ? $cwd : '';

    try {
        chdir($root);
        ob_start();
        $exitCode = Installer::run(['agent-skills', 'install', '--force']);
        ob_get_clean();

        expect($exitCode)->toBe(1);
    } finally {
        if ($originalCwd !== '') {
            chdir($originalCwd);
        }

        installerRemoveDirectory($root);
    }
});

test('install fails when rules subdirectory path is a file', function (): void {
    $root = installerCreateProjectRoot();
    $targetSubdir = $root . '/.claude/rules/php';
    installerEnsureDirectory(dirname($targetSubdir));
    file_put_contents($targetSubdir, 'blocking file');
    $cwd = getcwd();
    $originalCwd = $cwd !== false ? $cwd : '';

    try {
        chdir($root);
        ob_start();
        $exitCode = Installer::run(['agent-skills', 'install']);
        ob_get_clean();

        expect($exitCode)->toBe(1);
    } finally {
        if ($originalCwd !== '') {
            chdir($originalCwd);
        }

        installerRemoveDirectory($root);
    }
});

test('install copies security rules from rules/security directory', function (): void {
    $root = installerCreateProjectRoot();
    $cwd = getcwd();
    $originalCwd = $cwd !== false ? $cwd : '';

    try {
        chdir($root);
        ob_start();
        Installer::run(['agent-skills', 'install']);
        ob_end_clean();

        $securityDir = $root . '/.claude/rules/security';

        expect(is_file($securityDir . '/backend.md'))->toBeTrue();
        expect(file_get_contents($securityDir . '/backend.md'))->not->toBeEmpty();
        expect(is_file($securityDir . '/frontend.md'))->toBeTrue();
        expect(file_get_contents($securityDir . '/frontend.md'))->not->toBeEmpty();
        expect(is_file($securityDir . '/mobile.md'))->toBeTrue();
        expect(file_get_contents($securityDir . '/mobile.md'))->not->toBeEmpty();
    } finally {
        if ($originalCwd !== '') {
            chdir($originalCwd);
        }

        installerRemoveDirectory($root);
    }
});

test('install always force-copies security rules even without force flag', function (): void {
    $root = installerCreateProjectRoot();
    $cwd = getcwd();
    $originalCwd = $cwd !== false ? $cwd : '';
    $packageDir = dirname(__DIR__, 2);
    $originalSecurityContent = file_get_contents($packageDir . '/rules/security/backend.md');

    try {
        chdir($root);

        ob_start();
        Installer::run(['agent-skills', 'install']);
        ob_end_clean();

        $securityFile = $root . '/.claude/rules/security/backend.md';
        $regularFile = $root . '/.claude/rules/php/core-standards.mdc';

        file_put_contents($securityFile, 'old security content');
        file_put_contents($regularFile, 'old rules content');

        ob_start();
        Installer::run(['agent-skills', 'install']);
        ob_end_clean();

        expect(file_get_contents($securityFile))->toBe($originalSecurityContent);
        expect(file_get_contents($regularFile))->toBe('old rules content');
    } finally {
        if ($originalCwd !== '') {
            chdir($originalCwd);
        }

        installerRemoveDirectory($root);
    }
});

test('install detects a concatenated --force--editor argument and returns exit 1', function (): void {
    $root = installerCreateProjectRoot();
    $cwd = getcwd();
    $originalCwd = $cwd !== false ? $cwd : '';

    try {
        chdir($root);

        ob_start();
        $exitCode = Installer::run(['agent-skills', 'install', '--force--editor=claude']);
        ob_end_clean();

        expect($exitCode)->toBe(1);
        expect(is_dir($root . '/.claude/rules'))->toBeFalse();
    } finally {
        if ($originalCwd !== '') {
            chdir($originalCwd);
        }

        installerRemoveDirectory($root);
    }
});

test('install from package root installs rules and skills into .claude', function (): void {
    $packageRoot = dirname(__DIR__, 2);

    if (!file_exists($packageRoot . '/composer.json') || !is_dir($packageRoot . '/rules')) {
        expect(value: true)->toBeTrue();

        return;
    }

    $cwd = getcwd();
    $originalCwd = $cwd !== false ? $cwd : '';

    try {
        chdir($packageRoot);
        ob_start();
        $exitCode = Installer::run(['agent-skills', 'install']);
        $output = ob_get_clean();

        expect($exitCode)->toBe(0);
        expect($output)->toContain('installed');
        expect(is_dir($packageRoot . '/.claude/rules'))->toBeTrue();
        expect(is_dir($packageRoot . '/.claude/skills'))->toBeTrue();
        expect(installerCountFiles($packageRoot . '/.claude/rules'))->toBeGreaterThan(0);
        expect(installerCountFiles($packageRoot . '/.claude/skills'))->toBeGreaterThan(0);
    } finally {
        if ($originalCwd !== '') {
            chdir($originalCwd);
        }
    }
});

test('install fails when copy fails due to unwritable destination', function (): void {
    if (posix_getuid() === 0) {
        expect(value: true)->toBeTrue();

        return;
    }

    $root = installerCreateProjectRoot();
    $targetDir = $root . '/.claude/rules/php';
    installerEnsureDirectory($targetDir . '/examples');
    chmod($targetDir, 0555);
    $cwd = getcwd();
    $originalCwd = $cwd !== false ? $cwd : '';

    try {
        chdir($root);
        ob_start();
        set_error_handler(static fn (): bool => true);
        $exitCode = Installer::run(['agent-skills', 'install']);
        restore_error_handler();
        ob_get_clean();

        expect($exitCode)->toBe(1);
    } finally {
        chmod($targetDir, 0755);

        if ($originalCwd !== '') {
            chdir($originalCwd);
        }

        installerRemoveDirectory($root);
    }
});

test('install fails when existing file cannot be removed', function (): void {
    if (posix_getuid() === 0) {
        expect(value: true)->toBeTrue();

        return;
    }

    $root = installerCreateProjectRoot();
    $targetDir = $root . '/.claude/rules/php';
    $targetFile = $targetDir . '/core.mdc';
    installerWriteFile($targetFile, 'old content');
    chmod($targetDir, 0555);
    $cwd = getcwd();
    $originalCwd = $cwd !== false ? $cwd : '';

    try {
        chdir($root);
        ob_start();
        set_error_handler(static fn (): bool => true);
        $exitCode = Installer::run(['agent-skills', 'install', '--force']);
        restore_error_handler();
        ob_get_clean();

        expect($exitCode)->toBe(1);
    } finally {
        chmod($targetDir, 0755);

        if ($originalCwd !== '') {
            chdir($originalCwd);
        }

        installerRemoveDirectory($root);
    }
});

test('install preserves executable bit on shipped scripts', function (): void {
    $root = installerCreateProjectRoot();
    $cwd = getcwd();
    $originalCwd = $cwd !== false ? $cwd : '';

    try {
        chdir($root);
        ob_start();
        Installer::run(['agent-skills', 'install']);
        ob_end_clean();

        $installedScripts = [
            $root . '/.claude/skills/code-review-github/scripts/load-issue.sh',
            $root . '/.claude/skills/code-review-jira/scripts/load-issue.sh',
        ];

        foreach ($installedScripts as $script) {
            expect(is_file($script))->toBeTrue($script . ' should exist after install');
            expect(is_executable($script))->toBeTrue($script . ' must be executable after install');
        }
    } finally {
        if ($originalCwd !== '') {
            chdir($originalCwd);
        }

        installerRemoveDirectory($root);
    }
});

test('install with prune on non-existent target directory does nothing', function (): void {
    $root = installerCreateProjectRoot();
    installerWriteFile($root . '/skills/some-skill/SKILL.md', 'content');
    $cwd = getcwd();
    $originalCwd = $cwd !== false ? $cwd : '';

    try {
        chdir($root);
        ob_start();
        $exitCode = Installer::run(['agent-skills', 'install', '--prune']);
        ob_end_clean();

        expect($exitCode)->toBe(0);
    } finally {
        if ($originalCwd !== '') {
            chdir($originalCwd);
        }

        installerRemoveDirectory($root);
    }
});

test('installer never installs the per-project memory file into a target project (issue #626)', function (): void {
    $root = installerCreateProjectRoot();
    $homeEnv = getenv('HOME');
    $homeBefore = $homeEnv !== false && $homeEnv !== '' ? $homeEnv : getenv('USERPROFILE');
    putenv('HOME=' . $root);

    if (getenv('USERPROFILE') !== false) {
        putenv('USERPROFILE=' . $root);
    }

    $cwd = getcwd();
    $originalCwd = $cwd !== false ? $cwd : '';

    try {
        chdir($root);
        ob_start();
        Installer::run(['agent-skills', 'install']);
        ob_end_clean();

        foreach (['.cursor', '.claude', '.codex'] as $editorDir) {
            expect(is_file($root . '/' . $editorDir . '/docs/memory/PROJECT_MEMORY.md'))->toBeFalse();
            expect(is_dir($root . '/' . $editorDir . '/docs/memory'))->toBeFalse();
        }

        expect(is_file($root . '/docs/memory/PROJECT_MEMORY.md'))->toBeFalse();
    } finally {
        installerRestoreEnvAndCleanup($homeBefore, $originalCwd, $root);
    }
});
