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
        "h2. Merge report\n\nThe PR is ready at head {{d916d56}}.\n\nh3. How to test\n\n# Sign in.\n\n* Tests: 267\n",
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
    $document = jiraAdfConvert("*Check whether the {{modify}} webhook sends the header.*\n");
    $paragraph = jiraAdfChildren($document);
    $nodes = jiraAdfChildren($paragraph[0] ?? null);

    expect(array_column($nodes, 'text'))->toBe(['Check whether the ', 'modify', ' webhook sends the header.']);
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
    expect($helper)->toContain('acli jira workitem comment delete --key "$KEY" --id "$TARGET_ID"');
    expect($helper)->toContain('do not fall back to a raw acli write — use the JIRA MCP server with an ADF payload');
    // A renamed envelope key in the create response must not abort the publish.
    expect($helper)->toContain('[.. | objects | .id? | select(type == "string" or type == "number")] | first');
    expect($helper)->toContain('if [[ ! "$TARGET_ID" =~ ^[0-9]+$ ]]; then');
});

test('the merge-readiness TL;DR is published through the helper that matches the source tracker', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $april = (string) file_get_contents($packageDir . '/agents/april.md');
    $skill = (string) file_get_contents($packageDir . '/skills/verify-merge-readiness/SKILL.md');
    $rule = (string) file_get_contents($packageDir . '/rules/jira/general.md');

    foreach ([$april, $skill] as $document) {
        expect($document)->toContain('skills/code-review-jira/scripts/upsert-comment.sh <KEY|URL> -');
        expect($document)->toContain('pr-summary-jira.md');
        expect($document)->toContain('the only sanctioned');
    }

    // A JIRA source cleans its superseded duplicates through the JIRA helper, never a raw acli delete.
    foreach ([$april, $skill] as $document) {
        expect($document)->toContain('skills/code-review-jira/scripts/delete-owned-comment.sh <KEY|URL> <COMMENT_ID> <FINAL_TLDR_ID> <CURRENT_CR_ID>');
        expect($document)->toContain('`acli jira workitem comment delete`');
        expect($document)->toContain('every remaining marker-carrying comment by the actor is a protected ID');
    }

    expect($rule)->toContain('**Delete a comment only through `skills/code-review-jira/scripts/delete-owned-comment.sh`.**');
    expect($rule)->toContain('never from the `acli jira workitem comment list` body alone');

    expect($rule)->toContain('A helper that fails is never a licence to improvise.');
    expect($rule)->toContain('A GitHub-shaped instruction on a JIRA source is re-routed, never followed literally.');
});

test('april runs three publication checks before a JIRA comment counts as published (issue #118)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $april = (string) file_get_contents($packageDir . '/agents/april.md');

    expect($april)->toContain('**On a JIRA target, three publication checks are yours and nobody else\'s.**');

    // 1. The banned-list walk, and the one thing it must not do instead of removing a hit.
    expect($april)->toContain('**Walk the body against the banned list before the write, and remove what you find.**');
    expect($april)->toContain('**Remove each hit — never annotate it**');
    expect($april)->toContain('shorten `What changed` when it overflows, never `How to test`');

    // 2. ADF publication.
    expect($april)->toContain('**Publish as ADF.**');
    expect($april)->toContain('acli jira workitem comment update --body-adf');

    // 3. The structural read-back, and the verdict a flat body produces.
    expect($april)->toContain('**Read the comment back and confirm its structure, not only that it exists.**');
    expect($april)->toContain('real `heading`, `bulletList`, and `listItem` nodes');
    expect($april)->toContain('**A single `paragraph` of flat text means the conversion failed**');
    expect($april)->toContain('**publication failure, not a cosmetic one**');

    // The read the check needs is granted in april's own Bash boundary, and it stays a read.
    $boundary = installerDocsSection($april, '## Bash boundary');
    expect($boundary)->toContain('acli jira workitem view <KEY> --fields comment --json');
    expect($boundary)->toContain('never an `acli` write');
});

test('the reporting headline goes where the target template opens, not into a Problem field JIRA no longer has (issue #118)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $april = (string) file_get_contents($packageDir . '/agents/april.md');
    $splinter = splinterContractText();

    // Both files instructed the headline into the `Problem` field on every target. The JIRA
    // template has no such field now, and it does carry a slot of its own.
    expect($april)->not->toContain('the same position on every target, because every target renders the same structure');
    expect($april)->toContain('the **status sentence above `h2. Acceptance criteria`** — so the headline goes there');
    expect($splinter)->not->toContain('the opening sentence of the `Problem` field is');
    expect($splinter)->toContain('the status sentence above `h2. Acceptance criteria` on JIRA');
});
