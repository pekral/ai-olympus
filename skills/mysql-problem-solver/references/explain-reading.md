# Reading an `EXPLAIN` row in plain language (`MODE=cr-report`)

This file is the one owner of the plain-language reading of an `EXPLAIN` result. `skills/mysql-problem-solver/SKILL.md` `MODE=cr-report` says when the `TL;DR` carries a reading, and `skills/mysql-problem-solver/templates/cr-report.md` says where it goes. Neither restates the readings below.

## Rules

- **One sentence, one column.** Each sentence of a reading states the value that one named column of the `EXPLAIN` row holds. A sentence that no column of the row supports is not written (`@rules/general/general.md` *Verified statements only*).
- **Read the tabular output of plain `EXPLAIN` only.** This file does not explain `EXPLAIN FORMAT=JSON`, `EXPLAIN FORMAT=TREE`, `EXPLAIN ANALYZE`, MariaDB `ANALYZE`, or a PostgreSQL plan. For such output the `TL;DR` quotes the raw figures as they stand and gives no reading.
- **One reading per output row.** A join prints one row per table. The `table` column names the table of each reading.
- **A value this file does not explain is named, and the report quotes it raw.** Match a `type` value and an `Extra` value exactly as the column prints it. Extra text after a name, such as a suffix in parentheses, makes it a value that this file does not explain.
- **Estimates stay estimates.** `rows` and `filtered` are the optimizer's estimates. The reading says *estimated*.

## The columns

| Column | Reading |
|---|---|
| `table` | The table the database reads. |
| `type` | How the database finds rows in that table. See *The `type` values*. |
| `key` | The index the database uses. A `key` of `NULL` means it uses no index, and the reading names none. |
| `rows` | The number of rows the database estimates it examines for each lookup into this table. For the first table, or for a query over one table, that is the number of rows the whole query examines. |
| `filtered` | The estimated percentage of the examined rows that the conditions on this table keep. The rows kept are `rows` × `filtered` ÷ 100, and the reading says they are estimated. When the `EXPLAIN` row has no `filtered` column, the reading gives no figure for the rows kept and says that the plan carries none. |
| `Extra` | The extra steps of this row. See *The `Extra` values*. |

## The `type` values

| `type` | Plain-language reading |
|---|---|
| `system` | The table has at most one row. |
| `const` | At most one row can match, for example an equality on a primary key. The database treats the values of that row as constants. |
| `eq_ref` | The database reads at most one row through a unique index, for each combination of rows from the tables before it. |
| `ref` | The database looks up the rows that match one value through a non-unique index, or through a prefix of a unique index. |
| `fulltext` | The database finds the rows through a fulltext index. |
| `ref_or_null` | Like `ref`, with one more search for rows whose value is `NULL`. |
| `index_merge` | The database scans ranges of several indexes of this table and combines the rows it finds. `key` lists the indexes. |
| `unique_subquery` | A subquery runs as a lookup through a unique index, like `eq_ref`. |
| `index_subquery` | A subquery runs as a lookup through a non-unique index, like `ref`. |
| `range` | The database reads the rows that fall into one or more value ranges of the index in `key`. |
| `index` | The database reads the whole index in `key` from the start to the end. It is a full scan of the index, not a lookup. |
| `ALL` | The database reads the whole table. |

The words for the reading of *how the database reads the table* follow from the value: `ALL` is the whole table, `index` is the whole index, and every other `type` that names an index goes through the index in `key`.

## The `Extra` values

| `Extra` | Plain-language reading |
|---|---|
| `Using filesort` | The database sorts the rows in an extra step, instead of reading them already in sorted order. |
| `Using temporary` | The database builds a temporary table to hold the result. The reading can name `GROUP BY`, `DISTINCT`, or `ORDER BY` only when the SQL of the item contains it. |
| `Using index` | The index alone answers this table. The database does not read the table rows. |
| `Using where` | A `WHERE` condition checks each row, in addition to any index lookup. |
| `Using index condition` | The database passes a `WHERE` condition down to the storage engine, which checks it at the index level. |
| `Using join buffer` | The database stores the row combinations of the earlier tables in a buffer and matches this table against them in one pass. |
| `Using index for group-by` | The database resolves a `GROUP BY` or `DISTINCT` through the index without reading the rows. |

Every other `Extra` value is named in the `TL;DR` and quoted raw, with no reading.

## Basis

The readings follow the `join_type` enum in `sql/sql_opt_exec_shared.h` and the `rows` and `filtered` columns in `sql/opt_explain.cc` of the MySQL server source, and the *EXPLAIN* page of the MariaDB documentation (`server/reference/sql-statements/administrative-sql-statements/analyze-and-explain-statements/explain.md` in the MariaDB documentation repository).
