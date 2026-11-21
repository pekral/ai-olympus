---
name: simplification-audit
description: "Use when the user explicitly asks for an audit of the codebase or to refactor a part of the codebase, or in `MODE=cr` over the current diff of every code review. Coordinates a bounded, read-only pass over every subsystem in scope and returns ranked simplification recommendations for data structures, state representation, control flow, algorithms, and ownership, without changing a single file."
license: MIT
metadata:
  author: "Petr Král (pekral.cz)"
---

For an explicit user invocation, run `bash ../_shared/resolve-command-helper.sh --entry` from this skill directory and follow the returned command contract with the original arguments. For a bounded work step already in progress, use the method below.

## Constraints

- Read only the supplied subsystem or PR diff. Keep the working tree unchanged.
- Use material reductions in complexity, evidence from the project and the existing format in `templates/audit-report.md`.

## Scope
Coordinate a bounded, read-only audit that finds materially useful simplifications in the audited scope's **data structures, state representation, control flow, algorithms, and ownership**, then rank them.


**What this skill deliberately leaves to another skill.** It never implements: an accepted recommendation is implemented later, in a separate and separately requested run, by `@skills/class-refactoring/SKILL.md` (class-level structure) or `@skills/refactor-entry-point-to-action/SKILL.md` (entry-point orchestration extraction). A suspected defect is not a simplification — route it to `@skills/analyze-problem/SKILL.md`. A pull-request diff review is `@skills/code-review/SKILL.md`, which is tied to a PR, never triggered by a bare audit request, and runs this skill only in *Diff mode*.

---


## Execution

Apply the inspection in `references/worker-brief.md` to the assigned subsystem. Return at most two opportunities or an explicit skip. Preserve the subsystem boundary, evidence, dependencies and reasons.

## Diff mode (`MODE=cr`) — inside every code review

With `MODE=cr`, inspect the current pull-request diff (`@skills/code-review/references/specialized-reviews.md` *Specialized Reviews*). This section is the one owner of the diff-mode contract; the reviewer and the lens list reference it and never restate it. In this mode:

1. **Scope.** The mode reads the changed lines, and the code they directly call or replace, only to judge the changed lines. Every finding anchors to a changed line. A simplification only in called or replaced code is dropped, never published.
2. **Dimensions and bar.** Apply the same five dimensions as *Scope* above, the same signals in `references/worker-brief.md`, and the same bar: a finding names a concrete smaller result that is materially useful. *Never force an abstraction* in *Constraints* applies unchanged.
3. **Passes.** Read the changed representation and affected call sites. Return findings with the eight fields in `references/worker-brief.md`.
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
- `references/worker-brief.md` — the bounded subsystem inspection, and the eight required recommendation fields.
- `templates/audit-report.md` — the canonical report shape.
