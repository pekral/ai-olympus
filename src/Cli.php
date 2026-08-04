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
     * @param array<int, string> $argv
     * @param \Closure(list<string>, bool): \AgenticVibes\AgentSkills\CommandResult $agentExecutor
     * @param \Closure(): string $standardInput
     */
    public static function run(array $argv, Closure $agentExecutor, Closure $standardInput): int
    {
        $command = $argv[1] ?? 'help';

        // Nothing in this package registers the hook that would call this subcommand — see
        // docs/agents.md *Architecture constraint*. It exists so the decision logic can be
        // reviewed and tested on its own before any installer wiring turns it on (issue #185).
        if ($command === 'bash-guard') {
            echo AgentBashBoundaryHook::guard($standardInput) . PHP_EOL;

            return 0;
        }

        if ($command !== 'resolve-next') {
            return Installer::run($argv);
        }

        try {
            return new AgenticIssueResolver($agentExecutor)->run(AgenticOptions::fromArgv($argv));
        } catch (InstallerFailure $exception) {
            fwrite(STDERR, $exception->getMessage() . PHP_EOL);

            return 1;
        }
    }

}
