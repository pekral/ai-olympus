<?php

declare(strict_types = 1);

require __DIR__ . '/../../_shared/report-check-common.php';

const JIRA_SUMMARY_MAX_CHARACTERS = 3_000;

const JIRA_SUMMARY_MAX_CHANGES_CHARACTERS = 600;

const JIRA_SUMMARY_SECTIONS_WITHOUT_CRITERIA = 2;

const JIRA_SUMMARY_GROUP_HEADING = '/^h2\. \[PR #\d+\|/u';

const JIRA_SUMMARY_BLOCK_OPEN = '/^\{expand:(?<title>[^{}]+)\}$/u';

const JIRA_SUMMARY_BLOCK_CLOSE = '{expand}';

const JIRA_SUMMARY_MARKER_SLOT = '{agent-marker}';

const JIRA_SUMMARY_LINKS_LINE = '/^\[[^|\]]+\|[^\]]+\](?: · \[[^|\]]+\|[^\]]+\])*$/u';

const JIRA_SUMMARY_FINDING_TYPE = '/^\* \*\((?<type>security|bug|tests|refactor|performance|documentation)\):\* \S/u';

const JIRA_SUMMARY_TYPE_ORDER = ['security', 'bug', 'tests', 'refactor', 'performance', 'documentation'];

const JIRA_SUMMARY_LINK = '/\[(?<label>[^|\]]+)\|(?<url>[^\]]+)\]/u';

const JIRA_SUMMARY_GITHUB_LINK = '#^https://github\.com/(?<repository>[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+)/'
    . '(?:pull/[1-9][0-9]*(?:/files(?:\#diff-[0-9a-f]{64}R[1-9][0-9]*)?)?|issues/[1-9][0-9]*)$#';

const JIRA_SUMMARY_WHOLE_CHANGE_LINK = '#\|https://github\.com/[^\]]+/pull/[1-9][0-9]*/files\]#u';

const JIRA_SUMMARY_MENTION = '[~accountid:';

const JIRA_SUMMARY_REFACTOR_PREFIX = '* *(refactor):* ';

const JIRA_SUMMARY_MARKDOWN = '/^(?:#{1,6}\s|>|-\s)|\*\*[^\s*]|`|\]\(/u';

const JIRA_SUMMARY_DEVELOPER_TOKEN = '/' . REPORT_CHECK_DEVELOPER_TOKEN . '/u';

const JIRA_SUMMARY_USAGE = "Usage: check-jira-comment.php --criteria <unmet|none> [--author <account-id|none>] <source-file>\n"
    . "  Checks the Wiki Markup source of a pr-summary JIRA comment before it is published.\n"
    . "  --criteria unmet   an explicit requirement still needs a change or an answer\n"
    . "  --criteria none    no requirement needs action, so the comment has no criteria section\n"
    . "  --author <id>      the JIRA account ID of the author of the changes; every (refactor) finding mentions it\n"
    . "  --author none      no JIRA account matches the author, so no line mentions anyone (the default)\n"
    . "  Exit codes: 0 the source passes, 1 usage or read error, 2 at least one violation.\n"
    . "  stdout: one `violation: <check>: <detail>` line per violation.\n";

/**
 * @param list<string> $lines
 * @return array{blocks: list<array{title: string, group: int, body: list<string>}>, groups: int, stray: list<string>, violations: list<string>}
 */
function jiraSummaryLayout(array $lines): array
{
    $state = ['blocks' => [], 'groups' => 0, 'open' => null, 'stray' => [], 'violations' => []];

    foreach (array_slice($lines, 1) as $line) {
        if ($line === '----') {
            break;
        }

        $state = $state['open'] === null ? jiraSummaryLineOutsideBlock($state, $line) : jiraSummaryLineInsideBlock($state, $line);
    }

    if ($state['open'] !== null) {
        $state['violations'][] = 'blocks: a collapsed block without its closing line: ' . $state['open']['title'];
    }

    return ['blocks' => $state['blocks'], 'groups' => $state['groups'], 'stray' => $state['stray'], 'violations' => $state['violations']];
}

/**
 * @param array{blocks: list<array{title: string, group: int, body: list<string>}>, groups: int, open: array{title: string, group: int, body: list<string>}|null, stray: list<string>, violations: list<string>} $state
 * @return array{blocks: list<array{title: string, group: int, body: list<string>}>, groups: int, open: array{title: string, group: int, body: list<string>}|null, stray: list<string>, violations: list<string>}
 */
