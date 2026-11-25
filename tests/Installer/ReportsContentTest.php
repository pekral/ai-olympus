<?php

declare(strict_types = 1);

test('reports/general.md rule ships in the package and declares the canonical language statement', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $rulePath = $packageDir . '/rules/reports/general.md';

    expect(is_file($rulePath))->toBeTrue();

    $content = (string) file_get_contents($rulePath);

    expect($content)->toContain('Tracker-Published Reports — Language');
    expect($content)->toContain('same language as the source assignment');
    expect($content)->toContain('Czech');
    expect($content)->toContain('Code identifiers stay verbatim');
    expect($content)->toContain('@rules/git/general.md');
});

test('every tracker-publishing skill references @rules/reports/general.md', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $trackerPublishingSkills = [
        $packageDir . '/skills/pr-summary/SKILL.md',
        $packageDir . '/skills/code-review/SKILL.md',
        $packageDir . '/skills/code-review-github/SKILL.md',
        $packageDir . '/skills/code-review-jira/SKILL.md',
        $packageDir . '/skills/process-code-review/SKILL.md',
        $packageDir . '/skills/security-review/SKILL.md',
        $packageDir . '/skills/security-threat-analysis/SKILL.md',
        $packageDir . '/skills/assignment-compliance-check/SKILL.md',
        $packageDir . '/skills/resolve-issue/SKILL.md',
        $packageDir . '/skills/tester-cookbook/SKILL.md',
        $packageDir . '/skills/prepare-issue-context/SKILL.md',
    ];

    foreach ($trackerPublishingSkills as $skillFile) {
        $content = crContractText($skillFile);

        $hasReference = str_contains($content, '@rules/reports/general.md');

        expect($hasReference)->toBeTrue($skillFile . ' must reference the shared tracker-report language rule (@rules/reports/general.md)');
    }
});

test('no tracker-publishing skill still carries the obsolete "must be in English" constraint', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $forbiddenPatterns = [
        '/^[-*]\s*All output must be in English\s*$/m',
        '/^[-*]\s*All output posted to GitHub must be in English\s*$/m',
        '/^[-*]\s*GitHub output must be in English\s*$/m',
        '/^[-*]\s*All CR output must be written in English\s*$/m',
        '/^[-*]\s*Output must be in English\s*$/m',
    ];
    $skills = [
        $packageDir . '/skills/code-review/SKILL.md',
        $packageDir . '/skills/code-review-github/SKILL.md',
        $packageDir . '/skills/code-review-jira/SKILL.md',
        $packageDir . '/skills/process-code-review/SKILL.md',
        $packageDir . '/skills/security-review/SKILL.md',
        $packageDir . '/skills/security-threat-analysis/SKILL.md',
    ];

    foreach ($skills as $skillFile) {
        $content = (string) file_get_contents($skillFile);

        foreach ($forbiddenPatterns as $pattern) {
            expect((bool) preg_match($pattern, $content))->toBeFalse(
                $skillFile . ' still carries an obsolete English-only constraint matching ' . $pattern,
            );
        }
    }
});

test('reports/general.md declares the GitHub-PR technical-CR English exception', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/rules/reports/general.md');

    expect($content)->toContain('Exception — technical CR findings on the GitHub PR');
    expect($content)->toContain('canonical English');
    expect($content)->toContain('@skills/code-review-github/SKILL.md');
    expect($content)->toContain('@skills/process-code-review/SKILL.md');
    expect($content)->toContain('exception does **not** extend to');
    expect($content)->toContain('pr-summary');
});

test('reports/general.md bans bilingual parentheses and mid-comment language mixing', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/rules/reports/general.md');

    expect($content)->toContain('never mix that language with another natural language');
    expect($content)->toContain('No bilingual parentheses');
    expect($content)->toContain('Kritické (Critical)');
    expect((bool) preg_match('/use the Czech equivalents \(e\.g\. \*Kritické\*, \*Závažné\*, \*Drobné\*\)/', $content))->toBeFalse();
});

