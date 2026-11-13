---
name: simplification-audit
description: "Use when the user explicitly asks for an audit of the codebase or to refactor a part of the codebase, or in `MODE=cr` over the current diff of every code review. Coordinates a bounded, read-only pass over every subsystem in scope and returns ranked simplification recommendations for data structures, state representation, control flow, algorithms, and ownership, without changing a single file."
license: MIT
metadata:
  author: "Petr Král (pekral.cz)"
---

## Constraints
- **Audit-only and read-only.** Never edit, create, or delete a file. Never run the test suite, a fixer, a static analyser, or a build. Never implement a recommendation. Never stage, commit, push, or open a pull request. Read-only inspection commands (`git log`, `git grep`, `rg`, `find`, `cat`, `sed -n`) are allowed. The repository is byte-identical when the audit ends.
- **The audit mode runs only on an explicit user request** — see *Use when*. Never start the audit mode as a side effect of another skill or agent. The one other run is *Diff mode* (`MODE=cr`) inside every code review.
- **Publishes nothing.** No tracker comment, no issue, no PR. In *Diff mode* the code review publishes the findings on changed lines inside its own comment; the skill itself still publishes nothing. An audit produces proposals, and `@rules/compound-engineering/tracker.md` *File deferred points as follow-up tracker issues* → *The filing bar* files no refactoring proposal of any kind. The report stays in this run's output; the user decides what becomes an issue.
- Apply `@rules/refactoring/general.md` — it owns what refactoring is (behaviour-preserving structural change), when not to refactor ("just to make the code look nice", "without a business reason"), and the no-big-bang-rewrite rule. Every recommendation must be implementable incrementally.
- Apply `@rules/writing/general.md` — one idea per sentence, active voice, one term per concept, no marketing register. A finding states a fact, never an adjective.
- Apply `@rules/php/core-standards.md` **only once it is established that the audited project is a PHP project (PHP stack in `composer.json`) and the recommendation touches PHP code** — skip it for a non-PHP subsystem.
- Apply `@rules/laravel/architecture.md` **when the project is a Laravel project using `pekral/arch-app-services` and the recommendation moves logic between layers** — that rule owns where business logic lives, so the ownership dimension of this audit never invents a competing taxonomy.
- **Never force an abstraction.** Boring local code that is already clear stays. Style consistency, hypothetical extensibility, line-count reduction, and moving existing branching behind a new type are not recommendations.

---

## Scope
Coordinate a bounded, read-only audit that finds materially useful simplifications in the audited scope's **data structures, state representation, control flow, algorithms, and ownership**, then rank them.

The signals a worker looks for in those five dimensions live once, in the brief it is handed — `references/worker-brief.md`. This file does not restate them.

**What this skill deliberately leaves to another skill.** It never implements: an accepted recommendation is implemented later, in a separate and separately requested run, by `@skills/class-refactoring/SKILL.md` (class-level structure) or `@skills/refactor-entry-point-to-action/SKILL.md` (entry-point orchestration extraction). A suspected defect is not a simplification — route it to `@skills/analyze-problem/SKILL.md`. A pull-request diff review is `@skills/code-review/SKILL.md`, which is tied to a PR, never triggered by a bare audit request, and runs this skill only in *Diff mode*.

---

## Use when
- The user explicitly asks for an audit of the codebase ("audit this codebase", "audit the whole repository", "prověř celý projekt", "find simplifications across the repo").
- The user explicitly decides to refactor a part of the codebase and wants the cleanest available option before any code changes.

**Never runs when:** no such explicit request was made and no code review runs. The audit mode is never a side effect of another workflow — not `@skills/resolve-issue/SKILL.md`, not `@skills/code-review/SKILL.md` or any other review skill, not `@skills/process-code-review/SKILL.md`, and not an agent pipeline step. Another skill or agent may invoke the audit mode only when that caller is itself carrying out an explicit user audit or refactoring request, and it names that request when it does. *Diff mode* is the one run a code review starts on its own.

**Scope of the audit.** The user's scope always wins:
- An audit request covers the whole repository only when it says so in words ("the whole repository", "prověř celý projekt", "across the repo").
- A refactoring request, or an audit request that names an area, covers the part the user named. The inventory boundary in step 1 is then that part plus the interfaces it exposes to the rest of the tree.
- Any other request that names no area, for example "audit this codebase", covers the hot spots chosen by change frequency (step 1 *Hot-spot scoping*).

