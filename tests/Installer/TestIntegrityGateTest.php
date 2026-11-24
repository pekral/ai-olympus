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
        'prove: a production path whose local line a clean filter hides is refused',
        'prove: the blocked message advises no whole-tree restore',
        'verify: a record that claims a base failure its base report does not show is malformed',
        'verify: a JUnit report that does not match its hash is malformed',
        'verify: a test changed after the proof is stale',
        'verify: a recorded base that is not an ancestor of the merge base is stale',
        'verify: a rebase that leaves the named tests unchanged keeps the record',
        'verify: a symbolic link in a component of the path is refused',
        'verify: a record that is not a regular file is refused',
        'verify: a file name other than the key of its tests is malformed',
    ] as $label) {
        expect($script)->toContain('\'' . $label . '\'');
    }
});

test('the prover and the record reader are part of the shell self-tests and the lock repair', function (): void {
    expect(packageSource('composer.json'))->toContain(
        'skills/_shared/verify-installed-copy.sh skills/_shared/test-integrity.sh skills/_shared/gate-lock.sh',
    )
        ->and(packageSource('skills/_shared/gate-lock.sh'))->toContain('"$wrapper" == test-integrity.sh ]] || return 1')
        ->and(packageSource('skills/_shared/gate-lock.sh'))->toContain(
            'check \'a stale heartbeat of test-integrity.sh reads as a stuck wrapper of this package\'',
        )
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
        ->and($rule)->toContain(
            'a bug fix whose proof is refuted, incomplete, missing, stale, or malformed, or whose test failed '
            . 'on the base for the wrong reason, with no `unprovable` reason',
        )
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
        ->and($skill)->toContain('8. **Tests proven and intact** — `@skills/test-audit/references/test-integrity.md`.')
        ->and(packageSource('skills/resolve-issue/references/pre-pr-self-check.md'))->toContain('(the eight listed in the skill body)');
});

test('leonardo verifies the proof only from the trusted copy and never runs the prover', function (): void {
    $agent = packageSource('agents/leonardo.md');

    expect($agent)->toContain('**Test-integrity agenda:**')
        ->and($agent)->toContain('A check that did not run returns `not converged — test-integrity check incomplete: <reason>`, never a clean review.')
        ->and($agent)->toContain(
            'of that clone in its `verify` and `scan` modes only, after `skills/_shared/verify-installed-copy.sh` '
            . 'printed `installed-copy-verdict=verified` in the same shell call',
        )
        ->and($agent)->toContain('and never its `prove` mode;');
});

test('both Codex agents name the prover among their helpers', function (string $path): void {
    expect(packageSource($path))->toContain('`record-metrics.sh`, `test-integrity.sh`) live below `.agents/skills/_shared/`');
})->with([
    'donatello' => ['codex/agents/donatello.toml'],
    'leonardo' => ['codex/agents/leonardo.toml'],
]);

test('the scan proves every acceptance scenario of the weakening check in its self-test', function (): void {
    $script = packageSource('skills/_shared/test-integrity.sh');

    foreach ([
        'scan: a deleted test file is a candidate',
        'scan: a test file moved out of the test side is a candidate',
        'scan: a removed test declaration is a candidate',
        'scan: a removed assertion in a pre-existing test is a candidate',
        'scan: an added skip is a candidate',
        'scan: a skip written with spaces is a candidate',
        'scan: a skip written in another case is a candidate',
        'scan: a skip at the end of a chain is a candidate',
        'scan: an added exclude in the test configuration is a candidate',
        'scan: a deleted workflow is a candidate',
        'scan: a removed workflow line is a candidate',
        'scan: an added continue-on-error is a candidate',
        'scan: a lowered coverage minimum is a candidate',
        'scan: a removed Composer script entry is a candidate',
        'scan: a lowered PHPStan level is a candidate',
        'scan: a test file moved inside the test side unchanged is no candidate',
        'scan: a test the change adds is no candidate',
        'scan: the output carries no text of a diff line',
        'scan: the output carries no escape character',
        'scan: a binary diff of a test-side path is incomplete',
        'scan: a path with a control character is incomplete',
        'scan: a git error is incomplete, never clean',
        'scan: a jq error is incomplete, never clean',
    ] as $label) {
        expect($script)->toContain('\'' . $label . '\'');
    }
});

