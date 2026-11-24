<?php

declare(strict_types = 1);

// Issue #668 — the skill is a read-only code-review lens that only `leonardo` runs, so these tests
// pin the text of the skill, the checks, and the wiring into the review. The behaviour itself is
// the judgment of the reviewing agent over a diff, and no script runs it.

test('php-memory-leak-audit ships as a skill with its catalog row and the counted totals (issue #668)', function (): void {
    $skill = packageSource('skills/php-memory-leak-audit/SKILL.md');
    $catalog = packageSource('docs/skills.md');
    $infrastructure = packageSection($catalog, "\n## Infrastructure & performance\n", "\n## Refactoring & code quality\n");
    $skillCount = iterator_count(new GlobIterator(dirname(__DIR__, 2) . '/skills/*/SKILL.md'));

    expect($skill)->toContain("name: php-memory-leak-audit\n")
        ->and($skill)->toContain("license: MIT\n")
        ->and($skill)->toContain('author: "Petr Král (pekral.cz)"')
        ->and($catalog)->toContain('| [`php-memory-leak-audit`](../skills/php-memory-leak-audit/) |')
        ->and($catalog)->toContain('| [Infrastructure & performance](#infrastructure--performance) | '
            . preg_match_all('/^\| \[`/m', $infrastructure) . ' |')
        ->and($catalog)->toContain('All ' . $skillCount . ' skills this package ships')
        ->and(packageSource('README.md'))->toContain($skillCount . ' reusable skills');
});

test('the lens reports every finding as Moderate, as a risk or a confirmed leak, with no confidence level (issue #668)', function (): void {
    $skill = packageSource('skills/php-memory-leak-audit/SKILL.md');

    expect($skill)
        ->toContain('- **Every finding is Moderate.** No finding of this skill is Critical, and none is below Moderate.')
        ->and($skill)->toContain('| Risk | The diff shows a path by which memory outlives a job. No measurement shows growth. | `logic-trace` |')
        ->and($skill)->toContain(
            '| Confirmed leak | A measurement shows memory growing across jobs of one worker for the same path. | `runtime-failure` |',
        )
        ->and($skill)->toContain('A risk never reads as a leak.')
        ->and($skill)->toContain('Never report ordinary allocation as a leak')
        ->and($skill)->toContain('When the code leaves the class unclear, recommend this measurement')
        ->and($skill)->not->toContain('Confidence:')
        ->and($skill)->not->toContain('HIGH');
});

test('the lens judges only code that an asynchronous entry point reaches (issue #668)', function (): void {
    $skill = packageSource('skills/php-memory-leak-audit/SKILL.md');
    $checks = packageSource('skills/php-memory-leak-audit/references/checks.md');

    expect($skill)
        ->toContain('Out of scope: an HTTP request, a controller, middleware, a synchronous service, and a one-off CLI command.')
        ->and($skill)->toContain('A shared service is in scope only when an asynchronous entry point reaches it')
        ->and($skill)->toContain('**Only `leonardo` runs this skill, and only inside its code review**')
        ->and($skill)->toContain('No path leaves the line out of scope: report nothing for it.')
        ->and(packageSection($checks, "\n## Entry points\n", "\n## Worker lifecycle\n"))->toContain('A class that implements `ShouldQueue`');

    foreach (['ML-1 — Unbounded static state and singletons', 'ML-2 — Reference cycles and object retention',
        'ML-3 — Repeated listener registration', 'ML-4 — Active query log', 'ML-5 — Worker recycle bounds',
    ] as $check) {
        $section = packageSection($checks, '## ' . $check, "\n## ");

        expect($section)->toContain('**Hit:**')->and($section)->toContain('**Fix:**')->and($section)->toContain('**Not a finding:**');
    }
});

test('a diff only reaches the lens through a trigger that stays silent on a synchronous diff (issue #668)', function (): void {
    $reference = packageSource('skills/code-review/references/specialized-reviews.md');
    $conditional = explode('- Run conditionally:', explode("\n## Skills deliberately not run\n", $reference)[0])[1] ?? '';
    $alwaysRun = explode('- Run conditionally:', $reference)[0];

    expect($conditional)->toContain('**Asynchronous process code detected in the diff → the memory lens runs.**')
        ->and($conditional)->toContain('**a diff matching none of those patterns runs no memory lens at all**')
        ->and($conditional)->toContain('`@skills/php-memory-leak-audit/SKILL.md` with `MODE=cr`')
        ->and($conditional)->toContain('**No path to an entry point → the line is out of scope, so the lens reports nothing for it.**')
        ->and($conditional)->toContain('**The memory lens carries no severity scale of its own.** Every finding is a **Moderate** finding')
        ->and($alwaysRun)->not->toContain('@skills/php-memory-leak-audit/SKILL.md')
        ->and(packageSource('skills/code-review/SKILL.md'))->toContain('the memory lens `php-memory-leak-audit` with `MODE=cr`')
        ->and(packageSource('agents/leonardo.md'))->toContain(
            'the memory lens `php-memory-leak-audit` with `MODE=cr` when the diff changes asynchronous process code',
        );
});

test('the lens divides the dimensions of a job with the bulk-data walk and the latency lens (issue #668)', function (): void {
    $gating = packageSection(
        packageSource('skills/code-review/references/specialized-reviews.md'),
        '**Gating — one finding per violation, never two.** Three owners read lines near these.',
        "\n    - **Public crawl or indexing surface",
    );

    expect($gating)->toContain('**owns how much one job loads and releases when it ends**')
        ->and($gating)->toContain('the memory lens owns the memory dimension of `@rules/laravel/laravel.md` *Long-Running Runtime Safety***')
        ->and($gating)->toContain('That finding is Moderate, and the rule walk raises none on the same line.')
        ->and($gating)->toContain('a security finding is never lowered or moved to this lens')
        ->and($gating)->toContain('**The memory lens owns what survives the job**')
        ->and($gating)->toContain('A `Model::all()` inside `handle()` carries the volume finding from the walk')
        ->and($gating)->toContain('the `laravel-horizon-audit` skill audits the configuration of the whole repository, which a review never runs.');
});

test('the lens output carries the fields of the review template and defines none of its own (issue #668)', function (): void {
    $skill = packageSource('skills/php-memory-leak-audit/SKILL.md');
    $output = packageSection($skill, "\n## Output\n", "\n## Done when\n");
    $template = packageSource('skills/code-review/templates/review-output.md');

    foreach (['Location', 'Rule', 'Impact', 'Evidence'] as $field) {
        expect($template)->toContain('- **' . $field . ':**')
            ->and($output)->toContain('- **' . $field . ':**');
    }

    expect($output)->toContain('It opens with the class, `Risk:` or `Confirmed leak:`')
        ->and($output)->toContain('- **Evidence:** `logic-trace` for a risk, `runtime-failure` for a confirmed leak')
        ->and($output)->toContain('The lens defines no field of its own: every field is a field of the template.')
        ->and($output)->not->toContain('**Context:**')
        ->and($output)->not->toContain('**Cause:**')
        ->and($output)->not->toContain('Evidence status')
        ->and($skill)->not->toContain('`static analysis only`')
        ->and($skill)->not->toContain('`confirmed by measurement`')
        ->and(packageSource('skills/code-review/references/specialized-reviews.md'))
        ->toContain('opens its `Impact` with `Risk:`, with `Evidence: logic-trace`');
});
