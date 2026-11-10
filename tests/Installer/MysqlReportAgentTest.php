<?php

declare(strict_types = 1);

test('casey ships on both platforms as a read-only agent (issue #180)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $claude = (string) file_get_contents($packageDir . '/agents/casey.md');
    $codex = (string) file_get_contents($packageDir . '/codex/agents/casey.toml');

    expect($claude)->toMatch('/^name: casey$/m')
        ->and($claude)->toMatch('/^disallowedTools: Write, Edit$/m')
        ->and($claude)->toContain('Run `@skills/mysql-problem-solver/SKILL.md` with `MODE=cr-report`')
        ->and($codex)->toContain('name = "casey"')
        ->and($codex)->toContain('First read `.codex/agent-instructions/casey.md` in full');
});

test('the casey report is advisory and never gates convergence or the merge (issue #180)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $reference = (string) file_get_contents($packageDir . '/skills/code-review/references/specialized-reviews.md');
    $casey = (string) file_get_contents($packageDir . '/agents/casey.md');

    expect($reference)->toContain('- **Database report — the `casey` agent.**')
        ->and($reference)->toContain('Every part reports **critical** problems only')
        ->and($reference)->toContain('in the `mysql-report` marker namespace. It is not a `cr-comment`')
        ->and($reference)->toContain(
            'the convergence gate (`@skills/process-code-review/SKILL.md` *Review loop* step 4), or the merge gate of `merge-github-pr`',
        )
        ->and($casey)->toContain('**You advise; you never gate.**')
        ->and($casey)->toContain('upsert-comment.sh <PR-URL> - mysql-report');
});

test('krang dispatches casey in every parallel cr round, and casey stops on PostgreSQL (issue #180)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $krang = (string) file_get_contents($packageDir . '/agents/krang.md');
    $casey = (string) file_get_contents($packageDir . '/agents/casey.md');

    expect($krang)->toContain('- **Database report (`casey`) — advisory, in every round.**')
        ->and($krang)->toContain('stops `Not applicable` only on a diff with no SQL operation and no NoSQL access');
    expect($casey)->toContain('`pgsql` runs the PostgreSQL part, never the MySQL one.')
        ->and($casey)->not->toContain('Not applicable — PostgreSQL project')
        ->and($casey)->toContain('**Step 5 publishes nothing.**');
});

test('no review runs mysql-problem-solver inline, and a caller that cannot dispatch casey says so (issue #180)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $reference = (string) file_get_contents($packageDir . '/skills/code-review/references/specialized-reviews.md');
    $leonardo = (string) file_get_contents($packageDir . '/agents/leonardo.md');
    $wrapper = (string) file_get_contents($packageDir . '/skills/code-review-github/references/cr-wrapper-contract.md');

    expect($reference)->toContain('The review never runs `mysql-problem-solver` inline.')
        ->and($reference)->toContain('writes `Database report not run — dispatch casey` in its own output')
        ->and($reference)->toContain('A review inside a subagent never writes the not-run line')
        ->and($leonardo)->toContain('on MySQL / MariaDB and on an unresolved engine you run no inline `mysql-problem-solver`')
        ->and($leonardo)->toContain('- **Database report:** `needed — sql <mysql | mariadb | pgsql | unresolved | none>, nosql <yes | no>`')
        ->and($wrapper)->toContain('never `mysql-problem-solver` inline.')
        ->and($wrapper)->toContain('A top-level caller that cannot dispatch it writes `Database report not run — dispatch casey` in its output.');
});

test('mysql-problem-solver declares the report-only MODE=cr-report (issue #180)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $skill = (string) file_get_contents($packageDir . '/skills/mysql-problem-solver/SKILL.md');

    expect($skill)->toContain('- **`MODE=cr-report`**')
        ->and($skill)->toContain('reports **critical items only**')
        ->and($skill)->toContain('answers `No critical database item in this diff.` when nothing qualifies.');
});

