<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

use JsonSerializable;

final readonly class SliceGraph implements JsonSerializable
{

    public function __construct(public string $state, public int $files, public int $changed)
    {
    }

    /**
     * @return array{state: string, files: int, changed: int}
     */
    public function jsonSerialize(): array
    {
        return ['state' => $this->state, 'files' => $this->files, 'changed' => $this->changed];
    }

}
