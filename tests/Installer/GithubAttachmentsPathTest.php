<?php

declare(strict_types = 1);

test('the GitHub issue loaders point to the download script and its scan gate for attachment content', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $resolveIssue = (string) file_get_contents($packageDir . '/skills/resolve-issue/SKILL.md');
    $gatherer = (string) file_get_contents($packageDir . '/skills/code-review-github/scripts/gather-issue-context.sh');

    expect($resolveIssue)->toContain(
        'Get attachment content only through `skills/code-review-github/scripts/download-attachments.sh <URL>` and open only the files its security gate (`scan-attachments.sh`) promoted into `safe/`',
    )
        ->and($resolveIssue)->not->toContain('Read attachment content and the inventoried URLs with your own tools')
        ->and($gatherer)->toContain('Fetch it safely with \`skills/code-review-github/scripts/download-attachments.sh <URL>\`')
        ->and($gatherer)->toContain('promoted into \`safe/\`')
        ->and($gatherer)->not->toContain('Fetch it with your own tools.');
});
