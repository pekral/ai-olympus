#!/usr/bin/env php
<?php

declare(strict_types = 1);

const VISIBLE_USAGE = <<<'TXT'
Usage: check-visible-review-text.php --text <file|-> [--repo-paths <file>] [--diff-text <file>]

Reports every name of this package's own tooling in the visible text of a code review, per
rules/code-review/general.md *Visible review text names none of the package's own tooling*:
a package path (skills/<name>/..., rules/<dir>/..., agents/<name>.md, codex/agents/...), an
installed directory (.claude/, .codex/, .agents/), and the file name of a package script.

  --text        the visible text: the inline comments and the visible review body, never the hidden record
  --repo-paths  the paths of the reviewed repository, one per line. A path it holds, or a script
                name one of its files carries, is the reviewed project's own and stays allowed.
  --diff-text   the added and removed lines of the reviewed change, without their +/- marker. A name that
                one of those lines carries is the reviewed project's own text and stays allowed.

stdout: {"valid": bool, "names": ["skills/_shared/run-gate.sh"]}
exit:   0 no package name, 1 usage error or unreadable input, 3 package name found

TXT;

const VISIBLE_TOKEN = '~[A-Za-z0-9_.@/-]+~';
const VISIBLE_PACKAGE_PATH = '~^(?:\.(?:claude|codex|agents)/.|skills/[^/]+|rules/[^/]+/|agents/[^/]+\.md$|codex/agents/)~';

final class VisibleInputError extends RuntimeException
{
}

function visibleRead(string $source, string $what): string
{
    $content = $source === '-' ? stream_get_contents(STDIN) : (is_file($source) ? file_get_contents($source) : false);

    if (!is_string($content)) {
        throw new VisibleInputError('cannot read the ' . $what);
    }

    return $content;
}

/**
 * @return list<string>
 */
function visiblePackageScriptNames(string $skillsRoot): array
{
    $files = array_merge(glob($skillsRoot . '/_shared/*') ?: [], glob($skillsRoot . '/*/scripts/*') ?: []);

    return array_values(array_unique(array_map(basename(...), array_filter($files, is_file(...)))));
}

function visibleNormalize(string $token): string
{
    $token = (string) preg_replace('~^(?:@|\./)+~', '', $token);

    return rtrim((string) preg_replace('~:\d+(?:-\d+)?$~', '', rtrim($token, '.:')), '.:');
}

/**
 * @param array<string, true> $repoPaths
 */
function visibleHeldByRepository(string $path, array $repoPaths): bool
{
    $path = rtrim($path, '/');

    if (isset($repoPaths[$path])) {
        return true;
    }

    foreach (array_keys($repoPaths) as $repoPath) {
        if (str_starts_with($repoPath, $path . '/')) {
            return true;
        }
    }

    return false;
}

/**
 * @param list<string> $repoPathList
 * @return list<string>
 */
function visiblePackageNames(string $text, array $repoPathList, string $skillsRoot, string $diffText = ''): array
{
    $diffNames = [];

    preg_match_all(VISIBLE_TOKEN, $diffText, $diffMatches);

    foreach ($diffMatches[0] as $diffToken) {
        $diffNames[visibleNormalize($diffToken)] = true;
    }

    $repoPaths = array_fill_keys($repoPathList, true);
    $repoBasenames = array_fill_keys(array_map(basename(...), $repoPathList), true);
    $scriptNames = array_fill_keys(visiblePackageScriptNames($skillsRoot), true);
    $names = [];

    preg_match_all(VISIBLE_TOKEN, $text, $matches);

    foreach ($matches[0] as $token) {
        $name = visibleNormalize($token);

        if (isset($diffNames[$name])) {
            continue;
        }

        $basename = basename($name);
        $isPackagePath = preg_match(VISIBLE_PACKAGE_PATH, $name) === 1 && !visibleHeldByRepository($name, $repoPaths);
        $isPackageScript = $basename !== '' && isset($scriptNames[$basename]) && !isset($repoBasenames[$basename]);

        if ($isPackagePath || $isPackageScript) {
            $names[$name] = true;
        }
    }

    return array_keys($names);
}

function visibleMain(): int
{
    $options = getopt('', ['text:', 'repo-paths:', 'diff-text:']);
    $textSource = is_string($options['text'] ?? null) ? $options['text'] : null;
    $pathsSource = is_string($options['repo-paths'] ?? null) ? $options['repo-paths'] : null;
    $diffSource = is_string($options['diff-text'] ?? null) ? $options['diff-text'] : null;

    if ($textSource === null) {
        fwrite(STDERR, VISIBLE_USAGE);

        return 1;
    }

    try {
        $text = visibleRead($textSource, 'visible text');
        $repoPaths = $pathsSource === null ? [] : preg_split('/\R/', visibleRead($pathsSource, 'repository paths'), flags: PREG_SPLIT_NO_EMPTY);
        $diffText = $diffSource === null ? '' : visibleRead($diffSource, 'diff text');
    } catch (VisibleInputError $error) {
        fwrite(STDERR, 'check-visible-review-text.php: ' . $error->getMessage() . "\n");

        return 1;
    }

    $names = visiblePackageNames($text, array_values($repoPaths ?: []), dirname(__DIR__), $diffText);

    echo json_encode(['valid' => $names === [], 'names' => $names], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";

    return $names === [] ? 0 : 3;
}

exit(visibleMain());
