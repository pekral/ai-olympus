# Tinker EXPLAIN — the MySQL part in a Laravel application

This reference is the one owner of the Tinker test of `MODE=cr-report` (`the command workflow` *Workflow* step 2). It applies only to MySQL and MariaDB. A project without Laravel, without a local database, or without Laravel Tinker keeps the plain database client path of `SKILL.md`, unchanged.

The procedure boots the application of the checkout it runs in. The configuration, the service providers, and the models of that checkout run before any snippet does. In a code review that checkout holds the branch under review, so the trust rule comes first.

## Preconditions

Every item is checked before the application boots. The first item that fails ends the Tinker test.

- When item 1, 2, or 3 fails, the Tinker test does not apply. The report uses the plain database client path of `SKILL.md`, and its `DB access:` line names that path.
- When item 4 or 5 fails, the report goes on with static analysis and names the item as the reason in its `DB access:` line.

1. The dispatch carries a pull request URL and its head SHA. With no pull request there is no author whose trust the rule can read.
2. `composer.json` requires `laravel/framework` and `laravel/tinker`, the file `artisan` exists, and `vendor/autoload.php` exists. `database report` never installs a dependency.
3. The review resolved the engine as `mysql` or `mariadb`, and the diff changes SQL operations.
4. The trust rule below holds.
5. The configuration is not cached: the file `bootstrap/cache/config.php` does not exist. `database report` checks only that the file exists and never reads it. A cached configuration ignores the variables the command sets (*The command* below): `configuration is cached`.

## The trust rule — where `database report` may boot the application

`database report` boots the application only when all of these hold:

1. **The pull request does not come from a fork.** The loader field `isCrossRepository` is `false`. A missing or `null` value counts as a fork (fail closed).
2. **The author of the pull request is trusted** (`@rules/code-review/general.md` *Authorship trust*). `database report` reads the loader field `authorAssociation` from the output of `skills/code-review-github/scripts/load-issue.sh <PR-URL>`, which `the command workflow` *Workflow* step 1 already loads, and decides it with `write_access` of `skills/_shared/review-trust.jq`: `jq -L skills/_shared 'include "review-trust"; .authorAssociation | write_access'`. Only the output `true` is trusted. The output `false`, a `null` association (the loader could not read it), an empty output, and a failed command are untrusted.
3. **The checkout is the reviewed head.** `git rev-parse HEAD` equals the head SHA of the dispatch, and `git status --porcelain --untracked-files=no` prints nothing. `database report` never switches a branch to reach this state: in a parallel code review round `acceptance check` owns the main working tree. A checkout on another commit, or with a tracked change, gives `checkout is not the reviewed head`.

In every other case the Tinker test does not run, and the report names the failed item: `fork pull request`, `author not trusted`, or `checkout is not the reviewed head`.

## The guard — no connection before the proof

The snippet runs the guard before it builds or runs any query. The guard compares values already loaded in memory and opens no connection. It reads the connection of the model that the diff names (`getConnectionName()` of the model, or the default connection) and refuses unless every one of these holds:

1. The driver is `mysql` or `mariadb`.
2. The environment name (`app()->environment()`) is `local` or `testing`.
3. Every configured host is a loopback host (`localhost`, `127.0.0.1`, `::1`, `[::1]`). The guard checks `host`, every entry of a host list, `read.host`, `write.host`, and the host of a URL-style `url` (DSN). A connection with no host and no `unix_socket` is refused, and so is a URL with no readable host. The guard accepts a `unix_socket` as local, and it cannot see where the socket leads (*What the mechanism does not cover* below).
   A `url` that carries a query string is refused with `refused:url`, because Laravel merges its parameters (`host`, `read[host]`, `write[0][host]`) over the host of the URL and over the connection. A `url` whose scheme is not `mysql` or `mariadb`, and a `url` that is not a string, are refused too, because Laravel takes the driver from the scheme.
