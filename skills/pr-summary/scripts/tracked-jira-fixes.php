<?php

declare(strict_types = 1);

const JIRA_SUMMARY_TRACKED_FIX_LINK = '#^https://github\.com/(?<repository>[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+)/(?:issues|pull)/[1-9][0-9]*$#D';

const JIRA_SUMMARY_TRACKED_HEADING = '/^h3\. (?:Tracked security gaps|Sledované bezpečnostní mezery)$/uD';

const JIRA_SUMMARY_TRACKED_ROW = '/^\* \*\(security\):\* \*(?<severity>High|Moderate|Low)\* — '
    . '(?:Already on the default branch\.|Už je na výchozí větvi\.) \[[^|\]]+\|(?<url>https:\/\/[^\s\]]+)\]$/uD';

/**
 * @param list<string> $expected
 */
function jiraSummaryAllowsTrackedFix(string $href, string $repository, array $expected): bool
{
    return preg_match(JIRA_SUMMARY_TRACKED_FIX_LINK, $href, $match) === 1
        && strcasecmp($match['repository'], $repository) === 0
        && in_array($href, jiraSummaryTrackedFixUrls($expected), true);
}

/**
 * @param list<string> $expected
 * @return list<string>
 */
function jiraSummaryTrackedFixUrls(array $expected): array
{
    return array_map(static fn (string $fix): string => explode('=', $fix, 2)[1], $expected);
}

/**
 * @param list<string> $body
 * @return list<string>|null
 */
function jiraSummaryTrackedRows(array $body): ?array
{
    $rows = [];
    $tracked = false;

    foreach ($body as $line) {
        if (str_starts_with($line, 'h3. ')) {
            $tracked = preg_match(JIRA_SUMMARY_TRACKED_HEADING, $line) === 1;
        } elseif ($tracked) {
            if (preg_match(JIRA_SUMMARY_TRACKED_ROW, $line, $match) !== 1) {
                return null;
            }

            $rows[] = $match['severity'] . '=' . $match['url'];
        }
    }

    return $rows;
}

/**
 * @param list<array{title: string, group: int, body: list<string>}> $findings
 * @param list<string> $lines
 * @param list<string> $expected
 * @return list<string>
 */
function jiraSummaryTrackedFixViolations(array $findings, array $lines, array $expected): array
{
    $groups = array_map(static fn (array $block): ?array => jiraSummaryTrackedRows($block['body']), $findings);

    if (in_array(null, $groups, true)) {
        return ['tracked security gaps: each row needs its original severity and one fix link'];
    }

    $observed = array_merge([], ...array_filter($groups, is_array(...)));
    sort($observed);
    sort($expected);

    if ($observed !== $expected) {
        return ['tracked security gaps: the rows differ from the verified review'];
    }

    foreach (array_count_values(jiraSummaryTrackedFixUrls($expected)) as $url => $expectedCount) {
        $observedCount = array_sum(array_map(static fn (string $line): int => substr_count($line, '|' . $url . ']'), $lines));

        if ($observedCount !== $expectedCount) {
            return ['tracked security gaps: a fix link belongs only in its verified rows'];
        }
    }

    return [];
}
