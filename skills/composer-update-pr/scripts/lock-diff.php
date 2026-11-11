#!/usr/bin/env php
<?php

declare(strict_types = 1);

const LOCK_DIFF_USAGE = <<<'TXT'
Usage: lock-diff.php <old-composer.lock> <new-composer.lock>

Lists every package whose locked version differs between two composer.lock files. Every value
is read from the two files; nothing is estimated.

stdout: {"changes": [{"name": "vendor/pkg", "type": "updated|added|removed", "dev": bool,
         "from": "1.2.3|null", "to": "1.3.0|null", "level": "major|minor|patch|other|null"}]}
exit:   0 diff printed, 1 usage error or unreadable input

TXT;

/**
 * @return array<string, array{version: string, dev: bool}>
 */
function lockPackages(string $path): array
{
    $content = is_file($path) ? file_get_contents($path) : false;
    $lock = $content === false ? null : json_decode($content, true);

    if (!is_array($lock)) {
        fwrite(STDERR, "lock-diff.php: cannot read a composer.lock from {$path}\n");

        exit(1);
    }

    $packages = [];

    foreach (['packages' => false, 'packages-dev' => true] as $section => $dev) {
        $entries = is_array($lock[$section] ?? null) ? $lock[$section] : [];

        foreach ($entries as $entry) {
            if (is_array($entry) && is_string($entry['name'] ?? null) && is_string($entry['version'] ?? null)) {
                $packages[$entry['name']] = ['version' => $entry['version'], 'dev' => $dev];
            }
        }
    }

    return $packages;
}

function lockLevel(string $from, string $to): string
{
    $pattern = '~^v?(\d+)\.(\d+)(?:\.(\d+))?~';

    if (preg_match($pattern, $from, $old) !== 1 || preg_match($pattern, $to, $new) !== 1) {
        return 'other';
    }

    foreach (['major', 'minor', 'patch'] as $index => $level) {
        if (($old[$index + 1] ?? '0') !== ($new[$index + 1] ?? '0')) {
            return $level;
        }
    }

    return 'other';
}

if ($argc !== 3) {
    fwrite(STDERR, LOCK_DIFF_USAGE);

    exit(1);
}

$old = lockPackages($argv[1]);
$new = lockPackages($argv[2]);
$names = array_unique([...array_keys($old), ...array_keys($new)]);
sort($names);

$changes = [];

foreach ($names as $name) {
    $before = $old[$name] ?? null;
    $after = $new[$name] ?? null;

    if ($before !== null && $after !== null && $before['version'] === $after['version']) {
        continue;
    }

    $changes[] = [
        'name' => $name,
        'type' => $before === null ? 'added' : ($after === null ? 'removed' : 'updated'),
        'dev' => ($after ?? $before)['dev'] ?? false,
        'from' => $before['version'] ?? null,
        'to' => $after['version'] ?? null,
        'level' => $before !== null && $after !== null ? lockLevel($before['version'], $after['version']) : null,
    ];
}

echo json_encode(['changes' => $changes], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
