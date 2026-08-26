# Quality Gates

Project fixers and checkers run **once per branch, at the merge boundary** — not before every push. Discover available tooling using this priority:

1. **Phing** — check for `build.xml` or `phing.xml` in the project root. If present, list available targets (`phing -l`) and use relevant fixer/checker targets.
2. **Composer scripts** — if Phing is not available, inspect `composer.json` `scripts` section for fixer and checker commands (e.g. `fix`, `check`, `build`, `pint-fix`, `phpcs-fix`, `rector-fix`, `pint-check`, `phpcs-check`, `rector-check`, `test:coverage`).

Run in this order:
1. **Fixers** — run all available fixers (e.g. code style, rector, normalize). Fix any issues they report.
2. **Checkers** — run all available checkers/analyzers (e.g. code style check, static analysis, audit). Resolve all reported errors before proceeding.
   **Resolve means change the code, never silence the tool.** A `phpcs:ignore`, `@phpstan-ignore`, `@psalm-suppress`, `@SuppressWarnings`, a new baseline / `ignoreErrors` line, or a PHP `@` operator must never enter the diff — `@rules/php/core-standards.md` PHP Practices admits no exception, and a new suppression annotation is a **Critical** review finding. Narrow a type, split a method, introduce a DTO, or assert an invariant the analyser cannot infer. For a genuine false positive in a surface the project does not own, add one scoped entry to the project's own tool configuration naming the single rule and the single path, with a comment naming the external contract that forces it.
When neither works, **stop and report it** — state what the checker flags, what was tried, and why neither route resolved it, and let a human decide. Never write the suppression to get the gate green.
3. **Coverage** — if a coverage command exists, run it and confirm 100% coverage for changed code paths.

If both fixers and checkers fail or are not found, stop and inform the user.

## Gate placement — deferred to the merge boundary (issue #65, revised)

A branch used to run the project's full build several times: once per implementation phase, once before the PR opened, and once per review-loop iteration. Every one of those runs proved the same thing the next one would prove again, and on a larger task the repeated full builds dominated the wall-clock cost of delivering the change. The gate now runs **once, immediately before the merge**, and the fixes it produces land as their own commit.

- **During implementation and during the review loop — no gate.** Do not run fixers, checkers, or the full build while authoring commits, after applying a review fix, or before pushing. A push is not a gate boundary: nothing is released by it, and the branch is still being worked on. Author the change, commit it, push it.
- **Immediately before the merge — the full gate, once.** The project's full build (`composer build`, the Phing target, or the project's equivalent — install + fixers + full `check`, including full-suite coverage) runs on the exact head commit that is about to be merged. This single run is the boundary that guarantees a merge never lands with a broken project (issue #75). It is owned by `@skills/merge-github-pr/SKILL.md` *Pre-merge quality gate*, which is also where the fix-commit and re-review rules below are executed.
- **Fixes from the gate land as a new commit.** When the gate reports anything — a fixer rewrote a file, a checker flagged an error, coverage fell short — resolve it and commit the result as a **new commit** on the branch (`chore(gate): apply pre-merge fixer and checker fixes`, or a `fix(scope):` subject when the resolution changed behaviour). Never amend a commit already under review, and never force-push a branch a reviewer has commented on (`@rules/git/general.md`).
- **Re-run the gate after the fix commit.** The fix commit is a new tree, so the gate has not passed on it yet. Re-run the full build on the new head and repeat until it is green on the exact commit being merged. A merge proceeds only on a head commit whose own gate run passed.
- **A behaviour-changing fix re-opens the code review.** Whether the fix commit invalidates the converged review depends on what it changed, and the distinction is load-bearing:
  - **Tool-generated formatting only** — the commit contains nothing but the verbatim output of the project's fixers (code style, import order, normalization) with no hand-written change. The converged review still stands; record in the merge report which fixer produced the commit, so the exemption is auditable.
  - **Anything else** — a static-analysis error resolved by hand, a failing test, a coverage gap closed with new test code, or a `rector` rewrite that changed behaviour rather than formatting. This is a real code change on a reviewed diff: the code-review gate is **stale** and the review must be re-run to convergence (`@skills/code-review-github/SKILL.md` + `@skills/process-code-review/SKILL.md`) before the merge proceeds.
  When it is unclear which of the two applies, treat the commit as behaviour-changing and re-review. The cheap outcome of a wrong guess here is one extra review; the expensive one is an unreviewed change merged under a stale approval.

The rule is one full build at the merge boundary, nothing during the branch's working life — not a full build per phase, not one per push, and never a merge on no gate at all.

### Savings-mode build-gate cache (opt-in, issue #119)

When a shared task brief is present and records `## Savings mode: on`, the pre-merge gate above may cite a cached passing result from the brief's `## Build gate cache` instead of re-running. The canonical hash definition (a **tree** hash via `git rev-parse "$(git stash create)^{tree}"`, mixed with non-tracked build inputs such as `composer.lock`), the hit / miss / failing-entry semantics, and the per-brief append lock all live in `@rules/compound-engineering/orchestration.md` *Savings mode* mechanism 2.

Because the gate now runs **once per branch** rather than once per phase, this cache has a single consumer: `@skills/merge-github-pr/SKILL.md` *Pre-merge quality gate*, under that skill's own *Savings-mode cache reuse* provenance requirements. A run with savings mode off, or with no shared brief at all, always executes the gate in full.

**A cache hit never covers `security-audit`.** `composer audit` queries a live advisory database at run time, so a green verdict is a function of *when* it ran, not only of *what* it read — yesterday's green run says nothing about an advisory published today. It always runs fresh, on every gate execution, regardless of any cache entry.

**Why the always-on head-SHA dedup is gone (issue #212, retired).** That mechanism deduplicated the full build across the three call sites that each used to run one — the implementation's Finalization, `hephaestus`'s scoped validation, and the review loop's Finalization. Deferring the gate to the merge boundary removes all three, so there is no longer a second execution on the same commit to deduplicate: the branch runs one gate, and the only re-run is on a *new* head commit after a fix commit, which is a different key by construction and must genuinely execute. The `## Gate log` brief section it was keyed to is retired with it.
