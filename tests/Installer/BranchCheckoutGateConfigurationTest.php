<?php

declare(strict_types = 1);

use Symfony\Component\Process\Process;

test('no review or walkthrough text switches the main tree to the head of a pull request', function (string $path): void {
    $text = normalizedPackageSource($path);

    expect($text)->not->toContain('gh pr checkout')
        ->and($text)->not->toContain('git checkout -B')
        ->and($text)->not->toContain('git checkout "$HEAD_REF"')
        ->and($text)->not->toContain('git switch <')
        ->and($text)->not->toContain('git pull --ff-only')
        ->and($text)->not->toContain('owns the main working tree')
        ->and($text)->not->toContain('Switching to the relevant branch')
        ->and($text)->not->toContain('Checking out the relevant branch');
})->with([
    'agents/casey.md',
    'agents/krang.md',
    'agents/leonardo.md',
    'agents/raphael.md',
    'agents/splinter.md',
    'skills/_shared/orchestration/batch-preparation.md',
    'skills/_shared/orchestration/review-only-mode.md',
    'skills/api-review/SKILL.md',
    'skills/code-review/SKILL.md',
    'skills/code-review/references/branch-checkout-gate.md',
    'skills/code-review-github/references/cr-wrapper-contract.md',
    'skills/interactive-testing/SKILL.md',
    'skills/mysql-problem-solver/references/tinker-explain.md',
    'skills/security-review/SKILL.md',
    'skills/test-assignment/SKILL.md',
]);

test('every read-only review skill reads the branch only in the worktree of the checkout gate', function (string $path): void {
    expect(normalizedPackageSource($path))->toContain(
        'Read the branch only in the detached worktree that `@skills/code-review/references/branch-checkout-gate.md` opens; '
        . 'never switch or pull the main tree.',
    );
})->with([
    'skills/api-review/SKILL.md',
    'skills/code-review/SKILL.md',
    'skills/code-review-github/references/cr-wrapper-contract.md',
    'skills/security-review/SKILL.md',
]);

test('every review or test worktree is checked and removed only through the worktree script', function (
    string $path,
    string $text,
    string $plainRemove = 'git worktree remove <path>',
): void {
    $source = normalizedPackageSource($path);

    expect($source)->toContain($text)
        ->and($source)->not->toContain($plainRemove)
        ->and($source)->not->toContain('unlock|remove|prune')
        ->and($source)->not->toContain('`git worktree unlock`, `git worktree remove`')
        ->and($source)->not->toContain('git -C <path> status');
})->with([
    'krang cleanup' => ['agents/krang.md', 'skills/_shared/branch-worktree.sh remove --path <path> --lock-pid <N>'],
    'leonardo standalone' => ['agents/leonardo.md', 'skills/_shared/branch-worktree.sh remove --path <path> --lock-pid $PPID'],
    'raphael grant' => ['agents/raphael.md', '`skills/_shared/branch-worktree.sh remove` on `.claude/worktrees/agent-test-<slug>-raphael` only'],
    'splinter grant' => ['agents/splinter.md', '`skills/_shared/branch-worktree.sh remove` on your own review worktree only'],
    'stale sweep' => ['skills/_shared/orchestration/stale-run-recovery.md', 'skills/_shared/branch-worktree.sh remove --path <path> --lock-pid <N>'],
    'walkthrough' => ['skills/interactive-testing/SKILL.md', 'skills/_shared/branch-worktree.sh remove --path <path> --lock-pid $PPID'],
    'cleanup rule' => [
        'rules/git/general.md',
        'Remove a review, test, or gate worktree only with `skills/_shared/branch-worktree.sh remove --path <path> --lock-pid <N>`',
        'then run `git worktree remove <path>`',
    ],
]);

