<?php

declare(strict_types = 1);

use Tests\Support\GraphFixture;
use Tests\Support\HorizonAuditRun;

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
    expect($effective)->toContain('Merge the whole supervisor map.');
    expect($effective)->toContain('`array_replace_recursive($defaults, $environmentPlan)`');
    expect($effective)->toContain('A supervisor that only `defaults` defines therefore runs in every environment');
    expect($effective)->toContain('the first key of `environments`, in file order, for which `Str::is($key, $environment)` holds.');
    expect($effective)->toContain(
        'An exact key does not win over an earlier pattern: with `\'*\'` listed before `\'production\'`, Horizon deploys the `*` block in production',
    );
    expect($effective)->not->toContain('An exact key of `environments` wins.');
    expect($effective)->toContain('The audit still lists such a supervisor and never omits it.');
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
    expect($checks)->toContain('compare their effective `retry_after` values. A difference is a `FAIL`');
    expect($checks)->toContain('or when connections that share a physical queue with a fully known key differ in `retry_after`.');
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

test('no mode runs a command that shows a configuration or an environment value, even when it is approved', function (): void {
    $skill = packageSource('skills/laravel-horizon-audit/SKILL.md');

    expect($skill)->toContain('- **No command shows a configuration or environment value.** In every mode, and even when `allow_commands` names it');
    expect($skill)->toContain('`php artisan config:show`, `php artisan about`, `php artisan tinker`, a `config()` dump, `env`, or `printenv`');
    expect($skill)->toContain('`allow_commands` never lifts that exclusion.');
    expect($skill)->toContain('plus step 5 on `metrics_source` and the approved commands');
    expect($skill)->not->toContain('step 5a');
    expect(packageSource('skills/laravel-horizon-audit/references/effective-configuration.md'))
        ->toContain('never run a command that shows a configuration or environment value in any mode');
});

test('the rule sentences behind the judgement scenarios are pinned (T8, T9, T11)', function (): void {
    $checks = packageSource('skills/laravel-horizon-audit/references/checks.md');

    expect($checks)->toContain('With `balance` set to `auto`, the order of the queues does not set a strict priority.');
    expect($checks)->toContain('Report a queue list that expects order priority under `auto`.');
    expect($checks)->toContain('Report a key with no matching consumer (orphaned) and a consumed `connection:queue` with no key (missing).');
    expect($checks)->toContain('spends an attempt on each release. Report a `tries` value that is low for such a job and explain the effect.');
});

test('a retry_after that differs on one physical queue is a FAIL, and an unused backoff entry is not (T4, HZ-003)', function (): void {
    $checks = packageSource('skills/laravel-horizon-audit/references/checks.md');

    expect($checks)->toContain('A difference is a `FAIL`: the same queue then has two visibility windows.');
    expect($checks)->toContain('`FAIL` for a verified duplicate consumer on one physical queue with a different `retry_after`, which also feeds HZ-002.');
    expect($checks)->toContain('A `$backoff` list longer than `$tries` is not one');
    expect($checks)->toContain('so extra entries stay unused. Report them as `INFO`.');
    expect($checks)->not->toContain('longer than `$tries` allows to run, shown by the values');
});

/**
 * @return array<string, array{string}>
 */
function horizonAuditFixtureNames(): array
{
    $names = [];

    foreach (HorizonAuditRun::files('*/expected.json') as $file) {
        $names[basename(dirname($file))] = [basename(dirname($file))];
    }

    return $names;
}

function horizonAuditProjectWithHorizonConfig(string $fixture, string $config): string
{
    $root = GraphFixture::project('laravel-horizon-audit/' . $fixture);
    installerWriteFile($root . '/config/horizon.php', $config);

    return $root;
}

function horizonAuditScenarioRow(int $number): string
{
    foreach (explode("\n", packageSource('skills/laravel-horizon-audit/references/fixture-scenarios.md')) as $line) {
        if (str_starts_with($line, '| T' . $number . ' |')) {
            return $line;
        }
    }

    return '';
}

