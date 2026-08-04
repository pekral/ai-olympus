<?php

declare(strict_types = 1);

namespace AgenticVibes\AgentSkills;

/**
 * Turns a tokenized Bash command string into the list of programs it would run.
 *
 * The peeling done here is what makes the result stricter than a `permissions.deny` pattern:
 * wrappers (`env`, `xargs`, `sudo`, `bash -c '…'`), leading `VAR=value` assignments, shell
 * keywords, and absolute or relative paths are all removed before the program name is compared,
 * which closes bypasses 2 and 3 of the `--deny-network-bash` list in `SECURITY.md`. A wrapper is
 * recorded as an invocation in its own right *before* it is peeled, so `sudo curl …` yields both
 * `sudo` and `curl` rather than losing the outer one.
 *
 * Bypasses it does not close, and which must stay documented rather than assumed away: a child
 * process of an allowed program, a program name built at runtime, any obfuscation that only
 * resolves after the shell starts, and — the same class as "the contents of a script file passed
 * to an interpreter" — a program passed inline to a non-shell interpreter (`python3 -c …`,
 * `perl -e …`, `ruby -e …`, `node -e …`, `php -r …`). Only the shell wrappers' `-c` is peeled,
 * because only a shell's argument is another Bash command string; recursing into the others would
 * mean parsing four more languages, so they are named here rather than half-handled. A program
 * token that still contains `$` after tokenizing is reported as ambiguous instead of being
 * silently treated as unknown-and-therefore-fine.
 */
final class BashCommandInspector
{

    /**
     * Interpreters whose `-c` argument is another command string, inspected recursively.
     */
    private const array SHELL_WRAPPERS = ['ash', 'bash', 'dash', 'ksh', 'sh', 'zsh'];

    /**
     * Programs that run another program given as their argument. `sudo` is denied on its own too.
     */
    private const array WRAPPERS = [
        'builtin', 'command', 'doas', 'env', 'ionice', 'nice', 'nohup',
        'setsid', 'stdbuf', 'sudo', 'time', 'timeout', 'watch', 'xargs',
    ];

    /**
     * Shell syntax that can precede the program in a segment.
     */
    private const array KEYWORDS = [
        '!', '[[', ']]', '{', '{}', '}', 'case', 'do', 'done', 'elif', 'else',
        'esac', 'exec', 'fi', 'for', 'if', 'in', 'select', 'then', 'until', 'while',
    ];

    private const int MAX_DEPTH = 4;

    /**
     * @var list<\AgenticVibes\AgentSkills\BashCommandInvocation>
     */
    private array $invocations = [];

    /**
     * @var list<string>
     */
    private array $redirectionTargets = [];

    private bool $usesRawSocket = false;

    private bool $ambiguous = false;

    public static function inspect(string $command): BashCommandInspection
    {
        $inspector = new self();
        $inspector->consume($command, 0);

        return new BashCommandInspection($inspector->invocations, $inspector->redirectionTargets, $inspector->usesRawSocket, $inspector->ambiguous);
    }

    /**
     * Drops the shell keywords and `VAR=value` assignments that can precede the program.
     *
     * @param list<string> $tokens
     * @return list<string>
     */
    private function skipPrefix(array $tokens): array
    {
        foreach ($tokens as $index => $token) {
            if (!in_array($token, self::KEYWORDS, strict: true) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*=/', $token) !== 1) {
                return array_values(array_slice($tokens, $index));
            }
        }

        return [];
    }

    /**
     * Drops a wrapper's own options before its inner program: flags, `VAR=value` for `env`, and
     * bare numbers such as the `1` in `xargs -n 1 curl` or the duration in `timeout 5 curl`.
     *
     * @param list<string> $tokens
     * @return list<string>
     */
    private function skipOptions(array $tokens): array
    {
        foreach ($tokens as $index => $token) {
            if (!$this->isOptionLike($token)) {
                return array_values(array_slice($tokens, $index));
            }
        }

        return [];
    }

    private function isOptionLike(string $token): bool
    {
        return str_starts_with($token, '-')
            || preg_match('/^[A-Za-z_][A-Za-z0-9_]*=/', $token) === 1
            || preg_match('/^\d+[smhd]?$/', $token) === 1;
    }

    /**
     * `/usr/bin/curl` and `./curl` are the same program; a trailing slash never is one.
     */
    private function basename(string $token): string
    {
        $position = strrpos($token, '/');

        return $position === false ? $token : substr($token, $position + 1);
    }

    private function consume(string $command, int $depth): void
    {
        $tokens = BashCommandTokenizer::tokenize($command);
        $this->ambiguous = $this->ambiguous || $tokens['ambiguous'];

        foreach ($tokens['segments'] as $segment) {
            // Collected per segment rather than inside consumeSegment(), which recurses while
            // peeling wrappers and would record the same target once per peeled layer.
            $this->collectRedirections($segment);

            // The operators themselves are dropped here: they are punctuation, never a program.
            // Their targets stay, so `cat <.env` still exposes `.env` as an argument.
            $words = $this->plainWords($segment);

            $this->collectRawSocket($words);
            $this->consumeSegment($words, $depth);
        }

        foreach ($tokens['substitutions'] as $substitution) {
            $this->consumeNested($substitution, $depth + 1);
        }
    }

