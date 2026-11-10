#!/usr/bin/env php
<?php

declare(strict_types = 1);

/**
 * Report every PHP comment on the added lines of a diff that carries prose.
 *
 * rules/php/core-standards.md *Documentation* allows a comment to carry `@` annotations and
 * tool directives only, so PHPDoc keeps what static analysis reads and nothing else. This
 * script is the deterministic half of that rule: it reads a unified diff, tokenizes each
 * changed PHP file as it is on disk, and reports each comment that overlaps an added line
 * and holds a line which is not an `@` annotation. A continuation line of a multi-line
 * annotation, such as an array shape over several lines, is part of the annotation.
 *
 * Usage: check-comment-prose.php --diff <diff-file|-> [--root <dir>]
 *   git diff -U0 --diff-filter=AM "$BASE"...HEAD -- '*.php' | php skills/_shared/check-comment-prose.php --diff -
 *
 * The files are read from --root (default: the current directory), so run it on a tree at
 * the head the diff describes. The diff and the files are data: nothing in them is executed.
 *
 * stdout: {"valid": bool, "findings": [{"file": "app/X.php", "line": 12}]}
 * exit:   0 no prose, 1 usage error, 3 prose found
 *
 * @return array<string, list<int>>
 */
function proseAddedLines(string $diff): array
{
    $addedLines = [];
    $file = null;
    $newLine = 0;

    foreach (preg_split('/\R/', $diff) ?: [] as $diffLine) {
        if (str_starts_with($diffLine, '+++ ')) {
            $path = trim(substr($diffLine, 4), '"');
            $file = str_starts_with($path, 'b/') && str_ends_with($path, '.php') ? substr($path, 2) : null;

            continue;
        }

        if (preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)(?:,\d+)? @@/', $diffLine, $hunk) === 1) {
            $newLine = (int) $hunk[1];

            continue;
        }

        if ($file === null || str_starts_with($diffLine, '--- ')) {
            continue;
        }

        if (str_starts_with($diffLine, '+')) {
            $addedLines[$file][] = $newLine;
            $newLine++;
        } elseif (str_starts_with($diffLine, ' ')) {
            $newLine++;
        }
    }

    return $addedLines;
}

function proseBracketBalance(string $content): int
{
    $content = str_replace(['->', '=>'], '', $content);

    return substr_count($content, '<') + substr_count($content, '{') + substr_count($content, '(')
        - substr_count($content, '>') - substr_count($content, '}') - substr_count($content, ')');
}

function proseCommentContent(string $line): string
{
    $content = trim($line);
    $content = (string) preg_replace('~^(/\*\*?|//|#)~', '', $content);
    $content = (string) preg_replace('~\*/$~', '', $content);
    $content = (string) preg_replace('~^\*(?!/)~', '', trim($content));

    return trim($content);
}

/**
 * @return list<int>
 */
function proseLinesOfComment(string $comment, int $startLine): array
{
    $proseLines = [];
    $depth = 0;

    foreach (explode("\n", rtrim($comment, "\n")) as $offset => $line) {
        $content = proseCommentContent($line);

        if ($depth > 0) {
            $depth = max(0, $depth + proseBracketBalance($content));

            continue;
        }

        if ($content === '') {
            continue;
        }

        if (str_starts_with($content, '@')) {
            $depth = max(0, proseBracketBalance($content));

            continue;
        }

        $proseLines[] = $startLine + $offset;
    }

    return $proseLines;
}

/**
 * @param list<int> $addedLines
 * @return list<int>
 */
function proseCommentStartsInFile(string $source, array $addedLines): array
{
    $findings = [];

    foreach (PhpToken::tokenize($source) as $token) {
        if (!$token->is([T_COMMENT, T_DOC_COMMENT])) {
            continue;
        }

        $endLine = $token->line + substr_count(rtrim($token->text, "\n"), "\n");
        $touchedLines = array_filter($addedLines, static fn (int $line): bool => $line >= $token->line && $line <= $endLine);

        if ($touchedLines === []) {
            continue;
        }

        $proseLines = proseLinesOfComment($token->text, $token->line);

        if ($proseLines !== []) {
            $findings[] = $proseLines[0];
        }
    }

    return $findings;
}

/**
 * @return list<array{file: string, line: int}>
 */
function proseFindings(string $diff, string $root): array
{
    $findings = [];

    foreach (proseAddedLines($diff) as $file => $addedLines) {
        $path = $root . '/' . $file;

        if (str_contains('/' . $file . '/', '/../') || !is_file($path)) {
            continue;
        }

        foreach (proseCommentStartsInFile((string) file_get_contents($path), $addedLines) as $line) {
            $findings[] = ['file' => $file, 'line' => $line];
        }
    }

    return $findings;
}

/**
 * @param list<string> $arguments
 */
function proseMain(array $arguments): int
{
    $options = getopt('', ['diff:', 'root:']);
    $diffSource = is_string($options['diff'] ?? null) ? $options['diff'] : null;
    $root = is_string($options['root'] ?? null) ? $options['root'] : (string) getcwd();

    if ($diffSource === null || count($arguments) < 2 || !is_dir($root)) {
        fwrite(STDERR, "Usage: check-comment-prose.php --diff <diff-file|-> [--root <dir>]\n");

        return 1;
    }

    $diff = $diffSource === '-' ? stream_get_contents(STDIN) : @file_get_contents($diffSource);

    if (!is_string($diff)) {
        fwrite(STDERR, "check-comment-prose.php: cannot read the diff\n");

        return 1;
    }

    $findings = proseFindings($diff, rtrim($root, '/'));
    echo json_encode(['valid' => $findings === [], 'findings' => $findings], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

    return $findings === [] ? 0 : 3;
}

exit(proseMain(array_values($argv)));
