<?php

declare(strict_types = 1);

test('every agent cleans up its temporary files, worktrees, and local branches when it finishes a task', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $git = (string) file_get_contents($packageDir . '/rules/git/general.md');
    $orchestration = (string) file_get_contents($packageDir . '/rules/compound-engineering/orchestration.md');

    expect($git)->toContain('- **Clean up after the task — on every terminating path.**')
        ->and($git)->toContain('the final handoff, a `Blocked` stop, or an abort')
        ->and($git)->toContain('2. every worktree it created whose work is safe: clean, and its `HEAD` merged or on the remote branch.')
        ->and($git)->toContain('3. every local branch it created whose commits are merged or on the remote.')
        ->and($git)->toContain('is never forced away (`--force`, `-D`)')
        ->and($git)->not->toContain("paths:\n");
    expect($orchestration)->toContain('- **Worktrees and local branches the run created** — removed once their work is safe');
});
