<?php

declare(strict_types = 1);

test('test-assignment runs the assignment test through splinter, donatello, raphael, and april', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $command = (string) file_get_contents($packageDir . '/commands/test-assignment.md');
    $skill = (string) file_get_contents($packageDir . '/skills/test-assignment/SKILL.md');
    $splinter = (string) file_get_contents($packageDir . '/agents/splinter.md');
    $april = (string) file_get_contents($packageDir . '/agents/april.md');
    $raphael = (string) file_get_contents($packageDir . '/agents/raphael.md');

    expect($command)->toContain('$ARGUMENTS')
        ->and($command)->toContain('`@skills/test-assignment/SKILL.md`')
        ->and($command)->toContain('`report` (the default) or `fix`')
        ->and($command)->toContain('Never merge.')
        ->and($command)->toContain('$test-assignment');

    expect($skill)->toContain('### 2. Map every input that reaches the changed behaviour')
        ->and($skill)->toContain('Use real data only')
        ->and($skill)->toContain('It sends the same request set to the base branch.')
        ->and($skill)->toContain('dispatch `april` in *Test report mode*')
        ->and($skill)->toContain('continue with `@skills/verify-merge-readiness/SKILL.md` from its step 2');

    expect($splinter)->toContain('## Test mode — `/test-assignment`')
        ->and($splinter)->toContain('`Test report done`');
    expect($april)->toContain('## Test report mode')
        ->and($april)->toContain('upsert-comment.sh <PR> - test-report');
    expect($raphael)->toContain('when the dispatch asks for a base-branch comparison (`@skills/test-assignment/SKILL.md`)');
});
