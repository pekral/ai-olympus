<?php

declare(strict_types = 1);

test('the GitHub issue loaders point to the download script and its scan gate for attachment content', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $resolveIssue = (string) file_get_contents($packageDir . '/skills/resolve-issue/SKILL.md');
    $gatherer = (string) file_get_contents($packageDir . '/skills/code-review-github/scripts/gather-issue-context.sh');

    expect($resolveIssue)->toContain('Fetch attachments only through `skills/code-review-github/scripts/download-attachments.sh`')
        ->and($resolveIssue)->toContain('open only scan-promoted `safe/` files')
        ->and($resolveIssue)->not->toContain('Read attachment content and the inventoried URLs with your own tools')
        ->and($resolveIssue)->toContain('to a sensible depth.' . "\n")
        ->and(strpos($resolveIssue, 'fall back to the GitHub MCP server.'))->toBeLessThan(strpos($resolveIssue, 'Fetch attachments only through'))
        ->and($gatherer)->toContain('Fetch it safely with \`skills/code-review-github/scripts/download-attachments.sh <URL>\`')
        ->and($gatherer)->toContain('promoted into \`safe/\`')
        ->and($gatherer)->not->toContain('Fetch it with your own tools.');
});
