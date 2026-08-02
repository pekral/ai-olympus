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
