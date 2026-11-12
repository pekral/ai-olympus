---
description: Compound engineering — every change must make future work easier; the code-review memory is read only when a Critical finding is open.
---

## Compound Engineering

Compound engineering is **not** "let the AI write the code". It is a system where AI agents plan, implement, and review the work, while the human directs the direction, decides on quality, and curates what was learned so the next task is easier. Every loop is supposed to compound: each feature, fix, and review should lower the cost of the next change, never raise it.

- **Every change must make future work easier, not harder.** A change that adds hidden coupling, ships an undocumented decision, or introduces a new abstraction where an existing part of the system already fit is a defect to correct — not something to ship and move on from.
- **Every mistake, insight, or decision worth more than this one task gets written down** where the next agent *and* the next human will find it. Knowledge that lives only in someone's head, a chat thread, or a closed PR does not compound.
- The human stays in the loop on direction and quality; the agents do the planning, implementing, and reviewing against that direction.

## Compound Memory — the companion file

Durable solutions of resolved Critical code-review findings live in a per-user, git-ignored SQLite database, `.claude/run/code-review-memory.sqlite` (`.codex/run/` under Codex). The sections that govern it live in `@rules/compound-engineering/memory.md`, not in this file: *Compound Memory (per project)*, with *Where to store it*, *Entry fields*, *Read protocol* (with *Per-dispatch memory slice*), *Write protocol*, and the one-time migration of the legacy `docs/memory/PROJECT_MEMORY.md`. That file loads on demand, so this file stays inside the total instruction budget Claude Code enforces across every always-on file.

- **A run that reads, slices, or writes the code-review memory reads and applies `@rules/compound-engineering/memory.md` first.** Reading the memory file itself attaches the rule. Every skill and agent that reads or writes the memory names the file.

## Tracker workflow — the companion file

The sections that govern a run taking its assignment from a tracker item live in `@rules/compound-engineering/tracker.md`, not in this file: *Analyze every comment before you act on a tracker assignment*, *Fix the cause first, then repair the data it already wrote*, *Claim a tracker issue before working on it*, *Tracker status tracks the phase of work*, *Every pull request links back to its tracker issue*, *File deferred points as follow-up tracker issues*, and *Label tracker issues, and keep the labels true*. That file loads on demand, so this file stays inside the total instruction budget Claude Code enforces across every always-on file.

- **A run that reads, claims, updates, links, labels, or files a tracker item reads and applies `@rules/compound-engineering/tracker.md` first.** Every skill and agent that performs one of those steps names the file.

## The assignment is the scope

The goal of every assignment is that every acceptance criterion is met. This section is the one owner of that goal and of its boundary. Other files reference it and never restate it.

- **Verify each criterion before you report the work done.** Check every acceptance criterion against the result, with a test or an observation. A criterion with no check is not met. The report names each criterion as met or unmet, and an unmet criterion is never reported as done.
- **Change nothing outside the assignment.** A change is inside the assignment only when one of these requires it:
  1. an acceptance criterion;
  2. a line the change itself writes, including its comments, which `@rules/php/core-standards.md` governs;
  3. a Critical finding, a Moderate finding, or an unfulfilled reviewer instruction on the diff (the convergence gate in `@skills/process-code-review/SKILL.md` *Review loop* step 4);
  4. the exact-head quality gate, required CI, or a merge conflict.
- **The one exception is security.** An agent that changes files and finds a security vulnerability along the way fixes it, in its own `pre-existing — ` commit with a regression test (`@skills/resolve-issue/references/pre-existing-issue-handling.md`). A security vulnerability is a defect that a rule in `@rules/security/**` names and that has an exploit path. A defect that only sits on a security surface is not one. The fix is the smallest change that closes the vulnerability, in a file the change had to read. Anything more is outside the assignment. `@skills/security-audit/SKILL.md` covers its whole requested scope by design. A refactoring-only change files the vulnerability instead (`@rules/refactoring/general.md` *Behaviour stays identical — bugs included*).
- **Record everything else; implement none of it.** This covers a pre-existing bug, a project-rule violation, a comment outside the lines the change writes, a refactoring, an optimization, and a nice-to-have point. The existing filing path decides what happens to it: `@rules/compound-engineering/tracker.md` *File deferred points as follow-up tracker issues*, and in merge preparation an open decision (`@skills/verify-merge-readiness/SKILL.md` *Scope — the assignment only*). A point that clears no bar is dropped, not carried forward.

## A change needs a real problem, a minimal API, and a proving test

This section is the one owner of the principle. It applies to every change in every language. It is the only place that states it, because this file is always loaded and language-independent. The PHP forms stay in `@rules/php/core-standards.md`, and the test rules stay in `@rules/code-testing/general.md`.

