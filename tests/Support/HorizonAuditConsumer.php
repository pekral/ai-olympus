<?php

declare(strict_types = 1);

namespace Tests\Support;

/**
 * One consumer of a physical queue in the reference evaluation of the Horizon audit fixtures: the
 * queue key (driver, Redis connection, database, prefix, queue name), its `retry_after`, and the
 * supervisor timeout. A part that is not a literal in the fixture is null.
 */
final readonly class HorizonAuditConsumer
{

    /**
     * @param list<string|null> $key
     */
    public function __construct(public array $key, public ?string $retryAfter, public ?string $timeout)
    {
    }

    public function hasUnknownKeyPart(self $other): bool
    {
        return in_array(needle: null, haystack: [...$this->key, ...$other->key], strict: true);
    }

    public function sharesQueueWith(self $other): bool
    {
        return $this->key === $other->key;
    }

    public function differsInRetryAfterFrom(self $other): bool
    {
        return $this->sharesQueueWith($other) && !$this->hasUnknownKeyPart($other) && $this->retryAfter !== $other->retryAfter;
    }

}
