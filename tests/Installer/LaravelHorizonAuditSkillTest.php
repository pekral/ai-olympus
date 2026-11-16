<?php

declare(strict_types = 1);

function horizonAuditSchemaField(string $path): mixed
{
    return decodedJsonField(packageSource('skills/laravel-horizon-audit/schemas/report.schema.json'), $path);
}

test('laravel-horizon-audit ships as a layout A skill that other skills can invoke by name', function (): void {
    $skill = packageSource('skills/laravel-horizon-audit/SKILL.md');

    expect($skill)->toContain("name: laravel-horizon-audit\n");
    expect($skill)->toContain('description: "Use when auditing the Laravel Horizon, Redis queue, job, process-manager, and monitoring configuration');
    expect($skill)->toContain("license: MIT\n");
    expect($skill)->toContain('author: "Petr Král (pekral.cz)"');
    expect(packageSource('docs/skills.md'))->toContain('| [`laravel-horizon-audit`](../skills/laravel-horizon-audit/) |');
});

test('laravel-horizon-audit is classified as a skill the code review does not run', function (): void {
    $section = explode('## Skills deliberately not run', packageSource('skills/code-review/references/specialized-reviews.md'))[1] ?? '';

    expect(deliberatelyNotRunGroups($section)[3] ?? [])->toContain('laravel-horizon-audit');
});

test('the first run is a read-only static audit that touches no system and no environment value', function (): void {
    $skill = packageSource('skills/laravel-horizon-audit/SKILL.md');

    expect($skill)->toContain('| `mode` | `static` |');
    expect($skill)->toContain('- **Read-only by default.** The audit reads tracked files of the project and writes nothing.');
    expect($skill)->toContain('- **Static by default.** The audit never connects to Redis or a queue, never runs `php artisan`');
    expect($skill)->toContain('never `include`s, `require`s, or executes a PHP config file of the audited repository');
    expect($skill)->toContain('Never read any other `.env*` file.');
    expect($skill)->toContain('A mutating command (`horizon:terminate`, `queue:restart`, `queue:clear`, `queue:flush`, a Redis `FLUSH*`');
    expect($skill)->toContain('A target that is not shown to be one counts as production, and the runtime step stops `Blocked`.');
    expect($skill)->toContain('`apply_changes` only adds reviewable diff sketches to the report');
    expect($skill)->toContain('| `allow_commands` | `false` |');
});

test('a project without Horizon gets NOT_APPLICABLE and nothing is installed or changed (T1)', function (): void {
    $skill = packageSource('skills/laravel-horizon-audit/SKILL.md');

    expect($skill)->toContain('When `laravel/horizon` is absent from both files, return `NOT_APPLICABLE` with that evidence and stop. Change nothing.');
    expect($skill)->toContain('the audit installs nothing.');
});

test('the audit states each of the seven rules and runs them over the merged supervisor configuration', function (): void {
    $skill = packageSource('skills/laravel-horizon-audit/SKILL.md');
    $checks = packageSource('skills/laravel-horizon-audit/references/checks.md');
    $effective = packageSource('skills/laravel-horizon-audit/references/effective-configuration.md');

    foreach (['HZ-001', 'HZ-002', 'HZ-003', 'HZ-004', 'HZ-005', 'HZ-006', 'HZ-007'] as $rule) {
        expect($skill)->toContain('| ' . $rule . ' |');
        expect($checks)->toContain('## ' . $rule . ' — ');
    }

    expect($skill)->toContain('The step is done when the report holds one result for each of the seven rules.');
    expect($effective)->toContain('The audit of a supervisor uses the merged row.');
    expect($effective)->toContain('merge it over the `defaults` entry of the same name. The environment value wins.');
    expect($checks)->toContain('Interpret each setting by the detected `laravel/framework` and `laravel/horizon` versions.');
});

test('the physical queue is compared by its full key and an unknown part never confirms a collision (T4, T5, T6)', function (): void {
    $checks = packageSource('skills/laravel-horizon-audit/references/checks.md');

    expect($checks)->toContain('Two Laravel connection names never decide this by themselves');
    expect($checks)->toContain('equal queue names in different Redis databases or prefixes do not collide.');
    expect($checks)->toContain('Report a **potential collision** for that group, never a confirmed one.');
    expect($checks)->toContain('`FAIL` is never produced from an unknown part.');
    expect(packageSource('skills/laravel-horizon-audit/references/effective-configuration.md'))
        ->toContain('Different Laravel connection names are not different physical queues.');
});

test('the timeout order is checked without a universal number and with the semantics of the driver (T2, T3)', function (): void {
    $checks = packageSource('skills/laravel-horizon-audit/references/checks.md');

    expect($checks)->toContain('require `job_timeout < supervisor timeout < connection retry_after`');
    expect($checks)->toContain('Never print 600, 1800, or 3600 seconds as a recommended value.');
    expect($checks)->toContain('compare their effective `retry_after` values and report a difference.');
    expect($checks)->toContain('`sqs` uses the visibility timeout of the queue, set in SQS.');
    expect($checks)->toContain('recovery takes that long.');
});

test('no worker count is recommended without measured metrics', function (): void {
    $skill = packageSource('skills/laravel-horizon-audit/SKILL.md');

    expect($skill)->toContain('- **Never recommend a worker count without a measurement.**');
    expect($skill)->toContain('Without them the report names the missing metrics and states no target.');
    expect(packageSource('skills/laravel-horizon-audit/references/checks.md'))
        ->toContain('Never recommend a target process count without CPU, memory, and queue metrics.');
});

