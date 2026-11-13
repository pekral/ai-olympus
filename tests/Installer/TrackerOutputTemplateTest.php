<?php

declare(strict_types = 1);

/**
 * @return array<string, list<string>>
 */
function trackerOutputPublishers(): array
{
    $cr = 'skills/code-review-github/templates/pr-comment-output.md';
    $summaryGithub = 'skills/pr-summary/templates/pr-summary-github.md';
    $summaryJira = 'skills/pr-summary/templates/pr-summary-jira.md';
    $summaryBugsnag = 'skills/pr-summary/templates/pr-summary-bugsnag.md';
    $pullRequestBody = 'skills/resolve-issue/templates/pull-request-body.md';
    $decisions = 'skills/verify-merge-readiness/templates/decisions-before-merge.md';
    $followUp = 'skills/_shared/templates/follow-up-issue.md';
    $analysis = 'skills/analyze-problem/templates/analysis-report.md';
    $issueStructure = 'skills/create-issues-from-text/templates/issue-structure.md';

    return [
        'agents/april.md' => [$summaryGithub, $summaryJira, 'skills/test-assignment/templates/test-report-pr.md', $decisions, 'skills/_shared/templates/announcement-drafts-comment.md'],
        'agents/casey.md' => ['skills/mysql-problem-solver/templates/cr-report.md'],
        'agents/donatello.md' => [$pullRequestBody],
        'agents/krang.md' => ['skills/_shared/templates/superseded-pr-pointer.md', 'skills/_shared/templates/proposal-review-comment.md', 'skills/_shared/templates/memory-entry-written.md'],
        'agents/leonardo.md' => [$cr, 'skills/security-threat-analysis/templates/remediation-plan-issue.md'],
        'skills/_shared/orchestration/batch-preparation.md' => ['skills/_shared/templates/superseded-pr-pointer.md'],
        'skills/_shared/orchestration/proposal-review.md' => ['skills/_shared/templates/proposal-review-comment.md'],
        'skills/analyze-problem/SKILL.md' => [$analysis],
        'skills/analyze-support-issue/SKILL.md' => ['skills/analyze-support-issue/templates/support-comment-jira.md'],
        'skills/assignment-compliance-check/SKILL.md' => ['skills/assignment-compliance-check/templates/assignment-compliance.md'],
        'skills/code-review-bugsnag/SKILL.md' => ['skills/code-review-bugsnag/templates/github-output.md', $summaryBugsnag],
        'skills/code-review-github/references/cr-wrapper-contract.md' => [$cr, $summaryGithub],
        'skills/code-review-jira/SKILL.md' => ['skills/code-review-jira/templates/github-output.md', $summaryJira],
        'skills/code-review/references/feedback-loop.md' => ['skills/_shared/templates/memory-entry-written.md', 'skills/code-review/templates/prevention-proposal.md'],
        'skills/code-review/references/prevention-proposal.md' => ['skills/code-review/templates/prevention-proposal.md'],
        'skills/composer-update-pr/SKILL.md' => ['skills/composer-update-pr/templates/pull-request-body.md'],
        'skills/create-issue/SKILL.md' => ['skills/create-issue/templates/issue-body.md'],
        'skills/create-issues-from-text/SKILL.md' => [$issueStructure],
        'skills/github-issue-triage/SKILL.md' => ['skills/github-issue-triage/templates/needs-info-comment.md'],
        'skills/github-issue-triage/references/backlog-sweep.md' => ['skills/github-issue-triage/templates/close-evidence-comment.md'],
        'skills/github-issue-triage/references/pending-analysis.md' => [$analysis],
        'skills/github-release-roadmap/references/github-cli-workflow.md' => [$issueStructure],
        'skills/pr-summary/SKILL.md' => [$summaryGithub, $summaryJira, $summaryBugsnag],
        'skills/process-code-review/SKILL.md' => [$cr, $pullRequestBody],
        'skills/process-code-review/references/round-three-deferral.md' => [$followUp],
        'skills/resolve-issue/SKILL.md' => ['skills/resolve-issue/templates/technical-report-comment.md'],
        'skills/resolve-issue/references/deferred-follow-up.md' => [$followUp],
        'skills/resolve-issue/references/pull-request.md' => [$pullRequestBody],
        'skills/resolve-issue/references/tracker-follow-up.md' => ['skills/resolve-issue/templates/pr-link-back-comment.md', $summaryJira],
        'skills/security-threat-analysis/SKILL.md' => ['skills/security-threat-analysis/templates/remediation-report.md'],
        'skills/test-assignment/SKILL.md' => ['skills/test-assignment/templates/test-report-pr.md'],
        'skills/tester-cookbook/SKILL.md' => ['skills/tester-cookbook/templates/test-report-jira.md'],
        'skills/verify-merge-readiness/SKILL.md' => [$decisions, $summaryGithub, $summaryJira],
    ];
}

