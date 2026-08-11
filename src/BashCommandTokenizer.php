<?php

declare(strict_types = 1);

namespace AgenticVibes\AgentSkills;

/**
 * Splits a Bash command string into the segments a shell would execute, and each segment into
 * its words.
 *
 * This exists because substring matching on a command string is both bypassable and false-positive
 * prone — `str_contains($command, 'curl')` misses `/usr/bin/curl` only by luck and fires on
 * `git log --grep=curl`. Tokenizing first lets the caller compare a *program token* against a
 * policy instead of a haystack against a needle.
 *
 * Redirection operators are recognised during the scan rather than pattern-matched on a finished
 * token, because they are metacharacters: they break a word with or without whitespace around
 * them. Recognising them here — where quoting is still known — is what makes `curl>/dev/null`
 * three words rather than one, and what keeps a quoted `'>foo'` an ordinary argument instead of an
 * invented redirection. Each word therefore carries whether it is an operator (`BashCommandWord`).
 *
 * Two deliberate conservative choices, both of which fail toward "tell the caller you are unsure"
 * rather than toward silence:
 *
 * - Command substitutions (`$(…)`, backticks) are lifted out before the character scan and
 *   returned separately, so the caller can inspect them as commands in their own right. They are
 *   lifted even inside single quotes, where a real shell would not expand them: the cost is a
 *   false positive on `echo '$(curl …)'`, and the alternative — tracking quote state through the
 *   lift — would make `'"'"'$(curl …)'"'"'` a silent bypass.
 * - Anything left unbalanced (an unterminated quote, a `$(` or backtick the lift could not close)
 *   sets `ambiguous`, which callers turn into `ask` rather than into a guess.
 * - A process substitution (`<(…)`, `>(…)`) sets `ambiguous` too, because the parenthesis splits
 *   the segment and detaches every following word from its program. Saying "unsure" costs an
 *   `ask`; parsing it would mean re-attaching those words to the outer command.
 * - A heredoc body is skipped as data rather than scanned, and an unterminated one sets
 *   `ambiguous`. The body is a payload the command reads on stdin, not command text: scanning it
 *   lets any `>` inside it invent a redirection the shell would never perform, which the caller
 *   then attributes to the command as a write.
 *
 * What it cannot see, by construction: a program name assembled at runtime (`c=curl; $c …`), the
 * contents of a script file passed to an interpreter, and anything a child process does.
 */
final class BashCommandTokenizer
{

    /**
     * `(?&body)` recurses into the same group, so nested parentheses inside `$( … )` are balanced
     * rather than cut at the first `)`.
     */
    private const string SUBSTITUTION_PATTERN = '/\$\((?<body>(?:[^()]|\((?&body)\))*)\)/';

    private const string BACKQUOTE_PATTERN = '/`([^`]*)`/';

    /**
     * Word separators that end a command: `;`, `|`, `&`, subshell parentheses, and newline.
     */
    private const string SEPARATORS = ";|&\n()";

    /**
     * Redirection operators, longest spelling first so `>>` never matches as `>`, `>|` never as
     * `>`, and `2>&1` never as `2>` followed by a word. The optional file-descriptor prefix (the
     * `2` in `2>err.log`) is taken from the word being built rather than from this pattern, so
     * `a2>b` still yields the program `a2`. `A` anchors the match at the scan position.
     */
    private const string REDIRECTION_PATTERN = '/&>>|&>|>>|>\||>&|>|<<<|<>|<&|</A';

    /**
     * A heredoc opening: `<<WORD`, `<<'WORD'`, `<<"WORD"`, `<<-WORD`, and the same with whitespace
     * after the operator. `(?!<)` keeps `<<<` out — a here-string takes its word on the same line
     * and has no body, so reading it as a heredoc would swallow the rest of the command hunting for
     * a terminator that never comes.
     */
    private const string HEREDOC_PATTERN = '/<<(?!<)(?<dash>-?)[ \t]*(?:(?<quote>[\'"])(?<quoted>[^\'"\n]*)\k<quote>|(?<bare>[^\s;|&<>()\'"`]+))/A';

    private const string REDIRECTION_STARTS = '<>&';

    private const string WHITESPACE = " \t\r";

    private int $position = 0;

    private bool $ambiguous = false;

    private string $token = '';

    /**
     * @var list<\AgenticVibes\AgentSkills\BashCommandWord>
     */
    private array $words = [];

    /**
     * @var list<list<\AgenticVibes\AgentSkills\BashCommandWord>>
     */
    private array $segments = [];

    /**
     * @var list<\AgenticVibes\AgentSkills\BashHeredoc>
     */
    private array $pendingHeredocs = [];

