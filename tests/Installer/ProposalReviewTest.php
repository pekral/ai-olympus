<?php

declare(strict_types = 1);

/**
 * The file content with every run of whitespace collapsed to one space, so a pin never depends on
 * where a Markdown paragraph wraps.
 */
function proposalReviewFile(string $relativePath): string
{
    return (string) preg_replace('/\s+/', ' ', (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath));
}

test('one owner file defines the proposal review, and it reviews correctness, efficiency, and simplicity', function (): void {
    $owner = proposalReviewFile('skills/_shared/orchestration/proposal-review.md');

    expect($owner)->toContain('This file is the one owner of the proposal review.')
        ->and($owner)->toContain('The step is a code review before the code exists: it reviews the proposed solution and not a diff.')
        ->and($owner)->toContain('**Correct.** The proposal meets every acceptance criterion and breaks no existing behaviour.')
        ->and($owner)->toContain('**Efficient.** The proposal adds no avoidable cost in queries, I/O, loops, or memory.')
        ->and($owner)->toContain('**As simple as possible.**')
        ->and($owner)->toContain('The audit runs for every task, and a task that is not security-focused is no reason to skip it.')
        ->and($owner)->toContain('`## Proposed solution`');
});

test('two agents analyse the issue: krang the proposal, leonardo the security audit of it', function (): void {
    $owner = proposalReviewFile('skills/_shared/orchestration/proposal-review.md');

    expect($owner)->toContain('## Two agents analyse the issue')
        ->and($owner)->toContain('`krang` runs this part itself, in its own context.')
        ->and($owner)->toContain('Its own analysis of the issue, and the security audit of the proposed solution, for every task.')
        ->and($owner)->toContain('It ends when the roster gains an agent dedicated to the analysis')
        ->and($owner)->toContain('`krang` never implements, never reviews a diff');

    foreach (['agents/krang.md', 'agents/leonardo.md', 'codex/agents/krang.toml', 'codex/agents/leonardo.toml'] as $path) {
        expect(proposalReviewFile($path))->toContain('skills/_shared/orchestration/proposal-review.md');
    }
});

test('only Critical findings are published, as one new comment, and none is published when there is none', function (): void {
    $owner = proposalReviewFile('skills/_shared/orchestration/proposal-review.md');

    expect($owner)->toContain('A finding below Critical, from `krang` or from `leonardo`, is neither published, nor recorded, nor acted on.')
        ->and($owner)->toContain('publish nothing and record `proposal review: no Critical finding` in the brief')
        ->and($owner)->toContain('Publish the Critical findings of both agents as one new comment.')
        ->and($owner)->toContain('`krang` publishes one new comment on the source issue and never updates an earlier one.')
        ->and($owner)->toContain('`leonardo` publishes no comment of its own in this step.')
        ->and($owner)->toContain('Open with a `TL;DR`.')
        ->and($owner)->toContain('`@rules/reports/general.md`')
        ->and($owner)->toContain('`proposal-review` and `--create`')
        ->and($owner)->toContain('*Never disclose an unfixed vulnerability on a public tracker*');
});

test('leonardo runs the proposal audit for every task and publishes nothing in it', function (): void {
    $leonardo = proposalReviewFile('agents/leonardo.md');

    expect($leonardo)->toContain('### Proposal audit — every task')
        ->and($leonardo)->toContain('This audit runs for every task. A task that is not security-focused is no reason to skip it.')
        ->and($leonardo)->toContain('Post no tracker comment in the audit itself')
        ->and($leonardo)->toContain('A finding below Critical is not reported, not recorded, and not acted on.')
        ->and($leonardo)->toContain('`skills/_shared/orchestration/proposal-review.md` is the one owner of the step');

    expect(proposalReviewFile('codex/agents/leonardo.toml'))->toContain('audits the security of the proposed solution for every task');
});

test('krang runs the proposal review before donatello in the merge-preparation delivery path', function (): void {
    $krang = proposalReviewFile('agents/krang.md');

    expect($krang)->toContain('**Merge-preparation delivery path → proposal review, before the implementation.**')
        ->and($krang)->toContain('whatever the task type')
        ->and($krang)->toContain('Wait for the `Security analysis done` handoff before dispatching `donatello`')
        ->and($krang)->toContain('**Three named exceptions to that rule, and no others.**')
        ->and($krang)->toContain('This exception ends when the roster gains an agent dedicated to the analysis of an issue.');

    expect(proposalReviewFile('skills/_shared/orchestration/merge-preparation.md'))
        ->toContain('review of `skills/_shared/orchestration/proposal-review.md` on every such run');
    expect(proposalReviewFile('skills/verify-merge-readiness/SKILL.md'))
        ->toContain('review of `skills/_shared/orchestration/proposal-review.md`');
    expect(proposalReviewFile('commands/prepare-issue-for-merge.md'))
        ->toContain('the proposal review of');
});

test('a general analysis request is answered by krang and leonardo and never stops with Blocked', function (): void {
    $krang = proposalReviewFile('agents/krang.md');

    expect($krang)->toContain('The roster has no agent dedicated to it, so you and `leonardo` run it')
        ->and($krang)->not->toContain('stop with `Blocked: the roster has no agent for general analysis`');

    expect(proposalReviewFile('skills/_shared/orchestration/proposal-review.md'))->toContain('**A general analysis-only request.**');
});

test('the proposal-review comment is a sanctioned krang write with its own marker namespace', function (): void {
    expect(proposalReviewFile('agents/krang.md'))->toContain('run the one new `proposal-review` comment')
        ->and(proposalReviewFile('rules/code-review/general.md'))->toContain('`proposal-review` (the Critical findings of the proposal review')
        ->and(proposalReviewFile('rules/compound-engineering/orchestration.md'))->toContain('| `krang` | Publish the one new `proposal-review` comment');
});

test('krang dispatches only the agents the request needs and proposes a missing agent, under one routing owner', function (): void {
    $routing = proposalReviewFile('rules/compound-engineering/orchestration.md');

    expect($routing)->toContain('### Dispatch only the agents the work needs, and propose the ones it lacks')
        ->and($routing)->toContain('It dispatches no agent for a part the request does not need.')
        ->and($routing)->toContain('The proposal names the role, what the agent would own, and why it improves the result.')
        ->and($routing)->toContain('`krang` does not create the agent.')
        ->and($routing)->toContain('It does not do the work of that agent itself.')
        ->and($routing)->toContain('The one exception is the issue analysis.');

    $reference = '*Adaptive routing* → *Dispatch only the agents the work needs, and propose the ones it lacks*';

    expect(proposalReviewFile('agents/krang.md'))->toContain($reference)
        ->and(proposalReviewFile('agents/krang.md'))->toContain('- **Proposed agents:**')
        ->and(proposalReviewFile('codex/agents/krang.toml'))->toContain($reference)
        ->and(substr_count($routing, 'The proposal names the role'))->toBe(1)
        ->and(substr_count(proposalReviewFile('agents/krang.md'), 'The proposal names the role'))->toBe(0);
});
