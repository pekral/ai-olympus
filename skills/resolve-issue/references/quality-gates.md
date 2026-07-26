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

### Savings-mode build-gate cache (opt-in, issue #119)

When a shared task brief is present and records `## Savings mode: on` (`@rules/compound-engineering/general.mdc` *Savings mode*), the **final gate** above may reuse a cached result instead of re-running the full build: before running it, compute a content hash of the current working tree (`git stash create`, falling back to `git rev-parse HEAD` on a clean tree) and check the brief's `## Build gate cache` section for an entry whose recorded hash exactly matches. A hit on a **passing** entry lets this step cite the cached result instead of re-running; a miss (no entry, or the tree changed since) always triggers a fresh full run, whose result — hash, pass/fail, and which step produced it — is then appended to the brief for the next step to reuse.

**This never applies to the mandatory full run on the exact final head SHA immediately before merge** (`@skills/merge-github-pr/SKILL.md` *GitHub Actions billing exception*) — a cache hit is acceptable there only when its recorded hash is the hash of that exact final commit. Any run without a shared brief (savings mode off, or no `daidalos` orchestrating the run) always executes the final gate in full, exactly as before this mechanism existed.
