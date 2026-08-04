<?php

declare(strict_types = 1);

namespace AgenticVibes\AgentSkills;

/**
 * One option word of a coreutils file-mutating program, read against
 * `AgentBashBoundaryPolicy::getCoreutilsOptionGrammar()`.
 *
 * The reading is **fail-closed**: `$readable` is false for every shape the grammar cannot account
 * for, and the caller refuses those instead of passing them through. That is the whole point of
 * this class. GNU `getopt_long` accepts one option under many spellings — `--target-directory=x`,
 * any unambiguous abbreviation of it, and `-t` at any position of a short cluster — so a validator
 * that recognises spellings and ignores the rest leaks one spelling per review round. Recognising
 * *harmless* option words instead moves every unaccounted-for shape, including one a future
 * coreutils release introduces, onto the `deny` side.
 *
 * Being more permissive than GNU is deliberate wherever the two differ. An abbreviation is accepted
 * as the destination option whenever it is a prefix of its name, ambiguous or not, and a cluster is
 * read letter by letter without knowing whether the program would have errored first — both can
 * only ever turn a command into a refusal, never into a pass.
 */
final readonly class CoreutilsOptionWord
{

    /**
     * @param bool $readable whether the grammar could account for the word's shape at all
     * @param ?string $destination the write destination carried inside the word, if it carries one
     * @param bool $separated whether the word is the destination option in its separated spelling
     *                        (`-t src`, `--target-directory src`), whose value is the next word
     */
    private function __construct(public bool $readable, public ?string $destination, public bool $separated = false)
    {
    }

    /**
     * `null` for a program the policy does not read this strictly — its option words keep the
     * permissive treatment they had before, since none of them can carry a destination.
     *
     * `$word` is an option word, i.e. it starts with `-`; a positional argument is a path and is
     * never read here.
     */
    public static function read(string $program, string $word): ?self
    {
        $grammar = AgentBashBoundaryPolicy::getCoreutilsOptionGrammar()[$program] ?? null;

        if ($grammar === null) {
            return null;
        }

        if (str_starts_with($word, '--')) {
            return self::readLongOption($word, $grammar['destinationLong'], $grammar['long']);
        }

        return self::readShortCluster($word, $grammar['destinationShort'], $grammar['valueShort'], $grammar['flagShort']);
    }

    /**
     * The word each destination option in its separated spelling takes as its value. A positional
     * scan already sees an ordinary path there, but not one shaped like an option: `cp -t -r src`
     * copies into a directory really named `-r`, and `-r` is a valid `cp` flag, so nothing else in
     * this validator would ever look at it.
     *
     * @param list<string> $arguments
     * @return list<string>
     */
    public static function separatedDestinations(string $program, array $arguments): array
    {
        $destinations = [];

        foreach ($arguments as $position => $argument) {
            if (!str_starts_with($argument, '-')) {
                continue;
            }

            $next = $arguments[$position + 1] ?? null;

            if ($next !== null && self::read($program, $argument)?->separated === true) {
                $destinations[] = $next;
            }
        }

        return $destinations;
    }

    /**
     * A long option carries its value in the same word only after an `=`; without one the value is
     * the next word, which `separatedDestinations()` above is what picks up.
     *
     * @param list<string> $destinationLong
     * @param list<string> $long
     */
    private static function readLongOption(string $word, array $destinationLong, array $long): self
    {
        $body = substr($word, 2);
        $separator = strpos($body, '=');
        $name = $separator === false ? $body : substr($body, 0, $separator);
        $value = $separator === false ? null : substr($body, $separator + 1);

        if ($name === '') {
            return new self(readable: false, destination: null);
        }

        if (self::abbreviates($name, $destinationLong)) {
            return new self(readable: true, destination: $value, separated: $value === null);
        }

        return new self(readable: self::abbreviates($name, $long), destination: null);
    }

    /**
     * A short cluster is read left to right, exactly as `getopt` reads it: the first letter that
     * takes a value ends the cluster and swallows the rest of the word. The destination letter is
     * therefore honoured wherever it sits (`-ftsrc` is `-f -t src`), while a letter that belongs to
     * another option's value — `install -m755` — cannot be mistaken for one, because the scan has
     * already stopped at `m`.
     */
    private static function readShortCluster(string $word, string $destinationShort, string $valueShort, string $flagShort): self
    {
        $letters = substr($word, 1);
        // Everything up to the first letter that is not a flag belongs to no value, so the cluster
        // ends — and is read — at that letter, wherever in the word it sits.
        $rest = substr($letters, strspn($letters, $flagShort));

        if ($rest === '') {
            return new self(readable: true, destination: null);
        }

        $value = substr($rest, 1);

        if (str_contains($destinationShort, $rest[0])) {
            return new self(readable: true, destination: $value === '' ? null : $value, separated: $value === '');
        }

        return new self(readable: str_contains($valueShort, $rest[0]), destination: null);
    }

    /**
     * @param list<string> $options
     */
    private static function abbreviates(string $name, array $options): bool
    {
        foreach ($options as $option) {
            if (str_starts_with($option, $name)) {
                return true;
            }
        }

        return false;
    }

}
