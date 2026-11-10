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

test('the report template pins the TL;DR heading before the first item heading (issue #334)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $template = (string) file_get_contents($packageDir . '/skills/mysql-problem-solver/templates/cr-report.md');

    expect(strpos($template, '### TL;DR'))->toBeLessThan((int) strpos($template, '### 1. <one-sentence problem>'));
});

test('the report template pins the full text of the line', function (string $text): void {
    $packageDir = dirname(__DIR__, 2);
    $template = (string) file_get_contents($packageDir . '/skills/mysql-problem-solver/templates/cr-report.md');

    expect($template)->toContain($text);
})->with([
    'the consequence line' => [
        '- **If left as it is:** <the consequence for this query on this path>',
    ],
    'the DB access value comment' => [
        '<!-- DB access names one value for the whole report: the plain database client when any fact of the '
            . 'report was read through it, otherwise static analysis only with its reason. -->',
    ],
    'the evidence line with the raw EXPLAIN output' => [
        '- **Evidence:** <reconstructed SQL or NoSQL call, the raw `EXPLAIN` output of the baseline or the '
            . 'stated limitation, table or key-space size when known>',
    ],
    'the facts line' => [
        '- **Facts:** <fact> (<source>); <fact> (<source>)',
    ],
    'the no-critical-item comment' => [
        '<!-- When no item qualifies in any part that ran, keep the heading, the advisory line, and the '
            . 'stores line, and replace the TL;DR and the items with: No critical database item in this diff. A '
            . 'PostgreSQL diff without a local instance adds one line: PostgreSQL query plan not analysed — DB '
            . 'access: static analysis only — <reason> -->',
    ],
    'the not verified line' => [
        '- **Not verified:** <statement> — settled by <the step that settles it>',
    ],
    'the plan proposed line' => [
        '- **Plan proposed:** <the same reading for the proposed query>',
    ],
    'the plan today line' => [
        '- **Plan today:** <the reading of the `EXPLAIN` output, one sentence per column, node, or detail '
            . 'line>',
    ],
    'the stores line with the DB access values' => [
        'Stores: <mysql | mariadb | pgsql | unresolved — the review\'s `Not verified:` entry; redis | mongodb '
            . '| dynamodb | elasticsearch | …> · Head: <short SHA> · DB access: <the plain database client | static '
            . 'analysis only — <reason>>',
    ],
    'the TL;DR drop and replace comment' => [
        '<!-- The TL;DR has one entry per item, in the item order. Drop the Plan proposed line when the '
            . 'proposal was not tested, and drop the Not verified line when nothing is unverified. When EXPLAIN did '
            . 'not run, replace both Plan lines with one line: **Plan:** not read — the proposal rests on static '
            . 'analysis: <reason> -->',
    ],
    'the TL;DR entry line' => [
        '- **1. <the proposal in one sentence>.**',
    ],
    'the TL;DR heading' => [
        '### TL;DR',
    ],
]);

