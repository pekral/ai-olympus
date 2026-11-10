#!/usr/bin/env php
<?php

declare(strict_types = 1);

const PROSE_USAGE = <<<'TXT'
Usage: check-comment-prose.php --diff <diff-file|-> [--root <dir>]

Reports every PHP comment on an added line of the unified diff that carries a line which is not
an annotation allowed by rules/php/core-standards.md *Documentation*. The files are read from
--root (default: the current directory), as they are on disk.

  git diff -U0 --diff-filter=AM "$(git merge-base "origin/$DEFAULT_BRANCH" HEAD)" -- '*.php' \
    | php skills/_shared/check-comment-prose.php --diff -

stdout: {"valid": bool, "findings": [{"file": "app/X.php", "line": 12}]}
exit:   0 no prose, 1 usage error or unreadable input, 3 prose found

TXT;

const PROSE_ALLOWED_TAG = '~^@(param|return|var|throws|template(-[a-z]+)?|extends|implements|property(-read|-write)?|method|mixin|phpstan-[a-z-]+'
    . '|see|deprecated|api|covers|dataProvider|codeCoverageIgnore(Start|End)?)(?![\w-])~';

final class ProseInputError extends RuntimeException
{
}

function proseDiffPath(string $header): string
{
    $path = rtrim(substr($header, 4), "\t");

    if (str_starts_with($path, '"') && str_ends_with($path, '"')) {
        $path = stripcslashes(substr($path, 1, -1));
    }

    return str_starts_with($path, 'b/') ? substr($path, 2) : $path;
}

/**
 * @return array<string, list<int>>
 */
function proseAddedLines(string $diff): array
{
    $addedLines = [];
    $file = null;
    $newLine = 0;

    foreach (preg_split('/\R/', $diff) ?: [] as $diffLine) {
        if (str_starts_with($diffLine, '+++ ')) {
            $path = proseDiffPath($diffLine);
            $file = str_ends_with($path, '.php') ? $path : null;

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

function proseOpensContinuation(string $content): bool
{
    return proseBracketBalance($content) > 0 && preg_match('/[{<(,]$/', $content) === 1;
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

        if (preg_match(PROSE_ALLOWED_TAG, $content) === 1) {
            $depth = proseOpensContinuation($content) ? proseBracketBalance($content) : 0;

            continue;
        }

        $proseLines[] = $startLine + $offset;
    }

    return $proseLines;
}

/**
 * @return list<array{line: int, text: string}>
 */
function proseCommentBlocks(string $source): array
{
    $blocks = [];
    $lastIsLineComment = false;

    foreach (PhpToken::tokenize($source) as $token) {
        if ($token->is(T_WHITESPACE)) {
            $lastIsLineComment = $lastIsLineComment && substr_count($token->text, "\n") <= 1;

            continue;
        }

        if (!$token->is([T_COMMENT, T_DOC_COMMENT])) {
            $lastIsLineComment = false;

            continue;
        }

        $isLineComment = !str_starts_with($token->text, '/*');

        if ($isLineComment && $lastIsLineComment && $blocks !== []) {
            $blocks[array_key_last($blocks)]['text'] .= rtrim($token->text, "\n") . "\n";
        } else {
            $blocks[] = ['line' => $token->line, 'text' => rtrim($token->text, "\n") . "\n"];
        }

        $lastIsLineComment = $isLineComment;
    }

    return $blocks;
}

/**
 * @param list<int> $addedLines
 * @return list<int>
 */
function proseCommentStartsInFile(string $source, array $addedLines): array
{
    $findings = [];

    foreach (proseCommentBlocks($source) as $block) {
        $endLine = $block['line'] + substr_count(rtrim($block['text'], "\n"), "\n");
        $touchedLines = array_filter($addedLines, static fn (int $line): bool => $line >= $block['line'] && $line <= $endLine);

        if ($touchedLines === []) {
            continue;
        }

        $proseLines = proseLinesOfComment($block['text'], $block['line']);

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

        if (str_starts_with($file, '/') || str_contains('/' . $file . '/', '/../')) {
            throw new ProseInputError('the diff names a path outside the root');
        }

        if (!is_file($path)) {
            throw new ProseInputError('the diff names a PHP file that is not under the root');
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
        fwrite(STDERR, PROSE_USAGE);

        return 1;
    }

    $diff = $diffSource === '-' ? stream_get_contents(STDIN) : (is_file($diffSource) ? file_get_contents($diffSource) : false);

    try {
        if (!is_string($diff)) {
            throw new ProseInputError('cannot read the diff');
        }

        $findings = proseFindings($diff, rtrim($root, '/'));
    } catch (ProseInputError $error) {
        fwrite(STDERR, 'check-comment-prose.php: ' . $error->getMessage() . "\n");

        return 1;
    }

    echo json_encode(['valid' => $findings === [], 'findings' => $findings], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

    return $findings === [] ? 0 : 3;
}

exit(proseMain(array_values($argv)));
