<?php

declare(strict_types = 1);

use Symfony\Component\Process\Process;

function inlineReviewSource(string $relativePath): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
}

function inlineReviewContract(): string
{
    return installerDocsSection(
        inlineReviewSource('skills/code-review-github/references/cr-wrapper-contract.md'),
        '#### Findings in the review body',
    );
}

test('the inline review wrapper exists, is executable, and its self-test passes under the system bash', function (): void {
    $script = dirname(__DIR__, 2) . '/skills/code-review-github/scripts/post-inline-review.sh';
    $process = new Process(['/bin/bash', $script, '--self-test'], timeout: 300);
    $process->run();

    expect(is_executable($script))->toBeTrue()
        ->and($process->getExitCode())->toBe(0)
        ->and($process->getErrorOutput())->toBe('')
        ->and($process->getOutput())->toMatch('/^self-test: \d+ checks passed$/m');
});

test('the build runs the wrapper self-test exactly once', function (): void {
    $composer = (array) json_decode(inlineReviewSource('composer.json'), associative: true, depth: 512, flags: JSON_THROW_ON_ERROR);

    /** @var array<string, array<int, string>> $scripts */
    $scripts = $composer['scripts'];

    expect(substr_count(implode(' ', $scripts['shell-self-tests']), ' skills/code-review-github/scripts/post-inline-review.sh'))->toBe(1);
});