test('the casey report reaches only a local database and never executes a write through EXPLAIN ANALYZE (issue #180)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $skill = (string) file_get_contents($packageDir . '/skills/mysql-problem-solver/SKILL.md');
    $casey = (string) file_get_contents($packageDir . '/agents/casey.md');

    expect($skill)->toContain('`EXPLAIN ANALYZE` (MariaDB `ANALYZE`) executes the statement, so run it on a `SELECT` only')
        ->and($skill)->toContain('inside `START TRANSACTION READ ONLY; … ROLLBACK;`')
        ->and($skill)->toContain(
            'A `SELECT` that calls a stored or non-aggregate function can have side effects, so it gets plain `EXPLAIN`, never `ANALYZE`',
        )
        ->and($skill)->toContain('runs against a **locally started database only**')
        ->and($casey)->toContain('against a **locally started database only**, never a shared test, staging, or production host')
        ->and($casey)->toContain('never from a developer\'s `.env` and never from the value of an environment variable');
});

test('the casey report shape has one owner and the roster surfaces name casey (issue #180)', function (): void {
    $packageDir = dirname(__DIR__, 2);

    expect((string) file_get_contents($packageDir . '/skills/mysql-problem-solver/SKILL.md'))
        ->toContain('In `MODE=cr-report`, use `templates/cr-report.md` instead.')
        ->and((string) file_get_contents($packageDir . '/agents/casey.md'))
        ->toContain('The report follows `skills/mysql-problem-solver/templates/cr-report.md`')
        ->and((string) file_get_contents($packageDir . '/codex/agents/krang.toml'))
        ->toContain('`michelangelo`, `april`, `casey`, or `shredder`')
        ->and((string) file_get_contents($packageDir . '/.github/workflows/pr.yml'))
        ->toContain('for agent in krang donatello leonardo raphael michelangelo april casey shredder; do');
});

test('the casey NoSQL trigger runs in every project and has one owner (criterion 5)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $reference = (string) file_get_contents($packageDir . '/skills/code-review/references/specialized-reviews.md');
    $casey = (string) file_get_contents($packageDir . '/agents/casey.md');
    $krang = (string) file_get_contents($packageDir . '/agents/krang.md');

    expect($reference)->toContain('- **NoSQL part.** The diff changes NoSQL access, in every project, whatever the SQL engine resolved to.')
        ->and($reference)->toContain('a diff can fire one, two, or all three.')
        ->and($reference)->toContain('A Redis diff goes through `@skills/redis-patterns/SKILL.md` with `MODE=cr` in its `access-cost` scope')
        ->and($reference)->toContain('`casey` runs `redis-patterns` with `MODE=cr` in its `access-cost` scope on every Redis diff.')
        ->and($reference)->toContain(
            'The review\'s cache lens keeps its whole scope, including TTL, key, stampede, lock, limiter, eviction, `KEYS *`, and per-command loops',
        )
        ->and($reference)->toContain('`casey` never restates a cache-lens item')
        ->and($reference)->toContain('Every other store gets one general advisory pass by `casey` itself, with no skill of its own')
        ->and($reference)->toContain('a call per item in a loop (N+1), and an unbounded read')
        ->and($reference)->toContain('`casey` connects to no NoSQL server and analyses statically.')
        ->and($reference)->toContain('NoSQL access is not on this list: it fires its own trigger, independent of the engine')
        ->and(substr_count($reference, 'DynamoDB / NoSQL access'))->toBe(0)
        ->and($casey)->toContain('The engine never decides whether the NoSQL part runs.')
        ->and($casey)->toContain('Follow the same section → *NoSQL part*, and its *Gating* bullet for the Redis split.')
        ->and($casey)->not->toContain('keep only its critical items')
        ->and($casey)->toContain('You connect to no NoSQL server.')
        ->and($casey)->toContain('The trigger, the dispatch rule, and the advisory status are defined once in')
        ->and($krang)->toContain('runs the NoSQL part in every project');
});