test('a value the audit cannot read is UNVERIFIED and is never promoted to PASS (T6, T10, T15, T16)', function (): void {
    $skill = packageSource('skills/laravel-horizon-audit/SKILL.md');
    $checks = packageSource('skills/laravel-horizon-audit/references/checks.md');
    $effective = packageSource('skills/laravel-horizon-audit/references/effective-configuration.md');

    expect($skill)->toContain('An `UNVERIFIED` result is never promoted to `PASS`.');
    expect($skill)->toContain('Otherwise any `UNVERIFIED` gives `UNVERIFIED`.');
    expect($checks)->toContain('When no process-manager configuration is tracked, return `UNVERIFIED` and not `FAIL`.');
    expect($checks)->toContain('return `UNVERIFIED`, and never a security diagnosis.');
    expect($effective)->toContain('A value inside `env(...)` is **unknown**');
    expect($effective)->toContain('Never evaluate it.');
});

test('a finding carries an evidence basis instead of a confidence level', function (): void {
    $skill = packageSource('skills/laravel-horizon-audit/SKILL.md');
    $template = packageSource('skills/laravel-horizon-audit/templates/report.md');
    $required = horizonAuditSchemaField('$defs.finding.required');

    expect($skill)->toContain('**Evidence basis** replaces a confidence level.');
    expect($template)->toContain('- **Evidence basis:** {{read|computed|not_verified}}');
    expect($template)->not->toContain('Confidence');
    expect($required)->toContain('evidence_basis');
    expect($required)->not->toContain('confidence');
});

test('the report is deterministic, redacted, and available as validated JSON (T13, T14)', function (): void {
    $skill = packageSource('skills/laravel-horizon-audit/SKILL.md');

    expect($skill)->toContain('Order is deterministic: severity (`CRITICAL`, `HIGH`, `MEDIUM`, `LOW`, `INFO`), then rule id, then file path, then line.');
    expect($skill)->toContain('The same input gives the same findings in the same order, and the same JSON key order.');
    expect($skill)->toContain('No `.env` value, token, password, payload, or personal datum appears.');
    expect(horizonAuditSchemaField('required'))->toContain('schema_version');
    expect(horizonAuditSchemaField('properties.schema_version.const'))->toBe('1.0');
    expect(horizonAuditSchemaField('properties.overall_result.enum'))->toBe(['PASS', 'WARN', 'FAIL', 'NOT_APPLICABLE', 'UNVERIFIED']);
    expect(horizonAuditSchemaField('$defs.finding.properties.rule_id.enum'))
        ->toBe(['HZ-001', 'HZ-002', 'HZ-003', 'HZ-004', 'HZ-005', 'HZ-006', 'HZ-007']);
    expect(horizonAuditSchemaField('$defs.finding.properties.severity.enum'))->toBe(['CRITICAL', 'HIGH', 'MEDIUM', 'LOW', 'INFO']);
    expect(horizonAuditSchemaField('$defs.finding.properties.status.enum'))->toBe(['PASS', 'FAIL', 'WARN', 'UNVERIFIED', 'NOT_APPLICABLE']);
    expect(horizonAuditSchemaField('$defs.finding.required'))->toBe([
        'rule_id', 'title', 'severity', 'status', 'evidence_basis', 'evidence', 'impact', 'recommendation', 'verification',
    ]);
    expect(horizonAuditSchemaField('additionalProperties'))->toBeFalse();
});

test('the report is written in the language of the request', function (): void {
    expect(packageSource('skills/laravel-horizon-audit/SKILL.md'))
        ->toContain('write the report in the language of the request. A Czech request gets a Czech report.');
});

test('regression tests are proposed in the report and never added to the project', function (): void {
    $skill = packageSource('skills/laravel-horizon-audit/SKILL.md');
    $tests = packageSource('skills/laravel-horizon-audit/references/regression-tests.md');

    expect($skill)->toContain('Show them in the report. Never add a file to the project.');
    expect($tests)->toContain('The skill shows these sketches in the report. It never adds a file to the project.');
    expect($tests)->toContain('Tag every sketch with the Pest group `horizon-config`');
    expect($tests)->toContain('every Horizon supervisor timeout stays below the retry_after of its connection');
});

test('the sixteen fixture scenarios are listed with their expected result', function (): void {
    $scenarios = packageSource('skills/laravel-horizon-audit/references/fixture-scenarios.md');

    foreach (range(1, 16) as $number) {
        expect($scenarios)->toContain('| T' . $number . ' |');
    }

    expect($scenarios)->toContain('| T1 | No `laravel/horizon` in `composer.json` and `composer.lock` | `NOT_APPLICABLE`; no file changes |');
    expect($scenarios)->toContain('| T10 | Deployment with no process-manager file | HZ-005 `UNVERIFIED`, not `FAIL` |');
});

test('the skill documents its limits and the reading of each status', function (): void {
    $skill = packageSource('skills/laravel-horizon-audit/SKILL.md');

    expect($skill)->toContain('## Limits of a static audit');
    expect($skill)->toContain('| `UNVERIFIED` | The deciding value or file is not available statically | Never read as `PASS`');
    expect($skill)->toContain('Example requests:');
    expect($skill)->toContain('The audit cannot prove which consumers run in production.');
});
