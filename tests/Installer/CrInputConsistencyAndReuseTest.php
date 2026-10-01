<?php

declare(strict_types = 1);

test('every code review comment answers whether input is stored consistently and whether existing code can be reused', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $rule = (string) file_get_contents($packageDir . '/rules/code-review/general.md');
    $analysis = (string) file_get_contents($packageDir . '/rules/code-review/core-analysis.md');
    $contract = (string) file_get_contents($packageDir . '/skills/code-review-github/references/cr-wrapper-contract.md');
    $agent = (string) file_get_contents($packageDir . '/agents/leonardo.md');

    expect($rule)->toContain('**Input consistency and reuse — two questions every review answers.**')
        ->and($rule)->toContain('`Merge verdict:`, `Input consistency:`, `Reuse (DRY):`');

    expect($analysis)->toContain('- **Input consistency — mandatory write-path walk.**')
        ->and($analysis)->toContain('An entry point that bypasses the change is a finding. Severity: **Critical**');

    expect($contract)->toContain('- **`Input consistency:` and `Reuse (DRY):` header lines.**');

    expect($agent)->toContain('**Input-consistency and reuse agenda:**');

    foreach (['skills/code-review-github/templates/pr-comment-output.md', 'skills/code-review/templates/review-output.md'] as $template) {
        expect((string) file_get_contents($packageDir . '/' . $template))
            ->toContain('**Input consistency:** {yes | no | not applicable — the diff writes no persisted data}')
            ->toContain('**Reuse (DRY):** {no duplication found |');
    }
});
