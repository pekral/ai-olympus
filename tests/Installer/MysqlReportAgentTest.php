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

test('the report template pins a TL;DR before the first item with facts, consequence, plan, and not-verified lines (issue #334)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $template = (string) file_get_contents($packageDir . '/skills/mysql-problem-solver/templates/cr-report.md');

    expect(strpos($template, '### TL;DR'))->toBeLessThan((int) strpos($template, '### 1. <one-sentence problem>'))
        ->and($template)->toContain('  - **Facts:** <fact> (<source>); <fact> (<source>)')
        ->and($template)->toContain('  - **If left as it is:** <the consequence for this query on this path>')
        ->and($template)->toContain('  - **Plan today:** <the reading of the `EXPLAIN` output, one sentence per column, node, or detail line>')
        ->and($template)->toContain('  - **Plan proposed:** <the same reading for the proposed query>')
        ->and($template)->toContain('- **Not verified:** <statement> — settled by <the step that settles it>')
        ->and($template)->toContain('the raw `EXPLAIN` output of the baseline or the stated limitation')
        ->and($template)->toContain('Drop the Plan proposed line when the proposal was not tested')
        ->and($template)->toContain('replace both Plan lines with one line: **Plan:** not read — the proposal rests on static analysis: <reason>')
        ->and($template)->toContain('replace the TL;DR and the items with: No critical database item in this diff.');
});

test(
    'the skill pins the TL;DR content rules: three fact sources, a Not verified rule, and no TL;DR without a critical item (issue #334)',
    function (): void {
        $packageDir = dirname(__DIR__, 2);
        $skill = (string) file_get_contents($packageDir . '/skills/mysql-problem-solver/SKILL.md');

        expect($skill)->toContain('**opens with a `TL;DR` that defends each proposal with facts.**')
            ->and($skill)->toContain('the only sources are a `file:line`, a value read from the `EXPLAIN` output, and a table size the run read.')
            ->and($skill)->toContain('A statement with no such source goes under *Not verified* with the step that settles it')
            ->and($skill)->toContain(
                'On MySQL and MariaDB a table size is the `Cardinality` that `SHOW INDEX` prints for the `PRIMARY` index, on '
                    . 'its row with the highest `Seq_in_index`, and the fact names it as an estimate',
            )
            ->and($skill)->toContain('On PostgreSQL no statement that `agents/casey.md` *Bash boundary* allows prints a table size')
            ->and($skill)->toContain('**explains the `EXPLAIN` result in plain language when `EXPLAIN` ran.**')
            ->and($skill)->toContain('When `EXPLAIN` did not run, one line states that the proposal rests on static analysis and gives the reason')
            ->and($skill)->toContain('and the report explains no plan.')
            ->and($skill)->toContain('answers `No critical database item in this diff.` when nothing qualifies. That answer carries no `TL;DR`.');
    },
);

test('the access values stay in the template, and the fact sources and plan readings stay out of the other files (issue #334)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $others = array_map(
        static fn (string $file): string => (string) file_get_contents($packageDir . '/' . $file),
        [
            'agents/casey.md',
            'codex/agents/casey.toml',
            'skills/code-review/references/specialized-reviews.md',
            'skills/code-review-github/templates/pr-comment-output.md',
            'docs/agents.md',
        ],
    );
    $template = (string) file_get_contents($packageDir . '/skills/mysql-problem-solver/templates/cr-report.md');
    $skill = (string) file_get_contents($packageDir . '/skills/mysql-problem-solver/SKILL.md');
    $reading = (string) file_get_contents($packageDir . '/skills/mysql-problem-solver/references/explain-reading.md');
    $corpus = implode("\n", $others);

    expect($template)->toContain('DB access: <the plain database client | static analysis only — <reason>>')
        ->and(substr_count($corpus . $skill . $reading, 'the plain database client |'))->toBe(0)
        ->and(substr_count($corpus . $template . $reading, 'a table size the run read'))->toBe(0)
        ->and(substr_count($corpus . $template . $skill, '`Using filesort`'))->toBe(0)
        ->and(substr_count($corpus . $template . $skill, '`index_merge`'))->toBe(0)
        ->and((string) file_get_contents($packageDir . '/agents/casey.md'))
        ->toContain('the value of the `DB access:` line of the report');
});

