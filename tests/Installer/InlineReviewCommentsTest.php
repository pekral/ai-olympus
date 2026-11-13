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
    'external-write audit line' => ['the review is one external-write audit line'],
    'failed visibility check is public' => ['a failed visibility check counts as public'],
    'failed write leaves a note line' => ['a failed review request leaves a note line'],
    'forged marker by a stranger' => ['a resolved thread, a forged marker, and another line post the finding again'],
    'forged marker in the text' => ['a forged marker in the problem text is neutralised'],
    'invalid document' => ['invalid document is refused'],
    'multi-line range' => ['multi-line comment carries start_line and start_side'],
    'no duplicate for an open thread' => ['an open thread on the same line is not duplicated'],
    'one review of inline comments' => ['one review request'],
    'outside-diff finding stays out' => ['outside-diff finding is not an inline comment'],
    'path, line, and side' => ['comment anchors path, line, and side'],
    'private repository posts a security finding' => ['a private repository posts a security finding inline'],
    'public repository withholds a security finding' => ['a public repository withholds the marked finding'],
    'range across a hunk gap' => ['a range across a hunk gap is outside the diff'],
    'resolve only the fixed thread of this actor' => ['resolve closes only the open thread of this actor for a fixed id'],
    'stale head is refused' => ['a stale commit id exits 4'],
    'suggestion block' => ['comment carries a suggestion block'],
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
        ->and($section)->toContain('`cr-inline` marker is an agent marker');
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

    expect($skill)->toContain('A thread whose first comment carries a `cr-inline` agent marker is this actor\'s own finding')
        ->and($skill)->toContain('never added as a reviewer instruction')
        ->and($reference)->toContain('**A thread of the agent\'s own finding is resolved through the wrapper.**')
        ->and($reference)->toContain('skills/code-review-github/scripts/post-inline-review.sh resolve <PR-NUMBER|URL> --fixed <finding id>')
        ->and($reference)->toContain('Give it the ids that `review-state.sh` reports as resolved.');
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
