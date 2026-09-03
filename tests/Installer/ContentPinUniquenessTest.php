<?php

declare(strict_types = 1);

/**
 * A content pin written as `expect($corpus)->toContain('<literal>')` is satisfied by **any**
 * occurrence of the literal in the corpus. When the same sentence exists somewhere else in that
 * corpus, the pin stays syntactically valid and stops guarding the place it was written for:
 * deleting that place leaves the test green (issue #75, found twice on PR #74).
 *
 * This walk reads the pins of a content test file back out of its own source, counts each literal
 * in the corpus the pin is asserted against, and reports every pin that matches more than once.
 *
 * It resolves the three corpus loaders this package's content tests use — `file_get_contents()`
 * over a `$packageDir`-relative path, `crContractText()`, and `codeReviewRuleContents()` — each on
 * its own and concatenated with the others and with string literals. A pin whose corpus comes from
 * anywhere else (a `foreach` variable, a computed path, a nested call the pin head does not see) is
 * not counted: the walk reports what it can prove, never a guess.
 */

/**
 * Every PHP token of a file, with whitespace and comments dropped, in source order.
 *
 * @return array<int, array{id: int, line: int, text: string}>
 */
function contentPinTokens(string $absolutePath): array
{
    $tokens = [];

    foreach (token_get_all((string) file_get_contents($absolutePath)) as $token) {
        if (is_string($token)) {
            $tokens[] = ['id' => 0, 'line' => 0, 'text' => $token];

            continue;
        }

        if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $tokens[] = ['id' => $token[0], 'line' => $token[2], 'text' => $token[1]];
    }

    return $tokens;
}

/**
 * The runtime value of a single-quoted or double-quoted PHP string literal.
 */
function contentPinDecodeString(string $literal): string
{
    $body = substr($literal, 1, -1);

    if (str_starts_with($literal, '"')) {
        return stripcslashes($body);
    }

    return str_replace(['\\\\', '\\\''], ['\\', '\''], $body);
}

/**
 * How much a token changes the nesting depth of a call or an array expression.
 */
function contentPinDepthDelta(string $text): int
{
    return (int) in_array($text, ['(', '['], true) - (int) in_array($text, [')', ']'], true);
}

/**
 * The value one token of a constant string expression contributes, or null when the token is not
 * part of one.
 *
 * @param array{id: int, line: int, text: string} $token
 */
function contentPinConstantToken(array $token): ?string
{
    if ($token['id'] === T_CONSTANT_ENCAPSED_STRING) {
        return contentPinDecodeString($token['text']);
    }

    if ($token['text'] === '.') {
        return '';
    }

    if ($token['id'] === T_VARIABLE && $token['text'] === '$packageDir') {
        return dirname(__DIR__, 2);
    }

    return null;
}

/**
 * The value of a constant string expression — string literals concatenated with each other and
 * with `$packageDir`. Null when the expression carries anything else, which is how this walk says
 * it cannot resolve the expression statically.
 *
 * @param array<int, array{id: int, line: int, text: string}> $tokens
 */
function contentPinConstantString(array $tokens): ?string
{
    $value = '';

    foreach ($tokens as $token) {
        $piece = contentPinConstantToken($token);

        if ($piece === null) {
            return null;
        }

        $value .= $piece;
    }

    return $value === '' ? null : $value;
}

/**
 * The tokens of the first argument of a call, starting at the token after its opening parenthesis.
 * The walk stops at the argument separator, so a trailing comma never lands in the result and a
 * multi-value call resolves to its first value.
 *
 * @param array<int, array{id: int, line: int, text: string}> $tokens
 * @return array<int, array{id: int, line: int, text: string}>
 */
function contentPinCallArguments(array $tokens, int $start): array
{
    $arguments = [];
    $depth = 1;
    $total = count($tokens);

    for ($index = $start; $index < $total; $index++) {
        $token = $tokens[$index];
        $depth += contentPinDepthDelta($token['text']);

        if ($depth === 0 || ($depth === 1 && $token['text'] === ',')) {
            break;
        }

        $arguments[] = $token;
    }

    return $arguments;
}

