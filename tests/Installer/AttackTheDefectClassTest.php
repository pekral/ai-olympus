<?php

declare(strict_types = 1);

use Pekral\AiOlympus\Installer;

function attackClassFile(string $relativePath): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
}

function attackClassSection(string $content, string $heading): string
{
    $start = (int) strpos($content, $heading);
    $end = strpos($content, "\n## ", $start + strlen($heading));

    return $end === false ? substr($content, $start) : substr($content, $start, $end - $start);
}

test('the owner reference makes the fix round attack the whole class before the push (issue #608)', function (): void {
    $section = attackClassSection(
        attackClassFile('skills/process-code-review/references/close-the-defect-class.md'),
        '## Attack the fix before the push',
    );

    expect($section)
        ->toContain('- A security finding always gets it.')
        ->toContain('- Any other finding gets it when its class has more than one form:')
        ->toContain('- A finding whose class has one form skips it. The handoff names that reason.')
        ->toContain('1. **Write a handful of attacks.** Each attack is one short case against the class, not only against the reported case.')
        ->toContain('Keep the set small and fast, so it runs locally before the push.')
        ->toContain('2. **Try to get around the fix itself.** Write the same input in another form:')
        ->toContain('3. **Make each attack fail on the code before the fix.**')
        ->toContain('(`git stash push -- <the fixed source files>`)')
        ->toContain('An attack that passes there reproduces nothing: rewrite it until it fails, or drop it.')
        ->toContain('4. **Make each attack pass on the fixed code.** An attack that still fails got through the fix.')
        ->toContain('Change the fix before the push, then run every attack again.')
        ->toContain('5. **Keep every attack as a regression test.**');
});

test('the attack step runs between the class test and the push (issue #608)', function (): void {
    $reference = attackClassFile('skills/process-code-review/references/close-the-defect-class.md');
    $classTest = (int) strpos($reference, '4. **Prove the class closed with one test over its variants.**');
    $attack = (int) strpos($reference, '5. **Attack the fix.** Run the short red tests of *Attack the fix before the push* below');
    $push = (int) strpos($reference, '6. **Push only then.**');

    expect($classTest)->toBeGreaterThan(0)
        ->and($attack)->toBeGreaterThan($classTest)
        ->and($push)->toBeGreaterThan($attack);
});

test('the attacks never replace the review (issue #608)', function (): void {
    $limits = attackClassSection(attackClassFile('skills/process-code-review/references/close-the-defect-class.md'), '## Limits');

    expect($limits)->toContain('- **The attacks never replace the review.** The next review round still checks the fix on its own.');
});

test('the fix handoff lists every attack tried with its result before and after the fix (issue #608)', function (): void {
    $handoff = attackClassSection(attackClassFile('skills/process-code-review/references/close-the-defect-class.md'), '## The handoff');

    expect($handoff)
        ->toContain('the handoff also lists the attacks tried.')
        ->toContain('the case, its result on the code before the fix (fails), and its result on the fixed code (passes).')
        ->toContain('every attack that got through the first version of the fix, and every attack that was dropped.')
        ->toContain('A covered finding without that list is reported as not closed too.');

    expect(attackClassFile('agents/donatello.md'))
        ->toContain('Before the push, attack each fix with the short red tests of its section *Attack the fix before the push*')
        ->toContain('Hand back the class, the instances, the test, and the attacks tried of each finding.')
        ->toContain('- **Attacks tried:** in a fix round, the attacks of each finding with their result before and after the fix,');
});

test('the attack section assumes no project-specific tool (issue #608)', function (string $tool): void {
    $section = attackClassSection(
        attackClassFile('skills/process-code-review/references/close-the-defect-class.md'),
        '## Attack the fix before the push',
    );

    expect(strtolower($section))->not->toContain($tool);
})->with(['vendor/bin', 'pest', 'phpunit', 'composer', 'artisan', 'npm', 'pytest', 'jest']);

test('no other file restates the attack steps (issue #608)', function (string $relativePath): void {
    expect(attackClassFile($relativePath))->not->toContain('Try to get around the fix itself.');
})->with([
    'skills/process-code-review/SKILL.md',
    'agents/donatello.md',
    'agents/krang.md',
    'agents/leonardo.md',
]);

test('Claude Code and Codex install the attack step and its handoff field (issue #608)', function (): void {
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
            '.agents/skills/process-code-review/references/close-the-defect-class.md' => '## Attack the fix before the push',
            '.claude/agents/donatello.md' => '- **Attacks tried:** in a fix round,',
            '.claude/skills/process-code-review/references/close-the-defect-class.md' => '## Attack the fix before the push',
            '.codex/agent-instructions/donatello.md' => '- **Attacks tried:** in a fix round,',
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
