<?php

declare(strict_types = 1);

use AgenticVibes\AgentSkills\BashCommandWord;

function installerEnsureDirectory(string $directory): void
{
    if (is_dir($directory)) {
        return;
    }

    mkdir($directory, 0777, recursive: true);
}

function installerCreateProjectRoot(): string
{
    $root = sys_get_temp_dir() . '/agent-skills-' . bin2hex(random_bytes(4));
    installerEnsureDirectory($root);
    file_put_contents($root . '/composer.json', '{}');

    return $root;
}

function installerWriteFile(string $path, string $content): void
{
    $directory = dirname($path);
    installerEnsureDirectory($directory);
    file_put_contents($path, $content);
}

function installerRemoveDirectory(string $directory): void
{
    if (is_file($directory)) {
        unlink($directory);

        return;
    }

    if (!is_dir($directory)) {
        return;
    }

    /** @var \RecursiveIteratorIterator<\RecursiveDirectoryIterator> $iterator */
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $fileInfo) {
        if ($fileInfo instanceof SplFileInfo) {
            installerRemoveFileSystemEntry($fileInfo);
        }
    }

    rmdir($directory);
}

/**
 * A symlink must be checked before isDir() — isDir() follows the link and would route a
 * directory symlink into rmdir(), which POSIX refuses ("Not a directory") since the link itself,
 * not a directory, sits at that path. unlink() removes the link entry only, never whatever it
 * points to (PR #150 CR fix — a test fixture creating a directory symlink surfaced this).
 */
function installerRemoveFileSystemEntry(SplFileInfo $fileInfo): void
{
    if ($fileInfo->isLink() || !$fileInfo->isDir()) {
        unlink($fileInfo->getPathname());

        return;
    }

    rmdir($fileInfo->getPathname());
}

function installerSymlinkUnsupported(): bool
{
    return !function_exists('symlink') || stripos(PHP_OS, 'WIN') === 0;
}

function installerCountFiles(string $dir): int
{
    if (!is_dir($dir)) {
        return 0;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY,
    );
    $count = 0;

    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->isFile()) {
            $count++;
        }
    }

    return $count;
}

/**
 * Builds a Clover XML document from a list of [file path, list of [line, type, count]] tuples.
 *
 * @param array<array{0: string, 1: array<array{0: int, 1: string, 2: int}>}> $files
 */
function coverageDiffCheckBuildClover(array $files): string
{
    $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<coverage><project>";

    foreach ($files as [$path, $lines]) {
        $xml .= sprintf('<file name="%s">', htmlspecialchars($path, ENT_XML1 | ENT_QUOTES));

        foreach ($lines as [$num, $type, $count]) {
            $xml .= sprintf('<line num="%d" type="%s" count="%d"/>', $num, $type, $count);
        }

        $xml .= '</file>';
    }

    return $xml . '</project></coverage>';
}

/**
 * Returns `skill directory => raw front-matter description` for every shipped skill.
 * `_shared/` carries no SKILL.md and is therefore not a skill.
 *
 * @return array<string, string>
 */
function skillFrontMatterDescriptions(): array
{
    $skillsDir = __DIR__ . '/../skills';
    $entries = scandir($skillsDir);
    assert($entries !== false);
    $descriptions = [];

    foreach ($entries as $entry) {
        $file = $skillsDir . '/' . $entry . '/SKILL.md';

        if ($entry === '.' || $entry === '..' || !is_file($file)) {
            continue;
        }

        $source = (string) file_get_contents($file);

        if (preg_match('/^---\n(.*?)\n---\n/s', $source, $frontMatter) !== 1) {
            continue;
        }

        if (preg_match('/^description:\s*(.*?)(?=\n[a-z_]+:|\z)/ms', $frontMatter[1], $match) !== 1) {
            continue;
        }

        $descriptions[$entry] = (string) preg_replace('/\s+/', ' ', trim($match[1]));
    }

    return $descriptions;
}

/**
 * Derives the one-line catalog description from a skill's own front-matter: drop the
 * `Use when` prefix so the column reads as a statement, then keep only the summary that
 * precedes the detail list (an em dash), the alternative trigger (`, or when`), or the
 * end of the first sentence. Nothing is added — the output is always a substring of the
 * declared description, which is what makes "no invented capabilities" checkable.
 */
