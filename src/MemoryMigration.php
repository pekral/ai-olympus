<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

use JsonSerializable;

final readonly class MemoryMigration implements JsonSerializable
{

    /**
     * @param list<string> $rejected
     */
    public function __construct(public string $memory, public string $source, public int $parsed, public int $inserted, public array $rejected) {
    }

    /**
     * @return array{memory: string, source: string, parsed: int, inserted: int, skipped: int, rejected: list<string>}
     */
    public function jsonSerialize(): array
    {
        return [
            'inserted' => $this->inserted,
            'memory' => $this->memory,
            'parsed' => $this->parsed,
            'rejected' => $this->rejected,
            'skipped' => $this->parsed - $this->inserted,
            'source' => $this->source,
        ];
    }

}
