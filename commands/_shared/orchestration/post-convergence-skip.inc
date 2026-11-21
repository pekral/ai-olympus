# Skipping the post-convergence scoped pass

Extracted from `commands/_shared/krang-workflow.inc` so the orchestrator does not carry it on every run. **Loading trigger:** step 6 considers skipping the post-convergence scoped pass. Without this file the pass runs. A section, a step, or a bullet this file names *above* or *below* without a file is in `commands/_shared/krang-workflow.inc`.

### The skip of the post-convergence scoped pass

- **Skip it exactly when all four conditions hold at once:**
  1. the CR converged on **0 Critical with no undeferred Moderate** and the loop produced no fix commit, so no new code followed the review;
  2. the PR's **head SHA is identical** to a SHA a green validation already covered in this run — the `donatello:impl` implementation round, or the step-5 pre-convergence scoped pass;
  3. the run does **not** carry a coverage gate `leonardo` deferred to `donatello` (`@rules/compound-engineering/orchestration.md` *Context-efficient orchestration* mechanism 3) — a deferred coverage verdict has no other owner, so the pass runs to produce it;
  4. the brief already carries, **for that same head SHA**, the `donatello` handoff that `april` builds its `How to test` from in step 6a — without it the reporting step has nothing to read.
- **Write the skip into the ledger; never leave it silent.** Append one line to `${BRIEF%.md}.dispatches` for role `donatello:scoped` with the state `skipped — head <SHA> already validated`, under the same per-brief append lock every other ledger write uses (*Dispatch ledger* above). A skipped round that leaves no line is indistinguishable from a round that was dispatched and then fell over.
- **Name the skip in the final report.** The `Route taken:` line carries it as `donatello (scoped — skipped, head <SHA> already validated)` instead of dropping the step, so the user reads a deliberate decision rather than a missing one (*Output — handoff to the user* below).
- **The skip relaxes nothing downstream.** Step 6a still dispatches `april` — condition 4 guarantees the handoff it reads is already in the brief — the interactive-testing decision still answers its own question, and the pre-merge quality gate in `@skills/merge-github-pr/SKILL.md` runs unchanged on the merged head. The skip removes one duplicate validation of an already-validated commit, never a gate.
