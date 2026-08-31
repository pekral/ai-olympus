# Comment analysis

Referenced from `skills/resolve-issue/SKILL.md` *Comment analysis* (step 5 of Execution). Extracted to keep the skill body under the skill-check token limit; the rules are unchanged, including the *Reopened task (mandatory deep pass)* clause that step 1 of Execution names.

5. Before analyzing the problem, fetch and read **all comments and replies** from the issue tracker (GitHub, JIRA, or Bugsnag). For GitHub, JIRA, and Bugsnag issues, read `comments[]` directly off the JSON loaded in step 2 — do not issue a second listing call:
   - Group comments by conversation thread (e.g., review threads, reply chains).
   - For each thread, determine:
     - **Current requirements** — requests or conditions that are still valid and unfulfilled.
     - **Resolved items** — requirements already addressed by merged PRs or subsequent comments.
     - **Outdated items** — requests superseded by newer comments or decisions.
   - Use only the **current requirements** (combined with the issue description) as input for the next step.

   **Reopened task (mandatory deep pass).** When step 1 marked the run as a reopened continuation, the comment analysis above is blocking: read the comments posted **after the most recent close / merge** first (they win over the original description on why it was reopened and what still fails), then load every earlier linked PR (`closingPullRequests[]` / `pullRequests[]` / `devSummary` / the mirrored issue's PRs) via the deterministic loader and classify what already landed as **Resolved items** — **never reimplement or revert** it unless a post-reopen comment asks. Derive the **continuation scope** as the post-reopen delta plus any stated requirement that verifiably never landed, not the original assignment from scratch.
If nothing explains the reopen, stop as **Blocked**, post a question asking the reopen reason, and release the claim per step 1 — never guess the continuation scope.
