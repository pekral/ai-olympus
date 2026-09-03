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

test('web-article-writer keeps the natural-prose intent in its own steps and orders any external rewriting pass before verification', function (): void {
    $skill = (string) file_get_contents(dirname(__DIR__, 2) . '/skills/web-article-writer/SKILL.md');

    // The submitted draft closed with an `## Output Humanization` section mandating an external
    // `blader/humanizer` repository for "all skill outputs". The source file does not carry it —
    // though `InstallerHumanizer` appends that same section to every installed `SKILL.md`, this one
    // included, which is the pre-existing package-wide behaviour tracked in issue #111.
    expect($skill)->not->toContain('## Output Humanization');
    expect($skill)->not->toContain('blader/humanizer');

    // Dropping the section without keeping its intent would be a silent scope cut, so the two
    // steps that own prose quality carry it: step 5 names the machine register while drafting,
    // step 8 re-checks the same list and orders any external pass ahead of itself.
    expect($skill)->toContain('Write prose a human editor would keep.');
    expect($skill)->toContain(
        'Break each of them deliberately as you draft, rather than fixing the text afterwards.',
    );
    expect($skill)->toContain(
        'removal of the machine register step 5 names: opening throat-clearing, hollow bridge '
        . 'phrases, uniform paragraph and list rhythm, and a closing question added only to invite '
        . 'a reply',
    );

    // The one objection to the draft's directive that survived verification is ordering: it landed
    // after the step that checks what the article claims. Step 8 therefore orders an external
    // rewriting pass instead of banning it, and sends the article back through verification when
    // one runs late — so the shipped file no longer contradicts the section the installer appends.
    expect($skill)->toContain(
        'Run any external rewriting or "humanizing" tool before this step, never after it',
    );
    expect($skill)->toContain(
        'When any tool rewrites the article once this step is done, run this step again over the '
        . 'result.',
    );
});