test('the skill pins the full text of the TL;DR sentence', function (string $text): void {
    $packageDir = dirname(__DIR__, 2);
    $skill = (string) file_get_contents($packageDir . '/skills/mysql-problem-solver/SKILL.md');

    expect($skill)->toContain($text);
})->with([
    'a critical item gives the TL;DR, the template owns its shape' => [
        'A report with a critical item opens with it, and `templates/cr-report.md` owns its shape.',
    ],
    'a tested proposal gets the same reading' => [
        'Where the proposal was tested, it says the same for the proposed query.',
    ],
    'the DB access line and its owner' => [
        'The `DB access:` line of the report names how the facts were obtained, and `templates/cr-report.md` '
            . 'owns its values.',
    ],
    'the MySQL and MariaDB table size read' => [
        'On MySQL and MariaDB a table size is the `Cardinality` that `SHOW INDEX` prints for the `PRIMARY` '
            . 'index, on its row with the highest `Seq_in_index`, and the fact names it as an estimate; a table '
            . 'with no `PRIMARY` index has no table size.',
    ],
    'the no-critical-item answer carries no TL;DR' => [
        'That answer carries no `TL;DR`.',
    ],
    'the no-EXPLAIN line' => [
        'When `EXPLAIN` did not run, one line states that the proposal rests on static analysis and gives the '
            . 'reason, and the report explains no plan.',
    ],
    'the Not verified rule' => [
        'A statement with no such source goes under *Not verified* with the step that settles it '
            . '(`@rules/general/general.md` *Verified statements only*).',
    ],
    'the plain-language explanation content' => [
        'The `TL;DR` says which table the database reads, how it reads it, how many rows it examines against '
            . 'how many it keeps, and what an extra step such as a sort or a temporary table means for this query.',
    ],
    'the plain-language explanation heading rule' => [
        '**explains the `EXPLAIN` result in plain language when `EXPLAIN` ran.**',
    ],
    'the PostgreSQL table size rule' => [
        'On PostgreSQL no statement that `agents/casey.md` *Bash boundary* allows prints a table size, so a '
            . 'fact about the size of a PostgreSQL table is a value read from the `EXPLAIN` output and names that '
            . 'source.',
    ],
    'the raw output stays in the evidence' => [
        'The raw output stays in the item\'s evidence.',
    ],
    'the reading file owns the readings' => [
        '`references/explain-reading.md` owns which output forms get a reading, the reading of each MySQL / '
            . 'MariaDB column and value, and the reading of each PostgreSQL figure, node, and detail line.',
    ],
    'the three fact sources' => [
        'A fact names its source, and the only sources are a `file:line`, a value read from the `EXPLAIN` '
            . 'output, and a table size the run read.',
    ],
    'the TL;DR heading rule' => [
        '**opens with a `TL;DR` that defends each proposal with facts.**',
    ],
    'the TL;DR states proposal, facts, and consequence' => [
        'Per item it states the proposal, the facts that support it, and the consequence of leaving the code '
            . 'as it is.',
    ],
]);

