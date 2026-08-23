<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

final readonly class CommandResult
{

    public function __construct(public int $exitCode, public string $output) {
    }

}
