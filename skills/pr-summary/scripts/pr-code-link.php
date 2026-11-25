<?php

declare(strict_types = 1);

const PR_CODE_LINK_PULL_REQUEST = '#^https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/pull/[1-9][0-9]*$#';

const PR_CODE_LINK_MAX_LINE = 10_000_000;

const PR_CODE_LINK_USAGE = "Usage: pr-code-link.php <pull-request-url> [<path> <line>]\n"
    . "  Prints the link to the whole change of the pull request, or with <path> and <line>\n"
    . "  the link to that line of that file in the changes of the pull request.\n"
    . "  Exit codes: 0 the link is printed, 1 a refused argument.\n";

function prCodeLinkRefuse(string $reason): never
{
    fwrite(STDERR, 'pr-code-link.php: ' . $reason . "\n" . PR_CODE_LINK_USAGE);
    exit(1);
}

function prCodeLinkValidPath(string $path): bool
{
    return $path !== ''
        && strlen($path) <= 4_096
        && preg_match('/[\x00-\x1F\x7F]/', $path) !== 1
        && !str_starts_with($path, '/')
        && !in_array('..', explode('/', $path), strict: true)
        && !in_array('', explode('/', $path), strict: true);
}

$arguments = array_slice($argv, 1);

if (!in_array(count($arguments), [1, 3], strict: true)) {
    prCodeLinkRefuse('expected a pull-request URL, optionally followed by a path and a line');
}

if (preg_match(PR_CODE_LINK_PULL_REQUEST, $arguments[0]) !== 1) {
    prCodeLinkRefuse('not a github.com pull-request URL: ' . $arguments[0]);
}

if (count($arguments) === 1) {
    echo $arguments[0] . "/files\n";
    exit(0);
}

if (!prCodeLinkValidPath($arguments[1])) {
    prCodeLinkRefuse('not a relative repository path');
}

if (preg_match('/^[1-9][0-9]*$/', $arguments[2]) !== 1 || (int) $arguments[2] > PR_CODE_LINK_MAX_LINE) {
    prCodeLinkRefuse('not a line number: ' . $arguments[2]);
}

echo $arguments[0] . '/files#diff-' . hash('sha256', $arguments[1]) . 'R' . $arguments[2] . "\n";