/**
 * The tokens of a statement's right-hand side, starting at the token after the `=`.
 *
 * @param array<int, array{id: int, line: int, text: string}> $tokens
 * @return array<int, array{id: int, line: int, text: string}>
 */
function contentPinStatementTail(array $tokens, int $start): array
{
    $tail = [];
    $depth = 0;
    $total = count($tokens);

    for ($index = $start; $index < $total; $index++) {
        $token = $tokens[$index];
        $depth += contentPinDepthDelta($token['text']);

        if ($depth === 0 && $token['text'] === ';') {
            break;
        }

        $tail[] = $token;
    }

    return $tail;
}

/**
 * The corpus a file-reading loader returns, as a stable name plus its text.
 *
 * @return array{name: string, text: string}|null
 */
function contentPinFileCorpus(string $loader, string $path): ?array
{
    $packageDir = dirname(__DIR__, 2);
    $absolute = str_starts_with($path, '/') ? $path : $packageDir . '/' . $path;
    $relative = ltrim(str_replace($packageDir, '', $absolute), '/');

    if (!is_file($absolute)) {
        return null;
    }

    if ($loader === 'file_get_contents') {
        return ['name' => $relative, 'text' => (string) file_get_contents($absolute)];
    }

    if ($loader === 'crContractText') {
        return ['name' => 'crContractText(' . $relative . ')', 'text' => crContractText($relative)];
    }

    return null;
}

/**
 * The tokens of each top-level `.` operand of a concatenation, in source order.
 *
 * @param array<int, array{id: int, line: int, text: string}> $tail
 * @return array<int, array<int, array{id: int, line: int, text: string}>>
 */
function contentPinConcatTerms(array $tail): array
{
    $terms = [];
    $current = [];
    $depth = 0;

    foreach ($tail as $token) {
        $depth += contentPinDepthDelta($token['text']);

        if ($depth === 0 && $token['text'] === '.') {
            $terms[] = $current;
            $current = [];

            continue;
        }

        $current[] = $token;
    }

    $terms[] = $current;

    return $terms;
}

/**
 * The corpus one operand of the concatenation contributes.
 *
 * @param array<int, array{id: int, line: int, text: string}> $term
 * @return array{name: string, text: string}|null
 */
function contentPinCorpusTerm(array $term): ?array
{
    $call = ($term[0]['id'] ?? 0) === T_STRING_CAST ? array_slice($term, 1) : $term;

    if (count($call) === 1 && $call[0]['id'] === T_CONSTANT_ENCAPSED_STRING) {
        return ['name' => 'literal', 'text' => contentPinDecodeString($call[0]['text'])];
    }

    $loader = $call[0]['text'] ?? '';

    if ($loader === 'codeReviewRuleContents') {
        return ['name' => 'codeReviewRuleContents()', 'text' => codeReviewRuleContents()];
    }

    $path = contentPinConstantString(array_slice($call, 2, -1));

    return $path === null ? null : contentPinFileCorpus($loader, $path);
}

/**
 * The corpus a `$var = ...;` assignment loads, across every operand of a concatenation. Null when
 * any operand is not one of the loaders this walk understands.
 *
 * @param array<int, array{id: int, line: int, text: string}> $tail
 * @return array{name: string, text: string}|null
 */
function contentPinCorpus(array $tail): ?array
{
    $names = [];
    $text = '';

    foreach (contentPinConcatTerms($tail) as $term) {
        $resolved = contentPinCorpusTerm($term);

        if ($resolved === null) {
            return null;
        }

        $names[] = $resolved['name'];
        $text .= $resolved['text'];
    }

    return ['name' => implode(' . ', $names), 'text' => $text];
}

/**
 * The index of the first argument token when the seven tokens at `$index` are
 * `expect($var)->toContain(`, or null when they are not a pin. A `not->` chain puts `not` where
 * `toContain` has to be, so a negated pin never matches — it asserts an absence and has no
 * occurrence to count.
 *
 * @param array<int, array{id: int, line: int, text: string}> $tokens
 */
