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

test('reports/general.md bans developer content from a JIRA comment and names both exceptions (issue #118)', function (): void {
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

    // Exactly two exceptions, written as exceptions rather than as room for interpretation.
    expect($content)->toContain('### Two exceptions, and there is no third');
    expect($content)->toContain('**A string the end user sees is quoted verbatim.**');
    expect($content)->toContain('**One pull-request link at the end.**');

    // The sentence without which the next agent reads the rule as a loss of information.
    expect($content)->toContain('### The technical evidence moves to the pull request; it does not disappear');
    expect($content)->toContain('which is where `@skills/merge-github-pr/SKILL.md` reads it');

    // The cap, and the half of the comment that is never the one shortened.
    expect($content)->toContain('A JIRA comment fits within **3 000 characters**, counted over the published body.');
    expect($content)->toContain('**Never shorten *How to test***');

    // Gating against the sibling section in this same file: the two destinations are disjoint, so
    // no single comment is ever governed by both.
    expect($content)->toContain('### Boundary — this section and the GitHub-PR English exception never fire on the same comment');
});

test('the merge-readiness TL;DR obeys the JIRA banned list on a JIRA source (issue #118)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $skill = (string) file_get_contents($packageDir . '/skills/verify-merge-readiness/SKILL.md');

    // This path published the head SHA, the diff fingerprint, and the gate result to whichever
    // tracker the source was — the one route that would have bypassed the new rule.
    expect($skill)->toContain('**On a JIRA ticket the last item is not published, and the first three take the JIRA shape.**');
    expect($skill)->toContain('A JIRA comment is written for a non-technical reader');
    expect($skill)->toContain('On a GitHub issue publish all four items above unchanged.');
});
