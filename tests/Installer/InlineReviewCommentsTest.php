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
    'commit id is the reviewed head' => ['commit_id is the reviewed head'],
    'event COMMENT' => ['event is COMMENT'],
    'exactly two markers per comment' => ['every inline comment holds exactly two cr-inline markers and the review body one'],
    'external-write audit line' => ['the review is one external-write audit line'],
    'failed visibility check is public' => ['a failed visibility check counts as public'],
    'failed write leaves a note line' => ['a failed review request leaves a note line'],
    'finding fields' => ['the inline comment carries the Faulty example in a code block'],
    'forged marker by a stranger' => ['a resolved thread, a forged marker, and another live line post the finding again'],
    'forged marker in the text' => ['a forged marker in the problem text is neutralised'],
    'invalid document' => ['invalid document is refused'],
    'marker rebuilt after a backtick' => ['a rule marker rebuilt after a backtick is refused with its own message'],
    'marker rebuilt after a control character' => ['a faulty_example marker rebuilt after a control character is refused with its own message'],
    'multi-line range' => ['multi-line comment carries start_line and start_side'],
    'no duplicate for an open thread' => ['an open thread on the same line is not duplicated'],
    'one review of inline comments' => ['one review request'],
    'outdated thread is not duplicated' => ['an outdated thread of the same finding id is not duplicated'],
    'outside-diff finding stays out' => ['outside-diff finding is not an inline comment'],
    'path, line, and side' => ['comment anchors path, line, and side'],
    'private repository posts a security finding' => ['a private repository posts a security finding inline'],
    'public repository withholds a security finding' => ['a public repository withholds the marked finding'],
    'range across a hunk gap' => ['a range across a hunk gap is outside the diff'],
    'reply by another author' => ['a thread with a reply by another author is reported as has_reply and not resolved'],
    'reply of the actor without a marker' => ['a reply of the actor without its marker counts as a reviewer comment'],
    'reply on a later page' => ['a reply on a later page of the thread comments is found'],
    'resolve only the fixed thread of this actor' => ['resolve closes only the open thread of this actor for a fixed id'],
    'stale head is refused' => ['a stale commit id exits 4'],
    'suggestion block' => ['comment carries a suggestion block'],
    'suggestion marker guard' => ['a suggestion with a marker is refused with its own message'],
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
        'every finding that anchors to a line of the diff is published as an inline review comment on that line, as one pull-request review',
    )
        ->and($section)->toContain(
            'skills/code-review-github/scripts/post-inline-review.sh post <PR-NUMBER|URL> --commit-id <reviewed head SHA> <findings-file>',
        )
        ->and($section)->toContain('Never write a review with a raw `gh api` call or the GitHub MCP server.')
        ->and($section)->toContain('refuses a `--commit-id` that is not the current head (exit `4`')
        ->and($section)->toContain('records an `external-write` audit line')
        ->and($section)->toContain(
            '`outside_diff`: a finding whose line, or any line of its range, is not in the diff stays in this comment under `## Findings`',
        )
        ->and($section)->toContain(
            '`already_open`: the finding has an unresolved inline thread of this actor on the same path and line, so nothing is posted again',
        )
        ->and($section)->toContain('post-inline-review.sh resolve <PR-NUMBER|URL> --fixed <finding id>')
        ->and($section)->toContain('A quiet loop iteration posts nothing')
        ->and($section)->toContain('The JIRA ticket, the Bugsnag error, and the non-technical tracker summary are unchanged')
        ->and($section)->toContain('An outdated thread, which GitHub reports without a line, counts for any line of that path')
        ->and($section)->toContain('`cr-inline` marker is an agent marker')
        ->and($section)->toContain(
            'Exits `2`, `3`, and `4` (a missing tool, a failed API call, a head that is not the reviewed one) share one fallback',
        )
        ->and($section)->toContain('write the line `Inline: not posted (<reason>)`')
        ->and($section)->toContain('Never re-run the review for it, because the publish step reviews nothing again.')
        ->and($section)->not->toContain('re-run the review on the new head')
        ->and($section)->toContain('set every optional key of the findings file that the finding has')
        ->and($section)->toContain('The first line of `## Findings` is `Inline: <n> finding(s) in <review_url>`')
        ->and($section)->toContain('Classify each comment of the thread on its own.')
        ->and($section)->toContain('the script lists it under `has_reply`');
});

test('the contract posts a security finding inline on a private repository and withholds it on a public one', function (): void {
    $section = inlineReviewContract();

    expect($section)->toContain('Only the value `true` is private.')
        ->and($section)->toContain('On a private repository the finding is posted inline like any other, so the developers have the fix next to the code.')
        ->and($section)->toContain('On a public repository, and when the check fails, the finding is withheld')
        ->and($section)->toContain('it is posted in no inline comment and in no part of this comment');
});

test('the contract no longer forbids line-anchored review comments', function (): void {
    $contract = inlineReviewSource('skills/code-review-github/references/cr-wrapper-contract.md');

    expect($contract)->not->toContain('never as line-anchored review comments')
        ->and($contract)->toContain('A finding that anchors to a line of the diff is published as an inline review comment on that line')
        ->and($contract)->toContain('that review is not a comment of the `cr-comment` namespace');
});

