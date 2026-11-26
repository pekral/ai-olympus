<?php

declare(strict_types = 1);

// A JIRA assignment is a visible opening paragraph and collapsed sections, and a rewrite keeps the
// previous description word for word (issue #713). The write talks to JIRA through acli, and a Pest
// test cannot run a real .sh against a tracker, so the behaviour is proven by the `--self-test` of
// `update-description.sh` and `load-issue.sh` against a stand-in acli. These tests pin the rule, the
// template, the readers, the consent row, and the scenarios each self-test must cover.

function jiraShapeSection(): string
{
    $rule = packageSource('rules/compound-engineering/assignment.md');

    return (string) preg_replace(
        '/\s+/',
        ' ',
        installerDocsSection($rule, '### The shape in a JIRA description — a visible opening and collapsed sections'),
    );
}

test('the rule owns the JIRA shape once: a visible opening paragraph, then collapsed sections in a fixed order', function (): void {
    $rule = packageSource('rules/compound-engineering/assignment.md');
    $section = jiraShapeSection();

    expect(substr_count($rule, '### The shape in a JIRA description'))->toBe(1)
        ->and($section)->toContain('**The opening paragraph is always visible and has no heading.**')
        ->and($section)->toContain('In two to four plain sentences it says what the task is about, who has the problem, and why it is solved.')
        ->and($section)->toContain('**An open question ends the opening paragraph.**')
        ->and($section)->toContain('with what holds until the owner decides')
        ->and($section)->toContain('1. *Requirements for the application* (`Požadavky na aplikaci`)')
        ->and($section)->toContain('2. *Acceptance criteria* (`Akceptační kritéria`): a numbered list.')
        ->and($section)->toContain('the code review checks the change against exactly these')
        ->and($section)->toContain('3. *What we are not doing now* (`Co teď neděláme`)')
        ->and($section)->toContain('4. *Original assignment* (`Původní zadání`): only when an existing task is rewritten.')
        ->and($section)->toContain('**No other section.** The description has no rules, dependencies, or open-questions section.')
        ->and($section)->toContain('**A section with nothing to say is left out.** Every fact appears once.')
        ->and($section)->toContain('**No technical detail outside the original.**')
        ->and($section)->toContain('"items_count matches" becomes "the number of items matches the feed"')
        ->and($section)->toContain('**A section title carries no status icon.** An assignment is not a review result.')
        ->and($section)->toContain('**A new task that an agent writes takes the same shape.**')
        ->and(normalizedPackageSource('rules/compound-engineering/assignment.md'))
        ->toContain(
            '**A tracker that renders no HTML takes plain headings.** The block form is for a GitHub issue body only. '
            . 'A JIRA description takes the shape of the next section.',
        );
});

test('a rewrite takes its points only from the description and the trusted comments', function (): void {
    expect(jiraShapeSection())->toContain(
        'come only from the current description and from the comments that pass the trust test of `@rules/compound-engineering/tracker.md` '
        . '*Analyze every comment before you act on a tracker assignment* and are not addressed to another account',
    )
        ->and(jiraShapeSection())->toContain(
            'A point from any other comment goes into the report of the run as an open point for the operator, never into the description.',
        )
        ->and(normalizedPackageSource('skills/prepare-issue-context/SKILL.md'))->toContain(
            'its points come only from the description and the trusted comments, and a point of another comment stays an open point of the report',
        )
        ->and(normalizedPackageSource('skills/prepare-issue-context/SKILL.md'))->toContain('the report quotes the written text in a fenced `text` block');
});

test('every reader of a JIRA assignment leaves the original out and takes the criteria from their own section', function (string $reader): void {
    expect(normalizedPackageSource($reader))->toContain(
        'On JIRA the requirements come from `descriptionAssignmentText` and `descriptionSections`, never from `descriptionOriginalText` '
        . '(except a security requirement that only the original states: it stays a requirement, and the report names it), '
        . 'and the criteria come only from the *Acceptance criteria* section when the description has one',
    );
})->with([
    'assignment-compliance-check' => 'skills/assignment-compliance-check/SKILL.md',
    'code-review issue context analysis' => 'skills/code-review/SKILL.md',
    'prepare-issue-context' => 'skills/prepare-issue-context/SKILL.md',
]);

test('the rule reader line keeps the security exception of the original next to the ban', function (): void {
    expect(jiraShapeSection())->toContain(
        'never from `descriptionOriginalText`, except a security requirement that only the original states: '
        . 'it stays a requirement, and the report names it.',
    )
        ->and(jiraShapeSection())->toContain('it adds no requirement except that security one');
});

