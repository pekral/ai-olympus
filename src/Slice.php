<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

use JsonSerializable;

final readonly class Slice implements JsonSerializable
{

    /**
     * @param list<\Pekral\AiOlympus\SliceEntity> $entities
     * @param list<\Pekral\AiOlympus\SliceEdge> $outgoing
     * @param list<\Pekral\AiOlympus\SliceEdge> $incoming
     * @param list<string> $files
     * @param list<\Pekral\AiOlympus\SliceRelated> $related
     */
    public function __construct(
        public string $query,
        public array $entities,
        public array $outgoing,
        public array $incoming,
        public array $files,
        public array $related,
        public ?SliceOmitted $omitted = null,
    ) {
    }

    /**
     * @return array{query: string, entities: list<\Pekral\AiOlympus\SliceEntity>, outgoing: list<\Pekral\AiOlympus\SliceEdge>, incoming: list<\Pekral\AiOlympus\SliceEdge>, files: list<string>, related: list<\Pekral\AiOlympus\SliceRelated>, omitted?: \Pekral\AiOlympus\SliceOmitted}
     */
    public function jsonSerialize(): array
    {
        $slice = ['query' => $this->query, 'entities' => $this->entities, 'outgoing' => $this->outgoing, 'incoming' => $this->incoming];
        $slice += ['files' => $this->files, 'related' => $this->related];

        if ($this->omitted instanceof SliceOmitted) {
            $slice['omitted'] = $this->omitted;
        }

        return $slice;
    }

}
