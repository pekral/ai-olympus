<?php

declare(strict_types = 1);

const ASSIGNMENT_SECTION_NAMES = [
    'What this is about',
    'What the user must be able to do',
    'The rules',
    'What we are not doing now',
    'Open questions for the owner',
    'Dependencies',
    'Original assignment',
];

function assignmentLayoutFile(string $relativePath): string
{
    return (string) file_get_contents(__DIR__ . '/../' . $relativePath);
}

const ASSIGNMENT_OPENING_SECTION = 'What this is about';

const ASSIGNMENT_COLLAPSED_SECTION = 'Original assignment';

function assignmentBlock(string $name, string $content, bool $open): string
{
    $summary = "\n<summary><strong>" . $name . "</strong></summary>\n<dl>\n<dd>\n<br>\n\n";

    return '<details' . ($open ? ' open' : '') . '>' . $summary . $content . "\n\n</dd>\n</dl>\n</details>";
}

/**
 * Renders sections the way `rules/compound-engineering/assignment.md` *The shape in a GitHub issue body* states:
 * the opening section as plain text, an open block for each business section, and the original assignment collapsed.
 *
 * @param array<string, string> $sections section name to content
 */
function assignmentRenderLayout(array $sections): string
{
    $parts = [];

    foreach ($sections as $name => $content) {
        $parts[] = $name === ASSIGNMENT_OPENING_SECTION
            ? $content
            : assignmentBlock($name, $content, $name !== ASSIGNMENT_COLLAPSED_SECTION);
    }

    return implode("\n\n", $parts) . "\n";
}

/**
 * Renders every section as a collapsed block, the shape of a body that holds no business section.
 *
 * @param array<string, string> $sections section name to content
 */
function assignmentRenderCollapsed(array $sections): string
{
    return implode("\n\n", array_map(
        static fn (string $name, string $content): string => assignmentBlock($name, $content, open: false),
        array_keys($sections),
        $sections,
    )) . "\n";
}

/**
 * Names what is wrong with one block: a collapsed business section, or an open original assignment.
 */
function assignmentBlockViolation(string $name, bool $isOpen): ?string
{
    if ($name === ASSIGNMENT_COLLAPSED_SECTION) {
        return $isOpen ? 'the original assignment is open' : null;
    }

    return $isOpen ? null : 'the section "' . $name . '" is collapsed';
}

/**
 * Lists what breaks the layout rule: a body that does not open with plain text, a business block that is collapsed,
 * and an original assignment that is open.
 *
 * @return list<string>
 */
function assignmentLayoutViolations(string $body): array
{
    $violations = ltrim($body) === '' || str_starts_with(ltrim($body), '<details')
        ? ['the body does not start with plain text']
        : [];

    preg_match_all('#<details( open)?>\n<summary><strong>(.+?)</strong></summary>#', $body, $blocks, PREG_SET_ORDER);

    foreach ($blocks as $block) {
        $violation = assignmentBlockViolation($block[2], $block[1] === ' open');

        if ($violation !== null) {
            $violations[] = $violation;
        }
    }

    return $violations;
}

/**
 * Reads the sections of an issue body in either form: the opening text and the blocks, and the plain `## name` heading of an older body.
 *
 * @return array<string, string> section name to content
 */
function assignmentSections(string $body): array
{
    $sections = [];

    preg_match_all(
        '#<details(?: open)?>\n<summary><strong>(.+?)</strong></summary>\n<dl>\n<dd>\n<br>\n\n(.*?)\n\n</dd>\n</dl>\n</details>#s',
        $body,
        $blocks,
        PREG_SET_ORDER,
    );

    if ($blocks !== []) {
        $opening = trim(explode('<details', $body, 2)[0]);

        if ($opening !== '') {
            $sections[ASSIGNMENT_OPENING_SECTION] = $opening;
        }

        foreach ($blocks as $match) {
            $sections[$match[1]] = $match[2];
        }

        return $sections;
    }

    preg_match_all('/^## ([^\n]+)\n(.*?)(?=^## |\z)/ms', $body, $plain, PREG_SET_ORDER);

    foreach ($plain as $match) {
        $sections[$match[1]] = trim($match[2]);
    }

    return $sections;
}

