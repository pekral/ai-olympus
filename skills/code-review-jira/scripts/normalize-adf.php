#!/usr/bin/env php
<?php

declare(strict_types = 1);

/**
 * Print the ADF document read from stdin with every string value in Unicode form NFKC with case
 * folding (`Normalizer::FORM_KC_CF`). update-description.sh runs its URL test on this copy only, so a
 * host, an at sign, a digit, or a scheme written in a compatibility form (circled letters, a small full
 * stop, a full-width scheme) reads like its plain form. The copy is never written to JIRA.
 *
 * If the provider lacks case-folded NFKC or a string is not valid UTF-8, the script exits 1 without
 * printing a document.
 */
function normalizeAdfValue(mixed $value, int $form): mixed
{
    if (is_string($value)) {
        $normalized = Normalizer::normalize($value, $form);

        if ($normalized === false) {
            throw new RuntimeException('a string is not valid UTF-8');
        }

        return $normalized;
    }

    if (is_array($value)) {
        return array_map(static fn (mixed $item): mixed => normalizeAdfValue($item, $form), $value);
    }

    if ($value instanceof stdClass) {
        foreach (get_object_vars($value) as $name => $item) {
            $value->{$name} = normalizeAdfValue($item, $form);
        }
    }

    return $value;
}

$formName = Normalizer::class . '::FORM_KC_CF';
$form = defined($formName) ? constant($formName) : null;

if (!is_int($form)) {
    fwrite(STDERR, "normalize-adf.php: case-folded NFKC is unavailable in this PHP installation\n");
    exit(1);
}

$input = stream_get_contents(STDIN);

if ($input === false) {
    fwrite(STDERR, "normalize-adf.php: failed to read stdin\n");
    exit(1);
}

try {
    $document = json_decode($input, false, 512, JSON_THROW_ON_ERROR);
    echo json_encode(normalizeAdfValue($document, $form), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'normalize-adf.php: ' . $exception->getMessage() . "\n");
    exit(1);
}