4. `read` and `write` are checked in both forms Laravel accepts: one section, and a list from which Laravel picks one entry at random. Like Laravel, the guard reads a `read` or `write` array with an entry at key `0` as a list. In a list every entry counts: the `host` of each connection array, and each host string. An entry that is neither a connection array nor a host string is refused, and so is a `url` inside a `read` or `write` section.
5. No value that Laravel writes into the DSN adds a DSN key. `database`, `port`, and `unix_socket` of the connection and of every `read` or `write` entry, and the decoded database path of a `url`, are refused with `refused:dsn` when they contain `;` or `=`, or when they are not a scalar. Laravel builds `mysql:host=…;port=…;dbname=…` from them, and PDO takes the last `host=` of a DSN, so such a value moves the connection to another host.

The snippet runs the same guard on the default connection (`config('database.default')`) and prints its verdict as `database report:default=ok` or `database report:default=refused`. Laravel Boost reads that connection (*Laravel Boost and Laravel Telescope* below).

The snippet checks the default cache store (`config('cache.default')`) too, because Laravel Boost writes to it. It prints `database report:cache=ok` when the driver of that store is `array` or `file`, and `database report:cache=refused` for every other driver, a missing store, and a store with no driver. Neither of the two drivers opens a connection to another service.

The snippet also refuses with `refused:telescope` when `config('telescope.enabled')` is true after the boot. Telescope then records the run and writes it to its own storage connection, which the guard never checked.

The snippet opens the connection only after the guard returned `ok`. The first statement that runs on it is the plain `EXPLAIN` of an accepted statement.

## Nothing from the diff runs as text

- The snippet is the fixed text of *The snippet* below. `database report` pastes it verbatim and never edits it.
- No code taken from the diff goes into the snippet, and no value from the diff is interpolated into PHP or into a shell command.
- The diff reaches the snippet only as data: one JSON document on one line, written to `.claude/run/<source-slug>.database report-query.json` (`.codex/run/` under Codex) with a quoted heredoc, and read by the snippet from the environment variable `CASEY_QUERY_SPEC`, which holds that path. `database report` sets the variable for the one command and never reads its value. It escapes every quote, backslash, and control character in the document, so no line equals the heredoc delimiter. It removes the file when the run ends.
- The document has the keys `model` (a class name that matches `^[A-Za-z_\\][A-Za-z0-9_\\]*$` and extends `Illuminate\Database\Eloquent\Model`) and `operations` (at most 20 entries, each `[<method>, [<arguments>]]`).
- The snippet accepts only these methods: `where` and `orWhere` (column, operator, scalar), `whereIn` and `whereNotIn` (column, list of scalars), `whereNull` and `whereNotNull` (column), `whereBetween` (column, two scalars), `orderBy` (column, `asc` or `desc`), `select` (columns), `distinct`, `limit`, and `offset`.
- The operators are `=`, `<`, `>`, `<=`, `>=`, `<>`, `!=`, and `like`. A column matches `^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$`. Values travel as bindings and never as SQL text.
- A query the vocabulary cannot express (a raw expression, a local scope, a relation, a join, a closure) is analysed statically, and the item names it in its *Evidence* line.

## The statement accept rule

The snippet takes the statement from the application (`toSql()` and `getBindings()` of the Eloquent builder, so global scopes, soft deletes, and the table prefix apply). It runs `EXPLAIN` only when the statement is accepted.

A statement is accepted when it is one statement that starts with `select` and every character belongs to a closed vocabulary: backtick-quoted identifiers, `?`, `*`, `,`, `.`, parentheses, the comparison operators, digits, whitespace, and the keywords `select distinct from where and or not in is null like between exists order by asc desc limit offset group having join inner left right on as true false count min max sum avg`. A semicolon, a quote, a comment, `into`, `union`, `for update`, and every other word make the statement refused: `database report:error=statement`. The rule accepts, and never lists what it refuses.