test('the reading file pins the full text of the rule line', function (string $text): void {
    $packageDir = dirname(__DIR__, 2);
    $reading = (string) file_get_contents($packageDir . '/skills/mysql-problem-solver/references/explain-reading.md');

    expect($reading)->toContain($text);
})->with([
    'a detail line matches only when it starts with a row name and a colon' => [
        'A detail line matches a row of this table only when it starts with the row\'s name followed by `:`. '
            . 'Any other detail line is named and quoted raw with no reading, for example `Recheck Cond`, `Hash '
            . 'Cond`, `Sort Key`, `Heap Fetches`, `Planning`, or `Workers Planned`.',
    ],
    'a node matches only when its node type equals a row' => [
        'A node matches a row of this table only when its node type, as printed before `on`, `using`, or `(`, '
            . 'equals the row\'s value. Any other node is named and quoted raw with no reading, for example '
            . '`Parallel Seq Scan`, `Index Scan Backward`, `HashAggregate`, or `Memoize`.',
    ],
    'a value the file does not explain is quoted raw' => [
        '- **A value this file does not explain is named, and the report quotes it raw.** The matching rules '
            . 'of *The `Extra` column* and of *PostgreSQL nodes* decide whether a value is explained. Anything they '
            . 'do not match gets no reading.',
    ],
    'estimates stay estimates' => [
        '- **Estimates stay estimates.** `rows` and `filtered` on MySQL / MariaDB, and `cost`, `rows`, and '
            . '`width` on PostgreSQL, are estimates of the planner. The reading says *estimated*.',
    ],
    'Extra step 1, split at each semicolon' => [
        '1. Split the column at each `;`. Remove the white space at the start and at the end of each part.',
    ],
    'Extra step 2, an equal part matches' => [
        '- A part matches a row when it equals the row\'s value.',
    ],
    'Extra step 2, a parenthesised suffix for two values' => [
        '- A part also matches when the row\'s value is `Using join buffer` or `Using index condition`, and '
            . 'the part is that value followed by a text in parentheses, with or without a space before the '
            . 'parenthesis. The reading quotes the text in parentheses as it stands. Examples are `Using join '
            . 'buffer (hash join)` and `Using index condition(BKA)`.',
    ],
    'Extra step 2, match each part' => [
        '2. Match each part against the table below:',
    ],
    'Extra step 3, a part that matches no row is quoted raw' => [
        '3. Name each part that matches no row, and quote it raw with no reading. `Using index for skip scan` '
            . 'matches no row, because only an exact value or one of the two named values with a parenthesis '
            . 'matches.',
    ],
    'one reading per MySQL and MariaDB output row' => [
        'One reading per output row. A join prints one row per table, and the `table` column names the table '
            . 'of each reading.',
    ],
    'one reading per PostgreSQL plan node' => [
        'One reading per plan node. A node line starts with the node type, at the top or after `->`. The `on '
            . '<table>` and `using <index>` words name the table and the index of the reading as printed.',
    ],
    'one sentence states one value' => [
        '- **One sentence, one value.** Each sentence of a reading states what one named column, node, or '
            . 'field of the output shows. A sentence that no column, node, or field of the output supports is not '
            . 'written (`@rules/general/general.md` *Verified statements only*).',
    ],
    'output form 1, the MySQL and MariaDB table' => [
        '1. the table of plain `EXPLAIN` on MySQL and MariaDB;',
    ],
    'output form 2, the PostgreSQL plain text plan' => [
        '2. the text plan of plain `EXPLAIN` on PostgreSQL;',
    ],
    'output form 3, the PostgreSQL EXPLAIN ANALYZE text plan' => [
        '3. the text plan of `EXPLAIN ANALYZE` on PostgreSQL.',
    ],
    'the basis comparison date' => [
        'Each reading was compared on 2026-11-10 with these pages:',
    ],
    'the cost and the actual time figures are never compared' => [
        'The `cost` figures and the `actual time` figures have different units, so the reading never compares '
            . 'one with the other.',
    ],
    'the Extra column rule heading line' => [
        'Read the column with this rule:',
    ],
    'the file is the one owner of the reading and names the other two files' => [
        'This file is the one owner of the plain-language reading of an `EXPLAIN` result: the MySQL / MariaDB '
            . 'table and the PostgreSQL plan. `skills/mysql-problem-solver/SKILL.md` `MODE=cr-report` says when the '
            . '`TL;DR` carries a reading, and `skills/mysql-problem-solver/templates/cr-report.md` says where it '
            . 'goes. Neither restates the readings below.',
    ],
    'the MariaDB basis page' => [
        '- MariaDB: the *EXPLAIN* page of the MariaDB documentation '
            . '(`server/reference/sql-statements/administrative-sql-statements/analyze-and-explain-statements/explain.md` '
            . 'in the repository `mariadb-corporation/mariadb-docs`). It is the source of the `;` separator, of '
            . '`Using index condition(BKA)`, and of the `filtered` column that plain `EXPLAIN` omits.',
    ],
    'the MySQL basis pages' => [
        '- MySQL: the MySQL 8.4 Reference Manual, *EXPLAIN Output Format* '
            . '(https://dev.mysql.com/doc/refman/8.4/en/explain-output.html), the column descriptions and the '
            . 'sections *EXPLAIN Join Types*, *EXPLAIN Extra Information*, and *EXPLAIN Output Interpretation*; and '
            . '*SHOW INDEX Statement* (https://dev.mysql.com/doc/refman/8.4/en/show-index.html).',
    ],
    'the output forms quoted raw' => [
        '- **The output forms quoted raw.** It gives no reading for `EXPLAIN FORMAT=JSON` or `FORMAT=TREE`, '
            . 'for `EXPLAIN ANALYZE` on MySQL, for MariaDB `ANALYZE`, or for PostgreSQL `FORMAT JSON`, `YAML`, or '
            . '`XML`. For such output the `TL;DR` quotes the figures as they stand and gives no reading.',
    ],
    'the output forms the file reads' => [
        '- **The output forms this file reads.** It reads the output that `agents/casey.md` *Bash boundary* '
            . 'lets `casey` run:',
    ],
    'the PostgreSQL basis heading' => [
        '- PostgreSQL: the PostgreSQL 18 documentation:',
    ],
    'the PostgreSQL EXPLAIN page' => [
        '- *EXPLAIN* (https://www.postgresql.org/docs/18/sql-explain.html).',
    ],
    'the PostgreSQL index-only scans page' => [
        '- *11.9. Index-Only Scans and Covering Indexes* '
            . '(https://www.postgresql.org/docs/18/indexes-index-only-scans.html);',
    ],
    'the PostgreSQL Using EXPLAIN page' => [
        '- *14.1. Using EXPLAIN* (https://www.postgresql.org/docs/18/using-explain.html), sections *14.1.1 '
            . 'EXPLAIN Basics*, *14.1.2 EXPLAIN ANALYZE*, and *14.1.3 Caveats*;',
    ],
    'the type value matches only when it equals a row exactly' => [
        'The `type` column holds one value. A value matches a row of this table only when it equals the value '
            . 'exactly.',
    ],
]);

