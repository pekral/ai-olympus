<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

use JsonSerializable;

final readonly class SliceEntity implements JsonSerializable
{

    public function __construct(public string $id, public string $kind, public int $line, public string $file)
    {
    }

    public static function of(GraphNode $node, string $file): self
    {
        return new self($node->id, $node->kind, $node->line, $file);
    }

    /**
     * @return array{id: string, kind: string, line: int, file: string}
     */
    public function jsonSerialize(): array
    {
        return ['id' => $this->id, 'kind' => $this->kind, 'line' => $this->line, 'file' => $this->file];
    }

}
