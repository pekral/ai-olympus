<?php

declare(strict_types = 1);

test('one rule section owns the goal, the boundary, and the record-only path of an assignment', function (): void {
    $owner = (string) file_get_contents(dirname(__DIR__, 2) . '/rules/compound-engineering/general.md');

    expect(substr_count($owner, "\n## The assignment is the scope\n"))->toBe(1)
        ->and(substr_count($owner, 'The goal of every assignment is that every acceptance criterion is met.'))->toBe(1)
        ->and(substr_count($owner, '**Verify each criterion before you report the work done.**'))->toBe(1)
        ->and(substr_count($owner, 'The report names each criterion as met or unmet, and an unmet criterion is never reported as done.'))->toBe(1)
        ->and(substr_count($owner, '**Change nothing outside the assignment.**'))->toBe(1)
        ->and(substr_count($owner, '**The one exception is security.**'))->toBe(1)
        ->and(substr_count($owner, '`@skills/security-audit/SKILL.md` covers its whole requested scope by design.'))->toBe(1)
        ->and(substr_count($owner, '**Record everything else; implement none of it.**'))->toBe(1)
        ->and(substr_count($owner, '*File deferred points as follow-up tracker issues*, and in merge preparation an open decision'))->toBe(1)
        ->and(substr_count($owner, '*Scope — the assignment only*'))->toBe(1);
});

test('the principle is stated in the owner only and every other file references it', function (): void {
    $files = packageTextFiles();
    $statements = array_filter(
        $files,
        static fn (string $contents, string $path): bool => preg_match('#^(rules|skills|agents|commands)/#', $path) === 1
            && str_contains($contents, 'The goal of every assignment is that every acceptance criterion is met.'),
        ARRAY_FILTER_USE_BOTH,
    );

    expect(array_keys($statements))->toBe(['rules/compound-engineering/general.md']);

    $referencingFiles = [
        'rules/php/core-standards.md' => 1,
        'rules/refactoring/general.md' => 1,
        'skills/code-review/references/assignment-conformance-gate.md' => 1,
        'skills/create-missing-tests-in-pr/SKILL.md' => 1,
        'skills/create-test/SKILL.md' => 1,
        'skills/process-code-review/SKILL.md' => 1,
        'skills/resolve-issue/SKILL.md' => 2,
        'skills/resolve-issue/references/pre-existing-issue-handling.md' => 2,
        'skills/resolve-issue/references/pull-request.md' => 1,
        'skills/verify-merge-readiness/SKILL.md' => 1,
    ];

    foreach ($referencingFiles as $path => $count) {
        expect(substr_count($files[$path], '*The assignment is the scope*'))->toBe($count, $path);
    }
});

test('no skill mandates fixing a pre-existing bug, a rule violation, or a comment outside the change', function (): void {
    $files = packageTextFiles();

    $retiredMandates = [
        '`refactor(<scope>): pre-existing',
        'pre-existing — remove redundant comments',
        '- **Bugs** —',
        '- **Project-rule violations** —',
        '`fix(<scope>): pre-existing — …` (bug, security)',
        'for bugs and security',
    ];

    foreach ([
        'skills/resolve-issue/SKILL.md',
        'skills/resolve-issue/references/pre-existing-issue-handling.md',
        'skills/process-code-review/SKILL.md',
        'skills/create-test/SKILL.md',
        'skills/create-missing-tests-in-pr/SKILL.md',
    ] as $path) {
        foreach ($retiredMandates as $mandate) {
            expect($files[$path])->not->toContain($mandate);
        }
    }

    expect($files['skills/verify-merge-readiness/SKILL.md'])
        ->not->toContain('It also overrides `@skills/process-code-review/SKILL.md` *Pre-fix phase* rule 1');
    expect($files['skills/resolve-issue/SKILL.md'])
        ->not->toContain('**Fix a pre-existing issue you encountered in a file you had to read**');
    expect($files['rules/php/core-standards.md'])
        ->not->toContain('Remove every other comment in code you are changing.');
});

test('the one pre-existing fix that remains is a security vulnerability in its own commit', function (): void {
    $files = packageTextFiles();
    $reference = $files['skills/resolve-issue/references/pre-existing-issue-handling.md'];

    expect(substr_count($reference, '- **A security vulnerability is fixed in this PR.**'))->toBe(1)
        ->and(substr_count($reference, '- **Every other problem follows the owner.**'))->toBe(1)
        ->and(substr_count($reference, '`fix(<scope>): pre-existing — <description>`'))->toBe(1)
        ->and(substr_count($reference, '6. **A refactoring-only assignment fixes no vulnerability.**'))->toBe(1);

    foreach ([
        'skills/process-code-review/SKILL.md',
        'skills/create-test/SKILL.md',
        'skills/create-missing-tests-in-pr/SKILL.md',
    ] as $path) {
        expect(substr_count($files[$path], '@skills/resolve-issue/references/pre-existing-issue-handling.md'))->toBe(1, $path);
    }

    expect(substr_count($files['skills/resolve-issue/references/pull-request.md'], 'if any pre-existing security vulnerabilities were fixed'))->toBe(1)
        ->and(
            substr_count($files['skills/process-code-review/SKILL.md'], 'list every pre-existing security fix under a `## Pre-existing fixes` heading'),
        )->toBe(
            1,
        )
        ->and(substr_count($files['skills/process-code-review/SKILL.md'], 'only when **Pre-fix phase** landed a security fix'))->toBe(1);
});

