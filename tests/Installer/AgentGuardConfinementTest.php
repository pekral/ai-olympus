<?php

declare(strict_types = 1);

use Tests\Support\AgentGuardRun;

const AGENT_GUARD_UNAVAILABLE = 'this host cannot apply the confinement (no usable sandbox-exec or bwrap); '
    . 'the fallback tests of this file assert the strict and the non-strict path instead';

function agentGuardSkipWithoutConfinement(): bool
{
    return !AgentGuardRun::confinementAvailable();
}

/**
 * @return array<string, array{0: string}>
 */
function agentGuardInterpreterWrites(): array
{
    $writes = [
        'perl -e' => ['perl -e \'open(my $f, ">", "target") or exit 1; print $f "x";\''],
        'php -r' => ['php -r \'file_put_contents("target", "x");\''],
        'python3 -c' => ['python3 -c \'open("target", "w").write("x")\''],
    ];
    $node = trim((string) shell_exec('command -v node 2>/dev/null'));

    return $node === '' ? $writes : [...$writes, 'node -e' => ['node -e \'require("fs").writeFileSync("target", "x")\'']];
}

/**
 * @return array<string, array{0: string}>
 */
function agentGuardDetachedWrites(): array
{
    $writes = ['nohup … &' => ['nohup sh -c \'echo x > target; touch "$HOME/done"\' >/dev/null 2>&1 &']];
    $setsid = trim((string) shell_exec('command -v setsid 2>/dev/null'));

    return $setsid === '' ? $writes : [...$writes, 'setsid' => ['setsid sh -c \'echo x > target; touch "$HOME/done"\' >/dev/null 2>&1 &']];
}

/**
 * @return list<string>
 */
function agentGuardHookFiles(string $root): array
{
    $files = scandir($root . '/.git/hooks');

    return $files === false ? [] : $files;
}

function agentGuardGitState(string $root): string
{
    return AgentGuardRun::git($root, 'rev-parse', 'HEAD')->getOutput()
        . AgentGuardRun::git($root, 'for-each-ref')->getOutput()
        . AgentGuardRun::git($root, 'stash', 'list')->getOutput()
        . (string) file_get_contents($root . '/.git/config')
        . implode(',', agentGuardHookFiles($root));
}

test('a write into the git toplevel through a redirect, tee, or a heredoc is refused in the confinement', function (string $command): void {
    $root = AgentGuardRun::repository();
    $composer = (string) file_get_contents($root . '/composer.json');

    try {
        AgentGuardRun::exec($root, 'leonardo', $command);

        expect(file_exists($root . '/target'))->toBeFalse()
            ->and(file_get_contents($root . '/composer.json'))->toBe($composer);
    } finally {
        AgentGuardRun::remove($root);
    }
})->with([
    '>' => ['echo x > target'],
    '>>' => ['echo x >> composer.json'],
    'heredoc' => ["cat > target <<'EOF'\nx\nEOF"],
    'tee' => ['echo x | tee target'],
])->skip(agentGuardSkipWithoutConfinement(), AGENT_GUARD_UNAVAILABLE);

test('a write through a subprocess is refused in the confinement', function (string $command): void {
    $root = AgentGuardRun::repository();

    try {
        AgentGuardRun::exec($root, 'splinter', $command);

        expect(file_exists($root . '/target'))->toBeFalse();
    } finally {
        AgentGuardRun::remove($root);
    }
})->with([
    'bash -c' => ['bash -c "echo x > target"'],
    'env' => ['env sh -c "echo x > target"'],
    'find -exec touch' => ['find . -maxdepth 0 -exec touch target \;'],
    'sh -c' => ['sh -c "echo x > target"'],
    'xargs' => ['echo target | xargs touch'],
])->skip(agentGuardSkipWithoutConfinement(), AGENT_GUARD_UNAVAILABLE);

test('a write through an interpreter is refused in the confinement', function (string $command): void {
    $root = AgentGuardRun::repository();

    try {
        AgentGuardRun::exec($root, 'april', $command);

        expect(file_exists($root . '/target'))->toBeFalse();
    } finally {
        AgentGuardRun::remove($root);
    }
})->with(agentGuardInterpreterWrites())->skip(agentGuardSkipWithoutConfinement(), AGENT_GUARD_UNAVAILABLE);

