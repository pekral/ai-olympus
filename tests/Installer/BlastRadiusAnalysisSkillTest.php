<?php

declare(strict_types = 1);

function blastRadiusRead(string $relativePath): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
}

test('blast-radius-analysis is read-only and reuses the existing walks instead of restating them', function (): void {
    $skill = blastRadiusRead('skills/blast-radius-analysis/SKILL.md');

    expect($skill)->toContain("name: blast-radius-analysis\n");
    expect($skill)->toContain('**Read-only.** Never edit source, tests, or configuration.');
    expect($skill)->toContain('**The procedure is not restated here; it is reused.**');
    expect($skill)->toContain('*Behaviour changed outside the diff*');
    expect($skill)->toContain('*Input consistency*');
    expect($skill)->toContain('*A caller or consumer outside the repository is a verification gap, never proof of absence*');
    expect($skill)->toContain('Never compute a second risk score.');
    expect($skill)->toContain('`splinter` owns the security review.');
});

test('blast-radius-analysis sets its depth by the existing tier and escalates only through the classifier', function (): void {
    $skill = blastRadiusRead('skills/blast-radius-analysis/SKILL.md');

    expect($skill)->toContain('| `FAST` | No standalone report.');
    expect($skill)->toContain('| `STANDARD` | Direct callers and consumers of every changed symbol');
    expect($skill)->toContain('| `CRITICAL` | `STANDARD`, plus one further hop for every shared symbol');
    expect($skill)->toContain('skills/_shared/classify-risk.sh --files <the paths> --floor <the current tier>');
    expect($skill)->toContain('The number of files is never a depth signal.');
});

test('blast-radius-analysis grades evidence and never presents an unknown external consumer as compatible', function (): void {
    $skill = blastRadiusRead('skills/blast-radius-analysis/SKILL.md');

    expect($skill)->toContain('| `Verified` |');
    expect($skill)->toContain('| `Likely` |');
    expect($skill)->toContain('| `Unknown` |');
    expect($skill)->toContain('An external consumer this repository cannot inspect is always `Unknown`, never compatible.');
    expect($skill)->toContain('Never present a `Likely` or `Unknown` impact as `Verified`.');
});

test('a scope change re-analyses only the new part and never widens the implementation', function (): void {
    $skill = blastRadiusRead('skills/blast-radius-analysis/SKILL.md');

    expect($skill)->toContain('1. Stop the affected implementation step.');
    expect($skill)->toContain('2. Run steps 2–6 for the new part of the scope only, and append the result to the report.');
    expect($skill)->toContain('A change that leaves the report\'s assumptions true needs no new analysis.');
    expect($skill)->toContain('it never widens the implementation.');
});

test('the impact report reaches the implementer, the reviewer, and the acceptance pass through the shared brief', function (): void {
    expect(blastRadiusRead('skills/resolve-issue/SKILL.md'))
        ->toContain('On a `STANDARD` or `CRITICAL` task, record the Map result through `@skills/blast-radius-analysis/SKILL.md`');
    expect(blastRadiusRead('agents/donatello.md'))->toContain('- **Impact report:** `## Impact report` in the brief');
    expect(blastRadiusRead('agents/leonardo.md'))->toContain('read it as a lead, never as evidence');
    expect(blastRadiusRead('agents/raphael.md'))->toContain('add each of its `verification.runtime` scenarios as a regression row');
});
