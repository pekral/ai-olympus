<?php

declare(strict_types = 1);

use AgenticVibes\AgentSkills\Installer;
use AgenticVibes\AgentSkills\InstallerClaudeSettings;
use AgenticVibes\AgentSkills\InstallerFailure;

test('InstallerClaudeSettings exposes the two bundled-script permission patterns', function (): void {
    $patterns = InstallerClaudeSettings::getBundledScriptPermissions();

    expect($patterns)->toBe([
        'Bash(*skills/code-review-github/scripts/load-issue.sh:*)',
        'Bash(*skills/code-review-jira/scripts/load-issue.sh:*)',
    ]);
});

test('InstallerClaudeSettings exposes exactly the ten network-Bash deny patterns', function (): void {
    expect(InstallerClaudeSettings::getNetworkBashDenyPermissions())->toBe([
        'Bash(curl:*)',
        'Bash(wget:*)',
        'Bash(nc:*)',
        'Bash(ncat:*)',
        'Bash(netcat:*)',
        'Bash(telnet:*)',
        'Bash(ssh:*)',
        'Bash(scp:*)',
        'Bash(sftp:*)',
        'Bash(openssl s_client:*)',
    ]);
});

test('every network-Bash deny pattern uses the :* trailing-wildcard form and openssl is never denied wholesale', function (): void {
    $patterns = InstallerClaudeSettings::getNetworkBashDenyPermissions();

    foreach ($patterns as $pattern) {
        // The `:*` suffix is only recognised at the end of a pattern, and it is the form
        // getBundledScriptPermissions() already uses — pinned so nobody "fixes" it to ` *`.
        expect($pattern)->toStartWith('Bash(');
        expect($pattern)->toEndWith(':*)');
    }

    // A deny rule cannot carry allow-list exceptions, so a bare openssl deny would also
    // block `openssl dgst` — the checksum verification the repo's own security rules require.
    expect($patterns)->not->toContain('Bash(openssl:*)');
    expect($patterns)->toContain('Bash(openssl s_client:*)');

    // Never deny what the agent roster and `composer build` themselves run through.
    foreach (['Bash(git:*)', 'Bash(gh:*)', 'Bash(bash:*)', 'Bash(sh:*)', 'Bash(env:*)', 'Bash(php:*)', 'Bash(composer:*)'] as $forbidden) {
        expect($patterns)->not->toContain($forbidden);
    }
});

test('InstallerClaudeSettings resolveSettingsPath joins HOME with /.claude/settings.json', function (): void {
    expect(InstallerClaudeSettings::resolveSettingsPath('/tmp/fakehome'))->toBe('/tmp/fakehome/.claude/settings.json');
});

test('ensureBundledScriptPermissions creates a fresh settings.json with both patterns', function (): void {
    $home = sys_get_temp_dir() . '/claude-settings-' . bin2hex(random_bytes(4));

    try {
        $added = InstallerClaudeSettings::ensureBundledScriptPermissions($home);

        expect($added)->toBe(2);

        $settingsPath = $home . '/.claude/settings.json';
        expect(is_file($settingsPath))->toBeTrue();
        expect(InstallerClaudeSettings::loadAllowList($home))
            ->toBe(InstallerClaudeSettings::getBundledScriptPermissions());
    } finally {
        installerRemoveDirectory($home);
    }
});

test('ensureBundledScriptPermissions merges into existing settings.json without dropping unrelated keys', function (): void {
    $home = sys_get_temp_dir() . '/claude-settings-' . bin2hex(random_bytes(4));
    $settingsPath = $home . '/.claude/settings.json';
    installerWriteFile($settingsPath, (string) json_encode([
        'theme' => 'dark',
        'permissions' => [
            'allow' => ['Bash(git status:*)'],
            'deny' => ['Bash(rm -rf:*)'],
        ],
    ], JSON_PRETTY_PRINT));

    try {
        $added = InstallerClaudeSettings::ensureBundledScriptPermissions($home);

        expect($added)->toBe(2);
        expect(InstallerClaudeSettings::loadAllowList($home))->toBe([
            'Bash(git status:*)',
            'Bash(*skills/code-review-github/scripts/load-issue.sh:*)',
            'Bash(*skills/code-review-jira/scripts/load-issue.sh:*)',
        ]);

        $raw = (string) file_get_contents($settingsPath);
        expect($raw)->toContain('"theme": "dark"');
        expect($raw)->toContain('"Bash(rm -rf:*)"');
    } finally {
        installerRemoveDirectory($home);
    }
});

