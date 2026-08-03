# Commit planning (one point = one commit)

Referenced from `skills/resolve-issue/SKILL.md` *Commit planning (one point = one commit)*. Extracted to keep the skill body under the skill-check token limit (issue #59).

Before writing any code, decide how the in-scope work will be split into commits within the PR, applying the **one phase = one commit** rule and its **one assignment point = one commit** clause from `@rules/git/general.mdc` *Git Rules*. The commit list is what a reviewer reads as the list of resolved points, so it is planned up front — never discovered while committing.

## 1. Inventory the points the assignment enumerates

Read the issue description together with the **current requirements** kept by the comment analysis (step 5 of the skill body) and list every discrete point the assignment asks for. Point markers:

- **Phase markers** — explicit `Phase N` headings, numbered milestones, ordered acceptance-criteria blocks, or a step-by-step plan written by the reporter.
- **Recommended fixes / action points** — a numbered or bulleted list of defects, a checklist (`- [ ]`), a review report's findings with their `Suggested Fix` blocks, or a section collecting them (`Doporučené opravy`, `Recommended fixes`, `Findings`, `To do`).
- **Individually testable acceptance criteria**, when the assignment enumerates nothing else.

**Precedence when the enumerations nest.** An assignment often carries both — a `Phase 2` heading whose body is a checklist of three fixes. The **innermost independently verifiable level is the point**; the container is not a point of its own, it only fixes the order of the points inside it. So that phase yields three commits, never four, and never one. Two markers describe one point only when they are the same change stated twice (a heading repeating its single checklist item) — that is the deduplication case below, not a nesting case.

Rules for the inventory:

- Keep the assignment's **own wording and order**. Quote each point short enough to fit a commit subject and keep a stable reference to it (`point 3`, the finding title, the checklist line) so the commit and the PR can cite it.
- **Deduplicate** points that describe the same change from two angles, and **split** a point that bundles two unrelated changes — each half becomes its own point.
- A point the run does **not** implement never becomes a commit: an *Out of scope (deferred)* item goes to the PR `## TODO` list plus a follow-up issue (*Deferred-item follow-up issues*), and a pre-existing issue gets its own `pre-existing — ` commit ordered before the in-scope commits (`references/pre-existing-issue-handling.md`).
- When the assignment enumerates nothing — one atomic bug or feature — the inventory holds one point and the work stays one commit. Never invent points to lengthen the list.

## 2. Map one point to one commit

- Each inventoried in-scope point is **exactly one commit**, in the assignment's original order. Never merge two points into one commit, never split one point across two, never re-scope or reorder them.
- Each commit is **complete on its own**: the production change for its point, the tests covering it (for a bug, the TDD failing test written first), and any doc or locale update the point requires — so the point is verifiable at that commit and nothing is left to a later fixup.
- Every commit subject is `type(scope): description` per `@rules/git/general.mdc`, in English, and names the point it resolves rather than the mechanics of the edit.

## 3. Order for independence (cherry-pick friendly — preferred, not required)

Aim for a history where each commit can be cherry-picked onto the default branch on its own and still build:

- Prefer a grouping whose commits touch **disjoint** files and symbols. The case to avoid is two commits editing the same lines — not two points living in the same directory.
- Put shared groundwork a later point needs (a new helper, a signature change, a migration) in the commit of the point that **introduces** it, ordered before the points that consume it. Never leave a commit referencing code only a later commit adds.
- When two points genuinely cannot be separated, keep them as two commits, order the dependent one after its prerequisite, and record the dependency in the plan as `depends on #N`.
- Independence is a **preference**. Never merge two points into one commit, invent an artificial split, or reorder points whose order is meaningful (a phased assignment, or a fix that must land before the test proving it) just to flatten the dependency graph.

## 4. Record the commit plan before implementing

Write the plan down **before** the first line of production code, as a numbered table — one row per commit:

| # | Assignment point | Commit subject | Files | Independence |
|---|---|---|---|---|
| 1 | point 1 — "reject an empty import payload" | `fix(api): reject an empty import payload` | `app/Http/...`, `tests/Feature/...` | independent |
| 2 | point 2 — "log every rejected payload" | `feat(api): log a rejected import payload` | `app/Http/...`, `tests/Feature/...` | depends on #1 |

This table is the commit plan for step 11 of the skill body and the source of the PR's `## Changes` section.

**Rendering the PR `## Changes` list.** The PR carries one entry per commit, in commit order, so the change list and the commit list are the same list read twice:

```markdown
## Changes

1. `fix(api): reject an empty import payload` — point 1: reject an empty payload instead of importing zero rows
2. `feat(api): log a rejected import payload` — point 2: record every rejection in the audit log (depends on 1)
```

- One line per commit — never one line per file, and never a line for work that has no commit.
- The list enumerates the **assignment-point commits** only. Two commit classes are deliberately outside it and are never appended to it: a `pre-existing — ` fix (it belongs to `## Pre-existing fixes`) and a **remediation commit pushed by the post-PR review loop** (`@skills/process-code-review/SKILL.md`), which reports its own work on the `cr-status` comment. Without this carve-out the list would go stale on the first review round, since no skill edits the PR description after the PR is open.
- Name the assignment point the commit resolves, in the assignment's wording, so a reviewer can check the assignment off against the list.
- Mark a dependency inline (`depends on <N>`) and say which commits are independently cherry-pickable when only some are; a reader deploying a subset needs that from the PR, not from the diff.
- `## Pre-existing fixes` and `## TODO` keep their own sections — a pre-existing fix commit is listed there, not in `## Changes`.

## 5. Implement point by point

Implement one point at a time and, at the end of each point, run the pre-push fixers and tests on that point's changes before committing it (`references/quality-gates.md`). Never start the next point with the previous one uncommitted — a mixed working tree is what silently merges two points into one commit.

If implementation proves the plan wrong (a point turns out to need two commits, or two points are inseparable), update the table and reflect it in the PR `## Changes` list; the committed history and the plan must never diverge.

## 6. Keep every later change in a logical commit

Work that arrives after the plan — a finding from the pre-PR review loop, a correction to a commit you already made, a follow-through the first pass missed, the CHANGELOG entry — still lands in a logical commit, per `@rules/git/general.mdc` *Every change on the branch belongs to a logical commit*. For each such change decide **amend or new** before committing it:

| The change… | Do this |
|---|---|
| completes or corrects a commit already on this branch, and that commit is not yet under review | fold it in — `git commit --amend` for the tip, `git commit --fixup=<sha>` + `git rebase --autosquash <base>` for an earlier one |
| is a separate logical unit (a different point, a pre-existing fix, the CHANGELOG) | new commit |
| corrects a commit that is already pushed **and under review** | new commit naming what it corrects — never a force-push that detaches review anchors and invalidates SHAs already cited |

Then reconcile: run `git log <base>..HEAD` against the recorded plan table and confirm every commit is one logical change and every logical change is one commit. Split a commit that turned out to bundle two; fold two that turned out to be one. Do this **before** opening the PR, while the branch is still yours to rewrite — and re-derive every short SHA the plan table and the PR description cite, since a rewrite moves all of them.

Review-loop and CHANGELOG commits stay **out** of the PR's `## Changes` checklist (that table maps assignment points), but they are still named in the PR description so no commit on the branch is unaccounted for.
