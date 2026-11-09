<?php

declare(strict_types = 1);

test('every agent cleans up its temporary files, worktrees, and local branches when it finishes a task', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $git = (string) file_get_contents($packageDir . '/rules/git/general.md');
    $orchestration = (string) file_get_contents($packageDir . '/rules/compound-engineering/orchestration.md');

    expect($git)->toContain('- **Clean up after the task — on every terminating path.**')
        ->and($git)->toContain('When a run ends — the final report, a `Blocked` stop, or an abort')
        ->and($git)->toContain('run `git worktree unlock <path>` when it is locked, then `git worktree remove <path>` and `git worktree prune`')
        ->and($git)->toContain('the patch-identity proof of `@skills/cleanup-local-branches/SKILL.md` step 5')
        ->and($git)->toContain('- **The owner removes, the others hand back.**')
        ->and($git)->toContain('is never removed with `--force` or `-D`')
        ->and($git)->not->toContain("paths:\n");
    expect($orchestration)->toContain('- **Worktrees and local branches the run created** — removed once their work is safe');
});
