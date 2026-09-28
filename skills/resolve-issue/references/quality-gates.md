# Quality Gates

Project fixers and checkers run **once per branch, at the merge boundary** — not before every push. This file is the one place that defines how the project's gate and coverage commands are discovered; every other file points here. Discover them in this order, and resolve the gate and the coverage command separately — each comes from the first source that defines it:

1. **Project manifest** — read `extra.ai-olympus` with `skills/_shared/read-manifest.sh` (`@rules/general/general.md` *Project manifest*). The script reads the default-branch copy, never the working tree of a branch under review. When the manifest sets `gate`, that list is the project's full gate: run its commands in the listed order, and every command must pass. When the manifest sets `coverage`, that command is the project's coverage command.
2. **Phing** — when the manifest sets no `gate`, check for `build.xml` or `phing.xml` in the project root. If present, list available targets (`phing -l`) and use relevant fixer/checker targets.
3. **Composer scripts** — when neither source applies, inspect `composer.json` `scripts` section for fixer and checker commands (e.g. `fix`, `check`, `build`, `pint-fix`, `phpcs-fix`, `rector-fix`, `pint-check`, `phpcs-check`, `rector-check`, `test:coverage`).

Before you run a gate or coverage command, whichever source supplied it, export exactly the `NAME=value` lines `skills/_shared/read-manifest.sh --env` prints — never the raw manifest `env`. When that call exits `4`, it refused a variable that changes which program runs (`PATH`, `GIT_*`, `LD_*`, …): stop and report the refused name instead of running the gate.

A manifest `gate` already fixes the order: run its commands as listed. Otherwise run in this order:
1. **Fixers** — run all available fixers (e.g. code style, rector, normalize). Fix any issues they report.
2. **Checkers** — run all available checkers/analyzers (e.g. code style check, static analysis, audit). Resolve all reported errors before proceeding.
   **Resolve means change the code, never silence the tool.** A `phpcs:ignore`, `@phpstan-ignore`, `@psalm-suppress`, `@SuppressWarnings`, a new baseline / `ignoreErrors` line, or a PHP `@` operator must never enter the diff — `@rules/php/core-standards.md` PHP Practices admits no exception, and a new suppression annotation is a **Critical** review finding. Narrow a type, split a method, introduce a DTO, or assert an invariant the analyser cannot infer. For a genuine false positive in a surface the project does not own, add one scoped entry to the project's own tool configuration naming the single rule and the single path, with a comment naming the external contract that forces it.
When neither works, **stop and report it** — state what the checker flags, what was tried, and why neither route resolved it, and let a human decide. Never write the suppression to get the gate green.
3. **Coverage** — if a coverage command exists, run it and confirm 100% coverage for changed code paths.

If the manifest sets no `gate` and both fixers and checkers fail or are not found, stop and inform the user.

## A flaky test outside the diff and the assignment is left alone

A flaky test fails on one run and passes on the next, on the same commit, with no change in between. When such a test has nothing to do with the task, fixing it widens the pull request and delays the finish for a problem the task did not create.

1. **Confirm it is flaky.** Re-run the failing test alone on the same commit. It passes → it is flaky. It fails again → it is a real failure, and the gate handles it as any other failure.
2. **Check that it is unrelated.** The test is unrelated only when all of these hold:
   - the PR's diff does not add or change the test file;
   - the test does not cover code the PR changes, and does not call code that the changed code calls;
   - the test does not belong to the area the assignment describes.

   When one of these does not hold, or it is unclear, the test is related: find and fix the cause per `@rules/code-testing/general.md` *Flaky Test Prevention*.
3. **Unrelated → ignore it.** Do not modify, skip, or delete the test, and do not file an issue for it. The failure does not block the gate when the re-run in step 1 passed. Name the test, the failure message, and the passing re-run in the handoff and in the merge report.

## HOTFIX — what the mode relaxes here

A caller may declare a run a HOTFIX (`@rules/compound-engineering/orchestration.md` *HOTFIX — the declared emergency path*). The mode waives the coverage gates and nothing else; it is declared by the caller and never inferred from how urgent the assignment sounds.