dataset('wrapper self-test scenarios', [
    'approved layout byte for byte' => ['the visible body is the approved layout, byte for byte'],
    'backtick in a link target' => ['a backtick in a link target leaves no raw tag in the body'],
    'backtick in a link target of a table cell' => ['a backtick in a link target of a table cell leaves no raw tag and keeps the cells'],
    'backtick in a link title' => ['a backtick in a link title leaves no raw tag in the body'],
    'code span identifier in the entry' => ['an identifier in a code span shows as written in the Actionable comments entry'],
    'code span marker stays encoded' => ['a comment marker in a code span stays encoded, so the body holds one cr-comment marker'],
    'code span pipe in a table cell' => ['a pipe in a code span of a table cell is encoded, and so is a pipe outside it'],
    'commit id is the reviewed head' => ['commit_id is the reviewed head'],
    'control character before a finding fence' => ['a control character does not carry a fenced block in a finding past the check'],
    'control character before a refused value' => ['a control character does not carry a refused walkthrough value past the check'],
    'critical dry finding refused' => ['a Critical dry finding is refused: a duplicated security control keeps the Problem shape'],
    'double backtick run opens no span' => ['a run of two backticks never opens a span, and the unpaired backtick after it stays encoded'],
    'dropped duplicate title is scanned' => ['the title of a dropped duplicate of a withheld id is scanned too'],
    'DRY author mention without a line' => ['a DRY finding with no line and an author ends its entry with the mention'],
    'DRY entry on a changed line' => ['a DRY finding on a changed line is an entry whose Fix names the existing implementation, with the mention'],
    'DRY finding posts no inline comment' => ['a review with a DRY finding carries no inline comment'],
    'duplicate of a withheld id' => ['two entries of one id on one path and line are withheld whichever of them carries the flag'],
    'escaped walkthrough naming a withheld title' => ['that names a withheld title through JSON escapes is refused and nothing is posted'],
    'event COMMENT' => ['event is COMMENT'],
    'external-write audit line' => ['the review is one external-write audit line'],
    'failed tree lookup' => ['a failed repository tree lookup publishes nothing'],
    'failed visibility check is public' => ['a failed visibility check counts as public'],
    'failed write leaves a note line' => ['a failed review request leaves a note line'],
    'fenced fix is refused' => ['a fix that holds a fenced block is refused with its own message'],
    'file name markup is text' => ['the file name shows its markup as text in a code element, each pipe encoded'],
    'finding on a changed line is an entry' => ['a finding on a changed line is an entry of the Actionable comments block, byte for byte'],
    'finding outside the diff posts no inline comment' => ['a finding outside the diff posts one review with no inline comment'],
    'finding with markup is never an inline comment' => ['a finding that holds markup is an entry, never an inline comment'],
    'finding with no line' => ['a finding with no line is an Actionable comments entry without a place'],
    'forged marker in the text' => ['a forged marker in the problem or the fix text is neutralised in the body'],
    'hidden record in the review body' => ['the review body holds one hidden record, one fingerprint marker, and the cr-comment actor marker'],
    'hidden record keeps a package path' => ['the hidden record may keep a package path'],
    'hidden record round trip' => ['the hidden record comes back byte for byte, --> and --!> and &lt; and <!-- included'],
    'interactive testing all Blocked' => ['a record whose interactive testing rows are all Blocked publishes nothing'],
    'interactive testing that ran' => ['a record whose interactive testing ran a scenario is published'],
    'invalid document' => ['invalid document is refused'],
    'invalid walkthrough' => ['invalid walkthrough is refused'],
    'marker rebuilt after a backtick' => ['a rule marker rebuilt after a backtick is refused with its own message'],
    'markup renders as text' => ['a walkthrough, a record, and findings that hold markup are published as text'],
    'mention on a changed line' => ['a finding on a changed line with an author ends its entry with the mention'],
    'mention once per finding that sets it' => ['the visible body mentions the author once for each finding that sets it'],
    'merge risk line is required' => ['a record with no Merge risk line publishes nothing'],
    'merge risk sentence is encoded' => ['the Merge Risk sentence from the record is encoded too'],
    'merge risk sentence tooling' => ['a Merge Risk sentence that names a package script is refused and nothing is posted'],
    'moderate icon and mention' => ['a Moderate finding carries the orange icon, the Fix line, and the author mention line'],
    'multi-line, removed line, and hunk gap are entries' => [
        'a multi-line finding, a removed line, and a range across a hunk gap are entries at their last line, with no inline comment',
    ],
    'no CI line, checks, buttons, or share link' => [
        'the visible body shows no CI line, pre-merge checks, recent review info, button, or share link',
    ],
    'no comments key' => ['the review request carries no inline comment: it has no comments key'],
    'no cr-inline marker in the body' => ['the review body holds no cr-inline marker'],
    'no HTML tag but the layout' => ['the visible body holds no HTML tag but the tags of the layout and code elements'],
    'no image' => ['no value renders an image'],
    'no inline comment with three findings' => ['a review with three findings carries no inline comment'],
    'no line-start markup' => ['no visible line starts a heading, a quote, a setext underline, or an indented code block'],
    'no literal backtick' => ['the visible body holds no literal backtick'],
    'no other visible field' => [
        'the entry renders no Rule, Provenance, Raised by, Level, Evidence, Expected behavior, Test hint, Impact, Suggested fix, or suggestion block',
    ],
    'no raw end of comment in the record' => ['the hidden block holds no raw --> of the record'],
    'no review thread read' => ['post reads no review thread'],
    'no Where to test without a route' => ['a change that touches no page or route has no Where to test block'],
    'one review request' => ['one review request'],
    'only fence is the diagram' => ['the only fenced block of the visible body is the sequence diagram'],
    'output lists every finding' => ['output lists every finding, on a changed line or not'],
    'outside-diff author mention' => ['an outside-diff finding with an author ends its Actionable comments entry with the mention'],
    'outside-diff finding is an Actionable comments entry' => ['an outside-diff finding is a line of the Actionable comments block'],
    'package path in a code span' => ['a package path in a code span, whose content is entity-encoded, is refused and nothing is posted'],
    'package tooling in a finding title' => ['a finding title that names a package script is refused and nothing is posted'],
    'package tooling in a fix' => ['a Fix sentence that names a package script is refused and nothing is posted'],
    'package tooling in an Actionable comments entry' => [
        'an Actionable comments entry that names a package script is refused and nothing is posted',
    ],
    'package tooling in an entry on a changed line' => ['an entry on a changed line that names a package script is refused and nothing is posted'],
    'package tooling in the walkthrough' => ['a Walkthrough that names a package script is refused and nothing is posted'],
    'private repository lists both entries' => ['a private repository lists both entries of the id'],
    'private repository posts a security finding' => ['a private repository posts a security finding with no inline comment'],
    'project own command and path' => ['the reviewed project own command and path are posted'],
    'public repository withholds a security finding' => ['a public repository withholds the marked finding'],
    'published finding naming a withheld title' => ['a published finding that names a withheld title is refused and nothing is posted'],
    'record naming a withheld id is refused' => ['a record that names a withheld finding by its id is refused and nothing is posted'],
    'record naming a withheld title is refused' => ['a record that names a withheld finding by its title is refused and nothing is posted'],
    'record refused by the header check' => ['a record that check-cr-header.sh refuses publishes nothing'],
    'record with a suggestion block' => ['a record that holds a suggestion block publishes nothing'],
    'reply by another author' => ['a thread with a reply by another author is reported as has_reply and not resolved'],
    'reply of the actor without a marker' => ['a reply of the actor without its marker counts as a reviewer comment'],
    'reply on a later page' => ['a reply on a later page of the thread comments is found'],
    'repository own script name' => ['a script name that the reviewed repository holds is the project own and is posted'],
    'resolve only the fixed thread of this actor' => ['resolve closes only the open thread of this actor for a fixed id'],
    'stale head is refused' => ['a stale commit id exits 4'],
    'stderr names the listed count' => ['the review stderr names the listed count and no inline count'],
    'suggestion is refused' => ['a suggestion is refused with its own message: a review carries no code'],
    'summary is plain sentences' => ['a TL;DR with a heading, a quote, a table, or a tag line is refused: it holds plain sentences'],
    'summary limit counts characters' => ['the TL;DR limit counts characters, not bytes: 500 two-byte characters pass'],
    'summary limit counts the neutralised text' => [
        'the TL;DR limit counts the neutralised text: a marker closer that grows to 501 characters is refused',
    ],
    'summary naming a withheld finding' => ['a TL;DR that names a withheld finding by its title is refused and nothing is posted'],
    'summary of 499 characters' => ['a TL;DR of 499 characters is published whole'],
    'summary of 500 characters' => ['a TL;DR of exactly 500 characters is published whole'],
    'summary of 501 characters' => ['a TL;DR of 501 characters is refused, not cut, and nothing is posted'],
    'summary opens the body' => ['the summary paragraph opens the review body, with no label and no findings count line'],
    'table rows keep their cells' => ['every table row of the visible body keeps its own cells: a pipe in a value is encoded or escaped'],
    'test file in the walkthrough' => ['a walkthrough row that names a test file is refused and nothing is posted'],
    'test-only pull request' => ['a pull request that changes only tests may list its test files'],
    'visible order' => ['the visible order is the summary, Merge Risk, Walkthrough, Where to test, Actionable comments'],
    'walkthrough beside a changed test' => ['a walkthrough with no test file is published for a pull request that changes a test too'],
    'walkthrough without a diagram' => ['a walkthrough with no diagram renders no sequence diagram and no fenced block in the body'],
    'Where to test lists the routes' => ['the Where to test block lists the method and the path of each route with one line to try'],
    'withheld finding stays out of the visible body' => ['a withheld finding is in no visible line, not in the count, and not in the hidden record'],
    'withheld id scan covers every other entry' => [
        'a published entry beside two entries of a withheld id that names the title in markup is refused',
    ],
    'withheld id shared by another entry' => ['an entry that shares the id of a withheld entry is withheld too, in no visible line'],
    'withheld title in other markup or invisible' => [
        'a walkthrough that names a withheld title in other markup or with an invisible character is refused',
    ],
    'zero-finding round' => ['a round with no finding posts one review with no inline comment'],
    'zero-finding round shows the layout' => [
        'a round with no finding shows the summary, Merge Risk, and Walkthrough, and no Actionable comments block',
    ],
    'zero-width character in a withheld title' => ['a zero-width character inside a withheld title does not hide it'],
]);