function jiraSummaryLineOutsideBlock(array $state, string $line): array
{
    if (preg_match(JIRA_SUMMARY_BLOCK_OPEN, $line, $match) === 1) {
        $state['open'] = ['body' => [], 'group' => $state['groups'], 'title' => trim($match['title'])];
    } elseif (preg_match(JIRA_SUMMARY_GROUP_HEADING, $line) === 1) {
        $state['groups']++;
    } elseif (preg_match(JIRA_SUMMARY_LINKS_LINE, $line) !== 1) {
        $state['stray'][] = $line;
    }

    return $state;
}

/**
 * @param array{blocks: list<array{title: string, group: int, body: list<string>}>, groups: int, open: array{title: string, group: int, body: list<string>}, stray: list<string>, violations: list<string>} $state
 * @return array{blocks: list<array{title: string, group: int, body: list<string>}>, groups: int, open: array{title: string, group: int, body: list<string>}|null, stray: list<string>, violations: list<string>}
 */
function jiraSummaryLineInsideBlock(array $state, string $line): array
{
    if ($line === JIRA_SUMMARY_BLOCK_CLOSE) {
        $state['blocks'][] = $state['open'];
        $state['open'] = null;
    } elseif (preg_match(JIRA_SUMMARY_BLOCK_OPEN, $line) === 1) {
        $state['violations'][] = 'blocks: a collapsed block inside another one: ' . $line;
    } else {
        $state['open']['body'][] = $line;
    }

    return $state;
}

/**
 * @param list<array{title: string, group: int, body: list<string>}> $blocks
 * @return list<string>
 */
function jiraSummaryChangesViolations(array $blocks): array
{
    $block = array_slice($blocks, -1)[0] ?? null;

    if ($block === null) {
        return [];
    }

    $violations = [];
    $prose = array_filter($block['body'], static fn (string $line): bool => preg_match('/^\* \S/u', $line) !== 1);
    $length = mb_strlen(implode(' ', $block['body']));

    if (preg_match('/\bTL\W?DR\b/iu', $block['title']) === 1) {
        $violations[] = 'changes: the final section carries no TL;DR label: ' . $block['title'];
    }

    if ($block['body'] === [] || $prose !== []) {
        $violations[] = 'changes: write what changed and what it affects as a few bullets, one fact per bullet';
    }

    if ($length > JIRA_SUMMARY_MAX_CHANGES_CHARACTERS) {
        $violations[] = sprintf('changes: %d characters, the limit is %d', $length, JIRA_SUMMARY_MAX_CHANGES_CHARACTERS);
    }

    return $violations;
}

/**
 * @param list<array{title: string, group: int, body: list<string>}> $blocks
 * @return list<string>
 */
function jiraSummaryBodyLines(array $blocks): array
{
    return array_merge([], ...array_map(static fn (array $block): array => $block['body'], $blocks));
}

/**
 * @param list<array{title: string, group: int, body: list<string>}> $findings
 * @param list<string> $lines
 * @return list<string>
 */
function jiraSummaryMentionViolations(array $findings, array $lines, string $author): array
{
    $refactor = array_values(array_filter(
        jiraSummaryBodyLines($findings),
        static fn (string $line): bool => str_starts_with($line, JIRA_SUMMARY_REFACTOR_PREFIX),
    ));
    $prefix = JIRA_SUMMARY_REFACTOR_PREFIX . JIRA_SUMMARY_MENTION . $author . '] ';
    $mentioned = $author === 'none' ? [] : array_filter(
        $refactor,
        static fn (string $line): bool => str_starts_with($line, $prefix) && substr_count($line, JIRA_SUMMARY_MENTION) === 1,
    );
    $unmentioned = $author === 'none' ? [] : array_filter($refactor, static fn (string $line): bool => !str_starts_with($line, $prefix));
    $violations = array_map(
        static fn (string $line): string => 'mention: a (refactor) finding mentions the author right after its type: ' . $line,
        array_values($unmentioned),
    );
    $mentions = array_sum(array_map(static fn (string $line): int => substr_count($line, JIRA_SUMMARY_MENTION), $lines));

    if ($mentions > count($mentioned)) {
        $violations[] = 'mention: only a (refactor) finding mentions anyone, the author right after its type';
    }

    return $violations;
}

