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

const HZ_SEVERITY_RANK = ['CRITICAL' => 0, 'HIGH' => 1, 'MEDIUM' => 2, 'LOW' => 3, 'INFO' => 4];

const HZ_SEVERITY_OF_STATUS = ['FAIL' => 'HIGH', 'WARN' => 'MEDIUM', 'UNVERIFIED' => 'INFO', 'PASS' => 'INFO'];

const HZ_SKIPPED_DIRECTORIES = ['vendor', 'node_modules', '.git', 'storage', 'cache'];

const HZ_PROCESS_FILE_NAME = '/^(supervisor|dockerfile|docker-compose|compose\.|procfile|envoy|deploy|crontab)|\.(conf|ini|service|ya?ml|sh)$/i';

const HZ_PROCESS_TOKEN = '/\b(horizon:terminate|horizon|queue:work|queue:listen|stopwaitsecs|terminationGracePeriodSeconds)\b/';

const HZ_MAX_FILE_BYTES = 1048576;

enum HzKind: string
{

    case Scalar = 'scalar';
    case Collection = 'array';
    case Unknown = 'unknown';

}

final readonly class HzNode
{

    /**
     * @param array<int|string, HzNode> $items
     */
    public function __construct(public HzKind $kind, public int|float|string|bool|null $value, public array $items, public int $line)
    {
    }

    public static function unknown(int $line): self
    {
        return new self(HzKind::Unknown, null, [], $line);
    }

    public static function scalar(int|float|string|bool|null $value, int $line): self
    {
        return new self(HzKind::Scalar, $value, [], $line);
    }

    /**
     * @param array<int|string, HzNode> $items
     */
    public static function collection(array $items, int $line): self
    {
        return new self(HzKind::Collection, null, $items, $line);
    }

}

final readonly class HzConsumer implements JsonSerializable
{

    public function __construct(
        public string $consumer,
        public string $source,
        public ?string $connection,
        public ?string $driver,
        public ?string $redisConnection,
        public int|string|null $host,
        public int|string|null $port,
        public int|string|null $database,
        public int|string|null $prefix,
        public ?string $queue,
        public string $environment,
        public ?int $retryAfter,
        public ?int $timeout,
        public ?string $connectionLine,
    ) {
    }

    /**
     * @return array<string, int|string|null>
     */
    public function jsonSerialize(): array
    {
        return [
            'consumer' => $this->consumer,
            'source' => $this->source,
            'connection' => $this->connection,
            'driver' => $this->driver,
            'redis_connection' => $this->redisConnection,
            'database' => $this->database,
            'prefix' => $this->prefix,
            'queue' => $this->queue,
            'environment' => $this->environment,
            'retry_after' => $this->retryAfter,
            'timeout' => $this->timeout,
        ];
    }

}

final readonly class HzJob
{

    public function __construct(
        public string $file,
        public ?int $timeout,
        public ?int $timeoutLine,
        public bool $timeoutUnknown,
        public ?int $tries,
        public ?int $triesLine,
        public ?string $queue,
        public bool $releasing,
    ) {
    }

}

final readonly class HzProcessFile
{

    /**
     * @param list<string> $tokens
     */
    public function __construct(public string $file, public int $line, public array $tokens)
    {
    }

}

final readonly class HzFinding implements JsonSerializable
{

    /**
     * @param list<string> $evidence
     */
    public function __construct(
        public string $ruleId,
        public string $status,
        public string $severity,
        public string $evidenceBasis,
        public array $evidence,
        public string $reason,
    ) {
    }

    public function outcome(): string
    {
        return $this->ruleId . ':' . $this->status;
    }

    /**
     * @return array<string, string|list<string>>
     */
    public function jsonSerialize(): array
    {
        return [
            'rule_id' => $this->ruleId,
            'status' => $this->status,
            'severity' => $this->severity,
            'evidence_basis' => $this->evidenceBasis,
            'evidence' => $this->evidence,
            'reason' => $this->reason,
        ];
    }

}

