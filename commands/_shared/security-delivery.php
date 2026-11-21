<?php

declare(strict_types = 1);

try {
    $record = json_decode((string) stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    $summary = $record['summary'] ?? null;

    if (!is_string($summary) || trim($summary) === '') {
        exit(3);
    }

    if (!str_contains($summary, 'security: owned by ')) {
        exit(0);
    }

    // A future owner is a delegation too. Unknown or missing evidence must keep the merge closed.
    $dates = [];

    foreach (['head_date', 'delivered_date'] as $key) {
        $value = $record[$key] ?? null;

        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $value) !== 1) {
            exit(3);
        }

        $dates[$key] = new DateTimeImmutable($value);

        if (DateTimeImmutable::getLastErrors() !== false) {
            exit(3);
        }
    }

    exit($dates['delivered_date'] >= $dates['head_date'] ? 0 : 3);
} catch (Throwable) {
    exit(3);
}
