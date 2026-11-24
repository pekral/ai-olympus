<?php

declare(strict_types = 1);

use Symfony\Component\Process\Process;
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
    $writes = ['nohup … &' => ['nohup sh -c \'echo x > target; touch .claude/run/done\' >/dev/null 2>&1 &']];
    $setsid = trim((string) shell_exec('command -v setsid 2>/dev/null'));

    return $setsid === '' ? $writes : [...$writes, 'setsid' => ['setsid sh -c \'echo x > target; touch .claude/run/done\' >/dev/null 2>&1 &']];
}

/**
 * @return list<string>
 */
function agentGuardHookFiles(string $root): array
{
    $files = scandir($root . '/.git/hooks');

    return $files === false ? [] : $files;
}

/**
 * @return list<string>
 */
function agentGuardObjectFiles(string $root): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/.git/objects', FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        assert($file instanceof SplFileInfo);
        $files[] = substr($file->getPathname(), strlen($root)) . ':' . hash_file('sha256', $file->getPathname());
    }

    sort($files);

    return $files;
}

function agentGuardConfinedInput(): string
{
    return (string) json_encode(['tool_name' => 'Bash', 'agent_type' => 'leonardo', 'tool_input' => ['command' => 'true']]);
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
    $done = $root . '/.claude/run/done';

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
    $sha = AgentGuardRun::remoteOnlyCommit($root);
    $fetch = 'mkdir -p .claude/run/fetch/objects && GIT_OBJECT_DIRECTORY=.claude/run/fetch/objects '
        . 'GIT_ALTERNATE_OBJECT_DIRECTORIES="$PWD/.git/objects" git fetch -q --no-write-fetch-head --no-auto-maintenance origin ' . $sha
        . ' && GIT_OBJECT_DIRECTORY=.claude/run/fetch/objects GIT_ALTERNATE_OBJECT_DIRECTORIES="$PWD/.git/objects" git cat-file -e ' . $sha;

    try {
        $process = AgentGuardRun::exec($root, $role, 'echo handoff >> .claude/run/brief.md && ' . $fetch . $extra);

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

test('a confined role cannot fetch into the object store of the project', function (): void {
    $root = AgentGuardRun::repository();
    $sha = AgentGuardRun::remoteOnlyCommit($root);

    try {
        $process = AgentGuardRun::exec($root, 'leonardo', 'git fetch -q origin ' . $sha);
        $present = new Process(['git', 'cat-file', '-e', $sha], $root);
        $present->run();

        expect($process->getExitCode())->not->toBe(0)
            ->and($present->getExitCode())->not->toBe(0);
    } finally {
        AgentGuardRun::remove($root);
    }
})->skip(agentGuardSkipWithoutConfinement(), AGENT_GUARD_UNAVAILABLE);

test('a confined role cannot change the git object store', function (string $command): void {
    $root = AgentGuardRun::repository();
    AgentGuardRun::git($root, 'gc', '-q');
    file_put_contents($root . '/loose.json', 'loose');
    $loose = trim(AgentGuardRun::git($root, 'hash-object', '-w', 'loose.json')->getOutput());
    $files = agentGuardObjectFiles($root);

    try {
        AgentGuardRun::exec($root, 'leonardo', str_replace('{loose}', substr($loose, 0, 2) . '/' . substr($loose, 2), $command));

        expect(agentGuardObjectFiles($root))->toBe($files)
            ->and(file_exists($root . '/.git/objects/info/alternates'))->toBeFalse();
    } finally {
        AgentGuardRun::remove($root);
    }
})->with([
    'a forged loose object' => ['chmod u+w .git/objects/{loose}; printf forged > .git/objects/{loose}'],
    'a removed pack' => ['rm -f .git/objects/pack/*.pack'],
    'an alternates file' => ['echo /tmp > .git/objects/info/alternates'],
])->skip(agentGuardSkipWithoutConfinement(), AGENT_GUARD_UNAVAILABLE);

test('a confined role cannot write the home directory, a directory on PATH, or the git configuration', function (string $command, string $path): void {
    $root = AgentGuardRun::repository();
    $home = dirname($root) . '/home';
    mkdir($home . '/.local/bin', 0777, recursive: true);

    try {
        AgentGuardRun::exec($root, 'april', $command);

        expect(file_exists($home . '/' . $path))->toBeFalse();
    } finally {
        AgentGuardRun::remove($root);
    }
})->with([
    'a binary in a PATH directory' => ['printf "#!/bin/sh\n" > "$HOME/.local/bin/git"', '.local/bin/git'],
    'a gh setting' => ['mkdir -p "$HOME/.config/gh" && echo "pager: x" > "$HOME/.config/gh/config.yml"', '.config/gh/config.yml'],
    'a global git setting' => ['git config --global core.fsmonitor "touch planted"', '.gitconfig'],
    'a launch agent' => ['mkdir -p "$HOME/Library/LaunchAgents" && echo x > "$HOME/Library/LaunchAgents/x.plist"', 'Library/LaunchAgents/x.plist'],
    'a shell startup file' => ['echo "touch planted" >> "$HOME/.bashrc"', '.bashrc'],
])->skip(agentGuardSkipWithoutConfinement(), AGENT_GUARD_UNAVAILABLE);

test('the hook and exec never run a tool planted in a directory on PATH', function (string $tool): void {
    $root = AgentGuardRun::repository();
    $planted = dirname($root) . '/home/planted-bin';
    installerWriteFile($planted . '/' . $tool, "#!/bin/sh\necho x > " . escapeshellarg($root . '/planted-' . $tool) . "\nexit 1\n");
    chmod($planted . '/' . $tool, 0755);
    $path = $planted . ':' . (string) getenv('PATH');

    try {
        AgentGuardRun::run($root, ['hook'], agentGuardConfinedInput(), ['CLAUDE_PROJECT_DIR' => $root, 'PATH' => $path]);
        AgentGuardRun::run($root, ['exec', 'leonardo', '--', 'true'], null, ['PATH' => $path]);

        expect(file_exists($root . '/planted-' . $tool))->toBeFalse();
    } finally {
        AgentGuardRun::remove($root);
    }
})->with(['bwrap', 'git', 'jq', 'uname']);

test('a review role cannot open the review worktree of another role on macOS', function (): void {
    $root = AgentGuardRun::repository();

    try {
        $process = AgentGuardRun::exec($root, 'splinter', 'git worktree add -q --detach .claude/worktrees/agent-cr-slug-leonardo HEAD');

        expect($process->getExitCode())->not->toBe(0)
            ->and(is_dir($root . '/.claude/worktrees/agent-cr-slug-leonardo/.git'))->toBeFalse();
    } finally {
        AgentGuardRun::remove($root);
    }
})->skip(
    PHP_OS_FAMILY !== 'Darwin' || agentGuardSkipWithoutConfinement(),
    'bwrap binds .claude/worktrees by directory, not by name, which SECURITY.md names as a residual',
);

test('a review role cannot write into the existing review worktree of another role', function (): void {
    $root = AgentGuardRun::repository();
    installerWriteFile($root . '/.claude/worktrees/agent-cr-slug-leonardo/review.md', 'leonardo');

    try {
        AgentGuardRun::exec($root, 'splinter', 'echo splinter > .claude/worktrees/agent-cr-slug-leonardo/review.md');

        expect(file_get_contents($root . '/.claude/worktrees/agent-cr-slug-leonardo/review.md'))->toBe('leonardo');
    } finally {
        AgentGuardRun::remove($root);
    }
})->skip(agentGuardSkipWithoutConfinement(), AGENT_GUARD_UNAVAILABLE);

test('the confinement is applied on a host that ships sandbox-exec', function (): void {
    expect(AgentGuardRun::confinementAvailable())->toBeTrue();
})->skip(PHP_OS_FAMILY !== 'Darwin', 'sandbox-exec ships only with macOS');

test('the confinement is applied on CI, where the workflow installs bubblewrap', function (): void {
    expect(AgentGuardRun::confinementAvailable())->toBeTrue();
})->skip(getenv('AI_OLYMPUS_EXPECT_CONFINEMENT') !== '1', 'only the CI workflow promises a usable bwrap');

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
