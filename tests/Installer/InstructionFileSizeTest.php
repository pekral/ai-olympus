<?php

declare(strict_types = 1);

test('every rule, agent, and command file stays under the loader size limit', function (): void {
    // Claude Code enforces a 150 000-character limit per instruction file. `rules/code-review/
    // general.md` once passed it and the loader stopped loading the file — silently, so the whole
    // code-review rule set went inactive with no error anywhere. The split into three files fixed
    // that instance; this guard pins the class, because until now the limit lived only in a
    // comment (tests/Pest.php `codeReviewRuleContents()`) and nothing measured a file against it.
    //
    // The threshold is 90 % of the hard limit, so a file trips this test while there is still room
    // to split it, rather than at the moment the loader has already dropped it.
    //
    // Measured in bytes, not characters: UTF-8 bytes are never fewer than the characters they
    // encode, so a byte count can only make this guard fire earlier than the real limit.
    $limit = 135_000;

    $oversized = [];

    foreach (packageTextFiles() as $relativePath => $contents) {
        if (preg_match('#^(rules|agents|commands)/.*\.md$#', $relativePath) !== 1) {
            continue;
        }

        $size = strlen($contents);

        if ($size >= $limit) {
            $oversized[] = $relativePath . ' (' . $size . ' bytes)';
        }
    }

    expect($oversized)->toBe([]);
});
