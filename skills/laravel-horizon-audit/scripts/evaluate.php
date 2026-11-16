#!/usr/bin/env php
<?php

declare(strict_types = 1);

const HZ_USAGE = <<<'TXT'
Usage: evaluate.php <project_path> [--environment <name>]

Evaluates the computable part of the Laravel Horizon audit rules HZ-001 to HZ-007 over the files of
one project and prints one JSON document. It reads files as text. It never includes, requires, or
executes a file of the project, never starts a process, never opens a network connection, and never
opens a file whose name starts with ".env". A file that is absent under its own name is read under
its name plus ".fixture" (test fixtures use that suffix).

--environment  The Horizon environment to audit (default: production). It selects configuration to
               read and never a system to connect to.

stdout: one JSON document with "applicable", "versions", "environment", "environment_key",
        "consumers", "findings", "outcomes" (RULE:STATUS tokens), "not_decided" (rules without an
        outcome that the agent decides from the rule text), "process_files", and "files_read".
exit:   0 evaluation printed (a FAIL finding is a result, not an error), 1 usage error or
        unreadable project path

TXT;

const HZ_UNSET = '(unset)';

const HZ_SEVERITY_RANK = ['CRITICAL' => 0, 'HIGH' => 1, 'MEDIUM' => 2, 'LOW' => 3, 'INFO' => 4];

const HZ_SEVERITY_OF_STATUS = ['FAIL' => 'HIGH', 'WARN' => 'MEDIUM', 'UNVERIFIED' => 'INFO', 'PASS' => 'INFO'];

const HZ_SKIPPED_DIRECTORIES = ['vendor', 'node_modules', '.git', 'storage', 'cache'];

const HZ_PROCESS_FILE_NAME = '/^(supervisor|dockerfile|docker-compose|compose\.|procfile|envoy|deploy|crontab)|\.(conf|ini|service|ya?ml|sh)$/i';

const HZ_PROCESS_TOKEN = '/\b(horizon:terminate|horizon|queue:work|queue:listen|stopwaitsecs|terminationGracePeriodSeconds)\b/';

const HZ_MAX_FILE_BYTES = 1048576;

final class HzParser
{

    private int $position = 0;

