<?php

declare(strict_types = 1);

test('every agent cleans up its temporary files, worktrees, and local branches when it finishes a task', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $git = (string) file_get_contents($packageDir . '/rules/git/general.md');
    $orchestration = (string) file_get_contents($packageDir . '/rules/compound-engineering/orchestration.md');

    expect($git)->toContain('- **Clean up after the task — on every terminating path.**')
        ->and($git)->toContain('When a run ends — the final report, a `Blocked` stop, or an abort')
        ->and($git)->toContain('unlock a locked worktree only when its lock reason names this run\'s own `$PPID`')
        ->and($git)->not->toContain('run `git worktree unlock <path>` when it is locked')
        ->and($git)->toContain('`@rules/compound-engineering/concurrency.md` *Parallel units*')
        ->and($git)->toContain('`agents/leonardo.md` *Review worktree*')
        ->and($git)->toContain('A worktree locked by another run is unlocked only by its owner, after the confirmed-dead probe')
        ->and($git)->toContain('`@skills/_shared/orchestration/stale-run-recovery.md` *Stale worktrees*')
        ->and($git)->toContain('A match with the pushed upstream proves only that the branch is pushed.')
        ->and($git)->toContain('`git merge-base --is-ancestor <branch> "origin/$DEFAULT_BRANCH"`, then run `git branch -d`')
        ->and($git)->not->toContain('`git branch -d` when it matches its pushed upstream')
        ->and($git)->toContain('the patch-identity proof of `@skills/cleanup-local-branches/SKILL.md` step 5')
        ->and($git)->toContain('- **The owner removes, the others hand back.**')
        ->and($git)->toContain('`krang` *Run cleanup* removes the review, parallel-unit, and gate worktrees of an orchestrated run')
        ->and($git)->toContain('is never removed with `--force` or `-D`')
        ->and($git)->not->toContain("paths:\n")
        ->and($git)->toContain('after the confirmed-dead probe of `krang` *Run cleanup*, `@skills/merge-github-pr/SKILL.md` *Post-merge*, and');
    $merge = (string) file_get_contents($packageDir . '/skills/merge-github-pr/SKILL.md');
    expect($merge)->toContain(
        'unlock it first with `git worktree unlock <path>` only when its lock reason names this run\'s own `$PPID`, '
        . 'otherwise only after the confirmed-dead probe of `skills/_shared/orchestration/stale-run-recovery.md` *Stale worktrees*; '
        . 'a live, EPERM, or unparseable probe leaves it in place and the report names it',
    )
        ->and($merge)->not->toContain('is such a worktree: unlock it first with `git worktree unlock <path>`, and remove it');
    expect($orchestration)->toContain('- **Worktrees and local branches the run created** — removed only by the owner that `@rules/git/general.md`')
        ->and($orchestration)->toContain('every other agent hands the path or branch back in its handoff.');
});