function expectOutcomeInReportSchema(string $token): void
{
    [$rule, $status] = array_pad(explode(':', $token, 2), 2, '');

    if ($status === '') {
        return;
    }

    expect(horizonAuditSchemaField('$defs.finding.properties.rule_id.enum'))->toContain($rule);
    expect(horizonAuditSchemaField('$defs.finding.properties.status.enum'))->toContain($status);
}

test('every scenario T1 to T16 ships a fixture whose expected result matches the scenario table and the report schema', function (): void {
    foreach (range(1, 16) as $number) {
        $fixture = sprintf('T%02d', $number);

        expect(HorizonAuditRun::expectedString($fixture, 'scenario'))->toBe('T' . $number);
        expect(file_exists(HorizonAuditRun::FIXTURES . $fixture . '/composer.json'))->toBeTrue();
        expect(horizonAuditScenarioRow($number))->not->toBe('');
        expect(HorizonAuditRun::expectedList($fixture, 'outcomes'))->not->toBe([]);
        expect(horizonAuditSchemaField('$defs.finding.properties.severity.enum'))->toContain(...HorizonAuditRun::expectedList($fixture, 'severity'));

        array_map(expectOutcomeInReportSchema(...), HorizonAuditRun::expectedList($fixture, 'outcomes'));
    }
});

test('the evaluator reproduces the expected outcomes and severity of fixture', function (string $fixture): void {
    $run = HorizonAuditRun::fixture($fixture);

    expect($run->exitCode)->toBe(0);
    expect($run->errorOutput)->toBe('');
    expect($run->outcomes())->toBe(HorizonAuditRun::expectedList($fixture, 'outcomes'));

    if ($run->outcomes() !== ['NOT_APPLICABLE']) {
        expect(HorizonAuditRun::expectedList($fixture, 'severity'))->toContain($run->topSeverity());
    }
})->with(horizonAuditFixtureNames());

test(
    'the evaluator never reports a security diagnosis for the dashboard and never a statement it cannot verify as PASS',
    function (string $fixture): void {
        $run = HorizonAuditRun::fixture($fixture);
    
        expect($run->outcomeOf('HZ-007') ?? 'HZ-007:UNVERIFIED')->toBe('HZ-007:UNVERIFIED');
        expect($run->outcomeOf('HZ-005'))->not->toBe('HZ-005:PASS');
    },
)->with(horizonAuditFixtureNames());

test('every judgement scenario names its undecided part, and the evaluator leaves it out of the outcomes', function (): void {
    foreach (['T3', 'T4', 'T8', 'T9', 'T11', 'T16'] as $scenario) {
        $fixture = sprintf('T%02d', (int) substr($scenario, 1));

        expect(HorizonAuditRun::expectedString($fixture, 'judgement'))->not->toBeNull();
    }

    expect(HorizonAuditRun::fixture('T11')->stringList('not_decided'))->toBe(['HZ-004', 'HZ-006']);
    expect(HorizonAuditRun::fixture('T08')->stringList('not_decided'))->toBe(['HZ-003', 'HZ-006']);
});

test('a project without Horizon changes nothing (T1)', function (): void {
    $before = HorizonAuditRun::digest('T01');

    $run = HorizonAuditRun::fixture('T01');

    expect($run->outcomes())->toBe(['NOT_APPLICABLE']);
    expect($run->stringList('files_read'))->toBe(['composer.json', 'composer.lock']);
    expect(HorizonAuditRun::digest('T01'))->toBe($before);
});