Never widen the boundary beyond what was asked.

---

## Execution

This section is the audit mode. Steps 1, 2, and 4 and the report template belong to the audit mode only; *Diff mode* below reuses step 3.

You are the coordinator. Continue until every subsystem in scope has been reviewed and the final audit is validated.

### 1. Establish the coverage contract

**Hot-spot scoping — only when the request names no area.** A file that changes often costs effort on every future change. A rarely touched ugly file costs little, so its simplification has low value. Rank the files by change frequency before the inventory:

```bash
git log --since=<window> --name-only --format= | sort | uniq -c | sort -rn
```

1. Choose the window (for example `"6 months ago"`) and state it. Drop lockfiles, generated files, the changelog, and every path that no longer exists at `HEAD`, then keep the top 20.
2. Group the top-ranked files by subsystem. Those subsystems are the audited scope, and the inventory below covers them.
3. Record the command, the window, and the resulting ranking in the report's `## Scope` block.

Inspect the repository and inventory every identifiable subsystem in scope. Each row carries:
- a stable ID and a descriptive name;
- an exact ownership boundary;
- its key implementation files;
- its relevant public interfaces, major call sites, and tests;
- a status: `queued`, `in review`, `recommend`, or `skip`.

Include frontend, backend, shared infrastructure, platform bridges, generated-contract ownership, and test / tooling infrastructure wherever they are materially relevant to the audited scope.

Hold the whole audit in **one canonical report** — the shape lives in `templates/audit-report.md`. It carries the subsystem inventory, the confirmed opportunities, the explicit skip decisions, the cross-cutting patterns, the duplicates and superseded findings, the final priorities and dependencies, and the audit log.

This inventory **is** the coverage contract. A broad catch-all row does not prove coverage of what it claims to cover: split it until every row has a boundary a worker can hold in one pass.

Record the pre-audit `git status --short` output in the report's `## Scope` block before any subsystem review starts. That line is the baseline the read-only guarantee in *Done when* is checked against.

### 2. Run bounded subsystem reviews

- Give every worker exactly one subsystem, with an **exact, non-overlapping** ownership boundary.
- Use fresh, read-only workers where the host provides them. Where it does not, run the same bounded passes sequentially in this context, one subsystem at a time, reading only that subsystem's files per pass.
- Keep concurrency bounded to the number of lanes you can actively coordinate.
- Use one consolidated wait mechanism. Do not interrupt a productive worker merely because it is slow. Close a completed worker once its result is harvested.
- Hand every worker the quoted brief block in `references/worker-brief.md` verbatim, with its subsystem row substituted in. That block is the whole of what a worker receives, and it carries the worker's read-only limit itself. The same file also holds the eight fields every recommendation must carry.
- A worker returns **at most two** opportunities, or `skip` when nothing clearly meets the threshold. Set the row's status from what it returned.

### 3. Validate and synthesize

- **Verify every finding yourself against the current repository before accepting it.** Re-open the cited `file:line` and its surrounding context. A finding you cannot confirm in the real, current bytes does not enter the report.
- **Re-run the search behind a deletion-test or single-implementation finding.** Search the whole tree for the callers of the class under the deletion test, and for the implementations and the consumers of the interface (`git grep`, `rg`). A verdict you cannot reproduce from the current repository does not enter the report.
- Reject, narrow, or demote a recommendation that is vague, duplicates another finding, misunderstands intentional semantics, or merely relocates complexity instead of removing it.
- Record every `skip` as **completed coverage**, not as a gap.
- Deduplicate overlapping findings and assign each accepted recommendation to exactly one authoritative subsystem.
- Keep opening bounded review batches until every inventory row is complete.

### 4. Audit the audit

Before finishing, run fresh independent passes for:
- repository coverage and missing subsystem boundaries;
- duplication and ownership overlap;
- materiality and over-abstraction;
- schema completeness (every finding carries all eight fields);
- dependency-aware priority ranking.

When the coverage pass finds a real omission, add an **explicit new subsystem row** and audit it. Never hide an omission by broadening a boundary that is already marked complete.

