<?php

declare(strict_types = 1);

namespace AgenticVibes\AgentSkills;

/**
 * The decisions this validator is allowed to reach.
 *
 * `allow` is deliberately **not** a case, and this enum is the only way a decision can be
 * constructed. Emitting `allow` from a PreToolUse hook overrides the user's own
 * `permissions.deny` — including the `Bash(curl:*)` entries `--deny-network-bash` writes — so a
 * validator meant to narrow the boundary could end up widening it. Making the value
 * unrepresentable is stronger than a test asserting it never appears, and both exist.
 *
 * `defer` means "no opinion, run the normal permission flow"; it is never an approval.
 */
enum BashBoundaryDecision: string
{

    case Ask = 'ask';
    case Defer = 'defer';
    case Deny = 'deny';

}
