<?php

declare(strict_types = 1);

// The gate this file pins is the only one in the pipeline whose tool is not a Composer package,
// so it is also the only one that can silently stop running — either by being dropped from
// `@check`, or by being rewritten into a shape that reports success when it did not lint.

test('the shellcheck gate is wired into the project build and cannot pass by accident', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $composer = (array) json_decode((string) file_get_contents($packageDir . '/composer.json'), associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);

    /** @var array<string, array<int, string>> $scripts */
    $scripts = $composer['scripts'];
    $step = implode("\n", $scripts['shellcheck']);

    expect($scripts['check'])->toContain('@shellcheck');

    // The skip is a guard on the binary being absent, never a `|| echo` fallback — that shape
    // would turn every real finding into a skip message and a zero exit code.
    expect($step)->toContain('if ! command -v shellcheck');
    expect($step)->not->toContain('|| echo');

    // `skills/**/*.sh` is not recursive in POSIX sh, so a glob would miss every script one level
    // deeper than `skills/<skill>/` — which is nearly all of them.
    expect($step)->toContain('git ls-files -z \'*.sh\'');

    // `-x` is what makes the `# shellcheck source=` directives the scripts already carry mean
    // anything; without it every sourcing script reports SC1091 instead. `-P SCRIPTDIR` is what
    // makes those directives resolve — they are relative to the script, while ShellCheck's default
    // is the working directory, which is the repository root when this step runs.
    expect($step)->toContain('shellcheck -x -P SCRIPTDIR');
});

test('CI runs the shellcheck gate, so a missing local binary never removes the check', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $workflow = (string) file_get_contents($packageDir . '/.github/workflows/pr.yml');

    // The local step skips when the binary is absent, so CI is where the gate is always enforced.
    expect($workflow)->toContain('composer shellcheck');
});
