<?php

declare(strict_types = 1);

function laterRoundsFile(string $path): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $path);
}

test('the code-review rule owns the later-round filter with the security floor and carry-over (issue #455)', function (): void {
    $rule = laterRoundsFile('rules/code-review/general.md');

    expect($rule)
        ->toContain('### Later rounds raise only new Critical findings (issue #455)')
        ->toContain('This section is the one owner of the rule. Every other file points here and never restates it.')
        ->toContain('- **A new finding is raised only when it is Critical.** A new Moderate or Minor point that the later round finds is not reported.')
        ->toContain(
            '- **The security floor is unchanged.** A finding that meets the S1–S3 carve-out of the Exclusion Gate '
                . 'is raised in every round, at its own severity',
        )
        ->toContain('- **An earlier finding stays tracked until it is fixed.**')
        ->toContain('The convergence gate (`@skills/process-code-review/SKILL.md` *Review loop* step 4) counts them as before.')
        ->toContain('`skills/_shared/review-state.sh record` applies the same filter to a round report from round 2 on');
});

test('every other file points at the owner and none restates the filter (issue #455)', function (string $path): void {
    $content = laterRoundsFile($path);

    expect($content)
        ->toContain('Later rounds raise only new Critical findings')
        ->not->toContain('A new finding is raised only when it is Critical.');
})->with([
    'skills/process-code-review/SKILL.md',
    'skills/process-code-review/references/review-loop-scope.md',
    'skills/process-code-review/references/review-state.md',
    'skills/process-code-review/references/round-three-deferral.md',
    'skills/code-review/references/finding-records.md',
    'skills/_shared/review-state.sh',
]);

test('the convergence gate and the cap-round table stay unchanged by the later-round filter (issue #455)', function (): void {
    $skill = laterRoundsFile('skills/process-code-review/SKILL.md');
    $deferral = laterRoundsFile('skills/process-code-review/references/round-three-deferral.md');

    expect($skill)->toContain(
        'The loop is **converged** when `criticalCount == 0`, `unfulfilledCount == 0`, '
            . 'and **no Moderate finding remains undeferred**',
    );
    expect($deferral)
        ->toContain('A new Moderate that the cap round itself finds is never reported')
        ->toContain('plus a security Moderate at any round.')
        ->toContain('| **Moderate, non-security, passing the filing bar** |');
});

test('the review-state self-test covers the later-round filter (issue #455)', function (string $label): void {
    expect(laterRoundsFile('skills/_shared/review-state.sh'))->toContain($label);
})->with([
    'round 1 still records a new non-Critical finding',
    'a new non-security Moderate in round 2 is not recorded and not blocking',
    'the ignored point is named on stderr',
    'a Moderate raised in round 1 stays tracked as confirmed in round 2',
    'a new Critical in a later round is recorded and blocks',
    'the security floor: a new security finding in round 2 is recorded at its severity and blocks',
    'a resolved earlier finding that returns is reopened and tracked, never ignored',
]);
