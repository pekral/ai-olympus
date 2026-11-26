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
        'never from `descriptionOriginalText`, except a security requirement that only the original states: it stays a requirement, and the report names it.',
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

test('the loader returns the original apart from the sections, and its self-test proves it', function (): void {
    $loader = packageSource('skills/code-review-jira/scripts/load-issue.sh');

    expect($loader)->toContain('"descriptionAssignmentText": "<descriptionText without the Original assignment block>"')
        ->and($loader)->toContain('"descriptionOriginalText": "<the text of the Original assignment block>" | null')
        ->and($loader)->toContain('"descriptionSections": [ { "title", "text" } ]')
        ->and($loader)->toContain('include "description-shape";')
        ->and($loader)->toContain('if ($descNodes | hasWrittenShape) then $descNodes[-1] else null end')
        ->and($loader)->not->toContain('IN("Original assignment", "Původní zadání")')
        ->and($loader)->toContain('check \'the last Original assignment block is the original text\'')
        ->and($loader)->toContain('check \'an original-titled block after a free paragraph is not an original\'')
        ->and($loader)->toContain('check \'an original-titled block after a section outside the fixed list is not an original\'')
        ->and($loader)->toContain('check \'a section title of one language before an original title of the other is not an original\'')
        ->and($loader)->toContain('check \'the assignment text leaves the original block out\'')
        ->and($loader)->toContain('check \'the sections list the collapsed sections without the original\'');
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

test('the writer and the loader share one definition of the original block, which needs the shape the writer produces', function (): void {
    $shape = packageSource('skills/code-review-jira/scripts/description-shape.jq');

    expect($shape)->toContain('def originalTitles: ["Original assignment", "Původní zadání"];')
        ->and($shape)->toContain('def hasWrittenShape:')
        ->and($shape)->toContain('if length < 3')
        ->and($shape)->toContain('(.[0] | type != "object" or (.type // "") != "paragraph")')
        ->and($shape)->toContain('sectionTitles($lang) | index($t) != null')
        ->and(normalizedPackageSource('rules/compound-engineering/assignment.md'))->toContain(
            'The loader takes the last block for the original only when everything before it has the shape the script writes',
        )
        ->and(normalizedPackageSource('rules/compound-engineering/assignment.md'))->toContain(
            'a security requirement that only the original states stays a requirement',
        )
        ->and(normalizedPackageSource('rules/compound-engineering/assignment.md'))->toContain(
            'A second rewrite keeps that block unchanged and replaces the opening paragraph and the sections of the first rewrite with its new text.',
        );
});

test('the rewrite helper is shipped, executable, and runs its self-test in the build', function (): void {
    $script = dirname(__DIR__, 2) . '/skills/code-review-jira/scripts/update-description.sh';

    expect(is_executable($script))->toBeTrue()
        ->and(packageSource('skills/code-review-jira/scripts/update-description.sh'))->toStartWith('#!/usr/bin/env bash')
        ->and(packageSource('composer.json'))->toContain(' skills/code-review-jira/scripts/update-description.sh ');
});

test('the rewrite helper writes one key with one edit command and checks the shape before it writes', function (): void {
    $script = packageSource('skills/code-review-jira/scripts/update-description.sh');

    expect($script)->toContain('acli jira workitem edit --key "$1" --description-file "$2" --yes')
        ->and($script)->not->toContain('--jql')
        ->and($script)->not->toContain('--from-json')
        ->and($script)->not->toContain('--filter')
        ->and($script)->toContain(
            '([a-z][a-z0-9+.-]*://|mailto:|www\\\\.|\\\\b[\\\\p{L}\\\\p{N}-]+(\\\\.[\\\\p{L}\\\\p{N}-]+)*\\\\.\\\\p{L}{2,}/)',
        )
        ->and($script)->toContain('select((.content? // null) | type == "array")')
        ->and($script)->not->toContain('select((.type // "") == "text") | (.text // "") | select(test(')
        ->and($script)->toContain('previous_adf "$prev"')
        ->and($script)->toContain('The previous description is on stdout as canonical ADF; restore it by hand.')
        ->and($script)->toContain('"a link written as text"')
        ->and($script)->toContain('the description is no longer the document this script wrote')
        ->and($script)->toContain('^[A-Z][A-Z0-9_]+-[0-9]+$')
        ->and($script)->toContain('refused — the URL host is not the JIRA site of the acli account')
        ->and($script)->toContain('"bulletList", "blockquote", "codeBlock", "heading", "mediaGroup", "mediaSingle", "orderedList",')
        ->and($script)->toContain('"panel", "paragraph", "rule", "table", "multiBodiedExtension", "extensionFrame", "nestedExpand"')
        ->and($script)->toContain('include "description-shape";')
        ->and($script)->toContain('if ($n | hasWrittenShape) then { mode: "kept"')
        ->and($script)->not->toContain('def originalTitles')
        ->and($script)->toContain('bash "$AUDIT_TRAIL" record --target "acli jira workitem edit --key $1 --description-file"');
});

test('the rewrite self-test covers every refusal, the verbatim original, the fingerprint, and the write-back', function (string $label): void {
    expect(packageSource('skills/code-review-jira/scripts/update-description.sh'))->toContain($label);
})->with([
    'audit line' => 'the rewrite records an external-write audit line',
    'change before the write' => 'a change just before the write writes nothing and exits 6',
    'edit after the write kept' => 'an edit by another account after the write is not overwritten, and the run exits 4',
    'failed read-back' => 'the failed read-back writes the previous description back once',
    'fixed title' => 'the original block is the last node and carries the fixed Czech title',
    'forbidden node or mark' => 'a new text with a $bad problem is refused with exit 5 and nothing is written',
    'foreign host' => 'a URL outside the acli site is refused with exit 1',
    'forged original stays on the ticket' => 'a previous description of kind $case_name stays on the ticket',
    'host with a path, non-ASCII and punycode' => 'url_idn) reason=\'a link written as text\'',
    'host with a path and no scheme' => 'url_host) reason=\'a link written as text\'',
    'idempotent' => 'a second rewrite keeps the first original and never wraps it again',
    'key list' => 'a list of keys is refused with exit 1',
    'node outside the allow-list' => 'an original with a $case_name node is refused with exit 5 and nothing is written',
    'node type named' => 'the refusal of a task list names taskList',
    'one edit of one key' => 'the write is the one edit of one key with a description file and --yes',
    'original not last or twice' => 'a previous description of kind $case_name is refused with exit 5',
    'previous description on stdout, late' => 'the previous description is on stdout as canonical ADF when nothing is written back (late)',
    'previous description on stdout, lossy' => 'the previous description is on stdout as canonical ADF when nothing is written back (lossy)',
    'stale fingerprint' => 'a fingerprint of another version writes nothing and exits 6',
    'stand-in first in PATH' => 'the stand-in acli is not first in PATH; nothing was run',
    'store that differs kept' => 'a store that differs from the written document is not overwritten either',
    'text that only looks like a URL' => 'a text with a version, an abbreviation, a ratio, and a slash between words is written',
    'url split by a mark' => 'url_em) reason=\'a link written as text\'',
    'url with www in a mark' => 'url_www) reason=\'a link written as text\'',
    'verbatim original' => 'the original block holds the previous top-level nodes, equal after canonical JSON',
]);