test('the physical key decides, not the connection name (T4, T5, T6)', function (): void {
    expect(HorizonAuditRun::fixture('T04')->consumerField('connection'))->toBe(['redis', 'redis-b']);
    expect(HorizonAuditRun::fixture('T04')->outcomeOf('HZ-001'))->toBe('HZ-001:WARN');
    expect(HorizonAuditRun::fixture('T05')->consumerField('database'))->toBe([0, 1]);
    expect(HorizonAuditRun::fixture('T05')->outcomeOf('HZ-001'))->toBe('HZ-001:PASS');
    expect(HorizonAuditRun::fixture('T06')->consumerField('database'))->toBe([null, null]);
    expect(HorizonAuditRun::fixture('T06')->outcomeOf('HZ-001'))->toBe('HZ-001:UNVERIFIED');
});

test('the merged row is audited and no single field of defaults decides (T7)', function (): void {
    $run = HorizonAuditRun::fixture('T07');

    expect($run->consumerField('timeout'))->toBe([150]);
    expect($run->string('environment_key'))->toBe('production');
    expect(HorizonAuditRun::fixture('T07', '--environment', 'staging')->consumerField('timeout'))->toBe([]);
    expect(HorizonAuditRun::fixture('T07', '--environment', 'staging')->outcomeOf('HZ-002'))->toBe('HZ-002:UNVERIFIED');
});

test('the list of queues is replaced index by index by the environment override (T8)', function (): void {
    expect(HorizonAuditRun::fixture('T08')->consumerField('queue'))->toBe(['high', 'default']);
});

test('a supervisor that only defaults defines runs in the environment, and maxProcesses 0 does not deploy one (E2)', function (): void {
    $run = HorizonAuditRun::fixture('E02');

    expect($run->consumerField('consumer'))->toBe(['horizon:supervisor-1', 'horizon:supervisor-mail']);
    expect($run->consumerField('timeout'))->toBe([90, 200]);
    expect($run->outcomeOf('HZ-002'))->toBe('HZ-002:FAIL');
});

test('Horizon takes the first environment key that matches, so a star block before the exact key is the one that runs (E1)', function (): void {
    $starFirst = HorizonAuditRun::fixture('E01');
    $local = HorizonAuditRun::fixture('E01', '--environment', 'local');

    expect($starFirst->string('environment_key'))->toBe('*');
    expect($starFirst->flag('wildcard_shadows_exact'))->toBeTrue();
    expect($starFirst->consumerField('timeout'))->toBe([150]);
    expect($starFirst->outcomeOf('HZ-002'))->toBe('HZ-002:FAIL');
    expect($local->string('environment_key'))->toBe('*');
    expect($local->flag('wildcard_shadows_exact'))->toBeFalse();
});

test('the exact key wins when it is listed before the star block, and the star block is the fallback', function (): void {
    $root = horizonAuditProjectWithHorizonConfig('E01', <<<'PHP'
        <?php

        return [
            'defaults' => [
                'supervisor-1' => ['connection' => 'redis', 'queue' => ['default'], 'timeout' => 60],
            ],
            'environments' => [
                'production' => [
                    'supervisor-1' => ['timeout' => 30],
                ],
                '*' => [
                    'supervisor-1' => ['timeout' => 150],
                ],
            ],
        ];
        PHP);

    try {
        $production = HorizonAuditRun::over($root);
        $local = HorizonAuditRun::over($root, '--environment', 'local');
    } finally {
        installerRemoveDirectory($root);
    }

    expect($production->string('environment_key'))->toBe('production');
    expect($production->consumerField('timeout'))->toBe([30]);
    expect($production->outcomeOf('HZ-002'))->toBe('HZ-002:PASS');
    expect($local->string('environment_key'))->toBe('*');
    expect($local->consumerField('timeout'))->toBe([150]);
});

test('a configuration that fails the audit passes it after the fix (T7)', function (): void {
    $root = horizonAuditProjectWithHorizonConfig(
        'T07',
        str_replace('150', '90', (string) file_get_contents(HorizonAuditRun::FIXTURES . 'T07/config/horizon.php.fixture')),
    );

    try {
        $fixed = HorizonAuditRun::over($root);
    } finally {
        installerRemoveDirectory($root);
    }

    expect(HorizonAuditRun::fixture('T07')->outcomeOf('HZ-002'))->toBe('HZ-002:FAIL');
    expect($fixed->outcomeOf('HZ-002'))->toBe('HZ-002:PASS');
});