test('ensureBundledScriptPermissions is idempotent on a settings.json that already has both entries', function (): void {
    $home = sys_get_temp_dir() . '/claude-settings-' . bin2hex(random_bytes(4));

    try {
        $firstAdded = InstallerClaudeSettings::ensureBundledScriptPermissions($home);
        $secondAdded = InstallerClaudeSettings::ensureBundledScriptPermissions($home);

        expect($firstAdded)->toBe(2);
        expect($secondAdded)->toBe(0);
    } finally {
        installerRemoveDirectory($home);
    }
});

test('ensureBundledScriptPermissions recovers when permissions key is the wrong shape', function (): void {
    $home = sys_get_temp_dir() . '/claude-settings-' . bin2hex(random_bytes(4));
    $settingsPath = $home . '/.claude/settings.json';
    installerWriteFile($settingsPath, (string) json_encode([
        'permissions' => 'not-an-array',
    ]));

    try {
        $added = InstallerClaudeSettings::ensureBundledScriptPermissions($home);

        expect($added)->toBe(2);
        expect(InstallerClaudeSettings::loadAllowList($home))
            ->toBe(InstallerClaudeSettings::getBundledScriptPermissions());
    } finally {
        installerRemoveDirectory($home);
    }
});

test('ensureBundledScriptPermissions recovers when permissions.allow is the wrong shape', function (): void {
    $home = sys_get_temp_dir() . '/claude-settings-' . bin2hex(random_bytes(4));
    $settingsPath = $home . '/.claude/settings.json';
    installerWriteFile($settingsPath, (string) json_encode([
        'permissions' => ['allow' => 'string-not-array'],
    ]));

    try {
        $added = InstallerClaudeSettings::ensureBundledScriptPermissions($home);

        expect($added)->toBe(2);
        expect(InstallerClaudeSettings::loadAllowList($home))
            ->toBe(InstallerClaudeSettings::getBundledScriptPermissions());
    } finally {
        installerRemoveDirectory($home);
    }
});

test('ensureBundledScriptPermissions drops non-string entries from allow before merging', function (): void {
    $home = sys_get_temp_dir() . '/claude-settings-' . bin2hex(random_bytes(4));
    $settingsPath = $home . '/.claude/settings.json';
    installerWriteFile($settingsPath, (string) json_encode([
        'permissions' => ['allow' => ['Bash(git status:*)', 42, null]],
    ]));

    try {
        InstallerClaudeSettings::ensureBundledScriptPermissions($home);

        expect(InstallerClaudeSettings::loadAllowList($home))->toBe([
            'Bash(git status:*)',
            'Bash(*skills/code-review-github/scripts/load-issue.sh:*)',
            'Bash(*skills/code-review-jira/scripts/load-issue.sh:*)',
        ]);
    } finally {
        installerRemoveDirectory($home);
    }
});

test('ensureBundledScriptPermissions skips when settings.json content is empty whitespace', function (): void {
    $home = sys_get_temp_dir() . '/claude-settings-' . bin2hex(random_bytes(4));
    $settingsPath = $home . '/.claude/settings.json';
    installerWriteFile($settingsPath, "   \n  \n");

    try {
        $added = InstallerClaudeSettings::ensureBundledScriptPermissions($home);

        expect($added)->toBe(2);
        expect(InstallerClaudeSettings::loadAllowList($home))
            ->toBe(InstallerClaudeSettings::getBundledScriptPermissions());
    } finally {
        installerRemoveDirectory($home);
    }
});

test('ensureBundledScriptPermissions raises InstallerFailure when settings.json is malformed JSON', function (): void {
    $home = sys_get_temp_dir() . '/claude-settings-' . bin2hex(random_bytes(4));
    $settingsPath = $home . '/.claude/settings.json';
    installerWriteFile($settingsPath, '{not-valid-json');

    try {
        expect(static fn (): int => InstallerClaudeSettings::ensureBundledScriptPermissions($home))
            ->toThrow(InstallerFailure::class);
    } finally {
        installerRemoveDirectory($home);
    }
});

test('ensureBundledScriptPermissions raises InstallerFailure when top-level value is not an object', function (): void {
    $home = sys_get_temp_dir() . '/claude-settings-' . bin2hex(random_bytes(4));
    $settingsPath = $home . '/.claude/settings.json';
    installerWriteFile($settingsPath, '"a string"');

    try {
        expect(static fn (): int => InstallerClaudeSettings::ensureBundledScriptPermissions($home))
            ->toThrow(InstallerFailure::class);
    } finally {
        installerRemoveDirectory($home);
    }
});

test('ensureBundledScriptPermissions raises InstallerFailure when settings.json target is a directory (write fails)', function (): void {
    $home = sys_get_temp_dir() . '/claude-settings-' . bin2hex(random_bytes(4));
    $settingsPath = $home . '/.claude/settings.json';
    installerEnsureDirectory($settingsPath);

    try {
        expect(static fn (): int => InstallerClaudeSettings::ensureBundledScriptPermissions($home))
            ->toThrow(InstallerFailure::class);
    } finally {
        installerRemoveDirectory($home);
    }
});

