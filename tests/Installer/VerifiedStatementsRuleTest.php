<?php

declare(strict_types = 1);

test('the always-on general rule owns the verified-statements contract for every output', function (): void {
    $rule = (string) file_get_contents(dirname(__DIR__, 2) . '/rules/general/general.md');

    expect(substr_count($rule, '## Verified statements only'))->toBe(1);
    expect($rule)->toContain('Every output an agent produces states verified facts only.');
    expect($rule)->toContain('**Verified means observed in this run.**');
    expect($rule)->toContain('**Never publish an estimate, an assumption, or a hypothesis.**');
    expect($rule)->toContain('A projection is an estimate too');
    expect($rule)->toContain('**Verify the open point, or report it as not verified.**');
    expect($rule)->toContain('A not-verified point never carries a guessed answer.');
    expect($rule)->toContain('**A choice made without a fact is an open decision.**');
    expect($rule)->toContain('**Verify a claim from another source again, or attribute it.**');
    expect($rule)->toContain('**Report a step that did not run as not done.**');

    // The contract binds every run only while the rule stays always-on.
    expect(ruleExtensionFrontmatter(dirname(__DIR__, 2) . '/rules/general/general.md'))->not->toMatch('/^paths:/m');
});

test('the code review applies the contract instead of publishing assumptions or possible defects', function (): void {
    $rule = codeReviewRuleContents();

    expect($rule)->toContain(
        'Report a point that no check in this run can settle as not verified, with the step that settles it '
        . '— never as an assumption and never as a guess',
    );
    expect($rule)->toContain('it applies `@rules/general/general.md` *Verified statements only* to the review');
    expect($rule)->toContain('**An open point is reported as not verified, never as an assumption.**');
    expect($rule)->toContain('Never publish it as a possible, likely, or confirmed defect.');
    expect($rule)->toContain('never label a finding with a confidence or certainty level');
    expect($rule)->toContain('- **No estimating words.**');
    expect($rule)->not->toContain('*possible* observation');
    expect($rule)->not->toContain('state assumptions instead of guessing');

    // A Critical survives verification only on a verified root cause; an unverified failing path
    // becomes a `Not verified:` entry, never a likely defect under `## Findings`.
    expect($rule)->toContain('**Root Cause** must be verified — reproduced, or traced on the real code path');
    expect($rule)->toContain('or the analysis could not verify the failing path → drop the finding from the report');
    expect($rule)->not->toContain('Probable Root Cause');

    // The header block that carries the open points is listed where the converged comment is defined.
    expect($rule)->toContain('and a `Not verified:` line whenever the run holds a point it could not verify');
});

test('the analysis behind a Critical verification states a verified root cause, never a probable one', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $skill = (string) file_get_contents($packageDir . '/skills/analyze-problem/SKILL.md');
    $template = (string) file_get_contents($packageDir . '/skills/analyze-problem/templates/analysis-report.md');

    expect($skill)->toContain('state the cause this run verified: reproduced, or traced on the real code path');
    expect($skill)->toContain('unranked, with no likelihood and no certainty level');
    expect($template)->toContain('## 4. Not Verified');
    expect($template)->toContain('## 5. Root Cause');
    expect($template)->toContain('**How it was verified:**');
    expect($template)->not->toContain('Certainty level');
    expect($template)->not->toContain('Most probable cause');
});

test('the authorization lens classifies findings by evidence, never by confidence', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $skill = (string) file_get_contents($packageDir . '/skills/laravel-authorization-review/SKILL.md');
    $template = (string) file_get_contents($packageDir . '/skills/laravel-authorization-review/templates/report.md');

    expect($skill)->toContain('| Signal | Evidence | CR severity |');
    expect($skill)->toContain('> **Evidence rule.** *Structural* = you can point at the missing layer structurally');
    expect($skill)->toContain('## Evidence & honesty');
    expect($skill)->not->toContain('confidence');
    expect($template)->toContain('- **Not verified:** whether any authenticated user may edit any post');
    expect($template)->not->toContain('Likely IDOR');
});

test('no rule, skill, agent, or command asks an output for a confidence slot, an assumption, or an estimate', function (): void {
    // Each phrase is an output slot the verified-statements contract removed. A new template or
    // skill that reintroduces one would ask the agent for exactly the guess the contract forbids.
    $forbidden = [
        '**Confidence**',
        'Confidence:',
        '| Confidence |',
        'confidence **',
        'Confidence of match',
        'Certainty level',
        'Probable Root Cause',
        'Most probable cause',
        'Assumption:',
        'state the assumption',
        'states the assumption',
        'state assumptions',
        'scheduling assumption',
        'important assumptions',
        'material assumptions',
        '~tokens',
        '~Tokens',
        'estimated duration',
        'Expected impact',
        'expected impact',
    ];

    $violations = [];

    foreach (packageTextFiles() as $relativePath => $contents) {
        if (preg_match('#^(rules|skills|agents|commands)/.*\.md$#', $relativePath) !== 1) {
            continue;
        }

        foreach ($forbidden as $phrase) {
            if (str_contains($contents, $phrase)) {
                $violations[] = $relativePath . ' :: ' . $phrase;
            }
        }
    }

    expect($violations)->toBe([]);
});
