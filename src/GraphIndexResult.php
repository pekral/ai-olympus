<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

final readonly class GraphIndexResult
{

    /**
     * @param array<string, \Pekral\AiOlympus\GraphRecord> $files
     */
    public function __construct(public array $files, public GraphSummary $summary)
    {
    }

}
