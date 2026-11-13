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

    expect($content)->toContain('Later rounds raise only new Critical findings');
    expect($content)->not->toContain('A new finding is raised only when it is Critical.');
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
    'a round that drops a new identity carries the earlier open finding and the loop continues',
    'a renamed file never resolves the earlier open finding',
    'the S1 lens and the S3 surface are recorded in round 2 and block',
]);

test('the extract-findings self-test covers every S1 lens and the S3 surfaces the filter relies on (issue #455)', function (string $label): void {
    expect(laterRoundsFile('skills/_shared/extract-findings.sh'))->toContain($label);
})->with([
    'round: a finding of the $lens lens is security-relevant',
    'round: the S3 surface \\"$surface\\" is security-relevant',
    'round: a finding that cites no rule cannot be classified and counts as security-relevant',
]);

test('the security classification names every S1 lens and the S3 surfaces of the exclusion gate (issue #455)', function (string $term): void {
    expect(laterRoundsFile('skills/_shared/extract-findings.sh'))->toContain($term);
})->with([
    'security-review|security-audit|security-bounty-hunter|security-threat-analysis'
        . '|laravel-security|laravel-authorization-review|git-history-exposure-audit',
    'hostname', 'private.?ip', 'outbound', 'allow.?list', 'supply.?chain', 'malicious', 'clickjack', 'bidi', 'invisible', 'trojan', 'redirect',
]);

test('the later-round filter fails closed and carries an earlier finding when it drops a new identity (issue #455)', function (): void {
    $rule = laterRoundsFile('rules/code-review/general.md');

    expect($rule)
        ->toContain('The filter fails closed: a finding is dropped only when its security flag is `false`')
        ->toContain('A round that drops a new identity carries every earlier open finding it no longer reports instead of resolving it');
    $records = laterRoundsFile('skills/code-review/references/finding-records.md');

    expect($records)->toContain('A finding that cites no rule cannot be classified and counts as security.');
    expect($records)->not->toContain('A false negative would let a security finding be dismissed or deferred.');
});

test('a later round is an iteration of one review-and-fix loop, and a published-comment baseline starts a new cycle (issue #455)', function (): void {
    $rule = laterRoundsFile('rules/code-review/general.md');

    expect($rule)
        ->toContain('A later round is an iteration of one review-and-fix loop')
        ->toContain('starts round 1 of a new review cycle');
    expect($rule)->not->toContain('A round that resolves a baseline under *Baseline resolution* above is a later round of its review cycle.');
});

test(
    'the pr-summary lists every point that blocks the merge and names a blocking security point in the JIRA status line (issue #455)',
    function (string $path): void {
        expect(laterRoundsFile($path))->not->toContain('that is not Critical');
        expect(laterRoundsFile($path))->not->toContain('appears only when it is Critical');
    },
)->with([
    'skills/pr-summary/SKILL.md',
    'skills/pr-summary/templates/pr-summary-jira.md',
]);

test('the JIRA status line names a security point that needs verification as a blocker (issue #455)', function (): void {
    expect(laterRoundsFile('skills/pr-summary/templates/pr-summary-jira.md'))
        ->toContain('When a security point that still needs verification blocks the merge, name it here as a blocker');
    expect(laterRoundsFile('skills/pr-summary/SKILL.md'))
        ->toContain('A security point that still needs verification blocks the merge: the status line names it as a blocker in plain words');
});

test('the security hand-off keeps its renamed field name in every line that routes to it (issue #455)', function (string $path): void {
    $content = laterRoundsFile($path);

    expect($content)->toContain('*Security findings outside the assignment*');
    expect($content)->not->toContain('out-of-scope handoff');
})->with([
    'agents/leonardo.md',
    'skills/security-audit/SKILL.md',
]);

