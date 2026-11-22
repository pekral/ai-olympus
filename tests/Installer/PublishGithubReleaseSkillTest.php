<?php

declare(strict_types = 1);

function publishReleaseSource(string $relativePath): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
}

/**
 * @return list<string>
 */
function publishReleaseGlob(string $pattern): array
{
    $paths = glob(dirname(__DIR__, 2) . '/' . $pattern);

    return $paths === false ? [] : $paths;
}

test('only an explicit user invocation starts the release, on both platforms', function (): void {
    $skill = publishReleaseSource('skills/publish-github-release/SKILL.md');
    $callers = [...publishReleaseGlob('agents/*.md'), ...publishReleaseGlob('codex/agents/*.toml'), ...publishReleaseGlob('commands/*.md')];

    expect($skill)->toMatch('/^disable-model-invocation: true$/m')
        ->and(publishReleaseSource('skills/publish-github-release/agents/openai.yaml'))->toContain("policy:\n  allow_implicit_invocation: false")
        ->and($skill)->toContain('`Blocked: explicit user invocation required`')
        ->and($callers)->not->toBe([]);

    foreach ($callers as $caller) {
        expect(file_get_contents($caller))->not->toContain('publish-github-release');
    }
});

test('a missing milestone, an open issue, or an open pull request of the release stops the run before the tag', function (): void {
    $skill = publishReleaseSource('skills/publish-github-release/SKILL.md');

    expect($skill)->toContain('No milestone stops the run: report both checks as not run')
        ->and($skill)->toContain('gh issue list --milestone "<version>" --state open --limit 1000')
        ->and($skill)->toContain('when its closing reference names an issue of that milestone')
        ->and($skill)->toContain('A list that returns as many items as its `--limit` stops the run')
        ->and($skill)->toContain('The run never removes an issue or a pull request from the milestone to pass a check.')
        ->and($skill)->toContain('3. Run both checks of step 2 again. A non-zero count stops the run before the tag.');
});

test('a security finding stops the release and is reported in the chat only', function (): void {
    $skill = publishReleaseSource('skills/publish-github-release/SKILL.md');

    expect($skill)->toContain('git diff <last tag>..<audited commit>')
        ->and($skill)->toContain('A finding at any severity stops the run. Report it to the user in the chat only');
});

test('the tag goes on the merged release commit and the release adds no unaudited commit', function (): void {
    $skill = publishReleaseSource('skills/publish-github-release/SKILL.md');

    expect($skill)->toContain('gh pr view <release PR> --json mergeCommit --jq .mergeCommit.oid')
        ->and($skill)->toContain('git rev-list --count <audited commit>..<release commit>')
        ->and($skill)->toContain('git tag -a <version> <release commit>')
        ->and($skill)->toContain('Never move, delete, or reuse a tag.');
});

test('every write of the release goes through the audit trail wrapper', function (): void {
    $skill = publishReleaseSource('skills/publish-github-release/SKILL.md');

    expect($skill)->toContain('AUDIT_TRAIL_FILE=<top>/.claude/run/release-<version>.audit')
        ->and($skill)->toContain('commands/_shared/audit-trail.sh run -- git push origin <version>')
        ->and($skill)->toContain('commands/_shared/audit-trail.sh run -- gh release create <version> --verify-tag');
});