function contentPinArgumentStart(array $tokens, int $index): ?int
{
    $texts = array_column(array_slice($tokens, $index, 7), 'text');
    $isPinHead = ($texts[0] ?? '') === 'expect'
        && ($texts[1] ?? '') === '('
        && str_starts_with($texts[2] ?? '', '$')
        && ($texts[3] ?? '') === ')'
        && ($texts[4] ?? '') === '->'
        && ($texts[5] ?? '') === 'toContain'
        && ($texts[6] ?? '') === '(';

    return $isPinHead ? $index + 7 : null;
}

/**
 * Whether the token at `$index` opens a `$var = ...` assignment.
 *
 * @param array<int, array{id: int, line: int, text: string}> $tokens
 */
function contentPinIsAssignment(array $tokens, int $index): bool
{
    return $tokens[$index]['id'] === T_VARIABLE && ($tokens[$index + 1]['text'] ?? '') === '=';
}

/**
 * Whether the token at `$index` opens a `test('...', ...)` / `it('...', ...)` block.
 *
 * @param array<int, array{id: int, line: int, text: string}> $tokens
 */
function contentPinIsTestHead(array $tokens, int $index): bool
{
    return in_array($tokens[$index]['text'], ['it', 'test'], true)
        && $tokens[$index]['id'] === T_STRING
        && ($tokens[$index + 1]['text'] ?? '') === '('
        && ($tokens[$index + 2]['id'] ?? 0) === T_CONSTANT_ENCAPSED_STRING
        && ($tokens[$index + 3]['text'] ?? '') === ',';
}

/**
 * The corpus map with every variable a `foreach (... as ...)` binds forgotten. A loop variable
 * holds a different value on every iteration, so whatever its name held before the loop says
 * nothing about the pins inside it.
 *
 * @param array<int, array{id: int, line: int, text: string}> $tokens
 * @param array<string, array{name: string, text: string}|null> $corpora
 * @return array<string, array{name: string, text: string}|null>
 */
function contentPinForgetLoopVariables(array $tokens, int $index, array $corpora): array
{
    $total = count($tokens);

    for ($cursor = $index + 1; $cursor < $total; $cursor++) {
        if ($tokens[$cursor]['id'] === T_VARIABLE) {
            $corpora[$tokens[$cursor]['text']] = null;

            continue;
        }

        if ($tokens[$cursor]['id'] !== T_DOUBLE_ARROW && !in_array($tokens[$cursor]['text'], [',', '[', ']'], true)) {
            break;
        }
    }

    return $corpora;
}

/**
 * The corpus map after the token at `$index`: a `test(` head starts a fresh scope, a `foreach`
 * binding shadows whatever the name held, and an assignment records what it loads.
 *
 * @param array<int, array{id: int, line: int, text: string}> $tokens
 * @param array<string, array{name: string, text: string}|null> $corpora
 * @return array<string, array{name: string, text: string}|null>
 */
function contentPinTrackScope(array $tokens, int $index, array $corpora): array
{
    if (contentPinIsTestHead($tokens, $index)) {
        return [];
    }

    if ($tokens[$index]['id'] === T_AS) {
        return contentPinForgetLoopVariables($tokens, $index, $corpora);
    }

    if (contentPinIsAssignment($tokens, $index)) {
        $corpora[$tokens[$index]['text']] = contentPinCorpus(contentPinStatementTail($tokens, $index + 2));
    }

    return $corpora;
}

/**
 * The pin at `$index`, with the number of times its literal occurs in the corpus it is asserted
 * against, or null when this is not a pin or its corpus is not resolvable.
 *
 * @param array<int, array{id: int, line: int, text: string}> $tokens
 * @param array<string, array{name: string, text: string}|null> $corpora
 * @return array{corpus: string, count: int, line: int, literal: string}|null
 */
