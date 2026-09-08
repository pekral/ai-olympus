<?php

declare(strict_types = 1);

use Symfony\Component\Process\Process;

/**
 * @return array<string, mixed>
 */
function jiraAdfConvert(string $wikiMarkup): array
{
    $packageDir = dirname(__DIR__, 2);
    $process = new Process(
        ['php', $packageDir . '/skills/code-review-jira/scripts/wiki-markup-to-adf.php'],
        $packageDir,
    );
    $process->setInput($wikiMarkup);
    $process->mustRun();

    /** @var array<string, mixed> $document */
    $document = (array) json_decode($process->getOutput(), associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);

    return $document;
}

/**
 * @return array<int, mixed>
 */
function jiraAdfChildren(mixed $node): array
{
    if (!is_array($node)) {
        return [];
    }

    $children = $node['content'] ?? [];

    return is_array($children) ? array_values($children) : [];
}

/**
 * @return array<int, mixed>
 */
function jiraAdfMarks(mixed $node): array
{
    $marks = is_array($node) ? ($node['marks'] ?? null) : null;

    return is_array($marks) ? array_values($marks) : [];
}

/**
 * @return list<string>
 */
function jiraAdfMarkTypes(mixed $node): array
{
    $types = [];

    foreach (jiraAdfMarks($node) as $mark) {
        $type = is_array($mark) ? ($mark['type'] ?? null) : null;

        if (is_string($type)) {
            $types[] = $type;
        }
    }

    return $types;
}

function jiraAdfLinkHref(mixed $node): string
{
    foreach (jiraAdfMarks($node) as $mark) {
        $attrs = is_array($mark) ? ($mark['attrs'] ?? null) : null;
        $href = is_array($attrs) ? ($attrs['href'] ?? null) : null;

        if (is_string($href)) {
            return $href;
        }
    }

    return '';
}

test('the converter turns the report markup into an ADF document rather than literal text', function (): void {
    $document = jiraAdfConvert(
        "h2. Hlášení k mergi\n\nPR je připraven na headu {{d916d56}}.\n\nh3. Jak otestovat\n\n# Přihlas se.\n\n* Testy: 267\n",
    );
    $encoded = json_encode($document, JSON_THROW_ON_ERROR);

    expect($document['version'])->toBe(1);
    expect($document['type'])->toBe('doc');
    expect(array_column(jiraAdfChildren($document), 'type'))
        ->toBe(['heading', 'paragraph', 'heading', 'orderedList', 'bulletList']);

    // The markup reaches JIRA as structure, never as the literal prefixes a reader would see.
    expect($encoded)->not->toContain('h2.');
    expect($encoded)->not->toContain('{{d916d56}}');
});

test('nested inline markup reaches ADF as its own mark instead of leaking as literal Wiki Markup', function (): void {
    $document = jiraAdfConvert("*Ověřit, zda {{modify}} webhook posílá hlavičku.*\n");
    $paragraph = jiraAdfChildren($document);
    $nodes = jiraAdfChildren($paragraph[0] ?? null);

    expect(array_column($nodes, 'text'))->toBe(['Ověřit, zda ', 'modify', ' webhook posílá hlavičku.']);
    expect(jiraAdfMarkTypes($nodes[0] ?? null))->toBe(['strong']);
    expect(jiraAdfMarkTypes($nodes[1] ?? null))->toBe(['code', 'strong']);
});

test('a link label carrying inline code keeps both marks on the nested span', function (): void {
    $document = jiraAdfConvert("Viz [odkaz s {{kódem}}|https://example.test/x].\n");
    $paragraph = jiraAdfChildren($document);
    $nodes = jiraAdfChildren($paragraph[0] ?? null);

    expect(jiraAdfMarkTypes($nodes[2] ?? null))->toBe(['code', 'link']);
    expect(jiraAdfLinkHref($nodes[2] ?? null))->toBe('https://example.test/x');
});

test('the JIRA publish helper never leaves an unformatted comment behind and forbids improvising', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $helper = (string) file_get_contents($packageDir . '/skills/code-review-jira/scripts/upsert-comment.sh');

    // A failed ADF update used to leave the created comment in place and exit 3, which is what
    // tempts a caller to improvise a raw plain-text `acli` write.
    expect($helper)->toContain('acli jira workitem comment delete --key "$KEY" --id "$NEW_ID"');
    expect($helper)->toContain('do not fall back to a raw acli write — use the JIRA MCP server with an ADF payload');
    // A renamed envelope key in the create response must not abort the publish.
    expect($helper)->toContain('[.. | objects | .id? | select(type == "string" or type == "number")] | first');
    expect($helper)->toContain('if [[ ! "$NEW_ID" =~ ^[0-9]+$ ]]; then');
});

test('the merge-readiness TL;DR is published through the helper that matches the source tracker', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $hermes = (string) file_get_contents($packageDir . '/agents/hermes.md');
    $skill = (string) file_get_contents($packageDir . '/skills/prepare-issue-for-merge/SKILL.md');
    $rule = (string) file_get_contents($packageDir . '/rules/jira/general.md');

    foreach ([$hermes, $skill] as $document) {
        expect($document)->toContain('skills/code-review-jira/scripts/upsert-comment.sh <KEY|URL> -');
        expect($document)->toContain('pr-summary-jira.md');
        expect($document)->toContain('the only sanctioned');
    }

    // Deletion stays GitHub-only, so a JIRA source publishes the TL;DR and deletes nothing.
    expect($hermes)->toContain('**Steps 4–7 are GitHub-only**');
    expect($hermes)->toContain('deletes nothing, and says so in the handoff');
    expect($skill)->toContain('The deletion pass below is **GitHub-only**');
    expect($skill)->toContain('deletes nothing, and reports that');

    expect($rule)->toContain('A helper that fails is never a licence to improvise.');
    expect($rule)->toContain('A GitHub-shaped instruction on a JIRA source is re-routed, never followed literally.');
});
