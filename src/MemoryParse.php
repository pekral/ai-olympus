<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

final readonly class MemoryParse
{

    /**
     * @param list<\Pekral\AiOlympus\MemoryEntry> $entries
     * @param list<string> $rejected one line per block that holds no entry: the heading and the reason
     */
    public function __construct(public array $entries, public array $rejected)
    {
    }

}