final readonly class HzEvaluation implements JsonSerializable
{

    /**
     * @param array<string, string|null> $versions
     * @param list<string> $environmentKeys
     * @param list<HzConsumer> $consumers
     * @param list<HzFinding> $findings
     * @param list<string> $outcomes
     * @param list<string> $notDecided
     * @param list<HzProcessFile> $processFiles
     * @param list<string> $filesRead
     */
    public function __construct(
        public bool $applicable,
        public array $versions,
        public string $environment,
        public ?string $environmentKey,
        public array $environmentKeys,
        public bool $wildcardShadowsExact,
        public array $consumers,
        public array $findings,
        public array $outcomes,
        public array $notDecided,
        public array $processFiles,
        public array $filesRead,
    ) {
    }

    /**
     * @param array<string, string|null> $versions
     * @param list<string> $filesRead
     */
    public static function notApplicable(array $versions, string $environment, array $filesRead): self
    {
        return new self(false, $versions, $environment, null, [], false, [], [], ['NOT_APPLICABLE'], [], [], $filesRead);
    }

    /**
     * @return array<string, bool|string|null|array<int|string, mixed>>
     */
    public function jsonSerialize(): array
    {
        return [
            'applicable' => $this->applicable,
            'versions' => $this->versions,
            'environment' => $this->environment,
            'environment_key' => $this->environmentKey,
            'environment_keys' => $this->environmentKeys,
            'wildcard_shadows_exact' => $this->wildcardShadowsExact,
            'consumers' => $this->consumers,
            'findings' => $this->findings,
            'outcomes' => $this->outcomes,
            'not_decided' => $this->notDecided,
            'process_files' => array_map(static fn (HzProcessFile $file): array => ['file' => $file->file, 'line' => $file->line, 'tokens' => $file->tokens], $this->processFiles),
            'files_read' => $this->filesRead,
            'runtime_access' => 'none',
        ];
    }

}

final class HzParser
{

    private int $position = 0;

    /**
     * @param list<PhpToken> $tokens
     */
    public function __construct(private readonly array $tokens)
    {
    }

    public function parseReturn(): ?HzNode
    {
        foreach ($this->tokens as $index => $token) {
            if ($token->id === T_RETURN) {
                $this->position = $index + 1;

                return $this->value();
            }
        }

        return null;
    }

    private function value(): HzNode
    {
        $node = $this->primary();
        $next = $this->tokens[$this->position] ?? null;

        if ($next !== null && !in_array($next->text, [',', ']', ')', ';', '=>'], true)) {
            $this->skipExpression();

            return HzNode::unknown($node->line);
        }

        return $node;
    }

    private function primary(): HzNode
    {
        $token = $this->tokens[$this->position] ?? null;

        if ($token === null) {
            return HzNode::unknown(0);
        }

        $isArray = $token->text === '[' || ($token->id === T_ARRAY && ($this->tokens[$this->position + 1]->text ?? '') === '(');

        if ($isArray) {
            return $this->arrayLiteral($token);
        }

        $scalar = $this->scalar($token);

        if ($scalar !== null) {
            return HzNode::scalar($scalar[0], $token->line);
        }

        $this->skipExpression();

        return HzNode::unknown($token->line);
    }

    /**
     * @return array{0: int|float|string|bool|null}|null
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
     * @return array{0: bool|null}
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
     * @return array{0: string}
     */
    private function string(string $text): array
    {
        $body = substr($text, 1, -1);

        return [$text[0] === '\'' ? strtr($body, ['\\\'' => '\'', '\\\\' => '\\']) : stripcslashes($body)];
    }

    private function arrayLiteral(PhpToken $opening): HzNode
    {
        $closing = $opening->text === '[' ? ']' : ')';
        $this->position += $opening->text === '[' ? 1 : 2;
        $items = [];
        $index = 0;

        while (($token = $this->tokens[$this->position] ?? null) !== null && $token->text !== $closing) {
            $start = $this->position;
            $first = $this->value();

            if (($this->tokens[$this->position]->text ?? '') === '=>') {
                $this->position++;
                $value = $this->value();

                if ($first->kind === HzKind::Scalar && (is_string($first->value) || is_int($first->value))) {
                    $items[$first->value] = $value;
                }
            } else {
                $items[$index++] = $first;
            }

            if (($this->tokens[$this->position]->text ?? '') === ',') {
                $this->position++;
            }

            if ($this->position === $start) {
                $this->position++;
            }
        }

        $this->position++;

        return HzNode::collection($items, $opening->line);
    }

