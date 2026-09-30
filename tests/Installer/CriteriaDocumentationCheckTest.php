<?php

declare(strict_types = 1);

test('the acceptance criteria are compared with the product documentation in review and in testing', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $rule = (string) file_get_contents($packageDir . '/rules/code-review/general.md');
    $compliance = (string) file_get_contents($packageDir . '/skills/assignment-compliance-check/SKILL.md');
    $testing = (string) file_get_contents($packageDir . '/skills/test-assignment/SKILL.md');

    expect($rule)->toContain('- **Compare the acceptance criteria with the documentation, not only the change.**')
        ->and($rule)->toContain(
            '`consistent` with the article URL, `contradicts` with the article URL and the sentence, or `not documented` with what was searched',
        )
        ->and($rule)->toContain('Four shapes count, and each is one finding')
        ->and($rule)->toContain('or an acceptance criterion itself asks for a behaviour the documentation describes differently');

    expect($compliance)->toContain('### 2a. Compare every requirement with the product documentation')
        ->and($compliance)->toContain('Return the statuses to the caller beside the block, never inside it.')
        ->and($compliance)->toContain('It is not a Critical gap, and it does not change the verdict of this block.');

    expect($testing)->toContain('Compare every criterion that describes user-facing behaviour with the product documentation the project declares')
        ->and($testing)->toContain('- **Documentation:** each criterion with its documentation status and article URL.');
});
