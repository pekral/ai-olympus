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

test('the trusted classifier runs the verifier from the default-branch blob before every run of the installed copy', function (): void {
    $section = installerDocsSection(
        verifyInstalledCopySource('skills/merge-github-pr/references/merge-exemptions.md'),
        '## The trusted classifier — the default-branch copy',
    );

    expect($section)->toContain('`skills/_shared/verify-installed-copy.sh`, the one verifier of a trusted copy')
        ->and($section)->toContain('no symbolic link in any component of the installed path, a regular file')
        ->and($section)->toContain('the SHA-256 the installer recorded outside the working tree')
        ->and($section)->toContain('git show "${REF:?}:skills/_shared/verify-installed-copy.sh" | bash -s -- --root "<main>" --ref "${REF:?}"')
        ->and($section)->toContain('= \'installed-copy-verdict=verified\' ] && "<main>/.claude/skills/_shared/classify-risk.sh"')
        ->and($section)->toContain('A verdict holds only for the run in the same shell call.')
        ->and($section)->toContain('Once code from a pull-request head ran in the main worktree')
        ->and($section)->toContain('no installed copy there is trusted until items 1 to 4 ran again')
        ->and($section)->not->toContain('(`cmp`)');
});

test('the low-risk path verifies the installed classifier again after the gate ran code from the head', function (): void {
    $confirm = installerDocsSection(
        verifyInstalledCopySource('skills/merge-github-pr/references/low-risk-path.md'),
        '## Confirm after the gate',
    );

    expect($confirm)->toContain('verified again as `references/merge-exemptions.md` *The trusted classifier* item 6 directs');
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
        ->and($step)->not->toContain('(`cmp`)')
        ->and($step)->not->toContain('git show "${REF:?}:skills/git-history-exposure-audit/scripts/history-scan.sh"');
});