The procedure runs plain `EXPLAIN` and never `EXPLAIN ANALYZE`, so it measures no latency. A statement that changes data or schema never runs, because the accept rule refuses it before any connection exists. Laravel Telescope stores no entry of the Tinker run: the command switches it off, the snippet first stops its recording and drops the entries the boot queued (`Telescope::stopRecording()` and `Telescope::flushEntries()`, when the class exists), and the guard refuses before any query when the configuration still enables it.

## The closed output list

The snippet prints only these lines. Every other line of the command output is dropped unread by the `grep` of the command, and `database report` never quotes or relays it.

| Line | Value |
|---|---|
| `database report:guard=` | `ok`, or `refused:` followed by `driver`, `environment`, `host`, `no-host`, `url`, `dsn`, or `telescope` |
| `database report:default=` | `ok` or `refused`: the guard verdict of the default connection |
| `database report:cache=` | `ok` or `refused`: the driver check of the default cache store |
| `database report:sql=` | the accepted statement with `?` placeholders |
| `database report:bindings=` | the number of bindings, never a value |
| `database report:plan=` | the `EXPLAIN` rows as JSON, restricted to the columns `id`, `select_type`, `table`, `partitions`, `type`, `possible_keys`, `key`, `key_len`, `rows`, `filtered`, and `Extra` |
| `database report:error=` | `spec`, `model`, `statement`, or `explain` |

No host, database name, DSN, credential, configuration value, or exception message is printed. The `ref` column of `EXPLAIN` is left out because it can name the database. The snippet catches every `Throwable` and prints `database report:error=explain` without its message.

## A refused or failed guard ends in static analysis

When the trust rule, the configuration check, the guard, the accept rule, or the `EXPLAIN` fails, `database report` analyses the query statically and writes the reason into the `DB access:` line as `static analysis only — <reason>`. The reason is one of: `fork pull request`, `author not trusted`, `checkout is not the reviewed head`, `configuration is cached`, `guard refused: <the token>`, `statement not accepted`, `query outside the vocabulary`, or `EXPLAIN failed`. The report never quotes a message of the failure.

A failed precondition 1, 2, or 3 is not such a failure. A run without a pull request, a project without Laravel or Laravel Tinker, and a project whose dependencies are not installed keep the plain database client path of `SKILL.md`, unchanged.

## The command

`database report` runs the original query and the proposal as two runs of the same snippet, each with its own document, and compares the two plans (`@rules/sql/optimalize.md` *Performance Non-Regression on Query Changes*).

```bash
TELESCOPE_ENABLED=false CASEY_QUERY_SPEC=<absolute path of the document> php artisan tinker --execute="$(cat <<'CASEY_SNIPPET'
<the snippet, verbatim>
CASEY_SNIPPET
)" 2>&1 | grep -E '^database report:(guard|default|cache|sql|bindings|plan|error)='
```

`TELESCOPE_ENABLED=false` switches Laravel Telescope recording off where the project's `config/telescope.php` reads that variable. Where it does not, the guard refuses with `refused:telescope`. `database report` sets the variable and never reads a value from the environment.

## Laravel Boost and Laravel Telescope — optional, never required

- **Laravel Boost.** `database report` calls the Boost tool `database-schema` for the columns and indexes of a table, only when the session exposes that tool, only when `composer.json` requires `laravel/telescope` in neither `require` nor `require-dev`, only after a run of this procedure printed `database report:guard=ok`, `database report:default=ok`, and `database report:cache=ok` in this run, and without its `database` argument.
  Boost runs every tool in its own `php artisan boost:execute-tool` process. The `TELESCOPE_ENABLED=false` of the command and the Telescope stop of the snippet do not reach that process, so Telescope would record the call. A project that requires `laravel/telescope` therefore reads the schema without Boost.
  The tool keeps the schema it read for 20 seconds in the default cache store of the application (`Cache::remember`). That is a write, so `database report:cache=ok` is the proof for the store Boost writes to. A `redis`, `memcached`, `dynamodb`, or `database` store writes over a connection the guard never checked, so a project with such a default store reads the schema without Boost.
  Without that argument Boost reads the default connection, so `database report:default=ok` is the proof for the connection Boost reads; `database report:guard=ok` alone proves only the connection of the model.
  A project without Boost, and a session without that tool, read the schema from the migrations and from the plain database client. `database report` never calls the Boost tool `tinker` (it executes arbitrary PHP and is off unless `boost.tinker_tool_enabled` is set) or `database-query` (it runs a statement that `database report` typed, outside the accept rule). Both tool names, and `database-schema`, were verified in the source of `laravel/boost` v2.10.4 (`src/Mcp/Tools/`), where a tool is named after its class in kebab case.
