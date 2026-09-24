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

test('the always-on rules and the installed CLAUDE.md template stay inside the total instruction budget', function (): void {
    // Claude Code also enforces a 150 000-character limit on the TOTAL of every always-loaded
    // instruction file: the project `CLAUDE.md`, the user's memory, and every rule without a
    // `paths:` key. A consuming project saw `⚠ 13 instruction files add up to 395.5k chars, over the
    // 150.0k-char total limit`, because the always-on rules this package shipped came to 344 317
    // bytes on their own (347 940 with the template) — every consumer was over before a single line
    // of its own instructions was counted.
    //
    // The budget is half the limit. The other half belongs to the consuming project: its own
    // `CLAUDE.md` (the one that reported the warning carries 54.5k on its own), the user's memory,
    // and whatever rules it adds. A package that took most of the limit would leave a real project
    // unable to stay under it, which is the failure this test exists to prevent.
    //
    // Always-on is read from the frontmatter, not from `ruleExtensionAlwaysOnFiles()`: a new rule
    // added without a `paths:` key costs budget whether or not anyone registered it. Bytes, not
    // characters, for the reason the per-file test above states.
    $packageDir = dirname(__DIR__, 2);
    $budget = 75_000;
    $sizes = ['templates/CLAUDE.md' => strlen((string) file_get_contents($packageDir . '/templates/CLAUDE.md'))];

    foreach (ruleTreeFiles() as $relativePath) {
        if (preg_match('/^paths:/m', ruleExtensionFrontmatter($packageDir . '/' . $relativePath)) === 1) {
            continue;
        }

        $sizes[$relativePath] = strlen((string) file_get_contents($packageDir . '/' . $relativePath));
    }

    arsort($sizes);

    // Without this the walk could stop finding always-on rules and the sum would pass vacuously.
    expect($sizes)->toHaveKey('rules/security/general.md');
    expect(array_sum($sizes))->toBeLessThanOrEqual($budget, 'always-on total: ' . json_encode($sizes));
});