test('the reading file pins the full row of the MySQL and MariaDB column', function (string $text): void {
    $packageDir = dirname(__DIR__, 2);
    $reading = (string) file_get_contents($packageDir . '/skills/mysql-problem-solver/references/explain-reading.md');

    expect($reading)->toContain($text);
})->with([
    'Extra' => [
        '| `Extra` | The extra steps of this row. See *The `Extra` column*. |',
    ],
    'filtered' => [
        '| `filtered` | The estimated percentage of the table\'s rows that the table condition keeps; `100` '
            . 'means that the condition removes no row. `rows` × `filtered` ÷ 100 is the estimated number of rows '
            . 'joined with the following table. When the output has no `filtered` column (plain `EXPLAIN` on '
            . 'MariaDB), the reading gives no figure for the rows kept and says that the plan carries none. |',
    ],
    'key' => [
        '| `key` | The index the database decided to use. A `key` of `NULL` means it found no index to use, '
            . 'and the reading names none. |',
    ],
    'rows' => [
        '| `rows` | The estimated number of rows the database examines for this table. The product of the '
            . '`rows` values of all rows of the output roughly gives the number of rows the whole query examines. |',
    ],
    'table' => [
        '| `table` | The table this row of the output refers to. |',
    ],
    'type' => [
        '| `type` | How the database finds rows in that table. See *The `type` values*. |',
    ],
]);

test('the reading file pins the full row of the `type` value', function (string $text): void {
    $packageDir = dirname(__DIR__, 2);
    $reading = (string) file_get_contents($packageDir . '/skills/mysql-problem-solver/references/explain-reading.md');

    expect($reading)->toContain($text);
})->with([
    'ALL' => [
        '| `ALL` | The database reads every row of the table, once for each combination of rows from the '
            . 'tables before it. |',
    ],
    'const' => [
        '| `const` | At most one row of the table matches, and the database reads it once, at the start of '
            . 'the query. MySQL uses it when the query compares all parts of a primary key or a unique index with '
            . 'constant values. |',
    ],
    'eq_ref' => [
        '| `eq_ref` | The database reads one row of this table for each combination of rows from the tables '
            . 'before it, through all parts of a primary key or a `UNIQUE NOT NULL` index. |',
    ],
    'fulltext' => [
        '| `fulltext` | The database uses a `FULLTEXT` index. |',
    ],
    'index' => [
        '| `index` | The database reads the whole index in `key`. With `Using index` in `Extra`, it reads '
            . 'only the index. Without it, it also reads every table row in the order of the index. |',
    ],
    'index_merge' => [
        '| `index_merge` | The database uses the Index Merge optimization: it reads ranges of several indexes '
            . 'of this table and merges the rows it finds. `key` lists the indexes. |',
    ],
    'index_subquery' => [
        '| `index_subquery` | Like `unique_subquery`, through an index that is not unique. |',
    ],
    'range' => [
        '| `range` | The database reads only the rows in a given range, through the index in `key`. |',
    ],
    'ref' => [
        '| `ref` | The database reads all rows with matching index values from this table, for each '
            . 'combination of rows from the tables before it. The index is used through a leftmost prefix, or it is '
            . 'not a primary key or unique index. |',
    ],
    'ref_or_null' => [
        '| `ref_or_null` | Like `ref`, with an extra search for rows that contain `NULL`. |',
    ],
    'system' => [
        '| `system` | The table has at most one row. |',
    ],
    'unique_subquery' => [
        '| `unique_subquery` | An `IN (SELECT …)` subquery runs as a lookup through a primary key, in place '
            . 'of the subquery. |',
    ],
]);