- **Laravel Telescope.** When the project uses `laravel/telescope`, `@skills/laravel-telescope/SKILL.md` may read the query entries the application recorded before this run for the same statement, and only after the guard printed `ok`. The Tinker run stores no Telescope entry: the command sets `TELESCOPE_ENABLED=false`, the snippet stops the recording and drops the queued entries first, and the guard refuses with `refused:telescope` when the configuration still enables it. A Telescope fact is the recorded `time`, the `slow` flag, or the number of entries with the same `hash`. The report never quotes the recorded statement: Telescope stores it with its bindings inlined. The report quotes the `file` of an entry relative to the project root and never an absolute path.

## What the mechanism does not cover

- A loopback port or a unix socket (including the default socket of `localhost`) that a tunnel or a proxy forwards to another host. The guard reads the configured host and the socket path, and cannot see where the port or the socket leads.
- A loopback database that holds a copy of production data. The guard proves the host and the environment name, not the content.
- Code that runs while the application boots, before the snippet. A service provider that connects to a database at boot is not stopped by the guard. The trust rule is the control for it.
- A commit on the pull request branch by an account other than the author. The trust rule reads the author of the pull request only.
- The name `localhost`, which the guard accepts without resolving it.
- Files the boot writes inside the checkout (logs, caches). They are git-ignored runtime files, and `database report` never writes a tracked file.
- A Telescope entry update of the boot. When a project keeps Telescope on through a configuration that ignores `TELESCOPE_ENABLED`, Telescope starts at the boot. `Telescope::flushEntries()` drops the queued entries, not an entry update (`Telescope::recordUpdate()`, which the job watchers use). No query of the snippet runs in that case.
- The path of a `file` cache store. `database report:cache=ok` proves the driver of the store, not where its configured path leads.
- The configuration the Laravel Boost server loaded when it started. `database report:default=ok` and `database report:cache=ok` prove the default connection and the default cache store of the Tinker process. A Boost server started before the checkout reached the reviewed head, or before a configuration change, can hold another default connection or another default cache store.
- The plan of the local database. It reflects the local data volume and statistics, and `EXPLAIN` here is plain, so no latency is measured. A table size comes from `SHOW INDEX` through the plain database client only.

## The snippet

Block 1 holds the pure functions. Block 2 uses them. `database report` pastes both blocks, in this order, as one text.

