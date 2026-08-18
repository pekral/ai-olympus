<?php

declare(strict_types = 1);

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
 * The rules issue #187 renamed from `.mdc` to `.md` so Claude Code loads them at all. Kept
 * separate from `ruleExtensionAlwaysOnFiles()`: scoping a rule (issue #274) takes it off the
 * always-on list, but it never revives the rule's retired `.mdc` path — a `.mdc` file is still
 * one Claude Code never reads, and a `@rules/….mdc` reference still points a reader at nothing.
 *
 * @return array<int, string>
 */
function ruleExtensionRenamedFromMdcFiles(): array
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
 * The references to a rule path issue #187 retired, found in the given file map, reported as
 * `<file> → <retired path>`. `CHANGELOG.md` and the project memory are append-only records of
 * what happened, so rewriting a path inside a dated entry would falsify the record — they are
 * excluded by design and the rename is explained in the changelog entry instead.
 *
 * @param array<string, string> $textFiles
 * @return array<int, string>
 */
function ruleExtensionStaleMdcReferences(array $textFiles): array
{
    $historicalRecord = ['CHANGELOG.md', 'docs/memory/PROJECT_MEMORY.md'];
    $retiredPaths = array_map(
        static fn (string $rule): string => substr($rule, 0, -3) . '.mdc',
        ruleExtensionRenamedFromMdcFiles(),
    );

    $stale = [];

    foreach ($textFiles as $relativePath => $contents) {
        if (in_array($relativePath, $historicalRecord, strict: true)) {
            continue;
        }

        foreach ($retiredPaths as $retired) {
            if (str_contains($contents, $retired)) {
                $stale[] = $relativePath . ' → ' . $retired;
            }
        }
    }

    return $stale;
}

/**
 * The rules whose author intent is "load into every session" (issue #187). Listed literally
 * rather than derived from the frontmatter, because the frontmatter key that used to carry that
 * intent is exactly what the fix removed — deriving the expectation from the file would make the
 * test agree with whatever the file happens to say.
 *
 * Issue #274 took four rules off this list by scoping them (see `ruleScopingExpectedGlobs()`).
 * The three that remain are always-on because they govern something every run produces rather
 * than a file type a run may or may not touch: `compound-engineering` (principles only, since
 * issue #275 split its dispatch-time orchestration mechanics into a scoped `orchestration.md`
 * sibling — see `ruleScopingExpectedGlobs()`) governs the memory/tracker contract every run
 * follows, `git` governs every commit and pull request, and `writing` governs every sentence an
 * agent writes.
 *
 * @return array<int, string>
 */
function ruleExtensionAlwaysOnFiles(): array
{
    return [
        'rules/compound-engineering/general.md',
        'rules/git/general.md',
        'rules/writing/general.md',
    ];
}

/**
 * The `paths:` globs each rule scoped in issue #274 (and, for the compound-engineering split,
 * issue #275) must declare, written out by hand for the same reason `ruleExtensionAlwaysOnFiles()`
 * is: an expectation read out of the file it checks agrees with any edit made to that file,
 * including a typo.
 *
 * @return array<string, array<int, string>>
 */
function ruleScopingExpectedGlobs(): array
{
    return [
        'rules/api/general.md' => [
            'routes/**/*.php',
            'app/Http/**/*.php',
            'src/**/Http/**/*.php',
            'packages/**/Http/**/*.php',
            'Modules/**/Http/**/*.php',
        ],
        'rules/compound-engineering/orchestration.md' => ['.claude/run/**'],
        'rules/laravel/laravel.md' => ['**/*.php'],
        'rules/php/core-standards.md' => ['**/*.php'],
        'rules/sql/optimalize.md' => [
            '**/database/migrations/**/*.php',
            'database/migrations/**/*.php',
            '**/*Repository.php',
            '**/*ModelManager.php',
            '**/*.sql',
        ],
    ];
}

/**
 * The globs a rule declares under `paths:`, in declaration order, or an empty list when the rule
 * carries no `paths:` key and is therefore always-on.
 *
 * @return array<int, string>
 */
function ruleScopingGlobs(string $path): array
{
    $globs = [];
    $inPathsList = false;

    foreach (explode("\n", ruleExtensionFrontmatter($path)) as $line) {
        if ($line === 'paths:') {
            $inPathsList = true;

            continue;
        }

        if (!$inPathsList) {
            continue;
        }

        if (preg_match('#^  - "(.+)"$#', $line, $matches) !== 1) {
            break;
        }

        $globs[] = $matches[1];
    }

    return $globs;
}

/**
 * Whether a `paths:` glob matches at least one of the given repository-relative paths. A double
 * asterisk followed by a separator spans any number of directories, including none; a single
 * asterisk stops at a separator; a question mark matches one character that is not a separator.
 *
 * These are the semantics this matcher assumes, not ones the vendor states: Claude Code's memory
 * documentation (https://code.claude.com/docs/en/memory, *Path-specific rules*) shows a pattern
 * table, brace expansion and bracket expressions, and never says whether a leading double-asterisk
 * segment may match zero directories. A glob that would depend on that therefore ships next to a root-anchored twin,
 * so the rule still loads if the assumption turns out to be wrong.
 *
 * @param array<int, string> $candidatePaths
 */
function ruleScopingGlobMatchesAny(string $glob, array $candidatePaths): bool
{
    $pattern = '#^' . strtr(preg_quote($glob, '#'), [
        '\*' => '[^/]*',
        '\*\*' => '.*',
        '\*\*/' => '(?:[^/]+/)*',
        '\?' => '[^/]',
    ]) . '$#';

    foreach ($candidatePaths as $candidatePath) {
        if (preg_match($pattern, $candidatePath) === 1) {
            return true;
        }
    }

    return false;
}

/**
 * Representative file paths of a consuming Laravel project, written independently of the globs
 * they are matched against. This package ships instructions, so it holds no `app/`, no
 * migrations and no `.sql` file of its own — without this corpus a glob aimed at a consumer
 * project could match nothing anywhere and the rule would behave as if it had been deleted.
 * `.claude/run/**` is `.gitignore`d in every consuming project (per this package's own
 * `agents/daidalos.md` *Shared task brief*), so it never appears in `packageTextFiles()` either
 * — its representative path lives here for the same reason the rest of this corpus does.
 *
 * @return array<int, string>
 */
function ruleScopingConsumerProjectPaths(): array
{
    return [
        'routes/api.php',
        'routes/web.php',
        'app/Http/Controllers/OrderController.php',
        'app/Http/Requests/StoreOrderRequest.php',
        'app/Http/Resources/OrderResource.php',
        'app/Http/Middleware/EnsureTokenIsValid.php',
        'src/Billing/Http/Controllers/InvoiceController.php',
        'packages/billing/src/Http/Controllers/RefundController.php',
        'Modules/Shop/Http/Controllers/CartController.php',
        'database/migrations/2026_01_01_000000_create_orders_table.php',
        'database/schema/mysql-schema.sql',
        'app/Repositories/OrderRepository.php',
        'app/Shop/Order/OrderModelManager.php',
        'resources/views/order/show.blade.php',
        'config/database.php',
        '.claude/run/gh-123.md',
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
    // `build/` is gitignored tooling output, and PHPStan's result cache echoes the source of every
    // analysed line back as a PHP string — so an absence guard that walks it matches its own
    // assertion and fails on a name the tree no longer carries (issue #231).
    $skipped = ['vendor', 'node_modules', '.git', '.claude', '.idea', 'build'];
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
