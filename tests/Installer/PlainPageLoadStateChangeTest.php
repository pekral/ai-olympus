<?php

declare(strict_types = 1);

test(
    'the security rule owns the finding, the safe shape, the three limits, and the Moderate severity of a state change on a plain page load',
    function (): void {
        $rule = packageSource('rules/security/backend.md');
        $section = packageSection($rule, '## State Change on a Plain Page Load (issue #681)', '## Output Rendering');
    
        expect(substr_count($rule, '## State Change on a Plain Page Load (issue #681)'))->toBe(1)
            ->and($section)->toContain('A page that a browser opens with a plain page load (a `GET` or `HEAD` request) must never change data.')
            ->and($section)->toContain('changes data or connects an external account or service')
            ->and($section)->toContain('The page only shows what will happen.')
            ->and($section)->toContain('The change runs only after the signed-in user confirms it in a form that carries an anti-CSRF token')
            ->and($section)->toContain('The Suggested Fix names the page and proposes this shape.')
            ->and($section)->toContain('a page whose handler reaches a line the diff changes through an action, a service, a model event, ')
            ->and($section)->toContain('a listener, or a synchronously dispatched job')
            ->and($section)->toContain('the caller walk of `@rules/code-review/core-analysis.md` *Behaviour changed outside the diff*')
            ->and($section)->toContain('An existing page outside the change is not a finding.')
            ->and($section)->toContain('**A page that only records a visit or a statistic is not a finding.**')
            ->and($section)->toContain('**A return from an external sign-in or connection flow is not a finding**')
            ->and($section)->toContain(
                'a one-time value that is bound to the user who started the flow, and the handler checks that value before anything changes',
            )
            ->and($section)->toContain('**Moderate**, as a security finding, for a page that')
            ->and($section)->toContain('does not already mark Critical. It is never deferred to a later pull request')
            ->and($section)->toContain('and never lowered.')
            ->and($section)->toContain('Raise one finding per page: a page that is already reported under *CSRF Protection* is not reported again here.');
    },
);

test('a page that both the security rule and the API rule match gets one finding at the Critical severity of the API rule', function (): void {
    $rule = packageSource('rules/security/backend.md');
    $section = packageSection($rule, '## State Change on a Plain Page Load (issue #681)', '## Output Rendering');
    $apiReview = packageSource('skills/api-review/SKILL.md');
    $apiRule = packageSource('rules/api/general.md');

    expect($section)->toContain('A page that is also a `GET` that mutates state under `@rules/api/general.md` *CR Severity Rules* gets that one finding ')
        ->and($section)->toContain('at the **Critical** severity of that rule, as a security finding that is never deferred.')
        ->and($section)->toContain('This section raises no second finding for it, and neither lens lowers the severity.')
        ->and($apiRule)->toContain('`GET` that mutates state')
        ->and($apiReview)->toContain('is also in the scope of `@rules/security/backend.md` *State Change on a Plain Page Load (issue #681)*. ')
        ->and($apiReview)->toContain('Raise one finding for it, as the *Scope boundary* of that section states.');
});

test('the CSRF protection section points at the section that owns a state change on a plain page load', function (): void {
    $rule = packageSource('rules/security/backend.md');
    $csrf = packageSection($rule, '### CSRF Protection', '## State Change on a Plain Page Load (issue #681)');

    expect($csrf)->toContain('Never change data on a plain page load.')
        ->and($csrf)->toContain('*State Change on a Plain Page Load* below owns that surface.');
});

test('the security review has a plain-page-load step that runs in every review round and restates no rule', function (): void {
    $skill = packageSource('skills/security-review/SKILL.md');
    $step = packageSection($skill, '### State Change on a Plain Page Load (issue #681)', '### Route Access of Changed Routes (issue #586)');

    expect(substr_count($skill, '### State Change on a Plain Page Load (issue #681)'))->toBe(1)
        ->and($step)->toContain('This step runs in **every review round**')
        ->and($step)->toContain('`@rules/security/backend.md` *State Change on a Plain Page Load (issue #681)* owns the rule')
        ->and($step)->toContain('each page that a browser opens with a plain page load and that the diff adds')
        ->and($step)->toContain('whose route definition or handler the diff changes')
        ->and($step)->toContain('whose handler reaches a line the diff changes through an action, a service, a model event, ')
        ->and($step)->toContain('a listener, or a synchronously dispatched job')
        ->and($step)->toContain('the caller walk of `@rules/code-review/core-analysis.md` *Behaviour changed outside the diff*')
        ->and($step)->toContain('`Route::any`')
        ->and($step)->toContain('Critical, which is Critical on the scale of this skill, for a page that is a `GET` that mutates state under ')
        ->and($step)->toContain(
            '`@rules/api/general.md` *CR Severity Rules*, and Moderate, which is Medium on the scale of this skill, for any other page',
        )
        ->and($step)->toContain('gets one finding at the Critical severity, never a second finding at the Moderate severity')
        ->and($step)->toContain('`Plain page load: not applicable — the diff changes no page that a plain page load opens`')
        ->and($step)->not->toContain('never deferred')
        ->and($step)->not->toContain('anti-CSRF token');
});

test('the audit report template carries the plain page load block', function (): void {
    $template = packageSource('skills/security-review/templates/audit-report.md');

    expect(substr_count($template, '### Plain Page Load'))->toBe(1)
        ->and($template)->toContain('return from an external flow (one-time value `file:line`, check `file:line`)')
        ->and($template)->toContain('verdict: finding | not a finding (reason) | not verified (step that settles it)');
});

test('splinter and leonardo run the plain page load step in every round', function (): void {
    $splinter = packageSource('agents/splinter.md');
    $leonardo = packageSource('agents/leonardo.md');

    expect($splinter)->toContain('its *State Change on a Plain Page Load* step (also in every round)')
        ->and($leonardo)->toContain('whose *State Change on a Plain Page Load* step also runs in every round');
});
