<?php

declare(strict_types = 1);

use Pekral\AiOlympus\Installer;

/**
 * The package is distributed twice — as a Composer plugin and as a Claude Code plugin (issue #261).
 * The second channel is two JSON manifests plus the files under `commands/`, none of which the
 * Composer installer touches, so nothing else in the suite would notice them breaking.
 *
 * Each reader decodes the manifest itself and narrows the result before returning, so no
 * unconstrained value reaches an assertion or a signature.
 */
function pluginManifestValue(string $file, string $path): string
{
    $node = json_decode(pluginManifestContents($file), associative: true, flags: JSON_THROW_ON_ERROR);

    foreach (explode('.', $path) as $segment) {
        expect($node)->toBeArray()->toHaveKey($segment);
        $node = is_array($node) ? $node[$segment] : null;
    }

    expect($node)->toBeString();

    return is_string($node) ? $node : '';
}

/**
 * @return list<string>
 */
function pluginManifestKeys(string $file, string $path = ''): array
{
    $node = json_decode(pluginManifestContents($file), associative: true, flags: JSON_THROW_ON_ERROR);

    foreach ($path === '' ? [] : explode('.', $path) as $segment) {
        expect($node)->toBeArray()->toHaveKey($segment);
        $node = is_array($node) ? $node[$segment] : null;
    }

    expect($node)->toBeArray();

    return is_array($node) ? array_map(strval(...), array_keys($node)) : [];
}

function pluginManifestContents(string $file): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/.claude-plugin/' . $file);
}

test('the marketplace manifest exposes the repository root as a single plugin', function (): void {
    expect(pluginManifestValue('marketplace.json', 'name'))->toBe('ai-olympus');
    expect(pluginManifestValue('marketplace.json', 'owner.name'))->toBe('Petr Král');
    expect(pluginManifestKeys('marketplace.json', 'plugins'))->toBe(['0']);

    // The plugin IS the repository root, which is what keeps the two channels on one version: the
    // plugin ships whatever the git checkout holds, with no second copy to keep in step. Verified
    // against Claude Code 2.1.x rather than assumed — `claude plugin marketplace add <dir>`
    // accepted the manifest and `claude plugin details` listed 54 skills and 4 agents.
    expect(pluginManifestValue('marketplace.json', 'plugins.0.name'))->toBe('ai-olympus');
    expect(pluginManifestValue('marketplace.json', 'plugins.0.source'))->toBe('./');
});

test('the plugin manifest names the package and carries no component-path override', function (): void {
    expect(pluginManifestValue('plugin.json', 'name'))->toBe('ai-olympus');
    expect(pluginManifestValue('plugin.json', 'license'))->toBe('MIT');
    expect(pluginManifestValue('plugin.json', 'repository'))->toBe('https://github.com/pekral/ai-olympus');

    $keys = pluginManifestKeys('plugin.json');

    // skills/, agents/, and commands/ already sit at the plugin root, so Claude Code finds them by
    // its own default scan. A path override here would be a second place to update whenever one of
    // those directories moves.
    //
    // `version` is absent deliberately: the marketplace source is the git checkout, so a number
    // here would be a second one to bump on every release and the first one to go stale.
    //
    // `hooks` is absent for the reason issue #265 restored — agents/*.md and now the plugin ship
    // unconditionally, so a hooks entry would install an active runtime component into every
    // consuming project.
    foreach (['skills', 'agents', 'commands', 'hooks', 'mcpServers', 'version'] as $absent) {
        expect($keys)->not->toContain($absent);
    }
});

test('the plugin ships no runtime component of its own', function (): void {
    $packageDir = dirname(__DIR__, 2);

    expect(file_exists($packageDir . '/hooks'))->toBeFalse();
    expect(file_exists($packageDir . '/.mcp.json'))->toBeFalse();
});

test('the surviving slash command installs into the Claude Code project directory', function (): void {
    $root = installerCreateProjectRoot();
    $cwd = getcwd();
    $originalCwd = $cwd !== false ? $cwd : '';

    try {
        chdir($root);
        ob_start();
        $exitCode = Installer::run(['ai-olympus', 'install']);
        ob_end_clean();

        expect($exitCode)->toBe(0);

        // Before this payload existed, `commands/` reached a project through the plugin marketplace
        // only, so a Composer install left `/prepare-issue-for-merge` unavailable while every skill
        // and agent it delegates to was already installed.
        expect(is_file($root . '/.claude/commands/prepare-issue-for-merge.md'))->toBeTrue();

        // Codex exposes no user-defined slash command — `SlashCommandItem` carries only built-in and
        // service-tier variants — so the same workflow reaches Codex as the skill it delegates to,
        // which `.agents/skills` already installs. A `.codex` copy would be a file nothing reads.
        expect(is_dir($root . '/.codex/commands'))->toBeFalse();
        expect(is_file($root . '/.agents/skills/verify-merge-readiness/SKILL.md'))->toBeTrue();
    } finally {
        if ($originalCwd !== '') {
            chdir($originalCwd);
        }

        installerRemoveDirectory($root);
    }
});

test('commands ships exactly the one command the package still exposes', function (): void {
    $files = glob(dirname(__DIR__, 2) . '/commands/*.md');

    expect($files)->not->toBeFalse();

    $names = array_map(static fn (string $path): string => basename($path), is_array($files) ? $files : []);
    sort($names);

    expect($names)->toBe(['prepare-issue-for-merge.md']);
});

test('both installation paths are documented with the difference between them', function (): void {
    $packageDir = dirname(__DIR__, 2);

    $readme = (string) file_get_contents($packageDir . '/README.md');
    expect($readme)->toContain('/plugin marketplace add pekral/ai-olympus');
    expect($readme)->toContain('### Via the plugin marketplace (no Composer)');
    expect($readme)->toContain('### Via Composer');

    $docs = (string) file_get_contents($packageDir . '/docs/installation.md');
    $section = installerDocsSection($docs, '## Installing without Composer (plugin marketplace)');

    // The honest limitation, not a promise the channel cannot keep. The command that used to carry
    // the rules across is gone, so the section must record that removal and route the reader to
    // Composer, rather than leaving an instruction that no longer resolves.
    expect($section)->toContain('reads **neither `rules/` nor a `CLAUDE.md`**');
    expect($section)->toContain('used to ship a `/ai-olympus:install-rules` command');
    expect($section)->toContain('| Rules (`rules/**`) | ❌ Composer only |');
    expect($section)->toContain('Composer only');
});
