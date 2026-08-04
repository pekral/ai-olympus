<?php

declare(strict_types = 1);

namespace AgenticVibes\AgentSkills;

/**
 * A decision plus the sentence explaining it.
 *
 * Every reason this package produces is authored in `AgentBashBoundaryPolicy` — a rule only fires
 * when the program token equals the rule's own program, so the sentence can name that program
 * without ever echoing the command string back. A Bash command may carry a token, a URL, or a
 * file path; the reason travels into the transcript, so it must stay attacker-input-free
 * (`@rules/security/backend.md` *No verbatim echo of attacker input*).
 */
final readonly class BashBoundaryVerdict
{

    public function __construct(public BashBoundaryDecision $decision, public string $reason)
    {
    }

}
