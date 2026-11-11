<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

final class MemoryMarkdown
{

    private const int MAX_PATHS = 20;

    private const array FIELDS = ['Example', 'Role', 'Rule', 'Source', 'Trigger'];

    public static function parse(string $markdown): MemoryParse
    {
        $entries = [];
        $rejected = [];

        $blocks = preg_split('/^(?=### )/m', $markdown);

        foreach (is_array($blocks) ? $blocks : [] as $block) {
            if (!str_starts_with($block, '### ')) {
                continue;
            }

            [$heading, $entry] = self::entry($block);

            if ($entry instanceof MemoryEntry) {
                $entries[] = $entry;

                continue;
            }

            $rejected[] = $heading . ': ' . $entry;
        }

        return new MemoryParse($entries, $rejected);
    }

    /**
     * @return array{0: string, 1: \Pekral\AiOlympus\MemoryEntry|string} the heading line, and the entry or the reason it is not one
     */
    private static function entry(string $block): array
    {
        $lines = explode("\n", $block);
        $heading = trim($lines[0]);

        if (preg_match('/^### ([a-z0-9][a-z0-9-]*) — (.+)$/', $heading, $title) !== 1) {
            return [$heading, 'the heading is not "### <slug> — <lesson>"'];
        }

        $fields = self::fields(array_slice($lines, 1));
        [$source, $added] = self::source($fields['Source']);

        try {
            return [$heading, MemoryEntry::fromArray([
                'added' => $added,
                'example' => $fields['Example'],
                'lesson' => trim($title[2]),
                'paths' => self::paths($fields['Trigger'] . ' ' . $fields['Rule'] . ' ' . $fields['Example']),
                'role' => $fields['Role'],
                'rule' => $fields['Rule'],
                'slug' => $title[1],
                'source' => $source,
                'trigger' => $fields['Trigger'],
            ], legacy: true),
            ];
        } catch (InstallerFailure $failure) {
            return [$heading, $failure->getMessage()];
        }
    }

    /**
     * @param list<string> $lines
     * @return array<string, string>
     */
    private static function fields(array $lines): array
    {
        $fields = array_fill_keys(self::FIELDS, '');
        $field = null;

        foreach ($lines as $line) {
            if (preg_match('/^- (' . implode('|', self::FIELDS) . '):[ \t]+(.+)$/', $line, $match) === 1) {
                $field = $match[1];
                $fields[$field] = trim($match[2]);
            } elseif ($field !== null && trim($line) !== '' && preg_match('/^(- |#)/', $line) !== 1) {
                $fields[$field] .= ' ' . trim($line);
            } else {
                $field = null;
            }
        }

        return $fields;
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
