<?php

declare(strict_types = 1);

namespace Pekral\AiOlympus;

final readonly class MetricsEntry
{

    /**
     * @param array<string, int> $counters
     * @param array<string, int> $reviewCounters
     */
    public function __construct(
        public ?string $initialTier,
        public ?string $finalTier,
        public int $criticalAndModerateFindings,
        public array $counters,
        public array $reviewCounters,
        public ?string $reviewResult,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $findings = self::integers($data['findings'] ?? null);
        $review = $data['review'] ?? null;

        return new self(
            initialTier: self::string($data['initial_tier'] ?? null),
            finalTier: self::string($data['final_tier'] ?? null),
            criticalAndModerateFindings: ($findings['critical'] ?? 0) + ($findings['moderate'] ?? 0),
            counters: self::integers($data),
            reviewCounters: self::integers($review),
            reviewResult: is_array($review) ? self::string($review['result'] ?? null) : null,
        );
    }

    public function counter(string $key): ?int
    {
        return $this->counters[$key] ?? null;
    }

    public function reviewCounter(string $key): ?int
    {
        return $this->reviewCounters[$key] ?? null;
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /**
     * @return array<string, int>
     */
    private static function integers(mixed $values): array
    {
        $integers = [];

        foreach (is_array($values) ? $values : [] as $key => $value) {
            if (is_string($key) && is_int($value)) {
                $integers[$key] = $value;
            }
        }

        return $integers;
    }

}
