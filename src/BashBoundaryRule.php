<?php

declare(strict_types = 1);

namespace AgenticVibes\AgentSkills;

/**
 * One policy rule: a program, optionally narrowed to a subcommand and to an argument prefix.
 *
 * The narrowing is what keeps the rules honest in both directions. `openssl` must be denied
 * through `s_client` only, or `openssl dgst` — the checksum verification
 * `@rules/security/backend.md` requires — would be blocked too; `git push` must be denied for
 * `--force*` and for the default branch without denying `git push origin feature/x`. Matching is
 * exact on the program token and positional on subcommands, never substring on the command
 * string.
 */
final readonly class BashBoundaryRule
{

    /**
     * Global options that take their value as the *next* word. Dropping such a flag without its
     * value leaves the value in the positional slot the subcommand is expected in, so
     * `git -C /repo commit` would read as the subcommand `/repo` and match nothing — one global
     * option would then disable every `subcommands:` rule. The `--flag=value` spelling needs no
     * entry: it is a single word starting with `-` and is dropped whole.
     */
    private const array VALUE_OPTIONS = [
        'gh' => ['-R', '--repo'],
        'git' => ['-C', '-c', '--exec-path', '--git-dir', '--namespace', '--work-tree'],
    ];

    /**
     * @param list<string> $subcommands positional non-flag words that must follow the program
     * @param list<string> $anyArgument any one of these must appear among the arguments; an entry
     *                                  ending in `*` matches by prefix, every other entry matches
     *                                  the whole argument
     */
    public function __construct(
        public string $program,
        public array $subcommands,
        public array $anyArgument,
        public BashBoundaryDecision $decision,
        public string $reason,
    ) {
    }

    public function matches(BashCommandInvocation $invocation): bool
    {
        if ($invocation->program !== $this->program) {
            return false;
        }

        if (!$this->matchesSubcommands($invocation->arguments)) {
            return false;
        }

        return $this->anyArgument === [] || $this->matchesAnyArgument($invocation->arguments);
    }

    /**
     * @param list<string> $arguments
     */
    private function matchesSubcommands(array $arguments): bool
    {
        $words = $this->positionalWords($arguments);

        foreach ($this->subcommands as $index => $subcommand) {
            if (($words[$index] ?? null) !== $subcommand) {
                return false;
            }
        }

        return true;
    }

    /**
     * The words a subcommand can occupy: options dropped, and the value of a value-taking global
     * option dropped with it.
     *
     * @param list<string> $arguments
     * @return list<string>
     */
    private function positionalWords(array $arguments): array
    {
        $words = [];
        $skipValue = false;

        foreach ($arguments as $argument) {
            if ($skipValue) {
                $skipValue = false;

                continue;
            }

            if (!str_starts_with($argument, '-')) {
                $words[] = $argument;

                continue;
            }

            $skipValue = in_array($argument, self::VALUE_OPTIONS[$this->program] ?? [], strict: true);
        }

        return $words;
    }

    /**
     * @param list<string> $arguments
     */
    private function matchesAnyArgument(array $arguments): bool
    {
        foreach ($arguments as $argument) {
            if ($this->matchesAnyPattern($argument)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Exact by default, prefix only where the rule asks for it. `--force*` genuinely has to cover
     * `--force-with-lease`; a branch name does not — matching `master` by prefix denies
     * `maintenance/foo`, `mainline` and `master-backup`, which are ordinary branch names.
     *
     * The exact comparison runs against the argument's refspec destination as well, because what a
     * `git push` argument names is a destination and not a branch: `+master` force-writes it and
     * `master:master` / `HEAD:master` write it under another local name. Comparing the raw word
     * alone let all three through while the branch names above were correctly left alone.
     */
    private function matchesAnyPattern(string $argument): bool
    {
        $destination = $this->refspecDestination($argument);

        foreach ($this->anyArgument as $pattern) {
            if ($this->matchesPattern($argument, $destination, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function matchesPattern(string $argument, string $destination, string $pattern): bool
    {
        if (str_ends_with($pattern, '*')) {
            return str_starts_with($argument, substr($pattern, 0, -1));
        }

        return $argument === $pattern || $destination === $pattern;
    }

    /**
     * The half of `<src>:<dst>` that gets written, with the force marker removed: `+master` is
     * `master`, `HEAD:master` is `master`, and `master:feature/x` is `feature/x` — the last of
     * which must keep deferring, since pushing the local default branch somewhere else does not
     * write the default branch.
     */
    private function refspecDestination(string $argument): string
    {
        $refspec = ltrim($argument, '+');
        $position = strrpos($refspec, ':');

        return $position === false ? $refspec : substr($refspec, $position + 1);
    }

}