    /**
     * @param list<string> $substitutions
     */
    private function __construct(private readonly string $command, private readonly array $substitutions)
    {
    }

    /**
     * @return array{ambiguous: bool, segments: list<list<\AgenticVibes\AgentSkills\BashCommandWord>>, substitutions: list<string>}
     */
    public static function tokenize(string $command): array
    {
        [$stripped, $substitutions] = self::liftSubstitutions($command);
        $result = new self($stripped, $substitutions)->run();
        $unbalanced = str_contains($stripped, '$(') || str_contains($stripped, '`');

        return [
            'ambiguous' => $result['ambiguous'] || $unbalanced,
            'segments' => $result['segments'],
            'substitutions' => $result['substitutions'],
        ];
    }

    /**
     * @return array{ambiguous: bool, segments: list<list<\AgenticVibes\AgentSkills\BashCommandWord>>, substitutions: list<string>}
     */
    private function run(): array
    {
        $length = strlen($this->command);

        while ($this->position < $length) {
            $this->consumeCharacter();
        }

        $this->flushSegment();

        // An opening whose body never started — the command ends on the same line it was declared
        // on — leaves the payload unaccounted for, which is the one direction to fail toward `ask`.
        $ambiguous = $this->ambiguous || $this->pendingHeredocs !== [];

        return ['ambiguous' => $ambiguous, 'segments' => $this->segments, 'substitutions' => $this->substitutions];
    }

    private function consumeCharacter(): void
    {
        $char = $this->command[$this->position];

        if ($this->consumeStructure($char)) {
            return;
        }

        $this->markProcessSubstitution($char);

        if ($this->consumeHeredoc($char)) {
            return;
        }

        if ($this->consumeRedirection($char)) {
            return;
        }

        if (str_contains(self::WHITESPACE, $char)) {
            $this->flushToken();
            $this->position++;

            return;
        }

        if (str_contains(self::SEPARATORS, $char)) {
            $this->flushSegment();
            $this->position++;

            if ($char === "\n") {
                $this->skipPendingHeredocBodies();
            }

            return;
        }

        $this->token .= $char;
        $this->position++;
    }

    /**
     * `<(…)` / `>(…)` is a process substitution: the shell runs the inner command and passes its
     * output as a filename. The parenthesis is an ordinary segment separator here, so the words
     * *after* the substitution detach from their own program — `cp <(echo x) src/Foo.php` reads as
     * `cp` with no arguments and `src/Foo.php` as a program, and the write target disappears with
     * `ambiguous` still false, which is the one direction this tokenizer promises never to fail in.
     *
     * It is reported as ambiguous rather than decomposed. Parsing it would mean running the nested
     * command through the inspector *and* re-attaching the following words to the outer program;
     * saying "unsure" is what the caller can already act on, and it turns the same detachment in
     * the sensitive-path check (`cat <(true) .env`) from a silent `defer` into an `ask`.
     *
     * It runs after `consumeStructure()`, so a quoted or escaped `<(` stays a literal word.
     */
    private function markProcessSubstitution(string $char): void
    {
        if (($char === '<' || $char === '>') && ($this->command[$this->position + 1] ?? '') === '(') {
            $this->ambiguous = true;
        }
    }

    /**
     * Quotes and backslash escapes: the characters that decide whether a separator separates.
     */
    private function consumeStructure(string $char): bool
    {
        if ($char === '\'' || $char === '"') {
            $this->consumeQuoted($char);

            return true;
        }

        if ($char !== '\\') {
            return false;
        }

        $this->token .= $this->command[$this->position + 1] ?? '';
        $this->position += 2;

        return true;
    }

    /**
     * Records the heredoc and consumes only its opening — the body starts after the current line
     * ends, so `skipPendingHeredocBodies()` finishes the job at the next newline.
     *
     * Without this, `<<` matched the redirection pattern as two `<` operators and the body was left
     * to the ordinary scan: a PHP object operator inside it became an output redirect, and a review
     * comment quoting PHP read as a write to whatever word followed it.
     *
     * It runs after `consumeStructure()`, so a quoted or escaped `<<` stays a literal word.
     */
    private function consumeHeredoc(string $char): bool
    {
        if ($char !== '<' || preg_match(self::HEREDOC_PATTERN, $this->command, $match, 0, $this->position) !== 1) {
            return false;
        }

        // A quoted delimiter leaves `bare` unset and vice versa, so neither group can be read alone.
        $bare = $match['bare'] ?? '';
        $delimiter = $bare === '' ? ($match['quoted'] ?? '') : $bare;
        $stripsTabs = $match['dash'] === '-';

        $this->flushToken();
        $this->words[] = new BashCommandWord('<<' . $match['dash'], isRedirection: true);
        $this->words[] = new BashCommandWord($delimiter, isRedirection: false);
        $this->pendingHeredocs[] = new BashHeredoc($delimiter, $stripsTabs);
        $this->position += strlen($match[0]);

        return true;
    }