test('a write from a detached process is refused after the process ended', function (string $command): void {
    $root = AgentGuardRun::repository();
    $done = dirname($root) . '/home/done';

    try {
        AgentGuardRun::exec($root, 'casey', $command);
        $deadline = microtime(as_float: true) + 10;

        while (!file_exists($done) && microtime(as_float: true) < $deadline) {
            usleep(50_000);
        }

        expect(file_exists($done))->toBeTrue()
            ->and(file_exists($root . '/target'))->toBeFalse();
    } finally {
        AgentGuardRun::remove($root);
    }
})->with(agentGuardDetachedWrites())->skip(agentGuardSkipWithoutConfinement(), AGENT_GUARD_UNAVAILABLE);

test('a git write fails in the confinement and leaves HEAD, the refs, and the git config unchanged', function (string $command): void {
    $root = AgentGuardRun::repository();
    file_put_contents($root . '/composer.json', '{"dirty": true}');
    $before = agentGuardGitState($root);

    try {
        $process = AgentGuardRun::exec($root, 'shredder', $command);

        expect($process->getExitCode())->not->toBe(0)
            ->and(agentGuardGitState($root))->toBe($before);
    } finally {
        AgentGuardRun::remove($root);
    }
})->with([
    'a write into .git/hooks' => ['echo "exit 0" > .git/hooks/pre-commit'],
    'git checkout -b' => ['git checkout -q -b escaped'],
    'git commit' => ['git -c user.name=t -c user.email=t@example.test commit -qam change'],
    'git config' => ['git config user.name escaped'],
    'git stash' => ['git -c user.name=t -c user.email=t@example.test stash'],
    'git update-ref' => ['git update-ref refs/heads/escaped HEAD'],
])->skip(agentGuardSkipWithoutConfinement(), AGENT_GUARD_UNAVAILABLE);

test('a confined role cannot change the files that switch its own protection off', function (string $path): void {
    $root = AgentGuardRun::repository();
    $before = (string) file_get_contents($root . '/' . $path);

    try {
        $process = AgentGuardRun::exec($root, 'krang', 'echo "{}" > ' . escapeshellarg($path));

        expect($process->getExitCode())->not->toBe(0)
            ->and(file_get_contents($root . '/' . $path))->toBe($before);
    } finally {
        AgentGuardRun::remove($root);
    }
})->with([
    '.claude/settings.local.json' => ['.claude/settings.local.json'],
    'composer.json' => ['composer.json'],
    'the installed guard' => ['.claude/skills/_shared/agent-guard.sh'],
])->skip(agentGuardSkipWithoutConfinement(), AGENT_GUARD_UNAVAILABLE);

test('a hard link inside .claude/run to a protected file is refused in the confinement', function (): void {
    $root = AgentGuardRun::repository();

    try {
        AgentGuardRun::exec($root, 'leonardo', 'ln composer.json .claude/run/hard');

        expect(file_exists($root . '/.claude/run/hard'))->toBeFalse();
    } finally {
        AgentGuardRun::remove($root);
    }
})->skip(agentGuardSkipWithoutConfinement(), AGENT_GUARD_UNAVAILABLE);

test('a symbolic link inside .claude/run is refused in the confinement on macOS', function (): void {
    $root = AgentGuardRun::repository();

    try {
        AgentGuardRun::exec($root, 'leonardo', 'ln -s ../../composer.json .claude/run/link');

        expect(is_link($root . '/.claude/run/link'))->toBeFalse();
    } finally {
        AgentGuardRun::remove($root);
    }
})->skip(
    PHP_OS_FAMILY !== 'Darwin' || agentGuardSkipWithoutConfinement(),
    'only the macOS profile refuses a symbolic link; bwrap cannot, which SECURITY.md names as a residual',
);

