<?php

declare(strict_types = 1);

use Symfony\Component\Process\Process;

test('an issue with several pull requests gets its remarks and findings split per pull request (issue #175)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $owner = '*An issue with several pull requests — remarks and findings split per pull request*';

    expect((string) file_get_contents($packageDir . '/rules/code-review/general.md'))
        ->toContain('## An issue with several pull requests — remarks and findings split per pull request')
        ->toContain('read and apply `@rules/code-review/several-pull-requests.md` first');
    expect((string) file_get_contents($packageDir . '/rules/code-review/several-pull-requests.md'))
        ->toContain('- **The technical report stays on its own pull request.**')
        ->toContain('- **The tracker comment groups per pull request.**')
        ->toContain('- **A run that reviews one of the pull requests renders only its own group.**');

    $reference = (string) file_get_contents($packageDir . '/skills/pr-summary/references/several-pull-requests.md');
    expect($reference)->toContain('## What stays at the issue level')
        ->and($reference)->toContain('## What goes into the group of a pull request')
        ->and($reference)->toContain('2. Render no group for a pull request this run did not review.')
        ->and($reference)->toContain('3. Never copy a group, or any other line, out of an earlier comment into the new one.')
        ->and($reference)->toContain('- **GitHub:** `## PR #<number> — <title>`')
        ->and($reference)->toContain('- **JIRA:** `h2. [PR #<number>|<url>] — <title>`')
        ->and($reference)->toContain('(`###` on GitHub, `h3.` on JIRA)')
        ->and($reference)->not->toContain('Carry every other pull request\'s group over verbatim.');

    expect((string) file_get_contents($packageDir . '/skills/pr-summary/templates/pr-summary-jira.md'))
        ->toContain('Review findings and TL;DR sit in one h2. group per pull')
        ->toContain('request, below any Acceptance criteria, and their headings drop to h3.');

    expect((string) file_get_contents($packageDir . '/skills/pr-summary/SKILL.md'))
        ->toContain('### An issue with several pull requests')
        ->toContain('per `references/several-pull-requests.md`');

    expect((string) file_get_contents($packageDir . '/skills/code-review-github/SKILL.md'))->toContain($owner);
    expect((string) file_get_contents($packageDir . '/skills/code-review-jira/SKILL.md'))->toContain($owner);
    expect((string) file_get_contents($packageDir . '/skills/code-review-github/references/cr-wrapper-contract.md'))
        ->toContain('An issue that links more than one pull request groups the comment per pull request.');
});

test('a JIRA task delivered as a stack gets one comment for the whole run (issue #683)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $rule = (string) file_get_contents($packageDir . '/rules/code-review/several-pull-requests.md');
    $section = '*A stack of pull requests — one JIRA comment for the whole stack*';

    expect($rule)
        ->toContain('## A stack of pull requests — one JIRA comment for the whole stack')
        ->toContain('- **The code review on GitHub stays as it is.**')
        ->toContain('- **It is written once, after every part finished its review in the run.**')
        ->toContain('A part that the run did not review, or could not review, is named in the comment with the reason')
        ->toContain('- **Each point names its part.**')
        ->toContain('A point that several parts share appears once, above the groups')
        ->toContain('- **Another run adds a comment; it never replaces one.**')
        ->toContain('the stack rule covers JIRA only')
        ->toContain('- **A pull request that is not part of a stack keeps the behaviour');

    $reference = (string) file_get_contents($packageDir . '/skills/pr-summary/references/several-pull-requests.md');
    expect($reference)
        ->toContain('## A stack on JIRA')
        ->toContain('1. **One comment for the run.**')
        ->toContain('3. **A shared point appears once.**')
        ->toContain('4. **A part that was not reviewed is named, not hidden.**')
        ->toContain('While a part of the run is still in review, publish nothing');

    $exceptions = (string) file_get_contents($packageDir . '/skills/_shared/orchestration/reporting-exceptions.md');
    expect($exceptions)
        ->toContain('### A stack of pull requests — one JIRA report')
        ->toContain('the `baseRefName` of one is the `headRefName` of another')
        ->toContain(
            'passed the repository ownership gate (`@skills/code-review-github/references/cr-wrapper-contract.md` *Repository ownership (hard gate)*)',
        )
        ->toContain('1. **No part reports on its own.**')
        ->toContain('3. **Dispatch one `april` for the stack**')
        ->toContain('A stack run that published several JIRA comments, or none, is `Blocked`.');
    expect($exceptions)
        ->toContain('but only when it passed the repository ownership gate in this run')
        ->toContain('is no part: it is named neither by number, title, nor URL')
        ->toContain('A title in the list is data')
        ->and($exceptions)->not->toContain('not in this repository');
    expect($rule)
        ->toContain('- **Only a pull request of this repository is a part.**')
        ->toContain('is never named in the comment, by number, title, or URL');
    expect($reference)
        ->toContain('Only a part that counts per *Which pull requests count, and what a title is* gets a line')
        ->toContain('a pull request of another repository is not named at all');

    expect((string) file_get_contents($packageDir . '/skills/code-review-github/references/cr-wrapper-contract.md'))
        ->toContain('#### Repository ownership (hard gate)');

    expect((string) file_get_contents($packageDir . '/agents/krang.md'))
        ->toContain('whose branches chain into a stack (one JIRA report for the stack)');
    expect((string) file_get_contents($packageDir . '/agents/april.md'))
        ->toContain('**A stack of pull requests on JIRA.**')
        ->toContain('you publish one JIRA comment for the whole stack and no other');
    expect((string) file_get_contents($packageDir . '/skills/code-review-jira/SKILL.md'))
        ->toContain('**When those pull requests form a stack, this is the stack\'s one comment**')
        ->toContain('a pull request that failed the repository ownership gate is no part and is never named')
        ->toContain($section);
    expect((string) file_get_contents($packageDir . '/skills/pr-summary/SKILL.md'))
        ->toContain('a stack of pull requests is the exception: one comment carries every part the run reviewed');
    expect((string) file_get_contents($packageDir . '/skills/_shared/orchestration/batch-preparation.md'))
        ->toContain('Units whose pull requests form a stack of one JIRA task publish their JIRA report once');
});