test('the reading file pins the full row of the `Extra` value', function (string $text): void {
    $packageDir = dirname(__DIR__, 2);
    $reading = (string) file_get_contents($packageDir . '/skills/mysql-problem-solver/references/explain-reading.md');

    expect($reading)->toContain($text);
})->with([
    'Using filesort' => [
        '| `Using filesort` | The database does an extra pass to retrieve the rows in sorted order. |',
    ],
    'Using index' => [
        '| `Using index` | The database reads the column values from the index alone, without reading the '
            . 'table row. |',
    ],
    'Using index condition' => [
        '| `Using index condition` | The database reads index entries and tests the condition on them first, '
            . 'to decide whether to read the full table row. |',
    ],
    'Using index for group-by' => [
        '| `Using index for group-by` | The database reads all columns of the `GROUP BY` or `DISTINCT` from '
            . 'an index, without reading the table. |',
    ],
    'Using join buffer' => [
        '| `Using join buffer` | The database reads the rows of the earlier tables into the join buffer, in '
            . 'portions, and joins this table against the buffered rows. |',
    ],
    'Using temporary' => [
        '| `Using temporary` | The database creates a temporary table to hold the result. |',
    ],
    'Using where' => [
        '| `Using where` | A `WHERE` condition restricts which rows are matched against the next table or '
            . 'sent to the client. |',
    ],
]);

test('the reading file pins the full row of the PostgreSQL figure', function (string $text): void {
    $packageDir = dirname(__DIR__, 2);
    $reading = (string) file_get_contents($packageDir . '/skills/mysql-problem-solver/references/explain-reading.md');

    expect($reading)->toContain($text);
})->with([
    '`actual time=<start>..<total>`' => [
        '| `actual time=<start>..<total>` | `EXPLAIN ANALYZE` only. The measured start-up time and total time '
            . 'of the node, in milliseconds of real time, averaged per execution of the node. |',
    ],
    '`cost=<start>..<total>`' => [
        '| `cost=<start>..<total>` | The estimated start-up cost, spent before the output phase of the node '
            . 'can begin, and the estimated total cost, if the node runs to completion. The unit is the planner\'s '
            . 'arbitrary cost unit, not time. The cost of a node includes the cost of all its child nodes. |',
    ],
    '`loops=<n>`' => [
        '| `loops=<n>` | `EXPLAIN ANALYZE` only. How many times the node ran. The total time and rows of the '
            . 'node are the averages × `loops`. |',
    ],
    '`rows=<n>`' => [
        '| `rows=<n>` | The estimated number of rows the node outputs, if it runs to completion. |',
    ],
    '`rows=<n>` after `actual time`' => [
        '| `rows=<n>` after `actual time` | `EXPLAIN ANALYZE` only. The measured rows the node output, '
            . 'averaged per execution of the node. |',
    ],
    '`width=<n>`' => [
        '| `width=<n>` | The estimated average width of an output row, in bytes. |',
    ],
]);

