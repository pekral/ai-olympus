---
name: create-issues-from-text
description: Use when break down assignment into multiple structured issues
license: MIT
metadata:
  author: Petr Král (pekral.cz)
---

## Constraints
- Preserve original assignment (store in parent or first issue)
- Do not implement code
- Assign all issues to current user
- Use CLI tools

---

## Execution

### 1. Analyze Assignment
- Understand scope and dependencies
- Identify logical implementation steps, and cut them as *Split into vertical slices* below defines

### 2. Propose Breakdown
- List steps with short descriptions
- Proceed directly to step 3; do not gate on a confirmation round when the breakdown is unambiguous

### 3. Create Issues
- One issue per step
- Ensure each is independently deliverable: it delivers a working result on its own (*Split into vertical slices*)
- For every issue created — flat breakdown, EPIC parent, and sub-issues alike — select and apply the single most relevant existing label per `@rules/compound-engineering/tracker.md` *Label tracker issues, and keep the labels true*; this is additive to, and independent of, the structural `EPIC` label from *EPIC parent & sub-issues* below

### 4. Output
- Return list of created issues with URLs

---

## Split into vertical slices

This section is the single owner of how an assignment is cut into issues. `@rules/compound-engineering/backlog.md` *Decomposition mode* step 2 and *EPIC parent & sub-issues* below reference it and do not restate it.

An issue is a **vertical slice** (a "tracer bullet"). It goes through every layer the feature needs — migration, model, Repository / ModelManager, Action, controller or Livewire component, UI, test — and delivers a working piece that is deployable on its own. A **horizontal** issue (a backend issue, then a frontend issue) is not a slice. The first one merges with no visible effect. The second cannot be tested until the first lands. A design mistake shows only when the two are joined.

1. **The first issue is the simplest case end to end.** It proves the design holds across all layers before any later issue builds on it.
2. **Each later issue adds one behaviour on top of a working slice.** It is again a full cut through the layers it touches, and it leaves the codebase working.
3. **Split by behaviour, never by layer or by application area.** Do not create one issue per layer. Do not bundle two unrelated behaviours into one issue either.

Example for "export orders":

- #1 Export the selected orders to CSV (the simplest case, end to end).
- #2 Add a date filter to the export.
- #3 Run the export in the background through the queue for large volumes.

### A wide refactor — expand, migrate, contract

A refactor that touches too many places for one pull request (for example renaming a column `status` to `state` that 40 places use) is split in time, not by layer. Every phase is its own issue, and each issue lists the phase before it in `## Dependencies`.

1. **Expand.** Add the new form next to the old one and write both. Nothing breaks.
2. **Migrate.** Switch the callers to the new form in batches. Each batch is its own issue and one pull request, small and green, and lists Expand in `## Dependencies`.
3. **Contract.** When nothing reads the old form any more, remove it. The Contract issue lists every Migrate batch in `## Dependencies`.

For a database column, `@rules/sql/optimalize.md` *Deploy-safe schema changes* owns the expand → backfill → contract deploy order. This section only splits the work into issues along that order. The backfill ships with the Expand issue (or as its own issue right after it), and every Migrate issue that switches a reader lists it in `## Dependencies`.

---

## Issue Structure

Use the template defined in `templates/issue-structure.md`.

---

## EPIC parent & sub-issues

Use this when the assignment bundles **several behaviours that are each delivered as their own vertical slice** (e.g. an export, its date filter, and its background run). Instead of a flat list of peer issues, build a parent → children tree so the whole effort is trackable from one place.

> **A human approves the tree before any of it is created (L2).** Present one confirmation package first — the parent's title and scope, one line per proposed sub-issue, the dependencies, and the resolve order — and wait for the user to approve it in words. Only that approval permits the writes below, and only for the pieces it names. Silence is not approval, the original request is not approval, and an approval given for one package does not carry to a changed split. Without it, report the proposed breakdown and stop: the pieces are in the report, so nothing is lost. A **flat** list of peer issues is outside this gate and keeps its existing consent (`@rules/compound-engineering/backlog.md` *Decomposition mode*).

1. **Pick (or create) the parent.** When the assignment already has a tracker item, that item becomes the EPIC parent. When the assignment is a described task with no tracker item, create the parent issue first, carrying the full original assignment in its body.
2. **Label the parent `EPIC`.** Apply the `EPIC` label to the parent. Create the label once if the repository does not have it yet (`gh label create EPIC --description "Tracks a cross-cutting effort split into sub-issues" --color 5319e7`), then ignore the "already exists" outcome on subsequent runs.
3. **Create one sub-issue per vertical slice.** Cut the sub-issues as *Split into vertical slices* above defines. Each sub-issue is an independently deliverable assignment (same `templates/issue-structure.md` structure) that delivers a working piece through every layer it needs. Never create one sub-issue per layer or per application area.
4. **Link every sub-issue back to the parent (both directions).**
   - In each sub-issue body, reference the parent with `Part of #<parent>` so the relationship is visible from the child.
   - In the parent body, keep a checkable task list of the children — `- [ ] #<child>` per sub-issue — under a `## Sub-issues` heading, so the parent shows the full breakdown and progress.
5. **Order.** Fill each sub-issue's `## Dependencies` so a resolving run (e.g. `krang`) can pick a dependency-aware order — dependencies before dependents.
6. **Output.** Return the `EPIC`-labelled parent URL plus the list of linked sub-issue URLs and the planned resolve order.
