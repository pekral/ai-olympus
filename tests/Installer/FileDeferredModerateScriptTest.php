<?php

declare(strict_types = 1);

// The round-3 deferral write. Per the project's test-isolation rule a Pest test cannot exec a real
// .sh, so these guards pin the script's behaviour in its source rather than running it.

test('file-deferred-moderate passes every String and ID GraphQL variable as a raw string', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/skills/process-code-review/scripts/file-deferred-moderate.sh');

    // `-F` sends a typed value: it coerces an all-digit owner or repository name to an integer that
    // GitHub rejects against `String!`, and it reads a leading `@` as a local file path, which would
    // send that file's contents to api.github.com for an attacker-influenced PARENT.
    foreach (['-F owner=', '-F repo=', '-F parent=', '-F child='] as $typed) {
        expect($content)->not->toContain($typed);
    }

    // The same four variables are passed as raw strings instead.
    foreach (['-f owner="$OWNER"', '-f repo="$REPO"', '-f parent="$PARENT_ID"', '-f child="$CHILD_ID"'] as $raw) {
        expect($content)->toContain($raw);
    }

    // Only the `Int!` variable legitimately stays typed.
    expect($content)->toContain('-F n="$NUMBER"');
    expect($content)->toContain('-F n="$CHILD_NUMBER"');

    // The dry-run rehearsal prints the command the script actually runs, so it carries `-f` too.
    expect($content)->toContain('-f parent=$PARENT_ID -f child=<CHILD_ID>');

    // The reason is recorded next to the calls, so a later edit does not reintroduce `-F`.
    expect($content)->toContain('it reads a leading `@` as "take this value from that');
});

test('file-deferred-moderate never exits silently once the child issue exists', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/skills/process-code-review/scripts/file-deferred-moderate.sh');

    // `set -e` plus a discarded stderr would end the run at exit 1 — documented as a usage error —
    // and take the created issue's URL with it. Every call after `gh issue create` therefore keeps
    // its stderr and handles its own failure.
    expect($content)->not->toContain('2>/dev/null)');

    // Each post-create failure names the child issue URL and exits with the documented code 4.
    foreach ([
        'created $CHILD_URL but the node-id lookup failed',
        'created $CHILD_URL but could not resolve its node id',
        'created $CHILD_URL but addSubIssue failed against $PARENT',
        'created $CHILD_URL but the parent re-read failed',
        'created $CHILD_URL but $PARENT does not list it as a sub-issue',
    ] as $message) {
        expect($content)->toContain($message);
    }

    // The parent read happens before anything is created, so it keeps exit 3 and reports gh's own
    // error text rather than a bare "failed".
    expect($content)->toContain('failed to read parent issue $PARENT: $PARENT_JSON');

    // A `jq` run over a non-JSON body must not become the silent exit-1 path either: every one of
    // them is guarded, so the empty result reaches the explicit message below it.
    expect(substr_count($content, '2>/dev/null || true)'))->toBe(substr_count($content, 'jq -r'));
});
