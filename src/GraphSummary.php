<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

use JsonSerializable;

final readonly class GraphSummary implements JsonSerializable
{

    /**
     * @param list<string> $errors
     */
    public function __construct(public int $files, public int $parsed, public int $reused, public int $removed, public array $errors)
    {
    }

    /**
     * @return array{files: int, parsed: int, reused: int, removed: int, errors: list<string>}
     */
    public function jsonSerialize(): array
    {
        return ['files' => $this->files, 'parsed' => $this->parsed, 'reused' => $this->reused, 'removed' => $this->removed, 'errors' => $this->errors];
    }

}