function skillCatalogDescription(string $frontMatterDescription): string
{
    $line = trim(trim(trim($frontMatterDescription), '"'));
    $line = (string) preg_replace('/^Use when\s+/i', '', $line);
    $line = rtrim(skillCatalogSummary($line), ' .,;:');

    return str_replace('|', '\|', skillCatalogCapitalise($line));
}

/**
 * Keeps only the summary that precedes the detail list, the alternative trigger, or the first sentence end.
 */
function skillCatalogSummary(string $line): string
{
    foreach ([' — ', ', or when '] as $cut) {
        $position = strpos($line, $cut);

        if ($position !== false && $position > 0) {
            $line = substr($line, 0, $position);
        }
    }

    if (preg_match('/\.\s+(?=[A-Z])/', $line, $sentence, PREG_OFFSET_CAPTURE) !== 1) {
        return $line;
    }

    return substr($line, 0, $sentence[0][1]);
}

/**
 * Leaves a leading path or identifier (`docs/memory/PROJECT_MEMORY.md`) in its own casing.
 */
function skillCatalogCapitalise(string $line): string
{
    $firstWord = strtok($line, ' ');

    if ($line === '' || !ctype_alpha($line[0]) || !is_string($firstWord)) {
        return $line;
    }

    return strpbrk($firstWord, '/.-') === false ? ucfirst($line) : $line;
}

/**
 * Returns every test file in this suite as `relative path => source`, so a rule can be
 * enforced against the suite itself rather than only documented.
 *
 * @return array<string, string>
 */
function codeTestingSuiteFiles(): array
{
    // This file lives in the suite root, so __DIR__ is the canonical tests/ path — no
    // `/../` segment that a str_replace prefix strip would then fail to match.
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__, FilesystemIterator::SKIP_DOTS));
    $files = [];

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $files['tests/' . ltrim(substr($file->getPathname(), strlen(__DIR__)), '/')] = (string) file_get_contents($file->getPathname());
    }

    return $files;
}

/**
 * Splits a Pest file into `test name => body`. Top-level `test(` / `it(` calls start at
 * column zero, so the next one is the reliable end of the previous body.
 *
 * @return array<string, string>
 */
function codeTestingTestBlocks(string $source): array
{
    $parts = preg_split('/^(?=(?:test|it)\()/m', $source);

    if ($parts === false) {
        return [];
    }

    $blocks = [];

    foreach ($parts as $part) {
        if (preg_match('/^(?:test|it)\(\s*[\'"](.+?)[\'"]\s*,/s', $part, $match) !== 1) {
            continue;
        }

        $blocks[$match[1]] = $part;
    }

    return $blocks;
}

/**
 * Returns the body of a Markdown section, from its `## Heading` to the next same-level
 * heading or the end of the document, so a section that closes the file still slices.
 */
function installerDocsSection(string $document, string $heading): string
{
    $start = strpos($document, $heading);
    assert($start !== false);

    $end = strpos($document, "\n## ", $start + 1);

    return $end === false ? substr($document, $start) : substr($document, $start, $end - $start);
}

function installerRestoreEnvAndCleanup(string|false $homeBefore, string $originalCwd, string $root): void
{
    if ($homeBefore !== false && $homeBefore !== '') {
        putenv('HOME=' . $homeBefore);
        putenv('USERPROFILE=' . $homeBefore);
    } else {
        putenv('HOME');
        putenv('USERPROFILE');
    }

    if ($originalCwd !== '') {
        chdir($originalCwd);
    }

    installerRemoveDirectory($root);
}

/**
 * The per-agent policy pinned as `[program, subcommands, decision, prose phrase]`, in policy
 * order.
 *
 * It exists because a parity walk over the policy alone can only ever confirm what the policy
 * already says. The pinned shape is what a deleted or relaxed rule fails against; the pinned
 * phrase is the sentence in `agents/<name>.md` that makes the rule true, so an agent file
 * rewritten to permit what the policy denies fails too. Neither document can move without the
 * other.
 *
 * @return array<string, list<array{0: string, 1: list<string>, 2: string, 3: string}>>
 */
