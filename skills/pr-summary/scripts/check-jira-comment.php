<?php

declare(strict_types = 1);

require __DIR__ . '/../../_shared/report-check-common.php';

const JIRA_SUMMARY_MAX_CHARACTERS = 3_000;

const JIRA_SUMMARY_MAX_TLDR_CHARACTERS = 600;

const JIRA_SUMMARY_SECTIONS_WITHOUT_CRITERIA = 2;

const JIRA_SUMMARY_GROUP_HEADING = '/^h2\. \[PR #\d+\|/u';

const JIRA_SUMMARY_BLOCK_OPEN = '/^\{expand:(?<title>[^{}]+)\}$/u';

const JIRA_SUMMARY_BLOCK_CLOSE = '{expand}';

const JIRA_SUMMARY_MARKER_SLOT = '{agent-marker}';

const JIRA_SUMMARY_LINKS_LINE = '/^\[[^|\]]+\|[^\]]+\](?: · \[[^|\]]+\|[^\]]+\])*$/u';

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
 * @return array{blocks: list<array{title: string, group: int, body: list<string>}>, groups: int, stray: list<string>, violations: list<string>}
 */
function jiraSummaryLayout(array $lines): array
{
    $layout = ['blocks' => [], 'groups' => 0, 'stray' => [], 'violations' => []];
    $open = null;

    foreach (array_slice($lines, 1) as $line) {
        if ($line === '----') {
            break;
        }

        $opening = preg_match(JIRA_SUMMARY_BLOCK_OPEN, $line, $match) === 1;

        if ($open !== null) {
            if ($line === JIRA_SUMMARY_BLOCK_CLOSE) {
                $layout['blocks'][] = $open;
                $open = null;
            } elseif ($opening) {
                $layout['violations'][] = 'blocks: a collapsed block inside another one: ' . $line;
            } else {
                $open['body'][] = $line;
            }

            continue;
        }

        if ($opening) {
            $open = ['title' => trim($match['title']), 'group' => $layout['groups'], 'body' => []];
        } elseif (preg_match(JIRA_SUMMARY_GROUP_HEADING, $line) === 1) {
            $layout['groups']++;
        } elseif (preg_match(JIRA_SUMMARY_LINKS_LINE, $line) !== 1) {
            $layout['stray'][] = $line;
        }
    }

    if ($open !== null) {
        $layout['violations'][] = 'blocks: a collapsed block without its closing line: ' . $open['title'];
    }

    return $layout;
}

/**
 * @param list<array{title: string, group: int, body: list<string>}> $blocks
 * @return list<string>
 */
