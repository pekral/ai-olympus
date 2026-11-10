# Reading an `EXPLAIN` result in plain language (`MODE=cr-report`)

This file is the one owner of the plain-language reading of an `EXPLAIN` result: the MySQL / MariaDB table and the PostgreSQL plan. `skills/mysql-problem-solver/SKILL.md` `MODE=cr-report` says when the `TL;DR` carries a reading, and `skills/mysql-problem-solver/templates/cr-report.md` says where it goes. Neither restates the readings below.

## Rules

- **One sentence, one value.** Each sentence of a reading states what one named column, node, or field of the output shows. A sentence that no column, node, or field of the output supports is not written (`@rules/general/general.md` *Verified statements only*).
- **The output forms this file reads.** It reads the output that `agents/casey.md` *Bash boundary* lets `casey` run:
  1. the table of plain `EXPLAIN` on MySQL and MariaDB;
  2. the text plan of plain `EXPLAIN` on PostgreSQL;
  3. the text plan of `EXPLAIN ANALYZE` on PostgreSQL.
- **The output forms quoted raw.** It gives no reading for `EXPLAIN FORMAT=JSON` or `FORMAT=TREE`, for `EXPLAIN ANALYZE` on MySQL, for MariaDB `ANALYZE`, or for PostgreSQL `FORMAT JSON`, `YAML`, or `XML`. For such output the `TL;DR` quotes the figures as they stand and gives no reading.
- **A value this file does not explain is named, and the report quotes it raw.** The matching rules of *The `Extra` column* and of *PostgreSQL nodes* decide whether a value is explained. Anything they do not match gets no reading.
- **Estimates stay estimates.** `rows` and `filtered` on MySQL / MariaDB, and `cost`, `rows`, and `width` on PostgreSQL, are estimates of the planner. The reading says *estimated*.

## MySQL and MariaDB

One reading per output row. A join prints one row per table, and the `table` column names the table of each reading.

### The columns

| Column | Reading |
|---|---|
| `table` | The table this row of the output refers to. |
| `type` | How the database finds rows in that table. See *The `type` values*. |
| `key` | The index the database decided to use. A `key` of `NULL` means it found no index to use, and the reading names none. |
| `rows` | The estimated number of rows the database examines for this table. The product of the `rows` values of all rows of the output roughly gives the number of rows the whole query examines. |
| `filtered` | The estimated percentage of the table's rows that the table condition keeps; `100` means that the condition removes no row. `rows` × `filtered` ÷ 100 is the estimated number of rows joined with the following table. When the output has no `filtered` column (plain `EXPLAIN` on MariaDB), the reading gives no figure for the rows kept and says that the plan carries none. |
| `Extra` | The extra steps of this row. See *The `Extra` column*. |

### The `type` values

The `type` column holds one value. A value matches a row of this table only when it equals the value exactly.

| `type` | Plain-language reading |
|---|---|
| `system` | The table has at most one row. |
| `const` | At most one row of the table matches, and the database reads it once, at the start of the query. MySQL uses it when the query compares all parts of a primary key or a unique index with constant values. |
| `eq_ref` | The database reads one row of this table for each combination of rows from the tables before it, through all parts of a primary key or a `UNIQUE NOT NULL` index. |
| `ref` | The database reads all rows with matching index values from this table, for each combination of rows from the tables before it. The index is used through a leftmost prefix, or it is not a primary key or unique index. |
| `fulltext` | The database uses a `FULLTEXT` index. |
| `ref_or_null` | Like `ref`, with an extra search for rows that contain `NULL`. |
| `index_merge` | The database uses the Index Merge optimization: it reads ranges of several indexes of this table and merges the rows it finds. `key` lists the indexes. |
| `unique_subquery` | An `IN (SELECT …)` subquery runs as a lookup through a primary key, in place of the subquery. |
| `index_subquery` | Like `unique_subquery`, through an index that is not unique. |
| `range` | The database reads only the rows in a given range, through the index in `key`. |
| `index` | The database reads the whole index in `key`. With `Using index` in `Extra`, it reads only the index. Without it, it also reads every table row in the order of the index. |
| `ALL` | The database reads every row of the table, once for each combination of rows from the tables before it. |

### The `Extra` column

Read the column with this rule:

1. Split the column at each `;`. Remove the white space at the start and at the end of each part.
2. Match each part against the table below:
   - A part matches a row when it equals the row's value.
   - A part also matches when the row's value is `Using join buffer` or `Using index condition`, and the part is that value followed by a text in parentheses, with or without a space before the parenthesis. The reading quotes the text in parentheses as it stands. Examples are `Using join buffer (hash join)` and `Using index condition(BKA)`.
3. Name each part that matches no row, and quote it raw with no reading. `Using index for skip scan` matches no row, because only an exact value or one of the two named values with a parenthesis matches.

| `Extra` value | Plain-language reading |
|---|---|
| `Using filesort` | The database does an extra pass to retrieve the rows in sorted order. |
| `Using temporary` | The database creates a temporary table to hold the result. |
| `Using index` | The database reads the column values from the index alone, without reading the table row. |
| `Using where` | A `WHERE` condition restricts which rows are matched against the next table or sent to the client. |
| `Using index condition` | The database reads index entries and tests the condition on them first, to decide whether to read the full table row. |
| `Using join buffer` | The database reads the rows of the earlier tables into the join buffer, in portions, and joins this table against the buffered rows. |
| `Using index for group-by` | The database reads all columns of the `GROUP BY` or `DISTINCT` from an index, without reading the table. |

## PostgreSQL

One reading per plan node. A node line starts with the node type, at the top or after `->`. The `on <table>` and `using <index>` words name the table and the index of the reading as printed.

### The figures of a node line