test(
    'the reading file pins one sentence per value, the output forms read and quoted raw, and the rule for an unexplained value (issue #334)',
    function (): void {
        $packageDir = dirname(__DIR__, 2);
        $reading = (string) file_get_contents($packageDir . '/skills/mysql-problem-solver/references/explain-reading.md');

        expect($reading)->toContain(
            '**One sentence, one value.** Each sentence of a reading states what one named column, node, or field of the output shows.',
        )
            ->and($reading)->toContain('1. the table of plain `EXPLAIN` on MySQL and MariaDB;')
            ->and($reading)->toContain('2. the text plan of plain `EXPLAIN` on PostgreSQL;')
            ->and($reading)->toContain('3. the text plan of `EXPLAIN ANALYZE` on PostgreSQL.')
            ->and($reading)->toContain('or for PostgreSQL `FORMAT JSON`, `YAML`, or `XML`.')
            ->and($reading)->not->toContain('or a PostgreSQL plan. For such output')
            ->and($reading)->toContain('**A value this file does not explain is named, and the report quotes it raw.**')
            ->and($reading)->toContain('A `key` of `NULL` means it found no index to use, and the reading names none.')
            ->and($reading)->toContain('`rows` × `filtered` ÷ 100 is the estimated number of rows joined with the following table.')
            ->and($reading)->toContain(
                'When the output has no `filtered` column (plain `EXPLAIN` on MariaDB), the reading gives no figure for the rows kept',
            )
            ->and($reading)->toContain('Any other node is named and quoted raw with no reading')
            ->and($reading)->toContain('Any other detail line is named and quoted raw with no reading');
    },
);

test(
    'the reading file pins the corrected index, rows, join buffer, and temporary readings and drops the wording the manual does not support (issue #334)',
    function (): void {
        $packageDir = dirname(__DIR__, 2);
        $reading = (string) file_get_contents($packageDir . '/skills/mysql-problem-solver/references/explain-reading.md');

        expect($reading)->toContain(
            '| `index` | The database reads the whole index in `key`. With `Using index` in `Extra`, it reads only the '
                . 'index. Without it, it also reads every table row in the order of the index. |',
        )
            ->and($reading)->toContain(
                'The product of the `rows` values of all rows of the output roughly gives the number of rows the whole query examines.',
            )
            ->and($reading)->toContain('in portions, and joins this table against the buffered rows.')
            ->and($reading)->toContain('| `Using temporary` | The database creates a temporary table to hold the result. |')
            ->and($reading)->not->toContain('in one pass')
            ->and($reading)->not->toContain('that is the number of rows the whole query examines')
            ->and($reading)->not->toContain('The reading can name `GROUP BY`')
            ->and($reading)->not->toContain('It is a full scan of the index, not a lookup.');
    },
);

test(
    'the reading file pins the Extra column rule: split at each semicolon, a parenthesised suffix for two values, raw for the rest (issue #334)',
    function (): void {
        $packageDir = dirname(__DIR__, 2);
        $reading = (string) file_get_contents($packageDir . '/skills/mysql-problem-solver/references/explain-reading.md');

        expect($reading)->toContain('1. Split the column at each `;`. Remove the white space at the start and at the end of each part.')
            ->and($reading)->toContain('with or without a space before the parenthesis. The reading quotes the text in parentheses as it stands.')
            ->and($reading)->toContain('`Using index for skip scan` matches no row');
    },
);