function jiraSummaryTldrViolations(array $blocks): array
{
    $violations = [];

    foreach (array_slice($blocks, -1) as $block) {
        if ($block['title'] !== 'TL;DR') {
            $violations[] = 'tldr: the final section must be TL;DR';
        }

        $paragraph = $block['body'];

        if (count($paragraph) !== 1 || preg_match('/^(?:\*+ |#+ |h[1-6]\. )/u', $paragraph[0]) === 1) {
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
 * @param list<array{title: string, group: int, body: list<string>}> $blocks
 * @return list<array{title: string, group: int, body: list<string>}>
 */
function jiraSummaryGroupBlocks(array $blocks, int $group): array
{
    return array_values(array_filter($blocks, static fn (array $block): bool => $block['group'] === $group));
}

/**
 * @param array{blocks: list<array{title: string, group: int, body: list<string>}>, groups: int, stray: list<string>, violations: list<string>} $layout
 * @return list<string>
 */
function jiraSummaryShapeViolations(array $layout, bool $criteriaUnmet): array
{
    $violations = [
        ...$layout['violations'],
        ...array_map(static fn (string $line): string => 'sections: text outside a collapsed block: ' . $line, $layout['stray']),
    ];
    $issueBlocks = jiraSummaryGroupBlocks($layout['blocks'], 0);

    if ($layout['groups'] === 0) {
        $expected = JIRA_SUMMARY_SECTIONS_WITHOUT_CRITERIA + ($criteriaUnmet ? 1 : 0);

        if (count($issueBlocks) !== $expected) {
            $violations[] = sprintf(
                'sections: %d collapsed blocks, expected %d %s a criteria section',
                count($issueBlocks),
                $expected,
                $criteriaUnmet ? 'with' : 'without',
            );
        }

        return [...$violations, ...jiraSummaryTldrViolations($issueBlocks)];
    }

    $findingsTitle = jiraSummaryGroupBlocks($layout['blocks'], 1)[0]['title'] ?? '';
    $criteriaBlocks = array_filter($issueBlocks, static fn (array $block): bool => $block['title'] !== $findingsTitle);
    $expectedIssueBlocks = (int) $criteriaUnmet;

    if (count($criteriaBlocks) !== $expectedIssueBlocks || count($issueBlocks) - count($criteriaBlocks) > 1) {
        $violations[] = sprintf('sections: %d issue-level blocks, expected %d', count($criteriaBlocks), $expectedIssueBlocks);
    }

    for ($group = 1; $group <= $layout['groups']; $group++) {
        $groupBlocks = jiraSummaryGroupBlocks($layout['blocks'], $group);

        if (count($groupBlocks) !== JIRA_SUMMARY_SECTIONS_WITHOUT_CRITERIA) {
            $violations[] = sprintf('sections: %d blocks in pull-request group %d, expected %d', count($groupBlocks), $group, JIRA_SUMMARY_SECTIONS_WITHOUT_CRITERIA);
        }

        $violations = [...$violations, ...jiraSummaryTldrViolations($groupBlocks)];
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
    $opening = $lines[0] ?? '';
    $next = $lines[1] ?? '';

    if (preg_match('/^(?:[*#-] |----$|\{|h[1-6]\. )/u', $opening) === 1
        || (preg_match(JIRA_SUMMARY_BLOCK_OPEN, $next) !== 1 && preg_match(JIRA_SUMMARY_GROUP_HEADING, $next) !== 1)
    ) {
        $violations[] = 'verdict: the comment must open with one deploy-verdict paragraph above the first section';
    }

    $rule = array_search('----', $lines, true);

    if ($rule === false || preg_match('/^_.+_$/u', $lines[$rule + 1] ?? '') !== 1) {
        $violations[] = 'footer: a ---- line followed by the italic footer line is missing';
    }

    $tail = $rule === false ? [] : array_slice($lines, $rule + 2);

    if (count($tail) !== 3
        || preg_match(JIRA_SUMMARY_BLOCK_OPEN, $tail[0]) !== 1
        || $tail[1] !== JIRA_SUMMARY_MARKER_SLOT
        || $tail[2] !== JIRA_SUMMARY_BLOCK_CLOSE
        || count(array_keys($lines, JIRA_SUMMARY_MARKER_SLOT, true)) !== 1
    ) {
        $violations[] = 'marker: the comment must end with one collapsed block that holds only the ' . JIRA_SUMMARY_MARKER_SLOT . ' line';
    }

    return $violations;
}

/**
 * @return list<string>
 */
function jiraSummaryViolations(string $source, bool $criteriaUnmet): array
{
    $lines = reportCheckLines($source);

    return [
        ...reportCheckLengthViolations($source, JIRA_SUMMARY_MAX_CHARACTERS),
        ...jiraSummaryFrameViolations($lines),
        ...jiraSummaryShapeViolations(jiraSummaryLayout($lines), $criteriaUnmet),
        ...jiraSummaryTextViolations($lines),
    ];
}

if (!isset($argv[3]) || isset($argv[4]) || $argv[1] !== '--criteria' || !in_array($argv[2], ['unmet', 'none'], true)) {
    fwrite(STDERR, JIRA_SUMMARY_USAGE);
    exit(1);
}

reportCheckFinish(jiraSummaryViolations(reportCheckReadSource('check-jira-comment.php', $argv[3]), $argv[2] === 'unmet'));
