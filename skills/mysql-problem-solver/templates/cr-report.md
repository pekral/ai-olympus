## Database report — critical optimizations (advisory)

Advisory only: this report does not block the merge and is not part of the code review.
Stores: <mysql | mariadb | pgsql | unresolved — the review's `Not verified:` entry; redis | mongodb | dynamodb | elasticsearch | …> · Head: <short SHA> · DB access: <the plain database client | static analysis only — <reason>>

### TL;DR

- **1. <the proposal in one sentence>.**
  - **Facts:** <fact> (<source>); <fact> (<source>)
  - **If left as it is:** <the consequence for this query on this path>
  - **Plan today:** <the reading of the `EXPLAIN` output, one sentence per column, node, or detail line>
  - **Plan proposed:** <the same reading for the proposed query>
- **Not verified:** <statement> — settled by <the step that settles it>

### 1. <one-sentence problem> — `<file>:<line>`
- **Store:** <the store this item concerns, for example `mysql`, `pgsql`, `redis`>
- **Evidence:** <reconstructed SQL or NoSQL call, the raw `EXPLAIN` output of the baseline or the stated limitation, table or key-space size when known>
- **Why it is critical:** <the concrete production consequence>
- **Proposed optimization:** <the rewrite, batch, index, key condition, or online DDL>
- **Non-regression:** <baseline vs proposal (plan, rows examined, or calls per request), or why it is slower and what justifies it>

<!-- The TL;DR has one entry per item, in the item order. Drop the Plan proposed line when the proposal was not tested, and drop the Not verified line when nothing is unverified. When EXPLAIN did not run, replace both Plan lines with one line: **Plan:** not read — the proposal rests on static analysis: <reason> -->

<!-- DB access names one value for the whole report: the plain database client when any fact of the report was read through it, otherwise static analysis only with its reason. -->

<!-- When no item qualifies in any part that ran, keep the heading, the advisory line, and the stores line, and replace the TL;DR and the items with: No critical database item in this diff. A PostgreSQL diff without a local instance adds one line: PostgreSQL query plan not analysed — DB access: static analysis only — <reason> -->
