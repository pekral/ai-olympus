<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

/**
 * The `ai-olympus stats` command.
 *
 * Lives beside the installer rather than inside it: the installer synchronises
 * files, this reads a local metrics store, and the two share nothing but the
 * CLI entry point.
 */
final class MetricsCommand
{

    /**
     * @param array<int, string> $argv
     */
    public static function run(array $argv): int
    {
        $path = MetricsStore::resolvePath();

        if ($path === null) {
            fwrite(STDERR, 'Cannot resolve a home directory, so the metrics store has no location.' . PHP_EOL);

            return 1;
        }

        $window = self::resolveWindow($argv);

        if ($window === null) {
            fwrite(STDERR, '--last takes a number of days (e.g. --last=7d) or "all".' . PHP_EOL);

            return 1;
        }

        [$label, $since] = $window;

        echo MetricsReport::render(MetricsStore::read($path, $since), $label);

        return 0;
    }

    /**
     * @param array<int, string> $argv
     * @return array{0: string, 1: int|null}|null
     */
    private static function resolveWindow(array $argv): ?array
    {
        foreach ($argv as $arg) {
            if (!str_starts_with($arg, '--last=')) {
                continue;
            }

            return self::parseWindowValue(substr($arg, strlen('--last=')));
        }

        return ['all time', null];
    }

    /**
     * @return array{0: string, 1: int|null}|null
     */
    private static function parseWindowValue(string $value): ?array
    {
        if ($value === 'all') {
            return ['all time', null];
        }

        if (preg_match('/^(\d{1,4})d$/', $value, $matches) !== 1) {
            return null;
        }

        $days = (int) $matches[1];

        return [sprintf('the last %d day(s)', $days), time() - ($days * 86_400)];
    }

}
