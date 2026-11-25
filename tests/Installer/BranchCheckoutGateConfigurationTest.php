<?php

declare(strict_types = 1);

use Symfony\Component\Process\Process;

function branchCheckoutGateConfigurationCheck(string $branchPath, bool $settingsTrackedOnDefault = false): int
{
    $directory = sys_get_temp_dir() . '/ai-olympus-checkout-gate-' . bin2hex(random_bytes(6));
    mkdir($directory);
    register_shutdown_function(static fn (): int => (new Process(['rm', '-rf', $directory]))->run());
    manifestRepositoryGit($directory, 'init', '-q', '-b', 'master');
    file_put_contents($directory . '/.gitignore', ".claude/\n");
    manifestRepositoryGit($directory, 'add', '.gitignore');

    if ($settingsTrackedOnDefault) {
        mkdir($directory . '/.claude');
        file_put_contents($directory . '/.claude/settings.json', "{}\n");
        manifestRepositoryGit($directory, 'add', '-f', '.claude/settings.json');
    }

    manifestRepositoryGit($directory, 'commit', '-q', '-m', 'init');
    manifestRepositoryGit($directory, 'update-ref', 'refs/remotes/origin/master', 'HEAD');
    $blob = trim((new Process(['git', 'hash-object', '-w', '--stdin'], $directory, input: "branch content\n"))->mustRun()->getOutput());
    manifestRepositoryGit($directory, 'update-index', '--add', '--cacheinfo', '100644,' . $blob . ',' . $branchPath);
    $commit = manifestRepositoryGit($directory, 'commit-tree', manifestRepositoryGit($directory, 'write-tree'), '-p', 'master', '-m', 'branch');

    $check = new Process(
        [dirname(__DIR__, 2) . '/skills/_shared/helper-agents-guard.sh', 'config-diff', '--base', 'origin/master', '--target', $commit],
        $directory,
    );
    $check->run();

    return (int) $check->getExitCode();
}

test(
    'the checkout gate refuses a main-tree checkout of a branch that changes a path a later session loads',
    function (string $branchPath, int $exitCode): void {
        expect(branchCheckoutGateConfigurationCheck($branchPath))->toBe($exitCode);
    },
)->with([
    'a Codex instruction file in lower case' => ['agents.md', 3],
    'a Codex setting in upper case' => ['.CODEX/config.toml', 3],
    'a Codex skill' => ['.agents/skills/x/SKILL.md', 3],
    'a deeper component with a letter outside ASCII' => ["src/caf\u{E9}.php", 3],
    'a first component with a letter outside ASCII' => [".agent\u{17F}/skills/x/SKILL.md", 3],
    'a local instruction file' => ['CLAUDE.local.md', 3],
    'an agent definition' => ['.claude/agents/leonardo.md', 3],
    'an agent definition in another letter case' => ['.Claude/agents/leonardo.md', 3],
    'a nested instruction file' => ['docs/CLAUDE.md', 3],
    'a nested skill directory' => ['packages/app/.claude/skills/x/SKILL.md', 3],
    'a project instruction file' => ['CLAUDE.md', 3],
    'a project MCP configuration' => ['.mcp.json', 3],
    'a settings file in another letter case' => ['.Claude/settings.json', 3],
    'a source file only' => ['src/Feature.php', 0],
]);

test('the checkout gate passes a branch that changes only code in a project that tracks a settings file', function (): void {
    expect(branchCheckoutGateConfigurationCheck('src/Feature.php', settingsTrackedOnDefault: true))->toBe(0);
});

test('the checkout gate checks the loaded head commit and writes only that commit into the main tree', function (string $text): void {
    expect(normalizedPackageSource('skills/code-review/references/branch-checkout-gate.md'))->toContain($text);
})->with([
    'no second fetch' => ['It fetches nothing'],
    'the checkout' => ['Run `git checkout -B "$HEAD_REF" "$HEAD_SHA"`.'],
    'the configuration check' => [
        'Run `skills/_shared/helper-agents-guard.sh config-diff --base "origin/$DEFAULT_BRANCH" --target "$HEAD_SHA"`.',
    ],
    'the fallback' => ['Review that branch in a detached review worktree (`agents/leonardo.md` *Review worktree*), or stop and report it.'],
    'the head commit' => ['`HEAD_SHA` is the head commit the gate checks: the PR head SHA from the loaded context (`headRefOid`)'],
    'the local branch' => ['require `git merge-base --is-ancestor "$HEAD_REF" "$HEAD_SHA"`'],
    'the refusal' => ['A non-zero exit refuses the checkout in the main tree'],
    'the remote ref' => ['Require `git rev-parse "origin/$HEAD_REF"` to print `HEAD_SHA`.'],
]);

test('the checkout gate fetches nothing after the check', function (): void {
    $gate = normalizedPackageSource('skills/code-review/references/branch-checkout-gate.md');
    $checkout = substr($gate, (int) strpos($gate, '4. **Check out the branch under review.**'));

    expect($checkout)->not->toContain('git pull')
        ->and($checkout)->not->toContain('git fetch')
        ->and($gate)->not->toContain('"origin/$HEAD_REF" --');
});
