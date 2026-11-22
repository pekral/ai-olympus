<?php

declare(strict_types = 1);

use Pekral\AiOlympus\Installer;
use Symfony\Component\Process\Process;
use Tests\Support\StderrCapture;

/**
 * Runs one installer invocation with HOME pointed at an isolated directory, so the assertions
 * can distinguish the project skills copy from the home one without touching the real home.
 *
 * The assertions run through $assert rather than on a returned value: both directories are
 * removed on the way out, so anything inspecting them has to do so before the cleanup.
 *
 * @param array<int, string> $arguments extra CLI arguments appended after `install`
 * @param (callable(string $home): void)|null $seedHome populates the home skills directory before the run
 * @param callable(array{home: string, root: string, exitCode: int, output: string, stderr: string}): void $assert
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
        [$exitCode, $stderr] = StderrCapture::run(static fn (): int => Installer::run([...['ai-olympus', 'install'], ...$arguments]));
        $output = (string) ob_get_clean();

        $assert(['home' => $home, 'root' => $root, 'exitCode' => $exitCode, 'output' => $output, 'stderr' => $stderr]);
    } finally {
        installerRestoreEnvAndCleanup($homeBefore, $originalCwd, $root);
        installerRemoveDirectory($home);
    }
}

test('install writes skills only to the project directory by default', function (): void {
    globalSkillsRunInstall([], seedHome: null, assert: function (array $result): void {
        expect(is_file($result['root'] . '/.claude/skills/code-review/SKILL.md'))->toBeTrue();
        expect(is_file($result['root'] . '/.agents/skills/code-review/SKILL.md'))->toBeTrue();
        // A home copy would override the project one in every project on the machine.
        expect(is_dir($result['home'] . '/.claude/skills'))->toBeFalse();
        expect(is_dir($result['home'] . '/.agents/skills'))->toBeFalse();
        expect($result['exitCode'])->toBe(0);
    });
});

test('install --global writes skills to both the project and the home directory', function (): void {
    globalSkillsRunInstall(['--global'], seedHome: null, assert: function (array $result): void {
        expect(is_file($result['root'] . '/.claude/skills/code-review/SKILL.md'))->toBeTrue();
        expect(is_file($result['home'] . '/.claude/skills/code-review/SKILL.md'))->toBeTrue();
        expect(is_file($result['home'] . '/.agents/skills/code-review/SKILL.md'))->toBeTrue();
        // The report names the real target path, so it cannot claim a copy that was not written.
        expect($result['output'])->toContain($result['home'] . '/.claude/skills');
        expect($result['output'])->toContain($result['home'] . '/.agents/skills');
        expect($result['output'])->toContain('override the project copy');
    });
});

test('install --prune-global removes this package\'s skills from home and keeps foreign ones', function (): void {
    $seed = static function (string $home): void {
        installerWriteFile($home . '/.claude/skills/code-review/SKILL.md', 'shadowing copy');
        installerWriteFile($home . '/.claude/skills/code-review/scripts/run.sh', 'echo shadow');
        installerWriteFile($home . '/.claude/skills/foreign-skill/SKILL.md', 'another source');
        installerWriteFile($home . '/.agents/skills/code-review/SKILL.md', 'shadowing Codex copy');
        installerWriteFile($home . '/.agents/skills/foreign-skill/SKILL.md', 'another Codex source');
    };

    globalSkillsRunInstall(['--prune-global'], $seed, function (array $result): void {
        expect(is_dir($result['home'] . '/.claude/skills/code-review'))->toBeFalse();
        expect(is_file($result['home'] . '/.claude/skills/foreign-skill/SKILL.md'))->toBeTrue();
        expect(is_dir($result['home'] . '/.agents/skills/code-review'))->toBeFalse();
        expect(is_file($result['home'] . '/.agents/skills/foreign-skill/SKILL.md'))->toBeTrue();
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
        expect($result['stderr'])->toContain('--global and --prune-global are mutually exclusive');
        // Nothing is installed and nothing is removed when the arguments contradict each other.
        expect(is_dir($result['root'] . '/.claude/skills'))->toBeFalse();
    });
});

test('global skill helpers use the matching home payload outside any checkout', function (): void {
    globalSkillsRunInstall(['--global'], seedHome: null, assert: function (array $result): void {
        foreach (['.agents', '.claude'] as $platform) {
            $scripts = $result['home'] . '/' . $platform . '/skills/_shared';
            $helper = $result['home'] . '/' . $platform . '/ai-olympus-commands/_shared/audit-trail.sh';
            $process = new Process(
                ['bash', '-c', 'source "$1" && resolve_command_helper audit-trail.sh', 'lookup', $scripts . '/resolve-command-helper.sh'],
                $result['home'],
                ['HOME' => $result['home']],
            );

            expect($process->mustRun()->getOutput())->toBe(realpath($helper) . "\n")
                ->and(is_file($helper))->toBeTrue();
        }
    });
});

function checkDiscoveredWorkflow(string $skills, string $public, string $name, string $command, string $protocol): void
{
    $skill = $skills . '/' . $name;
    $instructions = (string) file_get_contents($skill . '/SKILL.md');
    expect($instructions)->toContain('bash ../_shared/resolve-command-helper.sh --entry')
        ->and($instructions)->not->toContain('For a bounded work step already in progress');

    $resolver = new Process(['bash', '../_shared/resolve-command-helper.sh', '--entry'], $skill);
    $adapter = trim($resolver->mustRun()->getOutput());
    $entry = (string) file_get_contents($adapter);
    expect($entry)->toContain('| `$' . $name . '` | `commands/' . $command . '.md` |')
        ->and($entry)->toContain('An unmapped user invocation also stays in its current executor', 'resume below the entry link');

    $commandText = (string) file_get_contents($public . '/' . $command . '.md');
    expect($commandText)->toContain('commands/_shared/orchestration/' . $protocol . '.inc')
        ->and(is_file(dirname($adapter) . '/orchestration/' . $protocol . '.inc'))->toBeTrue()
        ->and((string) file_get_contents($skills . '/class-refactoring/SKILL.md'))->not->toContain('resolve-command-helper.sh --entry');
}

test('direct workflows continue from the host-discovered project and global skills even with older project instructions', function (): void {
    globalSkillsRunInstall(['--global'], seedHome: null, assert: function (array $result): void {
        $root = $result['root'];
        installerWriteFile($root . '/AGENTS.md', 'Custom project instructions.' . PHP_EOL);
        $previous = (string) file_get_contents(__DIR__ . '/../Fixtures/legacy-skills/do-it-0.5.md');
        installerWriteFile($root . '/.agents/skills/do-it/SKILL.md', $previous);
        $customSkill = $root . '/.agents/skills/process-code-review/SKILL.md';
        installerWriteFile($customSkill, 'Custom review procedure.' . PHP_EOL);
        [$status] = installedCopyReferenceInstall($root);
        expect($status)->toBe(0)->and(file_get_contents($root . '/AGENTS.md'))->toBe('Custom project instructions.' . PHP_EOL);
        expect(file_get_contents($customSkill))->toBe('Custom review procedure.' . PHP_EOL);
        $source = dirname(__DIR__, 2);
        $layouts = [
            [$source . '/skills', $source . '/commands'],
            [$root . '/.claude/skills', $root . '/.claude/commands'],
            [$root . '/.agents/skills', $root . '/.codex/commands'],
            [$result['home'] . '/.claude/skills', $result['home'] . '/.claude/ai-olympus-commands'],
            [$result['home'] . '/.agents/skills', $result['home'] . '/.agents/ai-olympus-commands'],
        ];

        foreach ($layouts as [$skills, $commands]) {
            checkDiscoveredWorkflow($skills, $commands, 'do-it', 'do-it', 'do-it');
            checkDiscoveredWorkflow($skills, $commands, 'verify-merge-readiness', 'prepare-issue-for-merge', 'verify-merge-readiness');
        }
    });
});

function recordInstalledReviewMetrics(string $directory, string $consumer, int $number): string
{
    $state = ".codex/run/round-{$number}.json";
    $script = $directory . '/review-state.sh';
    (new Process(['bash', $script, 'init', '--state', $state, '--repo', 'acme/shop', '--pr', (string) $number], $consumer))->mustRun();
    $report = "# Code Review\n\n**Status:** converged\n**Counts:** Critical 0 · Moderate 0\n"
        . '**Reviewed revision:** ' . str_repeat('a', 40) . "\n**Last updated:** 2026-09-03T12:00:00Z\n\n## Findings\n";
    (new Process(['bash', $script, 'record', '--state', $state, '--input', '-'], $consumer, input: $report))->mustRun();
    $output = (new Process(['bash', $script, 'evaluate', '--state', $state], $consumer))->mustRun()->getOutput();
    expect(json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR))->toMatchArray(['iteration' => 1, 'verdict' => 'converged']);

    $store = $consumer . "/metrics-{$number}.jsonl";
    $metrics = [
        'bash', $directory . '/record-metrics.sh', '--initial-tier', 'STANDARD', '--final-tier', 'STANDARD',
        '--status', 'success', '--review-state', $state, '--store', $store,
    ];
    (new Process($metrics, $consumer))->mustRun();

    return (string) file_get_contents($store);
}

test('review state and derived metrics use the same canonical jq modules in every distribution', function (): void {
    globalSkillsRunInstall(['--global'], seedHome: null, assert: function (array $result): void {
        $root = $result['root'];
        (new Process(['git', 'init', '-q'], $root))->mustRun();
        $commit = ['git', '-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid', 'commit', '--allow-empty', '-qm', 'base'];
        (new Process($commit, $root))->mustRun();
        installerEnsureDirectory($root . '/.codex/run');
        $directories = [
            dirname(__DIR__, 2) . '/commands/_shared',
            $root . '/.claude/command-support/_shared',
            $root . '/.codex/commands/_shared',
            $result['home'] . '/.claude/ai-olympus-commands/_shared',
            $result['home'] . '/.agents/ai-olympus-commands/_shared',
        ];

        foreach ($directories as $index => $directory) {
            expect(recordInstalledReviewMetrics($directory, $root, $index + 1))->toContain('"iterations":1');
        }
    });
});
