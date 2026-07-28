# Pre-push Quality Gates

Before committing and pushing changes, run project fixers and checkers on changed files. Discover available tooling using this priority:

1. **Phing** — check for `build.xml` or `phing.xml` in the project root. If present, list available targets (`phing -l`) and use relevant fixer/checker targets.
2. **Composer scripts** — if Phing is not available, inspect `composer.json` `scripts` section for fixer and checker commands (e.g. `fix`, `check`, `build`, `pint-fix`, `phpcs-fix`, `rector-fix`, `pint-check`, `phpcs-check`, `rector-check`, `test:coverage`).

Run in this order:
1. **Fixers** — run all available fixers on changed files (e.g. code style, rector, normalize). Fix any issues they report.
2. **Checkers** — run all available checkers/analyzers on changed files (e.g. code style check, static analysis, audit). Resolve all reported errors before proceeding.
3. **Coverage** — if a coverage command exists, run it and confirm 100% coverage for changed code paths.

If both fixers and checkers fail or are not found, stop and inform the user.

## Loop gate vs. final gate (issue #65)

A review/fix loop runs these gates **many times** — once per iteration — so the per-iteration gate must stay cheap without lowering the bar of the *merged* result. Distinguish the two:

- **Loop iteration (cheap, diff-scoped).** After applying a fix inside a review loop or a pre-PR self-check, run the **changed-files** variants only, and **skip the dependency/skill reinstall step** (`vendor/bin/agent-skills install --force`, `composer install`, `npm install`) — nothing the loop does changes the installed dependencies or skills, so reinstalling every iteration is pure latency. Prefer the project's diff-scoped scripts when they exist (`composer check:changed`, `composer test:coverage:diff`, or the equivalent) over the full `composer build` / `composer check`. Coverage is asserted **on the changed lines only** (never the full-suite `--min=100`) during a loop iteration.
- **Final gate (full, before push / PR).** Run the project's **full** build once — `composer build` (install + fixers + full `check`, including full-suite `--min=100` coverage) — as the last step before the changes are pushed or the PR is opened, and also whenever the change is **broad** (touches shared / core / config surface, or more than ~10 files — the same high-risk heuristic `apollon` uses). This is the gate that guarantees a merge never lands with a broken project (issue #75); the loop lightening above never removes it, it only stops re-running it on every intermediate iteration.

The rule is one full build at the boundary, diff-scoped checks in the loop — not a full build per iteration, and not a merge on diff-scoped checks alone.

### CI-result reuse for the loop gate (issue #124)

The **loop iteration** gate above may skip re-running a check locally when CI already validated it — mirroring the "Reuse CI results when available" pattern already established for the coverage gate (`@rules/code-review/general.mdc` *Validation & Coverage Gate* → Coverage gate), extended here to the fixer/checker execution itself.

- **Reuse CI results when available.** Before running a loop-gate checker, inspect the `statusCheckRollup[]` loaded from the PR JSON (via the deterministic loader scripts) and, when a per-step verdict is needed, the run log (`gh run view`). For every check CI actually runs and reports **green** on the commit currently being processed, reuse that result directly instead of re-executing it locally.
- **Staleness guard (mandatory, exact match — no heuristics).** Reuse is valid only when the CI run's validated head SHA equals the **exact** commit being processed: the working tree must be clean (no local changes since, staged or unstaged) and the current commit's SHA must equal that validated SHA (no new commits since that CI run either). This is the same guard already defined for the coverage gate — never a "similar enough" comparison. Any local edit invalidates reuse for every check it could affect; run the check locally instead.
- **Checks CI never runs are never reused.** A project's own full local gate can include steps its CI workflow does not execute at all — in this repository, `skill-check`, `composer-normalize-check`, and `shell-self-tests` are part of `composer.json`'s `@check` but are absent from `.github/workflows/pr.yml`'s 6 steps (`security-audit`, `phpcs-check`, `pint-check`, `rector-check`, `analyse`, `test:coverage`). There is never a CI result to reuse for a check CI does not run — it always executes locally regardless of CI status elsewhere.
- **Failing / incomplete CI.** A check reporting `FAILURE` / `ERROR` / missing / `IN_PROGRESS` on the commit being processed is never treated as a pass — run it locally, or record the gap as a blocking finding.
- **Scope: loop gate only, never the final gate.** This reuse applies solely to the loop-iteration checks above. The **final gate** always runs in full regardless of CI status — a freshly authored, not-yet-pushed commit has no CI result at all (there is nothing to reuse until it is pushed and CI completes), so CLAUDE.md's "before push run `composer build` and fix all errors, never ignore errors!" mandate is never weakened by this optimization.

### Savings-mode build-gate cache (opt-in, issue #119)

When a shared task brief is present and records `## Savings mode: on`, the **final gate** above may cite a cached passing result from the brief's `## Build gate cache` instead of re-running. The canonical hash definition (a **tree** hash via `git rev-parse "$(git stash create)^{tree}"`, mixed with non-tracked build inputs such as `composer.lock`), the hit / miss / failing-entry semantics, and the per-brief append lock all live in `@rules/compound-engineering/general.mdc` *Savings mode* mechanism 2 — this file adds only the local condition: the mechanism applies to this file's own **final gate** (never the diff-scoped loop gate), and a fresh full run's result is appended here exactly as that mechanism describes.

**This never applies to the mandatory full run on the exact final head SHA immediately before merge** (`@skills/merge-github-pr/SKILL.md` *GitHub Actions billing exception*) — a cache hit is acceptable there only under that skill's own *Savings-mode cache reuse* provenance requirements. Any run with savings mode off, or with no shared brief at all (no `daidalos` orchestrating the run), always executes the final gate in full, exactly as before this mechanism existed.
