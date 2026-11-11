<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

use RuntimeException;

final class MemoryUnavailable extends RuntimeException
{

    public static function driverMissing(string $driver): self
    {
        return new self(sprintf('Code-review memory unavailable: the PDO driver "%s" is not installed. Continue without memory.', $driver));
    }

    public static function database(string $reason): self
    {
        return new self(sprintf('Code-review memory unavailable: SQLite with FTS5 could not open the database (%s). Continue without memory.', $reason));
    }

    public static function locationNotIgnored(string $location): self
    {
        return new self(sprintf(
            'Code-review memory unavailable: %1$s is not git-ignored or is tracked. '
            . 'Add `%2$s/` to .gitignore and untrack the file. Continue without memory.',
            $location,
            dirname($location),
        ));
    }

}
