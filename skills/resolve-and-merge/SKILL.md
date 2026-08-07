---
name: resolve-and-merge
description: "Use when a batch of open GitHub issues carrying the auto-resolve label must be resolved and merged end-to-end in one run. Adopts eligible in-flight pull requests first, then randomly selects up to five dependency-free labeled issues, substituting the blocking parent whenever a candidate depends on another task, and processes the queue strictly sequentially on the shared working tree with no git worktrees. Every task gets its own branch, its own pull request, a converged code review, and a merge into the default branch; a silent orchestrator is nudged after twenty minutes of no progress, and a blocker is recorded instead of force-merged."
license: MIT
metadata:
  author: "Petr Král (pekral.cz)"
---

## Constraints
- Apply `@rules/git/general.mdc`
- Apply `@rules/reports/general.mdc`
- Apply `@rules/compound-engineering/general.mdc`
- Operate on the current Git repository's GitHub remote only — refuse if the remote is not GitHub
- **Strictly sequential.** Process one task at a time, start to finish, before touching the next. Never fan work out across tasks in parallel, and never split the batch across several dispatches in one message
- **Never use a git worktree for the writing path.** Every task runs on the single shared working tree (`agents/daidalos.md` *Worktrees — writing path stays on the shared tree*). A read-only code-review worktree stays the CR agent's own opt-in and is never requested by this skill
- **One task = one branch = one pull request.** Never bundle two issues into one PR, and never reuse a branch across tasks
- **Code review is a hard merge gate** (`@rules/git/general.mdc` *Merging*): a PR is merged only after the review has run on its final diff and converged to **0 Critical + 0 Moderate**. Never force-merge, never merge past a failing check, a merge conflict, or a Draft PR
- **Scope discipline.** Implement and review only what the assignment defines. The checks the delegated skills already run are the whole check set — never add an extra audit, an unrequested refactor, or a repository-wide sweep on top of them
- Never alter an issue's body, labels, or assignees beyond the claim label the delegated skills own (`@rules/compound-engineering/general.mdc` *Claim a tracker issue before working on it*)
- Never close, comment on, rebase, or otherwise touch an open pull request that is not linked to a labeled issue — such a PR is left exactly as found
- Do not expose sensitive or internal details in user-facing messages

---

## Use when
- The user asks for a batch of auto-resolvable GitHub issues to be taken from open to merged in one run ("resolve five issues and merge them")
- A scheduled or unattended run needs one deterministic entry point that resumes in-flight work first and only then opens new work
- Several labeled issues are interlinked and the batch must be ordered so blocking tasks land before the tasks that depend on them

Use `@skills/resolve-issue/SKILL.md` instead when the target issue is already known, only one task is wanted, and the run stops at the pull request.

---

## Inputs
- `LABEL` (optional) — the auto-resolve label. Default: `Resolve_by_AI`. GitHub matches label names case-insensitively, so `resolve_by_ai` selects the same set
- `CLAIM_LABEL` (optional) — the in-progress claim label. Default: `Resolve_by_AI:in-progress`
- `BATCH_SIZE` (optional) — how many tasks the batch may hold. Default: `5`
- `NUDGE_MINUTES` (optional) — minutes of no observable progress before the orchestrator is nudged. Default: `20`

---

## Scripts

**Execute these, do not read them** — their output is the input to the step that follows. All three are **read-only**: `gh` and `git` reads only, no tracker write, no merge, no working-tree change, no temporary file. Every caller value reaches `jq` through `--arg`, never concatenated into a filter, and every issue / PR title is stripped of control, bidi, and zero-width characters before it is printed, so an author-controlled title cannot rewrite the report a human reads before merging.

| Script | Purpose | Exit codes beyond `0` |
|--------|---------|------------------------|
| `scripts/preflight.sh` | Session, repository slug, default branch, clean-tree check | `1` usage · `2` missing tool · `3` no authenticated GitHub repo · `4` dirty tree |
| `scripts/inventory-open-prs.sh` | Classifies every open PR as adopt / ignore | `1` · `2` · `3` |
| `scripts/select-candidates.sh` | Draws eligible issues at random, minus claimed and adopted ones | `1` · `2` · `3` |

