<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

use JsonSerializable;

final readonly class SliceOmitted implements JsonSerializable
{

    public function __construct(public int $files, public int $incoming, public int $outgoing, public int $related)
    {
    }

    /**
     * @return array{files: int, incoming: int, outgoing: int, related: int}
     */
    public function jsonSerialize(): array
    {
        return ['files' => $this->files, 'incoming' => $this->incoming, 'outgoing' => $this->outgoing, 'related' => $this->related];
    }

}