function agentBashBoundaryProsePins(): array
{
    return [
        'athena' => agentBashBoundaryReadOnlyPins('never run `git commit` / `git push` / `git merge`', 'deny'),
        // `ask`, not `deny`: the prose permits the merge through one skill only, a condition the
        // command string cannot carry, so the human decides instead of the validator.
        'daidalos' => agentBashBoundaryReadOnlyPins('run read-only `git`', 'ask'),
        'hermes' => agentBashBoundaryReadOnlyPins('You never run any `git` write operation', 'deny'),
        'talos' => [
            ['git', ['push'], 'deny', 'never `git push --force*`'],
            ['git', ['push'], 'deny', 'never on the project\'s default branch'],
            ['gh', ['pr', 'merge'], 'ask', 'You never merge outside `@skills/merge-github-pr/SKILL.md`'],
        ],
    ];
}

/**
 * The three read-only agents share one rule set; they differ in the sentence that forbids the git
 * writes, and in whether their own file permits a merge through one named skill.
 *
 * @return list<array{0: string, 1: list<string>, 2: string, 3: string}>
 */
function agentBashBoundaryReadOnlyPins(string $gitPhrase, string $merge): array
{
    $mergePhrase = $merge === 'ask'
        ? 'run `gh pr merge` **only** via `@skills/merge-github-pr/SKILL.md`'
        : 'never commit, push, or merge';

    return [
        ['git', ['commit'], 'deny', $gitPhrase],
        ['git', ['push'], 'deny', $gitPhrase],
        ['git', ['merge'], 'deny', $gitPhrase],
        ['gh', ['pr', 'merge'], $merge, $mergePhrase],
    ];
}

/**
 * One pinned rule checked against its agent file: the program must be named in the `## Bash
 * boundary` block, and the sentence that forbids the rule must still be somewhere in the file —
 * some agents state it in their role paragraph rather than in the boundary block itself.
 *
 * @return list<string>
 */
function agentBashBoundaryPhraseDrift(string $agent, string $program, string $phrase, string $boundary, string $content): array
{
    $drift = [];

    if (!str_contains($boundary, '`' . $program)) {
        $drift[] = $agent . ': program `' . $program . '` is not named in its Bash boundary block';
    }

    if (!str_contains($content, $phrase)) {
        $drift[] = $agent . ': the phrase "' . $phrase . '" is gone';
    }

    return $drift;
}

/**
 * @param list<\AgenticVibes\AgentSkills\BashCommandWord> $words
 * @return list<string>
 */
function bashCommandWordTexts(array $words): array
{
    return array_map(static fn (BashCommandWord $word): string => $word->text, $words);
}

/**
 * The bidirectional decision corpus for the agent Bash boundary validator, as
 * `[agent_type, command, expected decision]`. It is shared because two tests need the same rows:
 * the one asserting each expected decision, and the property test asserting `allow` is never
 * reachable across all of them.
 *
 * Both directions matter. A row expecting `deny` proves the boundary is enforced; a row expecting
 * `defer` proves it does not fire on a command that merely mentions a denied program, which is the
 * failure mode a substring matcher would have.
 *
 * @return array<array{0: ?string, 1: string, 2: string}>
 */
function agentBashBoundaryCorpus(): array
{
    return [...agentBashBoundaryGlobalRows(), ...agentBashBoundaryFalsePositiveRows(), ...agentBashBoundaryAgentRows()];
}

/**
 * The commands that must be refused for every agent and for the main session alike.
 *
 * @return array<array{0: ?string, 1: string, 2: string}>
 */
