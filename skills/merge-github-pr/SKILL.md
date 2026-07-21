---
name: merge-github-pr
description: Use when safely merge GitHub pull requests that are ready
license: MIT
metadata:
  author: Petr Král (pekral.cz)
---

# Merge GitHub PR

## Purpose
Merge pull requests that meet all required conditions.

---

## Constraints
- Apply @rules/git/general.mdc
- **Never merge a PR without a converged code review.** A code review must have been run on the PR's final diff and report **no errors** — 0 Critical + 0 Moderate findings (Minor does not block). This is the hard merge gate from `@rules/git/general.mdc` *Merging*; it is mandatory on every merge and is verified in step 2 below.
- Never merge PRs with conflicts
- Never merge PRs with failing CI (unless explicitly instructed)
- Never bypass required approvals or protections
- The only tolerated CI failure is a **GitHub Actions billing / account-limit error**, and only when a **green local `composer build` on the exact head commit** stands in for the missing CI signal (see *GitHub Actions billing exception* below). Any other failure — real test failure, lint, static analysis — still blocks.

---

## Execution

### 1. Load PRs
- Identify candidate PRs ready for merge
- **Repository ownership (hard gate, runs first)** — for each candidate, confirm the PR belongs to the current checkout by running `skills/_shared/assert-current-repo.sh <URL>` before loading it. Exit code `4` means the PR lives in a different repository: **stop**, report the mismatch, and never merge it — a foreign PR merged from the wrong checkout lands in the wrong project's history. Exit code `5` means ownership could not be proven (not a git checkout, or no github.com remote on any of them): stop and tell the caller to run from inside the target checkout. Only a zero exit permits the flow to continue — every non-zero exit is a hard stop, and the deterministic loader's "exit 2/3 → fall back to the MCP server" convention never applies to this guard: there is no fallback for an ownership verdict.
- For each candidate, load PR context by running `skills/code-review-github/scripts/load-issue.sh <URL>` — the single deterministic entry point; always pass the full GitHub PR URL, never a bare number (the loader rejects it). Never call `gh pr view`, `gh pr checks`, or `gh api /repos/.../pulls/...` directly. Read `isDraft`, `mergeable`, `mergeStateStatus`, `reviewDecision`, and `statusCheckRollup[]` off the resulting JSON document.
- If the script is unavailable (missing tool, exit code 2/3) fall back to the GitHub MCP server.

### 2. Pre-checks (must all pass)

For each PR, derive the verdict from the JSON document loaded in step 1:

- **Converged code review on the final diff (hard gate, no exception)** — a code review must have run on the exact commits being merged and report **no errors**: 0 Critical + 0 Moderate findings (Minor does not block). Verify it from the PR's review comments in the loaded JSON: locate the latest code-review status comment (the technical CR comment / convergence status posted by `@skills/code-review-github/SKILL.md` / `@skills/process-code-review/SKILL.md`), confirm it reports `criticalCount + moderateCount == 0`, and confirm it reflects the head commit. Because the CR comment is **upserted in place** (`@skills/code-review/SKILL.md` *Cross-run history* — follow-up runs edit the same comment), use its **`updatedAt`** (not `createdAt`) for the staleness check: it is current only when `updatedAt` is **at or after** the newest `commits[].authoredDate` (the head commit). A comment whose `updatedAt` predates the head commit is stale and does not count. If no code-review comment exists, the latest one still carries Critical / Moderate findings, or its `updatedAt` predates the head commit, **do not merge** — report that the code-review gate is unmet and that the review must be run (or re-run) to convergence via `@skills/code-review-github/SKILL.md` + `@skills/process-code-review/SKILL.md` first. This gate is **never** waived — not by an explicit merge request, not by the billing exception below, and not by a GitHub `reviewDecision == "APPROVED"` on its own.
- **Delegated security coverage is verified, never assumed (hard gate, no exception)** — when the code-review comment's Summary line carries `security: owned by athena`, the inline security pass did **not** run in that review. The token records a delegation, not a delivery, so the gate must confirm the delegate actually reported: locate `athena`'s security comment on the PR and apply the **same staleness rule** as the code-review comment — its `updatedAt` must be at or after the newest `commits[].authoredDate`. If no security comment exists, or it predates the head commit, **do not merge**: the PR has zero security coverage while being positively marked as covered, which is worse than an obviously missing review. This is not hypothetical — a security pass that dies mid-run (API error, session limit, cancelled agent) leaves exactly this state: the code review published its delegation token and nothing ever arrived to honour it. Report the gap and require the security review to be re-run.
- **Not a Draft** — `isDraft == false`. A Draft PR signals the review/fix loop has not converged (`@rules/git/general.mdc` *Draft pull requests*): the Draft state mirrors the unmet code-review gate, so **do not merge** a Draft and report it as skipped. If the PR's code review has in fact converged (0 Critical + 0 Moderate), it must first be promoted out of Draft by `@skills/process-code-review/SKILL.md` (`gh pr ready`) before this skill will merge it — never flip a Draft to ready here just to merge it. The billing exception below never relaxes this.
- No merge conflicts — `mergeable == "MERGEABLE"` and `mergeStateStatus` is not `DIRTY` or `BEHIND`
- CI is passing — every entry in `statusCheckRollup[]` has a passing `state` (`SUCCESS` / `NEUTRAL` / `SKIPPED`), **with the single billing exception below**, which requires a green local build in its place
- Required approvals are present — `reviewDecision == "APPROVED"`
- Branch is up to date with base branch — `mergeStateStatus != "BEHIND"`

