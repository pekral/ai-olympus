<?php

declare(strict_types = 1);

use Pekral\AiOlympus\Installer;

const STOPPED_SWAP_REFERENCE = 'skills/process-code-review/references/close-the-defect-class.md';

function stoppedSwapFile(string $relativePath): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
}

function stoppedSwapSection(string $content, string $heading, string $marker = "\n## "): string
{
    $start = (int) strpos($content, $heading);
    $end = strpos($content, $marker, $start + strlen($heading));

    return $end === false ? substr($content, $start) : substr($content, $start, $end - $start);
}

test('the saved copy is checked before the fixed files are restored (issue #622)', function (): void {
    $section = stoppedSwapSection(stoppedSwapFile(STOPPED_SWAP_REFERENCE), '## Attack the fix before the push');
    $check = (int) strpos($section, '2. Check the saved copy before anything is restored.');
    $restore = (int) strpos($section, '3. Restore the fixed tracked files with `git restore --source=HEAD --worktree');
    $attacks = (int) strpos($section, '4. Run the attacks.');

    expect($check)->toBeGreaterThan(0)
        ->and($restore)->toBeGreaterThan($check)
        ->and($attacks)->toBeGreaterThan($restore)
        ->and($section)
        ->toContain('Run this step only after the check of swap step 2 passed.');
});

test('the check names each condition of a complete saved copy (issue #622)', function (string $condition): void {
    $section = stoppedSwapSection(stoppedSwapFile(STOPPED_SWAP_REFERENCE), '## Attack the fix before the push');

    expect($section)->toContain($condition);
})->with([
    'an incomplete copy restores nothing' => ['restore nothing, move nothing, leave the working tree as it is, and stop `Blocked`'],
    'each copy matches its source' => ['each copy matches its source in the working tree byte for byte'],
    'one copy of every file' => ['the swap directory holds one copy of every fixed file, tracked and new, at its repository-relative path'],
    'the saved diff exists and matches' => ['the saved `git diff` file exists and equals the `git diff` of the fixed tracked files now'],
]);

test('a stop of the swap names the saved copy, its worktree, the reason, and what was restored (issue #622)', function (string $item): void {
    $reference = stoppedSwapFile(STOPPED_SWAP_REFERENCE);
    $subsection = stoppedSwapSection($reference, '### A stopped swap keeps its saved copy');

    expect($subsection)->toContain($item);
})->with([
    'a failed round trip is a stop' => ['a failed round-trip check (swap step 5)'],
    'an incomplete copy is a stop' => ['an incomplete saved copy (swap step 2)'],
    'a refused path keeps no worktree' => ['That stop names the refused path, and no worktree is kept for it.'],
    'no unchecked delete' => ['No saved work is deleted or overwritten without a check.'],
    'no unchecked removal of the worktree' => ['never removes the worktree that holds a swap directory that was not deleted'],
    'the reason it is kept' => ['3. the reason that worktree is kept: it holds the only saved copy of an uncommitted fix;'],
    'the stop report heading' => ['The stop report names these four items:'],
    'the swap directory' => ['1. the absolute path of the swap directory, which is the saved copy;'],
    'the worktree' => ['2. the absolute path of the worktree that holds that swap directory;'],
    'what was restored' => ['4. what was restored: nothing, or the tracked files restored by swap step 3 before the stop.'],
]);

test('the swap directory is deleted only after the round-trip check (issue #622)', function (): void {
    $section = stoppedSwapSection(stoppedSwapFile(STOPPED_SWAP_REFERENCE), '## Attack the fix before the push');

    expect($section)
        ->toContain('Delete the swap directory after the check passes.')
        ->toContain('name the swap path in the handoff, and keep the worktree:');
});

test('the fix handoff carries the stop report of a stopped swap (issue #622)', function (): void {
    $handoff = stoppedSwapSection(stoppedSwapFile(STOPPED_SWAP_REFERENCE), '## The handoff');

    expect($handoff)->toContain(
        'A fix attempt that stopped during the swap also hands back the stop report of *A stopped swap keeps its saved copy*: '
        . 'the swap directory, the worktree that holds it, the reason the worktree is kept, and what was restored.',
    );
});

test('the stopped-swap rule stays in the owner reference only (issue #622)', function (string $relativePath): void {
    expect(stoppedSwapFile($relativePath))->not->toContain('it holds the only saved copy of an uncommitted fix');
})->with([
    'skills/process-code-review/SKILL.md',
    'agents/donatello.md',
    'agents/krang.md',
    'agents/leonardo.md',
]);

test('krang keeps the worktree of a stopped swap and names it with the reason (issue #622)', function (string $text): void {
    expect(stoppedSwapFile('agents/krang.md'))->toContain($text);
})->with([
    'a blocked stop reports it too' => ['on a `Blocked` stop too.'],
    'every kept worktree has a reason' => ['**Every worktree this item leaves in place is named in the report with its reason**'],
    'the owner reference' => ['`@skills/process-code-review/references/close-the-defect-class.md` *A stopped swap keeps its saved copy*'],
    'the report copy' => ['Copy the swap path, the worktree path, and the reason from that handoff into the `Kept worktrees` item of the final report.'],
    'the report item' => ['- **Kept worktrees:** each worktree *Run cleanup* left in place, as its absolute path and the reason it is kept'],
    'the swap directory in the report' => ['also the absolute path of the swap directory that holds the saved copy, and what the fix attempt restored'],
    'the worktree stays' => ['**A worktree that holds a stopped swap stays, whatever its state.**'],
]);

test('the kept-worktree item sits in Run cleanup and the report lists it before the memory entries (issue #622)', function (): void {
    $krang = stoppedSwapFile('agents/krang.md');
    $cleanup = (int) strpos($krang, '**A worktree that holds a stopped swap stays, whatever its state.**');
    $implementation = (int) strpos($krang, '**Remove the implementation worktree of a parallel unit**');
    $memoryWrite = (int) strpos($krang, '- **Write the memory entries**');
    $reportItem = (int) strpos($krang, '- **Kept worktrees:**');
    $memoryItem = (int) strpos($krang, '- **Memory entries:**');

    expect($cleanup)->toBeGreaterThan($implementation)
        ->and($cleanup)->toBeLessThan($memoryWrite)
        ->and($reportItem)->toBeGreaterThan(0)
        ->and($reportItem)->toBeLessThan($memoryItem);
});

test('Claude Code and Codex install the stopped-swap rule and the kept-worktree report item (issue #622)', function (): void {
    $root = installerCreateProjectRoot();
    $cwd = getcwd();
    $originalCwd = $cwd !== false ? $cwd : '';

    try {
        chdir($root);
        ob_start();
        $exitCode = Installer::run(['ai-olympus', 'install', '--force']);
        ob_end_clean();

        expect($exitCode)->toBe(0);

        $markers = [
            '.agents/skills/process-code-review/references/close-the-defect-class.md' => '### A stopped swap keeps its saved copy',
            '.claude/agents/krang.md' => '- **Kept worktrees:**',
            '.claude/skills/process-code-review/references/close-the-defect-class.md' => '### A stopped swap keeps its saved copy',
            '.codex/agent-instructions/krang.md' => '- **Kept worktrees:**',
        ];

        foreach ($markers as $path => $marker) {
            expect((string) file_get_contents($root . '/' . $path))->toContain($marker);
        }
    } finally {
        if ($originalCwd !== '') {
            chdir($originalCwd);
        }

        installerRemoveDirectory($root);
    }
});