test('casey analyses a PostgreSQL query plan from a local instance only, and postgres-patterns keeps the feature shape (criterion 9)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $reference = (string) file_get_contents($packageDir . '/skills/code-review/references/specialized-reviews.md');
    $casey = (string) file_get_contents($packageDir . '/agents/casey.md');
    $postgres = (string) file_get_contents($packageDir . '/skills/postgres-patterns/SKILL.md');
    $orchestration = (string) file_get_contents($packageDir . '/rules/compound-engineering/orchestration.md');

    expect($reference)->toContain('`casey` analyses the query plan, and only from a **local** PostgreSQL instance')
        ->and($reference)->toContain('It never connects to a shared test, staging, or production server.')
        ->and($reference)->toContain('`EXPLAIN ANALYZE` on a `SELECT` only')
        ->and($reference)->toContain('writes no query-plan analysis, never guesses a plan')
        ->and($reference)->toContain('`PostgreSQL query plan not analysed — DB access: static analysis only — <reason>`')
        ->and($reference)->toContain('`casey` never applies the MySQL analysis to a PostgreSQL diff, and a MySQL diff never runs `postgres-patterns`.')
        ->and($reference)->toContain('the same engine in two dimensions, never a second engine')
        ->and($reference)->not->toContain('A PostgreSQL diff never gets a `casey` report')
        ->and($casey)->toContain('→ *PostgreSQL part*')
        ->and($reference)->toContain('only inside `BEGIN TRANSACTION READ ONLY; … ROLLBACK;`')
        ->and($reference)->toContain('A `SELECT` that calls any non-aggregate function gets plain `EXPLAIN`, never `ANALYZE`')
        ->and($casey)->toContain(
            'inside `START TRANSACTION READ ONLY; … ROLLBACK;` (MySQL / MariaDB) or `BEGIN TRANSACTION READ ONLY; … ROLLBACK;` (PostgreSQL)',
        )
        ->and($casey)->toContain('never on a `SELECT` that calls a stored or non-aggregate function, which gets plain `EXPLAIN`')
        ->and($casey)->toContain('the defaults in `config/database.php`')
        ->and($casey)->toContain('with no local PostgreSQL instance write no query-plan analysis')
        ->and($postgres)->toContain('It **defers the query plan**')
        ->and($orchestration)->toContain('against a local MySQL / MariaDB or PostgreSQL database');
});

test('the database report heading is renamed on every surface while the mysql-report marker namespace stays (criteria 5 and 9)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $files = [
        'agents/casey.md',
        'agents/krang.md',
        'agents/leonardo.md',
        'skills/code-review/references/specialized-reviews.md',
        'skills/code-review-github/references/cr-wrapper-contract.md',
        'skills/code-review-github/templates/pr-comment-output.md',
        'skills/process-code-review/SKILL.md',
        'rules/code-review/general.md',
    ];

    foreach ($files as $file) {
        expect((string) file_get_contents($packageDir . '/' . $file))->not->toContain('## MySQL report');
    }

    expect((string) file_get_contents($packageDir . '/skills/code-review-github/templates/pr-comment-output.md'))
        ->toContain('## Database report')
        ->and((string) file_get_contents($packageDir . '/skills/mysql-problem-solver/templates/cr-report.md'))
        ->toContain('## Database report — critical optimizations (advisory)')
        ->and((string) file_get_contents($packageDir . '/skills/code-review-github/scripts/upsert-comment.sh'))
        ->toContain('mysql-report');
});

test('redis-patterns has one scope per caller and the cache lens keeps KEYS and per-command loops (round 2)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $redis = (string) file_get_contents($packageDir . '/skills/redis-patterns/SKILL.md');
    $reference = (string) file_get_contents($packageDir . '/skills/code-review/references/specialized-reviews.md');

    expect($redis)->toContain('> **Scope — one per caller.**')
        ->and($redis)->toContain('and `KEYS *` or per-command loops where `SCAN` or a pipeline belongs. These findings stay blocking review findings.')
        ->and($redis)->toContain('`HGETALL`, `SMEMBERS`, `LRANGE 0 -1`, `ZRANGE 0 -1`, `MGET` over an unbounded key list')
        ->and($redis)->toContain('It never restates a cache-lens item')
        ->and($reference)->toContain('eviction policy against the store\'s role, and `KEYS *` or per-command loops where `SCAN` or a pipeline belongs.')
        ->and($reference)->not->toContain('casey` keeps Redis access cost: `KEYS`');
});