test('the wrapper self-test asserts the scenario', function (string $label): void {
    expect(inlineReviewSource('skills/code-review-github/scripts/post-inline-review.sh'))->toContain($label);
})->with('wrapper self-test scenarios');

test('the wrapper treats only the value true as a private repository and fails closed to public', function (): void {
    $script = inlineReviewSource('skills/code-review-github/scripts/post-inline-review.sh');

    expect($script)->toContain('gh repo view "$NWO" --json isPrivate --jq .isPrivate')
        ->and($script)->toContain('if [[ "$private_value" == "true" ]]; then')
        ->and($script)->toContain('[.[] | select(.withhold) | .finding_id] as $withheld_ids')
        ->and($script)->toContain('| map(.withhold = (.finding_id | IN($withheld_ids[])))')
        ->and($script)->toContain('if (.finding_id | IN($withheld_ids[])) and ($private | not) then {class: "withheld", f: .}');
});

test('the wrapper writes through the audited path and never from a stale head', function (): void {
    $script = inlineReviewSource('skills/code-review-github/scripts/post-inline-review.sh');

    expect($script)->toContain('bash "$AUDIT_TRAIL" record --target "$review_url" --status 0')
        ->and($script)->toContain('bash "$AUDIT_TRAIL" record --target "pull request review on ${NWO}#${NUMBER}" --status "$write_rc"')
        ->and($script)->toContain('exit 4')
        ->and($script)->toContain('event: "COMMENT"')
        ->and($script)->toContain('commit_id: $commit');
});

