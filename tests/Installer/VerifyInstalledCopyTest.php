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
        ->and(verifyInstalledCopySource('skills/_shared/verify-installed-copy.sh'))->toContain('--root <untouched clone> --commit')
        ->and(verifyInstalledCopySource('skills/_shared/verify-installed-copy.sh'))->not->toContain('<main worktree>')
        ->and(substr_count(implode(' ', $composer['scripts']['shell-self-tests']), ' skills/_shared/verify-installed-copy.sh '))->toBe(1);
});

test('the trusted classifier runs only in an untouched clone and verifies against the commit that GitHub names', function (): void {
    $section = installerDocsSection(
        verifyInstalledCopySource('skills/merge-github-pr/references/merge-exemptions.md'),
        '## The trusted classifier — the default-branch copy',
    );

    expect($section)->toContain('`skills/_shared/verify-installed-copy.sh`, the one verifier of a trusted copy')
        ->and($section)->toContain('one worktree in the clone, no symbolic link in any component of the path, a regular file')
        ->and($section)->toContain('for an installed copy, the SHA-256 the installer recorded outside the working tree')
        ->and($section)->toContain('export GIT_NO_REPLACE_OBJECTS=1')
        ->and($section)->toContain(
            'export GIT_CONFIG_COUNT=2 GIT_CONFIG_KEY_0=core.hooksPath GIT_CONFIG_VALUE_0=/dev/null '
            . 'GIT_CONFIG_KEY_1=core.fsmonitor GIT_CONFIG_VALUE_1=false',
        )
        ->and($section)->toContain('defaultBranchRef{target{oid}}')
        ->and($section)->toContain('= "${TIP:?}" ]')
        ->and($section)->toContain('git show "${TIP:?}:skills/_shared/verify-installed-copy.sh" | bash -s -- --root "<clone>" --commit "${TIP:?}"')
        ->and($section)->toContain('= \'installed-copy-verdict=verified\' ] && "<clone>/skills/_shared/classify-risk.sh"')
        ->and($section)->not->toContain('<main>')
        ->and($section)->toContain('6. **Only an untouched clone gives a trusted copy.**')
        ->and($section)->toContain('So the main worktree of a project never gives one.')
        ->and($section)->toContain('The run created it itself, in this run, with the command below')
        ->and($section)->toContain('It has one worktree, and its `HEAD` is `TIP`.')
        ->and($section)->toContain('No command ran in it except')
        ->and($section)->toContain(
            'git clone --quiet --no-checkout --template= --config core.hooksPath=/dev/null --config core.fsmonitor=false',
        )
        ->and($section)->toContain('Accept `<owner>` and `<name>` only when each matches `^[A-Za-z0-9_.-]+$`.')
        ->and($section)->toContain('The `FAST`-tier exemption is then not qualified, the low-risk path is not available')
        ->and($section)->toContain('no untouched clone')
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
        ->and($confirm)->not->toContain('Run the trusted classifier again')
        ->and($confirm)->not->toContain('Start again from *Select the candidate*')
        ->and($confirm)->toContain('A fix commit of step 3 moves the head, and the full path takes over')
        ->and($confirm)->toContain('A tip that differs from the recorded **base tip** means the base moved, and the full path takes over')
        ->and($select)->toContain(
            'Run every command of this file in the untouched clone of `references/merge-exemptions.md` *The trusted classifier* item 6',
        );
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
        ->and($step)->toContain('`skills/git-history-exposure-audit/scripts/history-scan.sh skills/_shared/read-manifest.sh`')
        ->and($step)->toContain('the untouched clone of `skills/merge-github-pr/references/merge-exemptions.md` *The trusted classifier* item 6')
        ->and($step)->toContain('`Not verified: history exposure — no untouched clone;')
        ->and($step)->not->toContain('<main>')
        ->and($step)->not->toContain('(`cmp`)')
        ->and($step)->not->toContain('git show "${REF:?}:skills/git-history-exposure-audit/scripts/history-scan.sh"');
});

test('the installed reference replaces only a missing default-branch blob, and the self-test pins both outcomes', function (): void {
    $script = verifyInstalledCopySource('skills/_shared/verify-installed-copy.sh');

    expect($script)->toContain('#   verify-installed-copy.sh [--installed-reference] --root <untouched clone> --commit')
        ->and($script)->toContain('#   --installed-reference  for a path below `.claude/skills/` or `.agents/skills/` whose default branch neither ships')
        ->and($script)->toContain(
            '#            nothing for a path that has a default-branch blob, and nothing for a path below `skills/`. Only the review',
        )
        ->and($script)->toContain(
            '[[ "$reason" == \'no-default-branch-blob\' && "$installed_reference" == 1 && "$path" != skills/* ]] && reason=\'\'',
        );

    foreach ([
        'with --installed-reference, a copy the default branch neither ships nor tracks is verified by its recorded hash',
        'with --installed-reference, the Codex copy below .agents/skills is verified the same way',
        'with --installed-reference, a copy that differs from the recorded hash is refused',
        'with --installed-reference, a copy with no recorded reference is refused',
        'with --installed-reference, a copy the reference does not list is refused',
        'with --installed-reference, a copy that differs from an existing default-branch blob is still refused',
        'with --installed-reference, a skills/ path with no default-branch blob is still refused',
        'a copy the default branch does not ship or track is refused',
    ] as $label) {
        expect($script)->toContain($label);
    }

    // The merge gate never passes the option, so its classifier still needs a default-branch blob.
    expect(verifyInstalledCopySource('skills/merge-github-pr/references/merge-exemptions.md'))->not->toContain('--installed-reference')
        ->and(verifyInstalledCopySource('skills/merge-github-pr/references/low-risk-path.md'))->not->toContain('--installed-reference');
});