test('the Assignment Conformance Gate flags a non-security change outside the assignment once', function (): void {
    $files = packageTextFiles();
    $gate = $files['skills/code-review/references/assignment-conformance-gate.md'];

    expect(substr_count($gate, '- **Security fix** — the smallest change that closes a vulnerability'))->toBe(1)
        ->and(substr_count($gate, 'a fix of a pre-existing bug or rule violation, a comment removed outside the lines the change writes'))->toBe(1)
        ->and(substr_count($gate, 'a fixup of code the change touches'))->toBe(0)
        ->and(
            substr_count($gate, 'the smallest change that closes a vulnerability a rule in `@rules/security/**` names, with its exploit path cited'),
        )->toBe(
            1,
        )
        ->and(substr_count($gate, 'a change on a security surface that closes no cited vulnerability, is **Out of scope**'))->toBe(1)
        ->and(substr_count($gate, 'the S1–S3 carve-out of'))->toBe(0);

    expect(substr_count($files['rules/compound-engineering/general.md'], 'Raise it there and nowhere else.'))->toBe(1)
        ->and(
            substr_count($files['rules/compound-engineering/general.md'], '`@skills/code-review/references/assignment-conformance-gate.md` step 2'),
        )->toBe(
            1,
        );

    $rules = array_filter(
        $files,
        static fn (string $contents, string $path): bool => str_starts_with($path, 'rules/code-review/') && str_contains(
            $contents,
            'The assignment is the scope',
        ),
        ARRAY_FILTER_USE_BOTH,
    );

    expect($rules)->toBe([]);
});

test('the filing path and the merge-preparation path keep their own decision on an out-of-scope point', function (): void {
    $files = packageTextFiles();

    expect(substr_count($files['skills/verify-merge-readiness/SKILL.md'], 'replaces the filing bar with open decisions'))->toBe(1)
        ->and(
            substr_count($files['rules/compound-engineering/tracker.md'], 'a pre-existing security vulnerability too large to fix inline moved to a TODO'),
        )->toBe(
            1,
        )
        ->and(substr_count($files['rules/compound-engineering/tracker.md'], 'a non-trivial pre-existing issue moved to a TODO'))->toBe(0);
});

test('the security exception is defined once, by a rule in the security rules and an exploit path', function (): void {
    $files = packageTextFiles();
    $owner = $files['rules/compound-engineering/general.md'];

    expect(substr_count($owner, 'A security vulnerability is a defect that a rule in `@rules/security/**` names and that has an exploit path.'))->toBe(1)
        ->and(substr_count($owner, 'A defect that only sits on a security surface is not one.'))->toBe(1)
        ->and(substr_count($owner, 'The fix is the smallest change that closes the vulnerability, in a file the change had to read.'))->toBe(1)
        ->and(substr_count($owner, 'meets the S1–S3 carve-out'))->toBe(0);

    $definitions = array_filter(
        $files,
        static fn (string $contents, string $path): bool => preg_match('#^(rules|skills|agents|commands)/#', $path) === 1
            && str_contains($contents, 'A security vulnerability is a defect that a rule in'),
        ARRAY_FILTER_USE_BOTH,
    );

    expect(array_keys($definitions))->toBe(['rules/compound-engineering/general.md']);

    $reference = $files['skills/resolve-issue/references/pre-existing-issue-handling.md'];

    expect(substr_count($reference, 'defines what counts as one and how small the fix is.'))->toBe(1)
        ->and(substr_count($reference, 'would flag: injection'))->toBe(0);
});

test('no skill restates what happens to a non-security observation, and no output reports one', function (): void {
    $files = packageTextFiles();

    $copies = [
        'skills/process-code-review/SKILL.md',
        'skills/create-test/SKILL.md',
        'skills/create-missing-tests-in-pr/SKILL.md',
        'skills/resolve-issue/SKILL.md',
        'skills/resolve-issue/references/pre-existing-issue-handling.md',
    ];

    foreach ($copies as $path) {
        expect(substr_count($files[$path], 'Every other pre-existing problem'))->toBe(0, $path)
            ->and(substr_count($files[$path], 'out-of-scope observation'))->toBe(0, $path);
    }

    foreach (['skills/create-test/SKILL.md', 'skills/create-missing-tests-in-pr/SKILL.md'] as $path) {
        expect(substr_count($files[$path], 'plus the URL of any follow-up issue filed for a point outside the assignment'))->toBe(1, $path);
    }
});

test('a future feature is deferred only when the assignment itself marks it as later work', function (): void {
    $resolveIssue = packageTextFiles()['skills/resolve-issue/SKILL.md'];

    expect(substr_count($resolveIssue, 'refactors, and a future feature the assignment itself marks as later work)'))->toBe(1)
        ->and(substr_count($resolveIssue, 'future features the assignment names'))->toBe(0);
});