test('a config file in the layout of a real application is read: comments, several lines, and an env call (E2)', function (): void {
    $source = packageSource('tests/Fixtures/laravel-horizon-audit/E02/config/horizon.php.fixture');

    expect($source)->toContain("use Illuminate\\Support\\Str;\n");
    expect($source)->toContain('// Every supervisor of defaults is deployed');
    expect($source)->toContain('\'prefix\' => env(\'HORIZON_PREFIX\'');
    expect(HorizonAuditRun::fixture('E02')->consumerField('consumer'))->toBe(['horizon:supervisor-1', 'horizon:supervisor-mail']);
});

test('the same fixture evaluated twice gives identical output (T14)', function (): void {
    expect(HorizonAuditRun::fixture('T14')->output)->toBe(HorizonAuditRun::fixture('T14')->output);
    expect(HorizonAuditRun::fixture('T14')->output)->not->toBe('');
});

test('a PHP config with a dynamic expression is never evaluated and gives UNVERIFIED (T15)', function (): void {
    $root = GraphFixture::project('laravel-horizon-audit/T15');
    $canary = $root . '/evaluated-canary';
    $config = (string) file_get_contents($root . '/config/horizon.php');
    installerWriteFile(
        $root . '/config/horizon.php',
        str_replace('\'balance\' => \'auto\',', '\'balance\' => \'auto\', \'memory\' => file_put_contents(\'' . $canary . '\', \'x\'),', $config),
    );

    try {
        $run = HorizonAuditRun::over($root);
        $canaryWritten = file_exists($canary);
    } finally {
        installerRemoveDirectory($root);
    }

    expect($canaryWritten)->toBeFalse();
    expect($run->outcomeOf('HZ-002'))->toBe('HZ-002:UNVERIFIED');
    expect(HorizonAuditRun::fixture('T15')->outcomeOf('HZ-002'))->toBe('HZ-002:UNVERIFIED');
});

test(
    'the evaluator makes no runtime access: it reads only the named project files and its source has no process, network, or include call (T12)',
    function (): void {
        $run = HorizonAuditRun::fixture('T12');
        $allowed = ['composer.json', 'composer.lock', 'config/database.php', 'config/horizon.php', 'config/queue.php'];
        $forbidden = [
            'exec', 'shell_exec', 'system', 'passthru', 'popen', 'proc_open', 'fsockopen',
            'stream_socket_client', 'curl_init', 'mail', 'unlink', 'file_put_contents', 'fopen',
        ];
        $used = [];
    
        foreach (PhpToken::tokenize(packageSource('skills/laravel-horizon-audit/scripts/evaluate.php')) as $token) {
            if (in_array($token->id, [T_INCLUDE, T_INCLUDE_ONCE, T_REQUIRE, T_REQUIRE_ONCE, T_EVAL], strict: true) || $token->text === '`'
                || ($token->id === T_STRING && in_array($token->text, $forbidden, strict: true))
            ) {
                $used[] = $token->text;
            }
        }
    
        expect($run->string('runtime_access'))->toBe('none');
        expect($run->stringList('files_read'))->toBe($allowed);
        expect($used)->toBe([]);
    },
);

