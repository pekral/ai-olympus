<?php

declare(strict_types = 1);

// The batch ordering of `resolve-and-merge`. Per the project's test-isolation
// rule a Pest test cannot exec a real .sh, so the behaviour itself is proven by
// the script's own `--self-test`, which `composer build` runs via
// `@shell-self-tests` (the precedent is
// `skills/github-issue-triage/scripts/assign-priorities.sh`). These guards pin
// the two things that self-test cannot pin about itself — that it stays wired
// into the build, and which scenarios it is required to keep covering — plus
// the SKILL.md text, because #216 was a divergence between the documented
// taxonomy and the executed one that neither side could detect alone.

test('candidate selection script is shipped, executable, and offers a self-test', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $script = $packageDir . '/skills/resolve-and-merge/scripts/select-candidates.sh';

    expect(is_file($script))->toBeTrue();
    expect(is_executable($script))->toBeTrue();

    $content = (string) file_get_contents($script);
    expect($content)->toContain('set -euo pipefail');
    expect($content)->toContain('--self-test');
});

test('candidate selection ranks the priority labels this repository actually uses', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/skills/resolve-and-merge/scripts/select-candidates.sh');

    // The jq filter matches the four labels `github-issue-triage` seeds, with
    // the space after the colon that the live labels carry. Before #216 it
    // matched `^priority:p[0-3]$`, which no repository has ever had, so every
    // issue silently took the default level.
    expect($content)->toContain('test("^priority: *(critical|high|medium|low)$")');
    expect($content)->toContain('{"critical": 0, "high": 1, "medium": 2, "low": 3}');
    expect($content)->not->toContain('^priority:p[0-3]$');

    // An unlabeled issue ranks as `priority: medium` — the declared default —
    // expressed as the level itself rather than as a bare rank number, so the
    // default cannot drift away from the label it is supposed to mean.
    expect($content)->toContain('if $p == null then "medium" else $p end');
});

test('candidate selection self-test keeps covering the ordering scenarios', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/skills/resolve-and-merge/scripts/select-candidates.sh');

    // Each of these is a way the ordering has already been, or could silently
    // be, wrong — the self-test is not allowed to stop exercising any of them.
    expect($content)->toContain('expect_order \'critical, high, medium, unlabeled-as-medium, low\'');
    expect($content)->toContain('expect_order \'COUNT truncates after the ordering, not before\'');
    expect($content)->toContain('expect_order \'the retired priority:P0 taxonomy is not a priority label\'');
    expect($content)->toContain('expect_order \'a priority label written without the space still ranks\'');
    expect($content)->toContain('expect_order \'two priority labels on one issue keep the first match\'');
    expect($content)->toContain('expect_order \'the claim-label and EXCLUDE filters still hold\'');

    // The fixtures must reach the script through a stubbed `gh`, never through
    // a real network call — the self-test runs offline in `composer build`.
    expect($content)->toContain('gh stub: unexpected call');
});

test('candidate selection self-test runs as part of the project build', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $composer = (array) json_decode((string) file_get_contents($packageDir . '/composer.json'), associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);

    /** @var array<string, array<int, string>> $scripts */
    $scripts = $composer['scripts'];

    expect($scripts['shell-self-tests'])->toContain('bash skills/resolve-and-merge/scripts/select-candidates.sh --self-test');
    expect($scripts['check'])->toContain('@shell-self-tests');
});

test('resolve-and-merge skill documents the taxonomy the script reads', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/skills/resolve-and-merge/SKILL.md');

    // The four places the skill stated the retired taxonomy: the frontmatter
    // description, the step 3 selection paragraph, the step 5 queue order, and
    // the report table's Priority column.
    expect($content)->toContain('priority: critical before high before medium before low, oldest first within a priority');
    expect($content)->toContain('`priority: critical`, `priority: high`, `priority: medium`, `priority: low`, critical first');
    expect($content)->toContain(
        'Otherwise highest priority first (`priority: critical` → `priority: low`, an unlabeled issue sorting as `priority: medium`)',
    );
    expect($content)->toContain('critical / high / medium / low (or `—` when unlabeled)');

    // No P0..P3 vocabulary may come back into the skill text.
    expect($content)->not->toContain('priority:P0');
    expect($content)->not->toContain('P0–P3');
});
