<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

use JsonSerializable;

final readonly class GraphNode implements JsonSerializable
{

    public function __construct(public string $id, public string $kind, public int $line)
    {
    }

    /**
     * @param array{id: string, kind: string, line: int} $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['id'], $data['kind'], $data['line']);
    }

    /**
     * @return array{id: string, kind: string, line: int}
     */
    public function jsonSerialize(): array
    {
        return ['id' => $this->id, 'kind' => $this->kind, 'line' => $this->line];
    }

}