    /**
     * @param list<PhpToken> $tokens
     */
    public function __construct(private readonly array $tokens)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function parseReturn(): ?array
    {
        foreach ($this->tokens as $index => $token) {
            if ($token->id === T_RETURN) {
                $this->position = $index + 1;

                return $this->value();
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function value(): array
    {
        $node = $this->primary();
        $next = $this->tokens[$this->position] ?? null;

        if ($next !== null && !in_array($next->text, [',', ']', ')', ';', '=>'], true)) {
            $line = $node['line'];
            $this->skipExpression();

            return ['kind' => 'unknown', 'value' => null, 'items' => [], 'line' => $line];
        }

        return $node;
    }

    /**
     * @return array<string, mixed>
     */
    private function primary(): array
    {
        $token = $this->tokens[$this->position] ?? null;

        if ($token === null) {
            return ['kind' => 'unknown', 'value' => null, 'items' => [], 'line' => 0];
        }

        $isArray = $token->text === '[' || ($token->id === T_ARRAY && ($this->tokens[$this->position + 1]->text ?? '') === '(');

        if ($isArray) {
            return $this->arrayLiteral($token);
        }

        $scalar = $this->scalar($token);

        if ($scalar !== null) {
            return ['kind' => 'scalar', 'value' => $scalar[0], 'items' => [], 'line' => $token->line];
        }

        $this->skipExpression();

        return ['kind' => 'unknown', 'value' => null, 'items' => [], 'line' => $token->line];
    }

    /**
     * @return array{0: mixed}|null
     */
    private function scalar(PhpToken $token): ?array
    {
        $negative = false;
        $current = $token;

        if ($token->text === '-' && isset($this->tokens[$this->position + 1])) {
            $negative = true;
            $current = $this->tokens[$this->position + 1];
        }

        $value = match (true) {
            $current->id === T_LNUMBER => [intval(str_replace('_', '', $current->text), 0)],
            $current->id === T_DNUMBER => [(float) str_replace('_', '', $current->text)],
            $current->id === T_CONSTANT_ENCAPSED_STRING && !$negative => $this->string($current->text),
            $current->id === T_STRING && !$negative && in_array(strtolower($current->text), ['true', 'false', 'null'], true) => $this->keyword($current->text),
            default => null,
        };

        if ($value === null) {
            return null;
        }

        $this->position += $negative ? 2 : 1;

        return [$negative && (is_int($value[0]) || is_float($value[0])) ? -$value[0] : $value[0]];
    }

    /**
     * @return array{0: mixed}
     */
    private function keyword(string $text): array
    {
        return [match (strtolower($text)) {
            'true' => true,
            'false' => false,
            default => null,
        }];
    }

    /**
     * @return array{0: mixed}
     */
    private function string(string $text): array
    {
        $body = substr($text, 1, -1);

        return [$text[0] === '\'' ? strtr($body, ['\\\'' => '\'', '\\\\' => '\\']) : stripcslashes($body)];
    }

    /**
     * @return array<string, mixed>
     */
    private function arrayLiteral(PhpToken $opening): array
    {
        $closing = $opening->text === '[' ? ']' : ')';
        $this->position += $opening->text === '[' ? 1 : 2;
        $items = [];
        $index = 0;

        while (($token = $this->tokens[$this->position] ?? null) !== null && $token->text !== $closing) {
            $first = $this->value();

            if (($this->tokens[$this->position]->text ?? '') === '=>') {
                $this->position++;
                $value = $this->value();

                if ($first['kind'] === 'scalar' && (is_string($first['value']) || is_int($first['value']))) {
                    $items[$first['value']] = $value;
                }
            } else {
                $items[$index++] = $first;
            }

            if (($this->tokens[$this->position]->text ?? '') === ',') {
                $this->position++;
            }
        }

        $this->position++;

        return ['kind' => 'array', 'value' => null, 'items' => $items, 'line' => $opening->line];
    }

    private function skipExpression(): void
    {
        $depth = 0;

        while (($token = $this->tokens[$this->position] ?? null) !== null) {
            if (in_array($token->text, ['(', '[', '{'], true) || $token->id === T_CURLY_OPEN) {
                $depth++;
            } elseif (in_array($token->text, [')', ']', '}'], true)) {
                if ($depth === 0) {
                    return;
                }

                $depth--;
            } elseif ($depth === 0 && in_array($token->text, [',', ';', '=>'], true)) {
                return;
            }

            $this->position++;
        }
    }

}

final class HzProject
{

    /** @var array<string, true> */
    private array $read = [];

    public function __construct(public readonly string $root)
    {
    }

    public function read(string $path): ?string
    {
        if (str_starts_with(basename($path), '.env') || str_contains($path, '..')) {
            return null;
        }

        foreach ([$path, $path . '.fixture'] as $candidate) {
            $file = $this->root . '/' . $candidate;

            if (is_file($file) && !is_link($file) && filesize($file) <= HZ_MAX_FILE_BYTES) {
                $content = file_get_contents($file);
                $this->read[$path] = true;

                return $content === false ? null : $content;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public function list(string $directory = ''): array
    {
        $base = $this->root . ($directory === '' ? '' : '/' . $directory);

        if (!is_dir($base) || is_link($base)) {
            return [];
        }

        $paths = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
            static fn (SplFileInfo $entry): bool => !$entry->isLink()
                && !str_starts_with($entry->getFilename(), '.env')
                && !($entry->isDir() && in_array($entry->getFilename(), HZ_SKIPPED_DIRECTORIES, true)),
        ));

        foreach ($iterator as $entry) {
            if ($entry instanceof SplFileInfo && $entry->isFile()) {
                $relative = substr($entry->getPathname(), strlen($this->root) + 1);
                $paths[] = (string) preg_replace('/\.fixture$/', '', $relative);
            }
        }

        sort($paths);

        return $paths;
    }

    /**
     * @return list<string>
     */
    public function filesRead(): array
    {
        $paths = array_keys($this->read);
        sort($paths);

        return $paths;
    }

}

/**
 * @return array<string, mixed>|null
 */
function hzParseConfig(?string $source): ?array
{
    if ($source === null) {
        return null;
    }

    $tokens = array_values(array_filter(PhpToken::tokenize($source), static fn (PhpToken $token): bool => !$token->isIgnorable()));

    return (new HzParser($tokens))->parseReturn();
}

/**
 * @param array<string, mixed>|null $node
 * @return array<string, mixed>|null
 */
function hzItem(?array $node, string $key): ?array
{
    $item = $node !== null && $node['kind'] === 'array' ? ($node['items'][$key] ?? null) : null;

    return is_array($item) ? $item : null;
}

/**
 * @param array<string, mixed>|null $node
 */
function hzScalar(?array $node): int|float|string|bool|null
{
    return $node !== null && $node['kind'] === 'scalar' && !is_array($node['value']) ? $node['value'] : null;
}

/**
 * @param array<string, mixed>|null $node
 */
function hzInt(?array $node): ?int
{
    $value = hzScalar($node);

    return is_int($value) ? $value : null;
}

/**
 * @param array<string, mixed>|null $node
 */
function hzString(?array $node): ?string
{
    $value = hzScalar($node);

    return is_string($value) ? $value : null;
}

function hzStrIs(string $pattern, string $value): bool
{
    return $pattern === $value || preg_match('/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/su', $value) === 1;
}

/**
 * @param array<string, mixed>|null $base
 * @param array<string, mixed>|null $override
 * @return array<string, mixed>|null
 */
function hzMerge(?array $base, ?array $override): ?array
{
    if ($base === null || $override === null) {
        return $override ?? $base;
    }

    if ($base['kind'] === 'array' && $override['kind'] === 'array') {
        $items = $base['items'];

        foreach ($override['items'] as $key => $item) {
            $items[$key] = array_key_exists($key, $items) && is_array($items[$key]) && is_array($item) ? hzMerge($items[$key], $item) : $item;
        }

        return ['kind' => 'array', 'value' => null, 'items' => $items, 'line' => $override['line']] + $base;
    }

    return $base['kind'] === 'unknown' || $override['kind'] === 'unknown' ? ['kind' => 'unknown', 'value' => null, 'items' => [], 'line' => $override['line']] : $override;
}

/**
 * @param array<string, mixed>|null $node
 * @return list<string|null>
 */
function hzQueueNames(?array $node): array
{
    if ($node === null) {
        return [null];
    }

    if ($node['kind'] === 'scalar') {
        return is_string($node['value']) ? array_map('trim', explode(',', $node['value'])) : [null];
    }

    if ($node['kind'] !== 'array') {
        return [null];
    }

    $names = [];

    foreach ($node['items'] as $item) {
        $names[] = is_array($item) ? hzString($item) : null;
    }

    return $names === [] ? [null] : $names;
}

/**
 * @return array{applicable: bool, versions: array<string, string|null>}
 */
function hzDiscovery(HzProject $project): array
{
    $versions = ['laravel/framework' => null, 'laravel/horizon' => null];
    $declared = false;
    $manifest = json_decode($project->read('composer.json') ?? '', true);

    foreach (['require', 'require-dev'] as $section) {
        $declared = $declared || (is_array($manifest) && is_array($manifest[$section] ?? null) && array_key_exists('laravel/horizon', $manifest[$section]));
    }

    $lock = json_decode($project->read('composer.lock') ?? '', true);

    foreach (is_array($lock) ? array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) : [] as $package) {
        if (is_array($package) && is_string($package['name'] ?? null) && array_key_exists($package['name'], $versions)) {
            $versions[$package['name']] = is_string($package['version'] ?? null) ? $package['version'] : null;
        }
    }

    return ['applicable' => $declared || $versions['laravel/horizon'] !== null, 'versions' => $versions];
}

/**
 * @param array<string, mixed>|null $horizon
 * @return array{key: string|null, keys: list<string>, shadows: bool, supervisors: array<string, array<string, mixed>>}
 */
function hzSupervisors(?array $horizon, string $environment): array
{
    $environments = hzItem($horizon, 'environments');
    $keys = array_values(array_filter(array_keys($environments['items'] ?? []), 'is_string'));
    $key = null;

    foreach ($keys as $candidate) {
        if (hzStrIs($candidate, $environment)) {
            $key = $candidate;

            break;
        }
    }

    $rows = [];
    $names = $key === null ? [] : array_merge(array_keys(hzItem($horizon, 'defaults')['items'] ?? []), array_keys(hzItem($environments, $key)['items'] ?? []));

    foreach (array_unique($names) as $name) {
        $name = (string) $name;
        $row = hzMerge(hzItem(hzItem($horizon, 'defaults'), $name), hzItem(hzItem($environments, (string) $key), $name));

        if ($row !== null && hzInt(hzItem($row, 'maxProcesses')) !== 0) {
            $rows[$name] = $row;
        }
    }

    return ['key' => $key, 'keys' => $keys, 'shadows' => $key !== null && $key !== $environment && in_array($environment, $keys, true), 'supervisors' => $rows];
}

/**
 * @param array<string, mixed> $row
 * @param array<string, mixed>|null $queueConfig
 * @param array<string, mixed>|null $databaseConfig
 * @return list<array<string, mixed>>
 */
function hzConsumers(string $name, array $row, ?array $queueConfig, ?array $databaseConfig, string $environment): array
{
    $connectionName = hzString(hzItem($row, 'connection'));
    $connection = $connectionName === null ? null : hzItem(hzItem($queueConfig, 'connections'), $connectionName);
    $redisName = hzString(hzItem($connection, 'connection'));
    $redis = $redisName === null ? null : hzItem(hzItem($databaseConfig, 'redis'), $redisName);
    $driver = hzString(hzItem($connection, 'driver'));
    $part = static fn (string $key): int|string|null => $redis === null ? null : (hzItem($redis, $key) === null ? HZ_UNSET : (hzScalar(hzItem($redis, $key)) ?? null));
    $consumers = [];

    foreach (hzQueueNames(hzItem($row, 'queue')) as $queue) {
        $consumers[] = [
            'consumer' => 'horizon:' . $name,
            'source' => 'config/horizon.php:' . $row['line'],
            'connection' => $connectionName,
            'driver' => $driver,
            'redis_connection' => $redisName,
            'database' => $part('database'),
            'prefix' => $part('prefix'),
            'queue' => $queue,
            'environment' => $environment,
            'retry_after' => hzInt(hzItem($connection, 'retry_after')),
            'timeout' => hzInt(hzItem($row, 'timeout')),
            'connection_line' => $connection === null ? null : 'config/queue.php:' . $connection['line'],
            'key' => [$driver, $part('host'), $part('port'), $part('database'), $part('prefix'), $queue],
            'row' => $row,
        ];
    }

    return $consumers;
}

/**
 * @param list<int|string|null> $first
 * @param list<int|string|null> $second
 */
function hzKeyRelation(array $first, array $second): string
{
    $unknown = false;

    foreach ($first as $index => $part) {
        $other = $second[$index];

        if ($part === null || $other === null) {
            $unknown = true;
        } elseif ($part !== $other) {
            return 'distinct';
        }
    }

    return $unknown ? 'potential' : 'shared';
}

/**
 * @return list<array{file: string, timeout: int|null, timeout_line: int|null, timeout_unknown: bool, tries: int|null, tries_line: int|null, queue: string|null, releasing: bool}>
 */
function hzJobs(HzProject $project): array
{
    $jobs = [];

    foreach ($project->list('app/Jobs') as $file) {
        $source = str_ends_with($file, '.php') ? $project->read($file) : null;

        if ($source === null) {
            continue;
        }

        $line = static fn (string $pattern): ?int => preg_match($pattern, $source, $found, PREG_OFFSET_CAPTURE) === 1 ? substr_count($source, "\n", 0, $found[0][1]) + 1 : null;
        $number = static fn (string $property): ?int => preg_match('/public (?:int )?\$' . $property . ' = (\d+);/', $source, $found) === 1 ? (int) $found[1] : null;
        $queue = preg_match('/(?:public (?:string )?\$queue = |->onQueue\()\'([^\']+)\'/', $source, $found) === 1 ? $found[1] : null;
        $releasing = preg_match('/new\s+(?:RateLimited|RateLimitedWithRedis)\b/', $source) === 1
            || (preg_match('/new\s+WithoutOverlapping\b/', $source) === 1 && !str_contains($source, '->dontRelease()'));

        $jobs[] = [
            'file' => $file,
            'timeout' => $number('timeout'),
            'timeout_line' => $line('/public (?:int )?\$timeout\b/'),
            'timeout_unknown' => $number('timeout') === null && $line('/public (?:int )?\$timeout\b/') !== null,
            'tries' => $number('tries'),
            'tries_line' => $line('/public (?:int )?\$tries\b/'),
            'queue' => $queue,
            'releasing' => $releasing,
        ];
    }

    return $jobs;
}

/**
 * @return array{files: list<array{file: string, line: int, tokens: list<string>}>, standalone: list<string>}
 */
function hzProcessFiles(HzProject $project): array
{
    $files = [];
    $standalone = [];

    foreach ($project->list() as $path) {
        if (preg_match(HZ_PROCESS_FILE_NAME, basename($path)) !== 1 || str_ends_with($path, '.php')) {
            continue;
        }

        $tokens = [];
        $first = 0;

        foreach (explode("\n", $project->read($path) ?? '') as $index => $text) {
            if (preg_match_all(HZ_PROCESS_TOKEN, $text, $found) > 0) {
                $tokens = array_merge($tokens, $found[1]);
                $first = $first === 0 ? $index + 1 : $first;
            }

            if (preg_match('/\bqueue:(?:work|listen)\b/', $text) === 1) {
                $standalone[] = $path . ':' . ($index + 1);
            }
        }

        if ($tokens !== []) {
            $tokens = array_values(array_unique($tokens));
            sort($tokens);
            $files[] = ['file' => $path, 'line' => $first, 'tokens' => $tokens];
        }
    }

    return ['files' => $files, 'standalone' => $standalone];
}

/**
 * @param list<string> $evidence
 * @return array<string, mixed>
 */
function hzFinding(string $rule, string $status, string $reason, array $evidence): array
{
    sort($evidence);

    return [
        'rule_id' => $rule,
        'status' => $status,
        'severity' => HZ_SEVERITY_OF_STATUS[$status],
        'evidence_basis' => $status === 'UNVERIFIED' ? 'not_verified' : 'computed',
        'evidence' => array_values(array_unique($evidence)),
        'reason' => $reason,
    ];
}

/**
 * @param list<array<string, mixed>> $consumers
 * @param list<string> $standalone
 * @return array<string, mixed>
 */
function hzQueueSharing(array $consumers, array $standalone): array
{
    $shared = [];
    $potential = [];

    foreach ($consumers as $index => $consumer) {
        foreach (array_slice($consumers, $index + 1) as $other) {
            $relation = hzKeyRelation($consumer['key'], $other['key']);

            if ($relation === 'shared') {
                $shared[] = $consumer['source'];
                $shared[] = $other['source'];
            } elseif ($relation === 'potential') {
                $potential[] = $consumer['source'];
                $potential[] = $other['source'];
            }
        }
    }

    return match (true) {
        $shared !== [] => hzFinding('HZ-001', 'WARN', 'Two consumers share a physical queue with a fully known key (driver, host, port, database, prefix, queue).', $shared),
        $potential !== [] => hzFinding('HZ-001', 'UNVERIFIED', 'Two consumers may share a physical queue: every known part of the key is equal and a part is unknown, so only a comparison of the effective configuration confirms it.', $potential),
        $standalone !== [] => hzFinding('HZ-001', 'UNVERIFIED', 'A standalone queue:work or queue:listen command is not in the parsed consumer map.', $standalone),
        $consumers === [] => hzFinding('HZ-001', 'UNVERIFIED', 'No supervisor is selected for the audited environment.', ['config/horizon.php:1']),
        default => hzFinding('HZ-001', 'PASS', 'No two consumers share a physical queue.', array_column($consumers, 'source')),
    };
}

/**
 * @param array<string, mixed> $consumer
 * @param list<array<string, mixed>> $jobs
 * @param int $consumerCount the number of distinct supervisors
 * @return array{0: string, 1: string, 2: list<string>}
 */
function hzOrder(array $consumer, array $jobs, int $consumerCount): array
{
    $evidence = array_filter([$consumer['source'], $consumer['connection_line']]);

    if ($consumer['driver'] !== 'redis') {
        return ['UNVERIFIED', 'The retry_after rule of the redis driver does not apply to driver ' . ($consumer['driver'] ?? 'unknown') . '.', $evidence];
    }

    if ($consumer['timeout'] === null || $consumer['retry_after'] === null) {
        return ['UNVERIFIED', 'The supervisor timeout or the retry_after is not a literal in the files; the built-in default needs the installed Horizon source.', $evidence];
    }

    if ($consumer['timeout'] >= $consumer['retry_after']) {
        return ['FAIL', 'The supervisor timeout is not below retry_after.', $evidence];
    }

    $status = 'PASS';
    $reason = 'job timeout < supervisor timeout < retry_after holds for every value the files show.';

    foreach ($jobs as $job) {
        $applies = $consumerCount === 1 || $job['queue'] === $consumer['queue'];
        $reaches = $job['timeout_unknown'] || ($job['timeout'] !== null && $job['timeout'] >= $consumer['timeout']);

        if (!$reaches) {
            continue;
        }

        $evidence[] = $job['file'] . ':' . $job['timeout_line'];
        $status = $applies && !$job['timeout_unknown'] ? 'FAIL' : ($status === 'FAIL' ? 'FAIL' : 'UNVERIFIED');
        $reason = $status === 'FAIL' ? 'A job timeout is not below the supervisor timeout.' : 'A job timeout is not a literal or the queue of the job is not resolved, so its consumer is not verified.';
    }

    return [$status, $reason, $evidence];
}

/**
 * @param list<array<string, mixed>> $consumers
 * @param list<array<string, mixed>> $jobs
 * @return array<string, mixed>
 */
function hzTimeouts(array $consumers, array $jobs): array
{
    $supervisors = count(array_unique(array_column($consumers, 'consumer')));
    $status = $consumers === [] ? 'UNVERIFIED' : 'PASS';
    $reason = $consumers === [] ? 'No supervisor is selected for the audited environment.' : 'job timeout < supervisor timeout < retry_after holds for every consumer.';
    $byStatus = ['FAIL' => [], 'UNVERIFIED' => $consumers === [] ? ['config/horizon.php:1'] : [], 'PASS' => []];

    foreach ($consumers as $index => $consumer) {
        [$orderStatus, $orderReason, $orderEvidence] = hzOrder($consumer, $jobs, $supervisors);
        $differing = array_filter(array_slice($consumers, $index + 1), static fn (array $other): bool => $consumer['retry_after'] !== null
            && $other['retry_after'] !== null && $consumer['retry_after'] !== $other['retry_after']);
        $conflicts = array_filter($differing, static fn (array $other): bool => hzKeyRelation($consumer['key'], $other['key']) === 'shared');
        $maybe = array_filter($differing, static fn (array $other): bool => hzKeyRelation($consumer['key'], $other['key']) === 'potential');

        if ($conflicts !== []) {
            [$orderStatus, $orderReason] = ['FAIL', 'Connections that share a physical queue have different retry_after values.'];
            $orderEvidence = array_merge($orderEvidence, array_column($conflicts, 'connection_line'));
        } elseif ($maybe !== [] && $orderStatus === 'PASS') {
            [$orderStatus, $orderReason] = ['UNVERIFIED', 'Connections with different retry_after values may share a physical queue: a part of the key is unknown.'];
            $orderEvidence = array_merge($orderEvidence, array_column($maybe, 'connection_line'));
        }

        $rank = ['FAIL' => 2, 'UNVERIFIED' => 1, 'PASS' => 0];

        if ($rank[$orderStatus] > $rank[$status] || ($status === 'PASS' && $orderStatus !== 'PASS')) {
            [$status, $reason] = [$orderStatus, $orderReason];
        }

        $byStatus[$orderStatus] = array_merge($byStatus[$orderStatus], $orderEvidence);
    }

    return hzFinding('HZ-002', $status, $reason, array_values(array_filter($byStatus[$status])));
}

/**
 * @param list<array<string, mixed>> $jobs
 * @return array<string, mixed>|null
 */
function hzRetries(array $jobs): ?array
{
    $evidence = [];

    foreach ($jobs as $job) {
        if ($job['releasing'] && $job['tries'] !== null && $job['tries'] > 0) {
            $evidence[] = $job['file'] . ':' . $job['tries_line'];
        }
    }

    return $evidence === [] ? null : hzFinding('HZ-003', 'WARN', 'A job with a finite $tries uses a middleware that releases it, and each release spends an attempt. Whether the value is low for the expected contention is the agent\'s judgement.', $evidence);
}

/**
 * @param array<string, array<string, mixed>> $supervisors
 * @return array<string, mixed>|null
 */
function hzSupervisorConfiguration(array $supervisors): ?array
{
    $contradiction = [];
    $order = [];

    foreach ($supervisors as $row) {
        $queues = hzItem($row, 'queue');
        $min = hzInt(hzItem($row, 'minProcesses'));
        $max = hzInt(hzItem($row, 'maxProcesses'));

        if ($min !== null && $max !== null && $min > $max) {
            $contradiction[] = 'config/horizon.php:' . $row['line'];
        }

        if (hzString(hzItem($row, 'balance')) === 'auto' && count(array_filter(hzQueueNames($queues))) > 1) {
            $order[] = 'config/horizon.php:' . ($queues['line'] ?? $row['line']);
        }
    }

    return match (true) {
        $contradiction !== [] => hzFinding('HZ-004', 'FAIL', 'minProcesses is above maxProcesses.', $contradiction),
        $order !== [] => hzFinding('HZ-004', 'WARN', 'Under balance "auto" the order of the queues sets no strict priority. Whether the project expects order priority is the agent\'s judgement.', $order),
        default => null,
    };
}

/**
 * @param array<string, mixed>|null $horizon
 * @param list<array<string, mixed>> $consumers
 * @return array<string, mixed>|null
 */
function hzWaits(?array $horizon, array $consumers): ?array
{
    $waits = hzItem($horizon, 'waits');

    if ($waits === null || $waits['kind'] !== 'array') {
        return null;
    }

    $unknown = array_filter($consumers, static fn (array $consumer): bool => $consumer['connection'] === null || $consumer['queue'] === null) !== [];
    $consumed = [];

    foreach ($consumers as $consumer) {
        if ($consumer['connection'] !== null && $consumer['queue'] !== null) {
            $consumed[$consumer['connection'] . ':' . $consumer['queue']] = 'config/horizon.php:' . $consumer['row']['line'];
        }
    }

    $keys = array_map('strval', array_keys($waits['items']));
    $missing = array_values(array_diff_key($consumed, array_flip($keys)));
    $orphaned = array_map(static fn (string $key): string => 'config/horizon.php:' . $waits['items'][$key]['line'], array_diff($keys, array_keys($consumed)));

    return match (true) {
        $missing !== [] || ($orphaned !== [] && !$unknown) => hzFinding('HZ-006', 'WARN', 'A waits key has no consumer (orphaned), or a consumed connection:queue has no waits key (missing).', array_merge($missing, $unknown ? [] : $orphaned)),
        $orphaned !== [] => hzFinding('HZ-006', 'UNVERIFIED', 'A waits key matches no known consumer, and a consumer has an unknown connection or queue.', $orphaned),
        default => null,
    };
}

/**
 * @return array<string, mixed>
 */
function hzDashboard(HzProject $project): array
{
    $evidence = [];

    foreach ($project->list('app') as $file) {
        $source = str_ends_with($file, '.php') ? $project->read($file) : null;

        foreach (explode("\n", $source ?? '') as $index => $text) {
            $code = ltrim($text);
            $isComment = str_starts_with($code, '//') || str_starts_with($code, '*') || str_starts_with($code, '#') || str_starts_with($code, '/*');

            if (!$isComment && (str_contains($text, 'viewHorizon') || str_contains($text, 'Horizon::auth'))) {
                $evidence[] = $file . ':' . ($index + 1);
            }
        }
    }

    return hzFinding(
        'HZ-007',
        'UNVERIFIED',
        $evidence === [] ? 'No Horizon::auth callback or viewHorizon gate is defined in app/, so the default gate of Horizon applies and it decides at runtime.' : 'The dashboard authorization is a callback that runs at request time and is never evaluated, so no security diagnosis follows from it.',
        $evidence === [] ? ['composer.json:1'] : $evidence,
    );
}

/**
 * @return array<string, mixed>
 */
function hzEvaluate(HzProject $project, string $environment): array
{
    $discovery = hzDiscovery($project);
    $result = ['applicable' => $discovery['applicable'], 'versions' => $discovery['versions'], 'environment' => $environment];

    if (!$discovery['applicable']) {
        return $result + ['environment_key' => null, 'environment_keys' => [], 'wildcard_shadows_exact' => false, 'consumers' => [], 'findings' => [], 'outcomes' => ['NOT_APPLICABLE'], 'not_decided' => [], 'process_files' => [], 'files_read' => $project->filesRead(), 'runtime_access' => 'none'];
    }

    $horizon = hzParseConfig($project->read('config/horizon.php'));
    $queueConfig = hzParseConfig($project->read('config/queue.php'));
    $databaseConfig = hzParseConfig($project->read('config/database.php'));
    $plan = hzSupervisors($horizon, $environment);
    $consumers = [];

    foreach ($plan['supervisors'] as $name => $row) {
        $consumers = array_merge($consumers, hzConsumers($name, $row, $queueConfig, $databaseConfig, $environment));
    }

    $jobs = hzJobs($project);
    $process = hzProcessFiles($project);
    $findings = array_values(array_filter([
        hzQueueSharing($consumers, $process['standalone']),
        hzTimeouts($consumers, $jobs),
        hzRetries($jobs),
        hzSupervisorConfiguration($plan['supervisors']),
        $process['files'] === [] ? hzFinding('HZ-005', 'UNVERIFIED', 'No process-manager or deploy file that mentions Horizon or a queue worker is in the project, so the deployment is not shown.', ['composer.json:1']) : null,
        hzWaits($horizon, $consumers),
        hzDashboard($project),
    ]));
    $outcomes = array_map(static fn (array $finding): string => $finding['rule_id'] . ':' . $finding['status'], $findings);
    $decided = array_column($findings, 'rule_id');

    usort($findings, static fn (array $first, array $second): int => [HZ_SEVERITY_RANK[$first['severity']], $first['rule_id'], $first['evidence'][0] ?? ''] <=> [HZ_SEVERITY_RANK[$second['severity']], $second['rule_id'], $second['evidence'][0] ?? '']);

    return $result + [
        'environment_key' => $plan['key'],
        'environment_keys' => $plan['keys'],
        'wildcard_shadows_exact' => $plan['shadows'],
        'consumers' => array_map(static fn (array $consumer): array => array_diff_key($consumer, ['key' => 0, 'row' => 0, 'connection_line' => 0]), $consumers),
        'findings' => $findings,
        'outcomes' => $outcomes,
        'not_decided' => array_values(array_diff(['HZ-001', 'HZ-002', 'HZ-003', 'HZ-004', 'HZ-005', 'HZ-006', 'HZ-007'], $decided)),
        'process_files' => $process['files'],
        'files_read' => $project->filesRead(),
        'runtime_access' => 'none',
    ];
}

$arguments = array_slice($argv, 1);
$environment = 'production';
$root = null;

while ($arguments !== []) {
    $argument = array_shift($arguments);

    if ($argument === '--environment' && $arguments !== []) {
        $environment = array_shift($arguments);
    } elseif ($root === null && !str_starts_with($argument, '--')) {
        $root = $argument;
    } else {
        fwrite(STDERR, HZ_USAGE);

        exit(1);
    }
}

$resolved = $root === null ? false : realpath($root);

if ($resolved === false || !is_dir($resolved)) {
    fwrite(STDERR, HZ_USAGE);

    exit(1);
}

echo json_encode(hzEvaluate(new HzProject($resolved), $environment), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
