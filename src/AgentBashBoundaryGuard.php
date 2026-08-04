<?php

declare(strict_types = 1);

namespace AgenticVibes\AgentSkills;

/**
 * Decides what an agent's Bash command should be allowed to do, from the policy alone.
 *
 * Precedence is deliberate and total: a `deny` from any layer wins, an `ask` (a matched rule that
 * needs a human, or a command this validator could not read with confidence) comes next, and
 * `defer` — no opinion, run the normal permission flow — is the only remaining outcome. There is
 * no path to `allow`; `BashBoundaryDecision` has no such case.
 *
 * `permission_mode` never reaches this class. A validator that softened its decision because the
 * session runs in `bypassPermissions` would be strictest exactly when it matters least.
 */
final class AgentBashBoundaryGuard
{

    public static function evaluate(?string $agentType, string $command): BashBoundaryVerdict
    {
        return self::evaluateInspection($agentType, BashCommandInspector::inspect($command));
    }

    private static function evaluateInspection(?string $agentType, BashCommandInspection $inspection): BashBoundaryVerdict
    {
        $verdicts = [
            ...self::matchRules(AgentBashBoundaryPolicy::getGlobalRules(), $inspection),
            ...self::matchRules(AgentBashBoundaryPolicy::rulesFor($agentType), $inspection),
            ...self::rawSocketVerdicts($inspection),
            ...self::sensitivePathVerdicts($inspection),
            ...self::redirectionVerdicts($agentType, $inspection),
            ...self::writeCommandVerdicts($agentType, $inspection),
            ...self::ambiguityVerdicts($inspection),
        ];

        return self::strongest($verdicts);
    }

    /**
     * @param list<\AgenticVibes\AgentSkills\BashBoundaryRule> $rules
     * @return list<\AgenticVibes\AgentSkills\BashBoundaryVerdict>
     */
    private static function matchRules(array $rules, BashCommandInspection $inspection): array
    {
        $verdicts = [];

        foreach ($inspection->invocations as $invocation) {
            foreach ($rules as $rule) {
                if ($rule->matches($invocation)) {
                    $verdicts[] = new BashBoundaryVerdict($rule->decision, $rule->reason);
                }
            }
        }

        return $verdicts;
    }

    /**
     * @return list<\AgenticVibes\AgentSkills\BashBoundaryVerdict>
     */
    private static function rawSocketVerdicts(BashCommandInspection $inspection): array
    {
        if (!$inspection->usesRawSocket) {
            return [];
        }

        $reason = sprintf(
            'A `/dev/tcp` or `/dev/udp` redirection is an outbound network request, which no agent may run through Bash (%s).',
            AgentBashBoundaryPolicy::RULE_SOURCE,
        );

        return [new BashBoundaryVerdict(BashBoundaryDecision::Deny, $reason)];
    }

    /**
     * @return list<\AgenticVibes\AgentSkills\BashBoundaryVerdict>
     */
    private static function sensitivePathVerdicts(BashCommandInspection $inspection): array
    {
        $verdicts = [];

        foreach (AgentBashBoundaryPolicy::getSensitivePathPatterns() as $label => $pattern) {
            if (self::matchesAnyArgument($inspection, $pattern)) {
                $verdicts[] = new BashBoundaryVerdict(
                    BashBoundaryDecision::Deny,
                    sprintf('This command reads %s, which no agent may read through Bash (%s).', $label, AgentBashBoundaryPolicy::RULE_SOURCE),
                );
            }
        }

        return $verdicts;
    }