```php
$loopback = ['localhost', '127.0.0.1', '::1', '[::1]'];
$tokens = [
    'guard' => ['ok', 'refused:driver', 'refused:environment', 'refused:host', 'refused:no-host', 'refused:url', 'refused:dsn', 'refused:telescope'],
    'default' => ['ok', 'refused'],
    'cache' => ['ok', 'refused'],
    'error' => ['spec', 'model', 'statement', 'explain'],
];
$emit = static function (string $key, string|int $value) use ($tokens): void {
    if (in_array($key, ['sql', 'bindings', 'plan'], true) || in_array($value, $tokens[$key] ?? [], true)) {
        echo 'database report:' . $key . '=' . $value . PHP_EOL;
    }
};
$hostOf = static fn (mixed $host): string => is_string($host) ? strtolower(trim($host)) : '';
$hostsOf = static fn (array $section): array => array_map($hostOf, array_values((array) ($section['host'] ?? [])));
$isDsnPart = static fn (mixed $part): bool => $part === null || (is_scalar($part) && preg_match('/[;=]/', (string) $part) !== 1);
$dsnSafe = static fn (array $section): bool => $isDsnPart($section['database'] ?? null) && $isDsnPart($section['port'] ?? null) && $isDsnPart($section['unix_socket'] ?? null);
$sectionHosts = static function (mixed $section) use ($hostOf, $hostsOf, $dsnSafe): array|string {
    $hosts = [];
    foreach (is_array($section) && isset($section[0]) ? $section : [$section] as $entry) {
        if (is_array($entry) && isset($entry['url'])) {
            return 'refused:url';
        }
        if (is_array($entry) && ! $dsnSafe($entry)) {
            return 'refused:dsn';
        }
        $hosts = [...$hosts, ...(is_array($entry) ? $hostsOf($entry) : [$hostOf($entry)])];
    }

    return $hosts;
};
$guard = static function (array $connection, string $environment) use ($loopback, $hostsOf, $sectionHosts, $isDsnPart, $dsnSafe): string {
    if (! in_array($connection['driver'] ?? null, ['mysql', 'mariadb'], true)) {
        return 'refused:driver';
    }
    if (! in_array($environment, ['local', 'testing'], true)) {
        return 'refused:environment';
    }
    if (! $dsnSafe($connection)) {
        return 'refused:dsn';
    }
    $hosts = $hostsOf($connection);
    foreach (['read', 'write'] as $type) {
        $found = isset($connection[$type]) ? $sectionHosts($connection[$type]) : [];
        if (is_string($found)) {
            return $found;
        }
        $hosts = [...$hosts, ...$found];
    }
    $url = $connection['url'] ?? null;
    if ($url !== null && $url !== '') {
        $parts = is_string($url) ? parse_url($url) : false;
        if (! is_array($parts) || ! is_string($parts['host'] ?? null) || isset($parts['query']) || ! in_array(strtolower($parts['scheme'] ?? ''), ['mysql', 'mariadb'], true)) {
            return 'refused:url';
        }
        if (! $isDsnPart(rawurldecode($parts['path'] ?? ''))) {
            return 'refused:dsn';
        }
        $hosts[] = strtolower($parts['host']);
    }
    if ($hosts === [] && ($connection['unix_socket'] ?? '') === '') {
        return 'refused:no-host';
    }
    foreach ($hosts as $host) {
        if (! in_array($host, $loopback, true)) {
            return 'refused:host';
        }
    }

    return 'ok';
};
$cacheGuard = static fn (mixed $store): string => is_array($store) && in_array($store['driver'] ?? null, ['array', 'file'], true) ? 'ok' : 'refused';
$accept = static function (string $sql): bool {
    $keywords = 'select|distinct|from|where|and|or|not|in|is|null|like|between|exists|order|by|asc|desc|limit|offset|group|having|join|inner|left|right|on|as|true|false|count|min|max|sum|avg';
    $token = '`[A-Za-z0-9_$]+`|\?|\*|,|\.|\(|\)|<>|!=|<=|>=|=|<|>|[0-9]+|\b(?:' . $keywords . ')\b';
    preg_match_all('/\G(?:\s+|' . $token . ')/i', $sql, $matches);

    return implode('', $matches[0]) === $sql && preg_match('/^select\b/i', $sql) === 1;
};
$isColumn = static fn (mixed $value): bool => is_string($value) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $value) === 1;
$isScalar = static fn (mixed $value): bool => $value === null || is_scalar($value);
$isScalarList = static fn (mixed $value): bool => is_array($value) && array_is_list($value) && count(array_filter($value, $isScalar)) === count($value);
$isOperation = static function (mixed $operation) use ($isColumn, $isScalar, $isScalarList): bool {
    if (! is_array($operation) || ! array_is_list($operation) || count($operation) !== 2 || ! is_string($operation[0]) || ! is_array($operation[1]) || ! array_is_list($operation[1])) {
        return false;
    }
    [$method, $arguments] = $operation;

    return match ($method) {
        'where', 'orWhere' => count($arguments) === 3 && $isColumn($arguments[0]) && in_array($arguments[1], ['=', '<', '>', '<=', '>=', '<>', '!=', 'like'], true) && $isScalar($arguments[2]),
        'whereIn', 'whereNotIn' => count($arguments) === 2 && $isColumn($arguments[0]) && $isScalarList($arguments[1]),
        'whereNull', 'whereNotNull' => count($arguments) === 1 && $isColumn($arguments[0]),
        'whereBetween' => count($arguments) === 2 && $isColumn($arguments[0]) && $isScalarList($arguments[1]) && count($arguments[1]) === 2,
        'orderBy' => count($arguments) === 2 && $isColumn($arguments[0]) && in_array($arguments[1], ['asc', 'desc'], true),
        'select' => $arguments !== [] && count(array_filter($arguments, $isColumn)) === count($arguments),
        'distinct' => $arguments === [],
        'limit', 'offset' => count($arguments) === 1 && is_int($arguments[0]) && $arguments[0] >= 0,
        default => false,
    };
};
$isSpec = static fn (mixed $spec): bool => is_array($spec)
    && is_string($spec['model'] ?? null)
    && preg_match('/^[A-Za-z_\\\\][A-Za-z0-9_\\\\]*$/', $spec['model']) === 1
    && is_array($spec['operations'] ?? null)
    && array_is_list($spec['operations'])
    && count($spec['operations']) <= 20
    && count(array_filter($spec['operations'], $isOperation)) === count($spec['operations']);
```