test('the contract section states that every finding is an Actionable comments entry and no inline comment is posted', function (): void {
    $section = inlineReviewContract();

    expect($section)->toContain('On a GitHub pull request the review carries **no inline comment**.')
        ->and($section)->toContain('Every published finding, on a changed line or not and whatever lens raised it, is one entry of that block')
        ->and($section)->toContain('**The shape lives in one template, which the script renders.**')
        ->and($section)->toContain(
            '- `title`, the short name of the finding in the entry: a few plain words, written under the visible-text rule below.',
        )
        ->and($section)->toContain(
            'refuses the summary, the Merge Risk sentence, the Walkthrough, the Where to test block, or an Actionable comments entry',
        )
        ->and($section)->toContain('`kind: "dry"` with `existing`, the `<path>:<line>` of the existing implementation')
        ->and($section)->toContain('the script refuses `suggestion`, `faulty_example`, `faulty_example_lang`')
        ->and($section)->toContain('The request carries no `comments` key.')
        ->and($section)->not->toContain('already_open')
        ->and($section)->not->toContain('outside_diff')
        ->and($section)->not->toContain('**Problem:**')
        ->and($section)->not->toContain('inline-review-comment.md')
        ->and($section)->toContain('post-inline-review.sh resolve <PR-NUMBER|URL> --fixed <finding id>')
        ->and($section)->toContain('A review that an earlier release of this package published opened one inline thread')
        ->and($section)->toContain('A quiet loop iteration posts nothing')
        ->and($section)->toContain('The JIRA ticket, the Bugsnag error, and the non-technical tracker summary are unchanged')
        ->and($section)->toContain('The `cr-inline` marker stays an agent marker')
        ->and($section)->toContain('Classify each comment of the thread on its own.')
        ->and($section)->toContain('the script lists it under `has_reply`');
});

test('the contract publishes one review whose body carries the hidden record and posts no cr-comment comment', function (): void {
    $contract = inlineReviewSource('skills/code-review-github/references/cr-wrapper-contract.md');
    $section = installerDocsSection($contract, '#### GitHub pull-request review (one review per CR run — no `cr-comment` comment)');

    expect($section)->toContain('A CR run on GitHub publishes exactly one pull-request review and no pull-request comment')
        ->and($section)->toContain('A round with no finding still posts the review, so the merge gate and the carry-forward always find the round.')
        ->and($section)->toContain(
            'skills/code-review-github/scripts/post-inline-review.sh post <PR-NUMBER|URL> --commit-id '
                . '<reviewed head SHA> --meta-file <record-file> --walkthrough-file <walkthrough-file> <findings-file>',
        )
        ->and($section)->toContain('Never write a review with a raw `gh api` call or the GitHub MCP server, and never post '
            . 'the record as a pull-request comment.')
        ->and($section)->toContain('refuses a `--commit-id` that is not the current head (exit `4`)')
        ->and($section)->toContain('records an `external-write` audit line')
        ->and($section)->toContain('without the `Overview:` line, `## Changed areas`, `## TL;DR`, and `## Prompt to fix all issues`')
        ->and($section)->toContain('It carries no `Inline:` line. It never names a finding withheld on a public repository')
        ->and($section)->toContain('never fall back to a `cr-comment` pull-request comment')
        ->and($section)->toContain('Never re-run the review for it, because the publish step reviews nothing again.')
        ->and($contract)->not->toContain('upsert-comment.sh --create <PR-NUMBER|URL> <body-file>')
        ->and($contract)->not->toContain('write the line `Inline: not posted (<reason>)`');
});

test('the contract lists a security finding on a private repository and withholds it everywhere on a public one', function (): void {
    $section = inlineReviewContract();

    expect($section)->toContain('Only the value `true` is private.')
        ->and($section)->toContain('On a private repository the finding is an entry like any other, so the developers have the fix in writing.')
        ->and($section)->toContain(
            'On a public repository, and when the check fails, the finding is withheld, and so is every entry that shares its finding id',
        )
        ->and(
            $section,
        )->toContain(
            'it is in no entry or count of the Actionable comments block, and not in the hidden record, because '
                . 'hidden HTML is readable through the API',
        )
        ->and($section)->toContain(
            'The script refuses a record, a walkthrough file, or a published finding that names a withheld finding by its finding id or its title '
                . '(exit `1`, nothing posted). It compares the decoded text of every value, so a JSON escape or an invisible character hides no title.',
        )
        ->and($section)->toContain(
            'it removes every invisible character (Unicode category `Cf` and every default ignorable code point) and the inline Markdown markup '
                . '(a backtick, `*`, `_`, `~`, and the brackets and the target of a link)',
        )
        ->and($section)->toContain('The flag holds for the id before the script drops a duplicate entry of one id, path, and line, so no dedup drops it.')
        ->and($section)->toContain(
            'and the case folding is Unicode. It compares the title of every entry of a withheld id, a duplicate the dedup dropped included.',
        );
});