test('an environment file is never read and its secret never reaches the output (T13)', function (): void {
    $secret = HorizonAuditRun::expectedString('T13', 'secret') ?? '-';
    $root = GraphFixture::project('laravel-horizon-audit/T13');
    installerWriteFile($root . '/.env', 'REDIS_PASSWORD=' . $secret . "\n");
    installerWriteFile($root . '/.env.production', 'REDIS_PASSWORD=' . $secret . "\n");

    try {
        $withEnvironmentFile = HorizonAuditRun::over($root);
    } finally {
        installerRemoveDirectory($root);
    }

    expect(HorizonAuditRun::fixture('T13')->output)->not->toContain($secret);
    expect($withEnvironmentFile->output)->not->toContain($secret);
    expect($withEnvironmentFile->stringList('files_read'))->toBe(
        ['composer.json', 'composer.lock', 'config/database.php', 'config/horizon.php', 'config/queue.php'],
    );
    expect(packageSource('tests/Fixtures/laravel-horizon-audit/T13/config/database.php.fixture'))->toContain($secret);
});

test('a process-manager file that runs a standalone worker keeps HZ-001 from passing, and the files are listed', function (): void {
    $root = GraphFixture::project('laravel-horizon-audit/T10');
    installerWriteFile(
        $root . '/deploy/supervisor.conf',
        "[program:horizon]\ncommand=php artisan horizon\nstopwaitsecs=3600\n[program:worker]\ncommand=php artisan queue:work redis\n",
    );

    try {
        $run = HorizonAuditRun::over($root);
    } finally {
        installerRemoveDirectory($root);
    }

    expect($run->outcomeOf('HZ-001'))->toBe('HZ-001:UNVERIFIED');
    expect($run->outcomeOf('HZ-005'))->toBeNull();
    expect($run->stringList('not_decided'))->toContain('HZ-005');
    expect($run->string('process_files.0.file'))->toBe('deploy/supervisor.conf');
    expect($run->stringList('process_files.0.tokens'))->toBe(['horizon', 'queue:work', 'stopwaitsecs']);
});

test('the evaluator refuses a missing project path and an unknown option with the usage text and exit 1', function (): void {
    $missing = HorizonAuditRun::over(HorizonAuditRun::FIXTURES . 'does-not-exist');
    $unknown = HorizonAuditRun::over(HorizonAuditRun::FIXTURES . 'T01', '--apply');

    expect($missing->exitCode)->toBe(1);
    expect($missing->errorOutput)->toContain('Usage: evaluate.php <project_path>');
    expect($missing->output)->toBe('');
    expect($unknown->exitCode)->toBe(1);
});

test('the skill runs the evaluator and states what it computes and what stays judgement', function (): void {
    $skill = packageSource('skills/laravel-horizon-audit/SKILL.md');

    expect($skill)->toContain('Run `php skills/laravel-horizon-audit/scripts/evaluate.php <project_path> --environment <environment>` first');
    expect($skill)->toContain('## The evaluator');
    expect($skill)->toContain('It reads each file as text, tokenizes a config file with `PhpToken::tokenize`, and never executes anything.');
    expect($skill)->toContain('refuses every file whose name starts with `.env`');
    expect($skill)->toContain('- **What stays the agent\'s judgement.**');
    expect($skill)->toContain('HZ-007: always `UNVERIFIED`');
    expect(file_exists(__DIR__ . '/../../skills/laravel-horizon-audit/scripts/evaluate.php'))->toBeTrue();
});

test('the secret of the environment fixture appears nowhere in the skill, its report template, or its schema (T13)', function (): void {
    $secret = HorizonAuditRun::expectedString('T13', 'secret') ?? '-';

    $files = [
        'SKILL.md', 'templates/report.md', 'schemas/report.schema.json',
        'references/checks.md', 'references/fixture-scenarios.md', 'scripts/evaluate.php',
    ];

    foreach ($files as $file) {
        expect(packageSource('skills/laravel-horizon-audit/' . $file))->not->toContain($secret);
    }
});

/**
 * @param array<string, string|null> $files
 */
