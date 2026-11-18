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

function attackClassAnsiEscape(string $escape): string
{
    $decoded = json_decode('"\u' . str_pad(substr($escape, 1), 4, '0', STR_PAD_LEFT) . '"');

    return match (true) {
        $escape[0] === 'x' => chr((int) hexdec(substr($escape, 1)) & 255),
        ctype_digit($escape[0]) => chr((int) octdec($escape) & 255),
        $escape[0] === 'u' => is_string($decoded) ? $decoded : '',
        default => ['n' => "\n", 't' => "\t", 'r' => "\r"][$escape] ?? $escape,
    };
}

function attackClassAnsiC(string $text): string
{
    $decode = static fn (array $match): string => attackClassAnsiEscape(attackClassGroup($match, 1));

    return preg_replace_callback('/\\\\(x[0-9a-fA-F]{1,2}|[0-7]{1,3}|u[0-9a-fA-F]{1,4}|.)/s', $decode, $text) ?? attackClassPatternFailed();
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

/**
 * @param list<string> $words
 */
function attackClassWordsNameGitStash(array $words): bool
{
    foreach ($words as $start => $word) {
        if (!attackClassMatches('/^\W*git\W*$/i', $word)) {
            continue;
        }

        $next = attackClassAfterGlobalOptions($words, $start + 1);

        if (isset($words[$next]) && attackClassIsStashWord($words[$next])) {
            return true;
        }
    }

    return false;
}

/**
 * @param list<string> $words
 */
function attackClassAliasValueIsStash(array $words, int $position): bool
{
    $value = $words[$position + 1] ?? '';

    return attackClassIsStashWord($value === '=' ? ($words[$position + 2] ?? '') : $value);
}

/**
 * @param list<string> $words
 */
function attackClassWordsNameStashAlias(array $words): bool
{
    foreach ($words as $position => $word) {
        $setsStash = attackClassMatches('/^alias\.[\w.-]++=\s*+stash(?![\w-])/i', $word);
        $keyBeforeValue = attackClassMatches('/^alias\.[\w.-]++=?$/i', $word) && attackClassAliasValueIsStash($words, $position);

        if ($setsStash || $keyBeforeValue) {
            return true;
        }
    }

    return false;
}

function attackClassNamesStashAliasSection(string $text): bool
{
    $inAliasSection = false;

    foreach (explode("\n", $text) as $line) {
        if (attackClassMatches('/^\s*\[/', $line)) {
            $inAliasSection = attackClassMatches('/^\s*\[\s*alias\s*\]/i', $line);

            continue;
        }

        if ($inAliasSection && attackClassMatches('/^\s*[\w.-]++\s*+=\s*+["\']*+stash(?![\w-])/i', $line)) {
            return true;
        }
    }

    return false;
}

function attackClassNamesGitStash(string $content, int $depth = 0): bool
{
    if ($depth > 8) {
        throw new RuntimeException('The git stash detector stopped at a text that nests quotes more than eight levels deep.');
    }

    $joined = attackClassReplace('/\\\\\r?\n/', '', $content);
    $words = attackClassShellWords($joined);

    if (attackClassWordsNameGitStash($words) || attackClassWordsNameStashAlias($words) || attackClassNamesStashAliasSection($joined)) {
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
            . 'through `skills/_shared/run-gate.sh --exclusive --command "<command>"`',
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
    'agents/krang.md',
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
    'alias key quoted in a command-line option' => ['git -c "alias.s"=stash s'],
    'alias key with an escaped dot' => ['git config alias\.s stash'],
    'alias set in a named file' => ['git config --file .git/config alias.s stash'],
    'alias set with the set subcommand' => ['git config set alias.s stash'],
    'alias that points at the command' => ['git -c alias.s=stash s push'],
    'alias with a quoted value' => ['git config alias.sv "stash push"'],
    'alias with a shell command' => ['git config alias.s \'!git stash\''],
    'alias with blanks around the equals sign' => ['alias.sv = stash'],
    'alias written to the configuration' => ['git config --global alias.sv stash'],
    'another worktree' => ['git -C "$worktree" stash push'],
    'ansi-c quoted command' => ['git $\'stash\' push'],
    'ansi-c quoted command with a hex escape' => ['git $\'sta\\x73h\' push'],
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
    'command inside a double-quoted shell string' => ['bash -c "git stash push"'],
    'command inside a single-quoted shell string' => ['sh -c \'git stash\''],
    'command with an option value that has a separate argument' => ['git --shallow-file x stash push'],
    'continuation inside a fenced command' => ["```bash\ngit \\\n  stash push -- src/Fix.php\n```"],
    'double-quoted command' => ['git "stash" push'],
    'double space' => ['git  stash push'],
    'escaped blank in a path' => ['git -C my\\ dir stash'],
    'escaped letter' => ['git st\\ash push'],
    'escaped option name' => ['git \\-P stash'],
    'git dir with a blank' => ['git --git-dir /srv/repo stash push'],
    'line break after git' => ["git\nstash push"],
    'locale quoted command' => ['git $"stash" push'],
    'mixed case quoted command' => ['GiT $\'StAsH\' push'],
    'no pager' => ['git --no-pager stash pop'],
    'partly quoted command' => ['git s"ta"sh push'],
    'path value that joins a quote and a word' => ['git -C x"y z" stash pop'],
    'path value with a quoted part' => ['git -C "my dir"/sub stash pop'],
    'pop' => ['then git stash pop'],
    'push of a path' => ['run `git stash push -- src/Fix.php` first'],
    'quoted git' => ['"git" stash push'],
    'quoted option name' => ['git "-C" a stash'],
    'quoted option with an equals sign' => ['git "--git-dir=x" stash'],
    'quoted path with a blank' => ['git -C "my dir" stash pop'],
    'quoted upper case command' => ['GIT "STASH" push'],
    'quote joined to a word by a continuation' => ["git 'st'\\\na\"sh\" push"],
    'quote split by a continuation' => ["git \"sta\\\nsh\" push"],
    'shell string nested in a shell string' => ['bash -c "sh -c \'git stash\'"'],
    'short pager option' => ['git -P stash pop'],
    'single-quoted command' => ['git \'stash\' push'],
    'single-quoted path with a blank' => ['git -C \'my dir\' stash pop'],
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
    'a command separator between the words' => ['git; stash push'],
    'a commit message' => ['git commit -m "never stash"'],
    'a commit message after a continuation' => ["git commit \\\n  -m stash"],
    'a commit message in single quotes' => ['git commit -m \'never stash\''],
    'a different word with the same prefix' => ["git \\\n  stashed"],
    'a file name' => ['git add stash.txt'],
    'a file name after a continuation' => ["git add \\\n  stash.md"],
    'a file name in quotes' => ['git add "stash.txt"'],
    'a long list of path options with no command' => ['git ' . str_repeat('-C ', 25) . 'status'],
    'a long option list with no command' => ['git ' . str_repeat('--x ', 25) . 'status'],
    'a message with an apostrophe' => ['git commit -m "don\'t stash"'],
    'an alias of another command' => ['git config alias.co checkout'],
    'an alias of another command on the command line' => ['git -c alias.s=status s'],
    'an and-list between the words' => ['git && stash push'],
    'another command before the word' => ["git status \\\n  stash"],
    'a path that contains the word' => ['git -C stash-dir status'],
    'a section that is not an alias section' => ["[alias]\n\ts = status\n[core]\n\tpager = stash"],
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