test('the reading file pins the full row of the PostgreSQL node', function (string $text): void {
    $packageDir = dirname(__DIR__, 2);
    $reading = (string) file_get_contents($packageDir . '/skills/mysql-problem-solver/references/explain-reading.md');

    expect($reading)->toContain($text);
})->with([
    'Bitmap Heap Scan' => [
        '| `Bitmap Heap Scan` | The database sorts the row locations that its child node found into physical '
            . 'order and fetches those rows from the table. |',
    ],
    'Bitmap Index Scan' => [
        '| `Bitmap Index Scan` | The database visits the index to find the locations of the rows that match '
            . 'the index condition. |',
    ],
    'Hash' => [
        '| `Hash` | The database enters the rows of its child node into an in-memory hash table, for the join '
            . 'node above it. |',
    ],
    'Hash Join' => [
        '| `Hash Join` | The database scans the other input and probes the hash table for matches to each '
            . 'row. |',
    ],
    'Incremental Sort' => [
        '| `Incremental Sort` | The database sorts the rows in batches, and it can return rows before the '
            . 'whole result is sorted. |',
    ],
    'Index Only Scan' => [
        '| `Index Only Scan` | The database answers from the index named after `using`. It reads the table '
            . 'only for rows on pages that the visibility map does not mark as visible to all transactions. |',
    ],
    'Index Scan' => [
        '| `Index Scan` | The database fetches the table rows in the order of the index named after `using`. '
            . '|',
    ],
    'Limit' => [
        '| `Limit` | The database stops requesting rows from its child node at the limit. The child\'s '
            . 'estimated cost and rows are shown as if it ran to completion. |',
    ],
    'Materialize' => [
        '| `Materialize` | The database saves the rows of its child node in memory as it reads them and '
            . 'returns them from memory on each later pass. |',
    ],
    'Merge Join' => [
        '| `Merge Join` | The database joins two inputs that are sorted on the join keys. |',
    ],
    'Nested Loop' => [
        '| `Nested Loop` | The database runs the second (inner) child once for each row of the first (outer) '
            . 'child. |',
    ],
    'Seq Scan' => [
        '| `Seq Scan` | The database reads all rows of the table in sequence. |',
    ],
    'Sort' => [
        '| `Sort` | The database sorts the rows in an explicit step. |',
    ],
]);

test('the reading file pins the full row of the PostgreSQL detail line', function (string $text): void {
    $packageDir = dirname(__DIR__, 2);
    $reading = (string) file_get_contents($packageDir . '/skills/mysql-problem-solver/references/explain-reading.md');

    expect($reading)->toContain($text);
})->with([
    'Buffers' => [
        '| `Buffers` | The count of buffers hit, read, dirtied, and written by the node and all its child '
            . 'nodes. These are not distinct buffers. On PostgreSQL 18, `EXPLAIN ANALYZE` includes this line on its '
            . 'own; plain `EXPLAIN` without the `BUFFERS` option, which is the form `casey` runs, does not. |',
    ],
    'Execution Time' => [
        '| `Execution Time` | `EXPLAIN ANALYZE` only. The time to run the query, including executor start-up '
            . 'and shut-down and any triggers fired, without parsing, rewriting, and planning. |',
    ],
    'Filter' => [
        '| `Filter` | The node checks this condition for each row it scans and outputs only the rows that '
            . 'pass. |',
    ],
    'Index Cond' => [
        '| `Index Cond` | The condition that the node applies through the index. |',
    ],
    'Planning Time' => [
        '| `Planning Time` | `EXPLAIN ANALYZE` only. The time the planner took to generate and optimize the '
            . 'plan, without parsing and rewriting. |',
    ],
    'Rows Removed by Filter' => [
        '| `Rows Removed by Filter` | The scanned rows, or the potential join pairs of a join node, that the '
            . 'filter condition rejected. The line appears only when the condition rejected at least one. The '
            . 'documentation shows it in `EXPLAIN ANALYZE` output. |',
    ],
    'Rows Removed by Index Recheck' => [
        '| `Rows Removed by Index Recheck` | The candidate rows that the index returned and that a recheck of '
            . 'the index condition rejected. The documentation shows it in `EXPLAIN ANALYZE` output. |',
    ],
    'Sort Method' => [
        '| `Sort Method` | Shown by `EXPLAIN ANALYZE`. The sort method used, including whether the sort ran '
            . 'in memory or on disk, and the memory or disk space it needed. |',
    ],
]);

test(
    'the reading file pins the absence of the removed wording: one pass, the whole query examines, GROUP '
        . 'BY naming, a full index scan as no lookup, the old PostgreSQL exclusion, and the Hash Join parent',
    function (): void {
        $packageDir = dirname(__DIR__, 2);
        $reading = (string) file_get_contents($packageDir . '/skills/mysql-problem-solver/references/explain-reading.md');

        expect($reading)->not->toContain('in one pass')
            ->and($reading)->not->toContain('that is the number of rows the whole query examines')
            ->and($reading)->not->toContain('The reading can name `GROUP BY`')
            ->and($reading)->not->toContain('It is a full scan of the index, not a lookup.')
            ->and($reading)->not->toContain('or a PostgreSQL plan. For such output')
            ->and($reading)->not->toContain('for the `Hash Join` above it');
    },
);

