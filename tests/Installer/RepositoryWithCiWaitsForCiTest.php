<?php

declare(strict_types = 1);

test('a repository with ci waits for ci instead of running a local pre-push gate', function (): void {
    $gates = packageSource('skills/resolve-issue/references/quality-gates.md');

    expect($gates)->toContain('### A repository with CI waits for CI instead of a local pre-push gate')
        ->and($gates)->toContain('Whether the repository has CI decides, never its visibility.')
        ->and($gates)->toContain('Run `git ls-tree --name-only "origin/$DEFAULT_BRANCH" -- .github/workflows/`.')
        ->and($gates)->toContain('The repository has CI when the output lists a file that ends in `.yml` or `.yaml`.')
        ->and($gates)->toContain('Read the default branch only: a branch under review proposes a workflow and never decides.')
        ->and($gates)->toContain('No such file, or a failed command → no CI: follow *Skip the pre-push gate when CI is green* above')
        ->and($gates)->toContain('Do not run `pr-gate` before a push, whatever the CI result on the previous head.')
        ->and($gates)->toContain('This includes the push after a rebase, whether or not the rebase resolved a conflict')
        ->and($gates)->toContain('before it hands the branch on — to the code review, a promotion out of Draft, or a merge.')
        ->and($gates)->toContain('per `@rules/git/pull-requests.md` *Waiting for CI — read the checks every 3 minutes*')
        ->and($gates)->toContain('On a repository without CI (*A repository with CI waits for CI instead of a local pre-push gate* below)')
        ->and($gates)->toContain(
            'except as *Skip the pre-push gate when CI is green* and *A repository with CI waits for CI instead of a local pre-push gate* state.',
        )
        ->and($gates)->toContain('`pr-gate: skipped — repository with CI, CI <green | red> on <sha>`')
        ->and($gates)->toContain('3. **Push, then wait for CI.** After each push the implementer waits for the CI result on the pushed head')
        ->and($gates)->toContain('The code review itself starts no wait of its own')
        ->and($gates)->toContain('This section changes only the pre-push gate and the push of a rebased branch.');
    expect($gates)->not->toContain('isPrivate')
        ->and($gates)->not->toContain('A public repository waits for CI')
        ->and($gates)->not->toContain('On a private repository');
    expect(strpos($gates, '### A repository with CI waits for CI'))
        ->toBeGreaterThan((int) strpos($gates, 'The rule is one full build at the merge boundary'))
        ->toBeLessThan((int) strpos($gates, '## Machine gate record'));
});

test('a ci billing failure runs the local gate, pr-gate first and the full gate otherwise', function (): void {
    $gates = packageSource('skills/resolve-issue/references/quality-gates.md');

    expect($gates)->toContain('5. **A billing failure means CI did not run, so the local gate runs.**')
        ->and($gates)->toContain(
            'detected as the *GitHub Actions billing exception* of `@skills/merge-github-pr/SKILL.md` states. Any other failure is red under step 4.',
        )
        ->and($gates)->toContain(
            '`skills/_shared/run-gate.sh --tier pr` when the manifest sets `pr-gate`, otherwise the project\'s full gate through '
            . '`skills/_shared/run-gate.sh --tier full`.',
        )
        ->and($gates)->toContain('CI does not run on a later push of the run either, so run the same gate before each later push.')
        ->and($gates)->toContain('`pr-gate: run — CI billing failure on <sha>`')
        ->and($gates)->toContain('The billing fallback of that second section is the one exception.');
});