test('the review templates keep a withheld finding out of the hidden record', function (string $template): void {
    expect(inlineReviewSource($template))->toContain(
        '> A finding marked `do_not_file_publicly` on a public repository, or when the visibility check fails, stays out of the record too, '
        . 'because hidden HTML is public',
    );
})->with([
    'skills/code-review-github/templates/pr-comment-output.md',
    'skills/code-review/templates/review-output.md',
]);

test('the contract publishes no inline review comment', function (): void {
    $contract = inlineReviewSource('skills/code-review-github/references/cr-wrapper-contract.md');

    expect($contract)->not->toContain('is published as an inline review comment on that line')
        ->and($contract)->not->toContain('#### Inline review comments')
        ->and($contract)->toContain('It carries no inline comment.')
        ->and($contract)->toContain('the machine record of the round as hidden HTML in the `cr-comment` marker namespace');
});

test('the review templates list every published finding in full in the hidden record', function (string $template): void {
    $content = inlineReviewSource($template);

    expect($content)->toContain('**GitHub — this section lists every published finding in full.**')
        ->and($content)->toContain('The review carries no inline comment: every published finding, inside or outside the diff, is also an entry')
        ->and($content)->not->toContain('`already_open`')
        ->and($content)->toContain('It carries no `Inline:` line; a legacy comment with the line `Inline: <n> finding(s) in <review url>` stays readable.')
        ->and($content)->not->toContain('`Inline: not posted (<reason>)`')
        ->and($content)->toContain('The `Counts:` line and the round report of the review loop keep every finding.');
})->with([
    'skills/code-review-github/templates/pr-comment-output.md',
    'skills/code-review/templates/review-output.md',
]);

test('the review templates keep the header block and the merge risk text of the comment untouched', function (string $template): void {
    $content = inlineReviewSource($template);

    expect($content)->toContain('**Merge verdict:**')
        ->and($content)->toContain('**Merge risk:**')
        ->and($content)->toContain('**Security assessment:**');
})->with([
    'skills/code-review-github/templates/pr-comment-output.md',
    'skills/code-review/templates/review-output.md',
]);

test('the agent marker family keeps cr-inline for the threads of an earlier release, and the trust filter reads it as agent output', function (): void {
    $rule = inlineReviewSource('rules/code-review/general.md');
    $trust = inlineReviewSource('skills/_shared/review-trust.jq');

    expect($rule)->toContain(
        '`cr-inline` (the inline review comments that an earlier release of '
        . '`skills/code-review-github/scripts/post-inline-review.sh` posted on the changed lines of a pull request)',
    )
        ->and($trust)->toContain('def agent_marker: type == "string" and test("[a-z][a-z0-9-]*:actor=");');
});

test('the review rule publishes one review on GitHub and moves the machine record into its hidden body', function (): void {
    $rule = installerDocsSection(
        inlineReviewSource('rules/code-review/general.md'),
        '## One published comment per review run — a TL;DR, not a systematic report',
    );

    expect($rule)->toContain('**A review run publishes exactly one report per destination, in the `cr-comment` namespace.**')
        ->and($rule)->toContain('**On a GitHub pull request the one report is one pull-request review, and no `cr-comment` '
            . 'pull-request comment is posted.**')
        ->and($rule)->toContain('1. **The review carries no inline comment.** No finding is posted on a line of the diff, whatever lens raised it.')
        ->and($rule)->toContain('2. **Every finding is an entry of the Actionable comments block of the review body**')
        ->and($rule)->not->toContain('inline-review-comment.md')
        ->and($rule)->not->toContain('**Impact** in one sentence')
        ->and($rule)->toContain('**The machine record is hidden HTML in the review body.**')
        ->and($rule)->toContain(
            'reaches no part of the review**, the visible body and the hidden record included: hidden HTML is public.',
        )
        ->and($rule)->toContain('3. **The visible body of the review has one owner, `@skills/code-review-github/templates/review-body.md`.**')
        ->and($rule)->toContain('a summary paragraph of what the pull request changes (at most 500 characters, with no label and no heading)')
        ->and($rule)->toContain(
            'The body is visible text (*Visible review text names none of the package\'s own tooling* below), and the merge gate never reads it.',
        )
        ->and($rule)->toContain('Every reader reads the newest trusted report across both homes');
});

