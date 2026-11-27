<?php

declare(strict_types = 1);

use Pekral\AiOlympus\AgentGuardOutcome;
use Pekral\AiOlympus\Installer;
use Pekral\AiOlympus\InstallerAgentGuard;
use Pekral\AiOlympus\InstallerFailure;

const AGENT_GUARD_USER_HANDLER = ['type' => 'command', 'command' => 'bin/my-own-guard'];

/**
 * @return array<string, mixed>
 */
function agentGuardPackageHandler(): array
{
    return ['type' => 'command', 'command' => InstallerAgentGuard::HOOK_COMMAND, 'timeout' => 10, 'onFailure' => 'block'];
}

/**
 * @return array<string, mixed>
 */
function agentGuardUserPermissions(): array
{
    return ['allow' => ['Bash(git status)'], 'deny' => ['Bash(curl:*)', 'Read(./secrets/**)']];
}

/**
 * @param list<array<string, mixed>> $userGroupExtraHandlers
 * @param list<array<string, mixed>> $appendedGroups
 * @return array<string, mixed>
 */
function agentGuardUserSettings(bool $asWritten = true, array $userGroupExtraHandlers = [], array $appendedGroups = []): array
{
    return [
        'attribution' => $asWritten ? new stdClass() : [],
        'hooks' => [
            'PostToolUse' => [['matcher' => 'Write', 'hooks' => [['type' => 'command', 'command' => 'bin/format']]]],
            'PreToolUse' => [['matcher' => 'Bash', 'hooks' => [AGENT_GUARD_USER_HANDLER, ...$userGroupExtraHandlers]], ...$appendedGroups],
        ],
        'permissions' => agentGuardUserPermissions(),
        'theme' => 'dark',
    ];
}

/**
 * @return array<string, mixed>
 */
function agentGuardPackageGroup(): array
{
    return ['matcher' => '*', 'hooks' => [agentGuardPackageHandler()]];
}

function agentGuardSettingsPath(string $root): string
{
    return $root . '/.claude/settings.local.json';
}

/**
 * @param array<string, mixed> $settings
 */