test('CR wrapper skills carry the GitHub-PR English exception in their constraints', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $crWrapperSkills = [
        $packageDir . '/skills/code-review-github/SKILL.md',
        $packageDir . '/skills/code-review-jira/SKILL.md',
        $packageDir . '/skills/code-review/SKILL.md',
        $packageDir . '/skills/process-code-review/SKILL.md',
        $packageDir . '/skills/security-review/SKILL.md',
        $packageDir . '/skills/security-threat-analysis/SKILL.md',
        $packageDir . '/skills/resolve-issue/SKILL.md',
    ];

    foreach ($crWrapperSkills as $skillFile) {
        $content = crContractText($skillFile);

        $namesException = str_contains($content, 'Exception — technical CR findings on the GitHub PR');
        $mentionsCanonicalEnglish = str_contains($content, 'canonical English');

        expect($namesException && $mentionsCanonicalEnglish)->toBeTrue(
            $skillFile . ' must cite the GitHub-PR technical-CR English exception from @rules/reports/general.md',
        );
    }
});

test('reports/general.md bans developer content from a JIRA comment and names its exceptions (issue #118)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $content = (string) file_get_contents($packageDir . '/rules/reports/general.md');

    // The whole section is new on this branch, so every assertion below fails on the base branch.
    expect($content)->toContain('## A JIRA comment is written for a non-technical reader');

    // The banned list, item by item — a partial list would let the next agent publish the half
    // that was dropped from the test rather than from the rule.
    foreach ([
        '- class, method, function, variable, enum, and file names',
        '- file paths and line numbers',
        '- commit SHAs, diff fingerprints, branch names',
        '- quality-gate results, CI status, test / assertion counts, coverage figures',
        '- severity labels, finding counts, rule references',
        '- code blocks, and the names of internal layers (Action, Repository, Data Builder)',
    ] as $bannedItem) {
        expect($content)->toContain($bannedItem);
    }

    // Removed, never annotated: naming the omission spends the reader's attention anyway.
    expect($content)->toContain('An item on this list is **removed, never annotated**.');

    // Exactly three exceptions, written as exceptions rather than as room for interpretation.
    expect($content)->toContain('### Three exceptions, and there is no fourth');
    expect($content)->toContain('**A string the end user sees is quoted verbatim.**');
    expect($content)->toContain('**Links into the pull request.**');
    expect($content)->toContain('The label of such a link is fixed text, never a path or a line number');
    expect($content)->toContain('**Links to related tracker items and to the product documentation.**');
    expect($content)->toContain('Any other link to code, a commit, a CI run, or a log stays banned.');

    // The sentence without which the next agent reads the rule as a loss of information.
    expect($content)->toContain('### The technical evidence moves to the pull request; it does not disappear');
    expect($content)->toContain('which is where `@skills/merge-github-pr/SKILL.md` reads it');

    // The cap, and the bullets that are never the ones dropped. A JIRA comment has no How to test.
    expect($content)->toContain(
        'A JIRA comment fits within **3 000 characters**, counted over the visible text of the published body: a link counts its label, never its address.',
    );
    expect($content)->toContain('**Never drop an *Acceptance criteria* bullet or a *Review findings* bullet**');
    expect($content)->not->toContain('**Never shorten *How to test***');

    // Gating against the sibling section in this same file: the two destinations are disjoint, so
    // no single comment is ever governed by both.
    expect($content)->toContain('### Boundary — this section and the GitHub-PR English exception never fire on the same comment');
});

