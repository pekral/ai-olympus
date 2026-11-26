#!/usr/bin/env php
<?php

declare(strict_types = 1);

/**
 * Place the screenshots of a defect review into a JIRA comment source, or take their slot lines out.
 *
 * Usage: php screenshot-slots.php strip < body
 *        php screenshot-slots.php wiki <mapping.json> < body
 *
 * The comment source marks the place of a screenshot with one line `{screenshot:<finding id>:<head|base>}` directly
 * under the bullet of its finding. `strip` removes every such line, so a comment that shows no image never carries one.
 * `wiki` joins each slot to the bullet above it as `\\ *<label>:* \\ !<attachment name>|thumbnail!`, for the slot the
 * mapping names, and drops every other slot. It writes the image markup only for a mapping entry: the label must be
 * `This change` or `Default branch (<name>)`, and the name must match `^[a-z0-9-]{1,140}\.png$`. Every other `!` that
 * could start an image is escaped as `\!` outside a `[label|link]`, so a finding text never shows an attachment.
 * The mapping is `{"<finding id>:<state>": {"filename": "...", "label": "..."}}`.
 *
 * Exit codes: 0 written, 1 unreadable or invalid mapping, 2 usage error.
 */
const SLOT_LINE = '/^\{screenshot:([rf]-[0-9a-f]{16}:(?:head|base))\}$/';
const ALLOWED_LABEL = '/^(?:This change|Default branch \([A-Za-z0-9._\/-]{1,100}\))$/';
const ALLOWED_NAME = '/^[a-z0-9-]{1,140}\.png$/';

function escapeWikiText(string $line): string
{
    $escaped = '';

    foreach (preg_split('/(\[[^\]]*\])/', $line, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $part) {
        $isLink = str_starts_with($part, '[') && str_ends_with($part, ']');
        $escaped .= $isLink ? $part : (string) preg_replace('/!(?=\S)/', '\\\\!', str_replace('{screenshot:', '\\{screenshot:', $part));
    }

    return $escaped;
}

/**
 * @param array<string, array{filename: string, label: string}> $mapping
 */
function imageSuffix(string $key, array $mapping): string
{
    $entry = $mapping[$key] ?? null;

    if ($entry === null || preg_match(ALLOWED_LABEL, $entry['label']) !== 1 || preg_match(ALLOWED_NAME, $entry['filename']) !== 1) {
        return '';
    }

    return ' \\\\ *' . str_replace('_', '\\_', $entry['label']) . ':* \\\\ !' . $entry['filename'] . '|thumbnail!';
}

/**
 * @param list<string> $lines
 * @param array<string, array{filename: string, label: string}>|null $mapping null strips the slots without placing any
 * @return list<string>
 */
function placeSlots(array $lines, ?array $mapping): array
{
    $out = [];

    foreach ($lines as $line) {
        if (preg_match(SLOT_LINE, $line, $slot) === 1) {
            $last = array_key_last($out);

            if ($mapping !== null && $last !== null && str_starts_with($out[$last], '* ')) {
                $out[$last] .= imageSuffix($slot[1], $mapping);
            }

            continue;
        }

        $out[] = $mapping === null ? $line : escapeWikiText($line);
    }

    return $out;
}

$mode = $argv[1] ?? '';
$mapping = null;

if ($mode === 'wiki' && $argc === 3) {
    $decoded = json_decode((string) file_get_contents($argv[2]), true);

    if (! is_array($decoded)) {
        fwrite(STDERR, "screenshot-slots.php: the mapping is not a JSON object\n");
        exit(1);
    }

    /** @var array<string, array{filename: string, label: string}> $mapping */
    $mapping = $decoded;
} elseif ($mode !== 'strip' || $argc !== 2) {
    fwrite(STDERR, "usage: screenshot-slots.php strip|wiki <mapping.json> < body\n");
    exit(2);
}

$body = (string) stream_get_contents(STDIN);
$lines = explode("\n", rtrim($body, "\n"));

echo implode("\n", placeSlots($lines, $mapping)) . "\n";
