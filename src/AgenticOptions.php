<?php

declare(strict_types = 1);

namespace AgenticVibes\AgentSkills;

final readonly class AgenticOptions
{

    /**
     * The label this package's own workflow puts on issues it wants an agent to pick up.
     */
    public const string DEFAULT_LABEL = 'Resolve_by_AI';

    /**
     * @param list<string> $labels
     */
    public function __construct(public array $labels, public ?string $repository, public bool $merge, public bool $dryRun) {
    }

    /**
     * Parsed from the raw argv rather than the normalized one, because
     * InstallerPath::normalizeCliArguments() joins argv on spaces and re-splits on
     * whitespace — which would tear a label that legitimately contains one
     * ("good first issue", "help wanted") into three arguments.
     *
     * @param array<int, string> $argv
     */
    public static function fromArgv(array $argv): self
    {
        $labels = self::optionValues($argv, '--label=');

        return new self(
            labels: $labels === [] ? [self::DEFAULT_LABEL] : $labels,
            repository: self::optionValues($argv, '--repo=')[0] ?? null,
            merge: in_array('--merge', $argv, strict: true),
            dryRun: in_array('--dry-run', $argv, strict: true),
        );
    }

    /**
     * @param array<int, string> $argv
     * @return list<string>
     */
    private static function optionValues(array $argv, string $prefix): array
    {
        $values = [];

        foreach ($argv as $argument) {
            $value = str_starts_with($argument, $prefix) ? substr($argument, strlen($prefix)) : '';

            if ($value === '') {
                continue;
            }

            $values[] = $value;
        }

        return $values;
    }

}
