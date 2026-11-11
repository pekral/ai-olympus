<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

use JsonSerializable;

final readonly class MemoryCandidate implements JsonSerializable
{

    /**
     * @param list<string> $paths
     */
    public function __construct(public string $text, public string $slug, public array $paths)
    {
    }

    public static function fromEntry(MemoryEntry $entry, bool $withExample): self
    {
        $parts = [rtrim($entry->lesson, '.') . '.', 'Trigger: ' . $entry->trigger, 'Rule: ' . $entry->rule];

        if ($entry->evidence !== '') {
            $parts[] = 'Evidence: ' . $entry->evidence;
        }

        if ($withExample && $entry->example !== '') {
            $parts[] = 'Example: ' . $entry->example;
        }

        return new self(implode(' ', $parts), $entry->slug, $entry->paths);
    }

    /**
     * @return array{kind: string, text: string, source: array{type: string, id: string}, paths: list<string>}
     */
    public function jsonSerialize(): array
    {
        return ['kind' => 'learnings', 'text' => $this->text, 'source' => ['type' => 'learnings', 'id' => $this->slug], 'paths' => $this->paths];
    }

}
