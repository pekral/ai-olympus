<?php

declare(strict_types = 1);

use AgenticVibes\AgentSkills\CommandResult;
use AgenticVibes\AgentSkills\Installer;
use AgenticVibes\AgentSkills\InstallerBashGuard;
use AgenticVibes\AgentSkills\InstallerFailure;
use AgenticVibes\AgentSkills\InstallerHookSettings;

/**
 * The `--self-test` answer a healthy `bash-guard` binary produces. Every test here fakes the
 * process edge with it instead of spawning a binary, per @rules/code-testing/general.mdc.
 */
function bashHookSelfTestOutput(string $decision = 'deny'): string
{
    return sprintf('{"hookSpecificOutput":{"hookEventName":"PreToolUse","permissionDecision":"%s"}}' . "\n", $decision);
}

/**
 * @return \Closure(list<string>, bool): \AgenticVibes\AgentSkills\CommandResult
 */
function bashHookExecutor(int $exitCode = 0, ?string $output = null): Closure
{
    return static function (array $command, bool $captureOutput) use ($exitCode, $output): CommandResult {
        // Asserted here rather than in one test: every path below depends on the installer really
        // asking the resolved binary for its self-test and reading the answer back. A check that
        // spawned something else, or discarded the output, would prove nothing.
        expect($command)->toHaveCount(3);
        expect($command[1])->toBe('bash-guard');
        expect($command[2])->toBe('--self-test');
        expect($captureOutput)->toBeTrue();

        return new CommandResult($exitCode, $output ?? bashHookSelfTestOutput());
    };
}

function bashHookRoot(): string
{
    return sys_get_temp_dir() . '/agent-skills-hook-' . bin2hex(random_bytes(4));
}

/**
 * Creates a stand-in package tree holding a `bin/agent-skills` file, so a test can drive the
 * binary resolution without touching this package's own checkout.
 */
function bashHookPackageRoot(string $directoryName): string
{
    $packageRoot = sys_get_temp_dir() . '/' . $directoryName . '-' . bin2hex(random_bytes(4));
    installerWriteFile($packageRoot . '/bin/agent-skills', "#!/usr/bin/env php\n");

    return $packageRoot;
}

