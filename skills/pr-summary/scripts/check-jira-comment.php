<?php

declare(strict_types = 1);

require __DIR__ . '/../../_shared/report-check-common.php';

const JIRA_SUMMARY_MAX_CHARACTERS = 3000;

const JIRA_SUMMARY_MAX_TLDR_CHARACTERS = 600;

const JIRA_SUMMARY_SECTIONS_WITHOUT_CRITERIA = 2;

const JIRA_SUMMARY_GROUP_HEADING = '/^h2\. \[PR #\d+\|/u';

const JIRA_SUMMARY_MARKDOWN = '/^(?:#{1,6}\s|>|-\s)|\*\*[^\s*]|`|\]\(/u';

const JIRA_SUMMARY_DEVELOPER_TOKEN = '/' . REPORT_CHECK_DEVELOPER_TOKEN . '/u';

const JIRA_SUMMARY_USAGE = "Usage: check-jira-comment.php --criteria <unmet|none> <source-file>\n"
    . "  Checks the Wiki Markup source of a pr-summary JIRA comment before it is published.\n"
    . "  --criteria unmet   an explicit requirement still needs a change or an answer\n"
    . "  --criteria none    no requirement needs action, so the comment has no criteria section\n"
    . "  Exit codes: 0 the source passes, 1 usage or read error, 2 at least one violation.\n"
    . "  stdout: one `violation: <check>: <detail>` line per violation.\n";

/**
 * @param list<string> $lines
 * @return list<array{heading: string, body: list<string>}>
 */
function jiraSummarySections(array $lines, string $level): array
{
    $sections = [];

    foreach ($lines as $line) {
        if (str_starts_with($line, $level . '. ')) {
            $sections[] = ['heading' => $line, 'body' => []];

            continue;
        }

        if ($sections !== [] && $line !== '----') {
            $sections[count($sections) - 1]['body'][] = $line;

            continue;
        }

        if ($line === '----') {
            break;
        }
    }

    return $sections;
}

/**
 * @param list<array{heading: string, body: list<string>}> $sections
 * @return list<string>
 */
function jiraSummaryTldrViolations(array $sections): array
{
    $violations = [];

    foreach (array_slice($sections, -1) as $section) {
        if (preg_match('/^h[23]\. TL;DR$/u', $section['heading']) !== 1) {
            $violations[] = 'tldr: the final section must be TL;DR';
        }

        $paragraph = array_values(array_filter(
            $section['body'],
            static fn (string $line): bool => preg_match('/^\[[^|\]]+\|[^\]]+\](?: · \[[^|\]]+\|[^\]]+\])*$/u', $line) !== 1,
        ));

        if (count($paragraph) !== 1 || preg_match('/^(?:\*+ |#+ |h[1-6]\. )/u', $paragraph[0] ?? '') === 1) {
            $violations[] = 'tldr: use one paragraph combining changes and deployment impact';
        }

        $length = mb_strlen(implode(' ', $paragraph));

        if ($length > JIRA_SUMMARY_MAX_TLDR_CHARACTERS) {
            $violations[] = sprintf('tldr: %d characters, the limit is %d', $length, JIRA_SUMMARY_MAX_TLDR_CHARACTERS);
        }
    }

    return $violations;
}

/**
 * @param list<string> $lines
 * @return list<string>
 */
function jiraSummarySingleShapeViolations(array $lines, bool $criteriaUnmet): array
{
    $sections = jiraSummarySections($lines, 'h2');
    $expected = JIRA_SUMMARY_SECTIONS_WITHOUT_CRITERIA + ($criteriaUnmet ? 1 : 0);
    $violations = [];

    if (count($sections) !== $expected) {
        $violations[] = sprintf(
            'sections: %d h2 sections, expected %d %s a criteria section',
            count($sections),
            $expected,
            $criteriaUnmet ? 'with' : 'without',
        );
    }

    return [...$violations, ...jiraSummaryTldrViolations($sections)];
}

/**
 * @param list<string> $lines
 * @return list<string>
 */
