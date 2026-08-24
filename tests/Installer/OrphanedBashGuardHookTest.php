<?php

declare(strict_types = 1);

use Pekral\AiOlympus\Installer;
use Pekral\AiOlympus\InstallerProjectSettings;

/**
 * The handler shape the removed `--enforce-agent-bash-boundary` flag used to write: one command
 * handler pointing at the `bash-guard` subcommand the binary no longer has since #265.
 *
 * The default is the shape the field actually carries. `InstallerBashGuard::buildCommand()` wrote
 * `<binary> bash-guard`, and the binary was `agent-skills` for the whole life of that flag — the
 * rebrand to `ai-olympus` landed five days after `bash-guard` was deleted, so no installer ever
 * wrote the new name. The quoted-path and post-rebrand shapes are exercised as their own dataset
 * below.
 *
 * @return array<string, mixed>
 */
function orphanedBashGuardHandler(string $command = '/project/vendor/bin/agent-skills bash-guard'): array
{
    return ['type' => 'command', 'command' => $command, 'timeout' => 10];
}

/**
 * The full settings shape that flag wrote: a `Bash`-matched group under `hooks.PreToolUse`.
 *
 * @param array<int, mixed> $handlers
 * @return array<string, mixed>
 */
function orphanedBashGuardSettings(array $handlers): array
{
    return ['hooks' => ['PreToolUse' => [['matcher' => 'Bash', 'hooks' => $handlers]]]];
}

/**
 * @param array<string, mixed> $settings
 */