test('ensureAgentBashBoundaryHook writes one Bash PreToolUse handler with an explicit timeout', function (): void {
    $root = bashHookRoot();

    try {
        expect(InstallerHookSettings::ensureAgentBashBoundaryHook($root, '/opt/agent-skills bash-guard'))->toBeTrue();

        $data = json_decode((string) file_get_contents($root . '/.claude/settings.local.json'), associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
        assert(is_array($data));
        $hooks = $data['hooks'];
        assert(is_array($hooks));
        $groups = $hooks['PreToolUse'];
        assert(is_array($groups) && is_array($groups[0]));

        expect($groups)->toHaveCount(1);
        expect($groups[0]['matcher'])->toBe('Bash');

        $handlers = $groups[0]['hooks'];
        assert(is_array($handlers) && is_array($handlers[0]));

        expect($handlers)->toHaveCount(1);
        expect($handlers[0]['type'])->toBe('command');
        expect($handlers[0]['command'])->toBe('/opt/agent-skills bash-guard');
        // Without an explicit timeout the vendor default is 600 seconds, so a hung validator would
        // stall every Bash call for ten minutes.
        expect($handlers[0]['timeout'])->toBe(InstallerBashGuard::HOOK_TIMEOUT_SECONDS);
    } finally {
        installerRemoveDirectory($root);
    }
});

test('ensureAgentBashBoundaryHook preserves foreign matcher groups, foreign handlers, and the permissions block', function (): void {
    $root = bashHookRoot();
    installerWriteFile($root . '/.claude/settings.local.json', (string) json_encode([
        'theme' => 'dark',
        'permissions' => ['deny' => ['Bash(curl:*)']],
        'hooks' => [
            'PreToolUse' => [
                ['matcher' => 'Write', 'hooks' => [['type' => 'command', 'command' => 'audit-write']]],
                ['matcher' => 'Bash', 'hooks' => [['type' => 'command', 'command' => 'someone-elses-guard']]],
            ],
            'PostToolUse' => [['matcher' => 'Bash', 'hooks' => [['type' => 'command', 'command' => 'after']]]],
        ],
    ], JSON_PRETTY_PRINT));

    try {
        expect(InstallerHookSettings::ensureAgentBashBoundaryHook($root, '/opt/agent-skills bash-guard'))->toBeTrue();

        // The foreign handler keeps its position; ours is appended after it.
        expect(InstallerHookSettings::loadProjectLocalBashHookCommands($root))
            ->toBe(['someone-elses-guard', '/opt/agent-skills bash-guard']);

        $raw = (string) file_get_contents($root . '/.claude/settings.local.json');
        expect($raw)->toContain('"theme": "dark"');
        expect($raw)->toContain('Bash(curl:*)');
        expect($raw)->toContain('audit-write');
        expect($raw)->toContain('PostToolUse');
    } finally {
        installerRemoveDirectory($root);
    }
});

test('ensureAgentBashBoundaryHook is idempotent across two consecutive calls', function (): void {
    $root = bashHookRoot();

    try {
        expect(InstallerHookSettings::ensureAgentBashBoundaryHook($root, '/opt/agent-skills bash-guard'))->toBeTrue();
        expect(InstallerHookSettings::ensureAgentBashBoundaryHook($root, '/opt/agent-skills bash-guard'))->toBeFalse();
        expect(InstallerHookSettings::loadProjectLocalBashHookCommands($root))->toBe(['/opt/agent-skills bash-guard']);
    } finally {
        installerRemoveDirectory($root);
    }
});

test('ensureAgentBashBoundaryHook recovers when the hooks key is the wrong shape', function (): void {
    $root = bashHookRoot();
    installerWriteFile($root . '/.claude/settings.local.json', (string) json_encode(['hooks' => 'not-an-object']));

    try {
        expect(InstallerHookSettings::ensureAgentBashBoundaryHook($root, '/opt/agent-skills bash-guard'))->toBeTrue();
        expect(InstallerHookSettings::loadProjectLocalBashHookCommands($root))->toBe(['/opt/agent-skills bash-guard']);
    } finally {
        installerRemoveDirectory($root);
    }
});

test('ensureAgentBashBoundaryHook recovers when PreToolUse is the wrong shape', function (): void {
    $root = bashHookRoot();
    installerWriteFile($root . '/.claude/settings.local.json', (string) json_encode(['hooks' => ['PreToolUse' => 'not-an-array']]));

    try {
        expect(InstallerHookSettings::ensureAgentBashBoundaryHook($root, '/opt/agent-skills bash-guard'))->toBeTrue();
        expect(InstallerHookSettings::loadProjectLocalBashHookCommands($root))->toBe(['/opt/agent-skills bash-guard']);
    } finally {
        installerRemoveDirectory($root);
    }
});

test('ensureAgentBashBoundaryHook drops non-object items from PreToolUse and from a group handler list', function (): void {
    $root = bashHookRoot();
    installerWriteFile($root . '/.claude/settings.local.json', (string) json_encode([
        'hooks' => ['PreToolUse' => [42, ['matcher' => 'Bash', 'hooks' => ['not-a-handler', null]], null]],
    ]));

    try {
        expect(InstallerHookSettings::ensureAgentBashBoundaryHook($root, '/opt/agent-skills bash-guard'))->toBeTrue();
        expect(InstallerHookSettings::loadProjectLocalBashHookCommands($root))->toBe(['/opt/agent-skills bash-guard']);

        $raw = (string) file_get_contents($root . '/.claude/settings.local.json');
        expect($raw)->not->toContain('not-a-handler');
    } finally {
        installerRemoveDirectory($root);
    }
});

test('ensureAgentBashBoundaryHook recovers when the Bash group carries no handler list at all', function (): void {
    $root = bashHookRoot();
    installerWriteFile($root . '/.claude/settings.local.json', (string) json_encode([
        'hooks' => ['PreToolUse' => [['matcher' => 'Bash']]],
    ]));

    try {
        expect(InstallerHookSettings::ensureAgentBashBoundaryHook($root, '/opt/agent-skills bash-guard'))->toBeTrue();
        expect(InstallerHookSettings::loadProjectLocalBashHookCommands($root))->toBe(['/opt/agent-skills bash-guard']);
    } finally {
        installerRemoveDirectory($root);
    }
});

test('ensureAgentBashBoundaryHook raises InstallerFailure when settings.local.json is malformed JSON', function (): void {
    $root = bashHookRoot();
    installerWriteFile($root . '/.claude/settings.local.json', '{not-valid-json');

    try {
        expect(static fn (): bool => InstallerHookSettings::ensureAgentBashBoundaryHook($root, '/opt/agent-skills bash-guard'))
            ->toThrow(InstallerFailure::class);
    } finally {
        installerRemoveDirectory($root);
    }
});

test('loadProjectLocalBashHookCommands returns an empty list when no settings.local.json exists', function (): void {
    expect(InstallerHookSettings::loadProjectLocalBashHookCommands(bashHookRoot()))->toBe([]);
});

test('loadProjectLocalBashHookCommands ignores handlers that are not command handlers', function (): void {
    $root = bashHookRoot();
    installerWriteFile($root . '/.claude/settings.local.json', (string) json_encode([
        'hooks' => ['PreToolUse' => [['matcher' => 'Bash', 'hooks' => [['type' => 'prompt', 'command' => 'ignored'], ['type' => 'command']]]]],
    ]));

    try {
        expect(InstallerHookSettings::loadProjectLocalBashHookCommands($root))->toBe([]);
    } finally {
        installerRemoveDirectory($root);
    }
});

test('validateAgentBashBoundaryHook passes when the handler is present with its timeout', function (): void {
    $data = json_decode(
        '{"hooks":{"PreToolUse":[{"matcher":"Bash","hooks":[{"type":"command","command":"g bash-guard","timeout":10}]}]}}',
        associative: false,
        depth: 512,
        flags: JSON_THROW_ON_ERROR,
    );

    InstallerHookSettings::validateAgentBashBoundaryHook($data, 'g bash-guard', '/tmp/x');

    expect(value: true)->toBeTrue();
});

test('validateAgentBashBoundaryHook throws when the handler lost its explicit timeout', function (): void {
    $data = json_decode(
        '{"hooks":{"PreToolUse":[{"matcher":"Bash","hooks":[{"type":"command","command":"g bash-guard"}]}]}}',
        associative: false,
        depth: 512,
        flags: JSON_THROW_ON_ERROR,
    );

    expect(static function () use ($data): void {
        InstallerHookSettings::validateAgentBashBoundaryHook($data, 'g bash-guard', '/tmp/x');
    })->toThrow(InstallerFailure::class);
});

test('validateAgentBashBoundaryHook throws when the Bash matcher carries a different command', function (): void {
    $data = json_decode(
        '{"hooks":{"PreToolUse":[{"matcher":"Bash","hooks":[{"type":"command","command":"other","timeout":10}]}]}}',
        associative: false,
        depth: 512,
        flags: JSON_THROW_ON_ERROR,
    );

    expect(static function () use ($data): void {
        InstallerHookSettings::validateAgentBashBoundaryHook($data, 'g bash-guard', '/tmp/x');
    })->toThrow(InstallerFailure::class);
});

test('validateAgentBashBoundaryHook throws when the hooks key is not an object', function (): void {
    $data = json_decode('{"hooks":"not-an-object"}', associative: false, depth: 512, flags: JSON_THROW_ON_ERROR);

    expect(static function () use ($data): void {
        InstallerHookSettings::validateAgentBashBoundaryHook($data, 'g bash-guard', '/tmp/x');
    })->toThrow(InstallerFailure::class);
});

test('validateAgentBashBoundaryHook throws when no Bash matcher group exists', function (): void {
    $data = json_decode(
        '{"hooks":{"PreToolUse":[{"matcher":"Write","hooks":[{"type":"command","command":"g bash-guard","timeout":10}]}]}}',
        associative: false,
        depth: 512,
        flags: JSON_THROW_ON_ERROR,
    );

    expect(static function () use ($data): void {
        InstallerHookSettings::validateAgentBashBoundaryHook($data, 'g bash-guard', '/tmp/x');
    })->toThrow(InstallerFailure::class);
});

test('applyAgentBashBoundaryIfRequested returns false and writes nothing when the flag is not set', function (): void {
    $root = bashHookRoot();

    expect(InstallerHookSettings::applyAgentBashBoundaryIfRequested(enforceAgentBashBoundary: false, projectRoot: $root, processExecutor: null))
        ->toBeFalse();
    expect(is_file($root . '/.claude/settings.local.json'))->toBeFalse();
});

test('applyAgentBashBoundaryIfRequested writes the hook when the smoke test answers deny', function (): void {
    $root = bashHookRoot();

    try {
        expect(
            InstallerHookSettings::applyAgentBashBoundaryIfRequested(
                enforceAgentBashBoundary: true,
                projectRoot: $root,
                processExecutor: bashHookExecutor(),
            ),
        )->toBeTrue();
        expect(InstallerHookSettings::loadProjectLocalBashHookCommands($root))->toHaveCount(1);
    } finally {
        installerRemoveDirectory($root);
    }
});

test('applyAgentBashBoundaryIfRequested refuses to write when it has no way to test-run the binary', function (): void {
    $root = bashHookRoot();

    expect(
        static fn (): bool => InstallerHookSettings::applyAgentBashBoundaryIfRequested(
            enforceAgentBashBoundary: true,
            projectRoot: $root,
            processExecutor: null,
        ),
    )
        ->toThrow(InstallerFailure::class);
    expect(is_file($root . '/.claude/settings.local.json'))->toBeFalse();
});

test('applyAgentBashBoundaryIfRequested writes nothing when the smoke test exits non-zero', function (): void {
    $root = bashHookRoot();

    expect(
        static fn (): bool => InstallerHookSettings::applyAgentBashBoundaryIfRequested(
            enforceAgentBashBoundary: true,
            projectRoot: $root,
            processExecutor: bashHookExecutor(127),
        ),
    )
        ->toThrow(InstallerFailure::class);
    expect(is_file($root . '/.claude/settings.local.json'))->toBeFalse();
});

test('the smoke test refuses every answer that is not a deny decision', function (string $output): void {
    $root = bashHookRoot();

    expect(
        static fn (): bool => InstallerHookSettings::applyAgentBashBoundaryIfRequested(
            enforceAgentBashBoundary: true,
            projectRoot: $root,
            processExecutor: bashHookExecutor(0, $output),
        ),
    )
        ->toThrow(InstallerFailure::class);
    expect(is_file($root . '/.claude/settings.local.json'))->toBeFalse();
})->with([
    'a defer decision' => [bashHookSelfTestOutput('defer')],
    'a JSON scalar' => ['"deny"'],
    'a non-string decision' => ['{"hookSpecificOutput":{"permissionDecision":42}}'],
    'no hookSpecificOutput object' => ['{"hookSpecificOutput":"deny"}'],
    'no output at all' => [''],
    'unparsable output' => ['not json at all {{{'],
]);

test('resolve refuses when neither the project nor the package carries an agent-skills binary', function (): void {
    $root = bashHookRoot();
    $packageRoot = bashHookRoot();

    expect(static fn (): InstallerBashGuard => InstallerBashGuard::resolve($root, $packageRoot))
        ->toThrow(InstallerFailure::class);
});

test('resolve prefers the consuming project vendor binary over the package one', function (): void {
    $root = bashHookRoot();
    installerWriteFile($root . '/vendor/bin/agent-skills', "#!/usr/bin/env php\n");
    $packageRoot = bashHookPackageRoot('agent-skills-package');

    try {
        expect(InstallerBashGuard::resolve($root, $packageRoot)->binary)->toBe($root . '/vendor/bin/agent-skills');
    } finally {
        installerRemoveDirectory($root);
        installerRemoveDirectory($packageRoot);
    }
});

test('resolve single-quotes a binary path that needs shell quoting', function (): void {
    $packageRoot = bashHookPackageRoot('agent skills package');

    try {
        expect(InstallerBashGuard::resolve(bashHookRoot(), $packageRoot)->command)
            ->toBe(sprintf('\'%s/bin/agent-skills\' bash-guard', $packageRoot));
    } finally {
        installerRemoveDirectory($packageRoot);
    }
});

test('resolve refuses a binary path that cannot be quoted safely', function (): void {
    $packageRoot = bashHookPackageRoot('agent\'skills');

    try {
        expect(static fn (): InstallerBashGuard => InstallerBashGuard::resolve(bashHookRoot(), $packageRoot))
            ->toThrow(InstallerFailure::class);
    } finally {
        installerRemoveDirectory($packageRoot);
    }
});

test('install --enforce-agent-bash-boundary writes the hook and tells the user to restart the session', function (): void {
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
        Installer::run(['agent-skills', 'install', '--enforce-agent-bash-boundary'], bashHookExecutor());
        $output = ob_get_clean();

        expect($output)->toContain('Registered the per-agent Bash boundary PreToolUse hook in .claude/settings.local.json.');
        expect($output)->toContain('Restart your Claude Code session');

        $commands = InstallerHookSettings::loadProjectLocalBashHookCommands($root);
        expect($commands)->toHaveCount(1);
        expect($commands[0])->toEndWith(' bash-guard');
        expect($commands[0])->toContain('bin/agent-skills');
    } finally {
        installerRestoreEnvAndCleanup($homeBefore, $originalCwd, $root);
    }
});

