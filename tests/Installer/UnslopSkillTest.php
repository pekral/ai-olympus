<?php

declare(strict_types = 1);

function unslopSource(string $relativePath): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
}

test('the skill keeps the source rules with their stable ids and gaps', function (): void {
    $skill = unslopSource('skills/unslop/SKILL.md');
    $frontmatter = explode("\n---\n", $skill, 2)[0];
    $rules = explode('## Execution', explode('## Rules', $skill, 2)[1], 2)[0];

    expect($frontmatter)->toContain("name: unslop\n")
        ->and($frontmatter)->not->toContain('disable-model-invocation')
        ->and($skill)->toContain('Rule numbers are stable ids that other skills cite. A removed rule leaves a gap.');

    foreach ([3, 5, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 22, 23, 24, 25, 26, 27, 28, 29, 30, 31, 32, 33] as $id) {
        expect(preg_match('/^' . $id . '\. \S/m', $rules))->toBe(1, 'rule ' . $id);
    }

    foreach ([1, 2, 4, 6, 21] as $gap) {
        expect(preg_match('/^' . $gap . '\. /m', $rules))->toBe(0, 'gap ' . $gap);
    }
});

test('the skill carries the attribution and the MIT notice of the source', function (): void {
    $skill = unslopSource('skills/unslop/SKILL.md');
    $license = unslopSource('skills/unslop/LICENSE');

    expect($skill)->toContain('https://github.com/cursor/plugins/blob/main/pstack/skills/unslop/SKILL.md')
        ->and($skill)->toContain('by Lauren Tan')
        ->and($skill)->toContain('MIT License')
        ->and($license)->toContain('Copyright (c) 2026 Lauren Tan')
        ->and($license)->toContain('The above copyright notice and this permission notice shall be included in all');
});

test('the pass rewrites prose only and protects every required part of a tracker comment', function (): void {
    $skill = unslopSource('skills/unslop/SKILL.md');

    expect($skill)->toContain('the agent marker and the marker namespace')
        ->and($skill)->toContain('the headings, fields, and field labels a template requires, and their order')
        ->and($skill)->toContain('finding identifiers, severity labels, and the fields of a code-review finding')
        ->and($skill)->toContain('code identifiers, commands, paths, links, and quoted values')
        ->and($skill)->toContain('`@rules/general/general.md` *Verified statements only*')
        ->and($skill)->toContain('Every fact, number, link, and protected part of the draft is in the result, unchanged');
});

test('the voice skill owns the mandatory pass and the reports rule points to it', function (): void {
    $voice = unslopSource('skills/agent-voice/SKILL.md');
    $reports = unslopSource('rules/reports/general.md');

    expect($voice)->toContain('### 3. Run `unslop` before a tracker comment is published')
        ->and($voice)->toContain(
            'run `@skills/unslop/SKILL.md` over it as the last edit before the publish. The pass is mandatory in every voice and every language.',
        )
        ->and($voice)->toContain('- Every tracker comment for people to read went through `@skills/unslop/SKILL.md` before the publish.')
        ->and(substr_count($reports, '`@skills/unslop/SKILL.md`'))->toBe(1)
        ->and($reports)->toContain('as `@skills/agent-voice/SKILL.md` *Execution* step 3 requires.');
});

test('the skill is listed in the catalog and the changelog', function (): void {
    expect(unslopSource('docs/skills.md'))->toContain('[`unslop`](../skills/unslop/)')
        ->and(unslopSource('README.md'))->toContain('[`unslop`](skills/unslop/SKILL.md)')
        ->and(unslopSource('CHANGELOG.md'))->toContain('the `unslop` skill');
});
