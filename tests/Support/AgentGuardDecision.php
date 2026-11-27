<?php

declare(strict_types = 1);

namespace Tests\Support;

final readonly class AgentGuardDecision
{

    /**
     * @param array<string, mixed>|null $updatedInput
     */
    public function __construct(public string $permissionDecision, public ?array $updatedInput)
    {
    }

    public static function fromHookOutput(string $output): self
    {
        $decoded = json_decode($output, associative: true, flags: JSON_THROW_ON_ERROR);
        $specific = is_array($decoded) ? $decoded['hookSpecificOutput'] ?? null : null;
        assert(is_array($specific));
        $decision = $specific['permissionDecision'] ?? null;
        $updatedInput = $specific['updatedInput'] ?? null;
        assert(is_string($decision));

        return new self(
            permissionDecision: $decision,
            updatedInput: is_array($updatedInput) ? self::stringKeyed($updatedInput) : null,
        );
    }

    public function command(): string
    {
        $command = $this->updatedInput['command'] ?? null;

        return is_string($command) ? $command : '';
    }

    /**
     * @return array<string, mixed>
     */
    public function otherFields(): array
    {
        $fields = $this->updatedInput ?? [];
        unset($fields['command']);

        return $fields;
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $values): array
    {
        $keyed = [];

        foreach ($values as $key => $value) {
            $keyed[(string) $key] = $value;
        }

        return $keyed;
    }

}