function horizonAuditEvaluate(string $fixture, array $files, string ...$arguments): HorizonAuditRun
{
    $root = GraphFixture::project('laravel-horizon-audit/' . $fixture);

    foreach ($files as $path => $content) {
        $content === null ? installerRemoveDirectory($root . '/' . $path) : installerWriteFile($root . '/' . $path, $content);
    }

    try {
        return HorizonAuditRun::over($root, ...$arguments);
    } finally {
        installerRemoveDirectory($root);
    }
}

function horizonAuditLock(string $horizonVersion): string
{
    return '{"packages": [{"name": "laravel/framework", "version": "v12.0.0"}, {"name": "laravel/horizon", "version": "' . $horizonVersion . '"}]}';
}

function horizonAuditQueueConfig(string $secondConnection, int $secondRetryAfter): string
{
    return <<<PHP
        <?php

        return [
            'connections' => [
                'redis' => ['driver' => 'redis', 'connection' => 'default', 'queue' => 'default', 'retry_after' => 90],
                'redis-b' => ['driver' => 'redis', 'connection' => '{$secondConnection}', 'queue' => 'default', 'retry_after' => {$secondRetryAfter}],
            ],
        ];
        PHP;
}

test('a config that leaves the parser on one token ends at once and gives UNVERIFIED (M1)', function (string $config, string $rule): void {
    $run = horizonAuditEvaluate('T03', ['config/horizon.php' => $config]);

    expect($run->exitCode)->toBe(0);
    expect($run->errorOutput)->toBe('');
    expect($run->outcomeOf($rule))->toBe($rule . ':UNVERIFIED');
})->with([
    'a dollar-brace string as the timeout' => [<<<'PHP'
        <?php

        return [
            'defaults' => ['s' => ['connection' => 'redis', 'queue' => ['default'], 'timeout' => "x${y}"]],
            'environments' => ['production' => ['s' => []]],
        ];
        PHP, 'HZ-002',
    ],
    'a stray closing brace in a list' => [<<<'PHP'
        <?php

        return [1 => 2 }];
        PHP, 'HZ-001',
    ],
]);

test('a symlinked parent directory is never followed out of the project root (M2)', function (): void {
    $outside = GraphFixture::project('laravel-horizon-audit/T02');
    $root = GraphFixture::project('laravel-horizon-audit/T03');
    installerRemoveDirectory($root . '/config');
    installerRemoveDirectory($root . '/app');
    symlink($outside . '/config', $root . '/config');
    symlink($outside . '/app', $root . '/app');

    try {
        $run = HorizonAuditRun::over($root);
    } finally {
        installerRemoveDirectory($root);
        installerRemoveDirectory($outside);
    }

    expect($run->exitCode)->toBe(0);
    expect($run->stringList('files_read'))->toBe(['composer.json', 'composer.lock']);
    expect($run->consumerField('consumer'))->toBe([]);
    expect($run->outcomeOf('HZ-002'))->toBe('HZ-002:UNVERIFIED');
});

test('an absent Redis database is the server default 0 and the prefix is inherited from options.prefix (M7)', function (): void {
    $database = <<<'PHP'
        <?php

        return [
            'redis' => [
                'options' => ['prefix' => 'app_'],
                'default' => ['host' => '127.0.0.1', 'port' => 6379, 'database' => 0],
                'queue' => ['host' => '127.0.0.1', 'port' => 6379],
            ],
        ];
        PHP;

    $run = horizonAuditEvaluate('T04', ['config/database.php' => $database, 'config/queue.php' => horizonAuditQueueConfig('queue', 120)]);

    expect($run->consumerField('database'))->toBe([0, 0]);
    expect($run->consumerField('prefix'))->toBe(['app_', 'app_']);
    expect($run->outcomeOf('HZ-001'))->toBe('HZ-001:WARN');
    expect($run->outcomeOf('HZ-002'))->toBe('HZ-002:FAIL');
});

