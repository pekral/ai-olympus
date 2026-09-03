<?php

declare(strict_types = 1);

test('web-article-writer names the boundary against every neighbour that also produces content', function (): void {
    $skill = (string) file_get_contents(dirname(__DIR__, 2) . '/skills/web-article-writer/SKILL.md');

    // Nothing else in this package authors long-form editorial prose, so the risk is not
    // duplication of a skill but a caller reaching for the wrong one. Three neighbours produce
    // something a reader could mistake for this skill's job, and each boundary lives in the skill
    // body rather than only in the pull request that added it, so the two stay discoverable apart.
    expect($skill)->toContain(
        'Announcing a change shipped in **this** repository — a tweet, a thread, release notes, '
        . 'or a marketing blurb — belongs to `agents/hermes.md`, not here.',
    );
    expect($skill)->toContain(
        'When the target is a Laravel application and the request includes a broader SEO audit or '
        . 'SEO implementation, also apply `@skills/seo/SKILL.md`.',
    );
    expect($skill)->toContain(
        'use `@skills/diagram-design/SKILL.md`. Step 7 below covers editorial illustration and alt '
        . 'text only.',
    );
});

test('web-article-writer keeps the natural-prose intent in its own steps and mandates no external rewriting tool', function (): void {
    $skill = (string) file_get_contents(dirname(__DIR__, 2) . '/skills/web-article-writer/SKILL.md');

    // The submitted draft closed with an `## Output Humanization` section mandating an external
    // `blader/humanizer` repository for "all skill outputs". It named no invocation mechanism, no
    // install step and no fallback, reached past its own skill, and sat after the skill's own
    // verification pass — so a tool that rewrites finished prose could change what the article
    // claims with nothing left to check it. The section is not shipped.
    expect($skill)->not->toContain('## Output Humanization');
    expect($skill)->not->toContain('blader/humanizer');

    // Dropping the section without keeping its intent would be a silent scope cut, so the two
    // steps that own prose quality carry it: step 5 names the machine register while drafting,
    // step 8 re-checks the same list and states why an external pass is not the fix.
    expect($skill)->toContain('Write prose a human editor would keep.');
    expect($skill)->toContain(
        'Break each of them deliberately as you draft, rather than fixing the text afterwards.',
    );
    expect($skill)->toContain(
        'removal of the machine register step 5 names: opening throat-clearing, hollow bridge '
        . 'phrases, uniform paragraph and list rhythm, and a closing question added only to invite '
        . 'a reply',
    );
    expect($skill)->toContain(
        'Never route the finished article through an external rewriting or "humanizing" tool',
    );
});
