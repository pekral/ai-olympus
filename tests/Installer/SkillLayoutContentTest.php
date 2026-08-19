<?php

declare(strict_types = 1);

/**
 * Every `skills/<slug>/SKILL.md` the package ships, keyed by its package-relative path.
 *
 * @return array<string, string>
 */
function skillEntrypointFiles(): array
{
    $isSkillEntrypoint = static fn (string $path): bool => str_starts_with($path, 'skills/') && str_ends_with($path, '/SKILL.md');

    return array_filter(packageTextFiles(), $isSkillEntrypoint, ARRAY_FILTER_USE_KEY);
}

/**
 * A SKILL.md body with its YAML frontmatter removed.
 *
 * @param list<string> $lines
 * @return list<string>
 */
function skillBodyLines(array $lines): array
{
    if (($lines[0] ?? null) !== '---') {
        return $lines;
    }

    $closing = array_search('---', array_slice($lines, 1), strict: true);

    return $closing === false ? $lines : array_slice($lines, (int) $closing + 2);
}

/**
 * True when every fenced block in a SKILL.md body is closed again.
 *
 * An unclosed fence would leave `skillBodyHeadings()` below inside a block for the rest of the
 * file, so every later heading — including a reintroduced layout B title — would go unread. The
 * guard therefore fails on the unbalanced fence itself instead of silently reading less.
 */
function skillBodyClosesEveryFence(string $content): bool
{
    $isFenceMarker = static fn (string $line): bool => str_starts_with($line, '```');

    return count(array_filter(skillBodyLines(explode("\n", $content)), $isFenceMarker)) % 2 === 0;
}

/**
 * Markdown headings of a SKILL.md body. Fenced blocks are skipped, so a `# comment` inside a
 * shell snippet or an output template is never mistaken for a document heading.
 *
 * @return list<string>
 */
function skillBodyHeadings(string $content): array
{
    $headings = [];
    $insideFence = false;

    foreach (skillBodyLines(explode("\n", $content)) as $line) {
        if (str_starts_with($line, '```')) {
            $insideFence = !$insideFence;

            continue;
        }

        if ($insideFence || !str_starts_with($line, '#')) {
            continue;
        }

        $headings[] = $line;
    }

    return $headings;
}

/**
 * Every way one SKILL.md body can fail the layout A guard.
 *
 * @return list<string>
 */
function skillLayoutViolations(string $path, string $content): array
{
    $violations = skillBodyClosesEveryFence($content)
        ? []
        : [$path . ' leaves a fenced block open, so the guard cannot read the rest of the body'];

    $headings = skillBodyHeadings($content);

    foreach ($headings as $heading) {
        if (!str_starts_with($heading, '# ')) {
            continue;
        }

        $violations[] = $path . ' carries the layout B title heading "' . $heading . '"';
    }

    if (str_starts_with($headings[0] ?? '', '## ')) {
        return $violations;
    }

    return [...$violations, $path . ' does not open with a `## ` section'];
}

test('no skill uses the legacy layout B body (issue #278)', function (): void {
    // `skill-creator` describes two body layouts. Layout B opens with a `# Title Case Name`
    // heading that only restates `name:`, followed by a `## Purpose` block restating
    // `description:`. Layout A is Constraints-first and carries no H1 at all, so the absence of a
    // body-level H1 is what separates the two.
    $violations = [];

    foreach (skillEntrypointFiles() as $path => $content) {
        $violations = [...$violations, ...skillLayoutViolations($path, $content)];
    }

    expect($violations)->toBe([]);
});

test('every skill carries the packaged license and metadata.author values (issue #278)', function (): void {
    // `skill-check` validates `name` and `description` but not these two, so a skill authored
    // without them stays green forever — `skills/code-review/SKILL.md` shipped without either
    // until issue #278. This guard makes the omission fail the build instead.
    //
    // Both halves pin the value this package ships, not merely the presence of the key: every
    // skill here is authored by the package owner under one licence, so a divergent value is as
    // much a defect as a missing line. A fork changes both expectations together.
    $missing = [];

    foreach (array_keys(skillEntrypointFiles()) as $path) {
        $frontmatter = ruleExtensionFrontmatter(dirname(__DIR__, 2) . '/' . $path);

        if (!str_contains($frontmatter, "\nlicense: MIT")) {
            $missing[] = $path . ' (license: MIT)';
        }

        // The author value is quoted in most files and bare in a few; both are valid YAML, so the
        // guard accepts either quoting style around the one packaged author value.
        if (preg_match('/\nmetadata:\n {2}author: "?Petr Král \(pekral\.cz\)"?/', $frontmatter) === 1) {
            continue;
        }

        $missing[] = $path . ' (metadata.author: Petr Král (pekral.cz))';
    }

    expect($missing)->toBe([]);
});