test(
    'casey and mysql-problem-solver test the MySQL queries through php artisan tinker on the original and the proposed query (issue #334)',
    function (): void {
        $packageDir = dirname(__DIR__, 2);
        $casey = (string) file_get_contents($packageDir . '/agents/casey.md');
        $skill = (string) file_get_contents($packageDir . '/skills/mysql-problem-solver/SKILL.md');
        $reference = (string) file_get_contents($packageDir . '/skills/mysql-problem-solver/references/tinker-testing.md');
    
        expect($casey)->toContain('`php artisan tinker --execute` takes the SQL from the changed Eloquent or query-builder code')
            ->and($casey)->toContain('runs `EXPLAIN` on the original and on the proposed query.')
            ->and($casey)->toContain('`skills/mysql-problem-solver/references/tinker-testing.md` owns the procedure')
            ->and($skill)->toContain('**tests the queries through the local application when it can.**')
            ->and($skill)->toContain('the builder chain of the changed code runs through `php artisan tinker --execute`')
            ->and($skill)->toContain('and `toSql()` and `getBindings()` supply the statement.')
            ->and($skill)->toContain(
                '`EXPLAIN` runs on the original query and on the proposed query, and the two plans are compared. Nothing that changes data or schema runs.',
            )
            ->and($skill)->toContain('A project without Laravel, without a local database, or without Boost and Telescope keeps the behaviour above.')
            ->and($reference)->toContain('The agent copies the builder chain from the changed code. It never writes the SQL by hand')
            ->and($reference)->toContain(
                'The agent runs the snippet on the original builder and on the proposed builder, one run per query, and compares the two plans',
            )
            ->and($reference)->toContain('When one condition fails, this file does not apply. The report keeps the current behaviour')
            ->and($reference)->toContain('A PostgreSQL part and a NoSQL part never use this file.');
    },
);

test('the Tinker snippet prints only EXPLAIN rows, refused, or failed and reads no configuration value into its output (issue #334)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $reference = (string) file_get_contents($packageDir . '/skills/mysql-problem-solver/references/tinker-testing.md');
    preg_match('/```php\n(.*?)\n```/s', $reference, $matches);
    $snippet = $matches[1] ?? '';
    preg_match_all('/echo ([^;]+);/', $snippet, $echoes);

    expect($snippet)->not->toBe('')
        ->and($echoes[1])->toBe(['json_encode($connection->select("EXPLAIN ".$sql, $builder->getBindings()))', '"refused"', '"failed"'])
        ->and($snippet)->toContain('catch (Throwable) {')
        ->and($snippet)->not->toContain('config(')
        ->and($snippet)->not->toContain('env(')
        ->and($snippet)->not->toContain('getConfig')
        ->and($snippet)->not->toContain('getMessage')
        ->and($snippet)->not->toContain('getName')
        ->and($snippet)->not->toContain('dump(')
        ->and($snippet)->not->toContain('print_r')
        ->and($snippet)->not->toContain('var_export');
});

test(
    'the Tinker guard proves a local database from the connection status and refuses everything but one select inside a read-only transaction (issue #334)',
    function (): void {
        $packageDir = dirname(__DIR__, 2);
        $reference = (string) file_get_contents($packageDir . '/skills/mysql-problem-solver/references/tinker-testing.md');
        preg_match('/```php\n(.*?)\n```/s', $reference, $matches);
        $snippet = $matches[1] ?? '';
    
        expect($snippet)->toContain('$local = ["127.0.0.1", "::1", "localhost", COMPOSE_SERVICES];')
            ->and($snippet)->toContain('$pdo->getAttribute(PDO::ATTR_CONNECTION_STATUS)')
            ->and($snippet)->toContain('in_array($hostOf($connection->getPdo()), $local, true)')
            ->and($snippet)->toContain('in_array($hostOf($connection->getReadPdo()), $local, true)')
            ->and($snippet)->toContain('in_array($connection->getDriverName(), ["mysql", "mariadb"], true)')
            ->and($snippet)->toContain('! app()->environment("production", "prod", "staging")')
            ->and($snippet)->toContain('preg_match("/^\s*select\b/i", $sql) === 1')
            ->and($snippet)->toContain('preg_match("/;|\binto\b|\bfor\s+update\b|\block\s+in\s+share\s+mode\b/i", $sql) === 0')
            ->and(strpos($snippet, 'SET TRANSACTION READ ONLY'))->toBeLessThan((int) strpos($snippet, 'beginTransaction()'))
            ->and(strpos($snippet, 'beginTransaction()'))->toBeLessThan((int) strpos($snippet, '"EXPLAIN "'))
            ->and(strpos($snippet, '"EXPLAIN "'))->toBeLessThan((int) strpos($snippet, 'rollBack()'))
            ->and($reference)->toContain('an environment name alone does not prove a local database')
            ->and($reference)->toContain('A loopback port that a tunnel forwards to a shared server, and a hosts-file alias, look local.')
            ->and($reference)->toContain('**What this does not enforce.** The harness stops no other snippet')
            ->and($reference)->toContain('A snippet never prints, returns, or dumps a configuration or an environment value.')
            ->and($reference)->toContain('The agent never runs `php artisan config:show` and never reads a `.env*` file.');
    },
);

