<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

final class MemoryMarkdown
{

    private const int MAX_PATHS = 20;

    /**
     * @return list<\Pekral\AiOlympus\MemoryEntry>
     */
    public static function parse(string $markdown): array
    {
        $entries = [];

        $blocks = preg_split('/^(?=### )/m', $markdown);

        foreach (is_array($blocks) ? $blocks : [] as $block) {
            $entry = self::entry($block);

            if ($entry instanceof MemoryEntry) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    private static function entry(string $block): ?MemoryEntry
    {
        if (preg_match('/^### ([a-z0-9][a-z0-9-]*) — (.+)$/m', $block, $heading) !== 1) {
            return null;
        }

        preg_match_all('/^- (Trigger|Rule|Example|Source|Role):[ \t]+(.+)$/m', $block, $matches, PREG_SET_ORDER);

        $fields = [];

        foreach ($matches as $match) {
            $fields[$match[1]] = trim($match[2]);
        }

        if (!isset($fields['Trigger'], $fields['Rule'], $fields['Role']) || !MemoryEntry::isRole($fields['Role'])) {
            return null;
        }

        [$source, $added] = self::source($fields['Source'] ?? '');

        return new MemoryEntry(
            $heading[1],
            $fields['Role'],
            $fields['Trigger'],
            trim($heading[2]),
            $fields['Rule'],
            '',
            self::paths($fields['Trigger'] . ' ' . $fields['Rule'] . ' ' . ($fields['Example'] ?? '')),
            $fields['Example'] ?? '',
            $source,
            $added,
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function source(string $line): array
    {
        if (preg_match('/^(.*?)\s+Added:\s*(\d{4}-\d{2}-\d{2})/', $line, $match) === 1) {
            return [$match[1], $match[2]];
        }

        return [$line, ''];
    }

    /**
     * @return list<string>
     */
    private static function paths(string $text): array
    {
        preg_match_all('/`([\w.\/@-]+)(?::\d+(?:-\d+)?)?`/', $text, $matches);

        $paths = array_filter(
            $matches[1],
            static fn (string $token): bool => !str_starts_with($token, 'http') && preg_match(
                '~/|\.[A-Za-z0-9]{1,5}$~',
                $token,
            ) === 1 && MemoryEntry::isPath(
                $token,
            ),
        );

        return array_slice(array_values(array_unique($paths)), 0, self::MAX_PATHS);
    }

}