function agentBashBoundaryGlobalRows(): array
{
    return [
        [null, 'curl https://example.com', 'deny'],
        // Every redirection metacharacter breaks a word with or without whitespace, so each
        // spelling is pinned in both forms: a glued one that a whitespace-only scanner misses,
        // and the spaced control that has always decided correctly.
        [null, 'curl>/dev/null https://example.com', 'deny'],
        [null, 'curl > /dev/null https://example.com', 'deny'],
        [null, 'wget>/dev/null http://example.com/x', 'deny'],
        [null, 'cat <.env', 'deny'],
        [null, 'cat < .env', 'deny'],
        [null, 'command curl -v https://example.com', 'deny'],
        [null, 'command curl https://example.com', 'deny'],
        [null, '/usr/bin/curl https://example.com', 'deny'],
        [null, './curl https://example.com', 'deny'],
        [null, 'FOO=1 curl https://example.com', 'deny'],
        [null, 'bash -c \'curl https://example.com\'', 'deny'],
        [null, 'env curl https://example.com', 'deny'],
        [null, 'xargs -n 1 curl', 'deny'],
        [null, 'xargs -I {} curl {}', 'deny'],
        [null, 'git status && curl https://example.com', 'deny'],
        [null, 'echo $(curl https://example.com)', 'deny'],
        [null, 'echo `curl https://example.com`', 'deny'],
        [null, 'eval "curl https://example.com"', 'deny'],
        [null, 'exec 3<>/dev/tcp/example.com/80', 'deny'],
        [null, 'wget https://example.com', 'deny'],
        [null, 'socat - TCP:example.com:80', 'deny'],
        [null, 'openssl s_client -connect example.com:443', 'deny'],
        [null, 'sudo rm -rf /tmp/x', 'deny'],
        [null, 'cat ~/.ssh/id_rsa', 'deny'],
        [null, 'cat .env', 'deny'],
        [null, 'cat ~/.claude/.credentials.json', 'deny'],
    ];
}

/**
 * The other direction: commands that merely mention a denied program, or that this validator
 * cannot read confidently. A substring matcher would refuse the first group outright.
 *
 * @return array<array{0: ?string, 1: string, 2: string}>
 */
function agentBashBoundaryFalsePositiveRows(): array
{
    return [
        [null, 'echo "do not curl this"', 'defer'],
        [null, './tests/curly.sh', 'defer'],
        [null, 'git log --grep=curl', 'defer'],
        [null, 'openssl dgst -sha256 composer.json', 'defer'],
        [null, 'grep -rn wget README.md', 'defer'],
        [null, 'command -v curl', 'defer'],
        [null, 'command -pv curl', 'defer'],
        [null, 'composer build', 'defer'],
        // A quoted `>` is a literal, not an operator: reading redirections off finished tokens
        // turns both of these into a refusal to grep for a character.
        [null, 'grep -rn \'>foo\' README.md', 'defer'],
        [null, 'git log --grep ">>>"', 'defer'],
        [null, 'php -v 2>/dev/null', 'defer'],
        [null, 'echo \'unterminated', 'ask'],
        [null, 'c=curl; $c https://example.com', 'ask'],
        // A process substitution detaches the words that follow it from their program, so the
        // command is reported as unread rather than judged on the fragments that survive.
        ['athena', 'cp <(echo x) src/Foo.php', 'ask'],
        ['athena', 'tee >(cat) src/Foo.php', 'ask'],
        [null, 'cat <(true) .env', 'ask'],
        // Quoted, it is an ordinary word and nothing is detached.
        [null, 'echo \'<(cat)\'', 'defer'],
    ];
}

/**
 * The half that differs per `agent_type`, including the rows proving an agent keeps the commands
 * its own `## Bash boundary` block grants it.
 *
 * @return array<array{0: ?string, 1: string, 2: string}>
 */
function agentBashBoundaryAgentRows(): array
{
    return [
        ...agentBashBoundaryReadOnlyRows(),
        ...agentBashBoundaryWritePathRows(),
        ...agentBashBoundaryFileMutatingRows(),
        ...agentBashBoundaryInPlaceClusterRows(),
        ...agentBashBoundaryDestinationOptionRows(),
        ...agentBashBoundaryAbbreviatedOptionRows(),
        ...agentBashBoundaryUnreadableOptionRows(),
        ...agentBashBoundaryUnreadTargetRows(),
        ...agentBashBoundaryWriterRows(),
    ];
}

/**
 * The `git` / `gh` half of the read-only agents' rules, in the spellings that decide whether the
 * positional window a subcommand is read from can be shifted by a global option.
 *
 * @return array<array{0: ?string, 1: string, 2: string}>
 */