    private static function matchesAnyArgument(BashCommandInspection $inspection, string $pattern): bool
    {
        foreach ($inspection->invocations as $invocation) {
            foreach ($invocation->arguments as $argument) {
                if (preg_match($pattern, $argument) === 1) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * A read-only agent that redirects output anywhere outside its own scratch paths is writing a
     * file the harness would otherwise let it write, since `Bash` subsumes `Write`.
     *
     * Redirection is one write mechanism, not the mechanism — `writeCommandVerdicts()` below
     * covers the file-mutating programs, and both together still leave the residual gap
     * `docs/agents.md` *Architecture constraint* names.
     *
     * @return list<\AgenticVibes\AgentSkills\BashBoundaryVerdict>
     */
    private static function redirectionVerdicts(?string $agentType, BashCommandInspection $inspection): array
    {
        $allowed = self::writablePathsFor($agentType);

        if ($allowed === null) {
            return [];
        }

        $reason = sprintf(
            'Redirecting output to a path outside `%s`\'s permitted ones is a file write (agents/%s.md § Bash boundary).',
            $agentType,
            $agentType,
        );

        $verdicts = [];

        foreach ($inspection->redirectionTargets as $target) {
            $verdict = self::targetVerdict($target, $allowed, $agentType, $reason);

            if ($verdict !== null) {
                $verdicts[] = $verdict;
            }
        }

        return $verdicts;
    }

    /**
     * The same restriction as `redirectionVerdicts()`, for the programs whose whole purpose is to
     * write: `tee`, `cp`, `mv`, `rm`, `install`, `truncate`, `dd`, and `sed`/`perl` editing in
     * place. Without this, "read-only" would mean nothing more than "does not use `>`".
     *
     * @return list<\AgenticVibes\AgentSkills\BashBoundaryVerdict>
     */
    private static function writeCommandVerdicts(?string $agentType, BashCommandInspection $inspection): array
    {
        $allowed = self::writablePathsFor($agentType);

        if ($allowed === null) {
            return [];
        }

        $verdicts = [];

        foreach ($inspection->invocations as $invocation) {
            $verdicts = [...$verdicts, ...self::invocationWriteVerdicts($invocation, $allowed, $agentType)];
        }

        return $verdicts;
    }

    /**
     * A write target is any word that is not an option — plus the one a destination option carries
     * instead, in either the same word or the next one. Every remaining option word has to be read
     * too, because for the four programs that have such an option a shape this validator cannot
     * account for is refused rather than skipped; see `optionWordVerdict()`.
     *
     * @param list<string> $allowed
     * @return list<\AgenticVibes\AgentSkills\BashBoundaryVerdict>
     */
    private static function invocationWriteVerdicts(BashCommandInvocation $invocation, array $allowed, ?string $agentType): array
    {
        if (!self::mutatesFiles($invocation)) {
            return [];
        }

        [$paths, $options] = $invocation->partitionArguments();
        $paths = [...$paths, ...CoreutilsOptionWord::separatedDestinations($invocation->program, $invocation->arguments)];
        $verdicts = [];

        foreach ($paths as $path) {
            $verdict = self::targetVerdict($path, $allowed, $agentType, self::writeReason($invocation->program, $agentType));

            if ($verdict !== null) {
                $verdicts[] = $verdict;
            }
        }

        foreach ($options as $option) {
            $verdict = self::optionWordVerdict($invocation->program, $option, $allowed, $agentType);

            if ($verdict !== null) {
                $verdicts[] = $verdict;
            }
        }

        return $verdicts;
    }

    /**
     * An option word of one of the four programs `AgentBashBoundaryPolicy::getCoreutilsOptionGrammar()`
     * covers either carries a write destination — which is judged exactly like a positional one —
     * or is a shape that grammar accounts for, or is refused.
     *
     * The refusal is the point of the design: GNU accepts the destination option under an open set
     * of spellings, so a validator that recognised spellings would keep leaking one of them per
     * review round. Here an option word this validator cannot read is a `deny`, and the over-match
     * that direction produces — an option only one of the four programs has (`cp -D`), or an
     * abbreviation GNU would itself call ambiguous — refuses a command GNU would have rejected
     * anyway, which is the direction `isInPlaceFlag()` already fails in.
     *
     * @param list<string> $allowed
     */
    private static function optionWordVerdict(string $program, string $word, array $allowed, ?string $agentType): ?BashBoundaryVerdict
    {
        $reading = CoreutilsOptionWord::read($program, $word);

        if ($reading === null) {
            return null;
        }

        if (!$reading->readable) {
            return new BashBoundaryVerdict(BashBoundaryDecision::Deny, self::unreadableOptionReason($program, $agentType));
        }

        if ($reading->destination === null) {
            return null;
        }

        return self::targetVerdict($reading->destination, $allowed, $agentType, self::writeReason($program, $agentType));
    }

    private static function writeReason(string $program, ?string $agentType): string
    {
        return sprintf(
            '`%1$s` writes files, and this one targets a path outside `%2$s`\'s permitted ones (agents/%2$s.md § Bash boundary).',
            $program,
            $agentType,
        );
    }

    private static function unreadableOptionReason(string $program, ?string $agentType): string
    {
        return sprintf(
            'This `%1$s` option cannot be read as one that carries no write destination, and `%2$s` may not write outside its own '
                . 'paths, so it is refused rather than passed through (agents/%2$s.md § Bash boundary).',
            $program,
            $agentType,
        );
    }

    /**
     * @return ?list<string>
     */
    private static function writablePathsFor(?string $agentType): ?array
    {
        if ($agentType === null) {
            return null;
        }

        return AgentBashBoundaryPolicy::getWritablePathFragments()[$agentType] ?? null;
    }

    private static function mutatesFiles(BashCommandInvocation $invocation): bool
    {
        if (in_array($invocation->program, AgentBashBoundaryPolicy::getFileMutatingPrograms(), strict: true)) {
            return true;
        }

        if (!in_array($invocation->program, AgentBashBoundaryPolicy::getInPlaceEditPrograms(), strict: true)) {
            return false;
        }

        foreach ($invocation->arguments as $argument) {
            if (self::isInPlaceFlag($argument)) {
                return true;
            }
        }

        return false;
    }

    /**
     * `-i`, the suffix spellings `sed -i.bak` / `perl -i.orig`, the bundled short-option clusters
     * `perl -pi -e` and `perl -pi.bak -e` — which is *the* idiomatic in-place perl invocation — and
     * the GNU long form `sed --in-place` / `--in-place=.bak`.
     *
     * A cluster is matched on containing an `i` at all, so an option that merely happens to carry
     * one (`perl -Mstrict`) is read as in-place too. That over-matches into `deny` for a command
     * that also names a path outside the agent's own, which is the direction this validator is
     * meant to fail in; the alternative spelling-by-spelling table would miss a real edit instead.
     *
     * Digits are part of a cluster, not its end: `perl -0pi -e` and `perl -0777pi -e` — the
     * standard whole-file slurp substitution — rewrite the file exactly as `perl -pi -e` does, and
     * a letters-only cluster stops reading at the first digit and never reaches the `i`.
     */
    private static function isInPlaceFlag(string $argument): bool
    {
        return preg_match('/^-[A-Za-z0-9]*i/', $argument) === 1 || str_starts_with($argument, '--in-place');
    }

    /**
     * `null` when the target is permitted. A target the shell would still expand — `cat >> "$BRIEF"`
     * and `rm -rf "${BRIEF%.md}.audit"` are commands the read-only agents' own boundaries grant —
     * is a path this validator never actually read, so it asks rather than refusing a guess.
     *
     * Both callers therefore collect **every** verdict and leave precedence to `strongest()`.
     * Returning the first one instead would let an unread target that happens to come first silence
     * a literal violation later in the same command — `cat >> "$BRIEF" ; echo x > src/Installer.php`
     * would decide on argument order rather than on what it writes.
     *
     * @param list<string> $allowed
     */
    private static function targetVerdict(string $target, array $allowed, ?string $agentType, string $denyReason): ?BashBoundaryVerdict
    {
        if (self::isWritable($target, $allowed)) {
            return null;
        }

        if (!str_contains($target, '$')) {
            return new BashBoundaryVerdict(BashBoundaryDecision::Deny, $denyReason);
        }

        return new BashBoundaryVerdict(BashBoundaryDecision::Ask, self::unresolvedTargetReason($agentType));
    }

    private static function unresolvedTargetReason(?string $agentType): string
    {
        return sprintf(
            'This write target is assembled at runtime, so whether it stays inside `%s`\'s permitted paths cannot be read from the command string.',
            $agentType,
        );
    }

    /**
     * The allow-list is matched against a **lexically normalised** path, and a path that still
     * escapes upward after normalisation is never writable. A substring test on the raw target
     * accepts `.claude/run/../../../etc/hosts` — the allow-listed fragment is present, and the
     * path leaves the directory immediately after it.
     *
     * @param list<string> $allowed
     */
    private static function isWritable(string $target, array $allowed): bool
    {
        $normalized = self::normalizePath($target);

        if (str_contains('/' . $normalized . '/', '/../')) {
            return false;
        }

        // A `~`-rooted path is the user's home directory by definition, and every fragment granted
        // here names the project's own scratch space — `~/.claude/run/x` is a different directory
        // than `.claude/run/x` and was never granted.
        if (str_starts_with($normalized, '~')) {
            return false;
        }

        foreach ($allowed as $fragment) {
            if (self::matchesFragment($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A fragment is a path prefix matched at a segment boundary, never a substring, so
     * `foo.claude/run/bar` and `/dev/nullify` are not writable. A fragment ending in `-` is a
     * deliberate name prefix: `agent-cr-` has to cover `agent-cr-<slug>-athena`.
     */
    private static function matchesFragment(string $path, string $fragment): bool
    {
        $prefix = preg_quote(rtrim($fragment, '/'), '#');
        $tail = str_ends_with($fragment, '-') ? '' : '(/|$)';

        return preg_match('#(^|/)' . $prefix . $tail . '#', $path) === 1;
    }

    /**
     * Collapses `.` and `..` segments lexically — no filesystem access, which this pure function
     * neither needs nor wants. A `..` that cannot be collapsed (it climbs above the path's own
     * root) is kept, so the caller can refuse it rather than silently accept an escape.
     */
    private static function normalizePath(string $path): string
    {
        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment !== '..' || $segments === [] || end($segments) === '..') {
                $segments[] = $segment;

                continue;
            }

            array_pop($segments);
        }

        return (str_starts_with($path, '/') ? '/' : '') . implode('/', $segments);
    }

    /**
     * @return list<\AgenticVibes\AgentSkills\BashBoundaryVerdict>
     */
    private static function ambiguityVerdicts(BashCommandInspection $inspection): array
    {
        if (!$inspection->ambiguous) {
            return [];
        }

        $reason = 'This command could not be read with confidence (unbalanced quoting, or a program name built at runtime), '
            . 'so it is not being judged automatically.';

        return [new BashBoundaryVerdict(BashBoundaryDecision::Ask, $reason)];
    }

    /**
     * @param list<\AgenticVibes\AgentSkills\BashBoundaryVerdict> $verdicts
     */
    private static function strongest(array $verdicts): BashBoundaryVerdict
    {
        foreach ([BashBoundaryDecision::Deny, BashBoundaryDecision::Ask] as $decision) {
            foreach ($verdicts as $verdict) {
                if ($verdict->decision === $decision) {
                    return $verdict;
                }
            }
        }

        return new BashBoundaryVerdict(BashBoundaryDecision::Defer, 'No rule in the agent Bash boundary policy applies to this command.');
    }

}