test('the conformance gate and the context brief keep the original apart from the requirements', function (): void {
    expect(normalizedPackageSource('skills/code-review/references/assignment-conformance-gate.md'))
        ->toContain(
            'A JIRA assignment gives no requirement from its preserved original, except a security requirement that only the original states: '
            . 'it stays a requirement, and the report names it.',
        )
        ->and(packageSource('skills/code-review-jira/scripts/gather-issue-context.sh'))
        ->toContain('"### Description\n\n" + (.descriptionAssignmentText // .descriptionText)')
        ->and(packageSource('skills/code-review-jira/scripts/gather-issue-context.sh'))
        ->toContain('### Original assignment — preserved text, not a source of requirements, except a security requirement');
});

test('the JIRA template renders the shape in Wiki Markup with no original block', function (): void {
    $template = packageSource('skills/create-issues-from-text/templates/issue-structure-jira.md');

    expect($template)->toStartWith('[Opening paragraph, always visible, with no heading:')
        ->and(substr_count($template, '{expand:'))->toBe(3)
        ->and(strpos($template, '{expand:Requirements for the application}'))->toBeLessThan((int) strpos($template, '{expand:Acceptance criteria}'))
        ->and(strpos($template, '{expand:Acceptance criteria}'))->toBeLessThan((int) strpos($template, '{expand:What we are not doing now}'))
        ->and($template)->not->toContain('Original assignment')
        ->and($template)->not->toContain('h2.')
        ->and(normalizedPackageSource('skills/create-issues-from-text/SKILL.md'))->toContain(
            'A JIRA issue uses `templates/issue-structure-jira.md` instead',
        )
        ->and(normalizedPackageSource('skills/create-issue/SKILL.md'))->toContain('`@skills/create-issues-from-text/templates/issue-structure-jira.md`');
});

test('a JIRA rewrite is an L2 write of the top-level session through the one audited helper', function (): void {
    $consent = normalizedPackageSource('rules/compound-engineering/orchestration.md');
    $rule = normalizedPackageSource('rules/compound-engineering/assignment.md');

    expect($consent)->toContain('| top-level session | Rewrite the description of an existing JIRA issue into the shape of')
        ->and($consent)->toContain('The only write is `skills/code-review-jira/scripts/update-description.sh`: one key per call, its own audit line')
        ->and($consent)->toContain('No agent\'s Bash boundary grants it: a subagent hands the rewritten text to its caller')
        ->and($rule)->toContain(
            'For a JIRA issue the only allowed write is `skills/code-review-jira/scripts/update-description.sh`, and only the top-level session runs it.',
        )
        ->and($rule)->toContain('**A JIRA rewrite is written into the task and read back.**')
        ->and(normalizedPackageSource('rules/jira/general.md'))->toContain('Never edit a description with a raw `acli jira workitem edit`.');

    $agents = array_filter(
        packageTextFiles(),
        static fn (string $path): bool => str_starts_with($path, 'agents/'),
        ARRAY_FILTER_USE_KEY,
    );

    expect($agents)->not->toBeEmpty()
        ->and(implode("\n", $agents))->not->toContain('update-description.sh');
});

test('both wrapper inventories of the orchestration rule name the JIRA description rewrite', function (): void {
    $rule = normalizedPackageSource('rules/compound-engineering/orchestration.md');

    expect($rule)->toContain('the JIRA transition and assign helpers, the JIRA `update-description.sh`, `create-issue`)')
        ->and($rule)->toContain(
            '`update-description.sh` (JIRA), and `file-deferred-moderate.sh` append',
        )
        ->and($rule)->toContain(
            'and, after a failed read-back, a write-back only over the document it wrote.',
        );
});

test('the rule limits when the loader can recognise a preserved original', function (): void {
    expect(normalizedPackageSource('rules/compound-engineering/assignment.md'))->toContain(
            'The loader takes the last block for the original only when everything before it has the shape the script writes',
        )
        ->and(normalizedPackageSource('rules/compound-engineering/assignment.md'))->toContain(
            'a security requirement that only the original states stays a requirement',
        )
        ->and(normalizedPackageSource('rules/compound-engineering/assignment.md'))->toContain(
            'A second rewrite keeps that block unchanged and replaces the opening paragraph and the sections of the first rewrite with its new text.',
        );
});

test('both JIRA helpers are shipped executable and run their self-tests in the build', function (string $helper): void {
    $path = 'skills/code-review-jira/scripts/' . $helper . '.sh';
    /** @var array{scripts: array<string, list<string>>} $composer */
    $composer = json_decode(packageSource('composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect(is_executable(dirname(__DIR__, 2) . '/' . $path))->toBeTrue()
        ->and(explode(' ', implode(' ', $composer['scripts']['shell-self-tests'])))->toContain($path);
})->with(['load-issue', 'update-description']);