```php
$run = static function () use ($emit, $guard, $cacheGuard, $accept, $isSpec): void {
    if (class_exists(\Laravel\Telescope\Telescope::class)) {
        \Laravel\Telescope\Telescope::stopRecording();
        \Laravel\Telescope\Telescope::flushEntries();
    }
    $path = (string) getenv('CASEY_QUERY_SPEC');
    $spec = is_file($path) && is_readable($path) ? json_decode((string) file_get_contents($path), true) : null;
    if (! $isSpec($spec)) {
        $emit('error', 'spec');

        return;
    }
    $model = $spec['model'];
    if (! class_exists($model) || ! is_subclass_of($model, \Illuminate\Database\Eloquent\Model::class)) {
        $emit('error', 'model');

        return;
    }
    $environment = (string) app()->environment();
    $default = $guard((array) config('database.connections.' . config('database.default')), $environment);
    $emit('default', $default === 'ok' ? 'ok' : 'refused');
    $emit('cache', $cacheGuard(config('cache.stores.' . config('cache.default'))));
    $connectionName = (new $model())->getConnectionName() ?? config('database.default');
    $verdict = config('telescope.enabled') ? 'refused:telescope' : $guard((array) config('database.connections.' . $connectionName), $environment);
    $emit('guard', $verdict);
    if ($verdict !== 'ok') {
        return;
    }
    $builder = $model::query();
    foreach ($spec['operations'] as [$method, $arguments]) {
        $builder = $method === 'select' ? $builder->select($arguments) : $builder->{$method}(...$arguments);
    }
    $sql = $builder->toSql();
    if (! $accept($sql)) {
        $emit('error', 'statement');

        return;
    }
    $bindings = $builder->getBindings();
    $emit('sql', $sql);
    $emit('bindings', count($bindings));
    $rows = \Illuminate\Support\Facades\DB::connection($connectionName)->select('explain ' . $sql, $bindings);
    $columns = array_flip(['id', 'select_type', 'table', 'partitions', 'type', 'possible_keys', 'key', 'key_len', 'rows', 'filtered', 'Extra']);
    $emit('plan', json_encode(array_map(static fn (object $row): array => array_intersect_key((array) $row, $columns), $rows), JSON_THROW_ON_ERROR));
};
try {
    $run();
} catch (\Throwable) {
    $emit('error', 'explain');
}
```
