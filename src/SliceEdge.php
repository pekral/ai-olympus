<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

use JsonSerializable;

final readonly class SliceEdge implements JsonSerializable
{

    public function __construct(public string $from, public string $type, public string $to, public int $line, public string $file)
    {
    }

    public static function of(GraphEdge $edge, string $file): self
    {
        return new self($edge->from, $edge->type, $edge->to, $edge->line, $file);
    }

    public function withFrom(string $from): self
    {
        return new self($from, $this->type, $this->to, $this->line, $this->file);
    }

    /**
     * @return array{from: string, type: string, to: string, line: int, file: string}
     */
    public function jsonSerialize(): array
    {
        return ['from' => $this->from, 'type' => $this->type, 'to' => $this->to, 'line' => $this->line, 'file' => $this->file];
    }

}