test('a refused or failed guard sends the run to the plain database client or to static analysis with the reason named (issue #334)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $reference = (string) file_get_contents($packageDir . '/skills/mysql-problem-solver/references/tinker-testing.md');
    $casey = (string) file_get_contents($packageDir . '/agents/casey.md');
    $orchestration = (string) file_get_contents($packageDir . '/rules/compound-engineering/orchestration.md');

    expect($reference)->toContain('It does not use Tinker, Laravel Boost, or Laravel Telescope for this query.')
        ->and($orchestration)->toContain('the Tinker, Laravel Boost, and Laravel Telescope steps run only after the guard of')
        ->and($reference)->toContain('It does not print which fact failed, and it does not retry with a relaxed snippet.')
        ->and($reference)->toContain('and static analysis otherwise.')
        ->and($reference)->toContain('`static analysis only — the local-database guard refused`')
        ->and($casey)->toContain('When the guard refuses, or the run cannot show a local database target, you use no Tinker, Boost, or Telescope step')
        ->and($casey)->toContain('a Unix socket, or a database service of the committed `docker-compose*.yml`')
        ->and($casey)->toContain('you never run `config:show`, a `config()` or `env()` dump, or any output of connection settings')
        ->and($casey)->toContain('nothing in the harness enforces them');
});

test(
    'casey names only the verified Boost tools, never the Boost tools that print configuration or run arbitrary code, and Codex maps them (issue #334)',
    function (): void {
        $packageDir = dirname(__DIR__, 2);
        $casey = (string) file_get_contents($packageDir . '/agents/casey.md');
        $codex = (string) file_get_contents($packageDir . '/codex/agents/casey.toml');
        $reference = (string) file_get_contents($packageDir . '/skills/mysql-problem-solver/references/tinker-testing.md');
    
        expect($casey)->toMatch('/^tools: Read, Glob, Grep, Bash, mcp__laravel-boost__database-query, mcp__laravel-boost__database-schema$/m')
            ->and($casey)->toMatch('/^disallowedTools: Write, Edit$/m')
            ->and($casey)->not->toMatch('/^tools:.*mcp__laravel-boost__(tinker|database-connections|record-rule)/m')
            ->and($reference)->toContain('`mcp__laravel-boost__database-query` and `mcp__laravel-boost__database-schema`')
            ->and($reference)->toContain('`src/Mcp/Tools/DatabaseQuery.php`')
            ->and($reference)->toContain('`src/Mcp/Tools/DatabaseSchema.php`')
            ->and($reference)->toContain(
                '`database-connections` returns `config(\'database.default\')` and the connection names, which are configuration values.',
            )
            ->and($reference)->toContain('`tinker` runs arbitrary PHP, and Boost registers it only when `boost.tinker_tool_enabled` is true.')
            ->and($reference)->toContain('It never calls `database-connections`, `tinker`, or `record-rule`')
            ->and($reference)->toContain('Boost is never required.')
            ->and($codex)->toContain(
                '`database-query` and `database-schema` tools of the `laravel-boost` MCP server when this session carries that server',
            )
            ->and($codex)->toContain('tests the MySQL queries through the local application\'s Tinker behind a local-database guard');
    },
);

