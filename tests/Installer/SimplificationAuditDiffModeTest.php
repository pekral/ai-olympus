<?php

declare(strict_types = 1);

function diffModeSource(string $path): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $path);
}

test('the skill owns one diff mode whose findings anchor only to a changed line', function (): void {
    $section = installerDocsSection(diffModeSource('skills/simplification-audit/SKILL.md'), '## Diff mode (`MODE=cr`)');

    expect($section)->toContain('inside every code review');
    expect($section)->toContain('The mode reads the changed lines, and the code they directly call or replace, only to judge the changed lines.');
    expect($section)->toContain('Every finding anchors to a changed line.');
    expect($section)->toContain('goes to the reviewer\'s out-of-scope observations, never into the published comment.');
    expect($section)->toContain('the same five dimensions');
    expect($section)->toContain('`references/worker-brief.md`');
    expect($section)->toContain('*Never force an abstraction*');
    expect($section)->toContain('a concrete smaller result');
});

test('the diff mode is read-only, publishes nothing, and owns the severity of its findings', function (): void {
    $section = installerDocsSection(diffModeSource('skills/simplification-audit/SKILL.md'), '## Diff mode (`MODE=cr`)');

    expect($section)->toContain('Read-only.');
    expect($section)->toContain('Publishes nothing, and severity.');
    expect($section)->toContain('the mode itself carries no severity');
    expect($section)->toContain('A finding that rests on a rule with its own severity takes that severity, and that rule\'s bullet raises it.');
    expect($section)->toContain('Every other finding is **Moderate**, never Critical.');
    expect($section)->toContain('under `## Findings`');
});

test('the diff mode leaves a new abstraction, a new class, and a speculative interface to the core analysis', function (): void {
    $section = installerDocsSection(diffModeSource('skills/simplification-audit/SKILL.md'), '## Diff mode (`MODE=cr`)');

    expect($section)->toContain('**Gating — one finding per violation, never two.**');
    expect($section)->toContain('a state model, a typed shape, a branching map, a collection or index, or an ownership boundary');
    expect($section)->toContain('`@rules/code-review/core-analysis.md` *Speculative interfaces* and *Simplicity First* own');
    expect($section)->toContain('a new abstraction, a new class for simple logic, and a speculative interface.');
    expect(diffModeSource('rules/code-review/core-analysis.md'))->toContain('- Speculative interfaces')
        ->and(diffModeSource('rules/code-review/core-analysis.md'))->toContain('- **Simplicity First**');
});

test('the review runs the diff mode in every review and restates no part of its contract', function (): void {
    $reference = diffModeSource('skills/code-review/references/specialized-reviews.md');
    $always = explode('- Run conditionally:', $reference)[0];
    $leonardo = diffModeSource('agents/leonardo.md');

    expect($always)->toContain('@skills/simplification-audit/SKILL.md with `MODE=cr`');
    expect($always)->toContain('**always run, never skipped**');
    expect($always)->toContain('That skill\'s *Diff mode* owns the scope, the bar, the read-only contract, and the severity of a finding.');
    expect($always)->toContain('The audit mode of that skill never runs in a review');
    expect($always)->not->toContain('Default severity for a rule violation');
    expect($leonardo)->toContain('`@skills/simplification-audit/SKILL.md` with `MODE=cr` on every review');
    expect($leonardo)->toContain('its severity and gating contract is `@skills/simplification-audit/SKILL.md` *Diff mode*');
    expect($leonardo)->not->toContain('classify each finding by the code review severity rules');
    expect(diffModeSource('skills/code-review/SKILL.md'))->toContain('`simplification-audit` with `MODE=cr`');
    expect(diffModeSource('rules/compound-engineering/orchestration.md'))->toContain(
        '`api-review`, `simplification-audit` with `MODE=cr`, `assignment-compliance-check`',
    );
});

test('the Codex leonardo role loads the shared definition and names the diff mode outside its model tier list', function (): void {
    $toml = diffModeSource('codex/agents/leonardo.toml');
    $tiers = explode('Model tiers for this role on Codex', $toml)[1];

    expect($toml)->toContain('`.codex/agent-instructions/leonardo.md` in full');
    expect($toml)->toContain('runs `@skills/simplification-audit/SKILL.md` with `MODE=cr` over the diff');
    expect($tiers)->not->toContain('simplification-audit');
});

test('the audit mode of the review lens list stays excluded', function (): void {
    $notRun = explode("\n## Skills deliberately not run\n", diffModeSource('skills/code-review/references/specialized-reviews.md'))[1];

    expect($notRun)->toContain('- `simplification-audit` in its audit mode — audits the whole codebase');
});