test('the merge-readiness TL;DR obeys the JIRA banned list on a JIRA source (issue #118)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $skill = (string) file_get_contents($packageDir . '/skills/verify-merge-readiness/SKILL.md');

    // This path published the head SHA, the diff fingerprint, and the gate result to whichever
    // tracker the source was — the one route that would have bypassed the new rule.
    expect($skill)->toContain('**On a JIRA ticket the TL;DR takes the JIRA shape, and the decisions move to the pull request.**');
    expect($skill)->toContain('A JIRA comment is written for a non-technical reader');
    expect($skill)->toContain('On a GitHub issue publish all four items above unchanged, in one comment on the issue.');

    // Only a Critical decision reaches the ticket; the merge gate reads every decision off the PR.
    expect($skill)->toContain('A decision outside the assignment that is not
   Critical never reaches JIRA, neither as a finding nor as a question.');
    expect($skill)->toContain('`@skills/merge-github-pr/SKILL.md` *Open merge decisions* reads
   the decisions of a JIRA source here');
});

test('every remaining JIRA publish path renders the JIRA shape rather than another target\'s (issue #118)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $jiraWrapper = (string) file_get_contents($packageDir . '/skills/code-review-jira/SKILL.md');
    $resolveIssue = (string) file_get_contents($packageDir . '/skills/resolve-issue/SKILL.md');
    $trackerFollowUp = (string) file_get_contents($packageDir . '/skills/resolve-issue/references/tracker-follow-up.md');

    // The linked-GitHub-issue mirror was documented as identical to the JIRA comment. The two
    // targets render different shapes now, so copying one body onto the other publishes a shape
    // the receiving target's contract does not define.
    expect($jiraWrapper)->not->toContain('Both come from `pr-summary`, so they are guaranteed to match.');
    expect($jiraWrapper)->toContain('carry the same facts, but **not the same shape**');
    expect($jiraWrapper)->toContain('Do not copy one body onto the other target');

    // resolve-issue posts its own non-technical report to the source tracker before any review
    // runs, so it needs the same shape and the same content rules on a JIRA source.
    expect($resolveIssue)->toContain('use the JIRA shape per `references/tracker-follow-up.md`');
    expect($trackerFollowUp)->toContain('**The non-technical report on a JIRA issue takes the JIRA shape.**');
    expect($trackerFollowUp)->not->toContain('travel **inside** the `How to test` steps they belong to');
    expect($trackerFollowUp)->toContain('Before the code review runs, `Review findings` is one sentence stating that the review has not run yet.');
});

test('every restatement of the JIRA shape names what changed and what it affects with no TL;DR label (#709)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $reports = (string) file_get_contents($packageDir . '/rules/reports/general.md');
    $summary = (string) file_get_contents($packageDir . '/skills/pr-summary/SKILL.md');

    expect($reports)->toContain('*Nálezy z kontroly kódu*, and *Co se změnilo a co to ovlivní*, never an English heading')
        ->and($summary)->toContain('`Nálezy z kontroly kódu` / `Co se změnilo a co to ovlivní`.')
        ->and($summary)->toContain('a few bullets with no TL;DR label combine observable changes with what to watch after the release')
        ->and($summary)->not->toContain('one TL;DR paragraph');
});

test('exception 2 keeps the link of a finding outside the pull request at the reviewed head commit, and every publisher keeps it', function (): void {
    $rule = packageSource('rules/reports/general.md');
    $april = packageSource('agents/april.md');
    $wrapper = packageSource('skills/code-review-jira/SKILL.md');

    expect(packageSection($rule, '2. **Links into the pull request.**', '3. **Links to related tracker items'))->toContain(
        'A finding about code outside the pull request that no pull request fixes ends with the link to its file and line in the task repository '
        . 'at the reviewed head commit, the `Reviewed revision:` of the code-review report (owner decision of 2026-11-25).',
    )
        ->and($april)->toContain(
            'every link that exception 2 of that rule allows — the links into the pull request, and the link of a finding outside the pull request '
            . 'to its file and line at the reviewed head commit.',
        )
        ->and($april)->not->toContain('and the links into the pull request that exception 2 of that rule allows.')
        ->and($wrapper)->toContain('the link of a finding outside the pull request to its file and line at the reviewed head commit are the exceptions');
});