    private function consumeNested(string $command, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            $this->ambiguous = true;

            return;
        }

        $this->consume($command, $depth);
    }

    /**
     * @param list<string> $tokens
     */
    private function consumeSegment(array $tokens, int $depth): void
    {
        $words = $this->skipPrefix($tokens);

        if ($words === []) {
            return;
        }

        $program = $this->basename($words[0]);
        $arguments = array_values(array_slice($words, 1));

        if (str_contains($program, '$')) {
            $this->ambiguous = true;

            return;
        }

        $this->invocations[] = new BashCommandInvocation($program, $arguments);
        $this->peelWrapper($program, $arguments, $depth);
    }

    /**
     * @param list<string> $arguments
     */
    private function peelWrapper(string $program, array $arguments, int $depth): void
    {
        if (in_array($program, self::SHELL_WRAPPERS, strict: true)) {
            $this->peelShellWrapper($arguments, $depth);

            return;
        }

        if ($program === 'eval') {
            $this->consumeNested(implode(' ', $arguments), $depth + 1);

            return;
        }

        if (!in_array($program, self::WRAPPERS, strict: true)) {
            return;
        }

        // `command -v curl` asks whether curl exists, it does not run it. Peeling it would turn an
        // ordinary capability probe into a denial.
        if ($program === 'command' && $this->isCapabilityProbe($arguments)) {
            return;
        }

        $inner = $this->skipOptions($arguments);

        if ($inner !== []) {
            $this->consumeSegment($inner, $depth);
        }
    }

    /**
     * `-v` / `-V` is the `command` builtin's probe flag only while it is still one of `command`'s
     * own options — before the first non-option word. After that word it belongs to the program
     * being run, and `-v` happens to be `curl`'s verbose flag: `command curl -v <url>` runs curl.
     * Matching `-v` anywhere in the argument list would exempt exactly that spelling.
     *
     * @param list<string> $arguments
     */
    private function isCapabilityProbe(array $arguments): bool
    {
        foreach ($arguments as $argument) {
            if (!str_starts_with($argument, '-')) {
                return false;
            }

            // `-v`, `-V`, and the bundled short-flag spellings such as `-pv`.
            if (preg_match('/^-[a-zA-Z]*[vV][a-zA-Z]*$/', $argument) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $arguments
     */
    private function peelShellWrapper(array $arguments, int $depth): void
    {
        $position = array_search('-c', $arguments, strict: true);

        if ($position === false || !isset($arguments[$position + 1])) {
            return;
        }

        $this->consumeNested($arguments[$position + 1], $depth + 1);
    }

    /**
     * @param list<\AgenticVibes\AgentSkills\BashCommandWord> $words
     * @return list<string>
     */
    private function plainWords(array $words): array
    {
        $plain = [];

        foreach ($words as $word) {
            if (!$word->isRedirection) {
                $plain[] = $word->text;
            }
        }

        return $plain;
    }

    /**
     * Collects the target of every output redirection (`> f`, `>>f`, `2>f`, `>|f`, `&>f`), which
     * is how a read-only agent would create or overwrite a tracked file without running a write
     * command. Read redirections (`<`) are not writes and file-descriptor duplications (`2>&1`)
     * name a descriptor rather than a path, so neither yields a target.
     *
     * @param list<\AgenticVibes\AgentSkills\BashCommandWord> $words
     */
    private function collectRedirections(array $words): void
    {
        foreach (array_keys($words) as $index) {
            $target = $this->redirectionTarget($words, $index);

            if ($target !== null) {
                $this->redirectionTargets[] = $target;
            }
        }
    }

    /**
     * @param list<\AgenticVibes\AgentSkills\BashCommandWord> $words
     */
    private function redirectionTarget(array $words, int $index): ?string
    {
        $word = $words[$index];

        if (!$word->isRedirection || !str_contains($word->text, '>')) {
            return null;
        }

        $target = $words[$index + 1] ?? null;

        if ($target === null || $target->isRedirection || $target->text === '') {
            return null;
        }

        // `2>&1` and `2>&-` address a file descriptor; only a non-numeric word after `>&` is a path.
        if (str_ends_with($word->text, '&') && preg_match('/^(\d+|-)$/', $target->text) === 1) {
            return null;
        }

        return $target->text;
    }

    /**
     * `exec 3<>/dev/tcp/host/80` opens a socket without running any program, so no program token
     * would ever reveal it.
     *
     * @param list<string> $tokens
     */
    private function collectRawSocket(array $tokens): void
    {
        foreach ($tokens as $token) {
            if (str_contains($token, '/dev/tcp/') || str_contains($token, '/dev/udp/')) {
                $this->usesRawSocket = true;
            }
        }
    }

}
