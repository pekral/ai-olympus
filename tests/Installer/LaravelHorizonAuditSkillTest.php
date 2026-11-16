<?php

declare(strict_types = 1);

use Tests\Support\HorizonAuditConsumer;

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
    expect($effective)->toContain('`*` is the fallback environment.');
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
 * One data row of a config fixture, keyed by its name: `'name' => [options],` on one line.
 *
 * @return array<string, array<string, string>>
 */
function horizonAuditRows(string $section): array
{
    preg_match_all("/^\s*'([\w*-]+)' => \[(.*)\],$/m", $section, $matches, PREG_SET_ORDER);

    $rows = [];

    foreach ($matches as $match) {
        preg_match_all("/'(\w+)' => (\[[^\]]*\]|(?:[^,(]|\([^)]*\))+)/", $match[2], $options, PREG_SET_ORDER);

        foreach ($options as $option) {
            $rows[$match[1]][$option[1]] = trim($option[2]);
        }
    }

    return $rows;
}

/**
 * The merged supervisor map of one environment, the way Horizon 5.x builds it: every supervisor of
 * `defaults` and of the environment, the environment value winning. An exact environment key wins,
 * and `*` is the fallback.
 *
 * @return array<string, array<string, string>>
 */
function horizonAuditSupervisors(string $config, string $environment): array
{
    [$defaults, $environments] = explode('\'environments\' => [', $config, 2) + ['', ''];
    $plan = [];

    foreach ([$environment, '*'] as $key) {
        if (preg_match('/^ {8}\'' . preg_quote($key, '/') . '\' => \[\n(.*?)^ {8}\],/ms', $environments, $block) === 1) {
            $plan = horizonAuditRows($block[1]);

            break;
        }
    }

    $merged = horizonAuditRows($defaults);

    foreach ($plan as $name => $options) {
        $merged[$name] = array_replace($merged[$name] ?? [], $options);
    }

    return $merged;
}

function horizonAuditFile(string $fixture, string $path): string
{
    $file = __DIR__ . '/../Fixtures/laravel-horizon-audit/' . $fixture . '/' . $path;

    return is_file($file) ? (string) file_get_contents($file) : '';
}

function horizonAuditLiteral(?string $raw): ?string
{
    if ($raw === null || preg_match("/^(?:\d+|'[^']*')$/", $raw) !== 1) {
        return null;
    }

    return trim($raw, '\'');
}

/**
 * The consumers of the audited environment, one per supervisor queue.
 *
 * @return list<\Tests\Support\HorizonAuditConsumer>
 */
function horizonAuditConsumers(string $fixture): array
{
    $connections = horizonAuditRows(horizonAuditFile($fixture, 'config/queue.php.fixture'));
    $redis = horizonAuditRows(horizonAuditFile($fixture, 'config/database.php.fixture'));
    $consumers = [];

    foreach (horizonAuditSupervisors(horizonAuditFile($fixture, 'config/horizon.php.fixture'), 'production') as $options) {
        $connection = $connections[horizonAuditLiteral($options['connection'] ?? null) ?? ''] ?? [];
        $redisRow = $redis[horizonAuditLiteral($connection['connection'] ?? null) ?? ''] ?? [];

        preg_match_all('/\'([^\']+)\'/', $options['queue'] ?? '', $queues);

        foreach ($queues[1] as $queue) {
            $consumers[] = new HorizonAuditConsumer(
                [
                    horizonAuditLiteral($connection['driver'] ?? null), horizonAuditLiteral($connection['connection'] ?? null),
                    horizonAuditLiteral($redisRow['database'] ?? null), horizonAuditLiteral($redisRow['prefix'] ?? null), $queue,
                ],
                horizonAuditLiteral($connection['retry_after'] ?? null),
                horizonAuditLiteral($options['timeout'] ?? null),
            );
        }
    }

    return $consumers;
}

/**
 * The `public int $timeout` of every job class of the fixture, null for a job that declares none.
 *
 * @return list<int|null>
 */
function horizonAuditJobTimeouts(string $fixture): array
{
    $jobFiles = glob(__DIR__ . '/../Fixtures/laravel-horizon-audit/' . $fixture . '/app/Jobs/*');
    $timeouts = [];

    foreach ($jobFiles === false ? [] : $jobFiles as $jobFile) {
        $timeouts[] = preg_match('/public int \$timeout = (\d+);/', (string) file_get_contents($jobFile), $found) === 1 ? (int) $found[1] : null;
    }

    return $timeouts;
}