test('the reading file pins the PostgreSQL figures, the cost and time unit rule, and the documentation basis (issue #334)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $reading = (string) file_get_contents($packageDir . '/skills/mysql-problem-solver/references/explain-reading.md');

    expect($reading)->toContain('| `cost=<start>..<total>` |')
        ->and($reading)->toContain('| `width=<n>` |')
        ->and($reading)->toContain('| `loops=<n>` |')
        ->and($reading)->toContain(
            'The `cost` figures and the `actual time` figures have different units, so the reading never compares one with the other.',
        )
        ->and($reading)->toContain('https://dev.mysql.com/doc/refman/8.4/en/explain-output.html')
        ->and($reading)->toContain('https://www.postgresql.org/docs/18/using-explain.html');
});

test('the reading file pins one reading row for the PostgreSQL node', function (string $node): void {
    $packageDir = dirname(__DIR__, 2);
    $reading = (string) file_get_contents($packageDir . '/skills/mysql-problem-solver/references/explain-reading.md');

    expect(substr_count($reading, '| `' . $node . '` |'))->toBe(1);
})->with([
    'Seq Scan',
    'Index Scan',
    'Index Only Scan',
    'Bitmap Index Scan',
    'Bitmap Heap Scan',
    'Sort',
    'Incremental Sort',
    'Hash',
    'Hash Join',
    'Nested Loop',
    'Merge Join',
    'Materialize',
    'Limit',
]);

test('the reading file pins one reading row for the PostgreSQL detail line', function (string $line): void {
    $packageDir = dirname(__DIR__, 2);
    $reading = (string) file_get_contents($packageDir . '/skills/mysql-problem-solver/references/explain-reading.md');

    expect(substr_count($reading, '| `' . $line . '` |'))->toBe(1);
})->with([
    'Filter',
    'Index Cond',
    'Rows Removed by Filter',
    'Rows Removed by Index Recheck',
    'Sort Method',
    'Buffers',
    'Planning Time',
    'Execution Time',
]);

test('the reading file pins one reading row for the EXPLAIN type value', function (string $type): void {
    $packageDir = dirname(__DIR__, 2);
    $reading = (string) file_get_contents($packageDir . '/skills/mysql-problem-solver/references/explain-reading.md');

    expect(substr_count($reading, '| `' . $type . '` |'))->toBe(1);
})->with(['system', 'const', 'eq_ref', 'ref', 'fulltext', 'ref_or_null', 'index_merge', 'unique_subquery', 'index_subquery', 'range', 'index', 'ALL']);

test('the reading file pins a reading row for the Extra value', function (string $extra): void {
    $packageDir = dirname(__DIR__, 2);
    $reading = (string) file_get_contents($packageDir . '/skills/mysql-problem-solver/references/explain-reading.md');

    expect(substr_count($reading, '| `' . $extra . '` |'))->toBe(1);
})->with(['Using filesort', 'Using temporary', 'Using index', 'Using where', 'Using index condition', 'Using join buffer', 'Using index for group-by']);

test('the TL;DR pins both places the report is published and the publish and render instructions (issue #334)', function (): void {
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
});

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

test('the Codex description pins the same TL;DR behaviour as the Claude definition (issue #334)', function (): void {
    $packageDir = dirname(__DIR__, 2);
    $codex = (string) file_get_contents($packageDir . '/codex/agents/casey.toml');
    $casey = (string) file_get_contents($packageDir . '/agents/casey.md');

    expect($codex)->toContain(
        'Its report follows mysql-problem-solver MODE=cr-report, including the fact-based TL;DR and the plain-language reading of the EXPLAIN result.',
    )
        ->and($codex)->toContain('First read `.codex/agent-instructions/casey.md` in full')
        ->and($casey)->toContain('the `TL;DR`, and the plain-language reading of the plan.');
});

test('the casey tools line, the statements its Bash boundary allows, and the casey consent row match master (issue #334, criterion 7)', function (): void {
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
});