/**
 * @param array{title: string, group: int, body: list<string>} $block
 */
function jiraSummaryOpensWithWholeChange(array $block): bool
{
    $opening = $block['body'][0] ?? '';

    return !str_starts_with($opening, '* ') && preg_match(JIRA_SUMMARY_WHOLE_CHANGE_LINK, $opening) === 1;
}

function jiraSummaryMisplacesCodeLink(string $line): bool
{
    preg_match_all(JIRA_SUMMARY_LINK, $line, $links, PREG_SET_ORDER);

    foreach ($links as $index => $link) {
        $last = $index === count($links) - 1 && str_ends_with($line, $link[0]);

        if (str_contains($link['url'], '#diff-') && (!str_starts_with($line, '* ') || !$last)) {
            return true;
        }
    }

    return false;
}

/**
 * @param list<array{title: string, group: int, body: list<string>}> $blocks
 * @return list<string>
 */
function jiraSummaryFindingLinkViolations(array $blocks, bool $grouped): array
{
    $unopened = array_filter(
        $blocks,
        static fn (array $block): bool => !($grouped && $block['group'] === 0) && !jiraSummaryOpensWithWholeChange($block),
    );
    $misplaced = array_filter(jiraSummaryBodyLines($blocks), jiraSummaryMisplacesCodeLink(...));

    return [
        ...array_map(
            static fn (): string => 'links: the findings block opens with one line that links the whole change of the pull request',
            array_values($unopened),
        ),
        ...array_map(static fn (string $line): string => 'links: a code link ends a finding: ' . $line, array_values($misplaced)),
    ];
}

/**
 * @param list<string> $lines
 * @return list<string>
 */
function jiraSummaryLinkUrls(array $lines): array
{
    $urls = [];

    foreach ($lines as $line) {
        preg_match_all(JIRA_SUMMARY_LINK, $line, $links);
        $urls = [...$urls, ...$links['url']];
    }

    return $urls;
}

function jiraSummaryUrlViolation(string $url): ?string
{
    if (!str_starts_with($url, 'https://')) {
        return 'links: a link uses https: ' . $url;
    }

    if (str_starts_with($url, 'https://github.com/') && preg_match(JIRA_SUMMARY_GITHUB_LINK, $url) !== 1) {
        return 'links: a GitHub link points into a pull request or an issue of the task repository: ' . $url;
    }

    return null;
}

/**
 * @param list<string> $lines
 * @return list<string>
 */
function jiraSummaryLinkViolations(array $lines): array
{
    $urls = jiraSummaryLinkUrls($lines);
    $violations = array_values(array_filter(
        array_map(jiraSummaryUrlViolation(...), $urls),
        static fn (?string $violation): bool => $violation !== null,
    ));
    $repositories = [];

    foreach ($urls as $url) {
        if (preg_match(JIRA_SUMMARY_GITHUB_LINK, $url, $match) === 1) {
            $repositories[strtolower($match['repository'])] = true;
        }
    }

    if (count($repositories) > 1) {
        $violations[] = 'links: every GitHub link points into one repository: ' . implode(', ', array_keys($repositories));
    }

    return $violations;
}

/**
 * @param array{title: string, group: int, body: list<string>}|null $block
 * @return list<string>
 */
function jiraSummaryCriteriaViolations(?array $block): array
{
    $calls = array_filter($block['body'] ?? [], static fn (string $line): bool => str_contains($line, JIRA_SUMMARY_MENTION));

    return array_map(static fn (string $line): string => 'criteria: the block calls on nobody: ' . $line, array_values($calls));
}

/**
 * @param array{blocks: list<array{title: string, group: int, body: list<string>}>, groups: int, stray: list<string>, violations: list<string>} $layout
 * @return list<array{title: string, group: int, body: list<string>}>
 */
function jiraSummaryFindingsBlocks(array $layout, bool $criteriaUnmet): array
{
    if ($layout['groups'] === 0) {
        return array_slice(jiraSummaryGroupBlocks($layout['blocks'], 0), (int) $criteriaUnmet, 1);
    }

    $findings = [];

    for ($group = 1; $group <= $layout['groups']; $group++) {
        $findings = [...$findings, ...array_slice(jiraSummaryGroupBlocks($layout['blocks'], $group), 0, 1)];
    }

    $title = $findings[0]['title'] ?? null;
    $shared = array_filter(jiraSummaryGroupBlocks($layout['blocks'], 0), static fn (array $block): bool => $block['title'] === $title);

    return [...$shared, ...$findings];
}

