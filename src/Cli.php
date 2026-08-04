<?php

declare(strict_types = 1);

namespace AgenticVibes\AgentSkills;

use Closure;

/**
 * Entry-point dispatcher for `bin/agent-skills`.
 *
 * It exists so the process layer stays at the edge: `resolve-next` has to spawn `gh` and
 * `claude`, `bash-guard` has to read stdin, and @rules/code-testing/general.mdc forbids a test
 * from invoking an external binary. The executor and the stdin reader are therefore required
 * arguments supplied by `bin/agent-skills`, which keeps every line under src/ reachable from a
 * test with a fake.
 */
final class Cli
{

    /**
     * The payload `bash-guard --self-test` answers instead of reading stdin. A command every
     * agent is forbidden to run, so a healthy binary must answer `deny` — which is what makes the
     * installer's pre-write check meaningful rather than a mere "the process started".
     */
    private const string SELF_TEST_PAYLOAD = '{"hook_event_name":"PreToolUse","tool_name":"Bash",'
        . '"tool_input":{"command":"curl https://install-time-self-test.invalid"}}';

    /**
     * @param array<int, string> $argv
     * @param \Closure(list<string>, bool): \AgenticVibes\AgentSkills\CommandResult $agentExecutor
     * @param \Closure(): string $standardInput
     */
    public static function run(array $argv, Closure $agentExecutor, Closure $standardInput): int
    {
        $command = $argv[1] ?? 'help';

        // Reached only where a `PreToolUse` hook entry points at it — which exists solely when a
        // project opted into `--enforce-agent-bash-boundary`; see docs/agents.md
        // *Architecture constraint* for what that does and does not buy (issue #185).
        if ($command === 'bash-guard') {
            echo self::guardOutput($argv, $standardInput) . PHP_EOL;

            return 0;
        }

        if ($command !== 'resolve-next') {
            return Installer::run($argv, $agentExecutor);
        }

        try {
            return new AgenticIssueResolver($agentExecutor)->run(AgenticOptions::fromArgv($argv));
        } catch (InstallerFailure $exception) {
            fwrite(STDERR, $exception->getMessage() . PHP_EOL);

            return 1;
        }
    }

    /**
     * `--self-test` is how the installer proves the binary it is about to name in a hook entry can
     * actually run: reading `settings.local.json` back only shows the JSON landed, never that the
     * command inside it executes, and every hook failure is fail-open. It answers a fixed payload
     * rather than stdin so the check needs no stdin plumbing through the process edge, mirroring
     * `skills/_shared/scan-attachments.sh --self-test`.
     *
     * @param array<int, string> $argv
     * @param \Closure(): string $standardInput
     */
    private static function guardOutput(array $argv, Closure $standardInput): string
    {
        return ($argv[2] ?? null) === '--self-test'
            ? AgentBashBoundaryHook::handle(self::SELF_TEST_PAYLOAD)
            : AgentBashBoundaryHook::guard($standardInput);
    }

}