test('the code-review rule owns the four areas of a review and drops everything else (issue #455)', function (): void {
    $rule = laterRoundsFile('rules/code-review/general.md');

    expect($rule)
        ->toContain('## What a code review checks, and nothing else (issue #455)')
        ->toContain('This section is the one owner of the subject of a code review. Every other file points here and never restates it.')
        ->toContain('A code review covers exactly four areas, in this order:')
        ->toContain('1. **Functionality against the assignment.**')
        ->toContain('2. **Security.**')
        ->toContain('3. **Database optimisation.**')
        ->toContain('4. **Code rules.**')
        ->toContain('- **No follow-up proposal.** The review proposes no follow-up issue')
        ->toContain('- **No point unrelated to the assignment.** A point outside the assignment that is not a security issue is dropped.')
        ->toContain('- **The security exception stays.** A security vulnerability found outside the assignment is never dropped.')
        ->toContain('The security floor never moves.');
    expect(mb_strpos($rule, '1. **Functionality against the assignment.**'))
        ->toBeLessThan((int) mb_strpos($rule, '2. **Security.**'))
        ->and(mb_strpos($rule, '3. **Database optimisation.**'))
        ->toBeLessThan((int) mb_strpos($rule, '4. **Code rules.**'));
});

test('every review surface points at the owner of the four areas (issue #455)', function (string $path): void {
    $content = laterRoundsFile($path);

    expect($content)->toContain('What a code review checks, and nothing else');
    expect($content)->not->toContain('The review covers exactly four areas, in this order');
})->with([
    'agents/leonardo.md',
    'agents/splinter.md',
    'skills/code-review/SKILL.md',
    'skills/code-review-github/SKILL.md',
    'skills/code-review-jira/SKILL.md',
    'skills/code-review-bugsnag/SKILL.md',
    'skills/code-review-github/references/cr-wrapper-contract.md',
    'skills/code-review/templates/review-output.md',
    'skills/code-review-github/templates/pr-comment-output.md',
    'skills/pr-summary/SKILL.md',
    'skills/process-code-review/references/round-three-deferral.md',
    'rules/code-review/review-process.md',
]);

test(
    'the review no longer records an out-of-scope observation or proposes a follow-up issue (issue #455)',
    function (string $path, string $retired): void {
        expect(laterRoundsFile($path))->not->toContain($retired);
    },
)->with([
    'conformance gate follow-up' => ['skills/code-review/references/assignment-conformance-gate.md', 'move it to its own issue / PR'],
    'conformance gate move' => ['skills/code-review/references/assignment-conformance-gate.md', 'when it has independent value'],
    'krang critical pre-existing' => ['agents/krang.md', 'critical pre-existing problems'],
    'lens follow-up items' => ['skills/code-review/references/specialized-reviews.md', 'travel in the reviewer\'s handoff as follow-up items'],
    'leonardo critical field' => ['agents/leonardo.md', '**Critical out-of-scope findings:**'],
    'leonardo filed issue' => ['agents/leonardo.md', 'any filed out-of-scope issue'],
    'leonardo observation field' => ['agents/leonardo.md', '**Out-of-scope observations:**'],
    'review files an issue' => ['skills/code-review/references/specialized-reviews.md', 'to file an out-of-scope item'],
    'review process handoff' => ['rules/code-review/review-process.md', 'goes into the reviewer\'s handoff. When it clears the filing bar'],
    'splinter observation field' => ['agents/splinter.md', '*Out-of-scope observations*'],
    'wrapper handoff filing' => [
        'skills/code-review-github/references/cr-wrapper-contract.md',
        'becomes a tracker issue only when it clears the filing bar',
    ],
]);

test('the security exception for a vulnerability outside the assignment stays (issue #455)', function (): void {
    $leonardo = laterRoundsFile('agents/leonardo.md');
    $krang = laterRoundsFile('agents/krang.md');

    expect($leonardo)
        ->toContain('- **Security findings outside the assignment:**')
        ->toContain('The one exception is a **security vulnerability**, because leaving it unreported means nobody learns of it at all.')
        ->toContain('**Never disclose an unfixed vulnerability on a public tracker.**')
        ->toContain('Mark every item `do not file publicly`');
    expect($krang)->toContain('**Never file an item marked `do not file publicly`**');
});
