<?php

declare(strict_types = 1);

namespace AgenticVibes\AgentSkills;

/**
 * One command a Bash string would actually execute: the program token, normalised to its
 * basename (`/usr/bin/curl` and `./curl` both become `curl`), plus the words that follow it.
 */
final readonly class BashCommandInvocation
{

    /**
     * @param list<string> $arguments
     */
    public function __construct(public string $program, public array $arguments)
    {
    }

    /**
     * The arguments split the way a program's own option parser would split them: the words that
     * are not options first, the option words second, and everything past the `--` terminator in
     * the first group however much it looks like an option — a file really named `-r` is written
     * just the same.
     *
     * @return array{0: list<string>, 1: list<string>}
     */
    public function partitionArguments(): array
    {
        $arguments = $this->arguments;
        $terminator = array_search('--', $arguments, strict: true);
        $words = [];

        if ($terminator !== false) {
            $words = array_slice($arguments, $terminator + 1);
            $arguments = array_slice($arguments, 0, $terminator);
        }

        $options = [];

        foreach ($arguments as $argument) {
            if (str_starts_with($argument, '-')) {
                $options[] = $argument;

                continue;
            }

            $words[] = $argument;
        }

        return [$words, $options];
    }

}
