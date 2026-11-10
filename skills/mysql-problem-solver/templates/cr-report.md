## Database report — critical optimizations (advisory)

Advisory only: this report does not block the merge and is not part of the code review.
Stores: <mysql | mariadb | pgsql | unresolved — the review's `Not verified:` entry; redis | mongodb | dynamodb | elasticsearch | …> · Head: <short SHA> · DB access: <how the facts were obtained: Tinker | Laravel Boost | Laravel Telescope | the plain database client, joined with ` + ` when more than one gave facts | static analysis only — <reason>>

### TL;DR

- **1. <the proposal in one sentence>.**
  - **Facts:** <fact> (<source>); <fact> (<source>)
  - **If left as it is:** <the consequence for this query on this path>
  - **Plan today:** <which table the database reads; how it reads it — the whole table, or through the index `<key>`; rows examined against rows kept; what an extra step such as a sort or a temporary table means for this query>
  - **Plan proposed:** <the same for the proposed query, so the reader sees the difference>
- **Not verified:** <statement> — settled by <the step that settles it>

### 1. <one-sentence problem> — `<file>:<line>`
- **Store:** <the store this item concerns, for example `mysql`, `pgsql`, `redis`>
- **Evidence:** <the SQL or NoSQL call — the `toSql()` output when the application produced it, reconstructed from the code otherwise; the raw `EXPLAIN` output of the baseline or the stated limitation; table or key-space size when known>
- **Why it is critical:** <the concrete production consequence>
- **Proposed optimization:** <the rewrite, batch, index, key condition, or online DDL>
- **Non-regression:** <baseline vs proposal (plan, rows examined, or calls per request), or why it is slower and what justifies it>

<!-- TL;DR rules. The TL;DR opens the report and has one entry per item, in the same order. Every fact names its source: a `file:line`, the SQL the application produced, a value read from the output of EXPLAIN, SHOW CREATE TABLE, or SHOW INDEX, or a query Laravel Telescope recorded (`file:line` and `time_ms`). A statement with no such source moves to the Not verified line with the step that settles it; drop that line when nothing is unverified. Keep the two Plan lines only for an entry whose evidence carries an EXPLAIN result the run read, and drop Plan proposed when the proposal was not tested. When EXPLAIN did not run, replace both Plan lines with one line: **Plan:** not read — the proposal rests on static analysis: <reason>. Never explain a plan the run did not read. -->

<!-- When no item qualifies in any part that ran, keep the heading, the advisory line, and the stores line, and replace the TL;DR and the items with: No critical database item in this diff. A PostgreSQL diff without a local instance adds one line: PostgreSQL query plan not analysed — DB access: static analysis only — <reason> -->
