# Code Review

> **Section visibility — render only sections that have content.** Always render the header block (Status / Counts / Last updated / Linked-tracker mirror) and the final `Summary` line. The `Coverage:` header line, the `## Coverage` section, and the `coverage …` slot in the summary line are conditional — render them **only** when the coverage gate produced something to report (uncovered changed lines or unavailable / non-runnable tooling, both Critical findings per `@skills/code-review/SKILL.md` Coverage gate). When every changed line is at 100% coverage and the tool ran successfully, drop all three coverage surfaces; the Counts line is the clean signal. The `## Architecture` section follows the same conditional rule (issue #530): on Laravel projects the walk runs on every CR run, but the heading is rendered **only when the walk produces at least one finding** — when the walk is clean, omit the heading entirely (no "walked, 0 findings" line, no "clean" placeholder, no confirmation that the check ran). On non-Laravel projects (`laravel/framework` not in `composer.json` `require`), omit the `## Architecture` section entirely. Every section is conditional: omit its heading and body entirely when it has no items. Never emit `None.` / `Not applicable.` / `n/a` / `100%` / `walked, 0 findings` placeholders for empty sections or omitted coverage surfaces — drop them entirely. The Counts line in the header is the single source of "zero" signal; the goal is a clean, scannable PR comment a human can read at a glance — only items that still need action remain in the body.
>
> **Always-new comment:** this template is rendered into a fresh comment on every CR run. The hidden marker `<!-- cr-comment:actor=<gh-login> -->` (auto-appended by `skills/code-review-github/scripts/upsert-comment.sh`) stays in the body for per-actor traceability but does not drive an in-place edit — each run POSTs a new comment, so the PR thread keeps a chronological audit trail of CR outputs. The `Last updated` line below carries this run's timestamp.

**Status:** clean / needs-fix
**Counts:** Critical {n} · Moderate {n} · Minor {n} · Refactoring {n}
**Coverage:** {result} (tool: {name or "not available — <reason>"})  *(render this line only when the `## Coverage` section is rendered — i.e. uncovered changed lines or unavailable tooling)*
**Last updated:** {ISO-8601 timestamp of this CR run}
**Linked-tracker mirror:** {posted JIRA summary on <KEY> (+ mirrored to GitHub issue #N) | JIRA only — no linked GitHub issue | failed: <reason>}

---

## Technical Review

> Strict, rule-by-rule compliance check of the diff against every applicable project rule (`@rules/**/*.mdc`) — architecture, security, style, refactoring, testing. Wraps `## Findings` through `## Coverage` below, unchanged in content and conditional-rendering behavior (see `@rules/code-review/general.mdc` *Two-Part CR Output — Technical & Functional Review*). This heading always renders, even when every subsection beneath it is empty — the header block's `Status: clean` / `Counts: Critical 0 · Moderate 0 · Minor 0` above is the "nothing to fix" signal in that case.

## Findings

> Render only when at least one Critical, Moderate, or Minor finding exists. Within this section, render only the severity sub-headings that have items — omit the others entirely. When all three severities are empty, omit the entire `## Findings` parent heading.

### 🔴 Critical 1. <short title>

- **Location:** `path/to/file.php:42`
- **Rule:** `@rules/<area>/<file>.mdc#<section>`
- **Impact:** one sentence — what breaks or what risk this introduces.
- **Faulty Example:**
  ```php
  // minimal code or input that reproduces the issue (no secrets / PII)
  ```
- **Expected behavior:** single assertable statement (return value, thrown exception, persisted state, emitted event).
- **Test hint:** test layer (unit / integration / feature) + entry point, in one sentence.
- **Suggested fix:**
  ```php
  // minimal corrected snippet — must comply with @rules/php/core-standards.mdc (and @rules/laravel/architecture.mdc on Laravel projects). Use `n/a — <reason>` only when a snippet adds no value.
  ```

### 🟠 Moderate 1. <short title>

(same six fields as Critical)

### 🟡 Minor 1. <short title>

- **Location:** `path/to/file.php:42`
- **Note:** one sentence. Faulty Example / Expected behavior / Test hint / Suggested fix may be omitted when no behavior change is implied.

---

## Documentation Requests

> Render only when **Third-Party API & Service Analysis** step 7 in `@skills/code-review/SKILL.md` produced at least one blocking documentation request — i.e. the ordered source walk in step 2 resolved no reference for a third-party contract the diff touches. Each entry accompanies (never replaces) the Moderate finding raised for that contract; it is what lets the author close it with a single link. Omit the entire section when every affected contract resolved a reference — no `None.` placeholder.

1. **Vendor / service:** {name of the API, SDK, or webhook provider}
   **Version in use:** {resolved version + where it was read from — `composer.json` / lock file / pinned API version in config — or `could not determine`}
   **Verifying:** the concrete endpoints / SDK methods / webhook events / message contracts under review, one per line
   **Needed:** a link to the official documentation for that version covering the items above.

---

## Refactoring (DRY / tech debt)

> Render only when at least one in-scope refactoring item exists. Only items on lines touched by this PR. Each item must reduce tech debt — no stylistic preferences. Omit the entire section when there are no items.

1. **Location:** `path/to/file.php:42`
   **Problem:** one sentence.
   **Refactor:** concrete consolidation step (Data Builder / DTO / Service / Action / Repository / ModelManager).
   **Why:** rule reference (`@rules/laravel/architecture.mdc#<section>` or `@skills/class-refactoring/SKILL.md`) satisfied by the change.

---

## Refactoring proposals

> Render only when at least one out-of-scope structural improvement is justified by a rule. Omit the entire section when there are no items.

1. **Title:** short, actionable issue title
   **Scope:** affected file(s) or area
   **Reason:** rule violated + why it matters
   **Approach:** brief description

---

## Database Analysis

> Render only when the diff touches database operations (raw SQL, Eloquent / query-builder calls, eager loads, model scopes, ModelManager / Repository methods, migrations, seeders, DynamoDB / NoSQL access) **and** at least one finding is produced by `@skills/mysql-problem-solver/SKILL.md`. Omit the entire section when no DB operations are present in the diff, or when DB ops are present but no findings result — never leave a placeholder or fold it into Coverage. Report only findings and their fix recommendations — never the trigger decision, an inspected `file:line` list, or an EXPLAIN / static-analysis summary.

- **Findings:**
  1. **{Critical / Moderate / Minor}** — `file:line` — one-sentence problem
     **Suggested Fix:** {one-sentence fix category — query rewrite to reuse an existing index per `@rules/sql/optimalize.mdc`, batch operation per "Batch over per-row operations", or new-index proposal justified by EXPLAIN when no existing index covers the query}
     ```sql
     -- concrete rewritten query, index DDL, or batch-operation replacement implementing the fix above (issue #132) — never a category label alone
     ```

---

## Architecture

> **Laravel-only, conditional on findings (issue #530).** On every Laravel project (`laravel/framework` is in `composer.json` `require`), the architecture walk per `@skills/code-review/SKILL.md` Core Analysis "Architecture conformance (Laravel) — mandatory standalone walk-through" runs on every CR run, but this section is rendered **only when the walk produces at least one finding**. When the walk is clean, omit the entire `## Architecture` heading and body — do not render a `walked, 0 findings` status line, a `clean` placeholder, or any other confirmation that the check ran. On non-Laravel projects, omit the entire `## Architecture` section as well.
>
> Render findings under the standard severity sub-headings (Critical / Moderate / Minor) with the same six reproducer fields used in `## Findings`.

### 🔴 Critical 1. <short title>

(same six fields as `## Findings` — Location / Rule / Impact / Faulty Example / Expected behavior / Test hint / Suggested fix)

### 🟠 Moderate 1. <short title>

(same six fields as Critical)

### 🟡 Minor 1. <short title>

- **Location:** `path/to/file.php:42`
- **Rule:** `@rules/laravel/architecture.mdc#<subsection>`
- **Note:** one sentence. Faulty Example / Expected behavior / Test hint / Suggested fix may be omitted when no behavior change is implied.

---

## Coverage

> Render this section **only** when the coverage gate produced something to report — uncovered changed lines (Critical findings) or unavailable / non-runnable coverage tooling (Critical finding). When every changed line is at 100% coverage and the tool ran successfully, omit the entire `## Coverage` section, the `Coverage:` header line, and the `coverage …` slot in the summary line — the Counts line is the clean signal.

- **Tool:** {discovered coverage command name, or "not available — <reason>"}
- **Command:** `<exact command run>`
- **Result:** {list of uncovered added/changed lines — which must also appear as Critical findings — or "coverage tooling unavailable — <reason>"}

---

## Functional Review

> Always rendered — never omitted, the one exception to the omit-empty-section convention that governs `## Technical Review` above (see `@rules/code-review/general.mdc` *Two-Part CR Output — Technical & Functional Review*). Computed from the same Assignment Conformance Gate direction 1 already behind the `assignment conformance:` token on the Summary line below — no new analysis, only this explicit, always-present placement. Direction 2 (changes → requirements traceability / scope-creep) stays in `## Findings` above — it is diff hygiene, not "did the code satisfy the requirement".

{conformant → "All stated assignment requirements are satisfied." | gaps → list every Critical functional / business-logic gap below — still counted in the Counts line above}

### 🔴 Critical 1. <short title>  *(gaps case only)*

(same six fields as `## Findings` — Location / Rule / Impact / Faulty Example / Expected behavior / Test hint / Suggested fix; **Rule** cites the unmet requirement / acceptance criterion and its source instead of a `@rules/*.mdc` path)

(Repeat for every gap.)

---

**Summary:** {n} Critical · {n} Moderate · {n} Minor · {n} Refactoring · assignment conformance: {conformant | N gap(s) | no linked issue}{` · coverage {result}` — appended only when the `## Coverage` section is rendered; omitted on a clean 100% pass}{` · security: owned by athena (<url of athena's security comment>)` — appended **only** when the inline `security-review` pass was skipped because the caller set `SECURITY_OWNER=athena`; omitted when the pass ran here. **The URL is mandatory**: the token records a delegation, and without a link to the delivered review there is nothing to distinguish a security pass that ran from one that died mid-run. A token with no URL is itself the visible gap, and `@skills/merge-github-pr/SKILL.md` blocks the merge on it} · {linked-tracker mirror status}