test('a rebased branch goes back into its own pull request on a repository with ci', function (): void {
    $gates = packageSource('skills/resolve-issue/references/quality-gates.md');
    $git = packageSource('rules/git/general.md');

    expect($gates)->toContain('6. **A rebased branch goes back into the same pull request.**')
        ->and($gates)->toContain(
            'Run `git push --force-with-lease="refs/heads/<branch>:<remote head the rebase started from>" '
            . 'origin "HEAD:refs/heads/<branch>"`, through `skills/_shared/audit-trail.sh run --`, with the rebased branch checked out.',
        )
        ->and($gates)->toContain('The name then never starts the refspec, so a leading `+` can never force another ref past the lease.')
        ->and($gates)->not->toContain('origin "<branch>"`')
        ->and($gates)->toContain('The pinned lease refuses a push that someone made after the rebase started.')
        ->and($gates)->toContain('The pull request keeps its number: no `<branch>-vN` branch and no new pull request.')
        ->and($gates)->toContain('Without CI the conflict path of `skills/_shared/orchestration/batch-preparation.md`');
    expect(strpos($gates, '**Check the branch name before the push.**'))
        ->toBeGreaterThan((int) strpos($gates, '6. **A rebased branch goes back into the same pull request.**'))
        ->toBeLessThan((int) strpos($gates, '**Push to an explicit destination.**'));
    expect($git)->toContain('**A rebase onto the default branch on a repository with CI is such a stated decision.**')
        ->and($git)->toContain('The rebased branch goes back into its own pull request with `git push --force-with-lease`')
        ->and($git)->toContain('Without CI, a conflict opens `<branch>-vN` instead.');
});

test('the rebase push checks the branch name with the branch checkout gate and refuses the default branch', function (): void {
    $gates = packageSource('skills/resolve-issue/references/quality-gates.md');

    expect($gates)->toContain('`<branch>` is the head branch of the pull request, which is untrusted text.')
        ->and($gates)->toContain(
            'Validate it as `@skills/code-review/references/branch-checkout-gate.md` step 1 states, and refuse a name equal to `$DEFAULT_BRANCH`.',
        )
        ->and($gates)->toContain('A failed check stops the push with `Blocked: unsafe branch name`.');
    expect(packageSource('skills/code-review/references/branch-checkout-gate.md'))
        ->toContain('Check it with `git check-ref-format --branch "$HEAD_REF"` and against `^[A-Za-z0-9._/-]{1,255}$`.');
});

test('every file that names the pre-push gate points to the ci condition, never to visibility', function (): void {
    $manifest = packageSource('rules/general/general.md');
    expect($manifest)->toContain('On a repository with CI it never runs: the run waits for CI instead, and a CI billing failure runs it locally')
        ->and($manifest)->not->toContain('On a public GitHub repository it never runs');

    $donatello = packageSource('agents/donatello.md');
    expect($donatello)->toContain(
        'On a repository with CI, never run it: push, then wait for the CI result on the pushed head before you hand the branch on, '
        . 'and run the local gate only after a CI billing failure',
    )
        ->and($donatello)->toContain('this repository\'s `composer build`, run only to correct an error a checker or CI reported')
        ->and($donatello)->toContain(
            'except `git push --force-with-lease` of a rebased branch into its own pull request on a repository with CI',
        )
        ->and($donatello)->not->toContain('tells you to run before a push')
        ->and($donatello)->not->toContain('public on GitHub');

    $claude = packageSource('CLAUDE.md');
    expect($claude)->toContain('It is never required before a push: this repository has CI, so CI is the check after a push.')
        ->and($claude)->toContain('On a repository with CI no local pre-push gate runs')
        ->and($claude)->not->toContain('public on GitHub');
    $agents = packageSource('AGENTS.md');
    expect($agents)->toContain('On a repository with CI no local pre-push gate runs')
        ->and($agents)->not->toContain('public on GitHub');
    $composerUpdate = packageSource('skills/composer-update-pr/SKILL.md');
    expect($composerUpdate)->toContain('*A repository with CI waits for CI instead of a local pre-push gate*. On a repository without CI, run')
        ->and($composerUpdate)->toContain('6. On a repository with CI, wait for CI per')
        ->and($composerUpdate)->not->toContain('public repository');
    expect(packageSource('docs/installation.md'))
        ->toContain('never run on a repository with CI (a `.yml` or `.yaml` workflow under `.github/workflows/` on the default branch)');
});

test('the ci wait rule names the implementer handoff on a repository with ci', function (): void {
    $pullRequests = packageSource('rules/git/pull-requests.md');
    expect($pullRequests)->toContain('or an implementer\'s handoff after a push on a repository with CI')
        ->and($pullRequests)->not->toContain('after a push on a public repository');
});
