<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

use Closure;
use JsonException;

/**
 * Picks the oldest open issue carrying the configured labels and hands it to Claude Code
 * as one agent run. Built for `cron` / Task Scheduler: one invocation resolves one issue.
 *
 * The process layer is injected as a closure so the whole flow is exercised in tests
 * without ever spawning `gh` or `claude` — `bin/ai-olympus` supplies the real one.
 */
final readonly class AgenticIssueResolver
{

    /**
     * An issue already claimed by another run is skipped, so two overlapping cron ticks
     * never pick the same issue.
     */
    public const string CLAIM_LABEL = 'Resolve_by_AI:in-progress';

    private const int LIST_LIMIT = 100;

    /**
     * @param \Closure(list<string>, bool): \Pekral\AiOlympus\CommandResult $executor
     */
    public function __construct(private Closure $executor)
    {
    }

    public function run(AgenticOptions $options): int
    {
        $listing = ($this->executor)(self::listCommand($options), true);

        if ($listing->exitCode !== 0) {
            fwrite(STDERR, 'Could not list issues. Check that the GitHub CLI is installed and authenticated.' . PHP_EOL);

            return 1;
        }

        $issue = self::selectOldest($listing->output);

        if ($issue === null) {
            echo sprintf('No unclaimed open issue matches %s.%s', implode(', ', $options->labels), PHP_EOL);

            return 0;
        }

        $prompt = self::buildPrompt($issue->url, $options->merge);

        if ($options->dryRun) {
            echo sprintf('Would resolve #%d (%s) with:%s%s%s', $issue->number, $issue->url, PHP_EOL, $prompt, PHP_EOL);

            return 0;
        }

        echo sprintf('Resolving #%d (%s).%s', $issue->number, $issue->url, PHP_EOL);

        return ($this->executor)(self::agentCommand($prompt), false)->exitCode;
    }

    /**
     * @return list<string>
     */
    public static function listCommand(AgenticOptions $options): array
    {
        $command = ['gh', 'issue', 'list', '--state', 'open', '--json', 'number,url,createdAt,labels', '--limit', (string) self::LIST_LIMIT];

        foreach ($options->labels as $label) {
            $command[] = '--label';
            $command[] = $label;
        }

        if ($options->repository !== null) {
            $command[] = '--repo';
            $command[] = $options->repository;
        }

        return $command;
    }

    /**
     * @return list<string>
     */
    public static function agentCommand(string $prompt): array
    {
        return ['claude', '-p', $prompt];
    }

    /**
     * The chain the run performs, and the one boundary it must not cross on its own:
     * merging stays opt-in, so an unattended run leaves the pull request for a human.
     */
    public static function buildPrompt(string $issueUrl, bool $merge): string
    {
        $prompt = sprintf(
            'Run /resolve-issue %s. When it finishes, run /code-review-github %s, and if the review'
                . ' reports findings, run /process-code-review %s until it converges.',
            $issueUrl,
            $issueUrl,
            $issueUrl,
        );

        if (!$merge) {
            return $prompt . ' Leave the pull request open for human review — do not merge it.';
        }

        return $prompt . sprintf(' Once the review has converged, run /merge-github-pr %s.', $issueUrl);
    }

    public static function selectOldest(string $issueListJson): ?AgenticIssue
    {
        $issues = self::eligibleIssues($issueListJson);

        if ($issues === []) {
            return null;
        }

        // ISO-8601 timestamps sort correctly as strings, so the oldest is the smallest.
        usort($issues, static fn (AgenticIssue $a, AgenticIssue $b): int => strcmp($a->createdAt, $b->createdAt));

        return $issues[0];
    }

    /**
     * @return list<\Pekral\AiOlympus\AgenticIssue>
     */
    private static function eligibleIssues(string $issueListJson): array
    {
        try {
            $decoded = json_decode($issueListJson, associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw InstallerFailure::issueListUnparsable($exception->getMessage());
        }

        if (!is_array($decoded)) {
            throw InstallerFailure::issueListUnparsable('the payload is not a JSON array');
        }

        $issues = [];

        foreach ($decoded as $entry) {
            if (!is_array($entry) || self::isClaimed($entry)) {
                continue;
            }

            $issues[] = self::toIssue($entry);
        }

        return $issues;
    }

    /**
     * Every field is validated rather than cast: the payload comes from an external CLI,
     * so a missing or wrongly-typed field must degrade to a harmless placeholder instead
     * of a silent coercion.
     *
     * @param array<array-key, mixed> $entry
     */
    private static function toIssue(array $entry): AgenticIssue
    {
        $number = $entry['number'] ?? null;
        $url = $entry['url'] ?? null;
        $createdAt = $entry['createdAt'] ?? null;

        return new AgenticIssue(
            number: is_int($number) ? $number : 0,
            url: is_string($url) ? $url : '',
            createdAt: is_string($createdAt) ? $createdAt : '',
        );
    }

    /**
     * @param array<array-key, mixed> $entry
     */
    private static function isClaimed(array $entry): bool
    {
        $labels = $entry['labels'] ?? [];

        if (!is_array($labels)) {
            return false;
        }

        foreach ($labels as $label) {
            if (is_array($label) && ($label['name'] ?? null) === self::CLAIM_LABEL) {
                return true;
            }
        }

        return false;
    }

}
