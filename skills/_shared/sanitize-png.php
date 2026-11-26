#!/usr/bin/env php
<?php

declare(strict_types = 1);

/**
 * Copy a PNG without its ancillary text and metadata chunks.
 *
 * Usage: php sanitize-png.php <input.png> <output.png>
 *
 * The output keeps every chunk except tEXt, iTXt, zTXt, and eXIf: those four carry free text, a
 * creator tool name, a file path, or a GPS position, and none of them draws a pixel. The input must
 * be a well-formed PNG: the signature, IHDR first, IEND last, a CRC-32 that matches on every chunk,
 * and no byte after IEND. Anything else is refused and no output is written.
 *
 * Exit codes: 0 written (stderr: `dropped=<n>`), 1 refused, 2 usage error.
 */
const PNG_SIGNATURE = "\x89PNG\r\n\x1a\n";
const DROPPED_CHUNKS = ['tEXt', 'iTXt', 'zTXt', 'eXIf'];

/**
 * @return array{0: string, 1: int}|null the sanitized bytes and the number of dropped chunks, or null for a malformed PNG
 */
function sanitizePng(string $bytes): ?array
{
    if (substr($bytes, 0, 8) !== PNG_SIGNATURE) {
        return null;
    }

    $length = strlen($bytes);
    $offset = 8;
    $out = PNG_SIGNATURE;
    $dropped = 0;
    $first = true;

    while ($offset + 12 <= $length) {
        $unpacked = unpack('Nsize', substr($bytes, $offset, 4));
        $size = is_array($unpacked) ? (int) $unpacked['size'] : -1;
        $type = substr($bytes, $offset + 4, 4);
        $end = $offset + 12 + $size;

        if ($size < 0 || $end > $length || preg_match('/^[A-Za-z]{4}$/', $type) !== 1) {
            return null;
        }

        $crc = unpack('Ncrc', substr($bytes, $end - 4, 4));

        if (! is_array($crc) || (int) $crc['crc'] !== crc32(substr($bytes, $offset + 4, 4 + $size))) {
            return null;
        }

        if ($first && $type !== 'IHDR') {
            return null;
        }

        $first = false;

        if (in_array($type, DROPPED_CHUNKS, true)) {
            ++$dropped;
        } else {
            $out .= substr($bytes, $offset, 12 + $size);
        }

        $offset = $end;

        if ($type === 'IEND') {
            return $offset === $length ? [$out, $dropped] : null;
        }
    }

    return null;
}

if ($argc !== 3) {
    fwrite(STDERR, "usage: sanitize-png.php <input.png> <output.png>\n");
    exit(2);
}

$input = file_get_contents($argv[1]);
$result = is_string($input) ? sanitizePng($input) : null;

if ($result === null || file_put_contents($argv[2], $result[0]) === false) {
    fwrite(STDERR, "sanitize-png.php: the input is not a well-formed PNG\n");
    exit(1);
}

fwrite(STDERR, 'dropped=' . $result[1] . "\n");
