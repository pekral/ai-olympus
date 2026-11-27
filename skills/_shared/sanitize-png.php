#!/usr/bin/env php
<?php

declare(strict_types = 1);

/** Decode one bounded static RGB/RGBA raster, composite it onto white and re-encode only its pixels. */
const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";
const MAX_PNG_BYTES = 2_097_152;
const MAX_PNG_PIXELS = 4_194_304;

function readUint32(string $bytes): int
{
    $values = unpack('N', $bytes);

    return is_array($values) && is_int($values[1] ?? null) ? $values[1] : -1;
}

/**
 * @return array{type: string, raw: string}|null
 */
function readPngChunk(string $bytes, int $offset): ?array
{
    if ($offset + 12 > strlen($bytes)) {
        return null;
    }

    $end = $offset + 12 + readUint32(substr($bytes, $offset, 4));
    $type = substr($bytes, $offset + 4, 4);

    if ($end > strlen($bytes) || preg_match('/^[A-Za-z]{4}$/', $type) !== 1) {
        return null;
    }

    $raw = substr($bytes, $offset, $end - $offset);

    return readUint32(substr($raw, -4)) === crc32(substr($raw, 4, -4)) ? ['type' => $type, 'raw' => $raw] : null;
}

/**
 * @param list<array{type: string, raw: string}> $chunks
 */
function pngChunkOrderIsValid(array $chunks): bool
{
    $ends = array_filter($chunks, static fn (array $chunk): bool => $chunk['type'] === 'IEND');

    return $chunks !== [] && $chunks[0]['type'] === 'IHDR' && count($ends) === 1 && end($chunks)['type'] === 'IEND';
}

/**
 * @return list<array{type: string, raw: string}>|null
 */
function parsePngChunks(string $bytes): ?array
{
    $chunks = [];
    $offset = strlen(PNG_SIGNATURE);

    while ($offset < strlen($bytes)) {
        $chunk = readPngChunk($bytes, $offset);

        if ($chunk === null || count($chunks) >= 1_024) {
            return null;
        }

        $chunks[] = $chunk;
        $offset += strlen($chunk['raw']);
    }

    return pngChunkOrderIsValid($chunks) ? $chunks : null;
}

/**
 * @param list<string> $types
 */
function staticPngChunks(array $types): bool
{
    $critical = array_filter($types, static fn (string $type): bool => ctype_upper($type[0]));

    return array_diff($critical, ['IHDR', 'IDAT', 'IEND']) === []
        && array_intersect($types, ['acTL', 'fcTL', 'fdAT', 'tRNS']) === []
        && count(array_keys($types, 'IHDR', true)) === 1 && in_array('IDAT', $types, true);
}

/**
 * @return array{positive-int, positive-int, int}|null
 */
function pngDimensions(string $header): ?array
{
    if (strlen($header) !== 13 || !in_array(substr($header, 8), ["\x08\x02\x00\x00\x00", "\x08\x06\x00\x00\x00"], true)) {
        return null;
    }

    $width = readUint32(substr($header, 0, 4));
    $height = readUint32(substr($header, 4, 4));

    if (($width < 1 || $height < 1) || max($width, $height) > 4_096 || $width * $height > MAX_PNG_PIXELS) {
        return null;
    }

    return [$width, $height, 1 + $width * (ord($header[9]) === 2 ? 3 : 4)];
}

function validPngScanlines(string $compressed, int $height, int $stride): bool
{
    $pixels = @gzuncompress($compressed, $height * $stride);

    if ($pixels === false || strlen($pixels) !== $height * $stride) {
        return false;
    }

    for ($row = 0; $row < $height; $row++) {
        if (ord($pixels[$row * $stride]) > 4) {
            return false;
        }
    }

    return true;
}

/**
 * @param positive-int $width
 * @param positive-int $height
 */
function canonicalPng(string $header, string $compressed, int $width, int $height): ?string
{
    // Feed the decoder raster chunks only, then draw onto an opaque white background.
    $raster = PNG_SIGNATURE . $header
        . pack('N', strlen($compressed)) . 'IDAT' . $compressed . pack('N', crc32('IDAT' . $compressed))
        . "\x00\x00\x00\x00IEND\xae\x42\x60\x82";
    $decoded = @imagecreatefromstring($raster);
    $opaque = imagecreatetruecolor($width, $height);

    if ($decoded === false || $opaque === false) {
        return null;
    }

    imagefill($opaque, 0, 0, 0xffffff);
    imagecopy($opaque, $decoded, 0, 0, 0, 0, $width, $height);
    ob_start();
    $written = imagepng($opaque, null, 9);
    $canonical = ob_get_clean();

    return $written && is_string($canonical) && strlen($canonical) <= MAX_PNG_BYTES ? $canonical : null;
}

/**
 * @return array{string, int}|null
 */
function sanitizePng(string $bytes): ?array
{
    $chunks = str_starts_with($bytes, PNG_SIGNATURE) ? parsePngChunks($bytes) : null;

    if ($chunks === null || $chunks === [] || !function_exists('imagecreatefromstring')) {
        return null;
    }

    $types = array_column($chunks, 'type');
    $header = $chunks[0]['raw'];
    $dimensions = pngDimensions(substr($header, 8, -4));

    if ($dimensions === null || !staticPngChunks($types) || strlen($chunks[array_key_last($chunks)]['raw']) !== 12) {
        return null;
    }

    [$width, $height, $stride] = $dimensions;
    $data = array_filter($chunks, static fn (array $chunk): bool => $chunk['type'] === 'IDAT');
    $compressed = implode('', array_map(static fn (array $chunk): string => substr($chunk['raw'], 8, -4), $data));

    if (!validPngScanlines($compressed, $height, $stride)) {
        return null;
    }

    $canonical = canonicalPng($header, $compressed, $width, $height);

    return $canonical === null ? null : [$canonical, count(array_diff($types, ['IHDR', 'IDAT', 'IEND']))];
}

$arguments = array_slice($argv ?? [], 1);

if (count($arguments) !== 2) {
    fwrite(STDERR, "usage: sanitize-png.php <input.png> <output.png>\n");
    exit(2);
}

$input = file_get_contents($arguments[0], length: MAX_PNG_BYTES + 1);
$result = is_string($input) && strlen($input) <= MAX_PNG_BYTES ? sanitizePng($input) : null;

if ($result === null) {
    fwrite(STDERR, "sanitize-png.php: the input is not a supported static PNG\n");
    exit(1);
}

$output = @fopen($arguments[1], 'xb');

if ($output === false) {
    exit(1);
}

$written = fwrite($output, $result[0]);

if (!fclose($output) || $written !== strlen($result[0])) {
    unlink($arguments[1]);
    exit(1);
}

fwrite(STDERR, 'dropped=' . $result[1] . "\n");