test('leonardo publishes the review through the wrapper only, with no inline comment, and the consent table names the write', function (): void {
    $leonardo = inlineReviewSource('agents/leonardo.md');
    $orchestration = inlineReviewSource('rules/compound-engineering/orchestration.md');

    expect($leonardo)->toContain(
        'publish the one pull-request review of a round, and resolve the inline thread an earlier release opened for a fixed finding, '
        . 'only through `skills/code-review-github/scripts/post-inline-review.sh`',
    )
        ->and($leonardo)->toContain(
            'post every finding as an entry of the Actionable comments block of one pull-request review, with no inline comment, '
            . 'through `skills/code-review-github/scripts/post-inline-review.sh post <PR-NUMBER|URL> '
                . '--commit-id <reviewed head SHA> --meta-file <record-file> --walkthrough-file <walkthrough-file> <findings-file>`',
        )
        ->and($leonardo)->toContain('That one review is the whole publish: no `cr-comment` pull-request comment is posted.')
        ->and($leonardo)->toContain('reaches the handoff only')
        ->and($orchestration)->toContain('(`upsert-comment.sh`, `post-inline-review.sh`, `delete-owned-github-comment.sh`,')
        ->and($orchestration)->toContain(
            '| `leonardo` | Publish the findings of a review round in the Actionable comments block of one pull-request review, '
            . 'with no inline comment, and resolve the inline thread an earlier release opened for a fixed finding | L1 |',
        )
        ->and($orchestration)->toContain('A security finding marked `do not file publicly` is never posted on a public repository');
});

test('the review loop reads an inline thread of an earlier release as the agent own finding and resolves it through the wrapper', function (): void {
    $skill = inlineReviewSource('skills/process-code-review/SKILL.md');
    $reference = inlineReviewSource('skills/process-code-review/references/resolve-reviewer-threads.md');

    expect($skill)->toContain('Classify each comment on its own')
        ->and($skill)->toContain('only the current actor\'s comment with its `cr-inline` marker is its own finding')
        ->and($reference)->toContain('A review publishes no inline comment, so only a review of an earlier release opened such a thread.')
        ->and($skill)->toContain('every other comment, a reply included, is a reviewer comment')
        ->and($reference)->toContain('**A thread of the agent\'s own finding is resolved through the wrapper.**')
        ->and($reference)->toContain('skills/code-review-github/scripts/post-inline-review.sh resolve <PR-NUMBER|URL> --fixed <finding id>')
        ->and($reference)->toContain('Give it the ids that `review-state.sh` reports as resolved.')
        ->and($reference)->toContain('is listed under `has_reply` and not resolved');
});

test('no file cites the renamed section Inline review comments of the contract', function (): void {
    $citation = '*' . implode(' ', ['Inline', 'review', 'comments']) . '*';
    $citing = array_keys(array_filter(
        packageTextFiles(),
        static fn (string $content, string $path): bool => $path !== 'CHANGELOG.md' && str_contains($content, $citation),
        ARRAY_FILTER_USE_BOTH,
    ));

    expect($citing)->toBe([])
        ->and(inlineReviewSource('skills/code-review/references/finding-records.md'))
        ->toContain('cr-wrapper-contract.md` *GitHub pull-request review*');
});

test('the merge gate keeps reading the cr-comment and ignores the inline findings as reviewer feedback', function (): void {
    $merge = inlineReviewSource('skills/merge-github-pr/SKILL.md');

    expect($merge)->toContain('Older inline review comments (the `cr-inline` marker')
        ->and($merge)->toContain('They are never read as reviewer feedback and add no verdict')
        ->and($merge)->toContain(
            'this gate reads the `Counts:` line and the `## Deferred to sub-issues` section of the '
                . 'newest trusted report, the hidden record of its review included',
        );
});

test('the github review skill delegates the review to the contract and posts no inline comment', function (): void {
    $skill = inlineReviewSource('skills/code-review-github/SKILL.md');

    expect($skill)->toContain('one pull-request review through `scripts/post-inline-review.sh`')
        ->and($skill)->toContain('The review carries no inline comment')
        ->and($skill)->toContain('#### Pull-request review (the one publication on the pull request)')
        ->and($skill)->toContain('`references/cr-wrapper-contract.md` *GitHub pull-request review* and *Findings in the review body*')
        ->and($skill)->not->toContain('inline-review-comment.md');
});

