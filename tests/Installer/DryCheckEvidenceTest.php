<?php

declare(strict_types = 1);

test('the dry check searches every new rule in the whole codebase and publishes its evidence', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $rule = (string) file_get_contents($packageDir . '/rules/code-review/general.md');

    expect($rule)->toContain('### DRY check — every new rule is searched for in the whole codebase')
        ->and($rule)->toContain('Searching only inside the diff, or only for the same method name, is not a DRY check.')
        ->and($rule)->toContain('2. **Search for the rule by its mechanics, not by its name.**')
        ->and($rule)->toContain('3. **Check the siblings explicitly.**')
        ->and($rule)->toContain('4. **Treat variants as the same rule.**')
        ->and($rule)->toContain('A verdict of zero DRY findings without this list counts as a check that did not run.')
        ->and($rule)->toContain('**Consolidation does not offset duplication.**')
        ->and($rule)->toContain('`## Input consistency` and `## Reuse (DRY)` per *Input consistency and reuse* below.');

    foreach (['skills/code-review-github/templates/pr-comment-output.md', 'skills/code-review/templates/review-output.md'] as $template) {
        expect((string) file_get_contents($packageDir . '/' . $template))
            ->toContain('## Reuse (DRY)')
            ->toContain('**Siblings opened:**');
    }
});

test('the dry check stays generic and names no concrete project', function (): void {
    $rule = (string) file_get_contents(dirname(__DIR__, 2) . '/rules/code-review/general.md');

    expect($rule)->not->toContain('importShopCustomers')
        ->and($rule)->not->toContain('getExistingEmailsByList')
        ->and($rule)->not->toContain('app/Services/Integrations');
});
