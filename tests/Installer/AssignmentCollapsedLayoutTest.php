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

/**
 * Renders sections the way `rules/compound-engineering/assignment.md` *The shape in a GitHub issue body* states.
 *
 * @param array<string, string> $sections section name to content
 */
function assignmentRenderCollapsed(array $sections): string
{
    $blocks = [];

    foreach ($sections as $name => $content) {
        $blocks[] = "<details>\n<summary><strong>" . $name . "</strong></summary>\n<dl>\n<dd>\n<br>\n\n" . $content . "\n\n</dd>\n</dl>\n</details>";
    }

    return implode("\n\n", $blocks) . "\n";
}

/**
 * Reads the sections of an issue body in either form: the collapsed block, and the plain `## name` heading of an older body.
 *
 * @return array<string, string> section name to content
 */
function assignmentSections(string $body): array
{
    $sections = [];

    preg_match_all(
        '#<details>\n<summary><strong>(.+?)</strong></summary>\n<dl>\n<dd>\n<br>\n\n(.*?)\n\n</dd>\n</dl>\n</details>#s',
        $body,
        $collapsed,
        PREG_SET_ORDER,
    );

    foreach ($collapsed as $match) {
        $sections[$match[1]] = $match[2];
    }

    if ($sections !== []) {
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

test('the issue template renders every section as a collapsed block in the order of the rule', function (): void {
    $template = assignmentLayoutFile('../skills/create-issues-from-text/templates/issue-structure.md');
    $sections = assignmentSections($template);

    expect(array_keys($sections))->toBe(ASSIGNMENT_SECTION_NAMES)
        ->and(substr_count($template, '<details>'))->toBe(7)
        ->and(substr_count($template, '</details>'))->toBe(7)
        ->and($template)->not->toMatch('/^#{1,6} /m')
        ->and(assignmentRenderCollapsed($sections))->toBe($template)
        ->and($template)->not->toContain('Technical Solution')
        ->and($template)->not->toContain('Acceptance Criteria')
        ->and($template)->not->toContain('Testing Scenarios');
});

test('the preserved-text issue body is two collapsed blocks and no plain heading', function (): void {
    $template = assignmentLayoutFile('../skills/create-issue/templates/issue-body.md');
    $sections = assignmentSections($template);

    expect(array_keys($sections))->toBe(['Task', 'Notes'])
        ->and(assignmentRenderCollapsed($sections))->toBe($template)
        ->and($template)->not->toMatch('/^#{1,6} /m');
});

test('an example assignment renders byte for byte as the committed collapsed issue body', function (): void {
    expect(assignmentRenderCollapsed(assignmentFixtureSections()))
        ->toBe(assignmentLayoutFile('Fixtures/assignment-layout/invitation-issue.md'));
});

test('the readers find every section of a collapsed body by its name, and the Dependencies section among them', function (): void {
    $sections = assignmentSections(assignmentLayoutFile('Fixtures/assignment-layout/invitation-issue.md'));

    expect($sections)->toBe(assignmentFixtureSections())
        ->and($sections['Dependencies'])->toBe('- None.')
        ->and($sections['The rules'])->toContain('"This invitation is no longer valid"');
});

test('a body written before the collapsed shape, with plain headings, holds the same sections', function (): void {
    expect(assignmentSections(assignmentLayoutFile('Fixtures/assignment-layout/invitation-issue-legacy.md')))
        ->toBe(assignmentFixtureSections());
});

test('a section left out keeps no empty block', function (): void {
    $sections = assignmentFixtureSections();
    unset($sections['Open questions for the owner'], $sections['Original assignment']);
    $body = assignmentRenderCollapsed($sections);

    expect(array_keys(assignmentSections($body)))->toBe([
        'What this is about',
        'What the user must be able to do',
        'The rules',
        'What we are not doing now',
        'Dependencies',
    ])
        ->and($body)->not->toContain('Open questions')
        ->and($body)->not->toMatch('/<dd>\n<br>\n\n\n/');
});

test('the rule owns the collapsed shape once and the skills point to it', function (): void {
    $rule = assignmentLayoutFile('../rules/compound-engineering/assignment.md');
    $section = installerDocsSection($rule, '### The shape in a GitHub issue body — every section is a collapsed block');

    expect(substr_count($rule, '### The shape in a GitHub issue body — every section is a collapsed block'))->toBe(1)
        ->and($section)->toContain('A section is a `<details>` block whose `<summary>` is `<strong>{section name}</strong>`')
        ->and($section)->toContain('The summary carries the section name, verbatim.')
        ->and($section)->toContain('A part with nothing to say is left out, and no block is empty.')
        ->and($section)->toContain('The preserved original is the last block, *Original assignment*.')
        ->and($section)->toContain('A body written before this shape, with a plain `## <name>` heading, is read the same way')
        ->and($section)->toContain('A tracker that renders no HTML takes plain headings.')
        ->and(assignmentLayoutFile('../skills/create-issues-from-text/SKILL.md'))->toContain('every section of it is a collapsed block')
        ->and(assignmentLayoutFile('../skills/create-issue/SKILL.md'))->toContain('Every section of the body is a collapsed block');
});