test('the prefix of the connection wins over the options of the connection and the global options (M7)', function (): void {
    $database = <<<'PHP'
        <?php

        return [
            'redis' => [
                'options' => ['prefix' => 'global_'],
                'default' => ['database' => 0, 'options' => ['prefix' => 'own_']],
                'queue' => ['database' => 0, 'prefix' => 'app_', 'options' => ['prefix' => 'own_']],
                'bare' => ['database' => 0],
            ],
        ];
        PHP;

    $run = horizonAuditEvaluate('T04', ['config/database.php' => $database, 'config/queue.php' => horizonAuditQueueConfig('queue', 120)]);
    $inherited = horizonAuditEvaluate('T04', ['config/database.php' => $database, 'config/queue.php' => horizonAuditQueueConfig('bare', 120)]);

    expect($run->consumerField('prefix'))->toBe(['own_', 'app_']);
    expect($run->outcomeOf('HZ-001'))->toBe('HZ-001:PASS');
    expect($inherited->consumerField('prefix'))->toBe(['own_', 'global_']);
});

test('an absent prefix at every level is no prefix, and an absent host or an environment-driven database is unknown (M7)', function (): void {
    $bare = '<?php return [\'redis\' => [\'default\' => [\'host\' => \'h\', \'port\' => 1], \'queue\' => [\'host\' => \'h\', \'port\' => 1]]];';
    $noHost = '<?php return [\'redis\' => [\'default\' => [], \'queue\' => []]];';
    $driven = '<?php return [\'redis\' => [\'default\' => [\'host\' => \'h\', \'port\' => 1, \'database\' => 0], '
        . '\'queue\' => [\'host\' => \'h\', \'port\' => 1, \'database\' => env(\'REDIS_QUEUE_DB\', 0)]]];';
    $queue = horizonAuditQueueConfig('queue', 120);

    $noPrefix = horizonAuditEvaluate('T04', ['config/database.php' => $bare, 'config/queue.php' => $queue]);
    $unknownHost = horizonAuditEvaluate('T04', ['config/database.php' => $noHost, 'config/queue.php' => $queue]);
    $unknown = horizonAuditEvaluate('T04', ['config/database.php' => $driven, 'config/queue.php' => $queue]);

    expect($noPrefix->consumerField('prefix'))->toBe(['', '']);
    expect($noPrefix->outcomeOf('HZ-001'))->toBe('HZ-001:WARN');
    expect($unknownHost->outcomeOf('HZ-001'))->toBe('HZ-001:UNVERIFIED');
    expect($unknown->consumerField('database'))->toBe([0, null]);
    expect($unknown->outcomeOf('HZ-001'))->toBe('HZ-001:UNVERIFIED');
    expect($unknown->outcomeOf('HZ-002'))->toBe('HZ-002:UNVERIFIED');
});

test('the Horizon 5.x model applies only to a detected 5.x version (M8)', function (?string $lock): void {
    $run = horizonAuditEvaluate('E02', ['composer.lock' => $lock]);

    expect($run->consumerField('consumer'))->toBe([]);
    expect($run->string('environment_key'))->toBeNull();
    expect($run->outcomeOf('HZ-001'))->toBe('HZ-001:UNVERIFIED');
    expect($run->outcomeOf('HZ-002'))->toBe('HZ-002:UNVERIFIED');
    expect($run->stringList('not_decided'))->toBe(['HZ-003', 'HZ-004', 'HZ-006']);
    expect($run->string('findings.0.reason'))->toContain('not 5.x or is not known from composer.lock');
})->with([
    'a development version' => [horizonAuditLock('dev-master')],
    'Horizon 4.x' => [horizonAuditLock('v4.3.5')],
    'no composer.lock' => [null],
]);

test('a waits map is not compared with consumers of a Horizon version the evaluator does not model (M8)', function (): void {
    $run = horizonAuditEvaluate('T09', ['composer.lock' => horizonAuditLock('v4.3.5')]);

    expect($run->outcomeOf('HZ-006'))->toBeNull();
    expect($run->stringList('not_decided'))->toContain('HZ-006');
});