Then rank the accepted recommendations by concrete impact, open points, implementation scope, blast radius, and prerequisites, and name the best first implementation slices.

---

## Diff mode (`MODE=cr`) — inside every code review

`@agents/leonardo.md` runs this skill with `MODE=cr` over the current diff of the pull request on every review (`@skills/code-review/references/specialized-reviews.md` *Specialized Reviews*). This section is the one owner of the diff-mode contract; the reviewer and the lens list reference it and never restate it. In this mode:

1. **Scope.** The mode reads the changed lines, and the code they directly call or replace, only to judge the changed lines. Every finding anchors to a changed line. A simplification only in called or replaced code goes to the reviewer's out-of-scope observations, never into the published comment.
2. **Dimensions and bar.** Apply the same five dimensions as *Scope* above, the same signals in `references/worker-brief.md`, and the same bar: a finding names a concrete smaller result that is materially useful. *Never force an abstraction* in *Constraints* applies unchanged.
3. **Passes.** The reviewer runs every pass itself, in sequence, in its own context. It builds no inventory, ranks no hot spots, dispatches no worker, and writes no report. Each finding carries the eight fields of `references/worker-brief.md`, and a `skip` verdict returns no finding.
4. **Validation.** Step 3 *Validate and synthesize* applies to every finding before it is returned, including the re-run search behind a deletion-test or single-implementation finding.
5. **Read-only.** The mode modifies no file, runs no test, fixer, static analyser, or build, and stages, commits, and pushes nothing.
6. **Publishes nothing, and severity.** The findings return to the reviewer as markdown, and the mode itself carries no severity. The reviewer assigns it:
    - A finding that rests on a rule with its own severity takes that severity, and that rule's bullet raises it.
    - Every other finding is **Moderate**, never Critical.
    - A finding that maps to neither Critical nor Moderate is not reported (`@rules/code-review/general.md` *Minor findings are not detected*).
    - The reviewer publishes each finding in its one review comment under `## Findings`, with the usual reproducer fields.
    - When no changed line meets the bar, the mode returns one line saying so, and the review reports nothing for this lens.
7. **Gating — one finding per violation, never two.** The mode divides the *dimensions* of a change, never its lines. It owns whether the structure the changed lines introduce is the smallest one that fits the assignment, and it proposes only a representation: a state model, a typed shape, a branching map, a collection or index, or an ownership boundary. These owners raise their own findings, and the mode does not raise a line again when one of them does:
    - `@rules/code-review/core-analysis.md` *Speculative interfaces* and *Simplicity First* own a new abstraction, a new class for simple logic, and a speculative interface.
    - `@rules/code-review/general.md` *Reuse Existing Logic* owns duplicated and already-existing logic, and *Bulk Data & Batch Processing* owns per-row work.
    - The architecture rules own which layer holds the logic.
    - `@rules/code-review/core-analysis.md` *Architecture principles — one lens, one finding format* owns the failure and concurrency trace of a state change, a flag parameter at its call site, and a dependency the code does not need. The mode owns only the representation of the state and of a set of booleans.
    - `@skills/test-audit/SKILL.md` with `MODE=cr` owns the value of a test.

---

## Output

In the audit mode, one markdown report in the shape of `templates/audit-report.md`, returned in this run. It contains:
- the subsystem inventory with a final status on every row;
- every accepted recommendation with its eight fields;
- the explicit skip decisions and their reasons;
- the cross-cutting patterns, and the duplicates and superseded findings that were removed;
- the ranked priorities with their dependencies, and the best first implementation slices;
- the audit log.

Write the report in the language the user made the request in. Code identifiers, file paths, and status words stay verbatim.

---

## Done when
- Every identifiable subsystem in scope has been reviewed.
- Every subsystem row carries a recommendation or an explicit skip.
- Every finding carries complete evidence, scope, risk, and validation fields.
- Duplicates and weak abstractions have been removed.
- Priorities and dependencies are internally consistent.
- The repository is unchanged — `git status --short` matches the pre-audit baseline recorded in the report's `## Scope` block.

---

## References
- `references/worker-brief.md` — the verbatim brief handed to each subsystem worker, and the eight required recommendation fields.
- `templates/audit-report.md` — the canonical report shape.