function agentGuardWriteProjectSettings(string $root, array $settings): void
{
    installerWriteFile(agentGuardSettingsPath($root), json_encode($settings, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
}

/**
 * @return array<array-key, mixed>
 */
function agentGuardReadProjectSettings(string $root): array
{
    $decoded = json_decode((string) file_get_contents(agentGuardSettingsPath($root)), associative: true, flags: JSON_THROW_ON_ERROR);
    assert(is_array($decoded));

    return $decoded;
}

function agentGuardInstallScript(string $root, string $body): void
{
    installerWriteFile($root . '/' . InstallerAgentGuard::INSTALLED_SCRIPT, $body);
}

function agentGuardInstallPackageScripts(string $root): void
{
    $shared = dirname(__DIR__, 2) . '/skills/_shared/';
    agentGuardInstallScript($root, (string) file_get_contents($shared . 'agent-guard.sh'));
    chmod($root . '/' . InstallerAgentGuard::INSTALLED_SCRIPT, 0755);
    installerWriteFile($root . '/.claude/skills/_shared/read-manifest.sh', (string) file_get_contents($shared . 'read-manifest.sh'));
}

test('register writes one package handler per event and keeps every other key and handler', function (): void {
    $root = installerCreateProjectRoot();
    agentGuardWriteProjectSettings($root, agentGuardUserSettings());

    try {
        $registered = InstallerAgentGuard::register($root);
        $expected = agentGuardUserSettings(asWritten: false, appendedGroups: [agentGuardPackageGroup()]);
        $expected['hooks']['SessionEnd'] = [agentGuardPackageGroup()];

        expect($registered)->toBeTrue()
            ->and(agentGuardReadProjectSettings($root))->toBe($expected)
            ->and((string) file_get_contents(agentGuardSettingsPath($root)))->toContain('"attribution": {}');
    } finally {
        installerRemoveDirectory($root);
    }
});

test('register replaces an outdated package handler instead of adding a second one', function (): void {
    $root = installerCreateProjectRoot();
    $outdated = ['type' => 'command', 'command' => InstallerAgentGuard::HOOK_COMMAND, 'timeout' => 5];
    agentGuardWriteProjectSettings($root, ['hooks' => ['PreToolUse' => [['matcher' => 'Bash', 'hooks' => [AGENT_GUARD_USER_HANDLER, $outdated]]]]]);

    try {
        InstallerAgentGuard::register($root);

        expect(agentGuardReadProjectSettings($root))->toBe(['hooks' => [
            'PreToolUse' => [
                ['matcher' => 'Bash', 'hooks' => [AGENT_GUARD_USER_HANDLER]],
                agentGuardPackageGroup(),
            ],
            'SessionEnd' => [agentGuardPackageGroup()],
        ],
        ]);
    } finally {
        installerRemoveDirectory($root);
    }
});

test('a second register leaves settings.local.json byte for byte unchanged', function (): void {
    $root = installerCreateProjectRoot();
    agentGuardWriteProjectSettings($root, agentGuardUserSettings());

    try {
        InstallerAgentGuard::register($root);
        $first = (string) file_get_contents(agentGuardSettingsPath($root));
        $again = InstallerAgentGuard::register($root);

        expect($again)->toBeFalse()
            ->and(file_get_contents(agentGuardSettingsPath($root)))->toBe($first);
    } finally {
        installerRemoveDirectory($root);
    }
});

test('apply writes no handler when the self-test of the installed guard fails', function (string $script): void {
    $root = installerCreateProjectRoot();
    agentGuardInstallScript($root, $script);
    agentGuardWriteProjectSettings($root, agentGuardUserSettings());
    $before = (string) file_get_contents(agentGuardSettingsPath($root));

    try {
        $outcome = InstallerAgentGuard::apply(disabled: false, projectRoot: $root);

        expect($outcome)->toBe(AgentGuardOutcome::SelfTestFailed)
            ->and(file_get_contents(agentGuardSettingsPath($root)))->toBe($before);
    } finally {
        installerRemoveDirectory($root);
    }
})->with([
    'a script that is not bash' => ["<?php exit(0);\n)("],
    'a self-test that exits non-zero' => ["#!/usr/bin/env bash\necho 'FAIL  something'\nexit 4\n"],
]);

test('apply writes no handler when no installed guard exists', function (): void {
    $root = installerCreateProjectRoot();

    try {
        expect(InstallerAgentGuard::apply(disabled: false, projectRoot: $root))->toBe(AgentGuardOutcome::SelfTestFailed)
            ->and(file_exists(agentGuardSettingsPath($root)))->toBeFalse();
    } finally {
        installerRemoveDirectory($root);
    }
});

test('apply registers the handler once the self-test of the installed guard passes', function (): void {
    $root = installerCreateProjectRoot();
    agentGuardInstallPackageScripts($root);

    try {
        $first = InstallerAgentGuard::apply(disabled: false, projectRoot: $root);
        $second = InstallerAgentGuard::apply(disabled: false, projectRoot: $root);

        expect($first)->toBe(AgentGuardOutcome::Registered)
            ->and($second)->toBe(AgentGuardOutcome::Unchanged)
            ->and(agentGuardReadProjectSettings($root))->toBe(['hooks' => [
                'PreToolUse' => [agentGuardPackageGroup()],
                'SessionEnd' => [agentGuardPackageGroup()],
            ],
            ]);
    } finally {
        installerRemoveDirectory($root);
    }
});

test('--no-agent-guard removes only the package handler and keeps a user handler of the same matcher group', function (): void {
    $root = installerCreateProjectRoot();
    agentGuardWriteProjectSettings($root, agentGuardUserSettings(userGroupExtraHandlers: [agentGuardPackageHandler()]));

    try {
        $outcome = InstallerAgentGuard::apply(disabled: true, projectRoot: $root);
        $expected = agentGuardUserSettings(asWritten: false);

        expect($outcome)->toBe(AgentGuardOutcome::Removed)
            ->and(agentGuardReadProjectSettings($root))->toBe($expected);
    } finally {
        installerRemoveDirectory($root);
    }
});

test('--no-agent-guard leaves a settings file without the package handler untouched', function (): void {
    $root = installerCreateProjectRoot();
    agentGuardWriteProjectSettings($root, agentGuardUserSettings());
    $before = (string) file_get_contents(agentGuardSettingsPath($root));

    try {
        expect(InstallerAgentGuard::apply(disabled: true, projectRoot: $root))->toBe(AgentGuardOutcome::Absent)
            ->and(file_get_contents(agentGuardSettingsPath($root)))->toBe($before);
    } finally {
        installerRemoveDirectory($root);
    }
});

test('install registers the guard, reports it, and a second install changes nothing', function (): void {
    $root = installerCreateProjectRoot();
    agentGuardWriteProjectSettings($root, agentGuardUserSettings());
    $homeBefore = getenv('HOME');
    putenv('HOME=' . $root);
    $cwd = (string) getcwd();

    try {
        chdir($root);
        ob_start();
        Installer::run(['ai-olympus', 'install']);
        $firstOutput = (string) ob_get_clean();
        $afterFirst = (string) file_get_contents(agentGuardSettingsPath($root));
        ob_start();
        Installer::run(['ai-olympus', 'install']);
        $secondOutput = (string) ob_get_clean();

        expect($firstOutput)->toContain('Registered the runtime agent guard (PreToolUse hook) in .claude/settings.local.json.')
            ->and($secondOutput)->not->toContain('runtime agent guard')
            ->and(file_get_contents(agentGuardSettingsPath($root)))->toBe($afterFirst)
            ->and(agentGuardReadProjectSettings($root))->toHaveKey('permissions', agentGuardUserPermissions());
    } finally {
        installerRestoreEnvAndCleanup($homeBefore, $cwd, $root);
    }
});

test('install --no-agent-guard removes the handler and reports the removal', function (): void {
    $root = installerCreateProjectRoot();
    agentGuardWriteProjectSettings($root, ['hooks' => ['PreToolUse' => [['matcher' => 'Bash', 'hooks' => [agentGuardPackageHandler()]]]]]);
    $homeBefore = getenv('HOME');
    putenv('HOME=' . $root);
    $cwd = (string) getcwd();

    try {
        chdir($root);
        ob_start();
        Installer::run(['ai-olympus', 'install', '--no-agent-guard']);
        $output = (string) ob_get_clean();

        expect($output)->toContain('Removed the runtime agent guard hook from .claude/settings.local.json (--no-agent-guard).')
            ->and(agentGuardReadProjectSettings($root))->toBe([]);
    } finally {
        installerRestoreEnvAndCleanup($homeBefore, $cwd, $root);
    }
});

test('install reports an unregistered guard when the self-test of the installed copy fails', function (): void {
    $root = installerCreateProjectRoot();
    agentGuardInstallScript($root, "#!/usr/bin/env bash\nexit 4\n");
    agentGuardWriteProjectSettings($root, ['theme' => 'dark']);
    $homeBefore = getenv('HOME');
    putenv('HOME=' . $root);
    $cwd = (string) getcwd();

    try {
        chdir($root);
        ob_start();
        Installer::run(['ai-olympus', 'install']);
        $output = (string) ob_get_clean();

        expect($output)->toContain('The runtime agent guard was not registered: its self-test failed.')
            ->and(agentGuardReadProjectSettings($root))->toBe(['theme' => 'dark']);
    } finally {
        installerRestoreEnvAndCleanup($homeBefore, $cwd, $root);
    }
});

test('the read-back check refuses settings without one current handler per event', function (string $settings): void {
    $decoded = json_decode($settings, associative: false, flags: JSON_THROW_ON_ERROR);
    assert($decoded instanceof stdClass);

    expect(static fn () => InstallerAgentGuard::validateRegistered($decoded, '/project/.claude/settings.local.json'))
        ->toThrow(InstallerFailure::class, 'Invalid agent guard hook in /project/.claude/settings.local.json');
})->with([
    'an outdated handler' => [(string) json_encode(
        ['hooks' => ['PreToolUse' => [['matcher' => 'Bash', 'hooks' => [['type' => 'command', 'command' => InstallerAgentGuard::HOOK_COMMAND]]]]]],
    ),
    ],
    'no handler' => ['{}'],
    'two handlers' => [(string) json_encode(
        ['hooks' => ['PreToolUse' => [['matcher' => 'Bash', 'hooks' => [agentGuardPackageHandler(), agentGuardPackageHandler()]]]]],
    ),
    ],
]);

test('the installer help documents --no-agent-guard', function (): void {
    ob_start();
    Installer::run(['ai-olympus']);
    $output = (string) ob_get_clean();

    expect($output)->toContain('--no-agent-guard')
        ->and($output)->toContain('On by default - see SECURITY.md.');
});
