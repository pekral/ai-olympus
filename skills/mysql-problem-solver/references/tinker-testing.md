# Testing a MySQL query through the local Laravel application (`MODE=cr-report`)

This file is the one owner of the Tinker, Laravel Boost, and Laravel Telescope procedure of the `casey` report. `skills/mysql-problem-solver/SKILL.md` `MODE=cr-report` and `agents/casey.md` point here and do not restate it. The security floor is `@rules/security/general.md` *Environment values are never read* and *Production is never touched*. Nothing here lifts either rule.

## When this file applies

All three conditions hold:

1. The project uses Laravel: `artisan` sits in the project root and `composer.json` lists `laravel/framework`.
2. `composer.json` lists `laravel/tinker` in `require` or `require-dev`.
3. The changed code builds an Eloquent or query-builder query, and the engine resolved to `mysql` or `mariadb`.

When one condition fails, this file does not apply. The report keeps the current behaviour: the plain database client of `agents/casey.md` *Bash boundary* against a locally started database, and static analysis when none exists. A PostgreSQL part and a NoSQL part never use this file.

## Rules every snippet obeys

- The agent runs a snippet as `php artisan tinker --execute='<snippet>'` from the project root. The snippet contains no single quote.
- The agent copies the builder chain from the changed code. It never writes the SQL by hand: the SQL is `toSql()` and the bindings are `getBindings()` of that builder.
- The builder expression stops before `get()`, `first()`, `count()`, or any other call that runs the query. It calls no method of the diff that persists, dispatches, or sends.
- A snippet prints only these outputs: the `EXPLAIN` rows, the word `refused`, the word `failed`, the Telescope projection of *Laravel Telescope* below, and the SQL text of *Laravel Boost* below.
- A snippet never prints, returns, or dumps a configuration or an environment value. This covers `config()`, `env()`, `getConfig()`, a PDO attribute, a host, a database name, a DSN, a user, a password, a connection name, and an exception message. The agent never runs `php artisan config:show` and never reads a `.env*` file.
- The snippet reads a configuration value only inside the guard below, to compare it. It prints the comparison as a refusal, never as a value.
- A snippet catches every `Throwable` and prints `failed`. An uncaught exception is written by Tinker, and a connection error message can carry the host.

## The guard — how the run shows a local database without printing a value

The guard is part of every snippet. The code computes these facts inside the application, and the snippet prints none of them:

1. The driver of the connection is `mysql` or `mariadb`.
2. The application environment name is none of `production`, `prod`, and `staging`. This fact is necessary and never sufficient: an environment name alone does not prove a local database.
3. The write PDO and the read PDO of the connection both report a host in the fixed list below. The host is the text before ` via ` in `PDO::ATTR_CONNECTION_STATUS`, in lower case. With the `mysqlnd` driver that text is `<host> via TCP/IP`, or `Localhost via UNIX socket` for a socket connection (`ext/mysqlnd/mysqlnd_connection.c`; `ext/pdo_mysql/mysql_driver.c` returns it for that attribute). Another driver build can word the text differently, and the guard then refuses.
4. The SQL of the builder is one `select` statement: it starts with `select` and contains no `;`, no `into`, no `for update`, and no `lock in share mode`.

**Local means** that the connection reaches this machine through a loopback address, a Unix socket, or the name of a database service that the project's committed `docker-compose*.yml` declares. The fixed list is `127.0.0.1`, `::1`, `localhost`, and those service names. The agent reads the service names from the committed compose file and writes them into the list. A host outside the list, a value that cannot be read, and any error make the guard refuse.

**What the guard cannot see.** A loopback port that a tunnel forwards to a shared server, and a hosts-file alias, look local. The agent never starts a tunnel. The proof holds for the connection as the project's own setup configures it.

When the guard refuses or fails, the agent does the following:

1. It does not use Tinker, Laravel Boost, or Laravel Telescope for this query.
2. It does not print which fact failed, and it does not retry with a relaxed snippet.
3. It uses the plain database client when `agents/casey.md` *Bash boundary* finds a local database for that client, and static analysis otherwise.
4. The `DB access:` line names the reason, for example `static analysis only — the local-database guard refused`.

## The snippet

`BUILDER` is the builder chain copied from the changed code. `COMPOSE_SERVICES` is the list of database service names from the committed compose file, or nothing.

```php
try {
    $builder = BUILDER;
    $connection = $builder->getConnection();
    $local = ["127.0.0.1", "::1", "localhost", COMPOSE_SERVICES];
    $hostOf = fn ($pdo) => strtolower(explode(" via ", (string) $pdo->getAttribute(PDO::ATTR_CONNECTION_STATUS))[0]);
    $sql = $builder->toSql();
    if (
        in_array($connection->getDriverName(), ["mysql", "mariadb"], true)
        && ! app()->environment("production", "prod", "staging")
        && in_array($hostOf($connection->getPdo()), $local, true)
        && in_array($hostOf($connection->getReadPdo()), $local, true)
        && preg_match("/^\s*select\b/i", $sql) === 1
        && preg_match("/;|\binto\b|\bfor\s+update\b|\block\s+in\s+share\s+mode\b/i", $sql) === 0
    ) {
        $connection->statement("SET TRANSACTION READ ONLY");
        $connection->beginTransaction();
        try {
            echo json_encode($connection->select("EXPLAIN ".$sql, $builder->getBindings()));
        } finally {
            $connection->rollBack();
        }
    } else {
        echo "refused";
    }
} catch (Throwable) {
    echo "failed";
}
```

**How it keeps the run read-only.** The statement type check refuses everything except one `select`. The statement that runs is `EXPLAIN` plus that `select`. It runs inside a read-only transaction that is always rolled back. The agent refuses to run an `INSERT`, `UPDATE`, `DELETE`, a DDL statement, or a stacked statement, and the snippet has no code path that executes one.

