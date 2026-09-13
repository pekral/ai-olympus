<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

/**
 * Reads the local orchestration-metrics store written by
 * `skills/_shared/record-metrics.sh`.
 *
 * The store is JSONL so two runs finishing at once cannot lose each other's
 * entry. A line that does not parse is skipped rather than fatal: the store is
 * append-only operational data, and one truncated write (a crash mid-append)
 * must not make every later report unreadable.
 */
final class MetricsStore
{

    public const string DEFAULT_RELATIVE_PATH = '.ai-olympus/metrics.jsonl';

    public static function resolvePath(): ?string
    {
        $home = getenv('AI_OLYMPUS_HOME');

        if (is_string($home) && $home !== '') {
            return rtrim($home, '/') . '/metrics.jsonl';
        }

        $userHome = getenv('HOME');

        if (!is_string($userHome) || $userHome === '') {
            $userHome = getenv('USERPROFILE');
        }

        if (!is_string($userHome) || $userHome === '') {
            return null;
        }

        return rtrim($userHome, '/') . '/' . self::DEFAULT_RELATIVE_PATH;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function read(string $path, ?int $sinceTimestamp = null): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }

        // `is_file` + `is_readable` above already gate this, so a `false` return needs a race that
        // deletes the file between the two calls. Casting handles that without a branch no test can
        // reach: `(string) false` is '', which yields no entries — the same answer the guard gave.
        $contents = (string) file_get_contents($path);

        $entries = [];

        foreach (explode("\n", $contents) as $line) {
            $entry = self::decodeLine($line, $sinceTimestamp);

            if ($entry === null) {
                continue;
            }

            $entries[] = $entry;
        }

        return $entries;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decodeLine(string $line, ?int $sinceTimestamp): ?array
    {
        $trimmed = trim($line);

        if ($trimmed === '') {
            return null;
        }

        $decoded = json_decode($trimmed, associative: true);

        // A malformed line is skipped, never fatal: one truncated append must not
        // make every later report unreadable.
        if (!is_array($decoded) || !isset($decoded['final_tier'])) {
            return null;
        }

        /** @var array<string, mixed> $entry */
        $entry = $decoded;

        if ($sinceTimestamp !== null && !self::isWithinWindow($entry, $sinceTimestamp)) {
            return null;
        }

        return $entry;
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function isWithinWindow(array $entry, int $sinceTimestamp): bool
    {
        $timestamp = $entry['timestamp'] ?? null;

        if (!is_string($timestamp)) {
            return false;
        }

        $parsed = strtotime($timestamp);

        return $parsed !== false && $parsed >= $sinceTimestamp;
    }

}