/**
 * @return array<string, string>
 */
function assignmentFixtureSections(): array
{
    $sections = [];

    $pairs = json_decode(
        assignmentLayoutFile('Fixtures/assignment-layout/invitation-sections.json'),
        associative: true,
        depth: 512,
        flags: JSON_THROW_ON_ERROR,
    );
    assert(is_array($pairs));

    foreach ($pairs as $pair) {
        assert(is_array($pair) && is_string($pair[0]) && is_string($pair[1]));
        $sections[$pair[0]] = $pair[1];
    }

    return $sections;
}

test('the issue template opens with plain text, then open blocks, and the original assignment is the one collapsed block', function (): void {
    $template = assignmentLayoutFile('../skills/create-issues-from-text/templates/issue-structure.md');
    $sections = assignmentSections($template);

    expect(array_keys($sections))->toBe(ASSIGNMENT_SECTION_NAMES)
        ->and($template)->toStartWith('<The problem today, who has it, and why it is fixed>')
        ->and(substr_count($template, '<details open>'))->toBe(5)
        ->and(substr_count($template, '<details>'))->toBe(1)
        ->and(substr_count($template, '</details>'))->toBe(6)
        ->and(assignmentLayoutViolations($template))->toBe([])
        ->and($template)->not->toMatch('/^#{1,6} /m')
        ->and($template)->not->toContain('What this is about')
        ->and(assignmentRenderLayout($sections))->toBe($template)
        ->and($template)->not->toContain('Technical Solution')
        ->and($template)->not->toContain('Acceptance Criteria')
        ->and($template)->not->toContain('Testing Scenarios');
});

test('the preserved-text issue body is two collapsed blocks and no plain heading', function (): void {
    $template = assignmentLayoutFile('../skills/create-issue/templates/issue-body.md');
    $sections = assignmentSections($template);

    expect(array_keys($sections))->toBe(['Task', 'Notes'])
        ->and(substr_count($template, '<details>'))->toBe(2)
        ->and(substr_count($template, '<details open>'))->toBe(0)
        ->and(assignmentRenderCollapsed($sections))->toBe($template)
        ->and($template)->not->toMatch('/^#{1,6} /m');
});

test('an example assignment renders byte for byte as the committed issue body', function (): void {
    $body = assignmentLayoutFile('Fixtures/assignment-layout/invitation-issue.md');

    expect(assignmentRenderLayout(assignmentFixtureSections()))->toBe($body)
        ->and($body)->toStartWith('A workspace owner cannot invite a colleague today.')
        ->and(assignmentLayoutViolations($body))->toBe([]);
});

test('the layout check fails on a body that does not start with the plain text', function (): void {
    $body = assignmentLayoutFile('Fixtures/assignment-layout/invitation-issue.md');
    $opening = assignmentFixtureSections()[ASSIGNMENT_OPENING_SECTION];

    expect(assignmentLayoutViolations(str_replace($opening . "\n\n", '', $body)))
        ->toBe(['the body does not start with plain text'])
        ->and(assignmentLayoutViolations(assignmentRenderCollapsed(assignmentFixtureSections())))
        ->toContain('the body does not start with plain text');
});

test('the layout check fails on a business section that is collapsed', function (): void {
    $body = assignmentLayoutFile('Fixtures/assignment-layout/invitation-issue.md');
    $collapsed = str_replace(
        "<details open>\n<summary><strong>The rules</strong>",
        "<details>\n<summary><strong>The rules</strong>",
        $body,
    );

    expect(assignmentLayoutViolations($collapsed))->toBe(['the section "The rules" is collapsed']);
});

test('the layout check fails on an original assignment that is open', function (): void {
    $body = assignmentLayoutFile('Fixtures/assignment-layout/invitation-issue.md');
    $open = str_replace(
        "<details>\n<summary><strong>Original assignment</strong>",
        "<details open>\n<summary><strong>Original assignment</strong>",
        $body,
    );

    expect(assignmentLayoutViolations($open))->toBe(['the original assignment is open']);
});

