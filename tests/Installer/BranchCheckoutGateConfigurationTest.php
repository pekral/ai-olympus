<?php

declare(strict_types = 1);

use Symfony\Component\Process\Process;

function branchCheckoutGateConfigurationCheck(string $branchPath): int
{
    $directory = sys_get_temp_dir() . '/ai-olympus-checkout-gate-' . bin2hex(random_bytes(6));
    mkdir($directory);
    register_shutdown_function(static fn (): int => (new Process(['rm', '-rf', $directory]))->run());
    manifestRepositoryGit($directory, 'init', '-q', '-b', 'master');
    file_put_contents($directory . '/.gitignore', ".claude/\n");
    manifestRepositoryGit($directory, 'add', '.gitignore');
    manifestRepositoryGit($directory, 'commit', '-q', '-m', 'init');
    manifestRepositoryGit($directory, 'update-ref', 'refs/remotes/origin/master', 'HEAD');
    $blob = trim((new Process(['git', 'hash-object', '-w', '--stdin'], $directory, input: "branch content\n"))->mustRun()->getOutput());
    manifestRepositoryGit($directory, 'update-index', '--add', '--cacheinfo', '100644,' . $blob . ',' . $branchPath);
    $commit = manifestRepositoryGit($directory, 'commit-tree', manifestRepositoryGit($directory, 'write-tree'), '-p', 'master', '-m', 'branch');
    manifestRepositoryGit($directory, 'update-ref', 'refs/remotes/origin/feature', $commit);

    $check = new Process(
        ['git', 'diff', '--quiet', 'origin/master', 'origin/feature', '--', ':(icase).claude', ':(icase).codex', ':(icase).agents'],
        $directory,
    );
    $check->run();

    return (int) $check->getExitCode();
}

test('the checkout gate refuses a main-tree checkout of a branch that changes a configuration path in any letter case', function (string $branchPath, int $exitCode): void {
    expect(branchCheckoutGateConfigurationCheck($branchPath))->toBe($exitCode);
})->with([
    'a Codex skill' => ['.agents/skills/x/SKILL.md', 1],
    'a Codex setting in upper case' => ['.CODEX/config.toml', 1],
    'a settings file in another letter case' => ['.Claude/settings.json', 1],
    'a source file only' => ['src/Feature.php', 0],
    'an agent definition in another letter case' => ['.Claude/agents/leonardo.md', 1],
    'an agent definition' => ['.claude/agents/leonardo.md', 1],
]);

test('the checkout gate names the configuration check before the checkout of the main tree', function (): void {
    expect(normalizedPackageSource('skills/code-review/references/branch-checkout-gate.md'))
        ->toContain(
            'In the main tree, first run `git diff --quiet "origin/$DEFAULT_BRANCH" "origin/$HEAD_REF" --'
            . ' \':(icase).claude\' \':(icase).codex\' \':(icase).agents\'`',
        )
        ->toContain('A non-zero exit refuses the checkout in the main tree')
        ->toContain('Review that branch in a detached review worktree (`agents/leonardo.md` *Review worktree*), or stop and report it.');
});
