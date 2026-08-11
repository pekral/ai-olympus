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
- Apply @rules/git/general.md
- **Never merge a PR without a converged code review.** A code review must have been run on the PR's final diff and report **no errors** — 0 Critical + 0 Moderate findings (Minor does not block). This is the hard merge gate from `@rules/git/general.md` *Merging*; it is mandatory on every merge and is verified in step 2 below. Its single exemption is a **dependency-only PR** (`@rules/git/general.md` *Dependency-only pull requests*), qualified in step 2 below.
- Never merge PRs with conflicts
- Never merge PRs with failing CI — the only sanctioned relaxation is the *GitHub Actions billing exception* below; no other explicit instruction, including "merge anytime", overrides a real CI failure
- Never bypass required approvals or protections
- The only tolerated CI failure is a **GitHub Actions billing / account-limit error**, and only when a **green local `composer build` on the exact head commit** stands in for the missing CI signal — or when the caller **explicitly requested a "merge anytime"**, which waives the substitute build for billing entries only (see *GitHub Actions billing exception* below). Any other failure — real test failure, lint, static analysis — still blocks.

---

## Execution

### 1. Load PRs
- Identify candidate PRs ready for merge
- **Repository ownership (hard gate, runs first)** — for each candidate, confirm the PR belongs to the current checkout by running `skills/_shared/assert-current-repo.sh <URL>` before loading it. Exit code `4` means the PR lives in a different repository: **stop**, report the mismatch, and never merge it — a foreign PR merged from the wrong checkout lands in the wrong project's history. Exit code `5` means ownership could not be proven (not a git checkout, or no github.com remote on any of them): stop and tell the caller to run from inside the target checkout. Only a zero exit permits the flow to continue — every non-zero exit is a hard stop, and the deterministic loader's "exit 2/3 → fall back to the MCP server" convention never applies to this guard: there is no fallback for an ownership verdict.
- For each candidate, load PR context by running `skills/code-review-github/scripts/load-issue.sh <URL>` — the single deterministic entry point; always pass the full GitHub PR URL, never a bare number (the loader rejects it). Never call `gh pr view`, `gh pr checks`, or `gh api /repos/.../pulls/...` directly. Read `isDraft`, `mergeable`, `mergeStateStatus`, `reviewDecision`, `statusCheckRollup[]`, and `files[]` off the resulting JSON document.
- If the script is unavailable (missing tool, exit code 2/3) fall back to the GitHub MCP server.

### 2. Pre-checks (must all pass)

For each PR, derive the verdict from the JSON document loaded in step 1:

