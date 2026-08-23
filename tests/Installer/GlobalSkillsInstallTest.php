<?php

declare(strict_types = 1);

use Pekral\AiOlympus\Installer;

/**
 * Runs one installer invocation with HOME pointed at an isolated directory, so the assertions
 * can distinguish the project skills copy from the home one without touching the real home.
 *
 * The assertions run through $assert rather than on a returned value: both directories are
 * removed on the way out, so anything inspecting them has to do so before the cleanup.
 *
 * @param array<int, string> $arguments extra CLI arguments appended after `install`
 * @param (callable(string $home): void)|null $seedHome populates the home skills directory before the run
 * @param callable(array{home: string, root: string, exitCode: int, output: string}): void $assert
 */
function globalSkillsRunInstall(array $arguments, ?callable $seedHome, callable $assert): void
{
    $root = installerCreateProjectRoot();
    $home = installerCreateProjectRoot();
    $homeEnv = getenv('HOME');
    $homeBefore = $homeEnv !== false && $homeEnv !== '' ? $homeEnv : getenv('USERPROFILE');
    putenv('HOME=' . $home);
    putenv('USERPROFILE=' . $home);

    $cwd = getcwd();
    $originalCwd = $cwd !== false ? $cwd : '';

    try {
        if ($seedHome !== null) {
            $seedHome($home);
        }

        chdir($root);
        ob_start();
        $exitCode = Installer::run([...['ai-olympus', 'install'], ...$arguments]);
        $output = (string) ob_get_clean();

        $assert(['home' => $home, 'root' => $root, 'exitCode' => $exitCode, 'output' => $output]);
    } finally {
        installerRestoreEnvAndCleanup($homeBefore, $originalCwd, $root);
        installerRemoveDirectory($home);
    }
}

test('install writes skills only to the project directory by default', function (): void {
    globalSkillsRunInstall([], seedHome: null, assert: function (array $result): void {
        expect(is_file($result['root'] . '/.claude/skills/code-review/SKILL.md'))->toBeTrue();
        // A home copy would override the project one in every project on the machine.
        expect(is_dir($result['home'] . '/.claude/skills'))->toBeFalse();
        expect($result['exitCode'])->toBe(0);
    });
});

test('install --global writes skills to both the project and the home directory', function (): void {
    globalSkillsRunInstall(['--global'], seedHome: null, assert: function (array $result): void {
        expect(is_file($result['root'] . '/.claude/skills/code-review/SKILL.md'))->toBeTrue();
        expect(is_file($result['home'] . '/.claude/skills/code-review/SKILL.md'))->toBeTrue();
        // The report names the real target path, so it cannot claim a copy that was not written.
        expect($result['output'])->toContain($result['home'] . '/.claude/skills');
        expect($result['output'])->toContain('overrides the project copy');
    });
});

test('install --prune-global removes this package\'s skills from home and keeps foreign ones', function (): void {
    $seed = static function (string $home): void {
        installerWriteFile($home . '/.claude/skills/code-review/SKILL.md', 'shadowing copy');
        installerWriteFile($home . '/.claude/skills/code-review/scripts/run.sh', 'echo shadow');
        installerWriteFile($home . '/.claude/skills/foreign-skill/SKILL.md', 'another source');
    };

    globalSkillsRunInstall(['--prune-global'], $seed, function (array $result): void {
        expect(is_dir($result['home'] . '/.claude/skills/code-review'))->toBeFalse();
        expect(is_file($result['home'] . '/.claude/skills/foreign-skill/SKILL.md'))->toBeTrue();
        expect(is_file($result['root'] . '/.claude/skills/code-review/SKILL.md'))->toBeTrue();
        expect($result['output'])->toContain('code-review');
        expect($result['exitCode'])->toBe(0);
    });
});

test('install --prune-global reports when the home skills directory holds nothing to remove', function (): void {
    globalSkillsRunInstall(['--prune-global'], seedHome: null, assert: function (array $result): void {
        expect($result['output'])->toContain('No skills from this package were left');
        expect($result['exitCode'])->toBe(0);
    });
});

test('install --global reports no effect when neither HOME nor USERPROFILE is set', function (): void {
    $root = installerCreateProjectRoot();
    $homeEnv = getenv('HOME');
    $homeBefore = $homeEnv !== false && $homeEnv !== '' ? $homeEnv : getenv('USERPROFILE');
    putenv('HOME');
    putenv('USERPROFILE');

    $cwd = getcwd();
    $originalCwd = $cwd !== false ? $cwd : '';

    try {
        chdir($root);
        ob_start();
        Installer::run(['ai-olympus', 'install', '--global']);
        $output = (string) ob_get_clean();

        // Claiming a home install that never happened would misreport where the skills landed.
        expect($output)->toContain('--global had no effect');
        expect(is_file($root . '/.claude/skills/code-review/SKILL.md'))->toBeTrue();
    } finally {
        installerRestoreEnvAndCleanup($homeBefore, $originalCwd, $root);
    }
});

test('install rejects --global combined with --prune-global', function (): void {
    globalSkillsRunInstall(['--global', '--prune-global'], seedHome: null, assert: function (array $result): void {
        expect($result['exitCode'])->toBe(1);
        // Nothing is installed and nothing is removed when the arguments contradict each other.
        expect(is_dir($result['root'] . '/.claude/skills'))->toBeFalse();
    });
});