test('Telescope is optional, follows its skill, reads one projection, and the report never quotes the recorded SQL (issue #334)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $reference = (string) file_get_contents($packageDir . '/skills/mysql-problem-solver/references/tinker-testing.md');

    expect($reference)->toContain('Telescope is never required.')
        ->and($reference)->toContain('**Follow `@skills/laravel-telescope/SKILL.md`.**')
        ->and($reference)->toContain(
            '->select(["created_at", "content->file as file", "content->line as line", "content->time as time_ms", "content->hash as hash"])',
        )
        ->and($reference)->not->toContain('->select(["content"')
        ->and($reference)->toContain(
            '**What the report may quote:** `file`, `line`, `time_ms`, `hash`, `created_at`, and the count of entries that share one `hash`.',
        )
        ->and($reference)->toContain(
            '**What the report never quotes:** the recorded `sql`, because Telescope replaces the bindings with their real values in it',
        )
        ->and($reference)->toContain('The agent never selects the `content` column as a whole.')
        ->and($reference)->toContain('the data comes from the local application, never from a production or unknown host');
});

test(
    'the report template opens with a TL;DR of sourced facts and names the access, and a report with no critical item has none (issue #334)',
    function (): void {
        $packageDir = dirname(__DIR__, 2);
        $template = (string) file_get_contents($packageDir . '/skills/mysql-problem-solver/templates/cr-report.md');
        $skill = (string) file_get_contents($packageDir . '/skills/mysql-problem-solver/SKILL.md');
    
        expect(strpos($template, '### TL;DR'))->toBeLessThan((int) strpos($template, '### 1. <one-sentence problem>'))
            ->and($template)->toContain('DB access: <how the facts were obtained: Tinker | Laravel Boost | Laravel Telescope | the plain database client')
            ->and($template)->toContain('joined with ` + ` when more than one gave facts | static analysis only — <reason>>')
            ->and($template)->toContain('  - **Facts:** <fact> (<source>); <fact> (<source>)')
            ->and($template)->toContain('  - **If left as it is:** <the consequence for this query on this path>')
            ->and($template)->toContain('  - **Plan today:** <which table the database reads;')
            ->and($template)->toContain('  - **Plan proposed:** <the same for the proposed query, so the reader sees the difference>')
            ->and($template)->toContain('- **Not verified:** <statement> — settled by <the step that settles it>')
            ->and($template)->toContain('the raw `EXPLAIN` output of the baseline or the stated limitation')
            ->and($template)->toContain('replace both Plan lines with one line: **Plan:** not read — the proposal rests on static analysis: <reason>.')
            ->and($template)->toContain('Never explain a plan the run did not read.')
            ->and($template)->toContain('replace the TL;DR and the items with: No critical database item in this diff.')
            ->and($skill)->toContain('**opens with a `TL;DR` that defends each proposal with facts.**')
            ->and($skill)->toContain('A statement with no such source goes under *Not verified* with the step that settles it')
            ->and($skill)->toContain('**explains the `EXPLAIN` result in plain language when `EXPLAIN` ran.**')
            ->and($skill)->toContain('and the report explains no plan.')
            ->and($skill)->toContain('answers `No critical database item in this diff.` when nothing qualifies. That answer carries no `TL;DR`.');
    },
);

test('the TL;DR reaches both places the report is published (issue #334)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $casey = (string) file_get_contents($packageDir . '/agents/casey.md');
    $reference = (string) file_get_contents($packageDir . '/skills/code-review/references/specialized-reviews.md');
    $commentTemplate = (string) file_get_contents($packageDir . '/skills/code-review-github/templates/pr-comment-output.md');

    expect($casey)->toContain(
        'Pipe the whole body, its `TL;DR` included, into `skills/code-review-github/scripts/upsert-comment.sh <PR-URL> - mysql-report`.',
    )
        ->and($casey)->toContain('with the full report and its `TL;DR`. The publishing `leonardo` renders it as the `## Database report` section')
        ->and($casey)->toContain('A report with no critical item keeps its one line and carries no `TL;DR`.')
        ->and($reference)->toContain('Both places carry the whole report, its `TL;DR` included.')
        ->and($reference)->toContain('A report with no critical item carries no `TL;DR`.')
        ->and($commentTemplate)->toContain('A report with a critical item opens with its `TL;DR`, and the render keeps it.');
});
