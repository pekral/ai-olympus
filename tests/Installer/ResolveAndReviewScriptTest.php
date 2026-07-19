<?php

declare(strict_types = 1);

// The pipeline template that guarantees the canonical task-resolution order.
// Per the project's test-isolation rule a Pest test cannot exec a real .sh, so
// these guards pin the script's behaviour in its source rather than running it.

test('resolve-and-review pipeline script is shipped and executable', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $script = $packageDir . '/scripts/resolve-and-review.sh';

    expect(is_file($script))->toBeTrue();
    expect(is_executable($script))->toBeTrue();

    $content = (string) file_get_contents($script);
    // A robust bash wrapper: fail fast on errors, unset vars, and pipe failures.
    expect($content)->toContain('set -euo pipefail');
});

test('resolve-and-review script sequences the four pipeline skills in order', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/scripts/resolve-and-review.sh');

    // All three tracker-matching code-review wrappers are offered for step 2.
    expect($content)->toContain('/code-review-github');
    expect($content)->toContain('/code-review-jira');
    expect($content)->toContain('/code-review-bugsnag');

    // The printed plan lists the four numbered steps in the fixed order 1 -> 2 -> 3 -> 4
    // (step 2 renders the tracker-matching wrapper via the ${cr_skill} placeholder).
    expect($content)->toMatch('#1\. /resolve-issue[\s\S]*2\. \$\{cr_skill\}[\s\S]*3\. /process-code-review[\s\S]*4\. /merge-github-pr#');
});

test('resolve-and-review script detects the source tracker from the assignment reference', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/scripts/resolve-and-review.sh');

    // The detection maps the reference to one of the four supported source kinds.
    expect($content)->toContain('detect_tracker');
    expect($content)->toContain('atlassian.net');
    expect($content)->toContain('bugsnag.com');
    expect($content)->toContain('github.com');
});

test('resolve-and-review script gates the merge step behind an opt-in --merge flag', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/scripts/resolve-and-review.sh');

    // Merge is opt-in: the flag exists and the merge step is printed only when it is set.
    expect($content)->toContain('--merge');
    expect($content)->toContain('merge is opt-in');
});

test('resolve-and-review script is a print-only template that never invokes claude or a tracker CLI', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/scripts/resolve-and-review.sh');

    // The wrapper only prints the steps; it must never run the Claude binary in
    // headless mode, nor drive the tracker CLIs that would mutate state.
    expect($content)->not->toContain('claude -p');
    expect($content)->not->toContain('claude --print');
    expect($content)->not->toContain('gh pr ');
    expect($content)->not->toContain('gh issue ');
    expect($content)->not->toContain('acli ');
    // And it documents that print-only contract explicitly.
    expect($content)->toContain('does NOT invoke');
});