    private function skipExpression(): void
    {
        $depth = 0;

        while (($token = $this->tokens[$this->position] ?? null) !== null) {
            if (in_array($token->text, ['(', '[', '{'], true) || $token->id === T_CURLY_OPEN || $token->id === T_DOLLAR_OPEN_CURLY_BRACES) {
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

            if (is_file($file) && !is_link($file) && $this->inside($file) && filesize($file) <= HZ_MAX_FILE_BYTES) {
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

        if (!is_dir($base) || is_link($base) || !$this->inside($base)) {
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

    private function inside(string $path): bool
    {
        $real = realpath($path);

        return $real !== false && ($real === $this->root || str_starts_with($real, $this->root . '/'));
    }

}

function hzParseConfig(?string $source): ?HzNode
{
    if ($source === null) {
        return null;
    }

    $tokens = array_values(array_filter(PhpToken::tokenize($source), static fn (PhpToken $token): bool => !$token->isIgnorable()));

    return (new HzParser($tokens))->parseReturn();
}

function hzItem(?HzNode $node, string $key): ?HzNode
{
    return $node !== null && $node->kind === HzKind::Collection ? ($node->items[$key] ?? null) : null;
}

function hzScalar(?HzNode $node): int|float|string|bool|null
{
    return $node !== null && $node->kind === HzKind::Scalar ? $node->value : null;
}

function hzInt(?HzNode $node): ?int
{
    $value = hzScalar($node);

    return is_int($value) ? $value : null;
}

function hzString(?HzNode $node): ?string
{
    $value = hzScalar($node);

    return is_string($value) ? $value : null;
}

function hzKeyPart(?HzNode $node): int|string|null
{
    $value = hzScalar($node);

    return is_int($value) || is_string($value) ? $value : null;
}

function hzStrIs(string $pattern, string $value): bool
{
    return $pattern === $value || preg_match('/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/su', $value) === 1;
}

function hzMerge(HzNode $base, HzNode $override): HzNode
{
    if ($base->kind === HzKind::Collection && $override->kind === HzKind::Collection) {
        $items = $base->items;

        foreach ($override->items as $key => $item) {
            $items[$key] = array_key_exists($key, $items) ? hzMerge($items[$key], $item) : $item;
        }

        return HzNode::collection($items, $override->line);
    }

    return $base->kind === HzKind::Unknown || $override->kind === HzKind::Unknown ? HzNode::unknown($override->line) : $override;
}

/**
 * @return list<string|null>
 */
function hzQueueNames(?HzNode $node): array
{
    if ($node === null) {
        return [null];
    }

    if ($node->kind === HzKind::Scalar) {
        return is_string($node->value) ? array_map('trim', explode(',', $node->value)) : [null];
    }

    if ($node->kind !== HzKind::Collection) {
        return [null];
    }

    $names = array_map(hzString(...), array_values($node->items));

    return $names === [] ? [null] : $names;
}

function hzHorizonMajor(?string $version): ?int
{
    return $version !== null && preg_match('/^v?(\d+)\./', $version, $found) === 1 ? (int) $found[1] : null;
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
 * @return array{key: string|null, keys: list<string>, shadows: bool, supervisors: array<string, HzNode>}
 */
function hzSupervisors(?HzNode $horizon, string $environment): array
{
    $environments = hzItem($horizon, 'environments');
    $keys = array_values(array_filter(array_keys($environments->items ?? []), 'is_string'));
    $key = null;

    foreach ($keys as $candidate) {
        if (hzStrIs($candidate, $environment)) {
            $key = $candidate;

            break;
        }
    }

    $rows = [];
    $defaults = hzItem($horizon, 'defaults');
    $plan = $key === null ? null : hzItem($environments, $key);
    $names = $key === null ? [] : array_unique(array_merge(array_keys($defaults->items ?? []), array_keys($plan->items ?? [])));

    foreach ($names as $name) {
        $name = (string) $name;
        $base = hzItem($defaults, $name);
        $override = hzItem($plan, $name);
        $row = $base === null || $override === null ? $override ?? $base : hzMerge($base, $override);

        if ($row !== null && hzInt(hzItem($row, 'maxProcesses')) !== 0) {
            $rows[$name] = $row;
        }
    }

    return ['key' => $key, 'keys' => $keys, 'shadows' => $key !== null && $key !== $environment && in_array($environment, $keys, true), 'supervisors' => $rows];
}

/**
 * @return list<HzConsumer>
 */
function hzConsumers(string $name, HzNode $row, ?HzNode $queueConfig, ?HzNode $databaseConfig, string $environment): array
{
    $connectionName = hzString(hzItem($row, 'connection'));
    $connection = $connectionName === null ? null : hzItem(hzItem($queueConfig, 'connections'), $connectionName);
    $redisName = hzString(hzItem($connection, 'connection'));
    $redisRoot = hzItem($databaseConfig, 'redis');
    $redis = $redisName === null ? null : hzItem($redisRoot, $redisName);
    $prefixLevels = [hzItem($redis, 'prefix'), hzItem(hzItem($redis, 'options'), 'prefix'), hzItem(hzItem($redisRoot, 'options'), 'prefix')];
    $prefixNode = current(array_filter($prefixLevels, static fn (?HzNode $level): bool => $level !== null)) ?: null;
    $database = $redis === null ? null : (hzItem($redis, 'database') === null ? 0 : hzKeyPart(hzItem($redis, 'database')));
    $prefix = $redis === null ? null : ($prefixNode === null ? '' : hzKeyPart($prefixNode));
    $consumers = [];

    foreach (hzQueueNames(hzItem($row, 'queue')) as $queue) {
        $consumers[] = new HzConsumer(
            consumer: 'horizon:' . $name,
            source: 'config/horizon.php:' . $row->line,
            connection: $connectionName,
            driver: hzString(hzItem($connection, 'driver')),
            redisConnection: $redisName,
            host: $redis === null ? null : hzKeyPart(hzItem($redis, 'host')),
            port: $redis === null ? null : hzKeyPart(hzItem($redis, 'port')),
            database: $database,
            prefix: $prefix,
            queue: $queue,
            environment: $environment,
            retryAfter: hzInt(hzItem($connection, 'retry_after')),
            timeout: hzInt(hzItem($row, 'timeout')),
            connectionLine: $connection === null ? null : 'config/queue.php:' . $connection->line,
        );
    }

    return $consumers;
}

function hzKeyRelation(HzConsumer $first, HzConsumer $second): string
{
    $sameRedisConnection = $first->redisConnection !== null && $first->redisConnection === $second->redisConnection;
    $parts = [
        [$first->driver, $second->driver, false],
        [$first->host, $second->host, $sameRedisConnection],
        [$first->port, $second->port, $sameRedisConnection],
        [$first->database, $second->database, false],
        [$first->prefix, $second->prefix, false],
        [$first->queue, $second->queue, false],
    ];
    $unknown = false;

    foreach ($parts as [$part, $other, $sharedEntry]) {
        if ($part === null && $other === null && $sharedEntry) {
            continue;
        }

        if ($part === null || $other === null) {
            $unknown = true;
        } elseif ($part !== $other) {
            return 'distinct';
        }
    }

    return $unknown ? 'potential' : 'shared';
}

/**
 * @return list<HzJob>
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
        $timeoutLine = $line('/public (?:int )?\$timeout\b/');

        $jobs[] = new HzJob(
            file: $file,
            timeout: $number('timeout'),
            timeoutLine: $timeoutLine,
            timeoutUnknown: $number('timeout') === null && $timeoutLine !== null,
            tries: $number('tries'),
            triesLine: $line('/public (?:int )?\$tries\b/'),
            queue: $queue,
            releasing: $releasing,
        );
    }

    return $jobs;
}

/**
 * @return array{files: list<HzProcessFile>, standalone: list<string>}
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
            $files[] = new HzProcessFile($path, $first, $tokens);
        }
    }

    return ['files' => $files, 'standalone' => $standalone];
}

/**
 * @param list<string> $evidence
 */
function hzFinding(string $rule, string $status, string $reason, array $evidence): HzFinding
{
    sort($evidence);

    return new HzFinding(
        ruleId: $rule,
        status: $status,
        severity: HZ_SEVERITY_OF_STATUS[$status],
        evidenceBasis: $status === 'UNVERIFIED' ? 'not_verified' : 'computed',
        evidence: array_values(array_unique($evidence)),
        reason: $reason,
    );
}

/**
 * @param list<HzConsumer> $consumers
 * @param list<string> $standalone
 */
function hzQueueSharing(array $consumers, array $standalone, string $noSupervisorReason): HzFinding
{
    $shared = [];
    $potential = [];

    foreach ($consumers as $index => $consumer) {
        foreach (array_slice($consumers, $index + 1) as $other) {
            $relation = hzKeyRelation($consumer, $other);

            if ($relation === 'shared') {
                $shared[] = $consumer->source;
                $shared[] = $other->source;
            } elseif ($relation === 'potential') {
                $potential[] = $consumer->source;
                $potential[] = $other->source;
            }
        }
    }

    return match (true) {
        $shared !== [] => hzFinding('HZ-001', 'WARN', 'Two consumers share a physical queue with a fully known key (driver, host, port, database, prefix, queue).', $shared),
        $potential !== [] => hzFinding('HZ-001', 'UNVERIFIED', 'Two consumers may share a physical queue: every known part of the key is equal and a part is unknown, so only a comparison of the effective configuration confirms it.', $potential),
        $standalone !== [] => hzFinding('HZ-001', 'UNVERIFIED', 'A standalone queue:work or queue:listen command is not in the parsed consumer map.', $standalone),
        $consumers === [] => hzFinding('HZ-001', 'UNVERIFIED', $noSupervisorReason, ['config/horizon.php:1']),
        default => hzFinding('HZ-001', 'PASS', 'No two consumers share a physical queue.', array_map(static fn (HzConsumer $consumer): string => $consumer->source, $consumers)),
    };
}

/**
 * @param list<HzJob> $jobs
 * @param int $consumerCount the number of distinct supervisors
 * @return array{0: string, 1: string, 2: list<string>}
 */
function hzOrder(HzConsumer $consumer, array $jobs, int $consumerCount): array
{
    $evidence = array_values(array_filter([$consumer->source, $consumer->connectionLine]));

    if ($consumer->driver !== 'redis') {
        return ['UNVERIFIED', 'The retry_after rule of the redis driver does not apply to driver ' . ($consumer->driver ?? 'unknown') . '.', $evidence];
    }

    if ($consumer->timeout === null || $consumer->retryAfter === null) {
        return ['UNVERIFIED', 'The supervisor timeout or the retry_after is not a literal in the files; the built-in default needs the installed Horizon source.', $evidence];
    }

    if ($consumer->timeout >= $consumer->retryAfter) {
        return ['FAIL', 'The supervisor timeout is not below retry_after.', $evidence];
    }

    $status = 'PASS';
    $reason = 'job timeout < supervisor timeout < retry_after holds for every value the files show.';

    foreach ($jobs as $job) {
        $applies = $consumerCount === 1 || $job->queue === $consumer->queue;
        $reaches = $job->timeoutUnknown || ($job->timeout !== null && $job->timeout >= $consumer->timeout);

        if (!$reaches) {
            continue;
        }

        $evidence[] = $job->file . ':' . $job->timeoutLine;
        $status = $applies && !$job->timeoutUnknown ? 'FAIL' : ($status === 'FAIL' ? 'FAIL' : 'UNVERIFIED');
        $reason = $status === 'FAIL' ? 'A job timeout is not below the supervisor timeout.' : 'A job timeout is not a literal or the queue of the job is not resolved, so its consumer is not verified.';
    }

    return [$status, $reason, $evidence];
}

/**
 * @param list<HzConsumer> $consumers
 * @param list<HzJob> $jobs
 */
function hzTimeouts(array $consumers, array $jobs, string $noSupervisorReason): HzFinding
{
    $supervisors = count(array_unique(array_map(static fn (HzConsumer $consumer): string => $consumer->consumer, $consumers)));
    $status = $consumers === [] ? 'UNVERIFIED' : 'PASS';
    $reason = $consumers === [] ? $noSupervisorReason : 'job timeout < supervisor timeout < retry_after holds for every consumer.';
    $byStatus = ['FAIL' => [], 'UNVERIFIED' => $consumers === [] ? ['config/horizon.php:1'] : [], 'PASS' => []];
    $connectionLine = static fn (HzConsumer $consumer): ?string => $consumer->connectionLine;

    foreach ($consumers as $index => $consumer) {
        [$orderStatus, $orderReason, $orderEvidence] = hzOrder($consumer, $jobs, $supervisors);
        $differing = array_filter(array_slice($consumers, $index + 1), static fn (HzConsumer $other): bool => $consumer->retryAfter !== null
            && $other->retryAfter !== null && $consumer->retryAfter !== $other->retryAfter);
        $conflicts = array_filter($differing, static fn (HzConsumer $other): bool => hzKeyRelation($consumer, $other) === 'shared');
        $maybe = array_filter($differing, static fn (HzConsumer $other): bool => hzKeyRelation($consumer, $other) === 'potential');

        if ($conflicts !== []) {
            [$orderStatus, $orderReason] = ['FAIL', 'Connections that share a physical queue have different retry_after values.'];
            $orderEvidence = array_merge($orderEvidence, array_filter(array_map($connectionLine, $conflicts)));
        } elseif ($maybe !== [] && $orderStatus === 'PASS') {
            [$orderStatus, $orderReason] = ['UNVERIFIED', 'Connections with different retry_after values may share a physical queue: a part of the key is unknown.'];
            $orderEvidence = array_merge($orderEvidence, array_filter(array_map($connectionLine, $maybe)));
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
 * @param list<HzJob> $jobs
 */
function hzRetries(array $jobs): ?HzFinding
{
    $evidence = [];

    foreach ($jobs as $job) {
        if ($job->releasing && $job->tries !== null && $job->tries > 0) {
            $evidence[] = $job->file . ':' . $job->triesLine;
        }
    }

    return $evidence === [] ? null : hzFinding('HZ-003', 'WARN', 'A job with a finite $tries uses a middleware that releases it, and each release spends an attempt. Whether the value is low for the expected contention is the agent\'s judgement.', $evidence);
}

/**
 * @param array<string, HzNode> $supervisors
 */
function hzSupervisorConfiguration(array $supervisors): ?HzFinding
{
    $contradiction = [];
    $order = [];

    foreach ($supervisors as $row) {
        $queues = hzItem($row, 'queue');
        $min = hzInt(hzItem($row, 'minProcesses'));
        $max = hzInt(hzItem($row, 'maxProcesses'));

        if ($min !== null && $max !== null && $min > $max) {
            $contradiction[] = 'config/horizon.php:' . $row->line;
        }

        if (hzString(hzItem($row, 'balance')) === 'auto' && count(array_filter(hzQueueNames($queues))) > 1) {
            $order[] = 'config/horizon.php:' . ($queues->line ?? $row->line);
        }
    }

    return match (true) {
        $contradiction !== [] => hzFinding('HZ-004', 'FAIL', 'minProcesses is above maxProcesses.', $contradiction),
        $order !== [] => hzFinding('HZ-004', 'WARN', 'Under balance "auto" the order of the queues sets no strict priority. Whether the project expects order priority is the agent\'s judgement.', $order),
        default => null,
    };
}

/**
 * @param list<HzConsumer> $consumers
 */
function hzWaits(?HzNode $horizon, array $consumers): ?HzFinding
{
    $waits = hzItem($horizon, 'waits');

    if ($waits === null || $waits->kind !== HzKind::Collection) {
        return null;
    }

    $unknown = array_filter($consumers, static fn (HzConsumer $consumer): bool => $consumer->connection === null || $consumer->queue === null) !== [];
    $consumed = [];

    foreach ($consumers as $consumer) {
        if ($consumer->connection !== null && $consumer->queue !== null) {
            $consumed[$consumer->connection . ':' . $consumer->queue] = $consumer->source;
        }
    }

    $keys = array_map('strval', array_keys($waits->items));
    $missing = array_values(array_diff_key($consumed, array_flip($keys)));
    $orphaned = array_map(static fn (string $key): string => 'config/horizon.php:' . $waits->items[$key]->line, array_diff($keys, array_keys($consumed)));

    return match (true) {
        $missing !== [] || ($orphaned !== [] && !$unknown) => hzFinding('HZ-006', 'WARN', 'A waits key has no consumer (orphaned), or a consumed connection:queue has no waits key (missing).', array_merge($missing, $unknown ? [] : $orphaned)),
        $orphaned !== [] => hzFinding('HZ-006', 'UNVERIFIED', 'A waits key matches no known consumer, and a consumer has an unknown connection or queue.', $orphaned),
        default => null,
    };
}

function hzDashboard(HzProject $project): HzFinding
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

function hzEvaluate(HzProject $project, string $environment): HzEvaluation
{
    $discovery = hzDiscovery($project);

    if (!$discovery['applicable']) {
        return HzEvaluation::notApplicable($discovery['versions'], $environment, $project->filesRead());
    }

    $modelled = hzHorizonMajor($discovery['versions']['laravel/horizon']) === 5;
    $noSupervisorReason = $modelled
        ? 'No supervisor is selected for the audited environment.'
        : 'The Horizon version is not 5.x or is not known from composer.lock, so the environment selection and the merge of defaults are not verified.';
    $horizon = hzParseConfig($project->read('config/horizon.php'));
    $queueConfig = hzParseConfig($project->read('config/queue.php'));
    $databaseConfig = hzParseConfig($project->read('config/database.php'));
    $plan = $modelled ? hzSupervisors($horizon, $environment) : ['key' => null, 'keys' => [], 'shadows' => false, 'supervisors' => []];
    $consumers = [];

    foreach ($plan['supervisors'] as $name => $row) {
        $consumers = array_merge($consumers, hzConsumers($name, $row, $queueConfig, $databaseConfig, $environment));
    }

    $jobs = hzJobs($project);
    $process = hzProcessFiles($project);
    $findings = array_values(array_filter([
        hzQueueSharing($consumers, $process['standalone'], $noSupervisorReason),
        hzTimeouts($consumers, $jobs, $noSupervisorReason),
        hzRetries($jobs),
        hzSupervisorConfiguration($plan['supervisors']),
        $process['files'] === [] ? hzFinding('HZ-005', 'UNVERIFIED', 'No process-manager or deploy file that mentions Horizon or a queue worker is in the project, so the deployment is not shown.', ['composer.json:1']) : null,
        $modelled ? hzWaits($horizon, $consumers) : null,
        hzDashboard($project),
    ]));
    $outcomes = array_map(static fn (HzFinding $finding): string => $finding->outcome(), $findings);
    $decided = array_map(static fn (HzFinding $finding): string => $finding->ruleId, $findings);

    usort($findings, static fn (HzFinding $first, HzFinding $second): int => [HZ_SEVERITY_RANK[$first->severity], $first->ruleId, $first->evidence[0] ?? ''] <=> [HZ_SEVERITY_RANK[$second->severity], $second->ruleId, $second->evidence[0] ?? '']);

    return new HzEvaluation(
        applicable: true,
        versions: $discovery['versions'],
        environment: $environment,
        environmentKey: $plan['key'],
        environmentKeys: $plan['keys'],
        wildcardShadowsExact: $plan['shadows'],
        consumers: $consumers,
        findings: $findings,
        outcomes: $outcomes,
        notDecided: array_values(array_diff(['HZ-001', 'HZ-002', 'HZ-003', 'HZ-004', 'HZ-005', 'HZ-006', 'HZ-007'], $decided)),
        processFiles: $process['files'],
        filesRead: $project->filesRead(),
    );
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
