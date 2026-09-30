<?php

declare(strict_types = 1);

test('the code review reports every untouched part of the application whose behaviour the diff changes', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $walk = (string) file_get_contents($packageDir . '/rules/code-review/core-analysis.md');
    $agent = (string) file_get_contents($packageDir . '/agents/leonardo.md');
    $contract = (string) file_get_contents($packageDir . '/skills/code-review-github/references/cr-wrapper-contract.md');
    $loop = (string) file_get_contents($packageDir . '/skills/process-code-review/SKILL.md');
    $rule = (string) file_get_contents($packageDir . '/rules/code-review/general.md');

    expect($walk)->toContain('- **Behaviour changed outside the diff — mandatory impact walk.**')
        ->and($walk)->toContain('Search the whole project for every caller and consumer of each symbol outside the diff.')
        ->and($walk)->toContain(
            'An affected part the assignment asks for is an entry, not a finding. An affected part the assignment does not ask for is a finding.',
        );

    expect($agent)->toContain('**Impact agenda:** report every untouched part of the application whose behaviour the current diff changes.');

    expect($contract)->toContain('- **`## Affected behaviour outside the diff` section.** Render this section on the PR comment, converged or not');

    expect($loop)->toContain('- **`## Affected behaviour outside the diff`** — only when the last review round listed at least one affected part');

    expect($rule)->toContain('4. `## Affected behaviour outside the diff`, `## Deferred to sub-issues`');

    foreach (['skills/code-review-github/templates/pr-comment-output.md', 'skills/code-review/templates/review-output.md'] as $template) {
        expect((string) file_get_contents($packageDir . '/' . $template))
            ->toContain("## Affected behaviour outside the diff\n\n> Render only when the impact walk");
    }
});
