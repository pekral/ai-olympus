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

test('every skill carries the license and metadata frontmatter keys (issue #278)', function (): void {
    // `skill-check` validates `name` and `description` but not these two, so a skill authored
    // without them stays green forever — `skills/code-review/SKILL.md` shipped without either
    // until issue #278. This guard makes the omission fail the build instead.
    $missing = [];

    foreach (array_keys(skillEntrypointFiles()) as $path) {
        $frontmatter = ruleExtensionFrontmatter(dirname(__DIR__, 2) . '/' . $path);

        if (!str_contains($frontmatter, "\nlicense: MIT")) {
            $missing[] = $path . ' (license)';
        }

        // The author value is quoted in most files and bare in a few; both are valid YAML, so the
        // guard pins the key, never the quoting style.
        if (preg_match('/\nmetadata:\n {2}author: "?Petr Král \(pekral\.cz\)"?/', $frontmatter) === 1) {
            continue;
        }

        $missing[] = $path . ' (metadata.author)';
    }

    expect($missing)->toBe([]);
});