/**
 * @return list<string>
 */
function jiraSummaryOrderViolations(?string $previous, string $type): array
{
    $position = array_search($type, JIRA_SUMMARY_TYPE_ORDER, strict: true);

    if ($previous === null || $position >= array_search($previous, JIRA_SUMMARY_TYPE_ORDER, strict: true)) {
        return [];
    }

    return [sprintf('findings: order the findings %s; (%s) stands after (%s)', implode(', ', JIRA_SUMMARY_TYPE_ORDER), $type, $previous)];
}

/**
 * @param list<string> $body
 * @return list<string>
 */
function jiraSummaryBlockTypeViolations(array $body): array
{
    $violations = [];
    $previous = null;
    $lines = array_filter($body, static fn (string $line): bool => str_starts_with($line, 'h3. ') || str_starts_with($line, '* '));

    foreach ($lines as $line) {
        if (str_starts_with($line, 'h3. ')) {
            $previous = null;
        } elseif (preg_match(JIRA_SUMMARY_FINDING_TYPE, $line, $match) !== 1) {
            $violations[] = 'findings: a finding must open with its change type, for example *(bug):*: ' . $line;
        } else {
            $violations = [...$violations, ...jiraSummaryOrderViolations($previous, $match['type'])];
            $previous = $match['type'];
        }
    }

    return $violations;
}

/**
 * @param list<array{title: string, group: int, body: list<string>}> $blocks
 * @return list<string>
 */
