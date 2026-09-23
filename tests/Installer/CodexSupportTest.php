<?php

declare(strict_types = 1);

use Pekral\AiOlympus\Installer;

test('every canonical agent has a project-scoped Codex adapter', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $agentFiles = glob($packageDir . '/agents/*.md');
    $codexAgentFiles = glob($packageDir . '/codex/agents/*.toml');

    expect($agentFiles)->not->toBeFalse();
    expect($codexAgentFiles)->not->toBeFalse();

    $canonicalNames = array_map(static fn (string $path): string => basename($path, '.md'), is_array($agentFiles) ? $agentFiles : []);
    $codexNames = array_map(static fn (string $path): string => basename($path, '.toml'), is_array($codexAgentFiles) ? $codexAgentFiles : []);
    sort($canonicalNames);
    sort($codexNames);

    expect($codexNames)->toBe($canonicalNames);

    foreach (is_array($codexAgentFiles) ? $codexAgentFiles : [] as $codexAgentFile) {
        $name = basename($codexAgentFile, '.toml');
        $content = (string) file_get_contents($codexAgentFile);

        expect($content)->toContain('name = "' . $name . '"');
        expect($content)->toContain('description = "');
        expect($content)->toContain('developer_instructions = """');
        expect($content)->toContain('`.codex/agent-instructions/' . $name . '.md`');
        expect($content)->toContain('`.agents/skills/');
        expect($content)->toContain('`.codex/rules/');

        // Each adapter binds its own default tier to concrete Codex controls. The adapter used to
        // carry no `model` key at all and describe the tier in prose only, which left the choice to
        // whatever the session happened to be configured with — the opposite of "declared per
        // platform in the agent's own definition". The prose block below the keys states the same
        // two values, so a future edit cannot move one without the other.
        expect($content)->toMatch('/^model = "(sol|luna|terra|astra)"$/m');
        expect($content)->toMatch('/^model_reasoning_effort = "(low|medium|high)"$/m');
    }
});

test('Codex installation uses official project locations and keeps existing AGENTS.md', function (): void {
    $root = installerCreateProjectRoot();
    installerWriteFile($root . '/AGENTS.md', 'project-owned instructions');
    $cwd = getcwd();
    $originalCwd = $cwd !== false ? $cwd : '';

    try {
        chdir($root);
        ob_start();
        $exitCode = Installer::run(['ai-olympus', 'install', '--force']);
        ob_end_clean();

        expect($exitCode)->toBe(0);
        expect(file_get_contents($root . '/AGENTS.md'))->toBe('project-owned instructions');
        expect(is_file($root . '/.agents/skills/code-review/SKILL.md'))->toBeTrue();
        expect(is_file($root . '/.codex/agents/splinter.toml'))->toBeTrue();
        expect(is_file($root . '/.codex/agent-instructions/splinter.md'))->toBeTrue();
        expect(is_file($root . '/.codex/rules/php/core-standards.md'))->toBeTrue();
    } finally {
        if ($originalCwd !== '') {
            chdir($originalCwd);
        }

        installerRemoveDirectory($root);
    }
});

test('the bundled AGENTS.md teaches Codex how to load AI Olympus artifacts', function (): void {
    $content = (string) file_get_contents(dirname(__DIR__, 2) . '/AGENTS.md');

    expect($content)->toContain('`.codex/rules/`');
    expect($content)->toContain('`.agents/skills/`');
    expect($content)->toContain('`.codex/agents/`');
    expect($content)->toContain('`.codex/run/`');
    expect($content)->toContain('`$skill-name`');
});
