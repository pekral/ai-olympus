<?php

declare(strict_types = 1);

/**
 * @param list<string> $lines
 * @return list<string>
 */
function jiraSummaryTitleViolations(array $lines): array
{
    $layout = jiraSummaryLayout($lines);
    $shared = $layout['groups'] > 0 ? jiraSummaryGroupBlocks($layout['blocks'], 0)[1] ?? null : null;
    $headings = array_filter($lines, static fn (string $line): bool => preg_match(JIRA_SUMMARY_GROUP_HEADING, $line) === 1);
    $numbers = array_values(array_map(static fn (string $heading): string => explode(' ', $heading)[2], $headings));
    $violations = [];

    for ($group = 0; $group <= $layout['groups']; $group++) {
        $blocks = jiraSummaryGroupBlocks($layout['blocks'], $group);
        $number = $numbers[$group - 1] ?? '';
        $sharedBlocker = $shared !== null && str_starts_with($shared['title'], '⚠️ ') && $number !== ''
            && preg_match('/\bPR ' . preg_quote($number, '/') . '(?![0-9])/u', implode(' ', $shared['body'])) === 1;
        $sections = jiraSummarySectionTitles($group, $layout['groups'], count($blocks));

        foreach ($sections as $index => $section) {
            $violations = [...$violations, ...jiraSummarySectionViolations($section, $blocks[$index] ?? null, $sharedBlocker)];
        }
    }

    return $violations;
}

/**
 * @return list<string>
 */
function jiraSummarySectionTitles(int $group, int $groups, int $count): array
{
    $sections = $group === 0 ? ['Acceptance criteria|Akceptační kritéria'] : [];

    if ($group !== 0 || $groups === 0 || $count > 1) {
        $sections[] = 'Findings from the code review|Nálezy z kontroly kódu';
    }

    if ($group !== 0 || $groups === 0) {
        $sections[] = 'Test scenarios|Testovací scénáře';
        $sections[] = 'What changed and what it affects|Co se změnilo a co to ovlivní';
    }

    return $sections;
}

/**
 * @param array{title:string, body:list<string>, group:int}|null $block
 * @return list<string>
 */
function jiraSummarySectionViolations(string $section, ?array $block, bool $sharedBlocker): array
{
    $changes = str_starts_with($section, 'What changed');
    $prefix = $changes ? '' : JIRA_SUMMARY_STATUS . ' ';

    if ($block === null || preg_match("/^{$prefix}(?:{$section})$/uD", $block['title']) !== 1 || $block['body'] === []) {
        return ['sections: expected a populated section with the prescribed title: ' . $section];
    }

    $icon = explode(' ', $block['title'], 2)[0];

    if (str_starts_with($section, 'Findings')) {
        return jiraSummaryFindingsStatus($block['body'], $icon, $sharedBlocker);
    }

    return $changes ? [] : jiraSummaryNumberedStatus($block['body'], $icon, str_starts_with($section, 'Acceptance'));
}

/**
 * @param list<string> $body
 * @return list<string>
 */
function jiraSummaryFindingsStatus(array $body, string $icon, bool $sharedBlocker): array
{
    $actionable = array_filter($body, static fn (string $line): bool => preg_match(JIRA_SUMMARY_FINDING_TYPE, $line) === 1);
    if (($actionable !== [] || $sharedBlocker) && $icon !== '⚠️') {
        return ['findings: show a warning title for an open finding or shared blocker'];
    }

    return [];
}

/**
 * @param list<string> $body
 * @return list<string>
 */
function jiraSummaryNumberedStatus(array $body, string $icon, bool $criteria): array
{
    $icons = [];
    $violations = [];

    foreach ($body as $line) {
        if (preg_match('/^# (✅|⚠️) \S/u', $line, $match) === 1) {
            $icons[] = $match[1];
        } elseif (!$criteria || preg_match('/^# \S/u', $line) !== 1) {
            $violations[] = 'sections: number each criterion or scenario and give it a status';
        }
    }

    $expected = match (true) {
        in_array('⚠️', $icons, true), !in_array('✅', $icons, true) => '⚠️',
        default => '✅',
    };

    if ($icon !== $expected) {
        $violations[] = 'sections: the title must show the most urgent item status';
    }

    return $violations;
}