test('no agent reads its review or test worktree with a plain git status', function (string $path, string $grant): void {
    expect(normalizedPackageSource($path))->toContain($grant);
})->with([
    'leonardo' => [
        'agents/leonardo.md',
        'run `git diff`, `git log`, `git show`, and every other read of your review worktree, such as `git status`, only as `skills/_shared/branch-worktree.sh git --path <path> -- <command>`',
    ],
    'raphael' => ['agents/raphael.md', 'only as `skills/_shared/branch-worktree.sh git --path <path> -- <command>`'],
    'splinter' => [
        'agents/splinter.md',
        'run `git diff`, `git log`, `git show`, and every other read of your review worktree, such as `git status`, only as `skills/_shared/branch-worktree.sh git --path <path> -- <command>`',
    ],
    'splinter diff' => ['agents/splinter.md', 'its diff through `skills/_shared/branch-worktree.sh git --path <path> -- diff <base>..<head>`'],
]);

test('the checkout gate reads the branch in a detached worktree and leaves the main tree on its branch', function (string $text): void {
    expect(normalizedPackageSource('skills/code-review/references/branch-checkout-gate.md'))->toContain($text);
})->with([
    'the head commit' => ['`HEAD_SHA` is the head commit the gate opens: the PR head SHA from the loaded context (`headRefOid`)'],
    'the later reads' => ['such as `git status`, `git diff`, `git show`, or `git log`, as `skills/_shared/branch-worktree.sh git --path <worktree> -- <command>`.'],
    'the one list of the mechanisms' => ['the table in its header is the one list of the git mechanisms that run a program'],
    'the open' => ['Run `skills/_shared/branch-worktree.sh open --path <worktree> --head "$HEAD_SHA"` in the main tree.'],
    'the principle' => ['**The main working tree is never checked out on a branch under review.**'],
    'the remote ref' => ['Require `git rev-parse "origin/$HEAD_REF"` to print `HEAD_SHA`.'],
]);

test('every worktree that reads a branch under review is created and moved only through the worktree script', function (string $path, string $text): void {
    expect(normalizedPackageSource($path))->toContain($text);
})->with([
    'leonardo creates' => [
        'agents/leonardo.md',
        '`skills/_shared/branch-worktree.sh open --path .claude/worktrees/agent-cr-<slug>-leonardo --head <head-sha> && git worktree lock',
    ],
    'leonardo moves' => ['agents/leonardo.md', 'move it to the new head with `skills/_shared/branch-worktree.sh open --path <path> --head <head-sha>`'],
    'raphael compares' => ['agents/raphael.md', 'with `skills/_shared/branch-worktree.sh open`, and never switch the main checkout;'],
    'splinter moves' => [
        'agents/splinter.md',
        'run `skills/_shared/branch-worktree.sh open` and `skills/_shared/branch-worktree.sh git` on your own review worktree only',
    ],
    'the walkthrough' => ['skills/interactive-testing/SKILL.md', '**The revision under test runs, and the main tree is never switched to it.**'],
]);

test('no agent moves a review worktree with a plain git checkout', function (string $path): void {
    expect(normalizedPackageSource($path))->not->toContain('checkout --detach <head-sha>');
})->with(['agents/leonardo.md', 'agents/splinter.md', 'agents/raphael.md']);

test('the worktree script runs no program of the branch through any mechanism of its table', function (): void {
    $selfTest = new Process([dirname(__DIR__, 2) . '/skills/_shared/branch-worktree.sh', '--self-test']);
    $selfTest->run();

    expect($selfTest->getExitCode())->toBe(0)
        ->and($selfTest->getOutput())->toContain('branch-worktree.sh self-test passed')
        ->and(packageSource('composer.json'))->toContain('skills/_shared/branch-worktree.sh');
});

test('no agent reads the diff of a branch under review with a plain git command', function (string $path, string $plain): void {
    expect(normalizedPackageSource($path))->not->toContain($plain);
})->with([
    'leonardo' => ['agents/leonardo.md', 'run read-only `git diff` / `git log` / `git show`'],
    'splinter grant' => ['agents/splinter.md', 'run read-only `git` (`diff`, `log`, `show`'],
    'splinter context' => ['agents/splinter.md', 'its diff through `git diff <base>..<head>`'],
]);