`scripts/_lib.sh` carries the shared guards and the safety contract; it is sourced, never run.

---

## Execution

### 1. Preflight
Run `scripts/preflight.sh`. It verifies the `gh` session, resolves the repository slug and the default branch, and reports whether the working tree is clean:

```
scripts/preflight.sh
```

Exit `3` means no authenticated GitHub repository — stop and ask the user to authenticate. Exit `4` means a dirty working tree — stop, because an unrelated uncommitted change must never be swept into a task's PR. On exit `0`, check out the reported `base` and pull.

### 2. Inventory the open pull requests first
Open work is finished before new work is opened. Run `scripts/inventory-open-prs.sh`, which classifies every open PR exactly once:

```
scripts/inventory-open-prs.sh "$LABEL"
```

- **Adopt** — the PR itself carries `$LABEL`, or it closes an issue that does. Each adopted PR becomes a batch task and consumes one `BATCH_SIZE` slot.
- **Ignore** — everything else, reported with its reason and touched in no way.

A PR whose linked issue cannot be determined is **ignored**, never adopted on a guess.

An adopted PR re-enters the pipeline at the step it stalled at, not at implementation: an open PR already has its branch and its commits, so re-running the implementation from scratch would duplicate work. Resume at the review-and-fix loop in step 6, then the merge gate in step 8.

### 3. Select the candidate issues
Eligible = open, carries `$LABEL`, does not carry `$CLAIM_LABEL`, and is not already covered by an adopted PR from step 2. Run `scripts/select-candidates.sh`, passing the remaining slots and the issue numbers step 2 already adopted:

```
scripts/select-candidates.sh "$REMAINING_SLOTS" "$LABEL" "$CLAIM_LABEL" 12,34
```

The draw is random (`sort -R`, since `shuf` is absent on macOS). If fewer than `BATCH_SIZE` tasks are eligible, the script returns a shorter list — run **only** those, and never widen the selection to unlabeled issues to reach the number. If it returns an empty array and no PR was adopted, stop with `No eligible $LABEL issues and no adoptable open pull requests found`.

### 4. Resolve dependencies before committing to the queue
For each drawn candidate, read its body and its parent/sub-issue links, and look for an open blocker:
- an explicit `Depends on #<n>`, `Blocked by #<n>`, `Blocked by:` reference,
- a GitHub sub-issue relation (`gh issue view <n> --json parent,subIssues` where the repository uses them),
- an unchecked task-list entry in another open issue that points at this candidate,
- the `EPIC` label on a referenced parent.

Then:
- **No open blocker** → the candidate stays in the queue.
- **Open blocker that carries `$LABEL`** → replace the candidate with that blocker (walk to the root of the chain, not just one level up) and queue the blocker instead. The dependent issue is reported as deferred, not resolved.
- **Open blocker without `$LABEL`** → drop the candidate, report it as `blocked by #<n> (not labeled)`, and draw a replacement from the remaining eligible pool. Never work an unlabeled issue.

Every replacement drawn here runs through this same dependency walk before it enters the queue — a replacement is a new candidate, not a free slot. When the pool empties before the slots fill, run the shorter queue and report the shortfall.

De-duplicate the queue: two candidates sharing one root blocker collapse into that single blocker.

### 5. Order the queue
1. Adopted pull requests (in-flight work finishes first)
2. Blockers before the tasks that depend on them
3. Otherwise oldest `createdAt` first

State the resulting order before starting — it is the batch plan the rest of the run follows.

### 6. Run the queue, one task at a time
For each task in order, and only after the previous task reached a terminal state (merged, or recorded as blocked):

- **Delegate the whole task to `daidalos`** through the Task tool, one dispatch per task, with the issue URL (or the adopted PR URL), the explicit instruction to take it through to a merged pull request, and the constraint that no worktree is used on the writing path. `daidalos` owns claim-labelling, implementation and scoped validation (`talos`), the review-and-fix loop (`athena` ↔ `talos`) to convergence, the post-convergence report (`hermes`), and the merge chain.
- **For an adopted PR, say so in the dispatch prompt**: state that the implementation already exists on the PR's branch and the run starts at the review-and-fix loop. `daidalos` resolves a URL into a subject and then dispatches `talos` to implement (`agents/daidalos.md` step 5), so an adopted PR handed over without that instruction is implemented a second time.
- **When no agent dispatch is available** (headless or nested run), run the equivalent skill chain inline, sequentially, in this order: `@skills/resolve-issue/SKILL.md` → `@skills/code-review-github/SKILL.md` → `@skills/process-code-review/SKILL.md` → `@skills/merge-github-pr/SKILL.md`, each on the task's own URL. For an adopted PR, start the chain at `code-review-github`.
- Do not review, implement, or fix anything in this skill's own context. This skill selects, orders, watches, and reports.
- Never dispatch the next task while the current one is still running.

