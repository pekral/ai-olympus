<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

/**
 * Renders the `ai-olympus stats` summary from the local metrics store.
 *
 * The report exists to answer the three tuning questions a single run cannot:
 * whether STANDARD is too eager (its reviews find nothing), whether FAST is too
 * eager (its runs keep escalating), and whether model escalation is rising
 * without failures falling. Every derived signal below maps to one of those.
 */
final class MetricsReport
{

    private const array TIERS = ['FAST', 'STANDARD', 'CRITICAL'];

    /**
     * @param list<array<string, mixed>> $entries
     */
    public static function render(array $entries, string $window): string
    {
        if ($entries === []) {
            return sprintf('No runs recorded for %s.%s', $window, PHP_EOL);
        }

        $lines = [sprintf('Runs                  %d', count($entries)), ''];

        foreach (self::TIERS as $tier) {
            $lines[] = sprintf('%-21s %d', $tier, self::countByTier($entries, $tier));
        }

        $lines[] = '';
        $lines[] = sprintf('Agent dispatches      %d', self::sum($entries, 'agent_dispatches'));
        $lines[] = sprintf('Escalated dispatches  %d', self::sum($entries, 'escalated_dispatches'));
        $lines[] = sprintf('Review rounds         %d', self::sum($entries, 'review_rounds'));
        $lines[] = '';
        $lines[] = self::renderFastEscalation($entries);
        $lines[] = self::renderZeroFindings($entries);
        $lines[] = self::renderTokens($entries);

        return implode(PHP_EOL, array_filter($lines, static fn (string $line): bool => $line !== ' ')) . PHP_EOL;
    }

    /**
     * @param list<array<string, mixed>> $entries
     */
    private static function countByTier(array $entries, string $tier): int
    {
        return count(array_filter($entries, static fn (array $entry): bool => ($entry['final_tier'] ?? null) === $tier));
    }

    /**
     * @param list<array<string, mixed>> $entries
     */
    private static function sum(array $entries, string $key): int
    {
        $total = 0;

        foreach ($entries as $entry) {
            $value = $entry[$key] ?? 0;
            $total += is_int($value) ? $value : 0;
        }

        return $total;
    }

    /**
     * A FAST run that keeps being re-classified upward means the classifier is
     * routing real work to the tier that skips the review.
     *
     * @param list<array<string, mixed>> $entries
     */
    private static function renderFastEscalation(array $entries): string
    {
        $startedFast = array_values(array_filter($entries, static fn (array $entry): bool => ($entry['initial_tier'] ?? null) === 'FAST'));
        $escalated = array_values(array_filter(
            $startedFast,
            static fn (array $entry): bool => ($entry['final_tier'] ?? null) !== 'FAST',
        ));

        if ($startedFast === []) {
            return 'FAST escalations      no FAST runs recorded';
        }

        return sprintf('FAST escalations      %d / %d', count($escalated), count($startedFast));
    }

    /**
     * A STANDARD tier whose reviews never find anything is paying for a review
     * pass that changes no outcome.
     *
     * @param list<array<string, mixed>> $entries
     */
    private static function renderZeroFindings(array $entries): string
    {
        $reviewed = array_values(array_filter($entries, static fn (array $entry): bool => ($entry['review_rounds'] ?? 0) > 0));

        if ($reviewed === []) {
            return 'Review zero-findings  no reviewed runs recorded';
        }

        $zero = array_values(array_filter($reviewed, static fn (array $entry): bool => self::sumFindings($entry) === 0));

        return sprintf('Review zero-findings  %d / %d', count($zero), count($reviewed));
    }

    /**
     * @param array<string, mixed> $entry
     */
    private static function sumFindings(array $entry): int
    {
        $findings = $entry['findings'] ?? [];

        if (!is_array($findings)) {
            return 0;
        }

        $critical = $findings['critical'] ?? 0;
        $moderate = $findings['moderate'] ?? 0;

        return (is_int($critical) ? $critical : 0) + (is_int($moderate) ? $moderate : 0);
    }

    /**
     * Token counts are optional — no runtime reports them reliably — so the line
     * says they are unavailable rather than printing a fabricated average.
     *
     * @param list<array<string, mixed>> $entries
     */
    private static function renderTokens(array $entries): string
    {
        $withTokens = array_values(array_filter($entries, static fn (array $entry): bool => isset($entry['input_tokens'])));

        if ($withTokens === []) {
            return 'Tokens                not reported by this runtime';
        }

        $total = self::sum($withTokens, 'input_tokens') + self::sum($withTokens, 'output_tokens');

        return sprintf('Avg tokens per run    %d (over %d run(s) that reported)', intdiv($total, count($withTokens)), count($withTokens));
    }

}
