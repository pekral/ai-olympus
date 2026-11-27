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
 * @return array{0: string, 1: int}|null the sanitized bytes and the number of dropped chunks, or null for a malformed PNG
 */
function sanitizePng(string $bytes): ?array
{
    $chunks = str_starts_with($bytes, PNG_SIGNATURE) ? parsePngChunks($bytes) : null;

    if ($chunks === null) {
        return null;
    }

    $types = array_column($chunks, 'type');
    foreach ($chunks as $chunk) {
        if (ctype_upper($chunk['type'][0]) && !in_array($chunk['type'], ['IHDR', 'IDAT', 'IEND'], true)) {
            return null;
        }
    }
    if (strlen($chunks[array_key_last($chunks)]['raw']) !== 12) {
        return null;
    }
    $header = substr($chunks[0]['raw'], 8, -4);
    if (strlen($header) !== 13 || count(array_keys($types, 'IHDR', true)) !== 1 || !in_array('IDAT', $types, true)
        || array_intersect($types, ['acTL', 'fcTL', 'fdAT', 'tRNS', 'PLTE']) !== [] || !function_exists('imagecreatefromstring')
        || substr($header, 8) !== "\x08\x02\x00\x00\x00" && substr($header, 8) !== "\x08\x06\x00\x00\x00") {
        return null;
    }

    $width = readUint32(substr($header, 0, 4));
    $height = readUint32(substr($header, 4, 4));
    if ($width < 1 || $height < 1 || $width > 4_096 || $height > 4_096 || $width * $height > MAX_PNG_PIXELS) {
        return null;
    }

    $stride = 1 + $width * (ord($header[9]) === 2 ? 3 : 4);
    $compressed = '';
    foreach ($chunks as $chunk) {
        if ($chunk['type'] === 'IDAT') {
            $compressed .= substr($chunk['raw'], 8, -4);
        }
    }
    $pixels = @gzuncompress($compressed, $height * $stride);
    if ($pixels === false || strlen($pixels) !== $height * $stride) {
        return null;
    }
    for ($row = 0; $row < $height; $row++) {
        if (ord($pixels[$row * $stride]) > 4) {
            return null;
        }
    }

    // Never pass ancillary chunks (including compressed metadata) to the image decoder.
    $raster = PNG_SIGNATURE . $chunks[0]['raw']
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
    if (!$written || !is_string($canonical) || strlen($canonical) > MAX_PNG_BYTES) {
        return null;
    }

    return [$canonical, count(array_diff($types, ['IHDR', 'IDAT', 'IEND']))];
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