test('minProcesses above maxProcesses is a HZ-004 FAIL (M10)', function (): void {
    $config = <<<'PHP'
        <?php

        return [
            'defaults' => [
                'supervisor-1' => ['connection' => 'redis', 'queue' => ['default'], 'minProcesses' => 5, 'maxProcesses' => 2, 'timeout' => 60],
            ],
            'environments' => ['production' => []],
        ];
        PHP;

    $run = horizonAuditEvaluate('T09', ['config/horizon.php' => $config]);

    expect($run->outcomeOf('HZ-004'))->toBe('HZ-004:FAIL');
    expect($run->topSeverity())->toBe('HIGH');
});

test('a middleware that releases the job gives HZ-003, and dontRelease() does not (M10)', function (string $middleware, ?string $expected): void {
    $job = <<<PHP
        <?php

        namespace App\Jobs;

        final class SyncOrders
        {
            public int \$tries = 3;

            public function middleware(): array
            {
                return [{$middleware}];
            }
        }
        PHP;

    $run = horizonAuditEvaluate('T11', ['app/Jobs/SyncOrders.php' => $job]);

    expect($run->outcomeOf('HZ-003'))->toBe($expected);
})->with([
    'RateLimited' => ['new RateLimited(\'api\')', 'HZ-003:WARN'],
    'RateLimitedWithRedis' => ['new RateLimitedWithRedis(\'api\')', 'HZ-003:WARN'],
    'WithoutOverlapping' => ['new WithoutOverlapping(\'orders\')', 'HZ-003:WARN'],
    'WithoutOverlapping with dontRelease' => ['(new WithoutOverlapping(\'orders\'))->dontRelease()', null],
]);

test('a driver other than redis never gets the retry_after rule of redis (M10)', function (string $driver): void {
    $queue = '<?php return [\'connections\' => [\'redis\' => [\'driver\' => \'' . $driver . '\', \'retry_after\' => 90]]];';

    $run = horizonAuditEvaluate('T03', ['config/queue.php' => $queue]);

    expect($run->outcomeOf('HZ-002'))->toBe('HZ-002:UNVERIFIED');
})->with(['sqs', 'database']);

test('a job timeout that is not a literal is UNVERIFIED and never PASS (M10)', function (): void {
    $job = "<?php\n\nnamespace App\\Jobs;\n\nfinal class SyncOrders\n{\n    public int \$timeout = self::LIMIT;\n}\n";

    $run = horizonAuditEvaluate('T03', ['app/Jobs/SyncOrders.php' => $job]);

    expect($run->outcomeOf('HZ-002'))->toBe('HZ-002:UNVERIFIED');
    expect(HorizonAuditRun::fixture('T03')->outcomeOf('HZ-002'))->toBe('HZ-002:PASS');
});

test('a waits key with a consumer of an unknown queue is UNVERIFIED, not WARN (M10)', function (): void {
    $config = <<<'PHP'
        <?php

        return [
            'defaults' => [
                'supervisor-1' => ['connection' => 'redis', 'queue' => [env('HORIZON_QUEUE', 'default')], 'timeout' => 60],
            ],
            'environments' => ['production' => []],
            'waits' => ['redis:reports' => 60],
        ];
        PHP;

    $run = horizonAuditEvaluate('T09', ['config/horizon.php' => $config]);

    expect($run->outcomeOf('HZ-006'))->toBe('HZ-006:UNVERIFIED');
});

test('the evaluator carries its records as typed classes and no mixed array shape (M9)', function (): void {
    $source = packageSource('skills/laravel-horizon-audit/scripts/evaluate.php');

    expect($source)->not->toContain('array<string, mixed>');

    foreach (['HzNode', 'HzConsumer', 'HzJob', 'HzFinding', 'HzEvaluation'] as $class) {
        expect($source)->toContain('final readonly class ' . $class);
    }
});