### 7. Watchdog — nudge a silent orchestrator
Dispatch each task so its progress stays observable (a background task with an output channel), and poll it. When a dispatch produces **no observable progress for `NUDGE_MINUTES`**, send the orchestrator this message verbatim in the assignment's language — Czech default: `Jak to vypadá? Potřebujeme dokončit úkol! Neblokuješ něco omylem?!` (English: `How is it going? We need to finish the task! Are you blocking something by mistake?`).

- Re-send at most once per `NUDGE_MINUTES` interval, up to **three** nudges for one task.
- After the third unanswered nudge, stop that task, record it as `stalled — no response after 3 nudges`, and move to the next task in the queue.
- When the dispatch is blocking with no mid-run channel, the watchdog cannot fire. Say so in the report for that task rather than claiming a nudge was sent.

### 8. Merge gate
A task counts as done only when its PR is merged into `$BASE`. Before the merge, all of the following hold — each already enforced by `@skills/merge-github-pr/SKILL.md`, which performs the checks and skips with a reason:
`isDraft == false`, `mergeable == MERGEABLE`, `mergeStateStatus` not `DIRTY` / `BEHIND`, every `statusCheckRollup[]` entry passing, and the review converged to 0 Critical + 0 Moderate.

Surface any skip reason verbatim. Never retry a merge by relaxing a gate.

### 9. Blockers do not kill the batch
A task that cannot reach a merge — merge conflict, failing CI, unconverged review, a stalled dispatch, or a delegated skill returning `Blocked` — is recorded with its verbatim reason and the batch **continues with the next task**. Never fix a blocker outside the contract of the skill that surfaced it, and never abandon the remaining queue because one task failed.

Stop the whole batch only when the repository state itself is unsafe to continue on: a dirty shared working tree left behind, a lost `gh` session, or `$BASE` no longer merge-able.

### 10. Close out
- Confirm the working tree is back on `$BASE`, clean, with no leftover task branch and no scratch file (`@rules/compound-engineering/general.mdc` *Temporary-file hygiene*).
- Confirm the claim label was released on every task that stopped **before its pull request opened**, and left in place on every task that opened one — a claim is never released once a PR exists, merged or not (`@rules/compound-engineering/general.mdc` *Claim a tracker issue before working on it*). Releasing it there would make the issue pickable again while its PR is still open, which is the collision the claim exists to prevent.

---

## Output

A single report:

| # | Task | Type | PR | Review | Result |
|---|------|------|----|--------|--------|
| 1 | #<n> <title> | adopted PR / new issue | <url> | C/M/m counts | merged / blocked — <reason> / stalled |

Plus:
- the batch plan from step 5 and why each task holds its position (blocker, adopted, oldest)
- every issue **not** taken and why (`blocked by #<n> (not labeled)`, `deferred behind #<n>`, `not eligible`)
- every open PR **ignored** in step 2 and why
- every nudge sent, with the task and the interval it followed
- the merged / blocked / stalled totals against `BATCH_SIZE`

---

## Done when
- Every open PR was classified once as adopted or ignored, and no ignored PR was modified
- The queue held at most `BATCH_SIZE` tasks, every one of them linked to a `$LABEL` issue
- Every dependent candidate was either replaced by its labeled root blocker or reported as blocked
- Tasks ran strictly one at a time, on the shared working tree, with no git worktree on the writing path
- Every task that reached a merge did so through a converged review and its own pull request
- Every task that did not reach a merge carries a verbatim blocker reason in the report
- The repository is left on a clean default branch with no orphaned branch or worktree, and every claim label is either released (task stopped before its PR opened) or deliberately left in place (task opened a PR)