function jiraSummaryFindingsViolations(array $blocks): array
{
    return array_merge([], ...array_map(static fn (array $block): array => jiraSummaryBlockTypeViolations($block['body']), $blocks));
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
 * @param list<array{title: string, group: int, body: list<string>}> $issueBlocks
 * @return list<string>
 */
function jiraSummarySingleShapeViolations(array $issueBlocks, bool $criteriaUnmet): array
{
    $expected = JIRA_SUMMARY_SECTIONS_WITHOUT_CRITERIA + ($criteriaUnmet ? 1 : 0);
    $violations = [];

    if (count($issueBlocks) !== $expected) {
        $violations[] = sprintf(
            'sections: %d collapsed blocks, expected %d %s a criteria section',
            count($issueBlocks),
            $expected,
            $criteriaUnmet ? 'with' : 'without',
        );
    }

    return [
        ...$violations,
        ...jiraSummaryCriteriaViolations($criteriaUnmet ? $issueBlocks[0] ?? null : null),
        ...jiraSummaryChangesViolations($issueBlocks),
    ];
}

/**
 * @param array{blocks: list<array{title: string, group: int, body: list<string>}>, groups: int, stray: list<string>, violations: list<string>} $layout
 * @return list<string>
 */
function jiraSummaryGroupedShapeViolations(array $layout, bool $criteriaUnmet): array
{
    $issueBlocks = jiraSummaryGroupBlocks($layout['blocks'], 0);
    $findingsTitle = jiraSummaryGroupBlocks($layout['blocks'], 1)[0]['title'] ?? '';
    $criteriaBlocks = array_values(array_filter($issueBlocks, static fn (array $block): bool => $block['title'] !== $findingsTitle));
    $expected = (int) $criteriaUnmet;
    $violations = jiraSummaryCriteriaViolations($criteriaUnmet ? $criteriaBlocks[0] ?? null : null);

    if (count($criteriaBlocks) !== $expected || count($issueBlocks) - count($criteriaBlocks) > 1) {
        $violations[] = sprintf('sections: %d issue-level blocks, expected %d', count($criteriaBlocks), $expected);
    }

    for ($group = 1; $group <= $layout['groups']; $group++) {
        $groupBlocks = jiraSummaryGroupBlocks($layout['blocks'], $group);

        if (count($groupBlocks) !== JIRA_SUMMARY_SECTIONS_WITHOUT_CRITERIA) {
            $violations[] = sprintf(
                'sections: %d blocks in pull-request group %d, expected %d',
                count($groupBlocks),
                $group,
                JIRA_SUMMARY_SECTIONS_WITHOUT_CRITERIA,
            );
        }

        $violations = [...$violations, ...jiraSummaryChangesViolations($groupBlocks)];
    }

    return $violations;
}

/**
 * @param array{blocks: list<array{title: string, group: int, body: list<string>}>, groups: int, stray: list<string>, violations: list<string>} $layout
 * @return list<string>
 */
function jiraSummaryShapeViolations(array $layout, bool $criteriaUnmet): array
{
    return [
        ...$layout['violations'],
        ...array_map(static fn (string $line): string => 'sections: text outside a collapsed block: ' . $line, $layout['stray']),
        ...($layout['groups'] === 0
            ? jiraSummarySingleShapeViolations(jiraSummaryGroupBlocks($layout['blocks'], 0), $criteriaUnmet)
            : jiraSummaryGroupedShapeViolations($layout, $criteriaUnmet)),
    ];
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
function jiraSummaryVerdictViolations(array $lines): array
{
    $opening = $lines[0] ?? '';
    $next = $lines[1] ?? '';
    $sectionFollows = preg_match(JIRA_SUMMARY_BLOCK_OPEN, $next) === 1 || preg_match(JIRA_SUMMARY_GROUP_HEADING, $next) === 1;

    if (preg_match('/^(?:[*#-] |----$|\{|h[1-6]\. )/u', $opening) !== 1 && $sectionFollows) {
        return [];
    }

    return ['verdict: the comment must open with one deploy-verdict paragraph above the first section'];
}

/**
 * @param list<string> $lines
 * @return list<string>
 */
function jiraSummaryFooterViolations(array $lines): array
{
    $rule = array_search('----', $lines, true);

    if ($rule !== false && preg_match('/^_.+_$/u', $lines[$rule + 1] ?? '') === 1) {
        return [];
    }

    return ['footer: a ---- line followed by the italic footer line is missing'];
}

/**
 * @param list<string> $lines
 * @return list<string>
 */
function jiraSummaryMarkerViolations(array $lines): array
{
    $rule = array_search('----', $lines, true);
    $tail = $rule === false ? [] : array_slice($lines, $rule + 2);
    $alone = count(array_keys($lines, JIRA_SUMMARY_MARKER_SLOT, true)) === 1;

    if ($alone && $tail === [$tail[0] ?? '', JIRA_SUMMARY_MARKER_SLOT, JIRA_SUMMARY_BLOCK_CLOSE] && preg_match(JIRA_SUMMARY_BLOCK_OPEN, $tail[0]) === 1) {
        return [];
    }

    return ['marker: the comment must end with one collapsed block that holds only the ' . JIRA_SUMMARY_MARKER_SLOT . ' line'];
}

/**
 * @return list<string>
 */
function jiraSummaryViolations(string $source, bool $criteriaUnmet, string $author): array
{
    $lines = reportCheckLines($source);
    $layout = jiraSummaryLayout($lines);
    $findings = jiraSummaryFindingsBlocks($layout, $criteriaUnmet);

    return [
        ...reportCheckLengthViolations($source, JIRA_SUMMARY_MAX_CHARACTERS),
        ...jiraSummaryVerdictViolations($lines),
        ...jiraSummaryFooterViolations($lines),
        ...jiraSummaryMarkerViolations($lines),
        ...jiraSummaryShapeViolations($layout, $criteriaUnmet),
        ...jiraSummaryFindingsViolations($findings),
        ...jiraSummaryFindingLinkViolations($findings, $layout['groups'] > 0),
        ...jiraSummaryLinkViolations($lines),
        ...jiraSummaryMentionViolations($findings, $lines, $author),
        ...jiraSummaryTextViolations($lines),
    ];
}

$arguments = array_slice($argv ?? [], 1);
$options = ['--author' => 'none', '--criteria' => null];

while (count($arguments) > 1 && array_key_exists($arguments[0], $options)) {
    $options[array_shift($arguments)] = array_shift($arguments);
}

if (count($arguments) !== 1
    || !in_array($options['--criteria'], ['unmet', 'none'], true)
    || preg_match('/^(?:none|[A-Za-z0-9:_-]+)$/', (string) $options['--author']) !== 1
) {
    fwrite(STDERR, JIRA_SUMMARY_USAGE);
    exit(1);
}

reportCheckFinish(jiraSummaryViolations(
    reportCheckReadSource('check-jira-comment.php', $arguments[0]),
    $options['--criteria'] === 'unmet',
    (string) $options['--author'],
));