test('the template carries the DB access values and no other casey file or the skill or the reading file carries them (issue #334)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $template = (string) file_get_contents($packageDir . '/skills/mysql-problem-solver/templates/cr-report.md');
    $skill = (string) file_get_contents($packageDir . '/skills/mysql-problem-solver/SKILL.md');
    $reading = (string) file_get_contents($packageDir . '/skills/mysql-problem-solver/references/explain-reading.md');
    $casey = (string) file_get_contents($packageDir . '/agents/casey.md');

    expect($template)->toContain('DB access: <the plain database client | static analysis only — <reason>>')
        ->and(substr_count($casey . $skill . $reading, 'the plain database client |'))->toBe(0);
});

test('the casey handoff points to the DB access line of the report (issue #334)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $casey = (string) file_get_contents($packageDir . '/agents/casey.md');

    expect($casey)->toContain('the value of the `DB access:` line of the report');
});

test('the fact-source sentence appears in the skill and in no template, reading file, or other casey file (issue #334)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $skill = (string) file_get_contents($packageDir . '/skills/mysql-problem-solver/SKILL.md');
    $others = implode("\n", array_map(
        static fn (string $file): string => (string) file_get_contents($packageDir . '/' . $file),
        [
            'agents/casey.md',
            'codex/agents/casey.toml',
            'skills/code-review/references/specialized-reviews.md',
            'skills/code-review-github/templates/pr-comment-output.md',
            'docs/agents.md',
            'skills/mysql-problem-solver/templates/cr-report.md',
            'skills/mysql-problem-solver/references/explain-reading.md',
        ],
    ));

    expect($skill)->toContain('a table size the run read')
        ->and(substr_count($others, 'a table size the run read'))->toBe(0);
});

test(
    'the plan readings `Using filesort` and `index_merge` appear in the reading file and in no skill, template, or casey file (issue #334)',
    function (): void {
        $packageDir = dirname(__DIR__, 2);
        $reading = (string) file_get_contents($packageDir . '/skills/mysql-problem-solver/references/explain-reading.md');
        $others = implode("\n", array_map(
            static fn (string $file): string => (string) file_get_contents($packageDir . '/' . $file),
            [
                'agents/casey.md',
                'codex/agents/casey.toml',
                'skills/code-review/references/specialized-reviews.md',
                'skills/code-review-github/templates/pr-comment-output.md',
                'docs/agents.md',
                'skills/mysql-problem-solver/templates/cr-report.md',
                'skills/mysql-problem-solver/SKILL.md',
            ],
        ));

        expect($reading)->toContain('`Using filesort`')
            ->and($reading)->toContain('`index_merge`')
            ->and(substr_count($others, '`Using filesort`'))->toBe(0)
            ->and(substr_count($others, '`index_merge`'))->toBe(0);
    },
);

test(
    'the TL;DR pins both places the report is published, the publish and render instructions, and the two ownership sentences (issue #334)',
    function (): void {
        $packageDir = dirname(__DIR__, 2);
        $casey = (string) file_get_contents($packageDir . '/agents/casey.md');
        $reference = (string) file_get_contents($packageDir . '/skills/code-review/references/specialized-reviews.md');
        $commentTemplate = (string) file_get_contents($packageDir . '/skills/code-review-github/templates/pr-comment-output.md');

        expect($casey)->toContain(
            'Pipe the whole body, its `TL;DR` included, into `skills/code-review-github/scripts/upsert-comment.sh <PR-URL> - mysql-report`.',
        )
            ->and($casey)->toContain('with the full report and its `TL;DR`. The publishing `leonardo` renders it as the `## Database report` section')
            ->and($casey)->toContain('and `skills/mysql-problem-solver/SKILL.md` `MODE=cr-report` owns when the report carries a `TL;DR`.')
            ->and($reference)->toContain('Both places carry the whole report, its `TL;DR` included.')
            ->and($reference)->toContain(
                '- **The `TL;DR`.** `skills/mysql-problem-solver/SKILL.md` `MODE=cr-report` owns when the report carries a '
                    . '`TL;DR` and its content rules, and `skills/mysql-problem-solver/templates/cr-report.md` owns its shape.',
            )
            ->and($commentTemplate)->toContain(
                '> Render the `casey` report verbatim, its `TL;DR` included, whenever the caller passes one with the status `Database report done`.',
            );
    },
);

