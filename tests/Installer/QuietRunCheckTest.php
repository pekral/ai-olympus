<?php

declare(strict_types = 1);

// The quiet-run check (issue #329). Per the project's test-isolation rule a Pest test cannot exec a real
// .sh, so the behavioural proof lives in the script's own `--self-test` (the precedent is
// `skills/_shared/check-cr-header.sh`). The pins below fix the contract that self-test enforces, the wiring
// that makes it run in the build, and every quiet review path that has to call it.

function quietRunCheckSource(string $relativePath): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
}

test('the quiet-run check is shipped, executable, self-testing, and run by the build', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $script = $packageDir . '/skills/_shared/quiet-run-check.sh';

    expect(is_file($script))->toBeTrue();
    expect(is_executable($script))->toBeTrue();

    expect(quietRunCheckSource('skills/_shared/quiet-run-check.sh'))
        ->toStartWith('#!/usr/bin/env bash')
        ->toContain('set -euo pipefail')
        ->toContain('--self-test) self_test; return $? ;;');

    /** @var array{scripts: array<string, array<int, string>>} $composer */
    $composer = json_decode((string) file_get_contents($packageDir . '/composer.json'), associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);

    expect(substr_count(implode(' ', $composer['scripts']['shell-self-tests']), ' skills/_shared/quiet-run-check.sh '))->toBe(1);
});

test('the script documents the three exit codes of the issue and prints the lines the callers read', function (): void {
    $script = quietRunCheckSource('skills/_shared/quiet-run-check.sh');

    expect(substr_count($script, '#   0  nothing was published and the head did not move'))->toBe(1)
        ->and(substr_count($script, '#   1  a comment was added during the run, by any author; every new comment is printed'))->toBe(1)
        ->and(substr_count($script, '#   3  nothing was published, but the head moved during the review; `head: moved <before> → <now>`'))->toBe(1)
        ->and(substr_count($script, 'printf \'head: moved %s → %s\\n\' "$head_before" "$head_now"'))->toBe(1)
        ->and(substr_count($script, 'printf \'result: quiet-head-moved\\n\''))->toBe(1)
        ->and(substr_count($script, 'lines="${lines}new-comment: $url"'))->toBe(2);
});

test('the script only reads: it names no tracker write and reads no environment value', function (): void {
    $script = quietRunCheckSource('skills/_shared/quiet-run-check.sh');

    expect($script)->not->toContain('upsert-comment.sh')
        ->and($script)->not->toContain('--method')
        ->and($script)->not->toContain('gh issue comment')
        ->and($script)->not->toContain('printenv')
        ->and($script)->not->toMatch('/\$\{?(GITHUB_LOADER|JIRA_LOADER)[:-]/')
        ->and(substr_count($script, 'GITHUB_LOADER="$HERE/../code-review-github/scripts/load-issue.sh"'))->toBe(1)
        ->and(substr_count($script, 'JIRA_LOADER="$HERE/../code-review-jira/scripts/load-issue.sh"'))->toBe(1);
});

test('a tracker the script cannot count goes under not-verified with the step that settles it', function (): void {
    $script = quietRunCheckSource('skills/_shared/quiet-run-check.sh');
    $scope = quietRunCheckSource('skills/process-code-review/references/review-loop-scope.md');

    expect(
        substr_count(
            $script,
            'settled by: open the error in the Bugsnag dashboard and check that its comment list has no entry newer than the start of the run\'',
        ),
    )->toBe(
        1,
    )
        ->and(substr_count($script, 'settled by: list the comments of the JIRA issue with acli'))->toBe(1)
        ->and(substr_count($script, '#   over exit 3 when both happened. An item on a `not-verified:` line never changes the exit code'))->toBe(1)
        ->and(substr_count($scope, '5. **Every `not-verified:` line goes under *Not verified* of the report**, with its own `settled by:` step.'))->toBe(
            1,
        );
});

