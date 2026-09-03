<?php

declare(strict_types = 1);

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

test('the install-rules command copies what the plugin channel cannot load by itself', function (): void {
    $command = (string) file_get_contents(dirname(__DIR__, 2) . '/commands/install-rules.md');

    // Claude Code reads skills/ and agents/ out of a plugin directory but neither rules/ nor a
    // CLAUDE.md, so this command is the only way the marketplace path delivers them. A rewrite that
    // drops either copy silently reduces the plugin to skills and agents.
    expect($command)->toContain('${CLAUDE_PLUGIN_ROOT}/rules/');
    expect($command)->toContain('${CLAUDE_PLUGIN_ROOT}/CLAUDE.md');
    expect($command)->toContain('.claude/rules/');

    // The same guarantee the Composer installer carries: CLAUDE.md is the file a team customises.
    expect($command)->toContain('only when the project has no');
    expect($command)->toContain('Never overwrite an existing one');

    // The opt-in security switches stay bound to the Composer path (issue #261, owner's answer).
    expect($command)->toContain('writes no `.claude/settings.local.json` entry');
});

test('the finalize-tasks command carries its argument and its own-comments guard', function (): void {
    $command = (string) file_get_contents(dirname(__DIR__, 2) . '/commands/finalize-tasks.md');

    // The tracker link is the one input the command cannot work without, and Claude Code passes it
    // only through `$ARGUMENTS`. A rewrite that drops the placeholder still reads like a working
    // command while silently discarding the link the caller typed.
    expect($command)->toContain('argument-hint: [issue or PR link(s)]');
    expect($command)->toContain('$ARGUMENTS');
    expect($command)->toContain('stop and ask me for it');

    // The consolidation step edits and deletes tracker comments, so the guard that keeps it off
    // somebody else's comment is the safety-relevant half of the file. Nothing else in the suite
    // reads it, and a destructive write is not a failure a later run can undo.
    expect($command)->toContain('Never edit and never delete a comment written by anybody else');
    expect($command)->toContain('gh api user --jq .login');
    expect($command)->toContain('acli jira auth status');

    // JIRA hands back a display name where GitHub hands back a login, so the guard fails safe
    // instead of matching on the weaker of the two.
    expect($command)->toContain('corroboration and never proof');
    expect($command)->toContain('delete nothing');
});

test('both installation paths are documented with the difference between them', function (): void {
    $packageDir = dirname(__DIR__, 2);

    $readme = (string) file_get_contents($packageDir . '/README.md');
    expect($readme)->toContain('/plugin marketplace add pekral/ai-olympus');
    expect($readme)->toContain('/ai-olympus:install-rules');
    expect($readme)->toContain('### Via the plugin marketplace (no Composer)');
    expect($readme)->toContain('### Via Composer');

    $docs = (string) file_get_contents($packageDir . '/docs/installation.md');
    $section = installerDocsSection($docs, '## Installing without Composer (plugin marketplace)');

    // The honest limitation, not a promise the channel cannot keep.
    expect($section)->toContain('reads **neither `rules/` nor a `CLAUDE.md`**');
    expect($section)->toContain('/ai-olympus:install-rules');
    expect($section)->toContain('Composer only');
});