function agentBashBoundaryReadOnlyRows(): array
{
    return [
        ['athena', 'git commit -m "x"', 'deny'],
        ['athena', 'git push origin master', 'deny'],
        ['athena', 'git merge master', 'deny'],
        ['athena', 'gh pr merge 12', 'deny'],
        // A value-taking global option must not shift the positional window — `git -C` is exactly
        // how an agent addresses its own worktree.
        ['athena', 'git -C /repo commit -m x', 'deny'],
        ['athena', 'gh --repo o/r pr merge 12', 'deny'],
        ['athena', 'gh -R o/r pr merge 12', 'deny'],
        ['athena', 'git -c user.name=x commit -m x', 'deny'],
        ['athena', 'git diff HEAD', 'defer'],
        ['athena', 'git diff HEAD 2>&1', 'defer'],
        ['athena', 'git worktree add .claude/worktrees/agent-cr-1-athena', 'defer'],
        ['daidalos', 'gh pr merge 12', 'ask'],
        ['daidalos', 'git commit -m "x"', 'deny'],
        ['hermes', 'gh pr merge 12', 'deny'],
        ['hermes', 'gh --repo o/r pr merge 12 --squash', 'deny'],
    ];
}

/**
 * Redirection in each spelling, and the path shapes the writable-path allow-list must and must not
 * accept.
 *
 * @return array<array{0: ?string, 1: string, 2: string}>
 */
function agentBashBoundaryWritePathRows(): array
{
    return [
        ['athena', 'cat > src/Foo.php', 'deny'],
        ['athena', 'echo pwned>src/Installer.php', 'deny'],
        ['athena', 'echo x >| /etc/hosts', 'deny'],
        ['hermes', 'echo x>>composer.json', 'deny'],
        // The writable-path allow-list is a normalised path prefix, never a substring.
        ['athena', 'echo x > .claude/run/../../../etc/hosts', 'deny'],
        ['athena', 'echo x > /tmp/evil/.claude/run/../../../etc/hosts', 'deny'],
        ['athena', 'echo x > foo.claude/run/bar', 'deny'],
        ['athena', 'echo x > /dev/nullify', 'deny'],
        // The granted fragment names the project's scratch directory, never the one in $HOME.
        ['athena', 'echo x > ~/.claude/run/evil', 'deny'],
        ['athena', 'cat >> .claude/run/gh-1.md', 'defer'],
        ['athena', 'cat >> /Users/x/project/.claude/run/gh-1.md', 'defer'],
        ['athena', 'cat >> .claude/worktrees/agent-cr-1-athena/notes.md', 'defer'],
        ['athena', 'rm -f .claude/run/gh-1.md', 'defer'],
        ['athena', 'ls -la > /dev/null', 'defer'],
        ['athena', 'ls -la 2>/dev/null', 'defer'],
    ];
}

/**
 * Redirection is one write mechanism; a file-mutating program is another, and an in-place editor is
 * only one when the flag that makes it one is present — in every spelling of that flag.
 *
 * @return array<array{0: ?string, 1: string, 2: string}>
 */
function agentBashBoundaryFileMutatingRows(): array
{
    return [
        ['athena', 'echo pwned | tee src/Foo.php', 'deny'],
        ['athena', 'cp /tmp/x src/Foo.php', 'deny'],
        ['athena', 'mv /tmp/x src/Foo.php', 'deny'],
        ['athena', 'rm -rf src', 'deny'],
        ['athena', 'truncate -s 0 composer.json', 'deny'],
        ['athena', 'touch src/Foo.php', 'deny'],
        ['athena', 'ln -sf /etc/passwd src/Foo.php', 'deny'],
        ['athena', 'touch .claude/run/gh-1.md', 'defer'],
        // Reading a tracked file with `sed` / `perl` is not a write.
        ['athena', 'sed -n 1p src/Foo.php', 'defer'],
        ['athena', 'perl -e \'print\' src/Foo.php', 'defer'],
        // Every canonical in-place spelling, not only the separate `-i`: a bundled short-option
        // cluster is how perl is actually invoked, and `--in-place` is the GNU sed long form.
        ['athena', 'sed -i \'\' \'s/a/b/\' src/Foo.php', 'deny'],
        ['athena', 'sed -i.bak \'s/a/b/\' src/Installer.php', 'deny'],
        ['athena', 'sed --in-place \'s/a/b/\' src/Installer.php', 'deny'],
        ['athena', 'sed --in-place=.bak \'s/a/b/\' src/Installer.php', 'deny'],
        ['athena', 'perl -i -pe \'s/a/b/\' src/Installer.php', 'deny'],
        ['athena', 'perl -pi -e \'s/a/b/\' src/Installer.php', 'deny'],
        ['athena', 'perl -pi.bak -e \'s/a/b/\' src/Installer.php', 'deny'],
    ];
}

