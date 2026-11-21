<?php

declare(strict_types = 1);

use Pekral\AiOlympus\Installer;

function attackClassFile(string $relativePath): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
}

function attackClassSection(string $content, string $heading): string
{
    $start = (int) strpos($content, $heading);
    $end = strpos($content, "\n## ", $start + strlen($heading));

    return $end === false ? substr($content, $start) : substr($content, $start, $end - $start);
}

function attackClassPatternFailed(): never
{
    throw new RuntimeException('The git stash detector could not read the text: ' . preg_last_error_msg());
}

function attackClassMatches(string $pattern, string $subject): bool
{
    $result = preg_match($pattern, $subject);

    if ($result === false) {
        attackClassPatternFailed();
    }

    return $result === 1;
}

function attackClassReplace(string $pattern, string $replacement, string $subject): string
{
    return preg_replace($pattern, $replacement, $subject) ?? attackClassPatternFailed();
}

/**
 * @param array<mixed> $match
 */
function attackClassGroup(array $match, int $index): string
{
    $group = $match[$index] ?? '';

    return is_string($group) ? $group : '';
}

function attackClassCodePoint(int $codePoint): string
{
    return match (true) {
        $codePoint < 128 => chr($codePoint & 127),
        $codePoint <= 0x10FFFF => html_entity_decode('&#' . $codePoint . ';', ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        default => '',
    };
}

function attackClassAnsiEscape(string $escape): string
{
    $digits = trim(substr($escape, 1), '{}');
    $isCodePoint = strlen($escape) > 1 && in_array($escape[0], ['u', 'U'], strict: true) || str_starts_with($escape, 'x{');

    return match (true) {
        $isCodePoint => attackClassCodePoint((int) hexdec($digits)),
        strlen($escape) > 1 && $escape[0] === 'x' => chr((int) hexdec($digits) & 255),
        ctype_digit($escape[0]) => chr((int) octdec($escape) & 255),
        default => ['n' => "\n", 't' => "\t", 'r' => "\r"][$escape] ?? $escape,
    };
}

function attackClassAnsiC(string $text): string
{
    $decode = static fn (array $match): string => attackClassAnsiEscape(attackClassGroup($match, 1));
    $escape = '/\\\\(x\{[0-9a-fA-F]++\}|x[0-9a-fA-F]{1,2}|[0-7]{1,3}|u[0-9a-fA-F]{1,4}|U[0-9a-fA-F]{1,8}|.)/s';

    return preg_replace_callback($escape, $decode, $text) ?? attackClassPatternFailed();
}

function attackClassUnquoteSegment(string $segment): string
{
    return match (true) {
        str_starts_with($segment, '$\'') => attackClassAnsiC(substr($segment, 2, -1)),
        str_starts_with($segment, '$"') => attackClassReplace('/\\\\([$`"\\\\])/', '$1', substr($segment, 2, -1)),
        $segment[0] === '"' => attackClassReplace('/\\\\([$`"\\\\])/', '$1', substr($segment, 1, -1)),
        $segment[0] === '\'' => substr($segment, 1, -1),
        default => $segment[1],
    };
}

function attackClassUnquote(string $word): string
{
    $unquote = static fn (array $match): string => attackClassUnquoteSegment(attackClassGroup($match, 0));
    $quoted = '/\$\'(?:\\\\.|[^\'\\\\])*+\'|\$?"(?:\\\\.|[^"\\\\])*+"|\'[^\']*+\'|\\\\./s';

    return preg_replace_callback($quoted, $unquote, $word) ?? attackClassPatternFailed();
}

/**
 * Splits a text into shell words with the quotes and escapes removed. An empty string marks the end of a command.
 *
 * @return list<string>
 */
function attackClassShellWords(string $text): array
{
    $segment = '\$\'(?:\\\\.|[^\'\\\\])*+\'|\$?"(?:\\\\.|[^"\\\\])*+"|\'[^\']*+\'|\\\\.|[^\s"\'\\\\`;|&()<>]';
    $found = preg_match_all('/((?:' . $segment . ')++)|[`;|&()<>]/s', $text, $matches, PREG_SET_ORDER);

    if ($found === false) {
        attackClassPatternFailed();
    }

    return array_map(
        static fn (array $match): string => attackClassUnquote(attackClassGroup($match, 1)),
        $matches,
    );
}

function attackClassIsStashWord(string $word): bool
{
    return attackClassMatches('/^stash(?![\w-])/i', $word);
}

/**
 * Returns the position after the global options of git that start at the given position.
 *
 * @param list<string> $words
 */
function attackClassAfterGlobalOptions(array $words, int $position): int
{
    $valueOptions = '/^(?:-c|--(?:git-dir|work-tree|namespace|super-prefix|config-env|attr-source|shallow-file))$/i';
    $otherOptions = '/^(?:--[a-z][a-z-]*+(?:=.*+)?|-[A-Za-z]++)$/s';

    while (isset($words[$position])) {
        $step = match (true) {
            attackClassMatches($valueOptions, $words[$position]) => 2,
            attackClassMatches($otherOptions, $words[$position]) => 1,
            default => 0,
        };

        if ($step === 0) {
            break;
        }

        $position += $step;
    }

    return $position;
}

function attackClassIsGitWord(string $word): bool
{
    return attackClassMatches('/^\W*git\W*$/i', basename($word));
}

/**
 * @param list<string> $words
 */
function attackClassWordsNameGitStash(array $words): bool
{
    foreach ($words as $start => $word) {
        if (!attackClassIsGitWord($word)) {
            continue;
        }

        $next = attackClassAfterGlobalOptions($words, $start + 1);

        if (isset($words[$next]) && attackClassIsStashWord($words[$next])) {
            return true;
        }
    }

    return false;
}

function attackClassConfigKey(string $word): string
{
    if (!attackClassMatches('/^(?:alias\.[^=]++|help\.autocorrect)(?:=|$)/i', $word)) {
        return '';
    }

    return strtolower(attackClassReplace('/=.*$/s', '', $word));
}

/**
 * @param list<string> $words
 */
function attackClassConfigValue(array $words, int $position): string
{
    $inline = substr((string) strstr($words[$position], '='), 1);
    $next = $words[$position + 1] ?? '';

    return match (true) {
        $inline !== '' => $inline,
        $next === '=' => $words[$position + 2] ?? '',
        default => $next,
    };
}

/**
 * Only the values that switch the automatic correction off, or only show it, are safe. Git runs it for every other value.
 */
function attackClassAutocorrectRuns(string $value): bool
{
    return !attackClassMatches('/^\s*+(?:false|off|no|show|never|prompt|[+-]?0++[kmg]?)\s*+$/i', $value);
}

function attackClassAliasRuns(string $value, int $depth): bool
{
    $command = ltrim($value);

    if (str_starts_with($command, '!')) {
        return attackClassNamesGitStash(substr($command, 1), $depth + 1);
    }

    $words = attackClassShellWords($command);

    return attackClassIsStashWord($words[attackClassAfterGlobalOptions($words, 0)] ?? '');
}

function attackClassConfigRuns(string $key, string $value, int $depth): bool
{
    return $key === 'help.autocorrect' ? attackClassAutocorrectRuns($value) : attackClassAliasRuns($value, $depth);
}

/**
 * `git config <key> <value>` takes the whole word as the key, and an alias subsection name may hold `=`, so the next word is the value too.
 *
 * @param list<string> $words
 */
function attackClassWordSetsRunningConfig(array $words, int $position, int $depth): bool
{
    $key = attackClassConfigKey($words[$position]);

    if ($key === '') {
        return false;
    }

    return attackClassConfigRuns($key, attackClassConfigValue($words, $position), $depth)
        || str_starts_with($key, 'alias.') && attackClassAliasRuns($words[$position + 1] ?? '', $depth);
}

/**
 * @param list<string> $words
 */
function attackClassWordsSetRunningConfig(array $words, int $depth): bool
{
    foreach (array_keys($words) as $position) {
        if (attackClassWordSetsRunningConfig($words, $position, $depth)) {
            return true;
        }
    }

    return false;
}

function attackClassDecodeEscape(string $next): string
{
    return match ($next) {
        'n' => "\n",
        't' => "\t",
        'b' => '',
        default => $next,
    };
}

function attackClassStartsComment(bool $quoted, string $character): bool
{
    return !$quoted && in_array($character, [';', '#'], strict: true);
}

function attackClassDecodeConfigValue(string $value): string
{
    $decoded = '';
    $quoted = false;
    $length = strlen($value);

    for ($position = 0; $position < $length; $position++) {
        $character = $value[$position];

        if ($character === '\\') {
            $position++;
            $decoded .= attackClassDecodeEscape($value[$position] ?? '');

            continue;
        }

        if ($character === '"') {
            $quoted = !$quoted;

            continue;
        }

        if (attackClassStartsComment($quoted, $character)) {
            break;
        }

        $decoded .= $character;
    }

    return $decoded;
}

function attackClassSectionLineRuns(string $section, string $line, int $depth): bool
{
    $found = preg_match('/^\s*([\w.-]++)\s*+(?:=(.*))?$/s', $line, $parts);

    if ($found === false) {
        attackClassPatternFailed();
    }

    $key = strtolower($parts[1] ?? '');
    $value = $parts[2] ?? '';

    return match (true) {
        $found === 0 => false,
        $section === 'alias' && isset($parts[2]) => attackClassAliasRuns(attackClassDecodeConfigValue($value), $depth),
        $section === 'help' && $key === 'autocorrect' => attackClassAutocorrectRuns(attackClassReplace('/["\']/', '', $value)),
        default => false,
    };
}

function attackClassSectionRuns(string $text, int $depth): bool
{
    $section = '';

    foreach (explode("\n", $text) as $line) {
        if (attackClassMatches('/^\s*\[/', $line)) {
            $section = strtolower(attackClassReplace('/^\s*\[\s*([^\]\s."]*)[^\]]*\].*$/s', '$1', $line));
            $line = attackClassReplace('/^\s*\[[^\]]*\]/', '', $line);
        }

        if (attackClassSectionLineRuns($section, $line, $depth)) {
            return true;
        }
    }

    return false;
}

function attackClassTextNamesGitStash(string $text, int $depth): bool
{
    $words = attackClassShellWords($text);

    if (attackClassWordsNameGitStash($words) || attackClassWordsSetRunningConfig($words, $depth) || attackClassSectionRuns($text, $depth)) {
        return true;
    }

    foreach ($words as $word) {
        if (attackClassMatches('/\s/', $word) && attackClassNamesGitStash($word, $depth + 1)) {
            return true;
        }
    }

    return false;
}

/**
 * @return list<string>
 */
function attackClassCodeSpans(string $text): array
{
    $found = preg_match_all('/`([^`\n]++)`/', $text, $matches);

    if ($found === false) {
        attackClassPatternFailed();
    }

    return array_values($matches[1]);
}

function attackClassNamesGitStash(string $content, int $depth = 0): bool
{
    if ($depth > 8) {
        throw new RuntimeException('The git stash detector stopped at a text that nests quotes more than eight levels deep.');
    }

    $joined = attackClassReplace('/\\\\\r?\n/', '', $content);

    if (attackClassTextNamesGitStash($joined, $depth)) {
        return true;
    }

    // A quote that an earlier part of a line leaves open must not change how a later line or an inline code span is read.
    $parts = [...(str_contains($joined, "\n") ? explode("\n", $joined) : []), ...attackClassCodeSpans($joined)];

    foreach ($parts as $part) {
        if (attackClassTextNamesGitStash($part, $depth)) {
            return true;
        }
    }

    return false;
}

/**
 * @return array<string, string>
 */
function attackClassAgentInstructionFiles(): array
{
    $isInstruction = static fn (string $path): bool => preg_match('#^(agents|skills|rules|codex)/#', $path) === 1;

    return array_filter(packageTextFiles(), $isInstruction, ARRAY_FILTER_USE_KEY);
}

test('the owner reference makes the fix round attack the whole class before the push (issue #608)', function (): void {
    $section = attackClassSection(
        attackClassFile('skills/process-code-review/references/close-the-defect-class.md'),
        '## Attack the fix before the push',
    );

    expect($section)
        ->toContain('- A security finding always gets it.')
        ->toContain('- Any other finding gets it when its class has more than one form:')
        ->toContain('- A finding whose class has one form skips it. The handoff names that reason.')
        ->toContain('1. **Write a handful of attacks.** Each attack is one short case against the class, not only against the reported case.')
        ->toContain('Keep the set small and fast, so it runs locally before the push.')
        ->toContain('2. **Try to get around the fix itself.** Write the same input in another form:')
        ->toContain('3. **Make each attack fail on the code before the fix.**')
        ->toContain('An attack that passes on the code before the fix reproduces nothing: rewrite it until it fails, or drop it.')
        ->toContain('4. **Make each attack pass on the fixed code.** An attack that still fails got through the fix.')
        ->toContain('Change the fix before the push, then run every attack again.')
        ->toContain('5. **Keep every attack as a regression test.**');
});

test('the pre-fix attack run swaps the fix out of its own working tree only (issue #608)', function (): void {
    $section = attackClassSection(
        attackClassFile('skills/process-code-review/references/close-the-defect-class.md'),
        '## Attack the fix before the push',
    );

    expect(strtolower($section))->not->toContain('stash');
    expect($section)->not->toContain('<worktree>/');
    expect($section)->not->toContain('system temporary directory');
    expect($section)->not->toContain('`mkdir -p <');
    expect(substr_count($section, 'mktemp -d'))->toBe(substr_count($section, '`mktemp -d <top>/.claude/run/attack/XXXXXXXX`'));
    expect($section)
        ->toContain('needs no swap.')
        ->toContain('Run the swap before you commit the fix, while `HEAD` still holds the code before the fix')
        ->toContain('never touch a ref or a directory that another worktree or session reads or writes.')
        ->toContain('   1. Create a fresh directory for this swap, and never reuse one that an earlier swap or another unit created.')
        ->toContain('`@rules/code-review/review-process.md` *No symbolic link may move the scratch directory*')
        ->toContain('`<top>` is the `pwd -P` of your own worktree\'s toplevel.')
        ->toContain('Create each missing part of `<top>/.claude/run/attack` with `mkdir`, never with `mkdir -p`.')
        ->toContain('Require that `pwd -P` in it prints the same path composed from `<top>`, then run')
        ->toContain('then run `mktemp -d <top>/.claude/run/attack/XXXXXXXX`')
        ->toContain('When a check fails, stop `Blocked`, write nothing, and name the refused path.')
        ->toContain('Run the `pwd -P` check on the swap directory again,')
        ->toContain('name the swap path in the handoff, and keep the worktree:')
        ->toContain('Copy every fixed source file, tracked and new, into that directory, each at its repository-relative path,')
        ->toContain('then copy every file back from the swap directory only.')
        ->toContain('When the round-trip check fails, stop `Blocked` and commit nothing,')
        ->toContain('Delete the swap directory after the check passes.')
        ->toContain('`git restore --source=HEAD --worktree -- <the fixed tracked files>`')
        ->toContain('move the new files out of the working tree into the swap directory.')
        ->toContain('Confirm that the `git diff` of the fixed tracked files equals the saved diff and that each new file matches its copy.');
});

test('every attack run takes the machine lock (issue #608)', function (): void {
    $section = attackClassSection(
        attackClassFile('skills/process-code-review/references/close-the-defect-class.md'),
        '## Attack the fix before the push',
    );

    expect($section)->toContain(
        'Run every attack, in this step and in step 4, with the project\'s test command '
            . 'through `commands/_shared/run-gate.sh --exclusive --command "<command>"`',
    );
});

test('the attack step runs between the class test and the push (issue #608)', function (): void {
    $reference = attackClassFile('skills/process-code-review/references/close-the-defect-class.md');
    $classTest = (int) strpos($reference, '4. **Prove the class closed with one test over its variants.**');
    $attack = (int) strpos($reference, '5. **Attack the fix.** Run the short red tests of *Attack the fix before the push* below');
    $push = (int) strpos($reference, '6. **Push only then.**');

    expect($classTest)->toBeGreaterThan(0)
        ->and($attack)->toBeGreaterThan($classTest)
        ->and($push)->toBeGreaterThan($attack);
});

test('the attacks never replace the review (issue #608)', function (): void {
    $limits = attackClassSection(attackClassFile('skills/process-code-review/references/close-the-defect-class.md'), '## Limits');

    expect($limits)->toContain('- **The attacks never replace the review.** The next review round still checks the fix on its own.');
});

test('the fix handoff lists every attack tried with its result before and after the fix (issue #608)', function (): void {
    $handoff = attackClassSection(attackClassFile('skills/process-code-review/references/close-the-defect-class.md'), '## The handoff');

    expect($handoff)
        ->toContain('the handoff also lists the attacks tried.')
        ->toContain('the case, its result on the code before the fix (fails), and its result on the fixed code (passes).')
        ->toContain('every attack that got through the first version of the fix, and every attack that was dropped.')
        ->toContain('A covered finding without that list is reported as not closed too.');

    expect(attackClassFile('agents/donatello.md'))
        ->toContain('Before the push, attack each fix with the short red tests of its section *Attack the fix before the push*')
        ->toContain('Hand back the class, the instances, the test, and the attacks tried of each finding.')
        ->toContain('- **Attacks tried:** in a fix round, the attacks of each finding with their result before and after the fix,');
});

test('the attack section assumes no project-specific tool (issue #608)', function (string $tool): void {
    $section = attackClassSection(
        attackClassFile('skills/process-code-review/references/close-the-defect-class.md'),
        '## Attack the fix before the push',
    );

    expect(strtolower($section))->not->toContain($tool);
})->with(['vendor/bin', 'pest', 'phpunit', 'composer', 'artisan', 'npm', 'pytest', 'jest']);

test('no other file restates the attack steps (issue #608)', function (string $relativePath): void {
    expect(attackClassFile($relativePath))->not->toContain('Try to get around the fix itself.');
})->with([
    'skills/process-code-review/SKILL.md',
    'agents/donatello.md',
    'commands/_shared/krang-workflow.inc',
    'agents/leonardo.md',
]);

test('Claude Code and Codex install the attack step and its handoff field (issue #608)', function (): void {
    $root = installerCreateProjectRoot();
    $cwd = getcwd();
    $originalCwd = $cwd !== false ? $cwd : '';

    try {
        chdir($root);
        ob_start();
        $exitCode = Installer::run(['ai-olympus', 'install', '--force']);
        ob_end_clean();

        expect($exitCode)->toBe(0);

        $markers = [
            '.agents/skills/process-code-review/references/close-the-defect-class.md' => '## Attack the fix before the push',
            '.claude/agents/donatello.md' => '- **Attacks tried:** in a fix round,',
            '.claude/skills/process-code-review/references/close-the-defect-class.md' => '## Attack the fix before the push',
            '.codex/agent-instructions/donatello.md' => '- **Attacks tried:** in a fix round,',
        ];

        foreach ($markers as $path => $marker) {
            expect((string) file_get_contents($root . '/' . $path))->toContain($marker);
        }
    } finally {
        if ($originalCwd !== '') {
            chdir($originalCwd);
        }

        installerRemoveDirectory($root);
    }
});

test('the git stash detector catches every spelling of the command (issue #608)', function (string $instruction): void {
    expect(attackClassNamesGitStash($instruction))->toBeTrue();
})->with([
    'alias in a configuration section' => ["[alias]\n\ts = stash"],
    'alias in a configuration section with a quoted value' => ["[core]\n\tbare = false\n[alias]\n\ts = \"stash\"\n"],
    'alias in a quoted option' => ['git -c "alias.s=stash" s'],
    'alias key in double quotes' => ['git config "alias.s" stash'],
    'alias key in single quotes' => ['git config \'alias.s\' stash'],
    'alias key on the section line' => ['[alias] s = stash'],
    'alias key quoted in a command-line option' => ['git -c "alias.s"=stash s'],
    'alias key with an escaped dot' => ['git config alias\.s stash'],
    'alias line with a leading option in a section' => ["[alias]\n\ts = -p stash"],
    'alias line with escaped quotes in a section' => ["[alias]\n\ts = st\\\"a\\\"sh"],
    'alias section value in double quotes with a leading option' => ["[alias]\n\ts = \"-p stash\""],
    'alias section value in double quotes with a long option' => ["[alias]\n\ts = \"--paginate stash\""],
    'alias section value with a comment after quotes' => ["[alias]\n\ts = \"-p stash\" ; c"],
    'alias section value with an escaped newline after an option' => ["[alias]\n\ts = -p\\nstash"],
    'alias section value with an escaped newline after the command' => ["[alias]\n\ts = stash\\n"],
    'alias section value with an escaped tab after an option' => ["[alias]\n\ts = -p\\tstash"],
    'alias section value with an escaped tab after the command' => ["[alias]\n\ts = stash\\t"],
    'alias section value with a quoted option on the section line' => ['[alias] s = "-p stash"'],
    'alias section value with quotes around a blank' => ["[alias]\n\ts = -p\" \"stash"],
    'alias set in a named file' => ['git config --file .git/config alias.s stash'],
    'alias set on the command line to a quoted shell command' => ['git -c alias.s="!git stash" s'],
    'alias set on the command line to a shell command' => ['git -c \'alias.s=!git stash\' s'],
    'alias set with the set subcommand' => ['git config set alias.s stash'],
    'alias subsection header with the command on the header line' => ['[alias.s] command = -p stash'],
    'alias subsection header with the command on the next line' => ["[alias.s]\n\tcommand = stash"],
    'alias that points at the command' => ['git -c alias.s=stash s push'],
    'alias to a partly quoted command' => ['git -c "alias.s=!git sta\\"\\"sh" s'],
    'alias to a path-qualified git' => ['git -c alias.s=\'!/usr/bin/git stash\' s'],
    'alias value in double quotes inside single quotes' => ['git -c \'alias.s="stash"\' s'],
    'alias value with a leading option' => ['git -c \'alias.s=-p stash\' s'],
    'alias value with an escaped letter' => ['git -c \'alias.s=st\\ash\' s'],
    'alias value with a quoted letter' => ['git -c \'alias.s=st"a"sh\' s'],
    'alias value with empty quotes' => ['git -c "alias.s=sta\'\'sh" s'],
    'alias value with quotes inside' => ["[alias]\n\ts = st\"a\"sh"],
    'alias with a blank in a subsection name' => ['git -c \'alias.s s.command=stash\' \'s s\''],
    'alias with a colon in a subsection name' => ['git -c alias.s:s.command=stash s:s'],
    'alias with an equals sign in an unquoted name' => ['git config alias.a=b.command stash'],
    'alias with an equals sign in an unquoted subsection name' => ['git config --global alias.s=s.command stash'],
    'alias with an equals sign in a quoted name set with the set subcommand' => ['git config set \'alias.a=b.command\' stash'],
    'alias with an equals sign in a quoted name written to the configuration' => ['git config --global \'alias.a=b.command\' stash'],
    'alias with an equals sign in a subsection name' => ['git config --global \'alias.s=s.command\' stash'],
    'alias with an equals sign in a subsection name and a named file' => ['git config --file f \'alias.a=b.command\' stash'],
    'alias with an equals sign in a subsection name set with the set subcommand' => ['git config set alias.s=s.command stash'],
    'alias with a non-ASCII subsection name' => ['git -c alias.é.command=stash é'],
    'alias with a quoted value' => ['git config alias.sv "stash push"'],
    'alias with a shell command' => ['git config alias.s \'!git stash\''],
    'alias with a slash in a subsection name' => ['git -c alias.s/s.command=stash s/s'],
    'alias with a subsection name set with the set subcommand' => ['git config set alias.s:s.command stash'],
    'alias with a subsection name written to the configuration' => ['git config --global alias.s:s.command stash'],
    'alias with blanks around the equals sign' => ['alias.sv = stash'],
    'alias written to the configuration' => ['git config --global alias.sv stash'],
    'another worktree' => ['git -C "$worktree" stash push'],
    'ansi-c braced hex escape' => ['git $\'sta\\x{73}h\' push'],
    'ansi-c long unicode escape' => ['git $\'sta\\U00000073h\' push'],
    'ansi-c quoted command' => ['git $\'stash\' push'],
    'ansi-c quoted command with a hex escape' => ['git $\'sta\\x73h\' push'],
    'automatic correction bare key in a section' => ["[help]\n\tautocorrect"],
    'automatic correction bare key on the section line' => ['[help] autocorrect'],
    'automatic correction in a configuration section' => ["[help]\n\tautocorrect = immediate"],
    'automatic correction in an upper case section' => ["It's here.\n[Help]\n\tautocorrect = IMMEDIATE"],
    'automatic correction key with no value' => ['git -c help.autocorrect verison'],
    'automatic correction leading zero in a section' => ["[help]\n\tautocorrect = 01"],
    'automatic correction negative in a section' => ["[help]\n\tautocorrect = -1"],
    'automatic correction set in the configuration' => ['git config help.autocorrect immediate'],
    'automatic correction switched on with one' => ['git config help.autocorrect 1'],
    'automatic correction that runs at once' => ['git -c help.autocorrect=immediate stahs push'],
    'automatic correction with a delay' => ['git config help.autocorrect 20'],
    'automatic correction with a hex number' => ['git -c help.autocorrect=0x1 stahs'],
    'automatic correction with a leading zero' => ['git -c help.autocorrect=01 stahs'],
    'automatic correction with a negative delay' => ['git -c help.autocorrect=-5 stahs'],
    'automatic correction with a negative number' => ['git -c help.autocorrect=-1 stahs'],
    'automatic correction with a plus sign' => ['git -c help.autocorrect=+1 stahs'],
    'automatic correction with a unit suffix' => ['git config help.autocorrect 1k'],
    'backslash continuation after a long option with a value' => ["git --git-dir /srv/repo \\\n  stash push"],
    'backslash continuation after a path option' => ["git -C \"my dir\" \\\n  stash pop"],
    'backslash continuation after a short option' => ["git -P \\\n  stash pop"],
    'backslash continuation after git' => ["git \\\nstash push"],
    'backslash continuation between global options' => ["git \\\n  --no-pager \\\n  -C a \\\n  stash pop"],
    'backslash continuation inside git' => ["gi\\\nt stash push"],
    'backslash continuation inside stash' => ["git sta\\\nsh push"],
    'backslash continuation inside stash with carriage returns' => ["git sta\\\r\nsh push"],
    'backslash continuation with carriage returns' => ["git \\\r\n  stash push"],
    'bare command in a code span' => ['take the fix out with `git stash`'],
    'carriage returns after global options' => ["git -C a \\\r\n  -P \\\r\n  stash pop"],
    'code span in a sentence after an apostrophe' => ['Don\'t skip it, run `git sta\'\'sh push` first.'],
    'command inside a double-quoted shell string' => ['bash -c "git stash push"'],
    'command inside a single-quoted shell string' => ['sh -c \'git stash\''],
    'command with an option value that has a separate argument' => ['git --shallow-file x stash push'],
    'configured alias value in quotes' => ['git config alias.s \'"stash"\''],
    'configured alias value with a leading option' => ['git config alias.s \'-p stash\''],
    'configured alias value with a quoted letter' => ['git config alias.s \'st"a"sh\''],
    'continuation inside a fenced command' => ["```bash\ngit \\\n  stash push -- src/Fix.php\n```"],
    'double-quoted command' => ['git "stash" push'],
    'double space' => ['git  stash push'],
    'empty quotes in a code span after an apostrophe' => ['Don\'t forget: `git sta\'\'sh push`'],
    'escaped blank in a path' => ['git -C my\\ dir stash'],
    'escaped letter' => ['git st\\ash push'],
    'escaped option name' => ['git \\-P stash'],
    'git dir with a blank' => ['git --git-dir /srv/repo stash push'],
    'line break after git' => ["git\nstash push"],
    'locale quoted command' => ['git $"stash" push'],
    'mixed case quoted command' => ['GiT $\'StAsH\' push'],
    'no pager' => ['git --no-pager stash pop'],
    'partly quoted command' => ['git s"ta"sh push'],
    'partly quoted command after an apostrophe' => ["Don't do it.\n```bash\ngit sta''sh push\n```"],
    'path-qualified git' => ['/usr/bin/git stash push'],
    'path-qualified git in a code span' => ['run `/usr/bin/git stash push` first'],
    'path-qualified git with a global option' => ['/usr/bin/git -C x stash pop'],
    'path value that joins a quote and a word' => ['git -C x"y z" stash pop'],
    'path value with a quoted part' => ['git -C "my dir"/sub stash pop'],
    'pop' => ['then git stash pop'],
    'push of a path' => ['run `git stash push -- src/Fix.php` first'],
    'quoted automatic correction key' => ['git -c "help.autocorrect"=immediate x'],
    'quoted git' => ['"git" stash push'],
    'quoted letter in a code span after an apostrophe' => ['The branch\'s tip: `git s\'t\'ash push`'],
    'quoted letter in a code span after a short apostrophe' => ['It\'s here: `git s\'t\'ash push`'],
    'quoted option name' => ['git "-C" a stash'],
    'quoted option with an equals sign' => ['git "--git-dir=x" stash'],
    'quoted path-qualified git' => ['"/usr/bin/git" stash push'],
    'quoted path with a blank' => ['git -C "my dir" stash pop'],
    'quoted upper case command' => ['GIT "STASH" push'],
    'quote joined to a word by a continuation' => ["git 'st'\\\na\"sh\" push"],
    'quote left open by a comment' => ["# it's fine\ngit sta''sh push"],
    'quote pair left open before a quoted letter' => ["# \"\ngit \"st\"ash push"],
    'quotes left open by a comment' => ["# say \"hi\ngit sta\"\"sh push"],
    'quote split by a continuation' => ["git \"sta\\\nsh\" push"],
    'relative path to git' => ['bin/git stash'],
    'shell string nested in a shell string' => ['bash -c "sh -c \'git stash\'"'],
    'short pager option' => ['git -P stash pop'],
    'single-quoted command' => ['git \'stash\' push'],
    'single-quoted path with a blank' => ['git -C \'my dir\' stash pop'],
    'single quoted letter after an apostrophe' => ["It's here.\ngit s't'ash push"],
    'tab between the words' => ["git\tstash push"],
    'twenty-five options and the command on a later line' => ['git ' . str_repeat('--x ', 25) . "\nrun git \\\n  stash push"],
    'twenty-five options before a continued command' => ['git ' . str_repeat('--x ', 25) . "\\\n  stash push"],
    'twenty-five options before the command' => ['git ' . str_repeat('--x ', 25) . 'stash push'],
    'twenty-five path options before the command' => ['git ' . str_repeat('-C a ', 25) . 'stash pop'],
    'two paths' => ['git -C a -C b stash pop'],
    'upper case' => ['GIT STASH APPLY'],
    'work tree with an equals sign' => ['git --work-tree=. stash push'],
]);

test('the git stash detector ignores text that only mentions the word, on one line or several (issue #623)', function (string $instruction): void {
    expect(attackClassNamesGitStash($instruction))->toBeFalse();
})->with([
    'a code span with another command' => ['Don\'t forget: `git sta\'\'tus`'],
    'a command separator between the words' => ['git; stash push'],
    'a commit message' => ['git commit -m "never stash"'],
    'a commit message after a continuation' => ["git commit \\\n  -m stash"],
    'a commit message in single quotes' => ['git commit -m \'never stash\''],
    'a different word with the same prefix' => ["git \\\n  stashed"],
    'a file name' => ['git add stash.txt'],
    'a file name after a continuation' => ["git add \\\n  stash.md"],
    'a file name in quotes' => ['git add "stash.txt"'],
    'alias line with a leading option and another command in a section' => ["[alias]\n\ts = -p status"],
    'alias section value with escaped quotes around a word with a blank' => ["[alias]\n\tzz = \\\"-p stash\\\""],
    'alias value with a leading option and another command' => ['git -c \'alias.s=-p status\' s'],
    'a long list of path options with no command' => ['git ' . str_repeat('-C ', 25) . 'status'],
    'a long option list with no command' => ['git ' . str_repeat('--x ', 25) . 'status'],
    'a message with an apostrophe' => ['git commit -m "don\'t stash"'],
    'an alias of another command' => ['git config alias.co checkout'],
    'an alias of another command on the command line' => ['git -c alias.s=status s'],
    'an alias with an equals sign in a name set on the command line' => ['git -c alias.a=b.command=version a=b'],
    'an and-list between the words' => ['git && stash push'],
    'another command before the word' => ["git status \\\n  stash"],
    'another help setting' => ['git config help.format web'],
    'another help setting in a configuration section' => ["[help]\n\tformat = web"],
    'a path that contains the word' => ['git -C stash-dir status'],
    'a path that only contains git' => ['/opt/git/bin/tool stash'],
    'a path whose last segment only starts with git' => ['/usr/bin/gitk stash'],
    'a section that is not an alias section' => ["[alias]\n\ts = status\n[core]\n\tpager = stash"],
    'automatic correction that asks first' => ['git config help.autocorrect prompt'],
    'automatic correction that is a double zero' => ['git config help.autocorrect 00'],
    'automatic correction that is false' => ['git -c help.autocorrect=false x'],
    'automatic correction that is off' => ['git config help.autocorrect 0'],
    'automatic correction that is off in a section' => ["[help]\n\tautocorrect = no"],
    'automatic correction that never runs' => ['git config help.autocorrect never'],
    'automatic correction that never runs on the command line' => ['git -c help.autocorrect=never x'],
    'automatic correction that only shows' => ['git config help.autocorrect show'],
    'a word that continuation joins to git' => ["git\\\nstash push"],
    'the word on its own line' => ["git log\nstash"],
]);

test('the git stash detector fails closed when the pattern cannot read the text (issue #623)', function (): void {
    $limit = (string) ini_get('pcre.backtrack_limit');
    $jit = (string) ini_get('pcre.jit');
    ini_set('pcre.jit', '0');
    ini_set('pcre.backtrack_limit', '1');

    try {
        expect(static fn (): bool => attackClassNamesGitStash(str_repeat('git commit -m message ', 50) . 'git stash push'))
            ->toThrow(RuntimeException::class);
    } finally {
        ini_set('pcre.backtrack_limit', $limit);
        ini_set('pcre.jit', $jit);
    }
});

test('the git stash detector ignores a prohibition that names no git command (issue #608)', function (): void {
    expect(attackClassNamesGitStash('Never stash, reset, or discard uncommitted changes.'))->toBeFalse();
});

test('no agent instruction uses the git stash stack that every worktree shares (issue #608)', function (): void {
    $explainsWhyStashIsForbidden = ['rules/compound-engineering/concurrency.md'];
    $offenders = array_keys(array_filter(
        array_diff_key(attackClassAgentInstructionFiles(), array_flip($explainsWhyStashIsForbidden)),
        attackClassNamesGitStash(...),
    ));

    expect($offenders)->toBe([]);
});