function contentPinAt(array $tokens, int $index, array $corpora): ?array
{
    $argumentStart = contentPinArgumentStart($tokens, $index);

    if ($argumentStart === null) {
        return null;
    }

    $corpus = $corpora[$tokens[$index + 2]['text']] ?? null;
    $literal = contentPinConstantString(contentPinCallArguments($tokens, $argumentStart));

    if ($corpus === null || $literal === null) {
        return null;
    }

    return [
        'corpus' => $corpus['name'],
        'count' => substr_count($corpus['text'], $literal),
        'line' => $tokens[$index]['line'],
        'literal' => $literal,
    ];
}

/**
 * Every resolvable `expect($corpus)->toContain('<literal>')` pin of a test file, with its
 * occurrence count.
 *
 * @return array<int, array{corpus: string, count: int, line: int, literal: string}>
 */
function contentPinOccurrences(string $relativeTestFile): array
{
    $tokens = contentPinTokens(dirname(__DIR__, 2) . '/' . $relativeTestFile);
    $corpora = [];
    $pins = [];

    foreach (array_keys($tokens) as $index) {
        $corpora = contentPinTrackScope($tokens, $index, $corpora);
        $pin = contentPinAt($tokens, $index, $corpora);

        if ($pin !== null) {
            $pins[] = $pin;
        }
    }

    return $pins;
}

/**
 * The `<corpus> :: <literal>` key of every pin that matches its corpus more than once.
 *
 * @return array<int, string>
 */
function contentPinDuplicateKeys(string $relativeTestFile): array
{
    $keys = [];

    foreach (contentPinOccurrences($relativeTestFile) as $pin) {
        if ($pin['count'] === 1) {
            continue;
        }

        $keys[] = $pin['corpus'] . ' :: ' . $pin['literal'];
    }

    return array_values(array_unique($keys));
}

/**
 * The pins that already matched more than once when this guard was introduced, read from
 * `content-pin-duplicate-baseline.txt` beside this file. It is a ratchet, not a permission: the
 * first test below fails on anything outside it, so no new duplicate pin can land, and the second
 * fails on an entry that is no longer a duplicate, so the list can only ever shrink. Anchoring one
 * of these on a literal unique to its own place — the fix issue #75 applied to the three pins it
 * names — is what removes it from the list.
 *
 * @return array<int, string>
 */
function contentPinDuplicateBaseline(): array
{
    $file = (string) file_get_contents(__DIR__ . '/content-pin-duplicate-baseline.txt');
    $isEntry = static fn (string $line): bool => $line !== '' && !str_starts_with($line, '#');

    return array_values(array_filter(explode("\n", $file), $isEntry));
}

test('no content pin outside the duplicate baseline matches its corpus more than once (issue #75)', function (): void {
    $duplicates = contentPinDuplicateKeys('tests/Installer/CodeReviewContentTest.php');

    // A pin listed here matches its corpus twice or more, so it is satisfied by a sibling
    // occurrence and deleting the place it guards leaves it green. Anchor it on a literal unique
    // to that place and assert `substr_count($corpus, '<literal>')->toBe(1)` instead of adding it
    // to the baseline.
    expect(array_values(array_diff($duplicates, contentPinDuplicateBaseline())))->toBe([]);
});

test('the duplicate content pin baseline carries no stale entry (issue #75)', function (): void {
    $duplicates = contentPinDuplicateKeys('tests/Installer/CodeReviewContentTest.php');

    // An entry here no longer matches more than once — the pin was anchored or removed. Delete the
    // entry so the baseline keeps shrinking and never turns into a permanent exemption list.
    expect(array_values(array_diff(contentPinDuplicateBaseline(), $duplicates)))->toBe([]);
});

test('the walk resolves a corpus concatenated from several loaders (issue #75)', function (): void {
    $corpora = array_column(contentPinOccurrences('tests/Installer/CodeReviewContentTest.php'), 'corpus');

    // `$content = (string) file_get_contents($packageDir . '/skills/code-review/SKILL.md') . "\n" .
    // codeReviewRuleContents();` is the dominant corpus shape of that file. A walk that stops at the
    // first top-level `.` resolves it to null and drops every pin asserted against it.
    expect(array_unique($corpora))->toContain('skills/code-review/SKILL.md . literal . codeReviewRuleContents()');
});
