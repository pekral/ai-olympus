<?php

declare(strict_types = 1);

/**
 * A production outage is the one situation where a review round costs more than it buys. HOTFIX is
 * the package's only path that shortens a review, so the tests below pin both halves of the trade:
 * what the mode actually waives, and the boundaries that stop it becoming a way to merge anything
 * unreviewed — a human declares it, security is never narrowed, and the build still has to pass.
 */
test('the orchestration rule owns the mode, its declaration, and its boundaries', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $rule = (string) file_get_contents($packageDir . '/rules/compound-engineering/orchestration.md');

    expect($rule)->toContain('## HOTFIX — the declared emergency path');

    // A mode that disables a review and that a tracker commenter could switch on is a hole. Both
    // halves of the declaration are load-bearing: a human said it, and the work is a bug fix.
    expect($rule)->toContain('**A human declared it, in words.**');
    expect($rule)->toContain('**The work is a bug fix.**');
    expect($rule)->toContain('**An agent never infers the mode and never offers it.**');

    // What the caller asked for.
    expect($rule)->toContain('**Test-coverage gates.**');
    expect($rule)->toContain('**Review breadth.**');

    // What no urgency buys: a green build and an unnarrowed security pass.
    expect($rule)->toContain('**The build.**');
    expect($rule)->toContain('a hotfix that breaks the default branch is a second outage');
    expect($rule)->toContain('HOTFIX narrows what a reviewer reports, never what the classifier decides');
    expect($rule)->toContain('HOTFIX is not a merge-anytime request');

    // The narrowed review still answers the two questions the caller actually cares about.
    expect($rule)->toContain('**Is the assignment satisfied?**');
    expect($rule)->toContain('**Does the change actually fix the reported bug?**');

    // The cost is stated rather than discovered later.
    expect($rule)->toContain('### What this costs, stated rather than hidden');
});

test('the review rules narrow the scope and declare the mode on the comment', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $general = (string) file_get_contents($packageDir . '/rules/code-review/general.md');
    $process = (string) file_get_contents($packageDir . '/rules/code-review/review-process.md');

    expect($general)->toContain('## HOTFIX runs — a narrowed review, declared on the comment');
    expect($general)->toContain('**Security is not part of the narrowing.**');
    expect($general)->toContain('**Mode:** HOTFIX — coverage waived, review scoped to assignment + bug fix (declared by <account>)');

    // The merge gate downstream trusts one line on one comment, so the narrowing cannot be claimed
    // anywhere a non-reviewer can write.
    expect($general)->toContain('A hotfix assertion anywhere else');

    // The coverage gate is waived at its own home, not re-decided by each caller.
    expect($process)->toContain('**Waived in full on a HOTFIX run.**');
});

test('the merge gate lifts only the coverage threshold, on evidence from the review comment', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $git = (string) file_get_contents($packageDir . '/rules/git/general.md');
    $merge = (string) file_get_contents($packageDir . '/skills/merge-github-pr/SKILL.md');

    // The existing two code-review exemptions stay two: a hotfix is reviewed, only differently.
    expect($git)->toContain('**A HOTFIX is not a third exemption.**');
    expect($git)->toContain('### HOTFIX pull requests (coverage threshold lifted, review still required)');

    expect($merge)->toContain('#### HOTFIX PR — coverage threshold lifted (code review still required)');
    expect($merge)->toContain('**No such line, no waiver.**');
    expect($merge)->toContain('On a qualified HOTFIX PR, a coverage shortfall is not a failure.');
});

test('the executors carry the mode: implementer, review loop, and route plan', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $gates = (string) file_get_contents($packageDir . '/skills/resolve-issue/references/quality-gates.md');
    $loop = (string) file_get_contents($packageDir . '/skills/process-code-review/references/review-loop-scope.md');
    $planner = (string) file_get_contents($packageDir . '/skills/_shared/plan-route.sh');
    $splinter = (string) file_get_contents($packageDir . '/agents/splinter.md');
    $leonardo = (string) file_get_contents($packageDir . '/agents/leonardo.md');

    // One home for every gate behaviour both skills share. Knowing the bug is gone survives there;
    // the committed test is what becomes optional.
    expect($gates)->toContain('## HOTFIX — what the mode relaxes here');
    expect($gates)->toContain('**Step 3 does not block.**');
    expect($gates)->toContain('**The reproduction stays; the committed test becomes optional.**');

    expect($loop)->toContain('#### HOTFIX runs (the caller declared a production emergency)');
    expect($loop)->toContain('**Never infer the mode.**');

    // The mode travels in the deterministic plan, so a dispatch can neither forget nor invent it.
    expect($planner)->toContain('--hotfix');
    expect($planner)->toContain('review_hotfix');
    expect($splinter)->toContain('**A declared HOTFIX is passed to the planner, never applied by hand.**');
    expect($leonardo)->toContain('**When the dispatch carries `review_hotfix`**');
});

/**
 * One label per planned stage — `role:mode` for an LLM dispatch, the bare type for a deterministic
 * one — so a plan can be compared against another plan by what it actually routes.
 *
 * @return list<string>
 */
function hotfixPlanStageLabels(string $json): array
{
    $labels = [];

    foreach ((array) decodedJsonField($json, 'stages') as $stage) {
        $stage = (array) $stage;
        $role = $stage['role'] ?? null;
        $mode = $stage['mode'] ?? null;
        $type = $stage['type'];

        $labels[] = is_string($role) && is_string($mode)
            ? $role . ':' . $mode
            : (is_string($type) ? $type : '');
    }

    return $labels;
}

function hotfixPlanJson(string $args): string
{
    $planner = dirname(__DIR__, 2) . '/skills/_shared/plan-route.sh';

    return (string) shell_exec('bash ' . escapeshellarg($planner) . ' ' . $args . ' 2>/dev/null');
}

test('the route planner narrows the review without removing it and never moves the tier', function (): void {
    $hotfix = hotfixPlanJson('--tier STANDARD --hotfix');
    $ordinary = hotfixPlanJson('--tier STANDARD');

    expect(decodedJsonField($hotfix, 'hotfix'))->toBeTrue();
    expect(decodedJsonField($ordinary, 'hotfix'))->toBeFalse();

    // Same stages, one narrowed mode — a plan that dropped the reviewer would ship an unreviewed
    // emergency change, which is the opposite of the trade the mode makes.
    $expected = [];

    foreach (hotfixPlanStageLabels($ordinary) as $label) {
        $expected[] = $label === 'leonardo:review' ? 'leonardo:review_hotfix' : $label;
    }

    expect(hotfixPlanStageLabels($hotfix))->toBe($expected);
});

test('a hotfix on a sensitive area still buys every stage the tier would buy', function (): void {
    $critical = hotfixPlanJson('--tier CRITICAL --hotfix');

    expect(decodedJsonField($critical, 'tier'))->toBe('CRITICAL');
    expect(hotfixPlanStageLabels($critical))->toContain('deterministic_validation');
    expect(hotfixPlanStageLabels($critical))->toContain('leonardo:review_hotfix');
});