function jiraSummaryGroupedShapeViolations(array $lines, bool $criteriaUnmet): array
{
    $violations = [];
    $issueLevel = 0;
    $group = null;
    $groups = [];

    foreach ($lines as $line) {
        if ($line === '----') {
            break;
        }

        if (preg_match(JIRA_SUMMARY_GROUP_HEADING, $line) === 1) {
            $group = $line;
            $groups[$group] = [];

            continue;
        }

        if (str_starts_with($line, 'h2. ')) {
            $issueLevel++;

            if ($groups !== []) {
                $violations[] = 'sections: an issue-level section below a pull-request group: ' . $line;
            }

            continue;
        }

        if ($group !== null) {
            $groups[$group][] = $line;
        }
    }

    if ($issueLevel !== ($criteriaUnmet ? 1 : 0)) {
        $violations[] = sprintf('sections: %d issue-level h2 sections, expected %d', $issueLevel, $criteriaUnmet ? 1 : 0);
    }

    foreach ($groups as $heading => $body) {
        $sections = jiraSummarySections($body, 'h3');

        if (count($sections) !== JIRA_SUMMARY_SECTIONS_WITHOUT_CRITERIA) {
            $violations[] = sprintf(
                'sections: %d h3 sections under "%s", expected %d',
                count($sections),
                $heading,
                JIRA_SUMMARY_SECTIONS_WITHOUT_CRITERIA,
            );
        }

        $violations = [...$violations, ...jiraSummaryTldrViolations($sections)];
    }

    return $violations;
}

/**
 * @param list<string> $lines
 * @return list<string>
 */
function jiraSummaryTextViolations(array $lines): array
{
    $violations = [];

    foreach ($lines as $line) {
        if ($line !== '----') {
            $violations = [...$violations, ...reportCheckMarkdownViolations($line, JIRA_SUMMARY_MARKDOWN)];
        }

        $withoutLinks = (string) preg_replace('/\[~accountid:[^\]]+\]|\[[^|\]]+\|[^\]]+\]/u', '', $line);
        $violations = [...$violations, ...reportCheckDeveloperTokenViolations($withoutLinks, JIRA_SUMMARY_DEVELOPER_TOKEN)];
    }

    return $violations;
}

/**
 * @param list<string> $lines
 * @return list<string>
 */
function jiraSummaryFrameViolations(array $lines): array
{
    $violations = [];

    if (preg_match('/^h2\. /u', $lines[0] ?? '') !== 1) {
        $violations[] = 'status line: the comment must open with an h2 heading, no text stands above it';
    }

    $rule = array_search('----', $lines, true);

    if ($rule === false || preg_match('/^_.+_$/u', $lines[$rule + 1] ?? '') !== 1) {
        $violations[] = 'footer: a ---- line followed by the italic footer line is missing';
    }

    return $violations;
}

/**
 * @return list<string>
 */
function jiraSummaryViolations(string $source, bool $criteriaUnmet): array
{
    $lines = reportCheckLines($source);

    $grouped = array_filter($lines, static fn (string $line): bool => preg_match(JIRA_SUMMARY_GROUP_HEADING, $line) === 1) !== [];

    return [
        ...reportCheckLengthViolations($source, JIRA_SUMMARY_MAX_CHARACTERS),
        ...jiraSummaryFrameViolations($lines),
        ...($grouped ? jiraSummaryGroupedShapeViolations($lines, $criteriaUnmet) : jiraSummarySingleShapeViolations($lines, $criteriaUnmet)),
        ...jiraSummaryTextViolations($lines),
    ];
}

if (!isset($argv[3]) || isset($argv[4]) || $argv[1] !== '--criteria' || !in_array($argv[2], ['unmet', 'none'], true)) {
    fwrite(STDERR, JIRA_SUMMARY_USAGE);
    exit(1);
}

reportCheckFinish(jiraSummaryViolations(reportCheckReadSource('check-jira-comment.php', $argv[3]), $argv[2] === 'unmet'));