/**
 * The in-place flag buried in a short-option cluster that carries a digit, which a cluster read as
 * letters-only stops at before it ever reaches the flag.
 *
 * @return array<array{0: ?string, 1: string, 2: string}>
 */
function agentBashBoundaryInPlaceClusterRows(): array
{
    return [
        // `-0777` is the standard whole-file slurp, and `-0777pi` rewrites the file exactly as
        // `-pi` does — as does `-0pi`, its single-digit sibling.
        ['athena', 'perl -0pi -e \'s/a/b/\' src/Installer.php', 'deny'],
        ['athena', 'perl -0777pi -e \'s/a/b/\' src/Installer.php', 'deny'],
        ['hermes', 'perl -0777pi -e \'s/a/b/\' composer.json', 'deny'],
        ['athena', 'perl -0777 -pi -e \'s/a/b/\' src/Installer.php', 'deny'],
        // …while a digit-bearing cluster without the flag is still only a read.
        ['athena', 'perl -0777 -e \'print\' src/Installer.php', 'defer'],
    ];
}

/**
 * The write destination carried inside a destination option instead of by a positional argument,
 * in every spelling — including the separated ones, whose value is positional and which therefore
 * decided correctly before this was covered at all.
 *
 * @return array<array{0: ?string, 1: string, 2: string}>
 */
function agentBashBoundaryDestinationOptionRows(): array
{
    return [
        ['athena', 'cp --target-directory=src .claude/run/x', 'deny'],
        ['athena', 'mv --target-directory=src .claude/run/x', 'deny'],
        ['athena', 'ln --target-directory=src .claude/run/x', 'deny'],
        ['athena', 'install --target-directory=src .claude/run/x', 'deny'],
        ['athena', 'install -D --target-directory=src .claude/run/x', 'deny'],
        ['hermes', 'cp --target-directory=/etc .claude/run/x', 'deny'],
        ['athena', 'cp -tsrc .claude/run/x', 'deny'],
        ['athena', 'cp --target-directory src .claude/run/x', 'deny'],
        ['athena', 'cp -t src .claude/run/x', 'deny'],
        // The option's value is judged against the writable paths, never refused for existing.
        ['athena', 'cp --target-directory=.claude/run .claude/run/x', 'defer'],
        ['athena', 'cp -t.claude/run .claude/run/x', 'defer'],
        // `-t` is a destination only where the program has one — for `touch` it is a timestamp.
        ['athena', 'touch -t202601010000 .claude/run/x', 'defer'],
    ];
}

/**
 * The same destination option under the spellings GNU also accepts for it — an abbreviation of the
 * long name, and the short letter anywhere in a cluster rather than only at its start. Each one was
 * confirmed to write the file against real GNU coreutils, and each one is the *same* option: a
 * validator that matched the literal spellings kept leaking one of them per review round.
 *
 * @return array<array{0: ?string, 1: string, 2: string}>
 */
