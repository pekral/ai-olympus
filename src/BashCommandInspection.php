<?php

declare(strict_types = 1);

namespace AgenticVibes\AgentSkills;

/**
 * Everything the policy needs to know about one Bash command string, and nothing else — the
 * string itself is deliberately not carried, so no decision reason can ever echo it back.
 */
final readonly class BashCommandInspection
{

    /**
     * @param list<\AgenticVibes\AgentSkills\BashCommandInvocation> $invocations
     * @param list<string> $redirectionTargets
     */
    public function __construct(public array $invocations, public array $redirectionTargets, public bool $usesRawSocket, public bool $ambiguous)
    {
    }

}
