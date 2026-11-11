#!/usr/bin/env php
<?php

declare(strict_types = 1);

const LOCK_DIFF_USAGE = <<<'TXT'
Usage: lock-diff.php <old-composer.lock> <new-composer.lock>

Lists every package whose locked version differs between two composer.lock files. Every value
is read from the two files; nothing is estimated.

A package whose version stays but whose source reference (fallback: dist reference) changes is an
"updated" change of level "other"; "from" and "to" then read "<version>@<7-character reference>".
A version that goes down is a "downgraded" change of level "other".
A change from 0.x.y to 0.z.w with a different minor is "major" (semver: a 0.x minor may break).

stdout: {"changes": [{"name": "vendor/pkg", "type": "updated|downgraded|added|removed", "dev": bool,
         "from": "1.2.3|null", "to": "1.3.0|null", "level": "major|minor|patch|other|null"}]}
exit:   0 diff printed, 1 usage error or unreadable input

TXT;

/**
 * @return array<string, array{version: string, reference: string|null, dev: bool}>
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
                $packages[$entry['name']] = [
                    'version' => $entry['version'],
                    'reference' => lockReference($entry),
                    'dev' => $dev,
                ];
            }
        }
    }

    return $packages;
}

/**
 * @param array<mixed> $entry
 */
function lockReference(array $entry): ?string
{
    foreach (['source', 'dist'] as $key) {
        $reference = is_array($entry[$key] ?? null) ? ($entry[$key]['reference'] ?? null) : null;

        if (is_string($reference) && $reference !== '') {
            return $reference;
        }
    }

    return null;
}

function lockNormalize(string $version): string
{
    return preg_match('~^v\d~i', $version) === 1 ? substr($version, 1) : $version;
}

function lockIsDowngrade(string $from, string $to): bool
{
    $numeric = '~^v?\d+(?:\.\d+)*(?:[-.+]|$)~i';

    return preg_match($numeric, $from) === 1
        && preg_match($numeric, $to) === 1
        && version_compare(lockNormalize($from), lockNormalize($to), '>');
}

function lockLevel(string $from, string $to): string
{
    $pattern = '~^v?(\d+)\.(\d+)(?:\.(\d+))?~';

    if (preg_match($pattern, $from, $old) !== 1 || preg_match($pattern, $to, $new) !== 1) {
        return 'other';
    }

    if ($old[1] === '0' && $new[1] === '0' && ($old[2] ?? '0') !== ($new[2] ?? '0')) {
        return 'major';
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
    $from = $before['version'] ?? null;
    $to = $after['version'] ?? null;
    $type = $before === null ? 'added' : ($after === null ? 'removed' : 'updated');
    $level = null;

    if ($before !== null && $after !== null) {
        if ($before['version'] === $after['version']) {
            if ($before['reference'] === null || $after['reference'] === null || $before['reference'] === $after['reference']) {
                continue;
            }

            $from = $before['version'] . '@' . substr($before['reference'], 0, 7);
            $to = $after['version'] . '@' . substr($after['reference'], 0, 7);
            $level = 'other';
        } elseif (lockIsDowngrade($before['version'], $after['version'])) {
            $type = 'downgraded';
            $level = 'other';
        } else {
            $level = lockLevel($before['version'], $after['version']);
        }
    }

    $changes[] = [
        'name' => $name,
        'type' => $type,
        'dev' => ($after ?? $before)['dev'] ?? false,
        'from' => $from,
        'to' => $to,
        'level' => $level,
    ];
}

echo json_encode(['changes' => $changes], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
