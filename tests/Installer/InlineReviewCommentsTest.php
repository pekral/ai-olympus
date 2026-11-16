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
        '#### Inline review comments',
    );
}

test('the inline review wrapper exists, is executable, and its self-test passes under the system bash', function (): void {
    $script = dirname(__DIR__, 2) . '/skills/code-review-github/scripts/post-inline-review.sh';
    $process = new Process(['/bin/bash', $script, '--self-test']);
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
    'agent markers' => ['comment carries both agent markers'],
    'CI check name forgery' => ['a check name cannot forge a marker or leave its code span'],
    'CI line counts' => ['the CI line counts passed, failed, pending, and skipped checks and names each failing check once'],
    'CI line with no check' => ['a head with no check says so in the CI line'],
    'CI lookup failure' => ['a failed CI lookup still publishes the review and says the result was not read'],
    'commit id is the reviewed head' => ['commit_id is the reviewed head'],
    'critical dry finding refused' => ['a Critical dry finding is refused: a duplicated security control keeps the Problem shape'],
    'DRY line outside the diff' => ['a DRY finding outside the diff is the DRY line in the review body'],
    'DRY shape' => ['the DRY finding is the Moderate DRY shape with the existing implementation, byte for byte'],
    'event COMMENT' => ['event is COMMENT'],
    'exactly two markers per comment' => ['every inline comment holds exactly two cr-inline markers and the review body none'],
    'external-write audit line' => ['the review is one external-write audit line'],
    'failed tree lookup' => ['a failed repository tree lookup publishes nothing'],
    'failed visibility check is public' => ['a failed visibility check counts as public'],
    'failed write leaves a note line' => ['a failed review request leaves a note line'],
    'faulty example of five lines at most' => ['a faulty example of more than 5 lines is refused with its own message'],
    'finding fields' => ['a faulty example with backticks gets a longer fence'],
    'finding with no line' => ['a finding with no line is one visible line marked (no line)'],
    'forged marker by a stranger' => ['a resolved thread, a forged marker, and another live line post the finding again'],
    'forged marker in the text' => ['a forged marker in the problem text is neutralised'],
    'hidden record in the review body' => ['the review body holds one hidden record, one fingerprint marker, and the cr-comment actor marker'],
    'hidden record keeps a package path' => ['the hidden record may keep a package path'],
    'hidden record round trip' => ['the hidden record comes back byte for byte, --> and --!> and &lt; and <!-- included'],
    'invalid document' => ['invalid document is refused'],
    'marker rebuilt after a backtick' => ['a rule marker rebuilt after a backtick is refused with its own message'],
    'marker rebuilt after a control character' => ['a faulty_example marker rebuilt after a control character is refused with its own message'],
    'moderate icon and mention' => ['a Moderate finding carries the orange icon and the author mention line before the markers'],
    'multi-line range' => ['multi-line comment carries start_line and start_side'],
    'no duplicate for an open thread' => ['an open thread on the same line is not duplicated'],
    'no other visible field' => ['the inline comment renders no Rule, Provenance, Raised by, Level, Evidence, Expected '
        . 'behavior, Test hint, Suggested fix, or suggestion block',
    ],
    'no raw end of comment in the record' => ['the hidden block holds no raw --> of the record'],
    'one review of inline comments' => ['one review request'],
    'outdated thread is not duplicated' => ['an outdated thread of the same finding id is not duplicated'],
    'outside-diff finding stays out' => ['outside-diff finding is not an inline comment'],
    'outside-diff visible line' => ['outside-diff finding is one visible line of the review body'],
    'package tooling in an inline comment' => ['an inline comment that names a package script is refused and nothing is posted'],
    'package tooling in a visible line' => ['a visible line that names a package script is refused and nothing is posted'],
    'path, line, and side' => ['comment anchors path, line, and side'],
    'private repository posts a security finding' => ['a private repository posts a security finding inline'],
    'problem and impact shape' => ['the inline comment is the Problem, Impact, and faulty example shape, byte for byte'],
    'project own command and path' => ['the reviewed project own command and path are posted'],
    'public repository withholds a security finding' => ['a public repository withholds the marked finding'],
    'range across a hunk gap' => ['a range across a hunk gap is outside the diff'],
    'record naming a withheld id is refused' => ['a record that names a withheld finding by its id is refused and nothing is posted'],
    'record naming a withheld title is refused' => ['a record that names a withheld finding by its title is refused and nothing is posted'],
    'record refused by the header check' => ['a record that check-cr-header.sh refuses publishes nothing'],
    'reply by another author' => ['a thread with a reply by another author is reported as has_reply and not resolved'],
    'reply of the actor without a marker' => ['a reply of the actor without its marker counts as a reviewer comment'],
    'reply on a later page' => ['a reply on a later page of the thread comments is found'],
    'repository own script name' => ['a script name that the reviewed repository holds is the project own and is posted'],
    'resolve only the fixed thread of this actor' => ['resolve closes only the open thread of this actor for a fixed id'],
    'stale head is refused' => ['a stale commit id exits 4'],
    'suggestion marker guard' => ['a suggestion with a marker is refused with its own message'],
    'TL;DR keeps an already open finding' => ['a finding already open as an inline comment keeps its TL;DR line'],
    'TL;DR leaves out an outside-diff finding' => ['the TL;DR lists no finding outside the diff, which keeps its own visible line'],
    'TL;DR links DRY and removed lines' => ['a DRY finding and a finding on a removed line link to their line on the matching side of the diff'],
    'TL;DR opens the body' => ['the review body opens with the TL;DR: one line per inline finding, most severe first, with a link to its line'],
    'TL;DR title visible text check' => ['a TL;DR title that names a package script is refused and nothing is posted'],
    'withheld finding stays out of the record' => ['a withheld finding is in no visible line and not in the hidden record'],
    'zero-finding round' => ['a round with no finding posts one review with no inline comment'],
    'zero-finding round is the TL;DR and hidden text' => ['a round with no finding carries the TL;DR and otherwise only hidden text in the review body'],
]);

