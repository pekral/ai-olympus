#!/usr/bin/env php
<?php

declare(strict_types = 1);

/**
 * Check the Wiki Markup source of a support-analysis comment before it is published.
 *
 * Three checks, each on whole words so that `snadný` never reads as `snad` and `nemožná`
 * never reads as `možná`:
 * - the source is at most 3 000 characters long;
 * - it carries no estimating word (Czech or English);
 * - it carries no developer token: a file extension, `::`, `->`, inline code, a code
 *   block, or a hexadecimal hash (10 to 40 hex characters with at least one letter, so a
 *   phone or an invoice number is not a hash).
 *
 * Usage: check-comment.php <source-file>
 *
 * Exit codes: 0 the source passes, 1 usage or read error, 2 at least one violation.
 * stdout: one `violation: <check>: <match>` line per violation.
 */
const SUPPORT_COMMENT_MAX_CHARACTERS = 3000;

const SUPPORT_COMMENT_ESTIMATE_PATTERN = '/(?<![\p{L}\p{N}])('
    . 'pravděpodob\p{L}*|nejspíš|nejspíše|asi|zřejmě|možná|snad|odhadem|domnív\p{L}*|hypotéz\p{L}*'
    . '|mělo by|mohlo by|vypadá to'
    . '|probabl\p{L}*|likely|maybe|perhaps|seems?|presumabl\p{L}*|might|could be|we assume|hypothes\p{L}*'
    . ')(?![\p{L}\p{N}])/iu';

const SUPPORT_COMMENT_DEVELOPER_PATTERN = '/\.(?:php|ts|js|sql|json|ya?ml)\b|::|->|\{\{|\{code'
    . '|(?<![0-9A-Za-z])(?=[0-9a-f]*[a-f])[0-9a-f]{10,40}(?![0-9A-Za-z])/u';

/**
 * @return list<string>
 */
function supportCommentViolations(string $source): array
{
    $violations = [];
    $length = mb_strlen($source);

    if ($length > SUPPORT_COMMENT_MAX_CHARACTERS) {
        $violations[] = sprintf('length: %d characters, the limit is %d', $length, SUPPORT_COMMENT_MAX_CHARACTERS);
    }

    if (preg_match_all(SUPPORT_COMMENT_ESTIMATE_PATTERN, $source, $estimates) > 0) {
        foreach ($estimates[1] as $estimate) {
            $violations[] = 'estimate: ' . $estimate;
        }
    }

    if (preg_match_all(SUPPORT_COMMENT_DEVELOPER_PATTERN, $source, $tokens) > 0) {
        foreach ($tokens[0] as $token) {
            $violations[] = 'developer token: ' . $token;
        }
    }

    return $violations;
}

if (!isset($argv[1]) || isset($argv[2])) {
    fwrite(STDERR, "Usage: check-comment.php <source-file>\n");
    exit(1);
}

$source = is_file($argv[1]) ? file_get_contents($argv[1]) : false;

if ($source === false) {
    fwrite(STDERR, 'check-comment.php: cannot read ' . $argv[1] . "\n");
    exit(1);
}

$violations = supportCommentViolations($source);

foreach ($violations as $violation) {
    echo 'violation: ' . $violation . "\n";
}

exit($violations === [] ? 0 : 2);