- **A real problem first.** A change states the concrete problem it solves: a failing case, a reported defect, or an acceptance criterion. The statement carries evidence observed in the run, such as a reproduction, a log line, or a failing test. A change justified only by "might be needed" is not made.
- **No unnecessary commitment in the API.** The public surface grows only as far as the problem needs. Do not add a public method, parameter, option, return field, event, config key, route, or response field that no current caller uses. A public member is a promise that consumers start to depend on, and it is costly to withdraw. Keep a new member private or internal until a caller needs it. The PHP forms of this point are the speculative parameter and the speculative interface (`@rules/php/core-standards.md` *Design Principles*), the public parameter name (*Named Arguments*), and a method that only its own class calls, which is `private` (*Structure*).
- **Behaviour proven by a test.** A behaviour the change adds or fixes is proven by a test that fails without the change and passes with it. A change that keeps behaviour identical, such as a refactoring, is proven by the existing tests staying green (`@rules/refactoring/general.md`). `@rules/code-testing/general.md` *Acceptance-Criteria Test Contract* owns the mapping of criteria to tests, `@rules/code-testing/general.md` *Test Value* owns the value of each test, `@skills/test-driven-development/SKILL.md` owns the failing-first step, and `@rules/code-review/core-analysis.md` *Simplicity First* owns the complexity limit.

## No generated documentation in `docs/`

An agent never generates documentation into `docs/` — no new file and no new subdirectory, unless the user explicitly asks for that file. Nothing checks generated documentation: no test, no fixer, no reviewer. The code moves and the document does not, so the next reader trusts a description of code that no longer exists. An agent writes nothing under `docs/` on its own initiative. The code-review memory is a git-ignored database, not a file under `docs/` (`@rules/compound-engineering/memory.md`).

| Documentation | Where it belongs |
|---|---|
| Conventions for agents and contributors | the project instruction sources (`@rules/general/general.md` *Project instructions take precedence*) |
| Path-scoped traps and settled decisions | the project's own rule files (e.g. `.ai/rules/**`) |
| The repository entry point | `README.md` |
| Analysis, plans, and reports for one task | the tracker item |

- **Deleting a committed file is a stated decision, never a side effect.** Run `git status` before you commit and stage only the changes you intended. The commit message says who decided the deletion and why.
- In code review, a diff that adds a file under `docs/`, other than the memory file, is a **Moderate** finding. The Suggested Fix names the row of the table the content moves to.

## What Not To Do

- Do not write a project-specific lesson into this shared rules package as a new global rule — only genuinely universal standards belong here.

## Blocked delegation is a hard stop

When an orchestrator delegates a step (analysis, implementation, review) and the delegate returns a blocker it cannot resolve — most commonly a write-capable implementer (e.g. `donatello`) whose `Write` / `Edit` is refused by the harness sandbox / permission layer even though the agent declares those tools, but equally an unresolvable merge conflict or a non-converging review loop — the run **stops and reports the blocker with its remediation**, never a silent bypass. Do **not** silently complete the blocked agent's work in the main thread (or in another agent's context): that bypasses the delegated, reviewed pipeline (no implementer→reviewer loop, no quality gates), hides the failure from the human, and breaks the compounding loop.
Surface *what was blocked*, *why* (the denied capability), and *how to unblock it* (the environment change the human must make), then halt.

## Code Review Application

- A change outside the assignment that is not a security fix is a finding of the Assignment Conformance Gate, direction *Changes → requirements* (`@skills/code-review/references/assignment-conformance-gate.md` step 2). Raise it there and nowhere else.
- Treat a change that makes future work harder — new abstraction where an existing part fit, undocumented non-obvious decision, hidden coupling — as a finding, with the concrete existing part it should have used.
- **A public member that no caller uses** — a member the diff adds to a public surface (a method, a return field, an event, a route, or a response field) that no current non-test caller uses and no acceptance criterion names — is a **Moderate** finding, per *A change needs a real problem, a minimal API, and a proving test*. The Suggested Fix makes the member private or internal, or removes it.
  - **Not findings:** a member that a framework or vendor contract requires, and a member of a published package API that the PR description names.
  - **Gating — one finding per line:** a speculative parameter and a speculative project interface belong to `@rules/php/core-standards.md` *Design Principles*, and an abstraction, flag, option, config key, or extension point belongs to `@rules/code-review/core-analysis.md` *Simplicity First*. A method whose callers sit only inside its own class, or that has no caller, belongs to `@rules/code-review/core-analysis.md` *Method visibility wider than its callers need*, and a member widened for a test belongs to *Visibility widened for a test*. This entry owns the remaining members only.
- **A behaviour with no proving test, or a change with no real problem** is a finding of the rule that already owns it, and this section raises no second one. A criterion with no test is the *Acceptance-criteria use-case coverage* finding (Critical), an uncovered changed line is the *Coverage gate* finding, both in `@rules/code-review/review-process.md`, a test that passes without the change is the test-value lens finding (`@skills/test-audit/SKILL.md`), and a change that no criterion requires is the Assignment Conformance Gate finding above.
