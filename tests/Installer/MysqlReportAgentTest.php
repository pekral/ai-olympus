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