function agentBashBoundaryAbbreviatedOptionRows(): array
{
    return [
        // GNU accepts any prefix of a long option name, down to a single letter.
        ['athena', 'cp --target=src .claude/run/x', 'deny'],
        ['athena', 'cp --targ=src .claude/run/x', 'deny'],
        ['athena', 'cp --t=src .claude/run/x', 'deny'],
        ['athena', 'cp --target-dir=src .claude/run/x', 'deny'],
        ['athena', 'mv --target=src .claude/run/x', 'deny'],
        ['athena', 'ln --target=src .claude/run/x', 'deny'],
        ['athena', 'install --targ=src .claude/run/x', 'deny'],
        ['hermes', 'mv --target=src .claude/run/x', 'deny'],
        ['daidalos', 'install --t=src .claude/run/x', 'deny'],
        // A short option's value is the rest of its word, wherever in the cluster the option sits.
        ['athena', 'cp -ftsrc .claude/run/x', 'deny'],
        ['athena', 'cp -rtsrc .claude/run/x', 'deny'],
        ['athena', 'cp -avtsrc .claude/run/x', 'deny'],
        ['athena', 'mv -ftsrc .claude/run/x', 'deny'],
        ['athena', 'ln -ftsrc .claude/run/x', 'deny'],
        ['athena', 'install -Dtsrc .claude/run/x', 'deny'],
        ['hermes', 'cp -ftsrc .claude/run/x', 'deny'],
        ['daidalos', 'ln -ftsrc .claude/run/x', 'deny'],
    ];
}

/**
 * The rule the rows above are only instances of: for these four programs an option word the policy
 * cannot read as a harmless one is refused, so a spelling nobody thought of — including one a later
 * coreutils release introduces — fails closed instead of passing through.
 *
 * The `defer` rows are the other half of that claim: the refusal must fall on what cannot be read,
 * never on the ordinary options these agents' own paths still grant them.
 *
 * @return array<array{0: ?string, 1: string, 2: string}>
 */
function agentBashBoundaryUnreadableOptionRows(): array
{
    return [
        ['athena', 'cp --frobnicate=src .claude/run/x', 'deny'],
        ['athena', 'cp -Q .claude/run/x', 'deny'],
        ['athena', 'cp --=src .claude/run/x', 'deny'],
        ['athena', 'mv --nonesuch .claude/run/a .claude/run/b', 'deny'],
        ['daidalos', 'install --strip-directory=src .claude/run/x', 'deny'],
        // A path shaped like an option is still a path: past the `--` terminator, and as the value
        // a separated destination option takes from the word after it.
        ['athena', 'cp -- .claude/run/x -r', 'deny'],
        ['athena', 'cp -t -r .claude/run/x', 'deny'],
        ['hermes', 'mv --target-directory -f .claude/run/x', 'deny'],
        ['athena', 'cp -t .claude/run .claude/run/x', 'defer'],
        ['athena', 'mv -- .claude/run/a .claude/run/b', 'defer'],
        ['athena', 'cp -r .claude/run/a .claude/run/b', 'defer'],
        ['athena', 'cp --recursive --verbose .claude/run/a .claude/run/b', 'defer'],
        // A short option that takes a value of its own ends the cluster, so its value is not a path.
        ['athena', 'cp -S.bak .claude/run/a .claude/run/b', 'defer'],
        ['daidalos', 'install -m755 .claude/run/a .claude/run/b', 'defer'],
        ['athena', 'cp --preserve=mode .claude/run/a .claude/run/b', 'defer'],
        // A program with no such option is read no more strictly than before.
        ['athena', 'rm -rf .claude/run/x', 'defer'],
    ];
}

/**
 * A target the shell has yet to expand is one this validator never read, so it asks — and both
 * spellings are commands these agents' own boundaries grant.
 *
 * The pairs that follow are why the ask must never be an early return: an unread target must not
 * launder a literal violation sharing the same command, in **either** order, or the decision would
 * turn on one token the caller chooses. Both orderings are pinned because either alone stays green.
 *
 * @return array<array{0: ?string, 1: string, 2: string}>
 */
function agentBashBoundaryUnreadTargetRows(): array
{
    return [
        ['athena', 'cat >> "$BRIEF"', 'ask'],
        ['daidalos', 'rm -rf "${BRIEF%.md}.audit"', 'ask'],
        ['athena', 'cat >> "$BRIEF" ; echo pwned > src/Installer.php', 'deny'],
        ['athena', 'echo pwned > src/Installer.php ; cat >> "$BRIEF"', 'deny'],
        ['athena', 'cp "$SRC" .claude/run/a.md && cp /tmp/x src/Installer.php', 'deny'],
        ['athena', 'tee "$X" src/Installer.php', 'deny'],
        ['athena', 'rm "$X" src/Installer.php', 'deny'],
    ];
}