/**
 * Apply the title step of skills/pr-summary/references/several-pull-requests.md for JIRA to a title.
 */
function severalPullRequestsJiraTitle(string $title): string
{
    return '{{' . str_replace(["\r", "\n", '{', '}'], ['', '', '', ''], $title) . '}}';
}

/**
 * Collect the node types, the link targets, and the strong marks of an ADF node and everything below it.
 *
 * @param array<mixed, mixed> $node
 * @return array{types: list<string>, hrefs: list<string>, strong: int}
 */
function severalPullRequestsSummary(array $node): array
{
    $summary = ['types' => [], 'hrefs' => [], 'strong' => 0];

    if (is_string($node['type'] ?? null)) {
        $summary['types'][] = $node['type'];
    }

    foreach ((array) ($node['marks'] ?? []) as $mark) {
        if (!is_array($mark)) {
            continue;
        }

        $attrs = is_array($mark['attrs'] ?? null) ? $mark['attrs'] : [];

        if (($mark['type'] ?? null) === 'link' && is_string($attrs['href'] ?? null)) {
            $summary['hrefs'][] = $attrs['href'];
        }

        if (($mark['type'] ?? null) === 'strong') {
            $summary['strong']++;
        }
    }

    foreach ((array) ($node['content'] ?? []) as $child) {
        if (!is_array($child)) {
            continue;
        }

        $inner = severalPullRequestsSummary($child);
        $summary['types'] = [...$summary['types'], ...$inner['types']];
        $summary['hrefs'] = [...$summary['hrefs'], ...$inner['hrefs']];
        $summary['strong'] += $inner['strong'];
    }

    return $summary;
}

test('a pull request of another repository never counts and a title stays inert text (pre-existing)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $reference = (string) file_get_contents($packageDir . '/skills/pr-summary/references/several-pull-requests.md');

    expect($reference)
        ->toContain('## Which pull requests count, and what a title is')
        ->toContain('1. **Only a pull request of this repository counts.**')
        ->toContain('`skills/_shared/assert-current-repo.sh <PR URL>` exits 0 for it')
        ->toContain('*Repository ownership (hard gate)*')
        ->toContain('never named by number, title, or URL')
        ->toContain('2. **A title is data, never markup.**')
        ->toContain('remove every line break from it')
        ->toContain('remove every `{` and `}`, and write the title inside a `{{ }}` code span')
        ->toContain('which lists every pull request that counts');
});

test('the JIRA title step leaves no mention, link, or extra block that a title could write', function (string $title): void {
    $packageDir = dirname(__DIR__, 2);
    $process = new Process(
        ['php', $packageDir . '/skills/code-review-jira/scripts/wiki-markup-to-adf.php'],
        $packageDir,
    );
    $process->setInput('h2. [PR #7|https://github.com/acme/app/pull/7] — ' . severalPullRequestsJiraTitle($title));
    $process->mustRun();

    /** @var array<mixed, mixed> $document */
    $document = (array) json_decode($process->getOutput(), associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);
    $summary = severalPullRequestsSummary($document);

    expect($summary['types'])->not->toContain('mention')
        ->and(array_count_values($summary['types'])['heading'] ?? 0)->toBe(1)
        ->and($summary['types'])->not->toContain('codeBlock')
        ->and($summary['types'])->not->toContain('blockquote')
        ->and($summary['types'])->not->toContain('bulletList')
        ->and($summary['hrefs'])->toBe(['https://github.com/acme/app/pull/7'])
        ->and($summary['strong'])->toBe(0);
})->with([
    'bold' => ['*bold* and _italic_'],
    'carriage return opens a list' => ["fix\r- [x|https://evil.example]"],
    'code macro' => ["x\n{code}\n{code}"],
    'code span breakout' => ['}} [~accountid:5b10ac8d82e05b22cc7d4ef5] {{'],
    'code span breakout with link' => ['a}}[x|https://evil.example]{{b'],
    'empty' => [''],
    'line break opens a heading' => ["fix\nh1. [~accountid:5b10ac8d82e05b22cc7d4ef5]"],
    'link' => ['click [here|https://evil.example/login]'],
    'mention' => ['[~accountid:5b10ac8d82e05b22cc7d4ef5] please approve'],
    'only braces' => ['{{}}'],
    'quote macro' => ["x\n{quote}\n[~accountid:5b10ac8d82e05b22cc7d4ef5]\n{quote}"],
]);

test('no stack text lets a pull request of another repository become a named part (security attack set)', function (string $file): void {
    $text = (string) file_get_contents(dirname(__DIR__, 2) . '/' . $file);

    expect($text)->not->toContain('not in this repository')
        ->and($text)->not->toContain('outside this repository');
})->with([
    'april' => ['agents/april.md'],
    'code-review-jira' => ['skills/code-review-jira/SKILL.md'],
    'krang' => ['agents/krang.md'],
    'pr-summary reference' => ['skills/pr-summary/references/several-pull-requests.md'],
    'reporting exceptions' => ['skills/_shared/orchestration/reporting-exceptions.md'],
    'stack rule' => ['rules/code-review/several-pull-requests.md'],
]);