- **Step 3 does not block.** Run the coverage command when one exists, record the figure, and proceed even when it falls short. Steps 1 and 2 are untouched — a fixer rewrite, a checker error, a static-analysis error, and a failing test all block a hotfix exactly as they block any other change, because a hotfix that breaks the default branch is a second outage.
- **The reproduction stays; the committed test becomes optional.** `@skills/resolve-issue/SKILL.md` *If bug* requires a failing test before the fix. Under HOTFIX that relaxes to: observe the failure before the fix and its absence after, by whatever is fastest — a test, a one-off script, or the running application — and state which was used in the handoff and the pull request.
- **Write the regression test when it is cheap.** When it is not, say so in the pull request, so the gap is visible rather than assumed. Everything else in the bug branch is unchanged, the data repair included.

## Gate placement — deferred to the merge boundary (issue #65, revised)

A branch used to run the project's full build several times: once per implementation phase, once before the PR opened, and once per review-loop iteration. Every one of those runs proved the same thing the next one would prove again, and on a larger task the repeated full builds dominated the wall-clock cost of delivering the change. The gate now runs **once, immediately before the merge**, and the fixes it produces land as their own commit.

- **During implementation and during the review loop — no gate.** Do not run fixers, checkers, or the full build while authoring commits, after applying a review fix, or before pushing. A push is not a gate boundary: nothing is released by it, and the branch is still being worked on. Author the change, commit it, push it.
- **Once the work is finished — the full gate, once.** The project's full gate (discovered in the order at the top of this file — install + fixers + full `check`, including full-suite coverage) runs after the code review has converged, before the pull request is offered as ready. `@skills/process-code-review/SKILL.md` *Finalization* owns that run: the review loop deliberately ran no fixers and no checkers, so this is the first point where they execute, and the fixes they produce land as the branch's last commit.
- **The merge re-checks rather than re-runs.** `@skills/merge-github-pr/SKILL.md` *Pre-merge quality gate* is the last safety net before an irreversible action: it accepts the recorded Finalization run only when all four of that step's conditions hold — the record is authentic, names this exact head commit, is a pass, and the tree is clean — and it runs the gate itself otherwise — for a PR that never went through the review loop, or one whose head moved afterwards. Together the two guarantee a merge never lands with a broken project (issue #75) while the gate still executes only once per set of bytes.
- **Fixes from the gate land as a new commit.** When the gate reports anything — a fixer rewrote a file, a checker flagged an error, coverage fell short — resolve it and commit the result as a **new commit** on the branch (`chore(gate): apply pre-merge fixer and checker fixes`, or a `fix(scope):` subject when the resolution changed behaviour). Never amend a commit already under review, and never force-push a branch a reviewer has commented on (`@rules/git/general.md`).
- **Re-run the gate after the fix commit.** The fix commit is a new tree, so the gate has not passed on it yet. Re-run the full build on the new head and repeat until it is green on the exact commit being merged. A merge proceeds only on a head commit whose own gate run passed.
- **A behaviour-changing fix re-opens the code review.** Whether the fix commit invalidates the converged review depends on what it changed, and the distinction is load-bearing:
  - **Tool-generated formatting only** — the commit contains nothing but the verbatim output of the project's fixers (code style, import order, normalization) with no hand-written change. The converged review still stands; record in the merge report which fixer produced the commit, so the exemption is auditable.
  - **Anything else** — a static-analysis error resolved by hand, a failing test, a coverage gap closed with new test code, or a `rector` rewrite that changed behaviour rather than formatting. Recompute the effective PR diff fingerprint. When it differs from `Reviewed diff fingerprint:`, this is a real change to reviewed content: the code-review gate is **stale** and the review must be re-run to convergence (`@skills/code-review-github/SKILL.md` + `@skills/process-code-review/SKILL.md`) before the merge proceeds. A new SHA with an identical fingerprint is a history-only rewrite and does not re-open CR.
  When it is unclear which of the two applies, treat the commit as behaviour-changing and re-review. The cheap outcome of a wrong guess here is one extra review; the expensive one is an unreviewed change merged under a stale approval.

