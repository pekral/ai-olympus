<?php

declare(strict_types = 1);

function analyzeSupportSource(string $relativePath): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
}

test('the local-run guard compares the head with the default-branch ref that git alone resolves', function (): void {
    $skill = analyzeSupportSource('skills/analyze-support-issue/SKILL.md');
    $start = (int) strpos($skill, '5. **Local run.**');
    $step = substr($skill, $start, (int) strpos($skill, '### 5. Choose one verdict per problem') - $start);

    expect($step)->toContain('`skills/merge-github-pr/references/merge-exemptions.md` *The trusted classifier* item 3')
        ->and($step)->toContain('with git only')
        ->and($step)->toContain('set `REF` to it in the same shell call')
        ->and($step)->toContain('`git rev-parse HEAD` to equal `git rev-parse "${REF:?}"`')
        ->and($step)->toContain('An empty `REF` runs nothing')
        ->and($step)->not->toContain('git rev-parse origin/<default>')
        ->and($step)->not->toContain('read-manifest.sh');
});