function orphanedBashGuardWriteSettings(string $root, array $settings): string
{
    $settingsPath = $root . '/.claude/settings.local.json';
    installerWriteFile($settingsPath, json_encode($settings, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

    return $settingsPath;
}

/**
 * @return array<array-key, mixed>
 */
function orphanedBashGuardReadSettings(string $settingsPath): array
{
    $decoded = json_decode((string) file_get_contents($settingsPath), associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
    assert(is_array($decoded));

    return $decoded;
}

/**
 * Redirects HOME at the temp project root so a full `install` run never writes to the real home
 * settings file. Restored by `installerRestoreEnvAndCleanup()`, which unsets both variables.
 */
function orphanedBashGuardRedirectHome(string $root): string|false
{
    $homeEnv = getenv('HOME');
    $homeBefore = $homeEnv !== false && $homeEnv !== '' ? $homeEnv : getenv('USERPROFILE');
    putenv('HOME=' . $root);
    putenv('USERPROFILE=' . $root);

    return $homeBefore;
}

test('removeOrphanedBashGuardHandlers deletes the stale handler and prunes the group, the event, and the hooks key', function (): void {
    $root = sys_get_temp_dir() . '/ai-olympus-obg-' . bin2hex(random_bytes(4));
    $settingsPath = orphanedBashGuardWriteSettings($root, orphanedBashGuardSettings([orphanedBashGuardHandler()]));

    try {
        $removed = InstallerProjectSettings::removeOrphanedBashGuardHandlers($root);

        expect($removed)->toBe(1);
        expect(orphanedBashGuardReadSettings($settingsPath))->toBe([]);
    } finally {
        installerRemoveDirectory($root);
    }
});

test('removeOrphanedBashGuardHandlers keeps a foreign handler sitting in the same Bash group', function (): void {
    $root = sys_get_temp_dir() . '/ai-olympus-obg-' . bin2hex(random_bytes(4));
    $foreign = ['type' => 'command', 'command' => 'bin/my-own-guard'];
    $settingsPath = orphanedBashGuardWriteSettings($root, orphanedBashGuardSettings([orphanedBashGuardHandler(), $foreign]));

    try {
        $removed = InstallerProjectSettings::removeOrphanedBashGuardHandlers($root);

        expect($removed)->toBe(1);
        expect(orphanedBashGuardReadSettings($settingsPath))->toBe(orphanedBashGuardSettings([$foreign]));
    } finally {
        installerRemoveDirectory($root);
    }
});

test('removeOrphanedBashGuardHandlers preserves unrelated keys and the permissions block it rewrites around', function (): void {
    $root = sys_get_temp_dir() . '/ai-olympus-obg-' . bin2hex(random_bytes(4));
    $settings = ['theme' => 'dark', 'permissions' => ['deny' => ['Bash(curl:*)']]] + orphanedBashGuardSettings([orphanedBashGuardHandler()]);
    $settingsPath = orphanedBashGuardWriteSettings($root, $settings);

    try {
        $removed = InstallerProjectSettings::removeOrphanedBashGuardHandlers($root);

        expect($removed)->toBe(1);
        expect(orphanedBashGuardReadSettings($settingsPath))->toBe(['theme' => 'dark', 'permissions' => ['deny' => ['Bash(curl:*)']]]);
    } finally {
        installerRemoveDirectory($root);
    }
});

test('removeOrphanedBashGuardHandlers keeps the hooks key when another event still holds a handler', function (): void {
    $root = sys_get_temp_dir() . '/ai-olympus-obg-' . bin2hex(random_bytes(4));
    $postToolUse = [['matcher' => 'Write', 'hooks' => [['type' => 'command', 'command' => 'bin/format']]]];
    $settings = ['hooks' => [
        'PreToolUse' => [['matcher' => 'Bash', 'hooks' => [orphanedBashGuardHandler()]]],
        'PostToolUse' => $postToolUse,
    ],
    ];
    $settingsPath = orphanedBashGuardWriteSettings($root, $settings);

    try {
        $removed = InstallerProjectSettings::removeOrphanedBashGuardHandlers($root);

        expect($removed)->toBe(1);
        expect(orphanedBashGuardReadSettings($settingsPath))->toBe(['hooks' => ['PostToolUse' => $postToolUse]]);
    } finally {
        installerRemoveDirectory($root);
    }
});

test('removeOrphanedBashGuardHandlers strips the stale handler from any hook event, not only PreToolUse', function (): void {
    $root = sys_get_temp_dir() . '/ai-olympus-obg-' . bin2hex(random_bytes(4));
    $settings = ['hooks' => ['PostToolUse' => [['matcher' => 'Bash', 'hooks' => [orphanedBashGuardHandler()]]]]];
    $settingsPath = orphanedBashGuardWriteSettings($root, $settings);

    try {
        $removed = InstallerProjectSettings::removeOrphanedBashGuardHandlers($root);

        expect($removed)->toBe(1);
        expect(orphanedBashGuardReadSettings($settingsPath))->toBe([]);
    } finally {
        installerRemoveDirectory($root);
    }
});

test('removeOrphanedBashGuardHandlers leaves an unrelated empty group in place while stripping another group', function (): void {
    $root = sys_get_temp_dir() . '/ai-olympus-obg-' . bin2hex(random_bytes(4));
    $emptyGroup = ['matcher' => 'Read', 'hooks' => []];
    $settings = ['hooks' => ['PreToolUse' => [
        $emptyGroup,
        ['matcher' => 'Bash', 'hooks' => [orphanedBashGuardHandler()]],
    ],
    ],
    ];
    $settingsPath = orphanedBashGuardWriteSettings($root, $settings);

    try {
        $removed = InstallerProjectSettings::removeOrphanedBashGuardHandlers($root);

        expect($removed)->toBe(1);
        expect(orphanedBashGuardReadSettings($settingsPath))->toBe(['hooks' => ['PreToolUse' => [$emptyGroup]]]);
    } finally {
        installerRemoveDirectory($root);
    }
});

test('removeOrphanedBashGuardHandlers matches a command carrying trailing whitespace', function (): void {
    $root = sys_get_temp_dir() . '/ai-olympus-obg-' . bin2hex(random_bytes(4));
    $handler = orphanedBashGuardHandler("\"\$CLAUDE_PROJECT_DIR\"/vendor/bin/ai-olympus bash-guard \n");
    $settingsPath = orphanedBashGuardWriteSettings($root, orphanedBashGuardSettings([$handler]));

    try {
        $removed = InstallerProjectSettings::removeOrphanedBashGuardHandlers($root);

        expect($removed)->toBe(1);
        expect(orphanedBashGuardReadSettings($settingsPath))->toBe([]);
    } finally {
        installerRemoveDirectory($root);
    }
});

// Every shape `InstallerBashGuard::buildCommand()` could emit: the project's
// `vendor/bin/agent-skills` and the package's own `bin/agent-skills` for a root checkout, each
// single-quoted whenever the path failed the shell-safe pattern. The `ai-olympus` rows were never
// written by an installer — the rebrand renamed the binary five days after `bash-guard` was
// deleted — but a hand-edited file may carry the current name, so they are cleaned up too.
test('removeOrphanedBashGuardHandlers deletes every command shape the removed flag could write', function (string $command): void {
    $root = sys_get_temp_dir() . '/ai-olympus-obg-' . bin2hex(random_bytes(4));
    $settingsPath = orphanedBashGuardWriteSettings($root, orphanedBashGuardSettings([orphanedBashGuardHandler($command)]));

    try {
        $removed = InstallerProjectSettings::removeOrphanedBashGuardHandlers($root);

        expect($removed)->toBe(1);
        expect(orphanedBashGuardReadSettings($settingsPath))->toBe([]);
    } finally {
        installerRemoveDirectory($root);
    }
})->with([
    'the binary resolved from PATH, with no directory at all' => ['agent-skills bash-guard'],
    'the package binary of a root checkout' => ['/src/laravel-agent-skills/bin/agent-skills bash-guard'],
    'the package binary of a root checkout, single-quoted' => ['\'/My Projects/laravel-agent-skills/bin/agent-skills\' bash-guard'],
    'the post-rebrand binary name, single-quoted' => ['\'/My Projects/app/vendor/bin/ai-olympus\' bash-guard'],
    'the post-rebrand binary name, unquoted' => ['/project/vendor/bin/ai-olympus bash-guard'],
    'the project vendor binary, single-quoted around a path with a space' => ['\'/My Projects/app/vendor/bin/agent-skills\' bash-guard'],
    'the project vendor binary, unquoted' => ['/project/vendor/bin/agent-skills bash-guard'],
]);

test('removeOrphanedBashGuardHandlers keeps a handler this package did not write', function (string $command): void {
    $root = sys_get_temp_dir() . '/ai-olympus-obg-' . bin2hex(random_bytes(4));
    $settings = orphanedBashGuardSettings([orphanedBashGuardHandler($command)]);
    $settingsPath = orphanedBashGuardWriteSettings($root, $settings);

    try {
        $removed = InstallerProjectSettings::removeOrphanedBashGuardHandlers($root);

        expect($removed)->toBe(0);
        expect(orphanedBashGuardReadSettings($settingsPath))->toBe($settings);
    } finally {
        installerRemoveDirectory($root);
    }
})->with([
    'a foreign binary whose name merely ends in this package\'s' => ['/project/vendor/bin/my-agent-skills bash-guard'],
    'a guard of the project\'s own' => ['bin/my-own-guard'],
    'a longer subcommand starting with the removed one' => ['/project/vendor/bin/agent-skills bash-guard-report'],
    'the subcommand is not the last thing the command runs' => ['bin/ai-olympus bash-guard --log | tee bash-guard.log'],
    'this package\'s binary without the removed subcommand' => ['/project/vendor/bin/agent-skills install'],
]);

test('removeOrphanedBashGuardHandlers reports nothing and creates no settings file when the project has none', function (): void {
    $root = sys_get_temp_dir() . '/ai-olympus-obg-' . bin2hex(random_bytes(4));

    $removed = InstallerProjectSettings::removeOrphanedBashGuardHandlers($root);

    expect($removed)->toBe(0);
    expect(is_file($root . '/.claude/settings.local.json'))->toBeFalse();
});

test('removeOrphanedBashGuardHandlers leaves a settings file it has nothing to strip byte-identical', function (string $settings): void {
    $root = sys_get_temp_dir() . '/ai-olympus-obg-' . bin2hex(random_bytes(4));
    $settingsPath = $root . '/.claude/settings.local.json';
    installerWriteFile($settingsPath, $settings);

    try {
        $removed = InstallerProjectSettings::removeOrphanedBashGuardHandlers($root);

        expect($removed)->toBe(0);
        expect((string) file_get_contents($settingsPath))->toBe($settings);
    } finally {
        installerRemoveDirectory($root);
    }
})->with([
    'a group carries no handler list' => ['{"hooks":{"PreToolUse":[{"matcher":"Bash"}]}}'],
    'a group is not an object' => ['{"hooks":{"PreToolUse":["Bash"]}}'],
    'a handler carries no command string' => ['{"hooks":{"PreToolUse":[{"matcher":"Bash","hooks":[{"type":"command"}]}]}}'],
    'a handler is not an object' => ['{"hooks":{"PreToolUse":[{"matcher":"Bash","hooks":["bash-guard"]}]}}'],
    'hooks is not an object' => ['{"hooks":"PreToolUse"}'],
    'no hooks key at all' => ['{"permissions":{"deny":["Bash(curl:*)"]}}'],
    'the event list is not an array' => ['{"hooks":{"PreToolUse":"Bash"}}'],
]);

test('install removes the orphaned handler and reports the removal with the session-restart instruction', function (): void {
    $root = installerCreateProjectRoot();
    $settingsPath = orphanedBashGuardWriteSettings($root, orphanedBashGuardSettings([orphanedBashGuardHandler()]));
    $homeBefore = orphanedBashGuardRedirectHome($root);
    $cwd = getcwd();
    $originalCwd = $cwd !== false ? $cwd : '';

    try {
        chdir($root);
        ob_start();
        $exitCode = Installer::run(['ai-olympus', 'install']);
        $output = (string) ob_get_clean();

        expect($exitCode)->toBe(0);
        expect($output)->toContain('Removed 1 orphaned hook handler(s)');
        expect($output)->toContain('.claude/settings.local.json');
        expect($output)->toContain('Restart the session');
        expect(orphanedBashGuardReadSettings($settingsPath))->toBe([]);
    } finally {
        installerRestoreEnvAndCleanup($homeBefore, $originalCwd, $root);
    }
});

test('install reports no hook-handler removal when the project carries none', function (): void {
    $root = installerCreateProjectRoot();
    $homeBefore = orphanedBashGuardRedirectHome($root);
    $cwd = getcwd();
    $originalCwd = $cwd !== false ? $cwd : '';

    try {
        chdir($root);
        ob_start();
        $exitCode = Installer::run(['ai-olympus', 'install']);
        $output = (string) ob_get_clean();

        expect($exitCode)->toBe(0);
        expect($output)->not->toContain('orphaned hook handler');
    } finally {
        installerRestoreEnvAndCleanup($homeBefore, $originalCwd, $root);
    }
});

test('the bash-guard subcommand itself stays removed — install cleans the caller up, it never answers the call', function (): void {
    $exitCode = Installer::run(['ai-olympus', 'bash-guard']);

    expect($exitCode)->toBe(1);
});