test('the self-test covers every scenario of the assignment', function (): void {
    $script = quietRunCheckSource('skills/_shared/quiet-run-check.sh');

    expect($script)
        ->toContain('run_verify \'an unchanged state is a quiet run\' 0')
        ->toContain('run_verify \'a new pull request comment is a publish\' 1')
        ->toContain('run_verify \'a new review submission is a publish\' 1')
        ->toContain('run_verify \'a new line-anchored review comment is a publish\' 1')
        ->toContain('run_verify \'a new comment on the closing issue is a publish\' 1')
        ->toContain('run_verify \'a moved head without a publish is exit 3\' 3')
        ->toContain('run_verify \'a publish with a moved head is exit 1\' 1')
        ->toContain('run_verify \'a removed comment is not a publish\' 0')
        ->toContain('run_verify \'a comment without a URL is still counted\' 1')
        ->toContain('run_verify \'a new JIRA comment is a publish with its URL\' 1')
        ->toContain('run_verify \'an empty JIRA list is not verified, never quiet\' 0')
        ->toContain('run_verify \'an empty JIRA list recorded before the run is not verified\' 0')
        ->toContain('refuse_state \'a state path outside any git checkout is refused\'')
        ->toContain('refuse_state \'a state path inside the repository but outside the run directory is refused\'')
        ->toContain('refuse_state \'a ".." component in a missing directory is refused\'')
        ->toContain('refuse_state \'a state path that is a symbolic link is refused\'')
        ->toContain('refuse_state \'a state path that git tracks is refused\'')
        ->toContain('run_verify \'a failed JIRA read is exit 4\' 4')
        ->toContain('run_verify \'a Bugsnag item goes under not-verified with its step\' 0')
        ->toContain('expect \'an issue URL as --pr is refused\' 2')
        ->toContain('expect \'a missing state file is exit 4\' 4')
        ->toContain('expect \'a failed read at record is exit 4 and writes no state\' 4');
});

test('the loop reference owns the contract: what to run, how to read each exit code, and how to mark the report', function (): void {
    $scope = quietRunCheckSource('skills/process-code-review/references/review-loop-scope.md');

    expect(substr_count($scope, '#### Prove that a quiet run published nothing'))->toBe(1)
        ->and(
            substr_count(
                $scope,
                'run `skills/_shared/quiet-run-check.sh record --pr <PR-URL> --state .claude/run/quiet-run/<source-slug>.json` (`.codex/run/` under Codex)',
            ),
        )->toBe(
            1,
        )
        ->and(substr_count($scope, 'run `skills/_shared/quiet-run-check.sh verify --state <the same file>`'))->toBe(1)
        ->and(substr_count($scope, '`Quiet run: verified — nothing published, head <sha> unchanged`'))->toBe(1)
        ->and(substr_count($scope, '`The review covers <before>, the head is now <now>.`'))->toBe(1)
        ->and(substr_count($scope, 'gets ` [not published — quiet run]` appended. The wording is never rewritten'))->toBe(1)
        ->and(substr_count($scope, '   - `1` — a comment was added during the run, by any author.'))->toBe(1)
        ->and(substr_count($scope, 'call it this run\'s publish only when the marker and the account are the run\'s own'))->toBe(1)
        ->and(substr_count($scope, 'A comment body that a later step publishes is stored without them'))->toBe(1)
        ->and($scope)->not->toContain('— the run published.')
        ->and(substr_count($scope, '   - `2` or `4` — the check did not run to its end. Mark nothing as verified'))->toBe(1)
        ->and(substr_count($scope, '4. **On exit `0` or `3`, mark every header line of the report that claims a publish.**'))->toBe(1);
});

