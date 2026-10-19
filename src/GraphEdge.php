<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

use JsonSerializable;

final readonly class GraphEdge implements JsonSerializable
{

    public function __construct(public string $from, public string $type, public string $to, public int $line)
    {
    }

    /**
     * @param array{from: string, type: string, to: string, line: int} $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['from'], $data['type'], $data['to'], $data['line']);
    }

    /**
     * @return array{from: string, type: string, to: string, line: int}
     */
    public function jsonSerialize(): array
    {
        return ['from' => $this->from, 'type' => $this->type, 'to' => $this->to, 'line' => $this->line];
    }

}
