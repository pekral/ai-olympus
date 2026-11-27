<?php

declare(strict_types = 1);

const REPORT_CHECK_DEVELOPER_TOKEN = '\{\{|\{code|(?<![0-9A-Za-z])(?=[0-9a-f]*[a-f])[0-9a-f]{10,40}(?![0-9A-Za-z])';

const JIRA_REVIEW_BYLINE = 'AI Olympus · ';

function jiraReviewByline(string $source): bool
{
    return str_starts_with(reportCheckLines($source)[0] ?? '', JIRA_REVIEW_BYLINE);
}

/**
 * @return list<string>
 */
function reportCheckLines(string $source): array
{
    $lines = preg_split('/\R/u', $source);

    return array_values(array_filter(
        $lines === false ? [] : $lines,
        static fn (string $line): bool => trim($line) !== '',
    ));
}

/**
 * @return list<string>
 */
function reportCheckLengthViolations(string $source, int $limit): array
{
    $length = mb_strlen($source);

    return $length > $limit ? [sprintf('length: %d characters, the limit is %d', $length, $limit)] : [];
}

/**
 * @return list<string>
 */
function reportCheckDeveloperTokenViolations(string $text, string $pattern): array
{
    if (preg_match_all($pattern, $text, $tokens) === 0) {
        return [];
    }

    return array_map(static fn (string $token): string => 'developer token: ' . $token, $tokens[0]);
}

/**
 * @return list<string>
 */
function reportCheckMarkdownViolations(string $line, string $pattern): array
{
    return preg_match($pattern, $line) === 1 ? ['format: Markdown: ' . $line] : [];
}

function reportCheckReadSource(string $script, string $path): string
{
    $source = is_file($path) ? file_get_contents($path) : false;

    if ($source === false) {
        fwrite(STDERR, $script . ': cannot read ' . $path . "\n");
        exit(1);
    }

    return $source;
}

/**
 * @param list<string> $violations
 */
function reportCheckFinish(array $violations): never
{
    foreach ($violations as $violation) {
        echo 'violation: ' . $violation . "\n";
    }

    exit($violations === [] ? 0 : 2);
}