| Figure | Reading |
|---|---|
| `cost=<start>..<total>` | The estimated start-up cost, spent before the output phase of the node can begin, and the estimated total cost, if the node runs to completion. The unit is the planner's arbitrary cost unit, not time. The cost of a node includes the cost of all its child nodes. |
| `rows=<n>` | The estimated number of rows the node outputs, if it runs to completion. |
| `width=<n>` | The estimated average width of an output row, in bytes. |
| `actual time=<start>..<total>` | `EXPLAIN ANALYZE` only. The measured start-up time and total time of the node, in milliseconds of real time, averaged per execution of the node. |
| `rows=<n>` after `actual time` | `EXPLAIN ANALYZE` only. The measured rows the node output, averaged per execution of the node. |
| `loops=<n>` | `EXPLAIN ANALYZE` only. How many times the node ran. The total time and rows of the node are the averages × `loops`. |

The `cost` figures and the `actual time` figures have different units, so the reading never compares one with the other.

A PostgreSQL plan shows the rows a node outputs, never the rows it examines. The reading gives no figure for the rows examined and says that the plan carries none.

### PostgreSQL nodes

A node matches a row of this table only when its node type, as printed before `on`, `using`, or `(`, equals the row's value. Any other node is named and quoted raw with no reading, for example `Parallel Seq Scan`, `Index Scan Backward`, `HashAggregate`, or `Memoize`.

| Node | Plain-language reading |
|---|---|
| `Seq Scan` | The database reads all rows of the table in sequence. |
| `Index Scan` | The database fetches the table rows in the order of the index named after `using`. |
| `Index Only Scan` | The database answers from the index named after `using`. It reads the table only for rows on pages that the visibility map does not mark as visible to all transactions. |
| `Bitmap Index Scan` | The database visits the index to find the locations of the rows that match the index condition. |
| `Bitmap Heap Scan` | The database sorts the row locations that its child node found into physical order and fetches those rows from the table. |
| `Sort` | The database sorts the rows in an explicit step. |
| `Incremental Sort` | The database sorts the rows in batches, and it can return rows before the whole result is sorted. |
| `Hash` | The database enters the rows of its child node into an in-memory hash table, for the join node above it. |
| `Hash Join` | The database scans the other input and probes the hash table for matches to each row. |
| `Nested Loop` | The database runs the second (inner) child once for each row of the first (outer) child. |
| `Merge Join` | The database joins two inputs that are sorted on the join keys. |
| `Materialize` | The database saves the rows of its child node in memory as it reads them and returns them from memory on each later pass. |
| `Limit` | The database stops requesting rows from its child node at the limit. The child's estimated cost and rows are shown as if it ran to completion. |

### PostgreSQL detail lines

A detail line matches a row of this table only when it starts with the row's name followed by `:`. Any other detail line is named and quoted raw with no reading, for example `Recheck Cond`, `Hash Cond`, `Sort Key`, `Heap Fetches`, `Planning`, or `Workers Planned`.

| Detail line | Plain-language reading |
|---|---|
| `Filter` | The node checks this condition for each row it scans and outputs only the rows that pass. |
| `Index Cond` | The condition that the node applies through the index. |
| `Rows Removed by Filter` | The scanned rows, or the potential join pairs of a join node, that the filter condition rejected. The line appears only when the condition rejected at least one. The documentation shows it in `EXPLAIN ANALYZE` output. |
| `Rows Removed by Index Recheck` | The candidate rows that the index returned and that a recheck of the index condition rejected. The documentation shows it in `EXPLAIN ANALYZE` output. |
| `Sort Method` | Shown by `EXPLAIN ANALYZE`. The sort method used, including whether the sort ran in memory or on disk, and the memory or disk space it needed. |
| `Buffers` | The count of buffers hit, read, dirtied, and written by the node and all its child nodes. These are not distinct buffers. On PostgreSQL 18, `EXPLAIN ANALYZE` includes this line on its own; plain `EXPLAIN` without the `BUFFERS` option, which is the form `casey` runs, does not. |
| `Planning Time` | `EXPLAIN ANALYZE` only. The time the planner took to generate and optimize the plan, without parsing and rewriting. |
| `Execution Time` | `EXPLAIN ANALYZE` only. The time to run the query, including executor start-up and shut-down and any triggers fired, without parsing, rewriting, and planning. |

## Basis

Each reading was compared on 2026-11-10 with these pages:

- MySQL: the MySQL 8.4 Reference Manual, *EXPLAIN Output Format* (https://dev.mysql.com/doc/refman/8.4/en/explain-output.html), the column descriptions and the sections *EXPLAIN Join Types*, *EXPLAIN Extra Information*, and *EXPLAIN Output Interpretation*; and *SHOW INDEX Statement* (https://dev.mysql.com/doc/refman/8.4/en/show-index.html).
- MariaDB: the *EXPLAIN* page of the MariaDB documentation (`server/reference/sql-statements/administrative-sql-statements/analyze-and-explain-statements/explain.md` in the repository `mariadb-corporation/mariadb-docs`). It is the source of the `;` separator, of `Using index condition(BKA)`, and of the `filtered` column that plain `EXPLAIN` omits.
- PostgreSQL: the PostgreSQL 18 documentation:
  - *14.1. Using EXPLAIN* (https://www.postgresql.org/docs/18/using-explain.html), sections *14.1.1 EXPLAIN Basics*, *14.1.2 EXPLAIN ANALYZE*, and *14.1.3 Caveats*;
  - *11.9. Index-Only Scans and Covering Indexes* (https://www.postgresql.org/docs/18/indexes-index-only-scans.html);
  - *EXPLAIN* (https://www.postgresql.org/docs/18/sql-explain.html).
