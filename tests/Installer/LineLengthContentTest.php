<?php

declare(strict_types = 1);

/**
 * @return array<string, string>
 */
function ruleOrSkillMarkdownFiles(): array
{
    $isRuleOrSkillFile = static fn (string $path): bool => (str_starts_with($path, 'rules/') || str_starts_with($path, 'skills/'))
        && (str_ends_with($path, '.md') || str_ends_with($path, '.mdc'));

    return array_filter(packageTextFiles(), $isRuleOrSkillFile, ARRAY_FILTER_USE_KEY);
}

/**
 * @return list<string>
 */
function linesAtOrOverBound(string $path, string $content, int $bound): array
{
    $violations = [];

    foreach (explode("\n", $content) as $lineNumber => $line) {
        if (mb_strlen($line) >= $bound) {
            $violations[] = $path . ':' . ($lineNumber + 1) . ' (' . mb_strlen($line) . ' chars)';
        }
    }

    return $violations;
}

test('no line in rules/ or skills/ exceeds an 800-character bound (issue #276)', function (): void {
    // Precedent: the pr-summary-specific bound in CodeReviewContentTest.php (issue #254) proved a
    // single dense paragraph is unreadable and unsearchable past a few hundred characters — the
    // worst offender here ran 6527 characters as one physical line. This test holds the same
    // 800-char bound (mb_strlen, not byte length, so diacritics never inflate the count) across the
    // whole rules/ + skills/ tree, so a future edit cannot silently regrow a wall of prose anywhere
    // in the package. Every physical line counts, including one inside a fenced code block: an
    // unreadable line is unreadable whether it renders as prose or as code.
    $violations = [];

    foreach (ruleOrSkillMarkdownFiles() as $path => $content) {
        $violations = [...$violations, ...linesAtOrOverBound($path, $content, 800)];
    }

    expect($violations)->toBe([]);
});
