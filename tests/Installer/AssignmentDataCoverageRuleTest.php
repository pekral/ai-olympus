<?php

declare(strict_types = 1);

/**
 * Issue #69 — the acceptance-criteria gate used to ask only that a test target the right
 * *scenario*. A reporter who states "an order below 499 Kč is rejected" and attaches the payload
 * has named the input that reproduces the defect, yet a test asserting the same rejection at an
 * invented 100 Kč passed the gate. These tests pin the data condition the gate gained, together
 * with the carve-outs that keep it from firing on a test that is already correct.
 */
function assignmentDataGateRule(): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/rules/code-review/review-process.md');
}

function assignmentDataGateSkill(): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/skills/code-review/SKILL.md');
}

test('the acceptance-criteria gate requires the test to use the data the assignment states (issue #69)', function (): void {
    $rule = assignmentDataGateRule();

    // The conjunction is the whole change: scenario alone was the old bar, scenario AND data is
    // the new one. Stating it once, in the gate that already owns use-case coverage, is what
    // makes the skill-side pointer below a reference rather than a second, weaker rule.
    expect(substr_count($rule, 'uses the data the assignment states'))->toBe(1);
    expect($rule)->toContain('The test must also use the data the assignment states (issue #69)');
    expect($rule)->toContain('targets the scenario **and** uses the data the assignment states');

    // Without the enumeration a reviewer has to guess what "data" means, and the gate degrades
    // into a judgment call. It is the definition, so it lives in exactly one place package-wide.
    // Built from two halves on purpose: the package-wide sweep below counts occurrences of this
    // string, and a contiguous literal here would count itself and make the assertion unfalsifiable.
    $enumeration = 'a concrete input value, a request / event payload, a boundary value, '
        . 'an expected output, or an expected error message';
    expect($rule)->toContain($enumeration);

    $definitions = 0;

    foreach (packageTextFiles() as $contents) {
        $definitions += substr_count($contents, $enumeration);
    }

    expect($definitions)->toBe(1);
});

test('the data condition does not apply when the assignment states no concrete data (issue #69)', function (): void {
    $rule = assignmentDataGateRule();

    // The regression criterion of the issue: on a PR whose assignment names no value, the counts
    // must equal what they were before the condition existed. A condition that fired on a
    // wordy-only assignment would raise a Critical on work that satisfies the assignment exactly.
    expect($rule)->toContain('**No stated data, no condition.**');
    expect($rule)->toContain('this condition does not apply');
    expect($rule)->toContain('Never invent a value the assignment does not carry and then flag a test for missing it');
    expect($rule)->toContain('raises nothing and the finding counts are identical to what they were before it existed');
});

test('the data condition names what is not a deviation so it cannot fire on a named fixture (issue #69)', function (): void {
    $rule = assignmentDataGateRule();

    // A rule that flagged a test for reaching 499 through a constant instead of a literal would
    // fire on almost every well-written test in a codebase, and would be ignored within a week.
    // The three carve-outs and the resolve-then-judge instruction are what prevent that.
    expect($rule)->toContain('**What is not a deviation.**');
    expect($rule)->toContain('**minimum, never a ceiling**');
    expect($rule)->toContain('**named fixture, constant, dataset, or factory** instead of a literal');
    expect($rule)->toContain('**equivalent representation** of the same value');
    expect($rule)->toContain('**additional** values, cases, or assertions the test adds beyond the stated ones');
    expect($rule)->toContain('Resolve a fixture or constant to the value it holds and judge that value');

    // The positive half has to stay concrete too, or the negative list swallows the finding.
    expect($rule)->toContain('**What is a deviation (the finding).**');
    expect($rule)->toContain('a boundary value the assignment names that no test exercises');
});

test('the data finding is gated against the coverage gate and test organization (issue #69)', function (): void {
    $rule = assignmentDataGateRule();

    // Both neighbours can plausibly claim the same test: the Coverage gate because the line does
    // execute, Test organization because the test is "wrong". Naming the owner in the gate itself
    // is half the fix; the other half is below, in the file that carries the Test organization walk.
    expect($rule)->toContain('**Gating — one finding per violation, never two.**');
    expect($rule)->toContain('a line a wrong-data test executes is covered for that gate');
    expect($rule)->toContain('Never raise two of these three on the same test.');

    $coreAnalysis = (string) file_get_contents(dirname(__DIR__, 2) . '/rules/code-review/core-analysis.md');

    expect($coreAnalysis)->toContain('This bullet also never owns the **data** a test uses');
    expect($coreAnalysis)->toContain('*Acceptance-criteria use-case coverage* finding in');
    expect($coreAnalysis)->toContain('never a Test organization finding — raise one, never both');
});

test('the code-review skill points at the gate instead of restating a weaker rule (issue #69)', function (): void {
    $skill = assignmentDataGateSkill();

    // The removed sentence carried neither a definition of coverage nor a severity, so the same
    // situation produced a Critical under the rule and an unranked "finding" under the skill.
    // `origin/master` carries exactly one occurrence of it; this run removes it.
    expect(substr_count($skill, 'If the issue contains test data or test scenarios'))->toBe(0);
    expect(substr_count($skill, 'verify they are covered by existing or new tests'))->toBe(0);

    expect($skill)->toContain('Hand the extracted criteria, scenarios, and test data to the **Validation & Coverage Gate**');
    expect($skill)->toContain('*Acceptance-criteria use-case coverage* bullet owns the whole contract');
    expect($skill)->toContain('Do not restate a weaker version of it here');
});

