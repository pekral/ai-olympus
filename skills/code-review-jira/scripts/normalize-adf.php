#!/usr/bin/env php
<?php

declare(strict_types = 1);

/**
 * With --prepare-wiki, validate the raw new Wiki Markup and print it in NFC before conversion.
 * In the default mode, print the ADF document read from stdin with every string value in Unicode form NFKC with case
 * folding (`Normalizer::FORM_KC_CF`). update-description.sh runs its URL test on this copy only, so a
 * host, an at sign, a digit, or a scheme written in a compatibility form (circled letters, a small full
 * stop, a full-width scheme) reads like its plain form. The copy is never written to JIRA.
 *
 * If the provider lacks case-folded NFKC or a string is not valid UTF-8, the script exits 1 without
 * printing a document.
 */
function normalizeJiraText(string $text, int $form): string
{
    $normalized = Normalizer::normalize($text, $form);

    if ($normalized === false) {
        throw new RuntimeException('normalisation failed');
    }

    return $normalized;
}

function normalizeAdfValue(mixed $value, int $form): mixed
{
    if (is_string($value)) {
        return normalizeJiraText($value, $form);
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

$prepareWiki = ($argv[1] ?? null) === '--prepare-wiki';
$formName = Normalizer::class . ($prepareWiki ? '::FORM_C' : '::FORM_KC_CF');
$form = defined($formName) ? constant($formName) : null;

if (!is_int($form)) {
    $name = $prepareWiki ? 'NFC' : 'case-folded NFKC';
    fwrite(STDERR, "normalize-adf.php: cannot normalise: $name is unavailable in this PHP installation\n");
    exit($prepareWiki ? 3 : 1);
}

const MAX_WIKI_BYTES = 65_536;

$input = stream_get_contents(STDIN, $prepareWiki ? MAX_WIKI_BYTES + 1 : null);

if ($input === false) {
    fwrite(STDERR, "normalize-adf.php: failed to read stdin\n");
    exit(1);
}

try {
    if ($prepareWiki) {
        if (strlen($input) > MAX_WIKI_BYTES) {
            fwrite(STDERR, 'normalize-adf.php: the new text is longer than ' . MAX_WIKI_BYTES . " bytes\n");
            exit(1);
        }

        $controls = '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f-\x{9f}\x{ad}\x{61c}\x{180e}'
            . '\x{200b}-\x{200f}\x{202a}-\x{202e}\x{2060}\x{2066}-\x{2069}\x{feff}]/u';

        if (preg_match($controls, $input) !== 0) {
            fwrite(STDERR, "normalize-adf.php: invalid UTF-8 or a forbidden control character in the new text\n");
            exit(5);
        }

        if (trim($input) === '') {
            fwrite(STDERR, "normalize-adf.php: the new text is empty\n");
            exit(1);
        }

        echo normalizeJiraText($input, $form);
        exit(0);
    }

    $document = json_decode($input, false, 512, JSON_THROW_ON_ERROR);
    echo json_encode(normalizeAdfValue($document, $form), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'normalize-adf.php: ' . $exception->getMessage() . "\n");
    exit(1);
}