test('ensureBundledScriptPermissions raises InstallerFailure when ~/.claude path is a file (mkdir fails)', function (): void {
    $home = sys_get_temp_dir() . '/claude-settings-' . bin2hex(random_bytes(4));
    installerEnsureDirectory($home);
    file_put_contents($home . '/.claude', 'blocker file');

    try {
        expect(static fn (): int => InstallerClaudeSettings::ensureBundledScriptPermissions($home))
            ->toThrow(InstallerFailure::class);
    } finally {
        installerRemoveDirectory($home);
    }
});

test('resolveProjectLocalSettingsPath joins the project root with /.claude/settings.local.json', function (): void {
    expect(InstallerClaudeSettings::resolveProjectLocalSettingsPath('/tmp/project'))->toBe('/tmp/project/.claude/settings.local.json');
});

test('ensureSubagentWritesEnabled writes scoped Edit/Write entries into a fresh settings.local.json', function (): void {
    $root = sys_get_temp_dir() . '/agent-skills-saw-' . bin2hex(random_bytes(4));

    try {
        $written = InstallerClaudeSettings::ensureSubagentWritesEnabled($root);

        expect($written)->toBeTrue();

        $settingsPath = $root . '/.claude/settings.local.json';
        expect(is_file($settingsPath))->toBeTrue();

        $data = json_decode((string) file_get_contents($settingsPath), associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
        assert(is_array($data));
        $permissions = $data['permissions'];
        assert(is_array($permissions));
        expect($permissions['allow'])->toBe([
            sprintf('Edit(/%s/**)', $root),
            sprintf('Write(/%s/**)', $root),
        ]);
    } finally {
        installerRemoveDirectory($root);
    }
});

test('ensureSubagentWritesEnabled prepends to existing allow without dropping unrelated entries', function (): void {
    $root = sys_get_temp_dir() . '/agent-skills-saw-' . bin2hex(random_bytes(4));
    $settingsPath = $root . '/.claude/settings.local.json';
    installerWriteFile($settingsPath, (string) json_encode([
        'theme' => 'dark',
        'permissions' => ['allow' => ['Bash(git status:*)']],
    ], JSON_PRETTY_PRINT));

    try {
        $written = InstallerClaudeSettings::ensureSubagentWritesEnabled($root);

        expect($written)->toBeTrue();

        $data = json_decode((string) file_get_contents($settingsPath), associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
        assert(is_array($data));
        $permissions = $data['permissions'];
        assert(is_array($permissions));
        expect($permissions['allow'])->toBe([
            sprintf('Edit(/%s/**)', $root),
            sprintf('Write(/%s/**)', $root),
            'Bash(git status:*)',
        ]);

        $raw = (string) file_get_contents($settingsPath);
        expect($raw)->toContain('"theme": "dark"');
    } finally {
        installerRemoveDirectory($root);
    }
});

test('ensureSubagentWritesEnabled is idempotent when both entries are already present', function (): void {
    $root = sys_get_temp_dir() . '/agent-skills-saw-' . bin2hex(random_bytes(4));
    $settingsPath = $root . '/.claude/settings.local.json';
    installerWriteFile($settingsPath, (string) json_encode([
        'permissions' => ['allow' => [
            sprintf('Edit(/%s/**)', $root),
            sprintf('Write(/%s/**)', $root),
        ],
        ],
    ], JSON_PRETTY_PRINT));

    try {
        expect(InstallerClaudeSettings::ensureSubagentWritesEnabled($root))->toBeFalse();
    } finally {
        installerRemoveDirectory($root);
    }
});

test('ensureSubagentWritesEnabled adds only the missing entry when one is already present', function (): void {
    $root = sys_get_temp_dir() . '/agent-skills-saw-' . bin2hex(random_bytes(4));
    $settingsPath = $root . '/.claude/settings.local.json';
    installerWriteFile($settingsPath, (string) json_encode([
        'permissions' => ['allow' => [sprintf('Write(/%s/**)', $root)]],
    ], JSON_PRETTY_PRINT));

    try {
        expect(InstallerClaudeSettings::ensureSubagentWritesEnabled($root))->toBeTrue();

        $data = json_decode((string) file_get_contents($settingsPath), associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
        assert(is_array($data));
        $permissions = $data['permissions'];
        assert(is_array($permissions));
        expect($permissions['allow'])->toBe([
            sprintf('Edit(/%s/**)', $root),
            sprintf('Write(/%s/**)', $root),
        ]);
    } finally {
        installerRemoveDirectory($root);
    }
});

test('ensureSubagentWritesEnabled recovers when permissions.allow is the wrong shape and drops non-strings', function (): void {
    $root = sys_get_temp_dir() . '/agent-skills-saw-' . bin2hex(random_bytes(4));
    $settingsPath = $root . '/.claude/settings.local.json';
    installerWriteFile($settingsPath, (string) json_encode([
        'permissions' => ['allow' => ['Bash(git status:*)', 42, null]],
    ]));

    try {
        expect(InstallerClaudeSettings::ensureSubagentWritesEnabled($root))->toBeTrue();

        $data = json_decode((string) file_get_contents($settingsPath), associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
        assert(is_array($data));
        $permissions = $data['permissions'];
        assert(is_array($permissions));
        expect($permissions['allow'])->toBe([
            sprintf('Edit(/%s/**)', $root),
            sprintf('Write(/%s/**)', $root),
            'Bash(git status:*)',
        ]);
    } finally {
        installerRemoveDirectory($root);
    }
});

test('ensureSubagentWritesEnabled recovers when permissions key is the wrong shape', function (): void {
    $root = sys_get_temp_dir() . '/agent-skills-saw-' . bin2hex(random_bytes(4));
    $settingsPath = $root . '/.claude/settings.local.json';
    installerWriteFile($settingsPath, (string) json_encode(['permissions' => 'not-an-object']));

    try {
        expect(InstallerClaudeSettings::ensureSubagentWritesEnabled($root))->toBeTrue();

        $data = json_decode((string) file_get_contents($settingsPath), associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
        assert(is_array($data));
        $permissions = $data['permissions'];
        assert(is_array($permissions));
        expect($permissions['allow'])->toBe([
            sprintf('Edit(/%s/**)', $root),
            sprintf('Write(/%s/**)', $root),
        ]);
    } finally {
        installerRemoveDirectory($root);
    }
});

test('applySubagentWritesIfRequested returns false when the flag is not set', function (): void {
    $root = sys_get_temp_dir() . '/agent-skills-saw-' . bin2hex(random_bytes(4));

    expect(InstallerClaudeSettings::applySubagentWritesIfRequested(allowSubagentWrites: false, projectRoot: $root))->toBeFalse();
    expect(is_file($root . '/.claude/settings.local.json'))->toBeFalse();
});

test('applySubagentWritesIfRequested writes the allow entries when requested', function (): void {
    $root = sys_get_temp_dir() . '/agent-skills-saw-' . bin2hex(random_bytes(4));

    try {
        expect(InstallerClaudeSettings::applySubagentWritesIfRequested(allowSubagentWrites: true, projectRoot: $root))->toBeTrue();
        expect(is_file($root . '/.claude/settings.local.json'))->toBeTrue();
    } finally {
        installerRemoveDirectory($root);
    }
});

test('validateSubagentWritePermissions passes when every required entry is present', function (): void {
    $data = json_decode('{"permissions":{"allow":["Edit(//tmp/p/**)","Write(//tmp/p/**)"]}}', associative: false, depth: 512, flags: JSON_THROW_ON_ERROR);

    InstallerClaudeSettings::validateSubagentWritePermissions($data, ['Edit(//tmp/p/**)', 'Write(//tmp/p/**)'], '/tmp/x');

    expect(value: true)->toBeTrue();
});

test('validateSubagentWritePermissions throws when a required entry is missing', function (): void {
    $data = json_decode('{"permissions":{"allow":["Edit(//tmp/p/**)"]}}', associative: false, depth: 512, flags: JSON_THROW_ON_ERROR);

    expect(static function () use ($data): void {
        InstallerClaudeSettings::validateSubagentWritePermissions($data, ['Edit(//tmp/p/**)', 'Write(//tmp/p/**)'], '/tmp/x');
    })->toThrow(InstallerFailure::class);
});

test('ensureNetworkBashDenyPermissions writes every pattern into a fresh settings.local.json', function (): void {
    $root = sys_get_temp_dir() . '/agent-skills-dnb-' . bin2hex(random_bytes(4));

    try {
        expect(InstallerClaudeSettings::ensureNetworkBashDenyPermissions($root))->toBeTrue();

        $settingsPath = $root . '/.claude/settings.local.json';
        expect(is_file($settingsPath))->toBeTrue();
        expect(InstallerClaudeSettings::loadProjectLocalDenyList($root))
            ->toBe(InstallerClaudeSettings::getNetworkBashDenyPermissions());
    } finally {
        installerRemoveDirectory($root);
    }
});

test('ensureNetworkBashDenyPermissions preserves existing allow entries and foreign deny entries', function (): void {
    $root = sys_get_temp_dir() . '/agent-skills-dnb-' . bin2hex(random_bytes(4));
    $settingsPath = $root . '/.claude/settings.local.json';
    installerWriteFile($settingsPath, (string) json_encode([
        'theme' => 'dark',
        'permissions' => [
            'allow' => ['Bash(git status:*)'],
            'deny' => ['Bash(rm -rf:*)'],
        ],
    ], JSON_PRETTY_PRINT));

    try {
        expect(InstallerClaudeSettings::ensureNetworkBashDenyPermissions($root))->toBeTrue();

        // The foreign deny entry keeps its position; the package's patterns are appended after it.
        expect(InstallerClaudeSettings::loadProjectLocalDenyList($root))
            ->toBe(['Bash(rm -rf:*)', ...InstallerClaudeSettings::getNetworkBashDenyPermissions()]);

        $data = json_decode((string) file_get_contents($settingsPath), associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
        assert(is_array($data));
        $permissions = $data['permissions'];
        assert(is_array($permissions));
        expect($permissions['allow'])->toBe(['Bash(git status:*)']);

        $raw = (string) file_get_contents($settingsPath);
        expect($raw)->toContain('"theme": "dark"');
    } finally {
        installerRemoveDirectory($root);
    }
});

test('ensureNetworkBashDenyPermissions is idempotent across two consecutive calls', function (): void {
    $root = sys_get_temp_dir() . '/agent-skills-dnb-' . bin2hex(random_bytes(4));

    try {
        expect(InstallerClaudeSettings::ensureNetworkBashDenyPermissions($root))->toBeTrue();
        expect(InstallerClaudeSettings::ensureNetworkBashDenyPermissions($root))->toBeFalse();
        expect(InstallerClaudeSettings::loadProjectLocalDenyList($root))
            ->toBe(InstallerClaudeSettings::getNetworkBashDenyPermissions());
    } finally {
        installerRemoveDirectory($root);
    }
});

test('ensureNetworkBashDenyPermissions adds only the missing patterns and drops non-strings', function (): void {
    $root = sys_get_temp_dir() . '/agent-skills-dnb-' . bin2hex(random_bytes(4));
    $settingsPath = $root . '/.claude/settings.local.json';
    installerWriteFile($settingsPath, (string) json_encode([
        'permissions' => ['deny' => ['Bash(curl:*)', 42, null]],
    ]));

    try {
        expect(InstallerClaudeSettings::ensureNetworkBashDenyPermissions($root))->toBeTrue();
        expect(InstallerClaudeSettings::loadProjectLocalDenyList($root))
            ->toBe(InstallerClaudeSettings::getNetworkBashDenyPermissions());
    } finally {
        installerRemoveDirectory($root);
    }
});

test('ensureNetworkBashDenyPermissions recovers when permissions.deny is the wrong shape', function (): void {
    $root = sys_get_temp_dir() . '/agent-skills-dnb-' . bin2hex(random_bytes(4));
    $settingsPath = $root . '/.claude/settings.local.json';
    installerWriteFile($settingsPath, (string) json_encode(['permissions' => ['deny' => 'string-not-array']]));

    try {
        expect(InstallerClaudeSettings::ensureNetworkBashDenyPermissions($root))->toBeTrue();
        expect(InstallerClaudeSettings::loadProjectLocalDenyList($root))
            ->toBe(InstallerClaudeSettings::getNetworkBashDenyPermissions());
    } finally {
        installerRemoveDirectory($root);
    }
});

test('ensureNetworkBashDenyPermissions recovers when the permissions key is the wrong shape', function (): void {
    $root = sys_get_temp_dir() . '/agent-skills-dnb-' . bin2hex(random_bytes(4));
    $settingsPath = $root . '/.claude/settings.local.json';
    installerWriteFile($settingsPath, (string) json_encode(['permissions' => 'not-an-object']));

    try {
        expect(InstallerClaudeSettings::ensureNetworkBashDenyPermissions($root))->toBeTrue();
        expect(InstallerClaudeSettings::loadProjectLocalDenyList($root))
            ->toBe(InstallerClaudeSettings::getNetworkBashDenyPermissions());
    } finally {
        installerRemoveDirectory($root);
    }
});

test('ensureNetworkBashDenyPermissions raises InstallerFailure when settings.local.json is malformed JSON', function (): void {
    $root = sys_get_temp_dir() . '/agent-skills-dnb-' . bin2hex(random_bytes(4));
    installerWriteFile($root . '/.claude/settings.local.json', '{not-valid-json');

    try {
        expect(static fn (): bool => InstallerClaudeSettings::ensureNetworkBashDenyPermissions($root))
            ->toThrow(InstallerFailure::class);
    } finally {
        installerRemoveDirectory($root);
    }
});

test('loadProjectLocalDenyList returns an empty list when no settings.local.json exists', function (): void {
    expect(InstallerClaudeSettings::loadProjectLocalDenyList(sys_get_temp_dir() . '/agent-skills-dnb-' . bin2hex(random_bytes(4))))->toBe([]);
});

test('applyNetworkBashDenyIfRequested returns false and writes nothing when the flag is not set', function (): void {
    $root = sys_get_temp_dir() . '/agent-skills-dnb-' . bin2hex(random_bytes(4));

    expect(InstallerClaudeSettings::applyNetworkBashDenyIfRequested(denyNetworkBash: false, projectRoot: $root))->toBeFalse();
    expect(is_file($root . '/.claude/settings.local.json'))->toBeFalse();
});

test('applyNetworkBashDenyIfRequested writes the deny entries when requested', function (): void {
    $root = sys_get_temp_dir() . '/agent-skills-dnb-' . bin2hex(random_bytes(4));

    try {
        expect(InstallerClaudeSettings::applyNetworkBashDenyIfRequested(denyNetworkBash: true, projectRoot: $root))->toBeTrue();
        expect(InstallerClaudeSettings::loadProjectLocalDenyList($root))
            ->toBe(InstallerClaudeSettings::getNetworkBashDenyPermissions());
    } finally {
        installerRemoveDirectory($root);
    }
});

test('validateNetworkBashDenyPermissions passes when every required entry is present', function (): void {
    $data = json_decode('{"permissions":{"deny":["Bash(curl:*)","Bash(wget:*)"]}}', associative: false, depth: 512, flags: JSON_THROW_ON_ERROR);

    InstallerClaudeSettings::validateNetworkBashDenyPermissions($data, ['Bash(curl:*)', 'Bash(wget:*)'], '/tmp/x');

    expect(value: true)->toBeTrue();
});

test('validateNetworkBashDenyPermissions throws when a required entry is missing', function (): void {
    $data = json_decode('{"permissions":{"deny":["Bash(curl:*)"]}}', associative: false, depth: 512, flags: JSON_THROW_ON_ERROR);

    expect(static function () use ($data): void {
        InstallerClaudeSettings::validateNetworkBashDenyPermissions($data, ['Bash(curl:*)', 'Bash(wget:*)'], '/tmp/x');
    })->toThrow(InstallerFailure::class);
});

test('validateNetworkBashDenyPermissions throws when the permissions key is not an object', function (): void {
    $data = json_decode('{"permissions":"not-an-object"}', associative: false, depth: 512, flags: JSON_THROW_ON_ERROR);

    expect(static function () use ($data): void {
        InstallerClaudeSettings::validateNetworkBashDenyPermissions($data, ['Bash(curl:*)'], '/tmp/x');
    })->toThrow(InstallerFailure::class);
});

test('validateNetworkBashDenyPermissions throws when permissions.deny is not an array', function (): void {
    $data = json_decode('{"permissions":{"deny":"not-an-array"}}', associative: false, depth: 512, flags: JSON_THROW_ON_ERROR);

    expect(static function () use ($data): void {
        InstallerClaudeSettings::validateNetworkBashDenyPermissions($data, ['Bash(curl:*)'], '/tmp/x');
    })->toThrow(InstallerFailure::class);
});

test('install --deny-network-bash writes the deny entries and reports it', function (): void {
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
        Installer::run(['agent-skills', 'install', '--deny-network-bash']);
        $output = ob_get_clean();

        expect($output)->toContain('Denied outbound-network Bash commands');
        expect($output)->toContain('session-wide in .claude/settings.local.json.');
        expect(InstallerClaudeSettings::loadProjectLocalDenyList($root))
            ->toBe(InstallerClaudeSettings::getNetworkBashDenyPermissions());
    } finally {
        installerRestoreEnvAndCleanup($homeBefore, $originalCwd, $root);
    }
});

test('install without --deny-network-bash writes no deny entry at all', function (): void {
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

        expect($output)->not->toContain('Denied outbound-network Bash commands');
        expect(InstallerClaudeSettings::loadProjectLocalDenyList($root))->toBe([]);
    } finally {
        installerRestoreEnvAndCleanup($homeBefore, $originalCwd, $root);
    }
});

test('install --deny-network-bash is idempotent and reports nothing on the second run', function (): void {
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
        Installer::run(['agent-skills', 'install', '--deny-network-bash']);
        ob_end_clean();

        ob_start();
        Installer::run(['agent-skills', 'install', '--deny-network-bash']);
        $secondOutput = ob_get_clean();

        expect($secondOutput)->not->toContain('Denied outbound-network Bash commands');
        expect(InstallerClaudeSettings::loadProjectLocalDenyList($root))
            ->toBe(InstallerClaudeSettings::getNetworkBashDenyPermissions());
    } finally {
        installerRestoreEnvAndCleanup($homeBefore, $originalCwd, $root);
    }
});

test('install --deny-network-bash and --allow-subagent-writes coexist in one settings.local.json', function (): void {
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
        Installer::run(['agent-skills', 'install', '--allow-subagent-writes', '--deny-network-bash']);
        ob_end_clean();

        $data = json_decode((string) file_get_contents($root . '/.claude/settings.local.json'), associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
        assert(is_array($data));
        $permissions = $data['permissions'];
        assert(is_array($permissions));
        $allow = $permissions['allow'];
        assert(is_array($allow));
        expect($allow[0])->toStartWith('Edit(/');
        expect($permissions['deny'])->toBe(InstallerClaudeSettings::getNetworkBashDenyPermissions());
    } finally {
        installerRestoreEnvAndCleanup($homeBefore, $originalCwd, $root);
    }
});

test('install --allow-bundled-scripts writes the permissions and reports them', function (): void {
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
        Installer::run(['agent-skills', 'install', '--allow-bundled-scripts']);
        $output = ob_get_clean();

        expect($output)->toContain('Allowed 2 bundled-script permission(s) in ~/.claude/settings.json.');

        $settingsPath = $root . '/.claude/settings.json';
        expect(is_file($settingsPath))->toBeTrue();
        expect(InstallerClaudeSettings::loadAllowList($root))
            ->toBe(InstallerClaudeSettings::getBundledScriptPermissions());
    } finally {
        installerRestoreEnvAndCleanup($homeBefore, $originalCwd, $root);
    }
});

test('install without --allow-bundled-scripts still disables AI co-author attribution', function (): void {
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

        expect($output)->not->toContain('Allowed');
        expect($output)->toContain('Disabled AI co-author attribution (includeCoAuthoredBy: false) in ~/.claude/settings.json.');

        $settingsPath = $root . '/.claude/settings.json';
        expect(is_file($settingsPath))->toBeTrue();

        $data = json_decode((string) file_get_contents($settingsPath), associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
        assert(is_array($data));
        expect($data['includeCoAuthoredBy'])->toBeFalse();
    } finally {
        installerRestoreEnvAndCleanup($homeBefore, $originalCwd, $root);
    }
});

test('install --allow-subagent-writes writes the allow entries and reports it', function (): void {
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
        Installer::run(['agent-skills', 'install', '--allow-subagent-writes']);
        $output = ob_get_clean();

        expect($output)->toContain('Allowed subagent file writes (Edit/Write on the working tree) in .claude/settings.local.json.');

        $settingsPath = $root . '/.claude/settings.local.json';
        expect(is_file($settingsPath))->toBeTrue();

        $data = json_decode((string) file_get_contents($settingsPath), associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
        assert(is_array($data));
        $permissions = $data['permissions'];
        assert(is_array($permissions));
        $allow = $permissions['allow'];
        assert(is_array($allow));
        expect($allow[0])->toStartWith('Edit(/');
        expect($allow[1])->toStartWith('Write(/');
        expect($allow[0])->toEndWith('/**)');
        expect($allow[1])->toEndWith('/**)');
    } finally {
        installerRestoreEnvAndCleanup($homeBefore, $originalCwd, $root);
    }
});

test('install without --allow-subagent-writes does not write settings.local.json', function (): void {
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

        expect($output)->not->toContain('Allowed subagent file writes');
        expect(is_file($root . '/.claude/settings.local.json'))->toBeFalse();
    } finally {
        installerRestoreEnvAndCleanup($homeBefore, $originalCwd, $root);
    }
});

test('install --allow-bundled-scripts with HOME unset is a no-op for settings.json', function (): void {
    $root = installerCreateProjectRoot();
    $homeBefore = getenv('HOME');
    $userProfileBefore = getenv('USERPROFILE');
    putenv('HOME');
    putenv('USERPROFILE');

    $cwd = getcwd();
    $originalCwd = $cwd !== false ? $cwd : '';

    try {
        chdir($root);
        ob_start();
        Installer::run(['agent-skills', 'install', '--allow-bundled-scripts']);
        $output = ob_get_clean();

        expect($output)->not->toContain('Allowed');
    } finally {
        if ($homeBefore !== false && $homeBefore !== '') {
            putenv('HOME=' . $homeBefore);
        }

        if ($userProfileBefore !== false && $userProfileBefore !== '') {
            putenv('USERPROFILE=' . $userProfileBefore);
        }

        if ($originalCwd !== '') {
            chdir($originalCwd);
        }

        installerRemoveDirectory($root);
    }
});

test('install --allow-bundled-scripts is idempotent across two consecutive runs', function (): void {
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
        Installer::run(['agent-skills', 'install', '--allow-bundled-scripts']);
        ob_end_clean();

        ob_start();
        Installer::run(['agent-skills', 'install', '--allow-bundled-scripts']);
        $secondOutput = ob_get_clean();

        expect($secondOutput)->not->toContain('Allowed');
        expect(InstallerClaudeSettings::loadAllowList($root))
            ->toBe(InstallerClaudeSettings::getBundledScriptPermissions());
    } finally {
        installerRestoreEnvAndCleanup($homeBefore, $originalCwd, $root);
    }
});