test('the gate and the thread rule bind agent status to the actor and read the other comments of an inline thread', function (): void {
    $contract = inlineReviewSource('skills/code-review-github/references/cr-wrapper-contract.md');
    $script = inlineReviewSource('skills/code-review-github/scripts/post-inline-review.sh');

    expect($contract)->toContain(
        'A `cr-inline` marker counts only on a comment that the current actor wrote; the other comments of an inline thread are reviewer comments',
    )
        ->and($contract)->not->toContain('therefore read the thread as this actor\'s finding and never as a reviewer instruction')
        ->and($script)->toContain('reply: ((.comments.pageInfo.hasNextPage // false) or any(.comments.nodes[]; agent_comment($actor) | not))')
        ->and($script)->toContain('comments(first:100){pageInfo{hasNextPage endCursor} nodes{author{login} body}}')
        ->and($script)->toContain('((.author.login // "") == $actor) and ((.body // "") | contains("<!-- cr-inline:actor=');
});

test('the publisher builds a review request with no comments key', function (): void {
    $script = inlineReviewSource('skills/code-review-github/scripts/post-inline-review.sh');

    expect($script)->toContain('{commit_id: $commit, event: "COMMENT", body: $body} as $r')
        ->and($script)->not->toContain('then error("a review carries no inline comment")')
        ->and($script)->not->toContain('open_here(')
        ->and($script)->not->toContain('file_anchors');
});

test('both GitHub wrappers resolve the target and the actor through one shared helper', function (): void {
    $helper = inlineReviewSource('skills/_shared/github-target.sh');

    expect($helper)->toContain('github_target_resolve()')
        ->and($helper)->toContain('github_actor_resolve()');

    foreach (['skills/code-review-github/scripts/upsert-comment.sh', 'skills/code-review-github/scripts/post-inline-review.sh'] as $wrapper) {
        $source = inlineReviewSource($wrapper);

        expect($source)->toContain('_shared/github-target.sh"')
            ->and($source)->toContain('github_actor_resolve || exit 3')
            ->and($source)->toContain('github_target_resolve')
            ->and($source)->not->toContain('for attempt in 1 2 3; do')
            ->and($source)->not->toContain('resolve_repo_from_git');
    }
});

test('audit-trail verify reads the pull request reviews and review comments, and the wrapper list names the new wrapper', function (): void {
    $audit = inlineReviewSource('skills/_shared/audit-trail.sh');
    $orchestration = inlineReviewSource('rules/compound-engineering/orchestration.md');

    expect($audit)->toContain('gh api "$pulls/reviews" --paginate')
        ->and($audit)->toContain('gh api "$pulls/comments" --paginate')
        ->and($audit)->toContain('pullrequestreview-$id')
        ->and($audit)->toContain('verify names a pull-request review and a review comment of the account that have no line')
        ->and($orchestration)->toContain('`post-inline-review.sh` (`post` and `resolve`), `delete-owned-github-comment.sh`');
});

test('a published report with an Inline line resolves nothing by absence, and the loop documents it', function (): void {
    $extractor = inlineReviewSource('skills/_shared/extract-findings.sh');

    expect($extractor)->toContain('a report with an Inline line resolves no open record by absence')
        ->and($extractor)->toContain('a report whose inline review was not posted still resolves by absence')
        ->and(inlineReviewSource('skills/code-review/references/feedback-loop.md'))
        ->toContain('resolves no open record in `collect`')
        ->and(inlineReviewSource('skills/code-review/references/finding-records.md'))
        ->toContain('The extractor resolves no record by absence for that legacy comment')
        ->and(inlineReviewSource('skills/code-review/references/finding-records.md'))
        ->toContain('carries no `Inline:` line, so it resolves by absence like any complete report')
        ->and(inlineReviewSource('skills/code-review/references/feedback-loop.md'))
        ->not->toContain('carries no such line: the findings it no longer lists');
});

test(
    'the marker guard runs after the last transformation, covers the rule field, and the review body holds no cr-inline marker',
    function (): void {
        $script = inlineReviewSource('skills/code-review-github/scripts/post-inline-review.sh');
        $contract = inlineReviewContract();

        expect($script)->toContain('req((.rule | if . == null then null else codespan | printable end) | marker_free; "rule '
            . 'must not contain an agent marker")')
            ->and($script)->toContain('| req([.problem, .impact, .fix, .existing] | all(marker_free); "a text field must not contain an agent marker")')
            ->and($script)->not->toContain('"suggestion must not contain an agent marker"')
            ->and($script)->toContain('if ($body | count("<!--\\\\s*cr-inline:")) == 0')
            ->and($script)->toContain('the rendered review was refused, nothing was published')
            ->and($script)->toContain('error("the review body does not hold exactly its own markers")')
            ->and($contract)->toContain('**A marker in the finding text is refused.**')
            ->and($contract)->toContain('that holds a `cr-inline` marker');
    },
);

