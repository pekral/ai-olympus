<?php

declare(strict_types = 1);

test('one rule section owns the real-problem, minimal-API, and proving-test principle', function (): void {
    $owner = (string) file_get_contents(dirname(__DIR__, 2) . '/rules/compound-engineering/general.md');

    expect(substr_count($owner, "\n## A change needs a real problem, a minimal API, and a proving test\n"))->toBe(1)
        ->and(substr_count($owner, '**A real problem first.**'))->toBe(1)
        ->and(substr_count($owner, 'The statement carries evidence observed in the run'))->toBe(1)
        ->and(substr_count($owner, '**No unnecessary commitment in the API.**'))->toBe(1)
        ->and(
            substr_count(
                $owner,
                'Do not add a public method, parameter, option, return field, event, config key, route, or response field that no current caller uses.',
            ),
        )->toBe(
            1,
        )
        ->and(substr_count($owner, 'Keep a new member private or internal until a caller needs it.'))->toBe(1)
        ->and(substr_count($owner, '**Behaviour proven by a test.** A behaviour the change adds or fixes is proven by a test'))->toBe(1)
        ->and(substr_count($owner, 'is proven by the existing tests staying green (`@rules/refactoring/general.md`)'))->toBe(1)
        ->and(substr_count($owner, '`@rules/code-testing/general.md` *Test Value* owns the value of each test'))->toBe(1);
});

test('the principle is stated in the owner only and the existing rules are referenced', function (): void {
    $files = packageTextFiles();
    $statements = array_filter(
        $files,
        static fn (string $contents, string $path): bool => preg_match('#^(rules|skills|agents|commands)/#', $path) === 1
            && str_contains($contents, 'The public surface grows only as far as the problem needs.'),
        ARRAY_FILTER_USE_BOTH,
    );

    expect(array_keys($statements))->toBe(['rules/compound-engineering/general.md']);

    $owner = $files['rules/compound-engineering/general.md'];

    $references = [
        '`@rules/php/core-standards.md` *Design Principles*',
        '`@rules/code-testing/general.md` *Acceptance-Criteria Test Contract*',
        '`@rules/code-review/core-analysis.md` *Simplicity First*',
        '`@skills/test-driven-development/SKILL.md`',
    ];

    foreach ($references as $reference) {
        expect($owner)->toContain($reference);
    }
});

test('the review entry makes an unused public member Moderate and gates it against the existing rules', function (): void {
    $files = packageTextFiles();
    $owner = $files['rules/compound-engineering/general.md'];

    expect(substr_count($owner, '- **A public member that no caller uses**'))->toBe(1)
        ->and(substr_count($owner, 'no current non-test caller uses and no acceptance criterion names — is a **Moderate** finding'))->toBe(1)
        ->and(substr_count($owner, '  - **Gating — one finding per line:** a speculative parameter and a speculative project interface'))->toBe(1)
        ->and(substr_count($owner, 'an abstraction, flag, option, config key, or extension point belongs to'))->toBe(1)
        ->and(substr_count($owner, '*Method visibility wider than its callers need*, and a member widened for a test'))->toBe(1)
        ->and(substr_count($owner, 'This entry owns the remaining members only.'))->toBe(1)
        ->and(substr_count($owner, 'a test that passes without the change is the test-value lens finding'))->toBe(1)
        ->and(substr_count($owner, '- **A behaviour with no proving test, or a change with no real problem**'))->toBe(1)
        ->and(substr_count($owner, 'this section raises no second one'))->toBe(1)
        ->and(substr_count($owner, '*Acceptance-criteria use-case coverage* finding (Critical)'))->toBe(1)
        ->and(
            substr_count(
                $files['rules/code-review/core-analysis.md'],
                '`@rules/compound-engineering/general.md` *Code Review Application* finding, raised there once.',
            ),
        )->toBe(
            1,
        );
});
