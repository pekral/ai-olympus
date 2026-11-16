<?php

declare(strict_types = 1);

function businessOnlyAssignmentSource(string $relativePath): string
{
    return (string) preg_replace('/\s+/', ' ', (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath));
}

test('one rule owns the shape of a business-only assignment and no other file restates it', function (): void {
    $rule = businessOnlyAssignmentSource('rules/compound-engineering/assignment.md');
    $statement = 'An assignment reads like a message from a product manager to the team.';
    $owners = array_keys(array_filter(
        packageTextFiles(),
        static fn (string $contents, string $path): bool => preg_match('#^(rules|skills|agents|commands)/#', $path) === 1
            && str_contains((string) preg_replace('/\s+/', ' ', $contents), $statement),
        ARRAY_FILTER_USE_BOTH,
    ));

    expect($owners)->toBe(['rules/compound-engineering/assignment.md'])
        ->and($rule)->toContain('1. **What this is about**')
        ->and($rule)->toContain('2. **What the user must be able to do**')
        ->and($rule)->toContain('3. **The rules**')
        ->and($rule)->toContain('4. **What we are not doing now**')
        ->and($rule)->toContain('5. **Open questions for the owner**')
        ->and($rule)->toContain('A class, file, route, table, index, or query name.')
        ->and($rule)->toContain('A test command, a quality-gate command, or a definition-of-done checklist.')
        ->and($rule)->toContain('**Technical constraints do not disappear.**');
});

test('the rule is scoped like the other on-demand rules, announced in the always-on file, and listed in the docs', function (): void {
    $rule = (string) file_get_contents(dirname(__DIR__, 2) . '/rules/compound-engineering/assignment.md');
    $general = businessOnlyAssignmentSource('rules/compound-engineering/general.md');

    expect($rule)->toStartWith("---\ndescription: ")
        ->and($rule)->toContain("paths:\n  - \".claude/run/**\"\n  - \".claude/rules/compound-engineering/assignment.md\"\n---")
        ->and($general)->toContain('reads and applies `@rules/compound-engineering/assignment.md` first')
        ->and(businessOnlyAssignmentSource('docs/rules.md'))->toContain(
            '[`compound-engineering/assignment.md`](../rules/compound-engineering/assignment.md)',
        );
});

test('every skill that writes or checks an assignment references the rule', function (): void {
    $referencing = [
        'skills/create-issue/SKILL.md',
        'skills/create-issues-from-text/SKILL.md',
        'skills/do-it/SKILL.md',
        'skills/prepare-issue-context/SKILL.md',
        'skills/code-review/references/assignment-conformance-gate.md',
        'rules/compound-engineering/tracker.md',
    ];

    foreach ($referencing as $path) {
        expect(businessOnlyAssignmentSource($path))->toContain('`@rules/compound-engineering/assignment.md`');
    }
});

test('the issue template carries the five business parts in order and no technical section', function (): void {
    $template = (string) file_get_contents(dirname(__DIR__, 2) . '/skills/create-issues-from-text/templates/issue-structure.md');
    $headings = [];

    preg_match_all('/^## (.+)$/m', $template, $headings);

    expect($headings[1])->toBe([
        'What this is about',
        'What the user must be able to do',
        'The rules',
        'What we are not doing now',
        'Open questions for the owner',
        'Dependencies',
    ])
        ->and($template)->not->toContain('Technical Solution')
        ->and($template)->not->toContain('Acceptance Criteria')
        ->and($template)->not->toContain('Testing Scenarios');
});

test('prepare-issue-context works from the business text and keeps the technical result out of the assignment', function (): void {
    $skill = businessOnlyAssignmentSource('skills/prepare-issue-context/SKILL.md');

    expect($skill)->toContain('*What the user must be able to do*, *The rules*, and *Open questions for the owner*')
        ->and($skill)->toContain('Each rule gives at least one scenario, and that includes the error case the rule describes.')
        ->and($skill)->toContain(
            'An assignment with no technical hint is the normal case, so a missing code path or table name in the assignment is never a gap.',
        )
        ->and($skill)->toContain('It is never written back into the assignment.')
        ->and($skill)->toContain('phrased as a plain question for *Open questions for the owner*')
        ->and($skill)->toContain('A purely technical gap stays in the in-conversation report.')
        ->and($skill)->toContain('The skill rewrites the tracker text only when the operator asks for it.')
        ->and($skill)->toContain('The skill creates no link')
        ->and($skill)->toContain('- Every business rule of the assignment has a scenario')
        ->and($skill)->toContain('- Every issue that the assignment mentions is linked natively or reported as a missing link.');
});

test('the rule makes the link check read-only by default and creating a link an L2 write', function (): void {
    $rule = businessOnlyAssignmentSource('rules/compound-engineering/assignment.md');
    $consent = businessOnlyAssignmentSource('rules/compound-engineering/orchestration.md');

    expect($rule)->toContain('**A run reports each missing link with the link type it recommends.**')
        ->and($rule)->toContain('**Creating a link is a tracker write, and it is L2.**')
        ->and($rule)->toContain('so a GitHub relationship other than a sub-issue is reported as not verified')
        ->and($consent)->toContain('| any agent, or the top-level session | Create a native link between two issues of the same tracker')
        ->and($consent)->toContain('only when the operator\'s request in this run names the link');
});

test('the review reads the technical constraints from the project rules when the assignment is business-only', function (): void {
    $rule = businessOnlyAssignmentSource('rules/compound-engineering/assignment.md');
    $gate = businessOnlyAssignmentSource('skills/code-review/references/assignment-conformance-gate.md');

    expect($rule)->toContain('It reads the technical constraints from the project instructions and rules')
        ->and($rule)->toContain('A technical constraint that no part of the assignment states is never an unmet requirement')
        ->and($gate)->toContain('**A business-only assignment**')
        ->and($gate)->toContain('A technical constraint that the assignment does not state is never an unmet requirement');
});

test('the issue-writing skills write new issues in the business-only shape and keep the original text of the operator', function (): void {
    $createIssue = businessOnlyAssignmentSource('skills/create-issue/SKILL.md');
    $fromText = businessOnlyAssignmentSource('skills/create-issues-from-text/SKILL.md');
    $doIt = businessOnlyAssignmentSource('skills/do-it/SKILL.md');

    expect($createIssue)->toContain('a text that carries technical detail is preserved as given and reported as technical')
        ->and($fromText)->toContain('- Preserve original assignment (store in parent or first issue)')
        ->and($fromText)->toContain('Write every issue it derives as a business-only assignment')
        ->and($fromText)->toContain('The technical detail of the original assignment stays in the preserved original text.')
        ->and($doIt)->toContain('as a business-only assignment (`@rules/compound-engineering/assignment.md`)')
        ->and($doIt)->toContain('The technical detail of the instructions stays in the verbatim text under `## Queue source`, never in the issue.')
        ->and($doIt)->toContain('The run creates none, and the report lists each missing link with its recommended type.');
});

test('the package text names no concrete project in the rule or its example', function (): void {
    $rule = (string) file_get_contents(dirname(__DIR__, 2) . '/rules/compound-engineering/assignment.md');

    expect($rule)->not->toMatch('/https?:\/\//')
        ->and($rule)->not->toMatch('/#\d+/');
});
