<?php

declare(strict_types = 1);

use Pekral\AiOlympus\Installer;

const STOPPED_SWAP_REFERENCE = 'skills/process-code-review/references/close-the-defect-class.md';

test('the saved copy is checked before the fixed files are restored (issue #622)', function (): void {
    $section = installerDocsSection(packageSource(STOPPED_SWAP_REFERENCE), '## Attack the fix before the push');
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
    $section = installerDocsSection(packageSource(STOPPED_SWAP_REFERENCE), '## Attack the fix before the push');

    expect($section)->toContain($condition);
})->with([
    'an incomplete copy restores nothing' => ['restore nothing, move nothing, leave the working tree as it is, and stop `Blocked`'],
    'each copy matches its source' => ['each copy matches its source in the working tree byte for byte'],
    'one copy of every file' => ['the swap directory holds one copy of every fixed file, tracked and new, at its repository-relative path'],
    'the saved diff exists and matches' => ['the saved `git diff` file exists and equals the `git diff` of the fixed tracked files now'],
]);

test('a stop of the swap names the saved copy, its worktree, the reason, and what was restored (issue #622)', function (string $item): void {
    $reference = packageSource(STOPPED_SWAP_REFERENCE);
    $subsection = installerDocsSection($reference, '### A stopped swap keeps its saved copy');

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

test('the fix handoff carries the stop report of a stopped swap (issue #622)', function (): void {
    $handoff = installerDocsSection(packageSource(STOPPED_SWAP_REFERENCE), '## The handoff');

    expect($handoff)->toContain(
        'A fix attempt that stopped during the swap also hands back the stop report of *A stopped swap keeps its saved copy*: '
        . 'the swap directory, the worktree that holds it, the reason the worktree is kept, and what was restored.',
    );
});

test('the stopped-swap rule stays in the owner reference only (issue #622)', function (string $relativePath): void {
    expect(packageSource($relativePath))->not->toContain('it holds the only saved copy of an uncommitted fix');
})->with([
    'skills/process-code-review/SKILL.md',
    'agents/donatello.md',
    'commands/_shared/krang-workflow.inc',
    'agents/leonardo.md',
]);

test('krang reports a stopped swap and names every kept tree with its reason (issue #622)', function (string $text): void {
    expect(packageSource('commands/_shared/krang-workflow.inc'))->toContain($text);
})->with([
    'every kept worktree has a reason' => ['**Every worktree that *Run cleanup* leaves in place is named in the report with its reason**'],
    'the owner reference' => ['`@skills/process-code-review/references/close-the-defect-class.md` *A stopped swap keeps its saved copy*'],
    'the report copy' => ['into the `Kept worktrees` item of the final report before the brief is deleted'],
    'the report item' => ['- **Kept worktrees:** each worktree or tree *Run cleanup* left in place, as its absolute path and the reason it is kept'],
    'the report reads it on a blocked stop' => [
        'Read it from the specialist handoffs and the cleanup result before the run ends, on a `Blocked` stop too.',
    ],
    'the report reads none only when nothing is kept' => [
        '`none` only when *Run cleanup* removed every worktree the run created, left no tree in place, '
        . 'and no `donatello` handoff named a swap directory',
    ],
    'the swap directory in the report' => ['also the absolute path of the swap directory that holds the saved copy, and what the fix attempt restored'],
]);

test('the stopped-swap item is its own Run cleanup item for the shared working tree too (issue #622)', function (): void {
    $krang = packageSource('commands/_shared/krang-workflow.inc');
    $item = '   - **Keep every tree that holds a stopped swap, and report it.**';
    $position = (int) strpos($krang, $item);
    $implementation = (int) strpos($krang, '   - **Remove the implementation worktree of a parallel unit**');
    $memoryWrite = (int) strpos($krang, '   - **Write the memory entries**');
    $reportItem = (int) strpos($krang, '- **Kept worktrees:**');
    $memoryItem = (int) strpos($krang, '- **Memory entries:**');

    expect($position)->toBeGreaterThan($implementation)
        ->and($position)->toBeLessThan($memoryWrite)
        ->and($reportItem)->toBeGreaterThan(0)
        ->and($reportItem)->toBeLessThan($memoryItem)
        ->and(substr($krang, $position, $memoryWrite - $position))
        ->toContain('The tree is the parallel-unit worktree, the batch unit worktree, or the shared working tree')
        ->toContain('run no `git restore`, `git checkout`, or `git clean` in the tree');
});

test('every removal path of a worktree checks for a swap directory first (issue #622)', function (string $relativePath, string $anchor): void {
    expect(packageSource($relativePath))->toContain($anchor);
})->with([
    'krang Run cleanup' => ['commands/_shared/krang-workflow.inc', 'Check every worktree for a swap directory before you remove it.'],
    'the batch unit removal' => ['commands/_shared/orchestration/batch-preparation.inc', '- **A swap directory keeps the worktree.**'],
    'the general worktree cleanup' => ['rules/git/general.md', 'and it holds no swap directory'],
    'the post-merge removal' => ['skills/merge-github-pr/SKILL.md', 'If active, dirty, or holding a swap directory ('],
    'the startup sweep' => ['commands/_shared/orchestration/stale-run-recovery.inc', '**Verify it holds no swap directory before the removal.**'],
]);

test('each removal path that carries the check points to the owner reference (issue #622)', function (string $relativePath): void {
    expect(packageSource($relativePath))
        ->toContain('`@skills/process-code-review/references/close-the-defect-class.md` *A stopped swap keeps its saved copy*');
})->with([
    'commands/_shared/krang-workflow.inc',
    'commands/_shared/orchestration/stale-run-recovery.inc',
    'commands/_shared/orchestration/batch-preparation.inc',
    'skills/merge-github-pr/SKILL.md',
    'rules/git/general.md',
]);

test('the owner reference states the swap-directory check once for every removal (issue #622)', function (string $text): void {
    $subsection = installerDocsSection(packageSource(STOPPED_SWAP_REFERENCE), '### A stopped swap keeps its saved copy');

    expect($subsection)->toContain($text);
})->with([
    'a non-zero exit keeps the worktree' => [
        'and also when it exits non-zero for any reason: an attack directory that cannot be read never reads as no swap',
    ],
    'a quoted path in every command' => ['Quote the path in every command'],
    'the clean check misses it' => ['The swap directory is git-ignored, so `git status --porcelain` prints nothing for it'],
    'the Codex path' => ['`.codex/run/attack` on Codex'],
    'the command is quoted' => ['2. Otherwise run `find "<path>/.claude/run/attack" -mindepth 1 -maxdepth 1`.'],
    'the existence test' => ['1. Run `test -e "<path>/.claude/run/attack"`.'],
    'the removal paths' => ['`krang` *Run cleanup*, the startup sweep, the removal of a batch unit, and the removal after a merge'],
    'the rule' => ['**A worktree that holds a swap directory is never removed.**'],
]);

function stoppedSwapDocumentedCommand(string $pattern, string $worktree): string
{
    $subsection = installerDocsSection(packageSource(STOPPED_SWAP_REFERENCE), '### A stopped swap keeps its saved copy');
    preg_match($pattern, $subsection, $matches);

    return str_replace('<path>', $worktree, $matches[1] ?? '');
}

/**
 * @return array{directory: string, worktree: string, git: string}
 */
function stoppedSwapScratchWorktree(): array
{
    $root = sys_get_temp_dir() . '/ai-olympus-swap-' . bin2hex(random_bytes(4));
    $repository = $root . '/repository';
    $git = 'git -c commit.gpgsign=false -c user.name=test -c user.email=test@example.com -C ' . escapeshellarg($repository);
    mkdir($repository, 0777, recursive: true);
    file_put_contents($repository . '/.gitignore', ".claude/run/\n");
    shell_exec($git . ' init -q 2>&1');
    shell_exec($git . ' add .gitignore 2>&1');
    shell_exec($git . ' commit -q -m base 2>&1');
    shell_exec($git . ' worktree add -q "' . $root . '/work tree" -b swap-test 2>&1');

    return ['directory' => $root, 'worktree' => $root . '/work tree', 'git' => $git];
}

function stoppedSwapRun(string $command): string
{
    return trim((string) shell_exec($command . ' 2>/dev/null; echo "exit:$?"'));
}

test('the documented check keeps a worktree whose ignored swap directory the clean check misses (issue #622)', function (): void {
    $scratch = stoppedSwapScratchWorktree();
    $worktree = '"' . $scratch['worktree'] . '"';
    $exists = stoppedSwapDocumentedCommand('/`(test -e "<path>[^`]+)`/', $scratch['worktree']);
    $list = stoppedSwapDocumentedCommand('/`(find "<path>[^`]+)`/', $scratch['worktree']);

    $withoutDirectory = stoppedSwapRun($exists);
    mkdir($scratch['worktree'] . '/.claude/run/attack', 0777, recursive: true);
    $withEmptyParent = stoppedSwapRun($list);
    mkdir($scratch['worktree'] . '/.claude/run/attack/AbCd1234');
    $withSwap = stoppedSwapRun($list);
    $status = trim((string) shell_exec('git -C ' . $worktree . ' status --porcelain 2>&1'));
    $removal = trim((string) shell_exec($scratch['git'] . ' worktree remove ' . $worktree . ' 2>&1; echo "exit:$?"'));

    installerRemoveDirectory($scratch['directory']);

    expect($withoutDirectory)->toBe('exit:1')
        ->and($withEmptyParent)->toBe('exit:0')
        ->and($withSwap)->toContain('/work tree/.claude/run/attack/AbCd1234')
        ->and($status)->toBe('')
        ->and($removal)->toBe('exit:0');
});

test('the documented check does not read an unreadable attack directory as no swap (issue #622)', function (): void {
    $scratch = stoppedSwapScratchWorktree();
    $list = stoppedSwapDocumentedCommand('/`(find "<path>[^`]+)`/', $scratch['worktree']);
    mkdir($scratch['worktree'] . '/.claude/run/attack/AbCd1234', 0777, recursive: true);
    chmod($scratch['worktree'] . '/.claude/run/attack', 0000);

    $unreadable = stoppedSwapRun($list);

    chmod($scratch['worktree'] . '/.claude/run/attack', 0755);
    installerRemoveDirectory($scratch['directory']);

    expect($unreadable)->toBe('exit:1');
})->skip(posix_geteuid() === 0, 'a superuser reads a directory of mode 0000');

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
            '.claude/command-support/_shared/krang-workflow.inc' => '- **Kept worktrees:**',
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
