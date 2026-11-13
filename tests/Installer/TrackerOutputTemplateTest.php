<?php

declare(strict_types = 1);

/**
 * @return array<string, list<string>>
 */
function trackerOutputPublishers(): array
{
    $registry = json_decode(
        (string) file_get_contents(__DIR__ . '/../Fixtures/tracker-output-publishers.json'),
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );
    assert(is_array($registry));
    $publishers = [];

    foreach ($registry as $publisher => $templates) {
        assert(is_array($templates));
        $publishers[(string) $publisher] = [];

        foreach ($templates as $template) {
            assert(is_string($template));
            $publishers[(string) $publisher][] = $template;
        }
    }

    return $publishers;
}

/**
 * @return list<string>
 */
function trackerOutputTemplateProblems(string $publisher, string $template, string $contents): array
{
    $packageDir = dirname(__DIR__, 2);
    $problems = [];

    if (preg_match('#^skills/(_shared|[a-z0-9-]+)/templates/[A-Za-z0-9_.-]+\.md$#', $template) !== 1) {
        return [$template . ' is not a skill template path'];
    }

    if (!is_file($packageDir . '/' . $template) || filesize($packageDir . '/' . $template) === 0) {
        $problems[] = $template . ' is missing or empty';
    }

    if (!str_contains($contents, 'templates/' . basename($template))) {
        $problems[] = $publisher . ' does not name templates/' . basename($template);
    }

    return $problems;
}

/**
 * @return list<string>
 */
function trackerOutputPublishingFiles(): array
{
    $writes = [
        '#upsert-comment\.sh|gh issue create|gh pr create|gh issue comment|gh pr comment#',
        '#comment create|add_issue_comment|file-deferred-moderate#',
        '#(?:post|publish)\b[^.\n]{0,40}\bcomment\b[^.\n]{0,40}\b(?:on|to) (?:the |each |every |a )?(?:linked |source )?'
        . '(?:github |jira )?(?:issue|pull request|PR|ticket|JIRA|Bugsnag)#i',
    ];
    $files = [];

    foreach (packageTextFiles() as $relativePath => $contents) {
        if (preg_match('#^(skills|agents)/.*\.md$#', $relativePath) !== 1 || str_contains($relativePath, '/templates/')) {
            continue;
        }

        foreach ($writes as $write) {
            if (preg_match($write, $contents) === 1) {
                $files[] = $relativePath;

                break;
            }
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
        expect($templates)->not->toBe([]);

        $contents = (string) file_get_contents($packageDir . '/' . $publisher);

        foreach ($templates as $template) {
            $problems = [...$problems, ...trackerOutputTemplateProblems($publisher, $template, $contents)];
        }
    }

    expect($problems)->toBe([]);
});

test('the reports rule owns the template requirement and its code review severity', function (): void {
    $rule = (string) file_get_contents(dirname(__DIR__, 2) . '/rules/reports/general.md');
    $section = installerDocsSection($rule, '## Every tracker output is rendered from a template');

    expect($section)->toContain('`skills/<skill>/templates/`');
    expect($section)->toContain('`skills/_shared/templates/`');
    expect($section)->toContain('`tests/Fixtures/tracker-output-publishers.json`');
    expect($section)->toContain('`tests/Installer/TrackerOutputTemplateTest.php` fails');
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
    expect($read('skills/resolve-issue/references/pull-request.md'))->not->toContain('- **Summary** —');
    expect($read('skills/resolve-issue/references/pull-request.md'))->not->toContain('Omit the section entirely');
    expect($read('skills/process-code-review/SKILL.md'))->not->toContain('that file owns the shape');
    expect($read('skills/resolve-issue/SKILL.md'))->not->toContain('**Risk areas and edge cases:**');
    expect($read('skills/tester-cookbook/SKILL.md'))->not->toContain('**Body sections:**');
    expect($read('skills/tester-cookbook/SKILL.md'))->not->toContain('(always at the top of the comment, in this order)');
    expect($read('skills/auto-interactive-testing/SKILL.md'))->not->toContain('contains, in this order:');
});

test('a JIRA or Bugsnag template keeps its guidance below the end-of-body marker', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $templates = ['skills/tester-cookbook/templates/test-report-jira.md', 'skills/resolve-issue/templates/pr-link-back-comment.md'];

    foreach ($templates as $template) {
        $contents = (string) file_get_contents($packageDir . '/' . $template);
        $marker = strpos($contents, '=== END OF COMMENT BODY');

        expect($marker)->toBeInt();
        expect(substr($contents, 0, (int) $marker))->not->toContain('<!--');
    }
});

test('the templates that restate a disclosure guard point to its owner', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $read = static fn (string $path): string => (string) file_get_contents($packageDir . '/' . $path);
    $plan = $read('skills/security-threat-analysis/templates/remediation-plan-issue.md');
    $review = $read('skills/_shared/templates/proposal-review-comment.md');

    expect($plan)->toContain('agents/leonardo.md Security analysis mode step 4');
    expect($plan)->toContain('Security-risk analysis section and the Success criteria');
    expect($plan)->toContain('An unfixed Critical is never published by rewording alone');
    expect($plan)->not->toContain('phrase each item as the invariant');
    expect($review)->toContain('skills/_shared/orchestration/proposal-review.md step 6');
    expect($review)->toContain('marked or not');
    expect($review)->not->toContain('A finding marked do not file publicly is never in this comment');
});
