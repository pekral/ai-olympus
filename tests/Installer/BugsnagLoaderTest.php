<?php

declare(strict_types = 1);

// The Bugsnag loader talks to a remote API over several HTTP responses, and what
// it does across those responses — following a `Link: rel="next"` header, stopping
// at the first project page that carries the slug, keeping a pruned event from
// aborting the document — is what actually breaks. Per the project's test-isolation
// rule a Pest test cannot exec a real .sh against the network, so the behavioural
// proof lives in the script's own `--self-test` (the precedent is
// `skills/github-issue-triage/scripts/assign-priorities.sh --self-test`) and these
// guards pin the scenarios that self-test is required to cover. Pinning source
// patterns instead would prove only that a line exists, never that a second page
// is ever requested.

test('the Bugsnag loader is shipped, executable, and offers a self-test', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $script = $packageDir . '/skills/code-review-bugsnag/scripts/load-issue.sh';

    expect(is_file($script))->toBeTrue();
    expect(is_executable($script))->toBeTrue();

    $content = (string) file_get_contents($script);
    expect($content)->toStartWith('#!/usr/bin/env bash');
    expect($content)->toContain('set -euo pipefail');
    expect($content)->toContain('--self-test');
    expect($content)->toContain('load-issue.sh --self-test');
});

test('the loader self-test runs the script against a stubbed curl, not against the network', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/skills/code-review-bugsnag/scripts/load-issue.sh');

    // The stub answers from a routing table so a case can serve two pages of one
    // endpoint; an unrouted URL is a failed test, never a 404, so a request the
    // case did not expect surfaces instead of being absorbed.
    expect($content)->toContain('BSNAG_STUB_ROUTES');
    expect($content)->toContain('curl stub: no route for $url');
    expect($content)->toContain('Link: <%s>; rel="next"');
    expect($content)->toContain('BUGSNAG_TOKEN=\'stub-token\'');
});

test('the loader self-test covers the multi-response behaviour of the fetch chain', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/skills/code-review-bugsnag/scripts/load-issue.sh');

    // Each label is one case the self-test must run. The project-paging pair is
    // the safety net the shared page fetch is extracted under: one case proves a
    // second page is requested, the other that paging stops at the first match.
    $cases = [
        'loads the error, its project and its comments',
        'stops paging projects at the first match',
        'degrades a pruned latest event to null',
        'aborts when the comments request fails',
        'reports an unknown organization slug',
        'reports a project slug that is on no page',
        'reports the HTTP cause when a projects page fails',
    ];

    foreach ($cases as $label) {
        expect($content)->toContain('\'' . $label . '\'');
    }
});

test('the loader self-test runs as part of the project build', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $composer = (array) json_decode((string) file_get_contents($packageDir . '/composer.json'), associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);

    /** @var array<string, array<int, string>> $scripts */
    $scripts = $composer['scripts'];

    expect($scripts['shell-self-tests'])->toContain('bash skills/code-review-bugsnag/scripts/load-issue.sh --self-test');
    expect($scripts['check'])->toContain('@shell-self-tests');
});