/**
 * The agents that legitimately write files, plus an unknown `agent_type`: the rows proving the
 * per-agent half narrows rather than blocks, and that a writer keeps what its own block grants.
 *
 * @return array<array{0: ?string, 1: string, 2: string}>
 */
function agentBashBoundaryWriterRows(): array
{
    return [
        ['talos', 'git push --force origin feature/x', 'deny'],
        ['talos', 'git push --force-with-lease origin feature/x', 'deny'],
        ['talos', 'git push origin master', 'deny'],
        ['talos', 'git push origin main', 'deny'],
        ['talos', 'git push origin refs/heads/master', 'deny'],
        // A push argument is a refspec: the destination is what gets written, and `+` forces it.
        ['talos', 'git push origin master:master', 'deny'],
        ['talos', 'git push origin main:main', 'deny'],
        ['talos', 'git push origin HEAD:master', 'deny'],
        ['talos', 'git push origin +master', 'deny'],
        ['talos', 'git push origin +refs/heads/main', 'deny'],
        // …and pushing the local default branch somewhere else writes that somewhere else.
        ['talos', 'git push origin master:feature/x', 'defer'],
        ['talos', 'git -C /repo push origin master', 'deny'],
        ['talos', 'curl https://example.com', 'deny'],
        ['talos', 'gh pr merge 12', 'ask'],
        ['talos', 'git push origin feature/x', 'defer'],
        // A branch is matched whole: only `--force*` is a prefix rule.
        ['talos', 'git push origin maintenance/foo', 'defer'],
        ['talos', 'git push origin mainline', 'defer'],
        ['talos', 'git push origin master-backup', 'defer'],
        ['talos', 'rm -rf src', 'defer'],
        ['talos', 'cat > src/Foo.php', 'defer'],
        ['talos', 'composer build', 'defer'],
        ['general-purpose', 'git commit -m "x"', 'defer'],
        ['general-purpose', 'curl https://example.com', 'deny'],
    ];
}

/**
 * The rules whose author intent is "load into every session" (issue #187). Listed literally
 * rather than derived from the frontmatter, because the frontmatter key that used to carry that
 * intent is exactly what the fix removed — deriving the expectation from the file would make the
 * test agree with whatever the file happens to say.
 *
 * @return array<int, string>
 */
function ruleExtensionAlwaysOnFiles(): array
{
    return [
        'rules/api/general.md',
        'rules/compound-engineering/general.md',
        'rules/git/general.md',
        'rules/laravel/laravel.md',
        'rules/php/core-standards.md',
        'rules/sql/optimalize.md',
        'rules/writing/general.md',
    ];
}

/**
 * Returns a rule file's YAML frontmatter, or an empty string when it carries none.
 */
function ruleExtensionFrontmatter(string $path): string
{
    $lines = explode("\n", (string) file_get_contents($path));

    if (($lines[0] ?? null) !== '---') {
        return '';
    }

    $closing = array_search('---', array_slice($lines, 1), strict: true);

    return $closing === false ? '' : implode("\n", array_slice($lines, 1, (int) $closing));
}

/**
 * Every text file the package ships, keyed by its package-relative path. Shared by the content
 * guards that have to prove a pattern appears nowhere in the tree — a retired rule path (issue
 * #187), a forbidden test assertion (issue #181). Walked from disk rather than asked of
 * `git ls-files`, because `@rules/code-testing/general.mdc` forbids a test from spawning a real
 * system process.
 *
 * @return array<string, string>
 */
function packageTextFiles(): array
{
    $packageDir = dirname(__DIR__);
    $skipped = ['vendor', 'node_modules', '.git', '.claude', '.idea'];
    $extensions = ['md', 'mdc', 'php', 'sh', 'json', 'yml', 'yaml'];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($packageDir, FilesystemIterator::SKIP_DOTS),
            static fn (SplFileInfo $file): bool => !in_array($file->getFilename(), $skipped, strict: true),
        ),
    );

    $files = [];

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || !in_array($file->getExtension(), $extensions, strict: true)) {
            continue;
        }

        $files[ltrim(substr($file->getPathname(), strlen($packageDir)), '/')] = (string) file_get_contents($file->getPathname());
    }

    return $files;
}