If any check fails:
- do not merge
- report reason

#### GitHub Actions billing exception (green local build required)

A single, narrow exception relaxes the CI-passing check. It exists because a billing / account-limit failure means the jobs **never ran** — it carries no information about the code, so treating it as a red build blocks every merge indefinitely for a reason unrelated to quality. The exception does not waive the evidence; it **substitutes** it:

- **Substitute evidence is mandatory.** Run the project's full local quality gate (`composer build`, or the project's equivalent) on the **exact head commit being merged**, and require it to pass. A billing failure removes the CI signal; it does not remove the requirement for one. If the local build cannot be run, or does not pass, **do not merge** — the exception has no effect. Record the command and its result in the merge report so the substitution is auditable.
- **Verify the jobs truly did not start.** A billing-blocked job fails in seconds with no executed steps. Confirm it from the run's annotations (`gh api repos/<owner>/<repo>/check-runs/<id>/annotations`) rather than inferring it from a red X — a real failure and a never-started job look identical in the checks list.
- **When it applies:** the *only* blocking entries in `statusCheckRollup[]` are GitHub Actions runs that did **not** execute because of a billing / account-limit problem — typically a `state` of `ERROR` (or a workflow that never started) whose detail message is an unambiguous billing notice such as *"The job was not started because recent account payments have failed or your spending limit needs to be increased"*, *"billing"*, or *"spending limit"*. In that case the gate **ignores those specific entries** and allows the merge.
- **Detection must stay conservative.** Treat an entry as a billing failure only when its message clearly names a billing / payment / spending-limit cause. A bare `ERROR` / `FAILURE` with no billing wording is a **real** failure — never assume billing. When in doubt, do not merge: report the ambiguous entry and stop.
- **The exception is billing-only.** It never relaxes any other gate: a missing or non-converged code review (the hard CR gate above), a Draft PR (`isDraft == true`), a real CI failure (tests, lint, static analysis) on any non-billing entry, `mergeStateStatus == "DIRTY"` / `"BEHIND"`, an unmergeable state, or `reviewDecision != "APPROVED"` still blocks the merge regardless of the explicit request.
- **Report what was waived.** When the merge proceeds under this exception, list each ignored billing entry (check name + the billing message) in the output so the waiver is auditable.

Without passing substitute evidence this exception does not apply — a billing failure then blocks like any other failing check. The exception never converts "we could not measure" into "it is fine"; it only allows a different, equally strict measurement.

### 3. Merge

- Merge PR using CLI
- Use project default merge strategy

### 4. Post-merge

- Delete branch (if configured)
- **Remove worktree (opt-in only)** — if an isolated git worktree was explicitly created for this work unit (per `@rules/git/general.mdc` *Worktrees / Workspaces*), remove it now that the merge is complete:
  1. Verify the worktree is not the currently active working tree and has no uncommitted changes. If it is active or dirty, report the issue and skip removal — never pass `--force`.
  2. `git worktree remove <path>` — removes the worktree directory and its metadata.
  3. `git worktree prune` — cleans up any remaining stale worktree metadata.
  If no worktree was explicitly created for this work unit (the default: agent worked in the shared tree), skip this step entirely.
- Confirm merge success

---

## Output

- List merged PRs
- List skipped PRs with reasons

---

## Principles

- Safety over speed
- Never bypass CI or review gates — a converged code review (0 Critical + 0 Moderate) on the final diff is a mandatory precondition for every merge
- Merge only fully ready PRs
- Be explicit about skipped PRs
