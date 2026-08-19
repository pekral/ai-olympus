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
 * The rules renamed from `.mdc` to `.md` so Claude Code loads them at all — issue #187 for the
 * first seven, issue #277 for the rest, which finished the job so `rules/` ships one extension.
 * Kept separate from `ruleExtensionAlwaysOnFiles()`: scoping a rule (issue #274) takes it off the
 * always-on list, but it never revives the rule's retired `.mdc` path — a `.mdc` file is still
 * one Claude Code never reads, and a `@rules/….mdc` reference still points a reader at nothing.
 *
 * @return array<int, string>
 */
function ruleExtensionRenamedFromMdcFiles(): array
{
    return [
        'rules/api/general.md',
        'rules/code-review/general.md',
        'rules/code-testing/general.md',
        'rules/compound-engineering/general.md',
        'rules/git/general.md',
        'rules/jira/general.md',
        'rules/laravel/architecture.md',
        'rules/laravel/dynamodb.md',
        'rules/laravel/filament.md',
        'rules/laravel/laravel.md',
        'rules/laravel/livewire.md',
        'rules/laravel/queue-debouncing.md',
        'rules/php/core-standards.md',
        'rules/php/dependency-selection.md',
        'rules/refactoring/general.md',
        'rules/reports/general.md',
        'rules/sql/optimalize.md',
        'rules/writing/general.md',
    ];
}

/**
 * The rules issue #277 renamed from `.mdc` to `.md`, finishing what issue #187 started so that
 * `rules/` ships one extension and one scoping key. Narrower than
 * `ruleExtensionRenamedFromMdcFiles()`, which is the whole retired-path sweep list: only these
 * paths were still spelled `.mdc` when the body digests the scoping tests pin were taken.
 *
 * @return array<int, string>
 */
function ruleExtensionRenamedInIssue277Files(): array
{
    return [
        'rules/code-review/general.md',
        'rules/code-testing/general.md',
        'rules/jira/general.md',
        'rules/laravel/architecture.md',
        'rules/laravel/dynamodb.md',
        'rules/laravel/filament.md',
        'rules/laravel/livewire.md',
        'rules/laravel/queue-debouncing.md',
        'rules/php/dependency-selection.md',
        'rules/refactoring/general.md',
        'rules/reports/general.md',
    ];
}

/**
 * A rule body — everything after the closing `---` — hashed with every rule path issue #277
 * respelled written back the way it read before. Repointing a cross-reference is the one body
 * edit that change was allowed to make, and the digests these tests pin were taken beforehand;
 * mapping the spellings back is what lets an unchanged digest keep proving that no normative
 * sentence moved, instead of being re-baselined into agreeing with whatever the file now says.
 *
 * Two spellings are mapped. Each renamed file's own path is the obvious one. The wildcard a rule
 * uses to name the rules a consuming project adds for itself — `@rules/**\/*.md`, retired
 * spelling `@rules/**\/*.mdc` — is the second: it matched every rule this package ships until
 * #277 renamed the last of them, and left matching none the moment it did.
 */
function ruleBodyDigest(string $path): string
{
    $content = (string) file_get_contents($path);
    $body = ltrim(substr($content, (int) strpos($content, '---', 3) + 3));
    $respellings = ['@rules/**/*.md' => '@rules/**/*.mdc'];

    foreach (ruleExtensionRenamedInIssue277Files() as $renamed) {
        $respellings[$renamed] = substr($renamed, 0, -3) . '.mdc';
    }

    return hash('sha256', str_replace(array_keys($respellings), array_values($respellings), $body));
}

/**
 * The references to a rule path issue #187 or #277 retired, found in the given file map, reported as
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
 * The four that remain are always-on because they govern something every run produces rather
 * than a file type a run may or may not touch: `compound-engineering` (principles only, since
 * issue #275 split its dispatch-time orchestration mechanics into a scoped `orchestration.md`
 * sibling — see `ruleScopingExpectedGlobs()`) governs the memory/tracker contract every run
 * follows, `general` governs the project-context and default-AI-behavior baseline every run
 * follows regardless of which file type it touches (split out of `php/core-standards.md` in
 * issue #281, because scoping that file to `**\/*.php` in issue #274 stopped those two sections
 * reaching a run that touches no PHP file), `git` governs every commit and pull request, and
 * `writing` governs every sentence an agent writes.
 *
 * @return array<int, string>
 */
function ruleExtensionAlwaysOnFiles(): array
{
    return [
        'rules/compound-engineering/general.md',
        'rules/general/general.md',
        'rules/git/general.md',
        'rules/writing/general.md',
    ];
}

/**
 * The `paths:` globs every path-scoped rule must declare, written out by hand for the same reason
 * `ruleExtensionAlwaysOnFiles()` is: an expectation read out of the file it checks agrees with any
 * edit made to that file, including a typo. Assembled from the two groups below because they were
 * scoped by two different kinds of change, and because one flat list outgrows the function-length
 * limit the project enforces on itself.
 *
 * A rule scoped to nothing carries `paths: []` and lives in `ruleScopingReferenceOnlyFiles()`
 * instead — an empty list here would assert the same thing whether the key is present or absent.
 *
 * @return array<string, array<int, string>>
 */
function ruleScopingExpectedGlobs(): array
{
    return array_merge(ruleScopingGlobsAddedByScoping(), ruleScopingGlobsTranslatedFromCursorGlobs());
}

/**
 * The rules that already shipped as `.md` and gained a `paths:` key they never had — the four
 * issue #274 took off the always-on list, plus the file issue #275 split out of
 * `compound-engineering/general.md`.
 *
 * @return array<string, array<int, string>>
 */
function ruleScopingGlobsAddedByScoping(): array
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
 * The rules whose Cursor `globs:` list issue #277 translated into `paths:` while renaming them out
 * of `.mdc`. The glob strings are the ones those files already carried — the rename was not
 * allowed to widen or narrow a rule's reach, only to spell the key the way Claude Code reads it.
 *
 * @return array<string, array<int, string>>
 */
function ruleScopingGlobsTranslatedFromCursorGlobs(): array
{
    return [
        'rules/laravel/architecture.md' => [
            'vendor/pekral/arch-app-services/**',
            'app/Actions/**',
            'app/DataBuilders/**',
            'app/DataValidators/**',
            'app/ModelManagers/**',
            'app/Repositories/**',
            'app/Services/**',
            'app/Http/Controllers/**',
            'app/Jobs/**',
            'app/Console/Commands/**',
            'app/Listeners/**',
            'app/Livewire/**/*.php',
        ],
        'rules/laravel/dynamodb.md' => ['app/**/*.php', 'config/dynamodb.php', 'tests/**/*.php'],
        'rules/laravel/filament.md' => ['app/Filament/**/*.php'],
        'rules/laravel/livewire.md' => [
            'app/Livewire/**/*.php',
            'resources/views/livewire/**/*.blade.php',
        ],
        'rules/laravel/queue-debouncing.md' => ['app/**/*.php', 'routes/**/*.php', 'tests/**/*.php'],
    ];
}

/**
 * The rules issue #277 gave an explicit empty scoping list, `paths: []`. Each carried Cursor's
 * `alwaysApply: false` plus `globs: []` — scoped to nothing, so Cursor never attached it by file
 * path either, and as a `.mdc` file Claude Code never loaded it at all. Both readings agree that
 * the rule reaches an agent only when a skill, an agent file, or another rule names it, so the
 * empty list is the translation that keeps the behaviour these rules already had. Dropping the
 * key instead would have made all six always-on, which is the opposite of what issues #274 and
 * #275 spent their diffs achieving.
 *
 * @return array<int, string>
 */
function ruleScopingReferenceOnlyFiles(): array
{
    return [
        'rules/code-review/general.md',
        'rules/code-testing/general.md',
        'rules/jira/general.md',
        'rules/php/dependency-selection.md',
        'rules/refactoring/general.md',
        'rules/reports/general.md',
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
 * they are matched against. This package ships instructions, so it holds no `app/`, no migrations
 * and no `.sql` file of its own — without this corpus a glob aimed at a consumer project could
 * match nothing anywhere and the rule would behave as if it had been deleted. Split across the
 * two functions below by the tree a path sits in, so neither outgrows the function-length limit
 * the project enforces on itself.
 *
 * @return array<int, string>
 */
function ruleScopingConsumerProjectPaths(): array
{
    return array_merge(ruleScopingConsumerAppTreePaths(), ruleScopingConsumerPathsOutsideAppTree());
}

/**
 * The consumer-project paths under `app/` — one per layer the shipped rules scope themselves to.
 *
 * @return array<int, string>
 */
function ruleScopingConsumerAppTreePaths(): array
{
    return [
        'app/Http/Controllers/OrderController.php',
        'app/Http/Requests/StoreOrderRequest.php',
        'app/Http/Resources/OrderResource.php',
        'app/Http/Middleware/EnsureTokenIsValid.php',
        'app/Actions/CreateOrderAction.php',
        'app/Console/Commands/SyncOrdersCommand.php',
        'app/DataBuilders/OrderDataBuilder.php',
        'app/DataValidators/Order/StoreOrderDataValidator.php',
        'app/Filament/Resources/OrderResource.php',
        'app/Jobs/ProcessOrderJob.php',
        'app/Listeners/SendOrderConfirmation.php',
        'app/Livewire/Order/OrderList.php',
        'app/ModelManagers/OrderModelManager.php',
        'app/Repositories/OrderRepository.php',
        'app/Services/OrderPricingService.php',
        'app/Shop/Order/OrderModelManager.php',
    ];
}

/**
 * The consumer-project paths outside `app/`. Two of them are not the consumer's own source at all
 * and would never appear in `packageTextFiles()` either: `.claude/run/**` is `.gitignore`d in
 * every consuming project (per this package's own `agents/daidalos.md` *Shared task brief*), and
 * the `vendor/pekral/arch-app-services/**` path the architecture rule scopes itself to sits in the
 * directory that walk skips. They live here for the same reason the rest of this corpus does.
 *
 * @return array<int, string>
 */
function ruleScopingConsumerPathsOutsideAppTree(): array
{
    return [
        'routes/api.php',
        'routes/web.php',
        'src/Billing/Http/Controllers/InvoiceController.php',
        'packages/billing/src/Http/Controllers/RefundController.php',
        'Modules/Shop/Http/Controllers/CartController.php',
        'database/migrations/2026_01_01_000000_create_orders_table.php',
        'database/schema/mysql-schema.sql',
        'resources/views/order/show.blade.php',
        'resources/views/livewire/order/list.blade.php',
        'config/database.php',
        'config/dynamodb.php',
        'vendor/pekral/arch-app-services/src/Concerns/DataValidator.php',
        '.claude/run/gh-123.md',
    ];
}

/**
 * Every file under `rules/`, as a package-relative path. Walked from disk rather than listed, so
 * a rule added in the wrong format cannot escape the guards that read this.
 *
 * @return array<int, string>
 */
function ruleTreeFiles(): array
{
    $packageDir = dirname(__DIR__);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($packageDir . '/rules', FilesystemIterator::SKIP_DOTS),
    );
    $files = [];

    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->isFile()) {
            $files[] = ltrim(substr($file->getPathname(), strlen($packageDir)), '/');
        }
    }

    sort($files);

    return $files;
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
 * The contract a package file applies: its own text, plus the shared
 * `skills/code-review-github/references/cr-wrapper-contract.md` when the file is one of the three
 * CR tracker wrappers that reference it (issue #279). Any other file is returned unchanged. The
 * path may be package-relative or absolute, because the guards that call this hold it both ways.
 *
 * The three wrappers used to carry their own copy of every shared rule, so a pin could read the
 * rule off the wrapper file directly. They now state a shared rule once, in the reference file, and
 * keep only what their own tracker decides — so a pin asking whether a wrapper declares a rule has
 * to read the contract the wrapper actually applies, not only the file it is written in. Reading
 * the wrapper alone would fail a rule that is correctly stated once; reading the reference alone
 * would miss the tracker-specific half.
 */
function crContractText(string $path): string
{
    $packageDir = dirname(__DIR__);
    $relativePath = ltrim(str_replace($packageDir, '', $path), '/');
    $content = (string) file_get_contents($packageDir . '/' . $relativePath);
    $wrappers = [
        'skills/code-review-github/SKILL.md',
        'skills/code-review-jira/SKILL.md',
        'skills/code-review-bugsnag/SKILL.md',
    ];

    if (!in_array($relativePath, $wrappers, strict: true)) {
        return $content;
    }

    return $content . "\n" . (string) file_get_contents($packageDir . '/skills/code-review-github/references/cr-wrapper-contract.md');
}

/**
 * Every text file the package ships, keyed by its package-relative path. Shared by the content
 * guards that have to prove a pattern appears nowhere in the tree — a retired rule path (issue
 * #187), a forbidden test assertion (issue #181). Walked from disk rather than asked of
 * `git ls-files`, because `@rules/code-testing/general.md` forbids a test from spawning a real
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