    /**
     * Bodies follow the line that opened them, in the order the openings appeared — which is why
     * `cat <<A <<B` reads A's body first. A body that never meets its delimiter is ambiguous rather
     * than assumed to run to the end of the command.
     */
    private function skipPendingHeredocBodies(): void
    {
        while ($this->pendingHeredocs !== []) {
            if (!$this->skipToHeredocTerminator(array_shift($this->pendingHeredocs))) {
                $this->ambiguous = true;
            }
        }
    }

    /**
     * Advances the scan past the body and reports whether the delimiter was actually reached.
     */
    private function skipToHeredocTerminator(BashHeredoc $heredoc): bool
    {
        $length = strlen($this->command);

        while ($this->position < $length) {
            $break = strpos($this->command, "\n", $this->position);
            $lineEnd = $break === false ? $length : $break;
            $line = substr($this->command, $this->position, $lineEnd - $this->position);
            $this->position = $lineEnd + 1;

            if ($this->terminatesHeredoc($line, $heredoc)) {
                return true;
            }
        }

        return false;
    }

    private function terminatesHeredoc(string $line, BashHeredoc $heredoc): bool
    {
        return ($heredoc->stripsTabs ? ltrim($line, "\t") : $line) === $heredoc->delimiter;
    }

    /**
     * The one place a word can end without whitespace or a separator. It runs after
     * `consumeStructure()`, so a quoted or backslash-escaped `>` has already been consumed as
     * ordinary text and can never be read as an operator — `grep -rn '>foo' README.md` and
     * `echo \>` both keep their literal word.
     */
    private function consumeRedirection(string $char): bool
    {
        if (!str_contains(self::REDIRECTION_STARTS, $char)) {
            return false;
        }

        if (preg_match(self::REDIRECTION_PATTERN, $this->command, $match, 0, $this->position) !== 1) {
            return false;
        }

        $operator = $match[0];
        $descriptor = preg_match('/^\d+$/', $this->token) === 1 && !str_starts_with($operator, '&') ? $this->token : '';

        if ($descriptor !== '') {
            $this->token = '';
        }

        $this->flushToken();
        $this->words[] = new BashCommandWord($descriptor . $operator, isRedirection: true);
        $this->position += strlen($operator);

        return true;
    }

    private function consumeQuoted(string $quote): void
    {
        $end = strpos($this->command, $quote, $this->position + 1);

        if ($end === false) {
            $this->token .= substr($this->command, $this->position + 1);
            $this->position = strlen($this->command);
            $this->ambiguous = true;

            return;
        }

        $this->token .= substr($this->command, $this->position + 1, $end - $this->position - 1);
        $this->position = $end + 1;
    }

    private function flushToken(): void
    {
        if ($this->token === '') {
            return;
        }

        $this->words[] = new BashCommandWord($this->token, isRedirection: false);
        $this->token = '';
    }

    private function flushSegment(): void
    {
        $this->flushToken();

        if ($this->words === []) {
            return;
        }

        $this->segments[] = $this->words;
        $this->words = [];
    }

    /**
     * Lifts every command substitution out of the string, replacing it with a space so the
     * surrounding words still separate, and returns the bodies for separate inspection.
     *
     * @return array{0: string, 1: list<string>}
     */
    private static function liftSubstitutions(string $command): array
    {
        [$command, $expanded] = self::liftPattern($command, self::SUBSTITUTION_PATTERN);
        [$command, $backquoted] = self::liftPattern($command, self::BACKQUOTE_PATTERN);

        return [$command, [...$expanded, ...$backquoted]];
    }

    /**
     * A `preg_replace()` that fails (catastrophic backtracking, PCRE limits) returns the string
     * untouched rather than an empty one — the leftover `$(` then trips the unbalanced check in
     * `tokenize()`, so a failed lift becomes `ask` instead of a command nobody inspected.
     *
     * @return array{0: string, 1: list<string>}
     */
    private static function liftPattern(string $command, string $pattern): array
    {
        $count = preg_match_all($pattern, $command, $matches);

        if ($count === false || $count === 0) {
            return [$command, []];
        }

        $bodies = array_values(array_filter($matches[1], static fn (string $body): bool => trim($body) !== ''));
        $stripped = preg_replace($pattern, ' ', $command);

        return $stripped === null ? [$command, $bodies] : [$stripped, $bodies];
    }

}
