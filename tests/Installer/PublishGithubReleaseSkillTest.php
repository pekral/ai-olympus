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

test('an open issue or an open pull request of the release stops the run, checked again before the tag', function (): void {
    $skill = publishReleaseSource('skills/publish-github-release/SKILL.md');

    expect($skill)->toContain('gh issue list --milestone "<version>" --state open')
        ->and($skill)->toContain('a pull request whose closing reference names an issue of that milestone')
        ->and($skill)->toContain('A non-zero count stops the run.')
        ->and($skill)->toContain('2. Run both checks of step 2 again. A non-zero count stops the run before the tag.');
});

test('a security finding at any severity in the diff since the last tag stops the release', function (): void {
    $skill = publishReleaseSource('skills/publish-github-release/SKILL.md');

    expect($skill)->toContain('git diff <last tag>..origin/<default branch>')
        ->and($skill)->toContain('A finding at any severity stops the run.');
});

test('the release is published only through the audit trail wrapper after the release pull request merged', function (): void {
    $skill = publishReleaseSource('skills/publish-github-release/SKILL.md');

    expect($skill)->toContain('skills/_shared/audit-trail.sh run -- gh release create <version> --verify-tag')
        ->and($skill)->toContain('git merge-base --is-ancestor <release commit> HEAD')
        ->and($skill)->toContain('Never move, delete, or reuse a tag.');
});
