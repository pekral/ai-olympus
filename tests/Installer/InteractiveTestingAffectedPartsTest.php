<?php

declare(strict_types = 1);

function affectedPartsRead(string $relativePath): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
}

test('the affected-parts reference takes its sources in order and points to their owners', function (): void {
    $reference = affectedPartsRead('skills/interactive-testing/references/affected-parts.md');

    expect($reference)->toContain('## Build the list before the walkthrough')
        ->toContain('`## Affected behaviour outside the diff`')
        ->toContain('`@rules/code-review/core-analysis.md` *Behaviour changed outside the diff — mandatory impact walk*')
        ->toContain('`@skills/blast-radius-analysis/SKILL.md`')
        ->toContain('**Your own trace.**')
        ->toContain('shared components, models, queries, routes, policies, events, jobs, configuration, and translations')
        ->toContain('`CR`, `blast radius`, or `own trace`');

    expect(mb_strpos($reference, '**The current code review.**'))->toBeLessThan((int) mb_strpos($reference, '**The blast-radius report.**'));
    expect(mb_strpos($reference, '**The blast-radius report.**'))->toBeLessThan((int) mb_strpos($reference, '**Your own trace.**'));
});

test('the affected-parts reference turns user-visible parts into regression scenarios that count toward Passed', function (): void {
    $reference = affectedPartsRead('skills/interactive-testing/references/affected-parts.md');

    expect($reference)->toContain('its entry point (a URL or a UI path)')
        ->toContain('its expected unchanged behaviour')
        ->toContain('listed as `not testable through the UI`, with the reason')
        ->toContain('Walk every regression scenario in the interactive browser.')
        ->toContain('A regression scenario counts toward the overall `Passed` verdict.')
        ->toContain('`Passed`, `Failed`, `Blocked`, or `not testable through the UI`')
        ->toContain('only with evidence that links it to the change');
});

test('the interactive-testing skill applies the affected-parts reference in its scenarios, walkthrough, report and done-when', function (): void {
    $skill = affectedPartsRead('skills/interactive-testing/SKILL.md');

    expect($skill)->toContain('Build the list of affected parts before the walkthrough')
        ->toContain('`@skills/interactive-testing/references/affected-parts.md`')
        ->toContain('Walk every regression scenario of the affected parts in the browser')
        ->toContain('- **Affected parts:** one row per affected part with its source (`CR`, `blast radius`, or `own trace`)')
        ->toContain('- Every affected part has a verdict');

    expect($skill)->not->toContain('`## Affected behaviour outside the diff`');
    expect(file_exists(dirname(__DIR__, 2) . '/skills/interactive-testing/references/affected-parts.md'))->toBeTrue();
});

test('raphael carries the Affected parts section in its output and walks the parts', function (): void {
    $raphael = affectedPartsRead('agents/raphael.md');

    expect($raphael)->toContain('**Walk every user-visible part the change can affect**')
        ->toContain('`@skills/interactive-testing/references/affected-parts.md`')
        ->toContain('- **Affected parts:** one row per affected part')
        ->toContain('`not testable through the UI`');

    expect(mb_strpos($raphael, '- **Result:**'))->toBeLessThan((int) mb_strpos($raphael, '- **Affected parts:**'));
    expect(mb_strpos($raphael, '- **Affected parts:**'))->toBeLessThan((int) mb_strpos($raphael, '- **Evidence:**'));
});
