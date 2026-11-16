<?php

declare(strict_types = 1);

function routeAccessSource(string $relativePath): string
{
    return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
}

function routeAccessSection(string $text, string $startHeading, string $endHeading): string
{
    $start = (int) strpos($text, $startHeading);

    return substr($text, $start, (int) strpos($text, $endHeading, $start) - $start);
}

test('the security review has a route-access step that runs in every review round for changed routes', function (): void {
    $skill = routeAccessSource('skills/security-review/SKILL.md');
    $step = routeAccessSection($skill, '### Route Access of Changed Routes (issue #586)', '### Session & Token Management');

    expect(substr_count($skill, '### Route Access of Changed Routes (issue #586)'))->toBe(1)
        ->and($step)->toContain('This step runs in **every review round**')
        ->and($step)->toContain(
            'each route the diff adds or changes, and each route whose middleware, route group, controller, or policy the diff changes',
        )
        ->and($step)->toContain('an unauthenticated caller, a signed-in user, or a privileged role')
        ->and($step)->toContain('the authorization in the controller or policy, each with its `file:line`')
        ->and($step)->toContain('`Route access: not applicable — the diff changes no route`')
        ->and($step)->toContain('goes under *Not verified* with the step that settles it');
});

test('the audit report template carries the route access block', function (): void {
    $template = routeAccessSource('skills/security-review/templates/audit-report.md');

    expect(substr_count($template, '### Route Access'))->toBe(1)
        ->and($template)->toContain('reached by: unauthenticated caller | signed-in user | privileged role')
        ->and($template)->toContain('public by design (reason)');
});

test('the security rule owns the Critical severity of an internal route reachable without signing in', function (): void {
    $rule = routeAccessSource('rules/security/backend.md');
    $section = routeAccessSection($rule, '## Route Access (issue #586)', '## External Requests');

    expect(substr_count($rule, '## Route Access (issue #586)'))->toBe(1)
        ->and($section)->toContain(
            'An internal route is an admin, back-office, management, debug, or internal-API route that is not meant for anonymous visitors.',
        )
        ->and($section)->toContain('**Critical** when an internal route is reachable without signing in, from a browser or through the REST API.')
        ->and($section)->toContain('The finding names the route, the HTTP method, and the missing control.')
        ->and($section)->toContain('The review reports it under *Not verified* with the step that settles it');
});

test('the public-by-design cases are reused from the authorization review and never restated', function (): void {
    $reference = '`@skills/laravel-authorization-review/references/public-by-design.md`';
    $rule = routeAccessSource('rules/security/backend.md');
    $section = routeAccessSection($rule, '## Route Access (issue #586)', '## External Requests');
    $step = routeAccessSection(
        routeAccessSource('skills/security-review/SKILL.md'),
        '### Route Access of Changed Routes (issue #586)',
        '### Session & Token Management',
    );

    expect($section)->toContain($reference . ' owns the list of those cases')
        ->and($step)->toContain($reference)
        ->and($section)->not->toContain('webhook')
        ->and($section)->not->toContain('health')
        ->and($section)->not->toContain('login')
        ->and($step)->not->toContain('webhook')
        ->and($step)->not->toContain('health')
        ->and($step)->not->toContain('login');
});

test('the step restates no rule of the security rule it applies', function (): void {
    $step = routeAccessSection(
        routeAccessSource('skills/security-review/SKILL.md'),
        '### Route Access of Changed Routes (issue #586)',
        '### Session & Token Management',
    );

    expect($step)->toContain('`@rules/security/backend.md` *Route Access (issue #586)* owns the rule')
        ->and($step)->toContain('A route that is public by design is named with its reason from')
        ->and($step)->not->toContain('back-office');
});

test('splinter and leonardo run the route-access step on Claude Code and on Codex', function (): void {
    $splinter = routeAccessSource('agents/splinter.md');
    $leonardo = routeAccessSource('agents/leonardo.md');

    expect($splinter)->toContain('its *Route Access of Changed Routes* step, which runs in every round')
        ->and($leonardo)->toContain('(with its *Route Access of Changed Routes* step, in every round)');

    foreach (['splinter', 'leonardo'] as $role) {
        $toml = routeAccessSource('codex/agents/' . $role . '.toml');

        expect($toml)->toContain('First read `.codex/agent-instructions/' . $role . '.md` in full and follow it as your authoritative role definition.');
    }
});

test('the changelog records the route-access step', function (): void {
    $changelog = routeAccessSource('CHANGELOG.md');
    $unreleased = routeAccessSection($changelog, '## [Unreleased]', '## [0.5.1]');

    expect($unreleased)->toContain('(#586)')
        ->and($unreleased)->toContain('Route Access of Changed Routes');
});