test('a nested sandbox with a looser profile cannot write into the git toplevel', function (): void {
    $root = AgentGuardRun::repository();
    $nested = 'if [ -x /usr/bin/sandbox-exec ]; then /usr/bin/sandbox-exec -p "(version 1)(allow default)" sh -c "echo x > target"; '
        . 'else bwrap --dev-bind / / sh -c "echo x > target"; fi';

    try {
        AgentGuardRun::exec($root, 'leonardo', $nested);

        expect(file_exists($root . '/target'))->toBeFalse();
    } finally {
        AgentGuardRun::remove($root);
    }
})->skip(agentGuardSkipWithoutConfinement(), AGENT_GUARD_UNAVAILABLE);

test('a profile file planted in the project or under .claude/run does not widen the confinement', function (): void {
    $root = AgentGuardRun::repository();
    $profile = '(version 1)(allow default)';

    foreach (['.claude/run/agent-guard.sb', '.claude/run/agent-guard.profile', '.claude/run/agent-guard.json', 'agent-guard.sb'] as $planted) {
        file_put_contents($root . '/' . $planted, $profile);
    }

    try {
        AgentGuardRun::exec($root, 'leonardo', 'echo x > target');

        expect(file_exists($root . '/target'))->toBeFalse();
    } finally {
        AgentGuardRun::remove($root);
    }
})->skip(agentGuardSkipWithoutConfinement(), AGENT_GUARD_UNAVAILABLE);

test('each confined role keeps the writes its definition documents', function (string $role, string $extra): void {
    $root = AgentGuardRun::repository();
    $sha = AgentGuardRun::head($root);

    try {
        $process = AgentGuardRun::exec($root, $role, 'echo handoff >> .claude/run/brief.md && git fetch -q origin ' . $sha . $extra);

        expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
            ->and(file_get_contents($root . '/.claude/run/brief.md'))->toBe("handoff\n");
    } finally {
        AgentGuardRun::remove($root);
    }
})->with([
    'april' => ['april', ''],
    'casey' => ['casey', ''],
    'krang' => [
        'krang',
        ' && git worktree add -q --detach .claude/worktrees/agent-batch-slug-krang HEAD && git worktree remove .claude/worktrees/agent-batch-slug-krang',
    ],
    'leonardo' => ['leonardo', ' && git worktree add -q --detach .claude/worktrees/agent-cr-slug-leonardo HEAD'],
    'shredder' => ['shredder', ''],
    'splinter' => ['splinter', ' && git worktree add -q --detach .claude/worktrees/agent-cr-slug-splinter HEAD'],
]);

test('a review role cannot open the review worktree of another role', function (): void {
    $root = AgentGuardRun::repository();

    try {
        $process = AgentGuardRun::exec($root, 'splinter', 'git worktree add -q --detach .claude/worktrees/agent-cr-slug-leonardo HEAD');

        expect($process->getExitCode())->not->toBe(0)
            ->and(is_dir($root . '/.claude/worktrees/agent-cr-slug-leonardo/.git'))->toBeFalse();
    } finally {
        AgentGuardRun::remove($root);
    }
})->skip(agentGuardSkipWithoutConfinement(), AGENT_GUARD_UNAVAILABLE);

test('the confinement is applied on a host that ships sandbox-exec', function (): void {
    expect(AgentGuardRun::confinementAvailable())->toBeTrue();
})->skip(PHP_OS_FAMILY !== 'Darwin', 'sandbox-exec ships only with macOS');

test('a confined command exits with its own status and names the confinement on a failure', function (): void {
    $root = AgentGuardRun::repository();

    try {
        $process = AgentGuardRun::exec($root, 'leonardo', 'exit 7');

        expect($process->getExitCode())->toBe(7)
            ->and($process->getErrorOutput())->toContain('leonardo ran this command in its read-only confinement (exit 7)');
    } finally {
        AgentGuardRun::remove($root);
    }
})->skip(agentGuardSkipWithoutConfinement(), AGENT_GUARD_UNAVAILABLE);

test('exec refuses a role outside the confined set', function (string $role): void {
    $root = AgentGuardRun::repository();

    try {
        $process = AgentGuardRun::exec($root, $role, 'echo x > target');

        expect($process->getExitCode())->toBe(64)
            ->and(file_exists($root . '/target'))->toBeFalse();
    } finally {
        AgentGuardRun::remove($root);
    }
})->with(['donatello', 'raphael', 'nobody']);