test('the review raises an unexplained weakened test or CI check as Critical and never twice', function (): void {
    $rule = packageSource('rules/code-review/core-analysis.md');
    $reference = packageSource('skills/test-audit/references/test-integrity.md');

    expect($rule)->toContain('runs on every review, a diff that changes no test included')
        ->and($rule)->toContain(
            '**Critical:** a test the diff removes, weakens, or skips, or a CI check it switches off or '
            . 'loosens, with no stated reason or a reason the review rejects',
        )
        ->and($rule)->toContain('**Not findings:** a test removed with the behaviour the assignment removed')
        ->and($rule)->toContain(
            'a suppression keeps the bullet above, a junk test the pull request adds keeps the `cr` lens of `@skills/test-audit/SKILL.md`',
        )
        ->and($rule)->toContain('the Assignment Conformance Gate does not raise the same line again')
        ->and(packageSource('skills/code-review/references/assignment-conformance-gate.md'))
        ->toContain('**Gating — a weakened test or CI check.**')
        ->and($reference)->toContain('`Test change: <path>[::<test>] — <kind> — <reason>`')
        ->and($reference)->toContain('**A weakened security check is a security finding.**')
        ->and($reference)->toContain('a `scan` whose last line is not `scan-verdict=complete`')
        ->and(packageSource('skills/resolve-issue/templates/pull-request-body.md'))->toContain('Test change: <path>[::<test>] — <kind> — <reason>');
});

test('a light round still runs the test-integrity check over the whole pull request', function (): void {
    expect(packageSource('rules/code-review/review-rounds.md'))
        ->toContain('Four are named because narrowing them would lose a real defect')
        ->toContain('the **test-integrity check** of `@skills/test-audit/references/test-integrity.md`');
});

test('leonardo scans every review and donatello states a reason for every candidate', function (): void {
    $leonardo = packageSource('agents/leonardo.md');
    $donatello = packageSource('agents/donatello.md');

    expect($leonardo)->toContain('**Test-integrity agenda:** on every review, a diff that changes no test included, run `scan`')
        ->and($leonardo)->toContain('in its `verify` and `scan` modes only')
        ->and($donatello)->toContain('Run its `scan` before the pull request and write a `Test change:` line with the reason for each candidate')
        ->and(packageSource('rules/code-review/hotfix-review.md'))->toContain('Its scan for a weakened test or CI check still runs.');
});

test('the scan reads the one test definition of the classifier from the trusted copy', function (): void {
    $script = packageSource('skills/_shared/test-integrity.sh');

    expect($script)->toContain('. "$TI_DIR/domain-vocabulary.sh"')
        ->and($script)->toContain('\'scan --without-review: the shared shapes complete with candidates\'')
        ->and(str_contains($script, 'TI_TEST_SIDE_RE'))->toBeFalse()
        ->and(str_contains(packageSource('skills/_shared/classify-risk.sh'), 'FORMAT_RE=\''))->toBeFalse()
        ->and(packageSource('skills/test-audit/references/test-integrity.md'))
        ->toContain('`skills/_shared/read-manifest.sh`, `skills/_shared/domain-vocabulary.sh`, and `skills/_shared/junit-results.php`');
});

test('a merge without a review takes the full path when the trusted scan lists a weakened test', function (string $path, string $pin): void {
    expect(packageSource($path))->toContain($pin)
        ->toContain('A `candidate` line, a last line other than `scan-verdict=complete`, or a copy that is not available')
        ->toContain('so only a change that touches none of them keeps the');
})->with([
    'FAST-tier exemption' => [
        'skills/merge-github-pr/references/merge-exemptions.md',
        '4. **The trusted test-integrity scan lists nothing.** A tests-only change classifies `FAST`',
    ],
    'low-risk path' => [
        'skills/merge-github-pr/references/low-risk-path.md',
        '5. Run `scan --base <base tip> --head <headRefOid> --without-review` of the trusted copy of `skills/_shared/test-integrity.sh` in the same clone',
    ],
]);