**What this does not enforce.** The harness stops no other snippet, and the application's database user can hold write privileges. The limit is this rule text and the content test that pins it.

**Variants.**

- `EXPLAIN ANALYZE` executes the statement. Replace `"EXPLAIN "` with `"EXPLAIN ANALYZE "` (MariaDB: `"ANALYZE "`), and only when the SQL calls no stored or non-aggregate function, judged from the SQL text and the code. Any other `select` gets plain `EXPLAIN`.
- The agent runs the snippet on the original builder and on the proposed builder, one run per query, and compares the two plans (`@rules/sql/optimalize.md` *Performance Non-Regression on Query Changes*). Every query that an eager load or a relation runs is one more run.

## Laravel Boost (optional)

Boost is never required. A project without it, or a session that exposes none of its tools, keeps the Tinker procedure above.

- **How `casey` reaches it.** On Claude Code the `tools:` line of `agents/casey.md` names two tools of the MCP server `laravel-boost`: `mcp__laravel-boost__database-query` and `mcp__laravel-boost__database-schema`. On Codex the session's tools of the same server are the equivalent, when the session carries them. When the server is absent, the entries do not resolve and the agent uses Tinker.
- **Verified tools.** Boost's documentation (`boost.md` of the Laravel documentation, *Available MCP Tools*) lists `Database Connections`, `Database Query`, and `Database Schema`. Boost's source (`src/Mcp/Boost.php`) registers `DatabaseConnections`, `DatabaseQuery`, `DatabaseSchema`, and `Tinker`. The MCP server key is `laravel-boost` (`src/Install/McpWriter.php`). A tool name is the kebab case of its class name unless the class sets another name (`Primitive::name()` in `laravel/mcp`), and none of these classes does.
- **`database-query`.** It accepts a `query` and an optional `database` connection name. It runs the query through a keyword allow-list, inside a transaction that it asks the engine to treat as read-only, and rolls the transaction back (`src/Mcp/Tools/DatabaseQuery.php`). The agent passes only `EXPLAIN`, `EXPLAIN ANALYZE` of one `select` under the limits above, `SHOW CREATE TABLE`, and `SHOW INDEX`. It never passes a `select` that returns rows. To get the SQL, the agent runs the snippet with `echo $sql;` in place of the `echo json_encode(...)` line, and replaces each `?` with the literal that the changed code passes, or with a sample of the right type that the item names. A binding is never printed.
- **`database-schema`.** It returns table names, columns, indexes, and foreign keys (`src/Mcp/Tools/DatabaseSchema.php`). The agent uses it in place of `SHOW CREATE TABLE` and `SHOW INDEX`.
- **Boost never replaces the guard.** No Boost tool returns a yes or no fact. `database-connections` returns `config('database.default')` and the connection names, which are configuration values. `tinker` runs arbitrary PHP, and Boost registers it only when `boost.tinker_tool_enabled` is true. The agent calls a Boost tool for a connection only after a Tinker snippet for that connection ran the guard and did not print `refused` or `failed`. It never calls `database-connections`, `tinker`, or `record-rule`, and it passes `database` only with a connection name taken from the code.

## Laravel Telescope (optional)

Telescope is never required. It applies when `composer.json` lists `laravel/telescope` and the guard accepted the Telescope storage connection.

- **Follow `@skills/laravel-telescope/SKILL.md`.** Its local-only limit applies unchanged: the data comes from the local application, never from a production or unknown host. `casey` has no Telescope URL. The changed file is the filter context.
- **Read one projection, never the whole entry.** The snippet above runs with the `BUILDER` below, and its `echo json_encode(...)` line becomes `echo json_encode($connection->select($sql, $builder->getBindings()));`. That line runs the projection itself and prints its rows: `\Laravel\Telescope\Storage\EntryModel::query()->where("type", "query")->where("content->file", "like", "%/<changed file path>")->latest()->limit(50)->select(["created_at", "content->file as file", "content->line as line", "content->time as time_ms", "content->hash as hash"])`. The guard reads the Telescope connection through that model, so it proves the connection that Telescope stores into.
- **What the report may quote:** `file`, `line`, `time_ms`, `hash`, `created_at`, and the count of entries that share one `hash`. A recorded duration is the evidence of one past run. It can predate the diff, so the item names `created_at`.
- **What the report never quotes:** the recorded `sql`, because Telescope replaces the bindings with their real values in it (`QueryWatcher::replaceBindings` in `laravel/telescope`), and any request, job, mail, notification, exception, log, cache, event, model, or Redis entry. It never quotes a header, a payload, a session value, or a user. The agent never selects the `content` column as a whole.

## Naming the access in the report

The `DB access:` line of `skills/mysql-problem-solver/templates/cr-report.md` names how the facts were obtained: `Tinker`, `Laravel Boost`, `Laravel Telescope`, `the plain database client`, or `static analysis only — <reason>`. When more than one source gave facts, the line joins the names with ` + `.

## Reading aid for the plain-language plan

- `type: ALL` means the database reads the whole table. `ref`, `range`, `eq_ref`, and `const` mean it reads through the index in `key`. A `key` of `NULL` means it uses no index.
- `rows` is the number of rows the database estimates it examines. `filtered` is the estimated percentage of those rows that stay after the table conditions. The rows kept are `rows` × `filtered` ÷ 100. `EXPLAIN ANALYZE` reports the actual row counts.
- `Using filesort` means the database sorts the rows in a separate step instead of reading them in index order. `Using temporary` means it builds a temporary table, for a `GROUP BY`, a `DISTINCT`, or a sort. `Using index` means the index alone answers the query without reading table rows.
