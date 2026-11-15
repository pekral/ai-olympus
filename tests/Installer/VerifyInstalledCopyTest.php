<?php

declare(strict_types = 1);

function verifyInstalledCopySource(string $relativePath): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
}

test('the verifier is an executable bash script whose self-test the build runs once', function (): void {
    $script = dirname(__DIR__, 2) . '/skills/_shared/verify-installed-copy.sh';
    /** @var array{scripts: array<string, array<int, string>>} $composer */
    $composer = json_decode(verifyInstalledCopySource('composer.json'), associative: true, flags: JSON_THROW_ON_ERROR);

    expect(is_executable($script))->toBeTrue()
        ->and(verifyInstalledCopySource('skills/_shared/verify-installed-copy.sh'))->toStartWith('#!/usr/bin/env bash')
        ->and(substr_count(implode(' ', $composer['scripts']['shell-self-tests']), ' skills/_shared/verify-installed-copy.sh '))->toBe(1);
});

test('the trusted classifier verifies against the default-branch commit that GitHub names, before code from the head runs', function (): void {
    $section = installerDocsSection(
        verifyInstalledCopySource('skills/merge-github-pr/references/merge-exemptions.md'),
        '## The trusted classifier — the default-branch copy',
    );

    expect($section)->toContain('`skills/_shared/verify-installed-copy.sh`, the one verifier of a trusted copy')
        ->and($section)->toContain('no symbolic link in any component of the installed path, a regular file')
        ->and($section)->toContain('the SHA-256 the installer recorded outside the working tree')
        ->and($section)->toContain('export GIT_NO_REPLACE_OBJECTS=1')
        ->and($section)->toContain('defaultBranchRef{target{oid}}')
        ->and($section)->toContain('= "${TIP:?}" ]')
        ->and($section)->toContain('git show "${TIP:?}:skills/_shared/verify-installed-copy.sh" | bash -s -- --root "<main>" --commit "${TIP:?}"')
        ->and($section)->toContain('= \'installed-copy-verdict=verified\' ] && "<main>/.claude/skills/_shared/classify-risk.sh"')
        ->and($section)->toContain('A verdict holds only for the run in the same shell call.')
        ->and($section)->toContain('before any code from the pull-request head runs in the main worktree')
        ->and($section)->toContain('never makes a copy trusted again')
        ->and($section)->not->toContain('until items 1 to 4 ran again')
        ->and($section)->not->toContain('--ref "${REF:?}"')
        ->and($section)->not->toContain('(`cmp`)');
});

test('the low-risk path decides after the gate from the verdicts recorded before code from the head ran', function (): void {
    $document = verifyInstalledCopySource('skills/merge-github-pr/references/low-risk-path.md');
    $select = installerDocsSection($document, '## Select the candidate');
    $confirm = installerDocsSection($document, '## Confirm after the gate');

    expect($select)->toContain('--coverage ok --gate green')
        ->and($select)->toContain('--coverage gap --gate green')
        ->and($select)->toContain('Keep both outputs as text in the context of the run, never in a file')
        ->and($confirm)->toContain('Take the output recorded in *Select the candidate* item 3 that matches the observed facts')
        ->and($confirm)->toContain('Run no installed copy after the gate')
        ->and($confirm)->not->toContain('Run the trusted classifier again');
});

test('the history scan verifies its installed copies with the shared verifier, not with a comparison of its own', function (): void {
    $skill = verifyInstalledCopySource('skills/git-history-exposure-audit/SKILL.md');
    $step = substr(
        $skill,
        (int) strpos($skill, '2. **The trusted copy of the script.**'),
        (int) strpos($skill, '3. **The commits.**') - (int) strpos($skill, '2. **The trusted copy of the script.**'),
    );

    expect($step)->toContain('`skills/_shared/verify-installed-copy.sh`')
        ->and($step)->toContain('`skills/merge-github-pr/references/merge-exemptions.md` *The trusted classifier* items 3 to 6')
        ->and($step)->toContain('.claude/skills/git-history-exposure-audit/scripts/history-scan.sh .claude/skills/_shared/read-manifest.sh')
        ->and($step)->toContain('before the review runs any code from the head in the main worktree')
        ->and($step)->toContain('`Not verified: history exposure — code from the head ran in the main worktree before the scan;')
        ->and($step)->not->toContain('(`cmp`)')
        ->and($step)->not->toContain('git show "${REF:?}:skills/git-history-exposure-audit/scripts/history-scan.sh"');
});
