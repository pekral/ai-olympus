<?php

declare(strict_types = 1);

namespace AgenticVibes\AgentSkills;

/**
 * One word of a tokenized Bash segment, plus the one thing a finished string can no longer tell
 * you about it: whether it is a redirection operator.
 *
 * `>`, `>>`, `<`, `<>`, `>|`, `2>` and `&>` are shell metacharacters — a word breaks on them with
 * or without surrounding whitespace, so `curl>/dev/null` is a program, an operator and a target
 * rather than the single word a naive scanner sees. The distinction has to be made *during* the
 * scan, while quoting is still known: a `>` that arrived inside quotes is ordinary text
 * (`grep -rn '>foo' README.md` redirects nothing), and a finished token can no longer tell the two
 * apart.
 */
final readonly class BashCommandWord
{

    public function __construct(public string $text, public bool $isRedirection)
    {
    }

}
