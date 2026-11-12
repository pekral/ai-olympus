<?php

declare(strict_types = 1);

function diffModeSource(string $path): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $path);
}

/**
 * The text of the `## Diff mode` section: the one owner of the diff-mode contract.
 */
function simplificationDiffModeSection(): string
{
    $parts = explode("\n## Diff mode (`MODE=cr`)", diffModeSource('skills/simplification-audit/SKILL.md'));
    expect($parts)->toHaveCount(2);

    return explode("\n## ", $parts[1])[0];
}

test('the skill owns one diff mode whose findings anchor only to the changed lines and what they call or replace', function (): void {
    $section = simplificationDiffModeSection();

    expect($section)->toContain('inside every code review');
    expect($section)->toContain('Findings anchor only to lines the pull request adds or modifies, plus the code those lines directly call or replace.');
    expect($section)->toContain('Code outside that set is never reported');
    expect($section)->toContain('the same five dimensions');
    expect($section)->toContain('`references/worker-brief.md`');
    expect($section)->toContain('*Never force an abstraction*');
    expect($section)->toContain('a concrete smaller result');
});

test('the diff mode is read-only, publishes nothing, and returns unclassified findings to the reviewer', function (): void {
    $section = simplificationDiffModeSection();

    expect($section)->toContain('Read-only.');
    expect($section)->toContain('Publishes nothing.');
    expect($section)->toContain('carries no severity');
    expect($section)->toContain('The reviewer classifies each finding by the code review severity rules');
    expect($section)->toContain('publishes it in its one review comment');
    expect($section)->toContain('@rules/code-review/general.md');
});

test('the whole-codebase audit mode keeps its explicit-request-only rule', function (): void {
    $skill = diffModeSource('skills/simplification-audit/SKILL.md');

    expect($skill)->toContain('**The audit mode runs only on an explicit user request**');
    expect($skill)->toContain('The one other run is *Diff mode* (`MODE=cr`) inside every code review.');
    expect($skill)->toContain('**Never runs when:** no such explicit request was made and no code review runs.');
    expect($skill)->toContain('Steps 1, 2, and 4 and the report template belong to the audit mode only;');
    expect(diffModeSource('skills/simplification-audit/SKILL.md'))->toContain('or in `MODE=cr` over the current diff of every code review');
});

test('leonardo runs the diff mode in every review and classifies its findings, and the Codex role loads that definition', function (): void {
    $leonardo = diffModeSource('agents/leonardo.md');

    expect($leonardo)->toContain('`@skills/simplification-audit/SKILL.md` with `MODE=cr` on every review');
    expect($leonardo)->toContain('classify each finding by the code review severity rules');
    expect($leonardo)->toContain('one review comment');
    expect(diffModeSource('codex/agents/leonardo.toml'))->toContain('`.codex/agent-instructions/leonardo.md` in full')
        ->and(diffModeSource('codex/agents/leonardo.toml'))->toContain('runs `@skills/simplification-audit/SKILL.md` with `MODE=cr` over the diff');
});

test('the review lists the diff mode as an always-run lens and keeps only the audit mode excluded', function (): void {
    $reference = diffModeSource('skills/code-review/references/specialized-reviews.md');
    $always = explode('- Run conditionally:', $reference)[0];

    expect($always)->toContain('@skills/simplification-audit/SKILL.md with `MODE=cr`');
    expect($always)->toContain('**always run, never skipped**');
    expect($always)->toContain('The audit mode of that skill never runs in a review');
    expect($always)->toContain('**Gating — one finding per violation, never two.**');
    $notRun = explode("\n## Skills deliberately not run\n", $reference)[1];

    expect($notRun)->toContain('- `simplification-audit` in its audit mode — audits the whole codebase');
    expect(diffModeSource('skills/code-review/SKILL.md'))->toContain('`simplification-audit` with `MODE=cr`');
});

test('only the reviewer and the lens list wire simplification-audit into a pipeline', function (): void {
    $callers = [];

    foreach (packageTextFiles() as $path => $content) {
        $isPipeline = str_starts_with($path, 'skills/') || str_starts_with($path, 'agents/');

        if ($isPipeline && !str_starts_with($path, 'skills/simplification-audit/') && str_contains($content, '@skills/simplification-audit/SKILL.md')) {
            $callers[] = $path;
        }
    }

    sort($callers);

    expect($callers)->toBe(['agents/leonardo.md', 'skills/code-review/references/specialized-reviews.md']);
});
