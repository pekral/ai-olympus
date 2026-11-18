#!/usr/bin/env php
<?php

declare(strict_types = 1);

/**
 * Check the Wiki Markup source of a support-analysis comment before it is published.
 *
 * Four checks, the word checks on whole words so that `snadný` never reads as `snad` and
 * `nemožná` never reads as `možná`:
 * - the source is at most 1 500 characters long;
 * - it carries no estimating word (Czech or English);
 * - it carries no developer token: a file extension, `::`, `->`, inline code, a code
 *   block, or a hexadecimal hash (10 to 40 hex characters with at least one letter, so a
 *   phone or an invoice number is not a hash);
 * - it is a TL;DR the ADF converter renders intact: `h2. TL;DR` is the first line and the
 *   only heading, every `{quote}` opens and closes on one line, and no Markdown leaks.
 *
 * Usage: check-comment.php <source-file>
 *
 * Exit codes: 0 the source passes, 1 usage or read error, 2 at least one violation.
 * stdout: one `violation: <check>: <match>` line per violation.
 */
require __DIR__ . '/../../_shared/report-check-common.php';

const SUPPORT_COMMENT_MAX_CHARACTERS = 1500;

const SUPPORT_COMMENT_HEADING = 'h2. TL;DR';

const SUPPORT_COMMENT_ESTIMATE_PATTERN = '/(?<![\p{L}\p{N}])('
    . 'pravděpodob\p{L}*|nejspíš|nejspíše|asi|zřejmě|možná|snad|odhadem|domnív\p{L}*|hypotéz\p{L}*'
    . '|mělo by|mohlo by|vypadá to'
    . '|probabl\p{L}*|likely|maybe|perhaps|seems?|presumabl\p{L}*|might|could be|we assume|hypothes\p{L}*'
    . ')(?![\p{L}\p{N}])/iu';

const SUPPORT_COMMENT_DEVELOPER_PATTERN = '/\.(?:php|ts|js|sql|json|ya?ml)\b|::|->|' . REPORT_CHECK_DEVELOPER_TOKEN . '/u';

const SUPPORT_COMMENT_MARKDOWN_PATTERN = '/^(?:#{2,}\s|>|-\s|\*\*)|\*\*|`|\]\(/u';

/**
 * @return list<string>
 */
function supportCommentViolations(string $source): array
{
    $violations = reportCheckLengthViolations($source, SUPPORT_COMMENT_MAX_CHARACTERS);

    if (preg_match_all(SUPPORT_COMMENT_ESTIMATE_PATTERN, $source, $estimates) > 0) {
        foreach ($estimates[1] as $estimate) {
            $violations[] = 'estimate: ' . $estimate;
        }
    }

    return [
        ...$violations,
        ...reportCheckDeveloperTokenViolations($source, SUPPORT_COMMENT_DEVELOPER_PATTERN),
        ...supportCommentFormatViolations($source),
    ];
}

/**
 * @return list<string>
 */
function supportCommentFormatViolations(string $source): array
{
    $lines = reportCheckLines($source);
    $violations = [];

    if (($lines[0] ?? '') !== SUPPORT_COMMENT_HEADING) {
        $violations[] = 'format: the first line must be ' . SUPPORT_COMMENT_HEADING;
    }

    foreach ($lines as $number => $line) {
        if ($number > 0 && preg_match('/^h[1-6]\./u', $line) === 1) {
            $violations[] = 'format: a second heading: ' . $line;
        }

        if (str_contains($line, '{quote}') && preg_match('/^\{quote\}(?:(?!\{quote\}).)+\{quote\}$/u', $line) !== 1) {
            $violations[] = 'format: a quote must open and close on one line: ' . $line;
        }

        $violations = [...$violations, ...reportCheckMarkdownViolations($line, SUPPORT_COMMENT_MARKDOWN_PATTERN)];
    }

    return $violations;
}

if (!isset($argv[1]) || isset($argv[2])) {
    fwrite(STDERR, "Usage: check-comment.php <source-file>\n");
    exit(1);
}

reportCheckFinish(supportCommentViolations(reportCheckReadSource('check-comment.php', $argv[1])));