/**
 * The timeout order of HZ-002 for one consumer: `job < supervisor timeout < retry_after`.
 *
 * @param list<int|null> $jobTimeouts
 */
function horizonAuditOrder(HorizonAuditConsumer $consumer, array $jobTimeouts): string
{
    if ($consumer->timeout === null || $consumer->retryAfter === null || in_array(needle: null, haystack: $jobTimeouts, strict: true)) {
        return 'UNVERIFIED';
    }

    $broken = array_filter($jobTimeouts, static fn (?int $job): bool => $job >= (int) $consumer->timeout) !== [];

    return $broken || (int) $consumer->timeout >= (int) $consumer->retryAfter ? 'FAIL' : 'PASS';
}

/**
 * Whether the test holds for any two consumers of the list.
 *
 * @param list<\Tests\Support\HorizonAuditConsumer> $consumers
 * @param callable(\Tests\Support\HorizonAuditConsumer, \Tests\Support\HorizonAuditConsumer): bool $test
 */
function horizonAuditAnyPair(array $consumers, callable $test): bool
{
    foreach ($consumers as $index => $consumer) {
        foreach (array_slice($consumers, $index + 1) as $other) {
            if ($test($consumer, $other)) {
                return true;
            }
        }
    }

    return false;
}

/**
 * The reference evaluation of the deterministic part of HZ-001 and HZ-002 over one fixture, as
 * `HZ-00N:STATUS` tokens. It reads regular literal config text and never executes it.
 *
 * @return list<string>
 */
function horizonAuditOutcomes(string $fixture): array
{
    if (!str_contains(horizonAuditFile($fixture, 'composer.json') . horizonAuditFile($fixture, 'composer.lock.fixture'), 'laravel/horizon')) {
        return ['NOT_APPLICABLE'];
    }

    $consumers = horizonAuditConsumers($fixture);
    $jobTimeouts = horizonAuditJobTimeouts($fixture);
    $orders = array_map(static fn (HorizonAuditConsumer $consumer): string => horizonAuditOrder($consumer, $jobTimeouts), $consumers);
    $sharing = match (true) {
        horizonAuditAnyPair($consumers, static fn (HorizonAuditConsumer $a, HorizonAuditConsumer $b): bool => $a->hasUnknownKeyPart($b)) => 'UNVERIFIED',
        horizonAuditAnyPair($consumers, static fn (HorizonAuditConsumer $a, HorizonAuditConsumer $b): bool => $a->sharesQueueWith($b)) => 'WARN',
        default => 'PASS',
    };

    if (in_array('FAIL', $orders, strict: true)
        || horizonAuditAnyPair($consumers, static fn (HorizonAuditConsumer $a, HorizonAuditConsumer $b): bool => $a->differsInRetryAfterFrom($b))
    ) {
        return ['HZ-001:' . $sharing, 'HZ-002:FAIL'];
    }

    $unverified = in_array('UNVERIFIED', $orders, strict: true) || $sharing === 'UNVERIFIED';

    return ['HZ-001:' . $sharing, 'HZ-002:' . ($unverified ? 'UNVERIFIED' : 'PASS')];
}

/**
 * One field of the `expected.json` of a fixture scenario, or null when it is missing.
 */
function horizonAuditExpected(int $number, string $field): mixed
{
    return decodedJsonField(horizonAuditFile(sprintf('T%02d', $number), 'expected.json'), $field);
}

/**
 * A list-of-strings field of the `expected.json` of a fixture scenario.
 *
 * @return list<string>
 */
function horizonAuditExpectedList(int $number, string $field): array
{
    $value = horizonAuditExpected($number, $field);

    return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
}

/**
 * The table row of one scenario in `references/fixture-scenarios.md`.
 */
function horizonAuditScenarioRow(int $number): string
{
    foreach (explode("\n", packageSource('skills/laravel-horizon-audit/references/fixture-scenarios.md')) as $line) {
        if (str_starts_with($line, '| T' . $number . ' |')) {
            return $line;
        }
    }

    return '';
}

/**
 * Asserts that one `RULE:STATUS` outcome of a scenario is a rule and status of the report schema,
 * and that a status other than PASS is named in the scenario table row.
 */