test('install without --enforce-agent-bash-boundary writes no hook at all', function (): void {
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
        $output = ob_get_clean();

        expect($output)->not->toContain('Registered the per-agent Bash boundary');
        expect(InstallerHookSettings::loadProjectLocalBashHookCommands($root))->toBe([]);
    } finally {
        installerRestoreEnvAndCleanup($homeBefore, $originalCwd, $root);
    }
});

test('install --enforce-agent-bash-boundary is idempotent and reports nothing on the second run', function (): void {
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
        Installer::run(['agent-skills', 'install', '--enforce-agent-bash-boundary'], bashHookExecutor());
        ob_end_clean();

        ob_start();
        Installer::run(['agent-skills', 'install', '--enforce-agent-bash-boundary'], bashHookExecutor());
        $secondOutput = ob_get_clean();

        expect($secondOutput)->not->toContain('Registered the per-agent Bash boundary');
        expect(InstallerHookSettings::loadProjectLocalBashHookCommands($root))->toHaveCount(1);
    } finally {
        installerRestoreEnvAndCleanup($homeBefore, $originalCwd, $root);
    }
});

test('the hook entry and the two permission flags coexist in one settings.local.json', function (): void {
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
        Installer::run(
            ['agent-skills', 'install', '--allow-subagent-writes', '--deny-network-bash', '--enforce-agent-bash-boundary'],
            bashHookExecutor(),
        );
        ob_end_clean();

        $data = json_decode((string) file_get_contents($root . '/.claude/settings.local.json'), associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
        assert(is_array($data));
        $permissions = $data['permissions'];
        assert(is_array($permissions));
        $allow = $permissions['allow'];
        assert(is_array($allow));

        expect($allow[0])->toStartWith('Edit(/');
        expect($permissions['deny'])->toContain('Bash(curl:*)');
        expect(InstallerHookSettings::loadProjectLocalBashHookCommands($root))->toHaveCount(1);
    } finally {
        installerRestoreEnvAndCleanup($homeBefore, $originalCwd, $root);
    }
});
