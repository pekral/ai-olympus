<?php

declare(strict_types = 1);

namespace AgenticVibes\AgentSkills;

final readonly class CommandResult
{

    public function __construct(public int $exitCode, public string $output) {
    }

}