test('the rule of when a report carries a TL;DR stays in its owner and is pinned absent from the other files (issue #334)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $skill = (string) file_get_contents($packageDir . '/skills/mysql-problem-solver/SKILL.md');
    $others = implode("\n", array_map(
        static fn (string $file): string => (string) file_get_contents($packageDir . '/' . $file),
        [
            'agents/casey.md',
            'codex/agents/casey.toml',
            'skills/code-review/references/specialized-reviews.md',
            'skills/code-review-github/templates/pr-comment-output.md',
            'docs/agents.md',
        ],
    ));

    expect($skill)->toContain('That answer carries no `TL;DR`.')
        ->and($others)->not->toContain('carries no `TL;DR`')
        ->and($others)->not->toContain('opens with the `TL;DR`')
        ->and($others)->not->toContain('opens with its `TL;DR`')
        ->and($others)->not->toContain('opens with a `TL;DR`')
        ->and($others)->not->toContain('opens with one');
});

test(
    'the Codex description pins the TL;DR sentence and the delegation to the Claude definition, and the '
        . 'Claude definition pins its matching sentence (issue #334)',
    function (): void {
        $packageDir = dirname(__DIR__, 2);
        $codex = (string) file_get_contents($packageDir . '/codex/agents/casey.toml');
        $casey = (string) file_get_contents($packageDir . '/agents/casey.md');

        expect($codex)->toContain(
            'Its report follows mysql-problem-solver MODE=cr-report, including the fact-based TL;DR and the plain-language reading of the EXPLAIN result.',
        )
            ->and($codex)->toContain('First read `.codex/agent-instructions/casey.md` in full')
            ->and($casey)->toContain('the `TL;DR`, and the plain-language reading of the plan.');
    },
);

test(
    'the casey tools line, the statements its Bash boundary allows, the casey consent row, and the number '
        . 'of casey consent rows match master (issue #334, criterion 7)',
    function (): void {
        $packageDir = dirname(__DIR__, 2);
        $casey = (string) file_get_contents($packageDir . '/agents/casey.md');
        $orchestration = (string) file_get_contents($packageDir . '/rules/compound-engineering/orchestration.md');

        expect($casey)->toMatch('/^tools: Read, Glob, Grep, Bash$/m')
            ->and($casey)->toContain(
                'Bash is granted to load the context, run plain `EXPLAIN` / `SHOW CREATE TABLE` / `SHOW INDEX` statements '
                    . '(MySQL / MariaDB) and plain `EXPLAIN` statements (PostgreSQL) — and `EXPLAIN ANALYZE` on a `SELECT` only, '
                    . 'because it executes the statement, inside `START TRANSACTION READ ONLY; … ROLLBACK;` (MySQL / MariaDB) or '
                    . '`BEGIN TRANSACTION READ ONLY; … ROLLBACK;` (PostgreSQL), and never on a `SELECT` that calls a stored or '
                    . 'non-aggregate function, which gets plain `EXPLAIN`',
            )
            ->and($orchestration)->toContain(
                '| `casey` | Run plain `EXPLAIN` / `SHOW` (and `EXPLAIN ANALYZE` on a `SELECT` only) against a local MySQL / '
                    . 'MariaDB or PostgreSQL database | L1 | `agents/casey.md` *Bash boundary* — a locally started database only; '
                    . 'never a shared test, staging, or production host, and never a statement that writes data or schema |',
            )
            ->and(substr_count($orchestration, '| `casey` |'))->toBe(2);
    },
);
