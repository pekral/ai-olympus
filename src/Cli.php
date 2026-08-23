<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

use Closure;

/**
 * Entry-point dispatcher for `bin/ai-olympus`.
 *
 * It exists so the process layer stays at the edge: `resolve-next` has to spawn `gh` and
 * `claude`, and @rules/code-testing/general.md forbids a test from invoking an external binary.
 * The executor is therefore a required argument supplied by `bin/ai-olympus`, which keeps every
 * line under src/ reachable from a test with a fake.
 */
final class Cli
{

    /**
     * @param array<int, string> $argv
     * @param \Closure(list<string>, bool): \Pekral\AiOlympus\CommandResult $agentExecutor
     */
    public static function run(array $argv, Closure $agentExecutor): int
    {
        if (($argv[1] ?? 'help') !== 'resolve-next') {
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
