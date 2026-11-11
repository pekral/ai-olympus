<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

final class CommandOptions
{

    public const string DEFAULT_RUN_DIRECTORY = '.claude/run';

    public const int JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    private const array RUN_DIRECTORIES = [self::DEFAULT_RUN_DIRECTORY, '.codex/run'];

    /**
     * @param array<int, string> $arguments
     * @param list<string> $allowed
     * @param list<string> $repeatable
     * @return array<string, list<string>>|null
     */
    public static function flags(array $arguments, array $allowed, array $repeatable = []): ?array
    {
        $flags = [];

        foreach (array_chunk($arguments, 2) as $pair) {
            $value = $pair[1] ?? null;

            if (!in_array($pair[0], $allowed, strict: true) || $value === null) {
                return null;
            }

            if (isset($flags[$pair[0]]) && !in_array($pair[0], $repeatable, strict: true)) {
                return null;
            }

            $flags[$pair[0]][] = $value;
        }

        return $flags;
    }

    /**
     * @param array<string, list<string>> $flags
     */
    public static function limit(array $flags): null|int|false
    {
        if (!isset($flags['--limit'])) {
            return null;
        }

        return preg_match('/^[1-9][0-9]{0,8}$/', $flags['--limit'][0]) === 1 ? (int) $flags['--limit'][0] : false;
    }

    /**
     * @param array<string, list<string>> $flags
     */
    public static function runDirectory(array $flags): ?string
    {
        $directory = rtrim($flags['--run-dir'][0] ?? self::DEFAULT_RUN_DIRECTORY, '/');

        return in_array($directory, self::RUN_DIRECTORIES, strict: true) ? $directory : null;
    }

    public static function isIgnored(string $root, string $location): bool
    {
        $git = 'git -C ' . escapeshellarg($root);
        $argument = escapeshellarg($location);

        exec($git . ' check-ignore -q -- ' . $argument . ' 2>&1', result_code: $ignored);

        $tracked = [];

        if ($ignored === 0) {
            exec($git . ' ls-files -- ' . $argument . ' 2>&1', $tracked);
        }

        return $ignored === 0 && $tracked === [];
    }

}