test('the wrapper self-test asserts the scenario', function (string $label): void {
    expect(inlineReviewSource('skills/code-review-github/scripts/post-inline-review.sh'))->toContain($label);
})->with('wrapper self-test scenarios');

test('the wrapper treats only the value true as a private repository and fails closed to public', function (): void {
    $script = inlineReviewSource('skills/code-review-github/scripts/post-inline-review.sh');

    expect($script)->toContain('gh repo view "$NWO" --json isPrivate --jq .isPrivate')
        ->and($script)->toContain('if [[ "$private_value" == "true" ]]; then')
        ->and($script)->toContain('if .withhold and ($private | not) then {class: "withheld", f: .}');
});

test('the wrapper writes through the audited path and never from a stale head', function (): void {
    $script = inlineReviewSource('skills/code-review-github/scripts/post-inline-review.sh');

    expect($script)->toContain('bash "$AUDIT_TRAIL" record --target "$review_url" --status 0')
        ->and($script)->toContain('bash "$AUDIT_TRAIL" record --target "pull request review on ${NWO}#${NUMBER}" --status "$write_rc"')
        ->and($script)->toContain('exit 4')
        ->and($script)->toContain('event: "COMMENT"')
        ->and($script)->toContain('commit_id: $commit');
});

test('the contract section states the inline review end to end', function (): void {
    $section = inlineReviewContract();

    expect($section)->toContain(
        'every finding that anchors to a line of the diff is published as an inline review comment on that line, whatever lens raised it',
    )
        ->and($section)->toContain('**The shapes live in `templates/inline-review-comment.md`, which the script renders.**')
        ->and($section)->toContain('are defined there and nowhere else. The findings file supplies these keys for them:')
        ->and($section)->toContain(
            '- `title`, the short name of the finding on its TL;DR line: a few plain words, written under the visible-text rule below.',
        )
        ->and($section)->toContain('refuses an inline comment, a TL;DR line, or a visible line that names a package path')
        ->and($section)->toContain('`kind: "dry"` with `existing`, the `<path>:<line>` of the existing implementation')
        ->and($section)->toContain('`faulty_example`, 5 lines at most')
        ->and($section)->not->toContain('This repeats the logic of')
        ->and($section)->not->toContain('**Problem:**')
        ->and($section)->toContain(
            '`already_open`: the finding has an unresolved inline thread of this actor on the same path and line, so nothing is posted again',
        )
        ->and($section)->toContain('post-inline-review.sh resolve <PR-NUMBER|URL> --fixed <finding id>')
        ->and($section)->toContain('A quiet loop iteration posts nothing')
        ->and($section)->toContain('The JIRA ticket, the Bugsnag error, and the non-technical tracker summary are unchanged')
        ->and($section)->toContain('An outdated thread, which GitHub reports without a line, counts for any line of that path')
        ->and($section)->toContain('`cr-inline` marker is an agent marker')
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
                . '<reviewed head SHA> --meta-file <record-file> <findings-file>',
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

test('the contract posts a security finding inline on a private repository and withholds it everywhere on a public one', function (): void {
    $section = inlineReviewContract();

    expect($section)->toContain('Only the value `true` is private.')
        ->and($section)->toContain('On a private repository the finding is posted inline like any other, so the developers have the fix next to the code.')
        ->and($section)->toContain('On a public repository, and when the check fails, the finding is withheld')
        ->and($section)->toContain('it is in no inline comment, in no visible line, and not in the hidden record, because '
            . 'hidden HTML is readable through the API')
        ->and($section)->toContain('The script refuses a record that names a withheld finding by its finding id or its title (exit `1`, nothing posted).');
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

test('the contract no longer forbids line-anchored review comments', function (): void {
    $contract = inlineReviewSource('skills/code-review-github/references/cr-wrapper-contract.md');

    expect($contract)->not->toContain('never as line-anchored review comments')
        ->and($contract)->toContain('every finding that anchors to a line of the diff is published as an inline review comment on that line')
        ->and($contract)->toContain('the machine record of the round as hidden HTML in the `cr-comment` marker namespace');
});

test('the review templates list every published finding in full in the hidden record', function (string $template): void {
    $content = inlineReviewSource($template);

    expect($content)->toContain('**GitHub — this section lists every published finding in full.**')
        ->and($content)->toContain('the findings posted inline, the `already_open` ones, and the `outside_diff` ones alike')
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

test('the agent marker family names cr-inline, and the trust filter reads it as agent output', function (): void {
    $rule = inlineReviewSource('rules/code-review/general.md');
    $trust = inlineReviewSource('skills/_shared/review-trust.jq');

    expect($rule)->toContain(
        '`cr-inline` (the inline review comments of a review round, '
        . 'which `skills/code-review-github/scripts/post-inline-review.sh` posts on the changed lines of a pull request)',
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
        ->and($rule)->toContain('**Each finding that anchors to a line of the diff is a short inline comment on that line**, whatever lens raised it')
        ->and($rule)->toContain('`@skills/code-review-github/templates/inline-review-comment.md` fixes its shape')
        ->and($rule)->not->toContain('**Impact** in one sentence')
        ->and($rule)->toContain('**Each finding that cannot anchor is one short visible line in the review body**')
        ->and($rule)->toContain('**The machine record is hidden HTML in the review body.**')
        ->and($rule)->toContain('reaches none of the four**, the TL;DR and the hidden record included: hidden HTML is public.')
        ->and($rule)->toContain('3. **The review body opens with a visible TL;DR of the round.**')
        ->and($rule)->toContain('Its last line is the CI result of the reviewed head: how many checks passed, failed, are pending, '
            . 'and were skipped, and the name of each failing check.')
        ->and($rule)->toContain('The hidden record does not change, and the merge gate never reads the TL;DR.')
        ->and($rule)->toContain('Every reader reads the newest trusted report across both homes');
});

test('leonardo publishes inline through the wrapper only, and the consent table names the write', function (): void {
    $leonardo = inlineReviewSource('agents/leonardo.md');
    $orchestration = inlineReviewSource('rules/compound-engineering/orchestration.md');

    expect($leonardo)->toContain(
        'post the findings that anchor to a line of the diff as one pull-request review, '
        . 'and resolve the inline thread of a fixed finding, only through `skills/code-review-github/scripts/post-inline-review.sh`',
    )
        ->and($leonardo)->toContain(
            'post the findings that anchor to a line of the diff first, as one pull-request review '
            . 'through `skills/code-review-github/scripts/post-inline-review.sh post <PR-NUMBER|URL> '
                . '--commit-id <reviewed head SHA> --meta-file <record-file> <findings-file>`',
        )
        ->and($leonardo)->toContain('That one review is the whole publish: no `cr-comment` pull-request comment is posted.')
        ->and($leonardo)->toContain('reaches the handoff only')
        ->and($orchestration)->toContain('(`upsert-comment.sh`, `post-inline-review.sh`, `delete-owned-github-comment.sh`,')
        ->and($orchestration)->toContain(
            '| `leonardo` | Publish the findings of a review round as inline review comments on a pull request, '
            . 'and resolve the inline thread of a fixed finding | L1 |',
        )
        ->and($orchestration)->toContain('A security finding marked `do not file publicly` is never posted on a public repository');
});

test('the review loop reads an inline thread as the agent own finding and resolves it through the wrapper', function (): void {
    $skill = inlineReviewSource('skills/process-code-review/SKILL.md');
    $reference = inlineReviewSource('skills/process-code-review/references/resolve-reviewer-threads.md');

    expect($skill)->toContain('Classify each comment on its own')
        ->and($skill)->toContain('only a comment the current actor wrote with its `cr-inline` marker is its own finding')
        ->and($skill)->toContain('every other comment, a reply included, is a reviewer comment')
        ->and($reference)->toContain('**A thread of the agent\'s own finding is resolved through the wrapper.**')
        ->and($reference)->toContain('skills/code-review-github/scripts/post-inline-review.sh resolve <PR-NUMBER|URL> --fixed <finding id>')
        ->and($reference)->toContain('Give it the ids that `review-state.sh` reports as resolved.')
        ->and($reference)->toContain('is listed under `has_reply` and not resolved');
});

test('the merge gate keeps reading the cr-comment and ignores the inline findings as reviewer feedback', function (): void {
    $merge = inlineReviewSource('skills/merge-github-pr/SKILL.md');

    expect($merge)->toContain('The inline review comments of a round (the `cr-inline` marker')
        ->and($merge)->toContain('They are never read as reviewer feedback and add no verdict')
        ->and($merge)->toContain(
            'this gate reads the `Counts:` line and the `## Deferred to sub-issues` section of the '
                . 'newest trusted report, the hidden record of its review included',
        );
});

test('the github review skill delegates the inline review to the contract', function (): void {
    $skill = inlineReviewSource('skills/code-review-github/SKILL.md');

    expect($skill)->toContain('one pull-request review of inline comments through `scripts/post-inline-review.sh`')
        ->and($skill)->toContain('#### Inline review (the one publication on the pull request)')
        ->and($skill)->toContain('`references/cr-wrapper-contract.md` *GitHub pull-request review* and *Inline review comments*');
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

test('an outdated thread is matched without its line', function (): void {
    $script = inlineReviewSource('skills/code-review-github/scripts/post-inline-review.sh');

    expect($script)->toContain('and (.line == null or .line == $f.line)')
        ->and($script)->not->toContain('(.line // .originalLine) == $f.line');
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
    'the marker guard runs after the last transformation, covers the rule field, and a rendered comment holds exactly its own markers',
    function (): void {
        $script = inlineReviewSource('skills/code-review-github/scripts/post-inline-review.sh');
        $contract = inlineReviewContract();

        expect($script)->toContain('req((.rule | if . == null then null else codespan | printable end) | marker_free; "rule '
            . 'must not contain an agent marker")')
            ->and($script)->toContain('| req(.suggestion | marker_free; "suggestion must not contain an agent marker")')
            ->and($script)->toContain('| req(.faulty_example | marker_free; "faulty_example must not contain an agent marker")')
            ->and($script)->toContain('[match("<!--\\\\s*cr-inline:"; "g")] | length')
            ->and($script)->toContain('the rendered review was refused, nothing was published')
            ->and($script)->toContain('error("the review body does not hold exactly its own markers")')
            ->and($contract)->toContain('**A marker in the finding text is refused.**')
            ->and($contract)->toContain('does not hold exactly its own two `cr-inline` markers');
    },
);

test('the wrapper accepts the finding fields for the hidden record and renders none of their labels inline', function (): void {
    $script = inlineReviewSource('skills/code-review-github/scripts/post-inline-review.sh');
    $payload = substr($script, (int) strpos($script, 'IFS= read -r -d \'\' JQ_PAYLOAD'), 4_000);

    $keys = [
        'fix', 'suggestion', 'rule', 'provenance', 'evidence', 'expected_behavior', 'test_hint',
        'raised_by', 'answered', 'level', 'kind', 'existing', 'faulty_example_lang',
    ];

    foreach ($keys as $key) {
        expect($script)->toContain('"' . $key . '"');
    }

    $labels = ['Rule', 'Provenance', 'Answered', 'Raised by', 'Level', 'Evidence', 'Expected behavior', 'Test hint', 'Suggested fix', 'suggestion'];

    foreach ($labels as $label) {
        expect($payload)->not->toContain('"' . $label . '"')
            ->and($payload)->not->toContain('**' . $label . ':**');
    }

    expect($payload)->toContain('"\(.severity | icon) **Problem:** \(.problem)", "**Impact:** \(.impact)", example')
        ->and($payload)->toContain('def dry_line: "🟠 **Moderate · DRY:** This repeats the logic of `\(.existing)`. Please '
            . 'unify it and call the existing implementation instead.";')
        ->and($script)->toContain('"faulty_example must be 5 lines at most"');
});

test('the inline comment template pins both shapes, the visible line, and the two markers', function (): void {
    $template = inlineReviewSource('skills/code-review-github/templates/inline-review-comment.md');
    $first = substr($template, (int) strpos($template, 'Every finding except DRY:'));
    $markers = "<!-- cr-inline:actor={login} -->\n<!-- cr-inline:finding={finding id} -->";

    expect($first)->toContain(
        "````markdown\n{🔴 | 🟠} **Problem:** {the problem, in one or two plain sentences}\n\n"
        . "**Impact:** {what breaks or what risk this introduces, in one sentence}\n\n"
        . "```{language}\n{the faulty code, at most 5 lines}\n```\n\n@{login}\n\n" . $markers . "\n````",
    )
        ->and($template)->toContain(
            '🟠 **Moderate · DRY:** This repeats the logic of `{existing file:line}`. '
            . "Please unify it and call the existing implementation instead.\n\n@{login}\n\n" . $markers,
        )
        ->and($template)->toContain(
            '{🔴 | 🟠} **Problem:** {the problem} **Impact:** {the impact} (`{file:line}`, outside the diff | no line) @{login}'
            . "\n<!-- cr-inline:finding={finding id} -->",
        )
        ->and($template)->toContain(
            "**TL;DR**\n\n- {🔴 | 🟠} **{Critical | Moderate}** · {title} — [`{file:line}`]"
            . '(https://github.com/{owner}/{repo}/pull/{number}/files#diff-{diff anchor}{R | L}{line})'
            . "\n- **CI** on `{short head SHA}`: {n} passed, {n} failed (`{failing check}`, …), {n} pending, {n} skipped.",
        )
        ->and($template)->toContain('With no inline finding, the finding lines are the one line `- No finding is open as an inline comment.`')
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
        expect(inlineReviewSource($script))->not->toContain('&amp;')
            ->and(inlineReviewSource($script))->not->toContain('include "review-trust"; [(.comments // [])[] | select(trusted_review)]');
    }
});
