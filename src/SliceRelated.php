<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

use JsonSerializable;

final readonly class SliceRelated implements JsonSerializable
{

    public function __construct(public string $file, public string $entity, public string $role, public int $distance)
    {
    }

    /**
     * @return array{file: string, entity: string, role: string, distance: int}
     */
    public function jsonSerialize(): array
    {
        return ['file' => $this->file, 'entity' => $this->entity, 'role' => $this->role, 'distance' => $this->distance];
    }

}