The rule is one full build at the merge boundary, nothing during the branch's working life — not a full build per phase, not one per push, and never a merge on no gate at all.

## Rebase that moves the head — analyse the incoming changes first

A rebase onto the newest default branch gives the head a new SHA. The branch's own change can stay identical while only the base moved. Re-running the full gate in that case repeats every checker for changes the branch never touched, and it delays the finish of the task. Analyse what the rebase brought in before you decide.

1. **Find the last green gate run.** Take the head SHA `G` from the trusted `Quality gate:` record (the four authenticity conditions in `@skills/merge-github-pr/SKILL.md` *Pre-merge quality gate* apply to it). `H` is the current head. No trusted record, or `G` is not available locally or by `git fetch origin <G>` → run the gate.
2. **Compare the branch's own change.** Compute the effective-PR-diff fingerprint (`@rules/code-review/general.md` *Incremental Review Scope*) for `G` against its merge base and for `H` against its merge base. A different fingerprint means the branch's own change moved → run the gate, exactly as before.
3. **List the incoming changes.** `git diff --name-only "$(git merge-base G origin/$DEFAULT_BRANCH)" "$(git merge-base H origin/$DEFAULT_BRANCH)"` lists what the default branch brought in.
4. **Classify each incoming change as related or unrelated.** An incoming change is **related** when any of these holds:
   - it touches a file the PR changes, or the rebase had a conflict;
   - it changes code the PR's changed code calls, extends, or is called by, or a test that covers the PR's changed code;
   - it falls into the area the assignment describes;
   - it has a project-wide effect: a dependency manifest or lockfile, a tool or checker configuration, a test bootstrap or shared test helper, a CI workflow, or an environment or build file.

   Search the PR's changed symbols in the incoming files and the incoming symbols in the PR's files; never classify from file names alone. When the classification is unclear, the change is related.
5. **Related → the rebase behaves exactly as before.** Run the full gate on `H` (*A history rewrite re-runs the gate* in `@rules/git/general.md`).
6. **Unrelated → carry the gate verdict forward.** Do not run the fixers, checkers, or coverage again on `H`. Run only the dependency advisory audit (for example `composer audit`), because its verdict depends on when it ran. Record in the merge report: `G`, `H`, both fingerprints, the incoming file list, and the reason each change is unrelated.

The trade, stated rather than hidden: the combined tree `H` is not re-tested as a whole. The default branch's own gate covers the incoming changes, and the analysis above covers their interaction with the branch. A related change, an unclear one, or a changed fingerprint always takes the full gate.

### Retired with the repeated builds they deduplicated

Three mechanisms existed only to stop the same commit being built more than once. Deferring the gate to the end of the work removed the repeats, so all three are retired rather than left as guidance nothing can reach.

- **Head-SHA push-level dedup (issue #212, retired).** It deduplicated the full build across the three call sites that each ran one — the implementation's Finalization, `donatello`'s scoped validation, and the review loop's Finalization. All three are gone, so there is no second execution on the same commit to deduplicate; the `## Gate log` brief section it was keyed to is retired with it.
- **CI-result reuse for the loop gate (issue #124, retired).** It applied only to the per-iteration loop gate, which no longer exists — and it was already structurally unreachable in this repository, whose `pull_request` workflow checks out the merge ref and so could never satisfy its staleness guard.
- **Savings-mode build-gate cache (issue #119, retired).** It cached a passing build keyed by the working-tree hash so the *next* full build in the same run could cite it. With one gate run per branch there is no next build to serve, and the cache was left with readers and no writer. The one reuse that still matters — the merge accepting the Finalization run — is keyed to the head SHA and lives in `@skills/merge-github-pr/SKILL.md` *Pre-merge quality gate*, which needs no cache. The `## Build gate cache` brief section is retired with it.

**`security-audit` is never reused by anything.** `composer audit` queries a live advisory database at run time, so a green verdict is a function of *when* it ran, not only of *what* it read. It runs fresh on every gate execution — that was true of each retired mechanism's carve-out and stays true without them.
