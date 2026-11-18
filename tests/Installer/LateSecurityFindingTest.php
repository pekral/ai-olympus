<?php

declare(strict_types = 1);

function lateSecurityFindingFile(string $path): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $path);
}

test('review-state ships a late command that counts a late security finding for the recorded round (issue #620)', function (): void {
    $script = lateSecurityFindingFile('skills/_shared/review-state.sh');

    expect($script)
        ->toContain('review-state.sh late     --state <file> --input <report|->')
        ->toContain('late) cmd_late "$@" ;;')
        ->toContain('cmd_late() {')
        ->toContain('JQ_LATE=')
        ->toContain('def merge_reported($prev):');
});

test('the review-state self-test proves each rule of a late security finding (issue #620)', function (string $label): void {
    expect(lateSecurityFindingFile('skills/_shared/review-state.sh'))->toContain('\'' . $label . '\'');
})->with([
    'a late security finding counts for the cap round and opens a security round',
    'the late finding is counted as new in the round it was reported for',
    'a late report repeated does not count the finding twice',
    'the fix of a late finding converges the loop in the security round',
    'a late report for a round that is no longer the last is refused',
    'a late report that reads another revision than the recorded round is refused',
    'a finding the classifier does not tag as security:F_STRUCT',
    'a history-exposure finding without a security keyword:F_HIST',
    'a point that needs verification:F_SEC_NOEV',
    'the next round of a late finding the classifier did not tag never escalates with cap-spent-after-convergence',
    'a late finding open in the state makes the next round run every lens',
    'a late report without a finding is refused',
    'a late finding below the cap continues the loop',
    'a late report for a state without a round is refused',
    'a late finding in the last security round escalates with security-rounds-spent, for the operator to decide',
    'a late report for an escalated loop is refused',
]);

test('every variant of a late finding is recorded as a finding of the security view (issue #620)', function (): void {
    expect(lateSecurityFindingFile('skills/_shared/review-state.sh'))
        ->toContain('reported late opens a security round and is stored as a security finding')
        ->toContain('reported late is never deferrable')
        ->toContain('jq -c \'.security = true\' "$TMP/round.jsonl"');
});

test('the review-state contract owns the late rule and leonardo points at it (issue #620)', function (): void {
    expect(lateSecurityFindingFile('skills/process-code-review/references/review-state.md'))
        ->toContain('**A security finding reported late counts for its round.**')
        ->toContain('`review-state.sh late --state <file> --input <report>`')
        ->toContain('No late finding escalates with `cap-spent-after-convergence`')
        ->toContain('The round limit and the two security rounds stay as they are.')
        ->toContain('**Every finding of a late report is a security finding.**')
        ->toContain('so `krang` passes `review depth: full` to the round that fixes it');

    expect(lateSecurityFindingFile('skills/process-code-review/references/review-state.md'))
        ->not->toContain('a finding that is not security-relevant, and a `Reviewed revision`');

    expect(lateSecurityFindingFile('agents/krang.md'))
        ->toContain('When the `splinter` handoff of the previous round reported `Clean: no`, pass `review depth: full` whatever `scope` printed');

    expect(lateSecurityFindingFile('agents/leonardo.md'))
        ->toContain('record it first with `review-state.sh late`');
});
