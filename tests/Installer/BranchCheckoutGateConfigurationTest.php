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
        ->and($text)->not->toContain('owns the main working tree');
})->with([
    'agents/casey.md',
    'agents/krang.md',
    'agents/leonardo.md',
    'agents/raphael.md',
    'agents/splinter.md',
    'skills/_shared/orchestration/batch-preparation.md',
    'skills/_shared/orchestration/review-only-mode.md',
    'skills/code-review/SKILL.md',
    'skills/code-review/references/branch-checkout-gate.md',
    'skills/code-review-github/references/cr-wrapper-contract.md',
    'skills/interactive-testing/SKILL.md',
    'skills/mysql-problem-solver/references/tinker-explain.md',
    'skills/test-assignment/SKILL.md',
]);

test('the checkout gate reads the branch in a detached worktree and leaves the main tree on its branch', function (string $text): void {
    expect(normalizedPackageSource('skills/code-review/references/branch-checkout-gate.md'))->toContain($text);
})->with([
    'the head commit' => ['`HEAD_SHA` is the head commit the gate opens: the PR head SHA from the loaded context (`headRefOid`)'],
    'the later reads' => ['as `skills/_shared/branch-worktree.sh git --path <worktree> -- <command>`.'],
    'the one owner of the options' => ['it is the one owner of the git options that do this.'],
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
    'splinter moves' => ['agents/splinter.md', 'run `skills/_shared/branch-worktree.sh open` and `skills/_shared/branch-worktree.sh git` on your own review worktree only'],
    'the walkthrough' => ['skills/interactive-testing/SKILL.md', '**The revision under test runs, and the main tree is never switched to it.**'],
]);

test('no agent moves a review worktree with a plain git checkout', function (string $path): void {
    expect(normalizedPackageSource($path))->not->toContain('checkout --detach <head-sha>');
})->with(['agents/leonardo.md', 'agents/splinter.md', 'agents/raphael.md']);

test('the worktree script runs no hook and no file-system monitor of the branch', function (): void {
    $selfTest = new Process([dirname(__DIR__, 2) . '/skills/_shared/branch-worktree.sh', '--self-test']);
    $selfTest->run();

    expect($selfTest->getExitCode())->toBe(0)
        ->and($selfTest->getOutput())->toContain('branch-worktree.sh self-test passed')
        ->and(packageSource('composer.json'))->toContain('skills/_shared/branch-worktree.sh');
});
