## Database report — critical optimizations (advisory)

Advisory only: this report does not block the merge and is not part of the code review.
Stores: <mysql | mariadb | pgsql | unresolved — the review's `Not verified:` entry; redis | mongodb | dynamodb | elasticsearch | …> · Head: <short SHA> · DB access: <EXPLAIN run | static analysis only — <reason>>

### 1. <one-sentence problem> — `<file>:<line>`
- **Store:** <the store this item concerns, for example `mysql`, `pgsql`, `redis`>
- **Evidence:** <reconstructed SQL or NoSQL call, EXPLAIN baseline or the stated limitation, table or key-space size when known>
- **Why it is critical:** <the concrete production consequence>
- **Proposed optimization:** <the rewrite, batch, index, key condition, or online DDL>
- **Non-regression:** <baseline vs proposal (plan, rows examined, or calls per request), or why it is slower and what justifies it>

<!-- When no item qualifies in any part that ran, keep the heading, the advisory line, and the stores line, and replace the items with: No critical database item in this diff. A PostgreSQL diff without a local instance adds one line: PostgreSQL query plan not analysed — DB access: static analysis only — <reason> -->
