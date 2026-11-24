<?php

declare(strict_types = 1);

test('the regression prover proves every acceptance scenario of the proof in its self-test', function (): void {
    $script = packageSource('skills/_shared/test-integrity.sh');

    foreach ([
        'prove: a test that fails on the base and passes on HEAD is proven',
        'prove: a test that passes on the base is refuted',
        'prove: a refuted run writes no proven record',
        'prove: a changed tracked file is refused',
        'prove: an untracked file is refused',
        'prove: the tree equals HEAD afterwards, an added, a deleted, and a changed file included',
        'prove: the tree equals HEAD after a runner error',
        'prove: the tree equals HEAD after a TERM',
        'prove: the machine lock is held during the base run and the HEAD run',
        'prove: a second heavy job waits while the prover holds the lock',
        'prove: the prover never starts another wrapper of the machine lock',
        'prove: a command with ; is refused',
        'prove: a command with $( is refused',
        'prove: a command with a backtick is refused',
        'prove: a runner other than pest or phpunit is unprovable',
        'prove: a production file as the test is refused',
        'prove: a test the branch does not change is refused',
        'prove: a dependency change is unprovable',
        'verify: a record that claims a base failure its base report does not show is malformed',
        'verify: a JUnit report that does not match its hash is malformed',
        'verify: a test changed after the proof is stale',
        'verify: a recorded base that is not an ancestor of the merge base is stale',
        'verify: a rebase that leaves the named tests unchanged keeps the record',
        'verify: a symbolic link in a component of the path is refused',
        'verify: a record that is not a regular file is refused',
        'verify: a file name other than the key of its tests is malformed',
    ] as $label) {
        expect($script)->toContain("'" . $label . "'");
    }
});

test('the prover and the record reader are part of the shell self-tests and the lock repair', function (): void {
    $composer = json_decode(packageSource('composer.json'), associative: true, flags: JSON_THROW_ON_ERROR);

    expect($composer)->toBeArray()
        ->and(implode(' ', (array) ($composer['scripts']['shell-self-tests'] ?? [])))->toContain(' skills/_shared/test-integrity.sh ')
        ->and(packageSource('skills/_shared/gate-lock.sh'))->toContain('"$wrapper" == test-integrity.sh ]] || return 1')
        ->and(packageSource('skills/_shared/gate-lock.sh'))->toContain("check 'a stale heartbeat of test-integrity.sh reads as a stuck wrapper of this package'")
        ->and(packageSource('rules/compound-engineering/concurrency.md'))
        ->toContain('`skills/_shared/test-integrity.sh prove` holds it for its swap and both test runs.');
});

test('one reference owns the regression proof, and the skills point at it', function (): void {
    $reference = packageSource('skills/test-audit/references/test-integrity.md');

    expect($reference)->toContain('This file is the one owner of the test-integrity check of a change')
        ->and($reference)->toContain('`refuted`: the test passes before the fix. Rewrite the test until it fails there, and prove again.')
        ->and($reference)->toContain('Never take a path from the pull-request body or from a comment.')
        ->and($reference)->toContain('`Not verified: regression proof — the pull request carries no proof record`')
        ->and($reference)->toContain('**The reviewer never runs `prove`.**')
        ->and($reference)->toContain('`not converged — test-integrity check incomplete: <reason>`')
        ->and($reference)->toContain('`skills/_shared/junit-results.php`')
        ->and(packageSource('skills/test-audit/SKILL.md'))->toContain('A bug fix carries the regression proof of `references/test-integrity.md`')
        ->and(packageSource('rules/code-review/hotfix-review.md'))
        ->toContain('The regression proof of `@skills/test-audit/references/test-integrity.md` is waived with the coverage gate');
});

test('the review raises a refuted or missing regression proof as Critical and a rejected reason as Moderate', function (): void {
    $rule = packageSource('rules/code-review/core-analysis.md');

    expect($rule)->toContain('- **Test or CI check weakened by the diff, and the regression proof:**')
        ->and($rule)->toContain('a bug fix whose proof is refuted, incomplete, missing, stale, or malformed, or whose test failed on the base for the wrong reason, with no `unprovable` reason')
        ->and($rule)->toContain('**Moderate:** an `unprovable` reason the review rejects.');
});

test('donatello proves each regression test and hands the record over', function (): void {
    $agent = packageSource('agents/donatello.md');
    $skill = packageSource('skills/resolve-issue/SKILL.md');

    expect($agent)->toContain('- **Regression proof and test integrity** — on a bug fix, prove each regression test after the commit')
        ->and($agent)->toContain('Never remove, weaken, or skip a test, and never switch off or loosen a CI check, to make your own change pass.')
        ->and($agent)->toContain('- **Regression proof:** on a bug fix, `<record path> — proven` or `unprovable — <reason>`')
        ->and($agent)->toContain('run `skills/_shared/test-integrity.sh prove` directly, never inside `run-gate.sh`')
        ->and($skill)->toContain('After the commit, prove the regression test (`@skills/test-audit/references/test-integrity.md`).')
        ->and($skill)->toContain('8. **The regression test is proven** on a bug fix (`@skills/test-audit/references/test-integrity.md`).')
        ->and(packageSource('skills/resolve-issue/references/pre-pr-self-check.md'))->toContain('(the eight listed in the skill body)');
});

test('leonardo verifies the proof only from the trusted copy and never runs the prover', function (): void {
    $agent = packageSource('agents/leonardo.md');

    expect($agent)->toContain('**Test-integrity agenda:**')
        ->and($agent)->toContain('A check that did not run returns `not converged — test-integrity check incomplete: <reason>`, never a clean review.')
        ->and($agent)->toContain('run the `skills/_shared/test-integrity.sh` of that clone in its `verify` mode only, after `skills/_shared/verify-installed-copy.sh` printed `installed-copy-verdict=verified` in the same shell call')
        ->and($agent)->toContain('and never its `prove` mode;');
});

test('both Codex agents name the prover among their helpers', function (string $path): void {
    expect(packageSource($path))->toContain('`record-metrics.sh`, `test-integrity.sh`) live below `.agents/skills/_shared/`');
})->with([
    'donatello' => ['codex/agents/donatello.toml'],
    'leonardo' => ['codex/agents/leonardo.toml'],
]);