- **Converged code review on the final diff (hard gate, one exemption: dependency-only PRs)** — a code review must have run on the exact commits being merged and report **no errors**: 0 Critical + 0 Moderate findings (Minor does not block). Verify it from the PR's review comments in the loaded JSON: locate the latest code-review status comment (the technical CR comment / convergence status posted by `@skills/code-review-github/SKILL.md` / `@skills/process-code-review/SKILL.md`), confirm it reports `criticalCount + moderateCount == 0`, and confirm it reflects the head commit. Because every CR run **POSTs a fresh comment** and never edits a prior one (`@skills/code-review/SKILL.md` *Cross-run history* — the chronological sequence of comments is the audit trail), the newest such comment is the latest review and its **`createdAt`** is when that review was produced: use `createdAt` (not `updatedAt`) for the staleness check, so it is current only when `createdAt` is **at or after** the newest `commits[].authoredDate` (the head commit). A comment whose `createdAt` predates the head commit is stale and does not count — and a later edit to that comment's body never refreshes the verdict, because the review behind it still ran on the older diff. If no code-review comment exists, the latest one still carries Critical / Moderate findings, or its `createdAt` predates the head commit, **do not merge** — report that the code-review gate is unmet and that the review must be run (or re-run) to convergence via `@skills/code-review-github/SKILL.md` + `@skills/process-code-review/SKILL.md` first. Apart from the *Dependency-only PR exemption* below, this gate is **never** waived — not by an explicit merge request, not by the billing exception below, and not by a GitHub `reviewDecision == "APPROVED"` on its own.
- **Delegated security coverage is verified, never assumed (hard gate, no exception)** — when the code-review comment's Summary line carries `security: owned by athena`, the inline security pass did **not** run in that review. The token records a delegation, not a delivery, so the gate must confirm the delegate actually reported: locate `athena`'s security comment on the PR and apply the **same staleness rule** as the code-review comment — its `createdAt` must be at or after the newest `commits[].authoredDate`. If no security comment exists, or it predates the head commit, **do not merge**: the PR has zero security coverage while being positively marked as covered, which is worse than an obviously missing review. This is not hypothetical — a security pass that dies mid-run (API error, session limit, cancelled agent) leaves exactly this state: the code review published its delegation token and nothing ever arrived to honour it. Report the gap and require the security review to be re-run. The gate reads the code-review comment, so it is vacuous on a PR merged under the *Dependency-only PR exemption* below (no review ran, therefore no delegation was ever claimed) — but on **every** PR that does carry a code-review comment it applies without exception.
- **Not a Draft** — `isDraft == false`. A Draft PR signals the review/fix loop has not converged (`@rules/git/general.md` *Draft pull requests*): the Draft state mirrors the unmet code-review gate, so **do not merge** a Draft and report it as skipped. If the PR's code review has in fact converged (0 Critical + 0 Moderate), it must first be promoted out of Draft by `@skills/process-code-review/SKILL.md` (`gh pr ready`) before this skill will merge it — never flip a Draft to ready here just to merge it. The billing exception below never relaxes this.
- No merge conflicts — `mergeable == "MERGEABLE"` and `mergeStateStatus` is not `DIRTY` or `BEHIND`
- CI is passing — every entry in `statusCheckRollup[]` has a passing `state` (`SUCCESS` / `NEUTRAL` / `SKIPPED`), **with the single billing exception below**, which requires a green local build in its place (or an explicit "merge anytime" request from the caller — see the exception's *merge anytime* clause)
- Required approvals are present — `reviewDecision == "APPROVED"`
- Branch is up to date with base branch — `mergeStateStatus != "BEHIND"`

If any check fails:
- do not merge
- report reason

#### Dependency-only PR exemption (code review not required)

A pull request that changes **nothing but dependency versions** is exempt from the code-review gate (`@rules/git/general.md` *Dependency-only pull requests*): it contains no application logic to review, so a review on it produces no actionable finding and only stalls routine upgrades. Qualify the exemption from evidence, never from the PR title, the branch name, or the author being a bot (`dependabot[bot]`, `renovate[bot]`) — a bot PR can still touch a workflow file, and a human PR can be a pure bump:

1. **Changed files** — read `files[]` from the JSON document loaded in step 1 and require **every** `files[].path` to be a dependency manifest or lockfile: `composer.json`, `composer.lock`, `package.json`, `package-lock.json`, `yarn.lock`, `pnpm-lock.yaml` (or the project's equivalent). One path outside that set voids the exemption for the whole PR — the code-review gate then applies in full.
2. **Manifest diff** — when `composer.json` or `package.json` is among the changed files, read its diff (`gh pr diff <URL> -- composer.json package.json`) and require every hunk to be a **version-constraint change on a package the project already requires**. An **added** entry in `require` / `require-dev` / `dependencies` / `devDependencies` is a dependency-selection decision (`@rules/php/dependency-selection.mdc`) and keeps the full code-review gate; so does a **removed** entry, which can drop a package the code still uses. A lockfile-only PR (`composer.lock` / `package-lock.json` alone) skips this step — it has no manifest to inspect.
3. **CI must be green** — the exemption replaces the review, not the build: a dependency bump fails at runtime, not at read time, so the test suite is the only evidence carrying this merge. A real CI failure blocks exactly as on any other PR; the billing exception below is the sole sanctioned relaxation and applies here unchanged.

The exemption covers the code-review gate **and nothing else**. No conflicts, not a Draft, required approvals present, and branch up to date all still apply, and an explicit "merge anytime" request does not widen it. When a merge proceeds under this exemption, record in the merge report that the code-review gate was exempted as a dependency-only PR, and list the changed files that prove it.

#### GitHub Actions billing exception (green local build required)

A single, narrow exception relaxes the CI-passing check. It exists because a billing / account-limit failure means the jobs **never ran** — it carries no information about the code, so treating it as a red build blocks every merge indefinitely for a reason unrelated to quality. The exception does not waive the evidence; it **substitutes** it:

- **Substitute evidence is mandatory.** Run the project's full local quality gate (`composer build`, or the project's equivalent) on the **exact head commit being merged**, and require it to pass. A billing failure removes the CI signal; it does not remove the requirement for one. If the local build cannot be run, or does not pass, **do not merge** — the exception has no effect. Record the command and its result in the merge report so the substitution is auditable. The only thing that lifts this requirement is the explicit *merge anytime* request below.
  - **Savings-mode cache reuse (opt-in, never a weaker check).** When the shared brief records `## Savings mode: on` (`@rules/compound-engineering/general.md` *Savings mode*), this requirement may be satisfied by a cached passing entry from the brief's `## Build gate cache` **only when that entry's recorded hash exactly equals the tree hash of this exact head commit** (`git rev-parse HEAD^{tree}`, mixed with the non-tracked build inputs per that section's canonical hash definition — a bare commit SHA can never equal a tree hash, so the comparison is always tree-to-tree) **and the entry carries this-run provenance** — the command that produced it, its exit status, a verbatim tail of its output, the producing step, and this run's identifier; an entry missing any of that provenance, or written by a different run, does not count. A cache entry recorded for any earlier commit, or missing provenance, does not count, and a miss always requires running the full build here, now, on this exact head SHA before merge. The cache never removes this evidence requirement; it only lets an already-proven-identical, attributable execution stand in for repeating it.
- **Explicit "merge anytime" request waives the substitute build.** When the caller's instruction for this merge run **explicitly** demands the merge proceed regardless of CI availability — wording such as *"merge anytime"*, *"merguj kdykoliv"*, or an equivalent unambiguous "merge now, do not wait for CI" directive — the confirmed billing / account-limit entries are ignored **without** requiring the green local build: the explicit instruction itself stands in as the authorization the substitute evidence would otherwise provide. This waiver is strictly **billing-only** and changes nothing else: the conservative detection below must still confirm every blocking entry is unambiguously a billing / account-limit failure (an ambiguous or real failure blocks exactly as before), and every other gate — converged code review, verified security coverage, non-Draft, no conflicts, approvals, up-to-date branch — applies unchanged. A general "merge this PR" request is **not** an explicit "merge anytime"; absent the explicit wording, the green local build remains mandatory. When the waiver is used, record in the merge report that the local build was waived by the caller's explicit "merge anytime" request, alongside the ignored billing entries.
- **Verify the jobs truly did not start.** A billing-blocked job fails in seconds with no executed steps. Confirm it from the run's annotations (`gh api repos/<owner>/<repo>/check-runs/<id>/annotations`) rather than inferring it from a red X — a real failure and a never-started job look identical in the checks list.
- **When it applies:** the *only* blocking entries in `statusCheckRollup[]` are GitHub Actions runs that did **not** execute because of a billing / account-limit problem — typically a `state` of `ERROR` (or a workflow that never started) whose detail message is an unambiguous billing notice such as *"The job was not started because recent account payments have failed or your spending limit needs to be increased"*, *"billing"*, or *"spending limit"*. In that case the gate **ignores those specific entries** and allows the merge.
- **Detection must stay conservative.** Treat an entry as a billing failure only when its message clearly names a billing / payment / spending-limit cause. A bare `ERROR` / `FAILURE` with no billing wording is a **real** failure — never assume billing. When in doubt, do not merge: report the ambiguous entry and stop.
- **The exception is billing-only.** It never relaxes any other gate: a missing or non-converged code review (the hard CR gate above), a Draft PR (`isDraft == true`), a real CI failure (tests, lint, static analysis) on any non-billing entry, `mergeStateStatus == "DIRTY"` / `"BEHIND"`, an unmergeable state, or `reviewDecision != "APPROVED"` still blocks the merge regardless of the explicit request — including an explicit "merge anytime" request, which waives only the substitute local build for confirmed billing entries and nothing else.
- **Report what was waived.** When the merge proceeds under this exception, list each ignored billing entry (check name + the billing message) in the output so the waiver is auditable.

Without passing substitute evidence — or the caller's explicit "merge anytime" request standing in for it — this exception does not apply: a billing failure then blocks like any other failing check. The exception never converts "we could not measure" into "it is fine"; it only allows a different, equally strict measurement, or an explicit, auditable decision by the caller to merge without one.

### 3. Merge

- Merge PR using CLI
- Use project default merge strategy

### 4. Post-merge

- Delete branch (if configured)
- **Remove worktree (opt-in only)** — if an isolated git worktree was explicitly created for this work unit (per `@rules/git/general.md` *Worktrees / Workspaces*), remove it now that the merge is complete:
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
- Never bypass CI or review gates — a converged code review (0 Critical + 0 Moderate) on the final diff is a mandatory precondition for every merge, exempt only for a dependency-only PR, where green CI carries the merge instead
- Merge only fully ready PRs
- Be explicit about skipped PRs
