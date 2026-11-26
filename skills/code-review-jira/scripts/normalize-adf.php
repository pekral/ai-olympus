#!/usr/bin/env php
<?php

declare(strict_types = 1);

/**
 * Print the ADF document read from stdin with every string value in Unicode form NFKC with case
 * folding (`Normalizer::FORM_KC_CF`). update-description.sh runs its URL test on this copy only, so a
 * host, an at sign, a digit, or a scheme written in a compatibility form (circled letters, a small full
 * stop, a full-width scheme) reads like its plain form. The copy is never written to JIRA.
 *
 * It fails closed: a missing `Normalizer` class (the intl extension) or a string that is not valid
 * UTF-8 ends the run with exit 1 and prints no document.
 */
function normalizeAdfValue(mixed $value): mixed
{
    if (is_string($value)) {
        $normalized = Normalizer::normalize($value, Normalizer::FORM_KC_CF);

        if ($normalized === false) {
            throw new RuntimeException('a string is not valid UTF-8');
        }

        return $normalized;
    }

    if (is_array($value)) {
        return array_map(normalizeAdfValue(...), $value);
    }

    if ($value instanceof stdClass) {
        foreach (get_object_vars($value) as $name => $item) {
            $value->{$name} = normalizeAdfValue($item);
        }
    }

    return $value;
}

if (!class_exists('Normalizer')) {
    fwrite(STDERR, "normalize-adf.php: the Normalizer class (the intl extension) is missing\n");
    exit(1);
}

$input = stream_get_contents(STDIN);

if ($input === false) {
    fwrite(STDERR, "normalize-adf.php: failed to read stdin\n");
    exit(1);
}

try {
    $document = json_decode($input, false, 512, JSON_THROW_ON_ERROR);
    echo json_encode(normalizeAdfValue($document), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'normalize-adf.php: ' . $exception->getMessage() . "\n");
    exit(1);
}
