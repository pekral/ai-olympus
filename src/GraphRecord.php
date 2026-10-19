<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

use JsonSerializable;

/**
 * @phpstan-type RecordData array{hash: string, error?: string, nodes: list<array{id: string, kind: string, line: int}>, edges: list<array{from: string, type: string, to: string, line: int}>}
 */
final readonly class GraphRecord implements JsonSerializable
{

    /**
     * @param list<\Pekral\AiOlympus\GraphNode> $nodes
     * @param list<\Pekral\AiOlympus\GraphEdge> $edges
     */
    public function __construct(public string $hash, public array $nodes, public array $edges, public ?string $error = null)
    {
    }

    /**
     * @param RecordData $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['hash'],
            array_map(GraphNode::fromArray(...), $data['nodes']),
            array_map(GraphEdge::fromArray(...), $data['edges']),
            $data['error'] ?? null,
        );
    }

    /**
     * @return array{hash: string, error?: string, nodes: list<\Pekral\AiOlympus\GraphNode>, edges: list<\Pekral\AiOlympus\GraphEdge>}
     */
    public function jsonSerialize(): array
    {
        $record = ['hash' => $this->hash];

        if ($this->error !== null) {
            $record['error'] = $this->error;
        }

        return $record + ['nodes' => $this->nodes, 'edges' => $this->edges];
    }

}
