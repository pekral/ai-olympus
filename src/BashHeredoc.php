<?php

declare(strict_types = 1);

namespace AgenticVibes\AgentSkills;

/**
 * One heredoc opened by a segment, held until the scan reaches the newline its body starts after.
 *
 * The opening (`<<XEOF`) and the body it introduces are separated by the rest of the command line,
 * so the two cannot be consumed in one step. A shell resolves this the same way: it finishes the
 * line, then reads the bodies in the order their openings appeared, which is why this is queued
 * rather than handled where it is found.
 */
final readonly class BashHeredoc
{

    public function __construct(public string $delimiter, public bool $stripsTabs)
    {
    }

}
