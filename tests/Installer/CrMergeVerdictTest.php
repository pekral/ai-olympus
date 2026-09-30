<?php

declare(strict_types = 1);

test('every code review comment answers whether the change can be merged with the assignment met and no critical finding', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $rule = (string) file_get_contents($packageDir . '/rules/code-review/general.md');
    $contract = (string) file_get_contents($packageDir . '/skills/code-review-github/references/cr-wrapper-contract.md');
    $agent = (string) file_get_contents($packageDir . '/agents/leonardo.md');

    expect($rule)->toContain('**Merge verdict — the direct answer to "can we merge, and is the assignment met with no Critical finding?".**')
        ->and($rule)->toContain('1. the header block — `Status:`, `Counts:`, `Merge verdict:`')
        ->and($rule)->toContain('`@skills/merge-github-pr/SKILL.md` still runs its own merge gate, and a `yes` never lifts it.');

    expect($contract)->toContain('- **`Merge verdict:` header line.** The posted PR comment always carries it, directly under `Counts:`');

    expect($agent)->toContain('**Merge-verdict agenda:**');

    foreach (['skills/code-review-github/templates/pr-comment-output.md', 'skills/code-review/templates/review-output.md'] as $template) {
        expect((string) file_get_contents($packageDir . '/' . $template))
            ->toContain('**Merge verdict:** {yes | no} — assignment met: {yes | no | no linked issue} · open Critical: {n}');
    }
});
