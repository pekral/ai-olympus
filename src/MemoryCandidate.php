<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

use JsonSerializable;

final readonly class MemoryCandidate implements JsonSerializable
{

    private const int MAX_TEXT = 500;

    /**
     * @param list<string> $paths
     */
    public function __construct(public string $text, public string $slug, public array $paths)
    {
    }

    /**
     * Builds the candidate text under the 500-character limit of `skills/_shared/pack-context.sh`.
     * The lesson and the rule come first. A text that is still too long loses the example, then the evidence, then a cut at a word boundary.
     */
    public static function fromEntry(MemoryEntry $entry, bool $withExample): self
    {
        $core = rtrim($entry->lesson, '.') . '. Rule: ' . $entry->rule . ' Trigger: ' . $entry->trigger;
        $optional = array_values(array_filter([
            $entry->evidence !== '' ? 'Evidence: ' . $entry->evidence : '',
            $withExample && $entry->example !== '' ? 'Example: ' . $entry->example : '',
        ], static fn (string $part): bool => $part !== ''));
        $text = implode(' ', [$core, ...$optional]);

        while ($optional !== [] && self::length($text) > self::MAX_TEXT) {
            array_pop($optional);
            $text = implode(' ', [$core, ...$optional]);
        }

        return new self(self::cut($text), $entry->slug, $entry->paths);
    }

    /**
     * @return array{kind: string, text: string, source: array{type: string, id: string}, paths: list<string>}
     */
    public function jsonSerialize(): array
    {
        return ['kind' => 'learnings', 'text' => $this->text, 'source' => ['type' => 'learnings', 'id' => $this->slug], 'paths' => $this->paths];
    }

    private static function length(string $text): int
    {
        return count(self::characters($text));
    }

    private static function cut(string $text): string
    {
        $characters = self::characters($text);

        if (count($characters) <= self::MAX_TEXT) {
            return $text;
        }

        $head = implode('', array_slice($characters, 0, self::MAX_TEXT - 1));
        $space = strrpos($head, ' ');

        if ($space !== false && preg_match('/\s/u', $characters[self::MAX_TEXT - 1]) !== 1) {
            $head = substr($head, 0, $space);
        }

        return rtrim($head, ' .,;:') . '…';
    }

    /**
     * @return list<string>
     */
    private static function characters(string $text): array
    {
        $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);

        return $characters === false ? [] : $characters;
    }

}