test('the readers find every section of a body by its name, and the Dependencies section among them', function (): void {
    $sections = assignmentSections(assignmentLayoutFile('Fixtures/assignment-layout/invitation-issue.md'));

    expect($sections)->toBe(assignmentFixtureSections())
        ->and($sections['Dependencies'])->toBe('- None.')
        ->and($sections['The rules'])->toContain('"This invitation is no longer valid"');
});

test('a body written before the block shape, with plain headings, holds the same sections', function (): void {
    expect(assignmentSections(assignmentLayoutFile('Fixtures/assignment-layout/invitation-issue-legacy.md')))
        ->toBe(assignmentFixtureSections());
});

test('a section left out keeps no empty block', function (): void {
    $sections = assignmentFixtureSections();
    unset($sections['Open questions for the owner'], $sections['Original assignment']);
    $body = assignmentRenderLayout($sections);

    expect(array_keys(assignmentSections($body)))->toBe([
        'What this is about',
        'What the user must be able to do',
        'The rules',
        'What we are not doing now',
        'Dependencies',
    ])
        ->and(assignmentLayoutViolations($body))->toBe([])
        ->and($body)->not->toContain('Open questions')
        ->and($body)->not->toMatch('/<dd>\n<br>\n\n\n/');
});

test('a parent reference after the last block leaves the opening text untouched, and one before the opening text does not', function (): void {
    $body = assignmentLayoutFile('Fixtures/assignment-layout/invitation-issue.md');
    $parentReference = 'Part of #12';

    $after = $body . "\n" . $parentReference . "\n";
    $before = $parentReference . "\n\n" . $body;

    expect(assignmentSections($after))->toBe(assignmentFixtureSections())
        ->and(assignmentLayoutViolations($after))->toBe([])
        ->and(assignmentSections($before))->not->toBe(assignmentFixtureSections())
        ->and(assignmentSections($before)[ASSIGNMENT_OPENING_SECTION])->toStartWith($parentReference);
});

test('the skill places the parent reference and the sub-issue list after the last block, and the rule says such text is no section', function (): void {
    $skill = assignmentLayoutFile('../skills/create-issues-from-text/SKILL.md');
    $rule = assignmentLayoutFile('../rules/compound-engineering/assignment.md');

    expect($skill)->toContain('Place the line after the last block of the body, never before the opening text')
        ->and($skill)->toContain('Place the heading and the list after the last block of the parent body, never before the opening text.')
        ->and($rule)->toContain('Text after the last block, such as a `Part of #<parent>` line or a `## Sub-issues` list, belongs to no section.');
});

test('the rule owns the shape once and the skills point to it', function (): void {
    $rule = assignmentLayoutFile('../rules/compound-engineering/assignment.md');
    $heading = '### The shape in a GitHub issue body — the opening text, open blocks, and a collapsed original';
    $section = installerDocsSection($rule, $heading);

    expect(substr_count($rule, $heading))->toBe(1)
        ->and($section)->toContain('The body opens with *What this is about* as plain text.')
        ->and($section)->toContain('with no heading and no `<summary>`')
        ->and($section)->toContain('A section is a `<details open>` block whose `<summary>` is `<strong>{section name}</strong>`')
        ->and($section)->toContain('The preserved original is the only collapsed block.')
        ->and($section)->toContain('*Original assignment* is a plain `<details>` block, with no `open`, and it is the last block.')
        ->and($section)->toContain('The summary carries the section name, verbatim.')
        ->and($section)->toContain('A part with nothing to say is left out, and no block is empty.')
        ->and($section)->toContain('A body written before this shape, with a plain `## <name>` heading for every section, is read the same way')
        ->and($section)->toContain('A tracker that renders no HTML takes plain headings.')
        ->and(assignmentLayoutFile('../skills/create-issues-from-text/SKILL.md'))->toContain(ltrim($heading, '# '))
        ->and(assignmentLayoutFile('../skills/create-issues-from-text/SKILL.md'))->toContain('which is the only collapsed one')
        ->and(assignmentLayoutFile('../skills/create-issue/SKILL.md'))->toContain('Every section of the body is a collapsed block');
});
