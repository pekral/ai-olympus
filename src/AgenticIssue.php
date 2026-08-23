<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

final readonly class AgenticIssue
{

    public function __construct(public int $number, public string $url, public string $createdAt) {
    }

}