test('ensureCoAuthoredByDisabled writes includeCoAuthoredBy false into a fresh settings.json', function (): void {
    $home = sys_get_temp_dir() . '/claude-settings-' . bin2hex(random_bytes(4));

    try {
        $written = InstallerClaudeSettings::ensureCoAuthoredByDisabled($home);

        expect($written)->toBeTrue();

        $settingsPath = $home . '/.claude/settings.json';
        expect(is_file($settingsPath))->toBeTrue();

        $data = json_decode((string) file_get_contents($settingsPath), associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
        assert(is_array($data));
        expect($data['includeCoAuthoredBy'])->toBeFalse();
    } finally {
        installerRemoveDirectory($home);
    }
});

test('ensureCoAuthoredByDisabled respects an existing includeCoAuthoredBy value', function (): void {
    $home = sys_get_temp_dir() . '/claude-settings-' . bin2hex(random_bytes(4));
    $settingsPath = $home . '/.claude/settings.json';
    installerWriteFile($settingsPath, (string) json_encode(['includeCoAuthoredBy' => true]));

    try {
        $written = InstallerClaudeSettings::ensureCoAuthoredByDisabled($home);

        expect($written)->toBeFalse();

        $data = json_decode((string) file_get_contents($settingsPath), associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
        assert(is_array($data));
        expect($data['includeCoAuthoredBy'])->toBeTrue();
    } finally {
        installerRemoveDirectory($home);
    }
});

test('ensureCoAuthoredByDisabled merges into existing settings.json without dropping unrelated keys', function (): void {
    $home = sys_get_temp_dir() . '/claude-settings-' . bin2hex(random_bytes(4));
    $settingsPath = $home . '/.claude/settings.json';
    installerWriteFile($settingsPath, (string) json_encode([
        'theme' => 'dark',
        'permissions' => ['allow' => ['Bash(git status:*)']],
    ], JSON_PRETTY_PRINT));

    try {
        $written = InstallerClaudeSettings::ensureCoAuthoredByDisabled($home);

        expect($written)->toBeTrue();

        $raw = (string) file_get_contents($settingsPath);
        expect($raw)->toContain('"theme": "dark"');
        expect($raw)->toContain('"Bash(git status:*)"');
        expect($raw)->toContain('"includeCoAuthoredBy": false');
    } finally {
        installerRemoveDirectory($home);
    }
});

test('ensureCoAuthoredByDisabled preserves empty JSON objects elsewhere in settings.json', function (): void {
    $home = sys_get_temp_dir() . '/claude-settings-' . bin2hex(random_bytes(4));
    $settingsPath = $home . '/.claude/settings.json';
    installerWriteFile($settingsPath, '{"attribution": {}}');

    try {
        InstallerClaudeSettings::ensureCoAuthoredByDisabled($home);

        $raw = (string) file_get_contents($settingsPath);
        expect($raw)->toContain('"attribution": {}');
        expect($raw)->not->toContain('"attribution": []');

        $decoded = json_decode($raw, associative: false, depth: 512, flags: JSON_THROW_ON_ERROR);
        assert($decoded instanceof stdClass);
        expect($decoded->attribution)->toBeInstanceOf(stdClass::class);
    } finally {
        installerRemoveDirectory($home);
    }
});

test('ensureBundledScriptPermissions preserves empty JSON objects elsewhere in settings.json', function (): void {
    $home = sys_get_temp_dir() . '/claude-settings-' . bin2hex(random_bytes(4));
    $settingsPath = $home . '/.claude/settings.json';
    installerWriteFile($settingsPath, '{"attribution": {}}');

    try {
        InstallerClaudeSettings::ensureBundledScriptPermissions($home);

        $raw = (string) file_get_contents($settingsPath);
        expect($raw)->toContain('"attribution": {}');
        expect($raw)->not->toContain('"attribution": []');
    } finally {
        installerRemoveDirectory($home);
    }
});
