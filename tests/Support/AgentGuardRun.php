<?php

declare(strict_types = 1);

namespace Tests\Support;

use Symfony\Component\Process\Process;

final class AgentGuardRun
{

    private const JQ = '/usr/bin/jq';

    private static ?bool $confinementAvailable = null;

    public static function script(): string
    {
        return dirname(__DIR__, 2) . '/skills/_shared/agent-guard.sh';
    }

    /**
     * @param array<array-key, mixed> $defaultBranchManifest
     */
    public static function repository(array $defaultBranchManifest = []): string
    {
        $base = (string) realpath(sys_get_temp_dir()) . '/ai-olympus-guard-' . bin2hex(random_bytes(6));
        mkdir($base . '/home', 0777, recursive: true);
        $root = $base . '/project';
        $composer = $defaultBranchManifest === []
            ? '{}'
            : json_encode(['extra' => ['ai-olympus' => $defaultBranchManifest]], JSON_THROW_ON_ERROR);

        self::git($base, 'init', '-q', '--bare', 'origin.git');
        self::git($base, 'init', '-q', '-b', 'master', 'project');
        file_put_contents($root . '/composer.json', $composer);
        mkdir($root . '/.claude/run', 0777, recursive: true);
        mkdir($root . '/.claude/skills/_shared', 0777, recursive: true);
        file_put_contents($root . '/.claude/settings.local.json', '{}');
        file_put_contents($root . '/.claude/skills/_shared/agent-guard.sh', 'installed copy');
        file_put_contents($root . '/.gitignore', ".claude/\n");
        self::git($root, 'add', '.');
        self::git($root, 'commit', '-q', '-m', 'init');
        self::git($root, 'remote', 'add', 'origin', $base . '/origin.git');
        self::git($root, 'push', '-q', 'origin', 'master');
        self::git($root, 'remote', 'set-head', 'origin', 'master');

        return $root;
    }

    public static function remove(string $root): void
    {
        installerRemoveDirectory(dirname($root));
    }

    public static function head(string $root): string
    {
        return trim(self::git($root, 'rev-parse', 'HEAD')->getOutput());
    }

    public static function git(string $cwd, string ...$arguments): Process
    {
        $process = new Process(
            ['git', '-c', 'user.name=Guard Test', '-c', 'user.email=guard@example.test', '-c', 'commit.gpgsign=false', ...$arguments],
            $cwd,
            self::environment(dirname($cwd) . '/home'),
        );
        $process->mustRun();

        return $process;
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $environment
     */
    public static function run(string $root, array $arguments, ?string $stdin = null, array $environment = []): Process
    {
        $process = new Process(['bash', self::script(), ...$arguments], $root, [...self::environment(dirname($root) . '/home'), ...$environment]);
        $process->setInput($stdin);
        $process->setTimeout(60);
        $process->run();

        return $process;
    }

    public static function exec(string $root, string $role, string $command): Process
    {
        return self::run($root, ['exec', $role, '--', $command]);
    }

    /**
     * @param list<string> $arguments
     */
    public static function runUnavailable(string $root, array $arguments): Process
    {
        $command = ['bash', self::script(), ...$arguments];

        if (PHP_OS_FAMILY === 'Darwin' && is_executable('/usr/bin/sandbox-exec')) {
            $command = ['/usr/bin/sandbox-exec', '-p', '(version 1)(allow default)', ...$command];
        } elseif (self::linuxBwrap() !== '') {
            $command = [self::linuxBwrap(), '--unshare-user', '--disable-userns', '--dev-bind', '/', '/', '--', ...$command];
        }

        return self::runCommand($root, $command);
    }

    /**
     * @param list<string> $arguments
     */
    public static function runWithoutJq(string $root, array $arguments, ?string $stdin = null): Process
    {
        $command = ['bash', self::script(), ...$arguments];

        if (PHP_OS_FAMILY === 'Darwin') {
            $profile = '(version 1)(allow default)(deny process-exec (literal "' . self::JQ . '"))(deny file-read-metadata (literal "' . self::JQ . '"))';
            $command = ['/usr/bin/sandbox-exec', '-p', $profile, ...$command];
        } else {
            $command = [self::linuxBwrap(), '--dev-bind', '/', '/', '--ro-bind', '/dev/null', self::JQ, '--', ...$command];
        }

        return self::runCommand($root, $command, $stdin);
    }

    public static function execFrom(string $cwd, string $root, string $role, string $command): Process
    {
        $process = new Process(
            ['bash', self::script(), 'exec', $role, '--project', $root, '--', $command],
            $cwd,
            self::environment(dirname($root) . '/home'),
        );
        $process->setTimeout(60);
        $process->run();

        return $process;
    }

    public static function linuxBwrap(): string
    {
        return is_executable('/usr/bin/bwrap') ? '/usr/bin/bwrap' : '';
    }

    public static function remoteOnlyCommit(string $root): string
    {
        $base = dirname($root);
        self::git($base, 'clone', '-q', $base . '/origin.git', 'other');
        file_put_contents($base . '/other/remote-only.txt', 'remote only');
        self::git($base . '/other', 'add', '.');
        self::git($base . '/other', 'commit', '-q', '-m', 'remote only');
        self::git($base . '/other', 'push', '-q', 'origin', 'HEAD:refs/heads/remote-only');

        return trim(self::git($base . '/other', 'rev-parse', 'HEAD')->getOutput());
    }

    public static function confinementAvailable(): bool
    {
        if (self::$confinementAvailable !== null) {
            return self::$confinementAvailable;
        }

        $root = self::repository();

        try {
            $process = self::exec($root, 'leonardo', 'true');
            self::$confinementAvailable = $process->getExitCode() === 0 && !str_contains($process->getErrorOutput(), 'protection inactive');
        } finally {
            self::remove($root);
        }

        return self::$confinementAvailable;
    }

    /**
     * @param list<string> $command
     */
    private static function runCommand(string $root, array $command, ?string $stdin = null): Process
    {
        $process = new Process($command, $root, self::environment(dirname($root) . '/home'));
        $process->setInput($stdin);
        $process->setTimeout(60);
        $process->run();

        return $process;
    }

    /**
     * @return array<string, string>
     */
    private static function environment(string $home): array
    {
        return [
            'CLAUDE_PROJECT_DIR' => '',
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_TERMINAL_PROMPT' => '0',
            'HOME' => $home,
            'SHELL' => '/bin/sh',
        ];
    }

}