test('the wrapper accepts the finding fields for the hidden record and renders none of their labels', function (): void {
    $script = inlineReviewSource('skills/code-review-github/scripts/post-inline-review.sh');
    $payload = substr($script, (int) strpos($script, 'IFS= read -r -d \'\' JQ_PAYLOAD'), 4_000);

    $keys = [
        'fix', 'rule', 'provenance', 'evidence', 'expected_behavior', 'test_hint',
        'raised_by', 'answered', 'level', 'kind', 'existing',
    ];

    foreach ($keys as $key) {
        expect($script)->toContain('"' . $key . '"');
    }

    $labels = [
        'Rule', 'Provenance', 'Answered', 'Raised by', 'Level', 'Evidence',
        'Expected behavior', 'Test hint', 'Impact', 'Suggested fix', 'suggestion',
    ];

    foreach ($labels as $label) {
        expect($payload)->not->toContain('"' . $label . '"')
            ->and($payload)->not->toContain('**' . $label . ':**');
    }

    expect($payload)->not->toContain('**Problem:**')
        ->and($payload)->not->toContain('def dry_line')
        // `existing` is normalised to its own closed code span, so the Fix of a DRY entry renders it as `<path>:<line>`.
        ->and($payload)->toContain('Use the existing implementation in \(.existing) instead of repeating the logic.')
        ->and($script)->toContain('existing: (.existing | if . == null then null else "`" + codespan + "`" | inert end),')
        ->and($script)->toContain('a review carries no code: leave out suggestion, faulty_example, and faulty_example_lang');
});

test('the inline comment template is gone and the review body template owns the entry', function (): void {
    $template = inlineReviewSource('skills/code-review-github/templates/review-body.md');

    expect(file_exists(dirname(__DIR__, 2) . '/skills/code-review-github/templates/inline-review-comment.md'))->toBeFalse()
        ->and($template)->toContain(
            "**🔴 Critical 1 — {title}** · `{file:line}`\n{the problem, in one sentence}\n**Fix:** {what to change, in one sentence}\n@{login}",
        )
        ->and($template)->toContain('The review carries no inline comment, so this block is the one place a person reads a finding.')
        ->and($template)->toContain('**An entry ends with the `@login` mention line only when the finding sets `author`**')
        ->and($template)->not->toContain('**Suggested fix:**')
        ->and($template)->not->toContain('```suggestion');
});

test('the readers read a review record and a legacy cr-comment through one jq definition and one unwrap', function (): void {
    $trust = inlineReviewSource('skills/_shared/review-trust.jq');
    $header = inlineReviewSource('skills/_shared/cr-header.sh');
    $readers = [
        'skills/_shared/collect-findings.sh',
        'skills/_shared/review-carry-forward.sh',
        'skills/code-review-github/scripts/post-inline-review.sh',
    ];

    expect($trust)->toContain('def review_reports:')
        ->and($trust)->toContain('map(select(.state != "PENDING") | . + {home: "review", createdAt: (.createdAt // .submittedAt)})')
        ->and($trust)->toContain('def newest_trusted_report:')
        ->and($header)->toContain('cr_review_meta_wrap() {')
        ->and($header)->toContain('cr_review_meta_unwrap() {')
        ->and(inlineReviewSource($readers[0]))->toContain('JQ_REPORTS=\'include "review-trust"; [ review_reports[] | select(trusted_review) ]\'')
        ->and(inlineReviewSource($readers[1]))->toContain('newest_trusted_report')
        ->and(inlineReviewSource($readers[2]))->toContain('cr_review_meta_wrap <"$TMP_ROOT/meta.md"');

    foreach ($readers as $script) {
        expect(inlineReviewSource($script))->not->toContain('include "review-trust"; [(.comments // [])[] | select(trusted_review)]');
    }

    // The hidden record is encoded by cr-header.sh only. The one `&amp;` of the publisher is the visible encoder `inert`,
    // which never touches the record.
    $publisher = inlineReviewSource($readers[2]);

    expect(inlineReviewSource($readers[0]))->not->toContain('&amp;')
        ->and(inlineReviewSource($readers[1]))->not->toContain('&amp;')
        ->and(substr_count($publisher, 'gsub("&"; "&amp;")'))->toBe(1)
        ->and($publisher)->toContain('def inert_text: gsub("&"; "&amp;")')
        ->and($publisher)->toContain('def inert: printable')
        ->and($publisher)->toContain('.out += (if .inside then "</code>" else "<code>" end)')
        ->and($publisher)->toContain('cr_review_meta_wrap <"$TMP_ROOT/meta.md" >"$TMP_ROOT/meta.wrapped"');
});