/**
 * @return list<string>
 */
function trackerOutputPublishingFiles(): array
{
    $writes = '#upsert-comment\.sh|gh issue create|gh pr create|gh issue comment|gh pr comment|comment create|add_issue_comment|file-deferred-moderate#';
    $files = [];

    foreach (packageTextFiles() as $relativePath => $contents) {
        if (preg_match('#^(skills|agents)/.*\.md$#', $relativePath) !== 1 || str_contains($relativePath, '/templates/')) {
            continue;
        }

        if (preg_match($writes, $contents) === 1) {
            $files[] = $relativePath;
        }
    }

    sort($files);

    return $files;
}

test('every file that writes to an issue tracker is registered with the templates it renders', function (): void {
    $exempt = [
        'agents/raphael.md',
        'skills/process-code-review/references/review-state.md',
    ];

    $unregistered = array_values(array_diff(
        trackerOutputPublishingFiles(),
        array_keys(trackerOutputPublishers()),
        $exempt,
    ));

    expect($unregistered)->toBe([]);
});

test('every registered publisher names each of its templates and the template exists', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $problems = [];

    foreach (trackerOutputPublishers() as $publisher => $templates) {
        $contents = (string) file_get_contents($packageDir . '/' . $publisher);

        expect($templates)->not->toBe([]);

        foreach ($templates as $template) {
            if (preg_match('#^skills/(_shared|[a-z0-9-]+)/templates/[A-Za-z0-9_.-]+\.md$#', $template) !== 1) {
                $problems[] = $template . ' is not a skill template path';

                continue;
            }

            if (!is_file($packageDir . '/' . $template) || filesize($packageDir . '/' . $template) === 0) {
                $problems[] = $template . ' is missing or empty';
            }

            if (!str_contains($contents, 'templates/' . basename($template))) {
                $problems[] = $publisher . ' does not name templates/' . basename($template);
            }
        }
    }

    expect($problems)->toBe([]);
});

test('the reports rule owns the template requirement and its code review severity', function (): void {
    $rule = (string) file_get_contents(dirname(__DIR__, 2) . '/rules/reports/general.md');
    $section = installerDocsSection($rule, '## Every tracker output is rendered from a template');

    expect($section)->toContain('`skills/<skill>/templates/`');
    expect($section)->toContain('`skills/_shared/templates/`');
    expect($section)->toContain('tests/Installer/TrackerOutputTemplateTest.php');
    expect($section)->toContain('is a **Moderate** finding');
    expect($section)->toContain('without a template');
    expect($section)->not->toContain('## Tracker-Published Reports');
});

test('the markers that scripts read stay in the templates that carry them', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $read = static fn (string $path): string => (string) file_get_contents($packageDir . '/' . $path);

    expect($read('skills/github-issue-triage/templates/needs-info-comment.md'))->toStartWith('<!-- github-issue-triage:needs-info -->');
    expect($read('skills/code-review/templates/prevention-proposal.md'))->toContain('<!-- prevention-proposal:key=');
    expect($read('skills/verify-merge-readiness/templates/decisions-before-merge.md'))->toContain('## Decisions before merge');
    expect($read('skills/resolve-issue/templates/pull-request-body.md'))->toStartWith('Closes #<N>');
    expect($read('skills/security-threat-analysis/templates/remediation-plan-issue.md'))->toContain('- [ ] [Critical]');
});

test('the prevention proposal template lists the headings the proposal script renders', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $template = (string) file_get_contents($packageDir . '/skills/code-review/templates/prevention-proposal.md');
    $script = (string) file_get_contents($packageDir . '/skills/_shared/propose-prevention.sh');

    preg_match_all('/^#{2,3} .+$/m', $template, $headings);

    expect($headings[0])->not->toBe([]);

    foreach ($headings[0] as $heading) {
        expect($script)->toContain('"' . $heading . '"');
    }
});

test('a skill points to the template of an output and does not describe its shape again', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $read = static fn (string $path): string => (string) file_get_contents($packageDir . '/' . $path);

    expect($read('skills/github-issue-triage/SKILL.md'))->not->toContain('**What we already know**');
    expect($read('skills/verify-merge-readiness/SKILL.md'))->not->toContain('Options: **A** implement in this PR');
    expect($read('skills/assignment-compliance-check/SKILL.md'))->not->toContain('#### 1. <short title in everyday language>');
    expect($read('skills/composer-update-pr/SKILL.md'))->not->toContain('one row per package with `level` `major`: name');
    expect($read('skills/security-threat-analysis/SKILL.md'))->not->toContain('# Security Threat Analysis — <Threat Title>');
    expect($read('skills/code-review/references/prevention-proposal.md'))->not->toContain('| `## Defect class` |');
});
