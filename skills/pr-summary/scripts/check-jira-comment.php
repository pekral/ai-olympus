<?php

declare(strict_types = 1);

const JIRA_SUMMARY_MAX_CHARACTERS = 3000;

const JIRA_SUMMARY_MAX_BULLETS = 3;

const JIRA_SUMMARY_MAX_BULLET_CHARACTERS = 200;

const JIRA_SUMMARY_SECTIONS_WITHOUT_CRITERIA = 3;

const JIRA_SUMMARY_GROUP_HEADING = '/^h2\. \[PR #\d+\|/u';

const JIRA_SUMMARY_MARKDOWN = '/^(?:#{1,6}\s|>|-\s)|\*\*[^\s*]|`|\]\(/u';

const JIRA_SUMMARY_DEVELOPER_TOKEN = '/\{\{|\{code|(?<![0-9A-Za-z])(?=[0-9a-f]*[a-f])[0-9a-f]{10,40}(?![0-9A-Za-z])/u';

const JIRA_SUMMARY_USAGE = "Usage: check-jira-comment.php --criteria <stated|none> <source-file>\n"
    . "  Checks the Wiki Markup source of a pr-summary JIRA comment before it is published.\n"
    . "  --criteria stated  the assignment states explicit acceptance criteria\n"
    . "  --criteria none    the assignment states none, so the comment has no criteria section\n"
    . "  Exit codes: 0 the source passes, 1 usage or read error, 2 at least one violation.\n"
    . "  stdout: one `violation: <check>: <detail>` line per violation.\n";

/**
 * @return list<string>
 */
function jiraSummaryLines(string $source): array
{
    return array_values(array_filter(
        preg_split('/\R/u', $source) ?: [],
        static fn (string $line): bool => trim($line) !== '',
    ));
}

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

    foreach (array_slice($sections, -2) as $section) {
        $bullets = array_values(array_filter(
            $section['body'],
            static fn (string $line): bool => preg_match('/^\*+ /u', $line) === 1,
        ));

        if (count($bullets) > JIRA_SUMMARY_MAX_BULLETS) {
            $violations[] = sprintf('tldr: %d bullets under "%s", the limit is %d', count($bullets), $section['heading'], JIRA_SUMMARY_MAX_BULLETS);
        }

        foreach ($bullets as $bullet) {
            if (mb_strlen($bullet) - 2 > JIRA_SUMMARY_MAX_BULLET_CHARACTERS) {
                $violations[] = sprintf(
                    'tldr: a bullet of %d characters under "%s", the limit is %d',
                    mb_strlen($bullet) - 2,
                    $section['heading'],
                    JIRA_SUMMARY_MAX_BULLET_CHARACTERS,
                );
            }
        }
    }

    return $violations;
}

/**
 * @param list<string> $lines
 * @return list<string>
 */
function jiraSummarySingleShapeViolations(array $lines, bool $criteriaStated): array
{
    $sections = jiraSummarySections($lines, 'h2');
    $expected = JIRA_SUMMARY_SECTIONS_WITHOUT_CRITERIA + ($criteriaStated ? 1 : 0);
    $violations = [];

    if (count($sections) !== $expected) {
        $violations[] = sprintf(
            'sections: %d h2 sections, expected %d %s a criteria section',
            count($sections),
            $expected,
            $criteriaStated ? 'with' : 'without',
        );
    }

    return [...$violations, ...jiraSummaryTldrViolations($sections)];
}

/**
 * @param list<string> $lines
 * @return list<string>
 */
function jiraSummaryGroupedShapeViolations(array $lines, bool $criteriaStated): array
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

    if ($issueLevel !== ($criteriaStated ? 1 : 0)) {
        $violations[] = sprintf('sections: %d issue-level h2 sections, expected %d', $issueLevel, $criteriaStated ? 1 : 0);
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
        if ($line !== '----' && preg_match(JIRA_SUMMARY_MARKDOWN, $line) === 1) {
            $violations[] = 'format: Markdown: ' . $line;
        }

        $withoutLinks = (string) preg_replace('/\[~accountid:[^\]]+\]|\[[^|\]]+\|[^\]]+\]/u', '', $line);

        if (preg_match_all(JIRA_SUMMARY_DEVELOPER_TOKEN, $withoutLinks, $tokens) > 0) {
            foreach ($tokens[0] as $token) {
                $violations[] = 'developer token: ' . $token;
            }
        }
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
function jiraSummaryViolations(string $source, bool $criteriaStated): array
{
    $lines = jiraSummaryLines($source);
    $violations = [];
    $length = mb_strlen($source);

    if ($length > JIRA_SUMMARY_MAX_CHARACTERS) {
        $violations[] = sprintf('length: %d characters, the limit is %d', $length, JIRA_SUMMARY_MAX_CHARACTERS);
    }

    $grouped = array_filter($lines, static fn (string $line): bool => preg_match(JIRA_SUMMARY_GROUP_HEADING, $line) === 1) !== [];

    return [
        ...$violations,
        ...jiraSummaryFrameViolations($lines),
        ...($grouped ? jiraSummaryGroupedShapeViolations($lines, $criteriaStated) : jiraSummarySingleShapeViolations($lines, $criteriaStated)),
        ...jiraSummaryTextViolations($lines),
    ];
}

if (!isset($argv[3]) || isset($argv[4]) || $argv[1] !== '--criteria' || !in_array($argv[2], ['stated', 'none'], true)) {
    fwrite(STDERR, JIRA_SUMMARY_USAGE);
    exit(1);
}

$source = is_file($argv[3]) ? file_get_contents($argv[3]) : false;

if ($source === false) {
    fwrite(STDERR, 'check-jira-comment.php: cannot read ' . $argv[3] . "\n");
    exit(1);
}

$violations = jiraSummaryViolations($source, $argv[2] === 'stated');

foreach ($violations as $violation) {
    echo 'violation: ' . $violation . "\n";
}

exit($violations === [] ? 0 : 2);