test('every quiet review path calls the check and reports its result', function (): void {
    $process = quietRunCheckSource('skills/process-code-review/SKILL.md');
    $leonardo = quietRunCheckSource('agents/leonardo.md');
    $contract = quietRunCheckSource('skills/code-review-github/references/cr-wrapper-contract.md');

    // The quiet loop iterations of process-code-review.
    expect(substr_count($process, 'Each quiet iteration is bracketed by `skills/_shared/quiet-run-check.sh record` and `verify`'))->toBe(1)
        ->and(substr_count($process, 'Bracket the call with `skills/_shared/quiet-run-check.sh record` and `verify`'))->toBe(1)
        ->and(substr_count($process, '*Prove that a quiet run published nothing*) and report its result with the round.'))->toBe(1);

    // The quiet leonardo round.
    expect(substr_count($leonardo, 'Before the wrapper starts run `skills/_shared/quiet-run-check.sh record`, and after it returns run `verify`'))->toBe(1)
        ->and(substr_count($leonardo, 'the result of `quiet-run-check.sh verify` as its own field beside the body'))->toBe(1)
        ->and(substr_count($leonardo, 'stored without any quiet-run mark (never `Quiet run: verified …`, never ` [not published — quiet run]`)'))->toBe(1)
        ->and(substr_count($leonardo, 'as a comment added during the run by any author and not yet attributed to this run'))->toBe(1)
        ->and(substr_count($leonardo, 'run `skills/_shared/quiet-run-check.sh` (`record` and `verify`) only as'))->toBe(1)
        ->and(substr_count($leonardo, 'writes only the state file under `.claude/run/quiet-run/`, and never writes to a tracker'))->toBe(1)
        ->and(substr_count($leonardo, 'the quiet-run state `skills/_shared/quiet-run-check.sh` writes under `.claude/run/quiet-run/`'))->toBe(1);

    // The wrapper that renders the header lines the mark applies to.
    expect(substr_count($contract, 'appends ` [not published — quiet run]` to each header line of the returned report that claims a publish'))->toBe(1);
});

test('record writes its state only where review-state.sh writes its own, through one shared guard', function (): void {
    $script = quietRunCheckSource('skills/_shared/quiet-run-check.sh');
    $reviewState = quietRunCheckSource('skills/_shared/review-state.sh');
    $guard = quietRunCheckSource('skills/_shared/state-path.sh');

    expect(substr_count($script, 'problem="$(state_path_problem "$state" create)" || die "$problem" 2'))->toBe(1)
        ->and(substr_count($script, '. "$HERE/state-path.sh"'))->toBe(1)
        ->and(substr_count($reviewState, 'problem="$(state_path_problem "$1")" || fail 3 "$problem"'))->toBe(1)
        ->and(substr_count($reviewState, '. "$HERE/state-path.sh"'))->toBe(1)
        ->and($guard)->toContain('the state file is a symbolic link')
        ->and($guard)->toContain('the state must live under .claude/run/ or .codex/run/ of the git toplevel')
        ->and($guard)->toContain('the state file is tracked by git')
        ->and($reviewState)->not->toContain('git -C "$top" ls-files --error-unmatch');
});

test('the check needs nothing platform-specific on Codex', function (): void {
    $script = quietRunCheckSource('skills/_shared/quiet-run-check.sh');

    // The Codex adapters load the canonical Markdown and map two things: the run directory and a bare
    // `skills/<path>` script. The check uses only those two, plus `gh` and `jq`, so no adapter changes.
    foreach (['leonardo', 'donatello'] as $role) {
        $adapter = quietRunCheckSource('codex/agents/' . $role . '.toml');

        expect(substr_count($adapter, '`.claude/run/` means `.codex/run/`.'))->toBe(1)
            ->and(substr_count($adapter, 'A bare `skills/<path>` reference to a shipped script'))->toBe(1);
    }

    expect($script)->not->toContain('CLAUDE_')
        ->and($script)->not->toContain('CODEX_')
        ->and(substr_count($script, '(.codex/run/ under Codex; git-ignored scratch,'))->toBe(1);
});
