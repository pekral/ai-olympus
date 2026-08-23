<?php

declare(strict_types = 1);

use Pekral\AiOlympus\InstallerGlobalSkills;

/**
 * Calls the pruner directly with HOME pointed at an isolated directory, so the branches the
 * end-to-end installer test cannot reach — a symlinked install, a non-directory entry in the
 * skills source, a project checked out inside the home skills directory — get exercised.
 *
 * @param callable(string $source, string $home, string $root): void $arrange
 * @param callable(array<int, string> $removed, string $home, string $root): void $assert
 * @param bool $projectRootIsHome makes the project root the home directory itself, so both
 *                                skills paths resolve to the same directory
 */
function globalSkillsPrune(callable $arrange, callable $assert, bool $projectRootIsHome = false): void
{
    $home = installerCreateProjectRoot();
    $source = installerCreateProjectRoot();
    $root = $projectRootIsHome ? $home : installerCreateProjectRoot();
    $homeEnv = getenv('HOME');
    $homeBefore = $homeEnv !== false && $homeEnv !== '' ? $homeEnv : getenv('USERPROFILE');
    putenv('HOME=' . $home);
    putenv('USERPROFILE=' . $home);

    try {
        $arrange($source, $home, $root);

        $assert(InstallerGlobalSkills::prune($source, $root), $home, $root);
    } finally {
        if ($homeBefore !== false && $homeBefore !== '') {
            putenv('HOME=' . $homeBefore);
            putenv('USERPROFILE=' . $homeBefore);
        } else {
            putenv('HOME');
            putenv('USERPROFILE');
        }

        installerRemoveDirectory($home);
        installerRemoveDirectory($source);

        if (!$projectRootIsHome) {
            installerRemoveDirectory($root);
        }
    }
}

test('prune removes a symlinked install as the link and leaves the tree it points at', function (): void {
    globalSkillsPrune(
        static function (string $source, string $home, string $root): void {
            installerWriteFile($source . '/code-review/SKILL.md', 'source');
            // The checkout the home symlink points at — deleting through the link would destroy it.
            installerWriteFile($root . '/real-skill/SKILL.md', 'the actual checkout');
            installerEnsureDirectory($home . '/.claude/skills');
            symlink($root . '/real-skill', $home . '/.claude/skills/code-review');
        },
        static function (array $removed, string $home, string $root): void {
            expect($removed)->toBe(['code-review']);
            expect(is_link($home . '/.claude/skills/code-review'))->toBeFalse();
            expect(is_file($root . '/real-skill/SKILL.md'))->toBeTrue();
        },
    );
});

test('prune ignores a non-directory entry in the skills source', function (): void {
    globalSkillsPrune(
        static function (string $source, string $home): void {
            installerWriteFile($source . '/README.md', 'not a skill');
            installerWriteFile($source . '/code-review/SKILL.md', 'source');
            installerWriteFile($home . '/.claude/skills/code-review/SKILL.md', 'shadowing copy');
            installerWriteFile($home . '/.claude/skills/README.md', 'a file, never a skill directory');
        },
        static function (array $removed, string $home): void {
            expect($removed)->toBe(['code-review']);
            expect(is_file($home . '/.claude/skills/README.md'))->toBeTrue();
        },
    );
});

test('prune removes nothing when the home skills directory is the project one', function (): void {
    // A checkout living inside the home directory makes both paths resolve to the same place;
    // pruning there would delete the project copy the same run just installed.
    globalSkillsPrune(
        static function (string $source, string $home): void {
            installerWriteFile($source . '/code-review/SKILL.md', 'source');
            installerWriteFile($home . '/.claude/skills/code-review/SKILL.md', 'the project copy itself');
        },
        static function (array $removed, string $home): void {
            expect($removed)->toBe([]);
            expect(is_file($home . '/.claude/skills/code-review/SKILL.md'))->toBeTrue();
        },
        projectRootIsHome: true,
    );
});

test('prune returns nothing when the home skills directory does not exist', function (): void {
    globalSkillsPrune(
        static function (string $source): void {
            installerWriteFile($source . '/code-review/SKILL.md', 'source');
        },
        static function (array $removed): void {
            expect($removed)->toBe([]);
        },
    );
});