function expectOutcomeInScenarioRow(string $token, string $row): void
{
    [$rule, $status] = array_pad(explode(':', $token, 2), 2, '');

    if ($status === '') {
        return;
    }

    expect(horizonAuditSchemaField('$defs.finding.properties.rule_id.enum'))->toContain($rule);
    expect(horizonAuditSchemaField('$defs.finding.properties.status.enum'))->toContain($status);

    if ($status === 'PASS') {
        return;
    }

    expect($row)->toContain($rule);
    expect($row)->toContain('`' . $status . '`');
}

test('every scenario T1 to T16 ships a fixture whose expected result matches the scenario table and the report schema', function (): void {
    foreach (range(1, 16) as $number) {
        $row = horizonAuditScenarioRow($number);

        expect(horizonAuditExpected($number, 'scenario'))->toBe('T' . $number);
        expect(horizonAuditFile(sprintf('T%02d', $number), 'composer.json'))->not->toBe('');
        expect($row)->not->toBe('');
        expect(horizonAuditExpectedList($number, 'outcomes'))->not->toBe([]);
        expect(horizonAuditSchemaField('$defs.finding.properties.severity.enum'))->toContain(...horizonAuditExpectedList($number, 'severity'));

        array_map(static fn (string $token) => expectOutcomeInScenarioRow($token, $row), horizonAuditExpectedList($number, 'outcomes'));
    }
});

test('the reference evaluation reproduces the expected result of the data scenarios T1 to T7, T14, and T15', function (): void {
    foreach ([1, 2, 3, 4, 5, 6, 7, 14, 15] as $number) {
        expect(horizonAuditExpected($number, 'evaluated'))->toBeTrue();
        expect(horizonAuditOutcomes(sprintf('T%02d', $number)))->toBe(horizonAuditExpectedList($number, 'outcomes'), 'T' . $number);
    }
});

test('the same fixture evaluated twice gives identical outcomes (T14)', function (): void {
    expect(horizonAuditOutcomes('T14'))->toBe(horizonAuditOutcomes('T14'));
});

test('the merged row is audited and no single field of defaults decides (T7)', function (): void {
    $config = horizonAuditFile('T07', 'config/horizon.php.fixture');

    expect(horizonAuditSupervisors($config, 'production')['supervisor-1']['timeout'] ?? null)->toBe('150');
    expect(horizonAuditSupervisors($config, 'staging')['supervisor-1']['timeout'] ?? null)->toBe('60');
});

test('a supervisor that only defaults defines runs in every environment, and the star environment is the fallback', function (): void {
    $config = <<<'PHP'
        <?php

        return [
            'defaults' => [
                'supervisor-1' => ['connection' => 'redis', 'queue' => ['default'], 'timeout' => 60],
                'supervisor-mail' => ['connection' => 'redis', 'queue' => ['mail'], 'timeout' => 200],
            ],
            'environments' => [
                'production' => [
                    'supervisor-1' => ['timeout' => 90],
                ],
                '*' => [
                    'supervisor-1' => ['timeout' => 30],
                ],
            ],
        ];
        PHP;

    expect(horizonAuditSupervisors($config, 'production'))->toBe([
        'supervisor-1' => ['connection' => '\'redis\'', 'queue' => '[\'default\']', 'timeout' => '90'],
        'supervisor-mail' => ['connection' => '\'redis\'', 'queue' => '[\'mail\']', 'timeout' => '200'],
    ]);
    expect(horizonAuditSupervisors($config, 'local')['supervisor-1']['timeout'] ?? null)->toBe('30');
    expect(array_keys(horizonAuditSupervisors($config, 'local')))->toBe(['supervisor-1', 'supervisor-mail']);
});

test('the secret of the environment fixture appears nowhere in the skill, its report template, or its schema (T13)', function (): void {
    $secret = horizonAuditExpected(13, 'secret');

    expect($secret)->toBeString();
    expect(horizonAuditFile('T13', 'dotenv.fixture'))->toContain(is_string($secret) ? $secret : '-');

    foreach (['SKILL.md', 'templates/report.md', 'schemas/report.schema.json', 'references/checks.md', 'references/fixture-scenarios.md'] as $file) {
        expect(packageSource('skills/laravel-horizon-audit/' . $file))->not->toContain(is_string($secret) ? $secret : '-');
    }
});