test('the review templates move the anchored findings inline and keep the rest', function (string $template): void {
    $content = inlineReviewSource($template);

    expect($content)->toContain('**GitHub — a finding that anchors to a line of the diff is an inline review comment, not an entry here.**')
        ->and($content)->toContain('`Inline: <n> finding(s) in <review url>`')
        ->and($content)->toContain('`Inline: not posted (<reason>)`')
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

test('the review rule keeps one cr-comment per run and adds the inline review beside it', function (): void {
    $rule = installerDocsSection(
        inlineReviewSource('rules/code-review/general.md'),
        '## One published comment per review run — a TL;DR, not a systematic report',
    );

    expect($rule)->toContain('**A review run publishes exactly one comment per destination, in the `cr-comment` namespace.**')
        ->and($rule)->toContain('**On a GitHub pull request, a finding that anchors to a line of the diff is published inline.**')
        ->and($rule)->toContain('The review is not a second comment of the `cr-comment` namespace')
        ->and($rule)->toContain('the `cr-comment` still holds the header block, the counts, the verdicts, and every finding outside the diff');
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
            . 'through `skills/code-review-github/scripts/post-inline-review.sh post`',
        )
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
            'this gate reads the `Counts:` line and the `## Deferred to sub-issues` section of the newest trusted `cr-comment`, exactly as before',
        );
});

test('the github review skill delegates the inline review to the contract', function (): void {
    $skill = inlineReviewSource('skills/code-review-github/SKILL.md');

    expect($skill)->toContain('one pull-request review of inline comments through `scripts/post-inline-review.sh`')
        ->and($skill)->toContain('#### Inline review (before the PR comment)')
        ->and($skill)->toContain('`references/cr-wrapper-contract.md` *Inline review comments*');
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

test('the inline comment renders the finding fields of the review template as optional keys', function (): void {
    $script = inlineReviewSource('skills/code-review-github/scripts/post-inline-review.sh');

    $keys = ['rule', 'provenance', 'impact', 'evidence', 'faulty_example', 'expected_behavior', 'test_hint'];

    foreach ([...$keys, 'raised_by', 'answered', 'author', 'level'] as $key) {
        expect($script)->toContain('"' . $key . '"');
    }

    $labels = ['Rule', 'Provenance', 'Answered', 'Raised by', 'Level', 'Author'];

    foreach ([...$labels, 'Impact', 'Evidence', 'Faulty example', 'Expected behavior', 'Test hint'] as $label) {
        expect($script)->toContain('"' . $label . '"');
    }

    expect($script)->toContain('suggestion must not contain an agent marker');
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
        ->toContain('The extractor resolves no record by absence for that report');
});

test(
    'the marker guard runs after the last transformation, covers the rule field, and a rendered comment holds exactly its own markers',
    function (): void {
        $script = inlineReviewSource('skills/code-review-github/scripts/post-inline-review.sh');
        $contract = inlineReviewContract();

        expect($script)->toContain('rule: (.rule | if . == null then null else codespan | prose end)')
            ->and($script)->toContain('| req(.suggestion | marker_free; "suggestion must not contain an agent marker")')
            ->and($script)->toContain('| req(.faulty_example | marker_free; "faulty_example must not contain an agent marker")')
            ->and($script)->toContain('"rule must not contain an agent marker"')
            ->and($script)->toContain('[match("<!--\\\\s*cr-inline:"; "g")] | length')
            ->and($script)->toContain('the rendered review was refused, nothing was published')
            ->and($contract)->toContain('**A marker in the finding text is refused.**')
            ->and($contract)->toContain('does not hold exactly its own two `cr-inline` markers');
    },
);

test('the inline comment template names every line the wrapper renders, in the same order', function (): void {
    $template = inlineReviewSource('skills/code-review-github/templates/inline-review-comment.md');
    $script = inlineReviewSource('skills/code-review-github/scripts/post-inline-review.sh');
    $labels = [
        'Rule', 'Provenance', 'Answered', 'Raised by', 'Level', 'Author', 'Problem',
        'Impact', 'Evidence', 'Faulty example', 'Expected behavior', 'Test hint', 'Suggested fix',
    ];
    $shape = substr($template, (int) strpos($template, '````markdown'));
    $positions = array_map(static fn (string $label): int|false => strpos($shape, '**' . $label . ':**'), $labels);
    $sorted = $positions;
    sort($sorted);

    expect($script)->toContain('"**Problem:** ')
        ->and($script)->toContain('"**Suggested fix:** ')
        ->and($positions)->not->toContain(false)
        ->and($positions)->toBe($sorted)
        ->and($shape)->toContain('<!-- cr-inline:actor={login} -->')
        ->and($shape)->toContain('<!-- cr-inline:finding={finding id} -->')
        ->and($shape)->toContain('```suggestion');

    foreach (array_diff($labels, ['Problem', 'Suggested fix']) as $label) {
        expect($script)->toContain('"' . $label . '"');
    }
});
