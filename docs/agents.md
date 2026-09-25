# Agents

Agents are **Claude Code subagents** that act as a thin orchestration layer over the existing skills. They run in their own context window, delegate the real work to skills, and hand a clean result back to the caller.

```text
Rules  = long-lived project standards
Skills = reusable workflows
Agents = specialised orchestration roles over multiple skills
```

## Agent roster

Every agent has its own portrait under [`assets/agents/`](../assets/agents), saved as `assets/agents/<name>.png`. The portraits depict each agent’s Teenage Mutant Ninja Turtles namesake in the same cartoon mascot style as Cockpit’s Krang. The universal [`placeholder.svg`](../assets/agents/placeholder.svg) is available for future agents without custom artwork.

### <a href="../assets/agents/leonardo.png"><img src="../assets/agents/thumbnails/leonardo.png" alt="leonardo avatar" width="48" align="left"></a> `leonardo` — code-review sentinel & security analyst

The strategic sentinel and **the roster's only code-review agent**, named after **Leonardo**, the disciplined leader who holds the team to its standards. It owns the review domain in **two modes**. (1) **Security analysis (pre-implementation)** — dispatched on demand when the task carries a cyber-security question: it scopes the security risk through all security skills, frames the smallest safe remediation via `analyze-problem`, publishes a plan artifact, and hands back a `Security analysis done` summary that `donatello` implements. (2) **Code review (post-implementation)** — given a PR from the current context or a tracker link, it runs the matching `code-review-*` wrapper (which drives the full CR skill set, security pass included), adds the three security skills the wrapper does not run, applies every security rule, labels each finding (Critical / Moderate / Minor), publishes **one** consolidated review, drives the fix loop to convergence, and hands back a `CR done` summary. There is no second CR pass to wait for, no barrier, and nothing to consolidate from a peer.

- **Trigger:** a pull request needs reviewing, or a task carries a cyber-security question and needs a pre-implementation security-risk analysis.
- **Orchestrates:** `code-review-github`, `code-review-jira`, `code-review-bugsnag`, `process-code-review`, `security-review`, `laravel-security`, `security-bounty-hunter`, `security-threat-analysis` (plus `analyze-problem` in analysis mode).
- **Rules applied:** `@rules/security/backend.md`, `@rules/security/frontend.md`, `@rules/security/mobile.md`, `@rules/security/general.md`.
- **Safety:** read-only — never edits, commits, pushes, or merges (`donatello` implements what it analyses and fixes what it finds).
- **Registration dependency:** dispatchable only after the installer copies `agents/leonardo.md` to `.claude/agents/`, or installs its adapter in `.codex/agents/`. Until then, the review runs inline in `code-review-github` (the continuity fallback), and the pre-implementation analysis mode is skipped. `donatello` is **not** the fallback for either: its pre-PR self-check is a deterministic checklist that runs no review skill (`skills/resolve-issue/SKILL.md` *Pre-PR self-check*).

### <a href="../assets/agents/donatello.png"><img src="../assets/agents/thumbnails/donatello.png" alt="donatello avatar" width="48" align="left"></a> `donatello` — code-writing implementer

The inventive engineer, named after **Donatello**, the team’s technical expert and builder. Give it a source — a tracker link (GitHub, JIRA, Bugsnag) or the current task — and it implements the fix or feature, runs the tests covering the change, opens a pull request, and hands back an `Impl done` summary with links. The whole code review — quality, architecture, optimisation and security — belongs to `leonardo` — `donatello` does not own it. Scoped validation is `donatello`'s own second dispatched mode since `apollon` was retired; the post-convergence report went to `april`, not here — `donatello` writes code and tests and never publishes to a tracker. The read-only CR run by `leonardo` may isolate in a worktree; write-capable runs are serialised via the working-tree write-lock (rule #627). It is the write-side counterpart to `leonardo`: `leonardo` is the tireless eye (review), `donatello` the tireless hands (implementation).

- **Trigger:** an issue or task needs implementing, or a landing step needs a fast scoped validation pass.
- **Orchestrates:** `resolve-issue`; plus `create-test` / `create-missing-tests-in-pr` and `e2e-testing` for the test coverage it authors.
- **Two modes:**
  - **Implementation (default)** — the full `resolve-issue` pipeline, ending at the Draft PR.
  - **Fast scoped validation gate (push-level)** — `splinter` re-dispatches `donatello` after a landing step: after the PR opens only when `splinter` classified the change as high-risk (shared / core / config surface, more than 10 files, or security impact), and once after the `leonardo` CR converges, unless `splinter` skipped that pass because the converged head already carries a green validation from this run (`agents/splinter.md` step 6). In this mode `donatello` derives the changed surface from the diff, runs only the affected tests, and verifies the relevant acceptance criteria against the diff. The test selection is widened when the change is broad. This gate runs at push-level granularity — inside the `leonardo` loop itself would violate the one-level nesting rule, so `splinter` is the dispatcher, not `leonardo`. Handoff: `Tests done (scoped)` or `Blocked`.
- **Safety:** stops at the PR — never reviews its own work, never merges, and never publishes to a tracker. If a caller explicitly instructs a merge, the only permitted path is `@skills/merge-github-pr/SKILL.md` — never `gh pr merge` or bare CLI.

### <a href="../assets/agents/splinter.png"><img src="../assets/agents/thumbnails/splinter.png" alt="splinter avatar" width="48" align="left"></a> `splinter` — engineering-workflow orchestrator

The mentor who coordinates the team, named after **Splinter**, the teacher who guides each specialist. It is the **entry point** for a free-form engineering request — *"resolve a random issue"*, *"resolve this URL"*, *"implement this"* — and the conductor that drives the job to a clean, reviewed result. It resolves a concrete source, decides whether a security-focused task needs a remediation plan first, then **delegates each step by dispatching the matching specialist agent** through the Task tool — `donatello` (implementation, then again for fast scoped validation — after PR-open for high-risk changes only, and after the CR converges unless the converged head already carries a green validation from this run), `leonardo` (the single CR pass after `donatello`, plus a pre-implementation security-risk analysis on demand when the task carries a cyber-security question), and `april` (the post-convergence report) — and reports the result to the user. It also owns the **backlog tier** itself and runs it **inline, in its own context** — triage over the open backlog, and splitting a subject too broad for one PR into deliverable issues, after which the run ends at those issues, each re-entering later as its own run. That tier used to be a peer agent (`zeus`, retired — *Retired agents* below); with no peer left to dispatch it to, and no third nesting level to invent one on, `splinter` performs it itself as the second of the two named exceptions to its own delegate-everything rule. The roster still carries no general (non-security) analysis agent — the backlog tier owns *what* is worked on, never the problem — so a pure analysis request is answered by running `@skills/analyze-problem` in the top-level session. When resolving multiple linked issues, it plans a dependency-aware resolve order (reading `## Dependencies` from each issue) that takes precedence over strict oldest-first. `donatello` the hands (and the scoped-validation gate), `leonardo` the eyes, `april` the voice; `splinter` the workshop lead that directs them.

- **Trigger:** a free-form engineering request — from a vague idea to a tracker link — that should be carried end to end; a request to prepare an existing GitHub issue's PR for merge without merging via `/prepare-issue-for-merge`; a page redesign from the page URL via `/redesign-page`; or a backlog decision — *"triage the open issues"*, *"what should we work on next"*, *"this assignment is too big for one PR, split it"* — which it answers inline and ends there, without opening a pull request.
- **Orchestrates (dispatches via the Task tool):** `donatello` (implementation step — owns `resolve-issue`, whose pre-PR self-check is a deterministic checklist rather than a review: it runs no review skill over the diff, because the authoritative review is `leonardo`'s and happens once), `donatello` again as the **fast scoped validation gate** — dispatched after PR-open only for high-risk changes, and after the CR converges unless the converged head already carries a green validation from this run; runs only the tests covering the diff and verifies the relevant acceptance criteria; a widened test selection for broad changes; each re-dispatch is recorded in the ledger under its moded role (`donatello:scoped`) so it is never mistaken for a repeat of the implementation round), `april` (the **post-convergence report** — dispatched last, to publish the human-readable non-technical summary to the source tracker via `pr-summary`; ledger role `april:reporting`), `leonardo` (the **single CR pass** — code quality, architecture, optimisation and security in one run — part of the `donatello` ↔ `leonardo` convergence loop, owns `process-code-review` / `code-review-github`, `maxIterations = 3`; and on demand a pre-implementation **security analysis** (security skills + `analyze-problem` → remediation plan that `donatello` implements) when the task carries a cyber-security question; active only after the installer registers it — fallback: the review runs inline in `code-review-github`); resolves the source itself using an oldest-open-issue selection (label `Resolve_by_AI`, excluding already-claimed issues) and `resolve-issue` source detection.
- **Runs inline (not dispatched):** `github-issue-triage` (triage mode), `create-issues-from-text` / `create-issue` (decomposition mode) — the backlog tier absorbed from the retired `zeus`. Handoff: `Triage done` / `Breakdown done`, and the run ends there.
- **Convergence gate:** the run is done only at **0 Critical with no undeferred Moderate** (security Critical findings arrive in the same `leonardo` handoff and count identically); on `maxIterations` or a blocker it stops and escalates rather than reporting success. Merging stays a separate, explicit step — when instructed, always via `@skills/merge-github-pr/SKILL.md`, never ad-hoc CLI.
- **Prepare-only mode:** `verify-merge-readiness` compares the current effective-diff fingerprint with the newest trusted converged review before dispatching a CR round. Content-identical history rewrites reuse the verdict; changed or missing fingerprints and new actionable feedback re-open review. After exact-head validation, `april` publishes one source-issue TL;DR and deletes only superseded actor-owned preparation comments, preserving the current `cr-comment` / `cr-status` merge evidence. Handoff: `Preparation report done` or `Blocked`; it never merges.
- **Safety:** read-only orchestrator — never analyses, implements, or reviews itself; it delegates each step by dispatching the matching specialist agent, the iteration loop is skill-driven (state lives in the skill the specialist owns), and it must be the top-level agent (not a nested subagent) per the one-level nesting rule below — that single level is what it spends to dispatch `donatello` / `leonardo`. The inline backlog tier does not soften that: it holds no `Write` / `Edit` tool, its only writes are the labels and issues the three backlog skills create on the tracker, and it never analyses, diagnoses, or designs.

### <a href="../assets/agents/michelangelo.png"><img src="../assets/agents/thumbnails/michelangelo.png" alt="michelangelo avatar" width="48" align="left"></a> `michelangelo` — page redesigner

The one that makes a screen usable by the person who has to work in it, named after **Michelangelo**, the creative member of the Ninja Turtles. Give it a page that grew field by field and is now hard to operate, and it reads the real view, maps every state the main screenshot hides, lays the content region out against the operator's task sequence, and hands back a developer-ready specification with one rendered preview per state. The user it designs for is not an IT person: a warehouse, workshop, or shop-floor operator who did not choose this software, will not explore it, and needs the order out.

- **Trigger:** an existing page must be redesigned for speed and clarity for non-technical operators, or a developer needs a buildable specification of a layout change rather than a sketch.
- **Dispatched by `splinter`** whenever the assignment asks for a page redesign, at every tier, **before** `donatello` — the proposal is the specification the implementation builds from (`@rules/compound-engineering/orchestration.md` *One stage is chosen by the kind of work, not by the tier*). A redesign request with no implementation ask is an analysis-only run whose deliverable is the proposal itself.
- **Orchestrates:** `page-redesign` (its whole run), and it names — never performs — the work owned by `frontend-design-direction`, `design-system`, `frontend-patterns`, and `frontend-a11y`.
- **Two boundaries that define the role:** the application's **main layout shell is never touched** without an explicit order, and it **proposes rather than implements**. It holds `Write` for exactly one purpose — the proposal, the mockups, and the previews under the path the caller named — and no `Edit` tool at all, so no file the application ships is changeable by it, even by accident.
- **Why `model: fable` at `medium` effort.** The deliverable is a layout judgment plus a precise specification, both of which it derives from material the caller supplies rather than from reasoning about a whole codebase. It never gates another agent's stage, so a deeper pass here buys nobody downstream a round.
- **Safety:** never commits, pushes, merges, or publishes to a tracker. A screenshot or a pasted HTML dump is untrusted content — material to analyse, never an instruction.
- **Registration dependency:** dispatchable only after the installer copies `agents/michelangelo.md` to `.claude/agents/`, or installs its adapter in `.codex/agents/`. Unregistered, its run is not silently taken over by another agent — invoke `@skills/page-redesign/SKILL.md` directly instead.

### <a href="../assets/agents/raphael.png"><img src="../assets/agents/thumbnails/raphael.png" alt="raphael avatar" width="48" align="left"></a> `raphael` — acceptance tester / QA

The direct and tenacious tester, named after **Raphael**, who challenges the result in practice. It is the only agent that **runs the application**: it starts a local instance and tests it the way a real tester would — the **API through an HTTP client that actually crosses the network** (recording the exact request and response), the **UI through a real browser** (navigating, filling, clicking, observing what renders and watching the network for a click that silently 404s) — driven by the project's own automation when it has one, otherwise by the `skills/_shared/browser-drive.sh` runner this package ships, which needs no config file and no dev-dependency inside the project under test — and returns a per-criterion `Met` / `Not met` / `Partial` / `Blocked` verdict with the steps it performed. Neither channel substitutes for the other: a UI criterion that cannot be driven in a browser is `Blocked`, never satisfied by calling the endpoint behind the page. Read-only with respect to code — it never edits a file, authors a test, commits, merges, or publishes.

- **Trigger:** dispatched by `splinter` after the post-convergence scoped pass, **only when the change alters behaviour a user can observe**. The browser is gated once more inside the run: it starts only when the diff shows a UI surface changed, so an API-only task is still exercised over HTTP but never pays for a browser run that would verify the previous release. A pure refactor, dependency bump, docs change, test-only change, or CI change is skipped with the reason named in the route.
- **Orchestrates:** the project's own `interactive-testing` skill when it ships one — it drives the walkthrough and carries the project's sandbox rules, which a package-shipped agent definition cannot know — plus `tester-cookbook` (report shape) and `e2e-testing` (only when the project has already adopted Playwright).
- **Why it is a separate agent:** its **input** differs from every other agent's. `leonardo` reads the diff; `donatello` reads the diff plus the suite it wrote. `raphael` reads the **running system**, which is where the defects that survive a green build live — a missing migration, a config key absent outside the test environment, a queue worker nobody starts, a permission that reads correctly and denies the wrong user. The boundary it must guard is not `leonardo`'s review but `donatello`'s scoped validation, which checks the same acceptance criteria *against the diff*; `raphael` checks them *against the running system*, and never accepts a criterion on the strength of the diff or a passing test.
- **Safety:** local instance only — never a shared, staging, or production host, and never a third-party endpoint. A criterion it did not exercise is reported `Blocked`, never `Met`.

### <a href="../assets/agents/april.png"><img src="../assets/agents/thumbnails/april.png" alt="april avatar" width="48" align="left"></a> `april` — release announcer / publicista

The reporter who makes the team’s work clear to its audience, named after **April O’Neil**, the Ninja Turtles’ trusted ally and journalist. It is the roster's **only publishing agent**: every other agent hands its result back through the shared brief or its handoff, and anything that reaches a tracker audience routes through `april`. Give it a merged change, a release, or a shipped feature and it loads the source read-only, composes the announcement content (Twitter/X tweet ≤280 chars + thread, release notes, marketing summary with **pekral.cz** promotion), and hands back an `Announce done` summary with all drafts inline. It runs **post-delivery**, outside the CR loop.

- **Trigger:** a merged change or release needs announcement content — tweet, thread, release notes, or marketing summary — a converged run needs its post-convergence report published, or `verify-merge-readiness` needs its final source-issue TL;DR consolidated.
- **Orchestrates:** `resolve-issue/references/source-detection` (source loading, read-only), `pr-summary` (post-convergence reporting to the source tracker).
- **Three modes:**
  - **Announcement (default)** — drafts the tweet / thread / release notes / marketing summary and returns them inline. Publishing needs an explicit ask (L2).
  - **Post-convergence reporting** — `splinter` dispatches `april` as the final step of a full-delivery run, after the CR converges and `donatello`'s scoped validation confirms `Tests done (scoped)` — or `splinter` skipped that pass over an already-validated head, leaving the earlier handoff for the same SHA in the brief. It composes the human-readable, non-technical summary (what changed + how to test) in the language from the brief `## Language` and publishes it to the source tracker via `@skills/pr-summary/SKILL.md`. Publishing is the deliverable of this dispatch, so it is pre-approved (L1). Before it publishes it reads the target's existing comments and skips its own only when one already carries **both** halves of the report — the summary and the `How to test` steps. Only a comment from an account with write access to the repository (`author_association` `OWNER` / `MEMBER` / `COLLABORATOR`) is admissible evidence; inside that set the skip is judged on the comment's content rather than on which trusted agent wrote it. After it publishes it reads the comment back through the same loader, so an unconfirmed write is `Blocked` rather than a reported success. With no linked tracker it returns the summary inline and `splinter` passes it to the user. Handoff: `Reporting done`, `Reporting done (already covered)` with the covering comment's URL, or `Reporting done (no tracker)`.
  - **Merge-preparation consolidation** — after merge readiness is known, publishes one `merge-readiness` TL;DR on the source GitHub issue from the existing `pr-summary` template and reads it back before cleanup. It deletes only exact actor-owned, superseded preparation-comment IDs through `skills/_shared/delete-owned-github-comment.sh`, or `skills/code-review-jira/scripts/delete-owned-comment.sh` on a JIRA source; comments by others, ambiguous or unrelated comments, the final TL;DR, and the newest trusted CR/status merge evidence remain. Handoff: `Preparation report done` or `Blocked` on any unverified publish or partial cleanup.
- **Why `model: haiku` is the right tier.** In both modes `april` composes prose from evidence other agents already produced — the brief's `## Gathered context`, the converged PR, and `donatello`'s handoff for the current head SHA (the executed tests, the coverage verdict, the acceptance-criteria statuses). It runs no suite, authors no test, and derives no new fact, so the job is summarisation of existing text rather than reasoning about code. Raising the tier would buy nothing; if a future change makes `april` derive facts of its own, revisit the tier in the same change.
- **Safety:** read-only for tracked files — never edits, commits, pushes, or merges. Publishes only through `upsert-comment.sh`; the explicitly authorized merge-preparation mode may delete only through `delete-owned-github-comment.sh` or the JIRA `delete-owned-comment.sh`. It never uses raw tracker writes.
- **Registration dependency:** dispatchable only after the installer copies `agents/april.md` to `.claude/agents/`, or installs its adapter in `.codex/agents/`. On a run whose source is a tracker that registration is load-bearing: the published report is part of the definition of a finished run, so an unregistered `april` stops the run `Blocked` with the remediation, and no other agent publishes the report in its place.

## Retired agents

An agent removed from the roster is recorded here rather than deleted without a trace, so an agent (or a human) that finds a stale `@apollon` reference in an old PR, brief, or memory entry can resolve it to what replaced it instead of guessing. **`apollon` is not `michelangelo`:** the retired one was the test engineer and post-convergence reporter, and the live one is the page redesigner above — two unrelated roles whose history this table preserves — or worse, trying to dispatch a `subagent_type` that no longer exists.

| Agent | Retired | Where its work went |
|---|---|---|
| `apollon` — test engineer & post-convergence reporter | 2026-08-07 | **Split across `donatello` and `april`, along the roster's capability line.** Test authoring is part of the implementation run (`resolve-issue` already drove the TDD and coverage gates), and the *fast scoped validation gate* became `donatello`'s second dispatched mode (`agents/donatello.md`) — both are code work. *Post-convergence reporting* went to `april` (`agents/april.md`), the roster's only publishing agent, so the write-capable implementer never gains the right to post on a tracker. The savings-mode coverage deferral now reads `deferred to donatello`. |
| `argos` — quality / architecture / optimisation reviewer | superseded by the single-CR-pass consolidation | **`leonardo`**, which runs the whole review — quality, architecture, optimisation **and** security — in one pass, with no barrier and nothing to consolidate from a peer. |
| `zeus` — backlog owner / project manager | 2026-08-25 | **Both modes to `splinter`, run inline rather than dispatched.** Triage and decomposition were the whole of the job, and the successor is the orchestrator that used to dispatch them — so there is nobody left to delegate them to. `agents/splinter.md` *Backlog tier — triage and decomposition, run inline* carries them verbatim as the **second** named exception to its own "never run the work in your own context" rule (the first is resolving the source), together with zeus's negative boundary: the roster still has no general (non-security) analysis agent, and absorbing the backlog tier does not make `splinter` one. Its `Bash boundary` gained exactly zeus's two tracker writes — `gh label` / `gh issue create`, only through `github-issue-triage` / `create-issues-from-text` / `create-issue` — and no `Write` / `Edit` tool. The handoff statuses `Triage done` / `Breakdown done` are unchanged; they are now `splinter`'s. |

**Retiring an agent here does not un-install it.** The installer copies `agents/` into `.claude/agents/` but only *removes* a file the source no longer ships when it is run with `--prune` (it otherwise prints `N file(s) across the target directories no longer exist in source. Re-run with --prune to remove them.`). A project that upgrades without that flag therefore keeps a stale `.claude/agents/<retired>.md`, and the retired agent stays dispatchable there with its old prompt — pointing at a pipeline position nothing dispatches any more. **Run `vendor/bin/ai-olympus install --prune` after upgrading past a retirement**, or delete the file by hand.

Rules for retiring an agent, so a removal never leaves the roster half-consistent:

1. **Name the successor before deleting anything, per responsibility, not per agent.** Every responsibility the agent owned moves to a named agent or is explicitly dropped — a responsibility with no owner is the failure this table exists to prevent. Route each one along the roster's existing capability line rather than handing the whole agent to one survivor: `apollon`'s test work went to `donatello` and its publishing work to `april`, precisely so the write-capable implementer did not also acquire the right to post on a tracker.
2. **Sweep the whole tree, not just `agents/`.** A retired name lives in the other agents' prompts, in `rules/`, in `skills/`, in the content-pin tests, and in `docs/memory/PROJECT_MEMORY.md` (`Role:` fields and `Rule:` guidance). `grep -ri <name> .` is the check; the content-pin tests are what keep it from drifting back.
3. **Re-key the dispatch ledger when a survivor gains a mode.** `agents/splinter.md` *Dispatch ledger* keys a round on `{role, pr-head-sha, round}` — an agent dispatched more than once per run writes a moded role (`donatello:impl` / `donatello:scoped`), or the second dispatch reads as a repeat of the first and is suppressed.
4. **Add the row here and a CHANGELOG entry.** This table is what an agent reads; the CHANGELOG entry is what a human reads.

## Renamed agents

A rename keeps the role, tools, model tiers, dispatched modes, permissions, and handoff contract. AI Olympus remains the package name.

| Previous identifier | Current identifier | Display name | Date |
|---|---|---|---|
| `daedalus` | `splinter` | Splinter | 2026-09-23 |
| `hephaestus` | `donatello` | Donatello | 2026-09-23 |
| `athena` | `leonardo` | Leonardo | 2026-09-23 |
| `argus` | `raphael` | Raphael | 2026-09-23 |
| `apollo` | `michelangelo` | Michelangelo | 2026-09-23 |
| `hermes` | `april` | April O’Neil | 2026-09-23 |

The earlier `talos` → `hephaestus` rename took place on 2026-08-11. Its implementation role is now `donatello`. Historical changelog entries and dated memory examples retain the names used at the time.

Run `vendor/bin/ai-olympus install --force` and restart the agent session. The installer removes only known, unmodified copies of these six previous definitions and their Codex adapters, or symlinks to the package’s previous files. Edited definitions and unrelated custom agents are preserved. Older or edited copies that cannot be verified remain reported as orphans; review them manually before removing them. `--prune` retains its broader behaviour and removes every orphan, including custom files.

Update saved invocations, external automation, and in-progress briefs to the current identifiers. Live memory role tags and routing references use the new names; old names are not dispatch aliases.

## Naming convention — Ninja Turtles

The English character names match each agent’s function. Use the lowercase identifier as `name:` and the file id (`agents/<name>.md`); the publishing agent’s display name is **April O’Neil**, with the identifier `april`.

| Agent | Character | Why it fits |
|---|---|---|
| `splinter` | Splinter | Mentor who coordinates specialists and guides the workflow |
| `donatello` | Donatello | Technical expert who implements changes and writes tests |
| `leonardo` | Leonardo | Disciplined reviewer responsible for quality, architecture, and security |
| `raphael` | Raphael | Tenacious tester who challenges behaviour in the running application |
| `michelangelo` | Michelangelo | Creative designer who makes pages clear and usable |
| `april` | April O’Neil | Reporter who explains changes and publishes the team’s results |

Retired identifiers `zeus`, `argos`, and `apollon` are never reused. The former reviewer `argos` maps to `leonardo`; the former acceptance tester `argus` maps to `raphael`.

## Avatar and activity assets

Open the [avatar preview](../assets/agents/preview.html) to compare all six portraits and activity states. The [manifest](../assets/agents/manifest.json) records stable identifiers, display names, roles, previous identifiers, portrait and thumbnail paths, accent colours, and animation states. Its paths are relative to `assets/agents/`. These assets ship with the package for future Cockpit integration; this change does not connect or run agents inside Cockpit.

Portraits and 160-pixel thumbnails are transparent PNGs. Use the [shared stylesheet](../assets/agents/activity.css) for the same gentle portrait tilt and three-dot animation as Krang. `thinking`, `working`, and `responding` animate; `queued` is static; `idle` hides the bubble. Reduced-motion preferences and `data-motion="paused"` stop animation.

```html
<link rel="stylesheet" href="assets/agents/activity.css">
<div class="ai-agent-activity" data-agent-state="thinking" style="--ai-agent-size: 4.5rem; --ai-agent-accent: #9570cf">
  <span class="ai-agent-portrait" aria-hidden="true">
    <img class="ai-agent-avatar" src="assets/agents/donatello.png" alt="">
    <span class="ai-agent-bubble"><span></span><span></span><span></span></span>
  </span>
  <span role="status" aria-live="polite" aria-atomic="true">Donatello is thinking…</span>
</div>
```

## Anatomy of an agent

An agent is a Markdown file with frontmatter + a system prompt:

```markdown
---
name: leonardo
description: When to auto-delegate to this agent (the trigger sentence).
tools: Read, Glob, Grep, Bash, WebSearch, WebFetch
model: opus
effort: high
---

System prompt: what the agent does, which skills it orchestrates, and the handoff it returns.
```

- **`name`** — lowercase, the id used as `subagent_type` / `@name`.
- **`description`** — drives auto-delegation; phrase it as the situation that should trigger the agent.
- **`tools`** — restrict to what the agent needs. A read-only reviewer needs `Read, Glob, Grep, Bash`, plus `WebSearch, WebFetch` when its review has to reach a third-party's public documentation (both fetch, neither writes, so the read-only stance holds).
- **`disallowedTools`** — the second, harness-enforced layer (issue #163): names tools the agent must never receive even if a later edit to `tools:` (or an inherited default) would otherwise grant them. Every shipped agent carries one — read-only agents (`leonardo`, `april`, `splinter`) list `Write, Edit`; agents with no documentation-fetch need (`donatello`) list `WebSearch, WebFetch`. It does **not** restrict what an agent can do through `Bash` — see *Capability model* below.
- **`memory`** — do **not** add this field to any agent in this roster. It automatically grants `Read`, `Write`, **and** `Edit` regardless of what `tools:` says, so adding it to a read-only agent silently reintroduces write access without ever touching the `tools:` line a content pin would catch.
- **`model`** — the **default tier** the agent runs at, not the tier it always runs at. `donatello` declares `sonnet`: adaptive routing pays for the escalated tier only when the run's tier or a failed cheaper attempt justifies it, and `splinter` then dispatches at that tier (`@rules/compound-engineering/orchestration.md` *Adaptive routing* → *Default model tier first, escalate with a recorded reason*). `leonardo` and `splinter` declare `opus` by an explicit operator decision — the reviewer and the orchestrator are the two roles whose mistakes cost every stage downstream, so this roster pays for the stronger model on both rather than escalating into it. `april` is `haiku`. Never read a `model:` line as "this role costs this much"; read it as the floor. **The field is the Claude Code binding of a platform-neutral contract** — on Codex / OpenAI each adapter declares its own default tier as real config: `codex/agents/*.toml` carries a `model` key (`sol`, `luna`, or `terra`) and a `model_reasoning_effort` key, and states the same two values in its tier block so the file cannot disagree with itself. The escalated tier stays described rather than pinned there, because it is whatever the strongest model the session can select turns out to be.
- **`effort`** — reasoning effort while the agent is active (`low` / `medium` / `high` / `xhigh` / `max`), set per agent by what its role actually decides. `leonardo` runs at `high`: it is the roster's single reviewer, so a shallow pass there costs a round for everybody downstream. `donatello` and `raphael` also run at `high`: the implementer and the acceptance tester produce the evidence every later stage relies on. Effort is orthogonal to `model`: the routing decision escalates the model, never the effort. `splinter`, `april` and `michelangelo` run at `medium`, and `max` is never used on any agent. The runtime clamps the level to the highest one the agent's `model` supports, and drops it silently: `claude-haiku-4-5` supports no effort level at all, so `april` carries the field for the day its model changes, not for today's dispatch.
- **System prompt** — orchestration only. Delegate to skills via `@skills/<name>/SKILL.md`; **never duplicate a skill's rules** — defer to the skill as the source of truth.

## Capability model

Every agent's declared capability sits on one of two footings — **harness-enforced** (the runtime actually blocks the tool call) or **advisory** (the agent's own instructions state a boundary the harness does not check). Confusing the two is the exact gap issue #163 closed: a "read-only" or "no internet" claim is only as strong as what is listed below as enforced.

| Agent | `tools:` | `disallowedTools:` | Bash is for | Harness-enforced | Advisory only |
|---|---|---|---|---|---|
| `leonardo` | `Read, Glob, Grep, Bash, WebSearch, WebFetch` | `Write, Edit` | loaders, `gh` reads, publishing via `upsert-comment.sh`, `git worktree` on its own review checkout | no `Write`/`Edit` tool at all; `disallowedTools` blocks them a second way | never running a Bash write / network call outside its declared purpose |
| `donatello` | `Read, Write, Edit, Glob, Grep, Bash` | `WebSearch, WebFetch` | `gh`/`acli` via `resolve-issue`, write `git` on the feature branch, running the diff-scoped tests, `composer build` only when running the quality gate itself | no `WebSearch`/`WebFetch` tool at all | never `git push --force*`, never a raw network call via Bash, never a tracker publish (that is `april`'s) |
| `splinter` | `Task, Read, Glob, Grep, Bash` | `Write, Edit` | resolving the source, maintaining the brief/ledgers under `.claude/run/*`, and the backlog tier's tracker writes (`gh label` / `gh issue create`) via `github-issue-triage` / `create-issues-from-text` / `create-issue` | no `Write`/`Edit` tool at all | never writing any tracked file through Bash redirection, never a bare `gh` write it composed itself |
| `april` | `Read, Glob, Grep, Bash` | `Write, Edit` | loaders, `gh` reads, publishing via `upsert-comment.sh` / `pr-summary` when explicitly asked (a reporting-mode dispatch being that ask) | no `Write`/`Edit` tool at all | never a `git` write op, never publishing without an explicit ask |

**Why Bash stays advisory.** All five agents carry `Bash`, and Bash subsumes both write access and network access no matter what `tools:` / `disallowedTools:` say — a "read-only" agent's own words do not stop `cat > file`, and a "no internet" agent's own words do not stop `curl`. Verified against the current harness: the frontmatter `tools:` field has no syntax for a scoped Bash command pattern (`Bash(gh:*)` is not expressible, and an unresolvable `tools:` entry prevents the agent from starting at all); `permissions.allow` / `permissions.deny` patterns are pattern-capable — this package's own installer already writes one via `--allow-bundled-scripts` — but apply **session-wide, never per agent**; and the only genuinely per-agent mechanism, a `hooks: PreToolUse` validator script, is a runtime component this instructions-only package does not ship. `@rules/compound-engineering/orchestration.md` *Bash capability boundary* is the normative contract every agent references; each `agents/<name>.md` carries its own `## Bash boundary` block naming the concrete purpose its Bash use serves. Of the two mechanisms that would narrow this gap, the first now exists as an **opt-in installer flag**: `--deny-network-bash` writes session-wide `permissions.deny` entries for ten literal network commands (`curl`, `wget`, `nc`, `ssh`, `scp`, `openssl s_client`, …), which the harness genuinely refuses. It is off by default and it does **not** make the boundary per-agent — the rule restricts every agent and the human's own interactive Bash in that project identically, and it matches command strings rather than process trees, so child processes of allowed commands, unstripped wrappers (`bash -c 'curl …'`), absolute paths, `/dev/tcp`, and unlisted tools stay open. Everything outside those ten command strings remains exactly as advisory as before. The second mechanism, a per-agent `PreToolUse` hook, does **not** exist here. This package shipped one behind `--enforce-agent-bash-boundary` and removed it again (issue #265): it was runtime code in an instructions-only package, it failed open in eleven separate cases, and it asked the user to confirm ordinary commands it could not read. The per-agent half of the boundary is therefore advisory in full. The OS-level tier (Claude Code sandboxing) remains unconfigured by this package and remains the only tier that would cover child processes. Full scope, the bypass list, and undo instructions: `SECURITY.md` *`--deny-network-bash`*, plus *Agent capability model & residual risk*.

## Architecture constraint

**This package ships instructions, never a runtime.** `rules/`, `skills/`, `agents/`, and `CLAUDE.md` are text an agent reads; the installer copies them into a consuming project and stops there. It is deliberately not a permission engine, a logging daemon, or a consent broker — which is why `@rules/compound-engineering/orchestration.md` *Audit trail for memory reads, outbound requests, and external writes* is a self-reported obligation rather than an interceptor, and why *Why Bash stays advisory* above describes a declared boundary rather than one the default install enforces.

## Handoff contract

An agent's final message is returned to the caller as the tool result, so it must be a self-contained handoff the next agent can act on without re-deriving context:

- **Status** — e.g. `CR done`.
- **Links** — the PR and the originating source (GitHub / JIRA / Bugsnag).
- **Result summary** — the numbers the caller needs (e.g. Critical / Moderate / Minor counts, a verdict).

**Language of the handoff / report.** Every agent writes the human-facing prose of its handoff and any end-user report in the **same natural language the assignment was given in** (if the request came in Czech, the handoff is in Czech). Identifiers stay verbatim regardless of that language — branch names, ticket / issue keys, links, severity labels, CLI commands, and skill / agent names are never translated, and two natural languages are never mixed inside a single handoff.

**How the language survives delegation.** When `splinter` orchestrates, the assignment's natural language is not re-guessed at each hop — `splinter` records it once in the shared brief's `## Language` field, writes every `Task` dispatch prompt in that language, and each specialist takes the brief's `## Language` field as the authoritative source for its reply. So a Czech request produces Czech output through the whole `donatello → leonardo` chain, not just in `splinter`'s own final report.

## Shared task brief (inter-agent memory)

The handoff above is the *return* channel. For the *forward* channel — passing context **into** each agent efficiently — `splinter` writes a **shared task brief** that every dispatched specialist reads, so the run's data is gathered once instead of re-derived by each agent.

- **Owner & gather phase.** Right after it resolves the source and **before the first dispatch**, `splinter` runs a gather phase: it collects everything the task needs solved — the tracker payload and acceptance criteria (via the deterministic loaders), the source item's **whole comment history** read under the trust gate in `@rules/compound-engineering/tracker.md` *Analyze every comment before you act on a tracker assignment*, the relevant files / symbols / reproduction, known constraints, and its own **work-breakdown plan** (which specialist does what, with each one's success gate).
- **Location & lifecycle.** The brief lives at `.claude/run/<source-slug>.md`. `.claude/` is git-ignored, so it is **ephemeral and never committed**; `splinter` removes it (`rm -f`) after the final report or a `Blocked` stop.
- **Read-then-append.** `splinter` passes the brief's absolute path in every `Task` dispatch prompt. Each specialist **reads it first** as authoritative shared context, then **appends its own handoff section** (`### <agent> — <status>`) when it finishes, so the next specialist in the chain inherits the full history — source, plan, and every prior handoff — without `splinter` re-passing it.
- **No new write scope.** Every agent already carries `Bash`, so the brief is created and appended through `Bash` redirection (`cat >> "$BRIEF" <<'EOF' … EOF`) to the git-ignored scratch path. No agent gains `Write` / `Edit` over the codebase from this — the read-only reviewer (`leonardo`) and the read-only orchestrator (`splinter`) keep their read-only-codebase stance; the files they touch are the brief and the run's audit-trail ledger (`.claude/run/<source-slug>.audit`, per `@rules/compound-engineering/orchestration.md` *Audit trail for memory reads, outbound requests, and external writes* — every agent appends its own lines through the same `cat >>` redirection), plus, for `leonardo` alone, the optional read-only review worktree (`agents/leonardo.md` *Review worktree*). None of the three is source, and none of them changes a tracked file.
- **Top-level runs only.** The brief's value — a single gather shared across **separate** dispatched subagents — materialises only when `splinter` runs **top-level** and dispatches `donatello` / `leonardo` as real Task subagents (separate processes, shared filesystem). A `splinter` invoked **as a subagent itself** has already spent the one nesting level, so it cannot dispatch separate specialists and instead returns a routing handoff (*Subagents of an agent*, case (b)) — there is no second process to read or append the brief, so the read-then-append loop does not apply to that nested case.

## Concurrency — working-tree write-lock

Several top-level `splinter` runs can target the **same project at once** (interactively). **The writing path never uses git worktrees**, so every writing run shares **one git working tree** and two runs that both write to it would corrupt each other's checkout and uncommitted edits. `splinter` guards this with a **scope-conditioned write-lock**, and processes the sources of a single request **sequentially, never fanning out**. The read-only code-review agent (`leonardo`) **may** opt into a throwaway read-only worktree for its review — it carries no write-lock, so it never contends here, and `splinter` removes any CR worktree during its post-run cleanup:

- **Read-only runs overlap.** An analysis-only run (dispatching `leonardo` in its security analysis mode) never modifies the working tree, so it takes **no** lock — any number of independent analysis runs overlap freely, with each other and with a writing run. When a single request resolves multiple sources, they are still processed **one at a time** (no parallel fan-out); when multiple linked issues exist, `splinter` plans a dependency-aware resolve order (reading `## Dependencies` from each issue) that takes precedence over oldest-first when issues are interlinked.
- **Writing runs serialise.** A full-delivery run (dispatching `donatello`) acquires a lock before the dispatch and runs one at a time. A second writing run that finds a live holder stops with `Blocked` and a remediation (**wait for the holder to finish and retry** — the writing path takes no worktree, so there is no isolated-worktree escape to run writing work in parallel) instead of dispatching `donatello` into another run's changes.
- **Keyed to the toplevel.** The lock is a directory at `.claude/run/.splinter-write.lock` inside the current toplevel's git-ignored `.claude/run/`. Because the writing path never uses worktrees, every full-delivery run resolves to the same toplevel and the same lock, so concurrent writing runs always serialise on the shared tree. Acquire is atomic (`mkdir`), a stale lock from a crashed run is reclaimed via a `kill -0` PID probe, and the lock is released on the final report and on any `Blocked` stop. See `agents/splinter.md` *Concurrency & the working-tree write-lock* for the mechanism.

## Subagents of an agent

Claude Code subagents invoked via the Task tool generally **cannot spawn their own subagents** (one level of nesting). This shapes how the roster composes:

1. **A top-level orchestrator dispatches specialists through the Task tool.** `splinter` runs as the top-level agent the user talks to, and spends its single nesting level dispatching `donatello` / `leonardo` directly. Each specialist then orchestrates its own skills inline — `donatello` runs `resolve-issue`, `leonardo` runs `code-review-github`, and so on.
2. **Lens skills called inline** by an orchestrating skill — e.g. `code-review-github` already runs `code-review`, `security-review`, `api-review`, `assignment-compliance-check` inline. This is what each dispatched specialist does in its own context, and it is also the fallback when no further nesting level is available.
3. **Parallel fan-out via the Workflow tool** — a DAG of agents for heavy runs that genuinely need concurrency.

Because of the one-level limit, an orchestrator like `splinter` must be the **top-level agent the user talks to** — it delegates each step by dispatching the matching specialist agent (or, if `splinter` was itself invoked headless and the nesting level is already spent, returns a routing handoff for the caller to execute), never by becoming a nested subagent that tries to spawn `donatello` / `leonardo` from inside another agent. The same limit is why **`splinter` performs backlog work inline instead of delegating it**. A backlog tier stacked above the orchestrator (`backlog agent → splinter → specialist`) would need three Task-subagent levels, which the runtime does not allow; a backlog tier beside it (the retired `zeus`) fitted, but only as one more dispatched specialist. With that peer retired into `splinter` itself (*Retired agents* above), there is no level left to spend on delegating triage or decomposition to anyone — so `splinter` runs them in its own context and spends its single nesting level where it is actually needed, on `donatello` / `leonardo`.

### End-to-end run (agent-dispatched, skill-owned loop)

The `splinter` run carries a request all the way to a clean, reviewed result. `splinter` resolves the source itself, then **dispatches each step as the matching specialist agent through the Task tool**; the iterative `donatello` ↔ `leonardo` review-and-fix loop is **owned by the skill the dispatched specialist drives** (its state lives there), not modelled as agents calling agents:

```text
user → splinter                                         (top-level; resolves source, then dispatches via Task tool)
         │  resolve source (oldest-open-issue selection / resolve-issue source-detection); classify, never execute here
         │  gather context → shared brief .claude/run/<slug>.md   (written before any branch below runs)
         │  classify-risk.sh → FAST | STANDARD | CRITICAL  (deterministic; decides who runs below and on which model)
         │  backlog request (triage / prioritise), or the source classified too broad for one PR? ── yes ─→ run the backlog tier inline, no PR:
         │       github-issue-triage → Triage done (ordered queue)  |  create-issues-from-text → Breakdown done (created issues, re-run per piece)
         │     │ no
         │  security-focused? ── yes ─→ Task ▶ leonardo (security analysis mode = security skills + analyze-problem → remediation plan; Security analysis done) → feeds donatello
         │     │ no
         ▼     ▼
       Task ▶ donatello   (= resolve-issue; default tier, escalated on a CRITICAL tier / a recorded reason)
         │        └─ lightweight pre-PR self-check (deterministic: criteria, diff-targeted tests, static analysis, no debug artifacts) → opens Draft PR
         │        └─ re-classify against the real diff (--floor = initial tier; the tier can rise, never fall)
         ▼
       Task ▶ donatello    (fast scoped validation mode — CRITICAL tier only; diff-targeted tests + acceptance-criteria check)
         │        └─ Tests done (scoped) → proceed | Blocked → escalate to donatello
         ▼
       FAST tier? ── yes ─→ no CR pass: splinter promotes the PR out of Draft after the scoped pass below, records stage|skipped|leonardo
         │     │ no
       Task ▶ leonardo  (default tier, escalated on a CRITICAL tier; = process-code-review / code-review-github — the single CR pass: quality / architecture / optimisation + laravel-security + security-bounty-hunter + security-threat-analysis — the donatello ↔ leonardo loop)
         │        └─ leonardo: convergence loop (code-review-github + fixes, maxIterations 3) → one published review → 0 Critical/Moderate
         │           (leonardo dispatch guarded by registration check — fallback: review inline in code-review-github)
         ▼
       Task ▶ donatello    (fast scoped validation mode — final gate after convergence; skipped over an already-validated head, never skipped on a FAST run)
         │        └─ Tests done (scoped) → proceed | Blocked → escalate to user
         ▼
       Task ▶ april   (post-convergence reporting — publishes a human-readable "what changed + how to test" to the source tracker via pr-summary, built from the brief + donatello's scoped handoff; fallback: inline summary in handoff when no tracker)
         │        └─ Reporting done (comment link, read back) | Reporting done (already covered) (covering comment URL) | Reporting done (no tracker) (inline)
         ▼
       splinter → reports result to the user   (merge stays a separate, explicit step — always via @skills/merge-github-pr/SKILL.md)
```

The scoped validation dispatch runs at **push-level granularity** — after `donatello` opens the PR (high-risk changes only) and once after the `leonardo` CR converges (every run whose converged head is not already validated — see `agents/splinter.md` step 6). Running it inside the `leonardo` loop would require `leonardo` to dispatch a subagent, which violates the one-level nesting rule (the nesting level is already spent on dispatching `leonardo` from `splinter`). `splinter` is therefore the correct dispatcher for both scoped passes.

The convergence gate is **0 Critical with no undeferred Moderate** (`@skills/process-code-review/SKILL.md` *Review loop* step 4); at round 3 a non-security Moderate that clears the filing bar is deferred into a tracker sub-issue, and anything left blocking stops the run and escalates instead of reporting success.

## Adaptive routing (always on)

The pipeline above is the `CRITICAL` shape of the run. Most tasks do not need it, and paying for it anyway is where the bulk of a run's tokens used to go: a README typo bought the same agent sessions, the same expensive models, and the same review passes as an authorization rewrite.

`skills/_shared/classify-risk.sh` decides how much pipeline a task gets. It is a shell script rather than another agent, because a router that asks a model how risky a task is adds an LLM call to save LLM calls and answers differently on every run. Its verdict prints the score and every signal that produced it, so a routing decision can be argued with rather than only obeyed.

| Tier | Who runs | Typical change |
| --- | --- | --- |
| `FAST` | `donatello` (default tier) + deterministic validation | docs, README, typo, formatting, tests-only, simple config, rename, small isolated fix |
| `STANDARD` | `+ leonardo` (default tier) | ordinary application and business-logic work |
| `CRITICAL` | `+ leonardo` upfront analysis where relevant, both at the escalated tier, `raphael` when behaviour is observable | auth, authorization, secrets, payments, migrations, data loss, concurrency, queues, locking, cache consistency, public APIs, core architecture, large refactors |

Four properties are what make it safe to route this way:

- **A sensitive area forces `CRITICAL` on its own**, regardless of the score and regardless of a lower explicit override.
- **The tier is recomputed against the real diff** after implementation, carrying the first verdict as a floor. A task that grew into an authorization change is reviewed like one; a tier never falls.
- **Deterministic gates are untouched at every tier.** Tests, static analysis, linting, CI, and the pre-merge quality gate run on a `FAST` change exactly as on a `CRITICAL` one. What the tier buys is LLM reasoning, and only that.
- **The decisions are recorded.** `.claude/run/<slug>.routing` carries the initial and final tier with their signals, every stage executed or skipped with its reason, and every model escalation with its reason — so *"why was `leonardo` executed?"*, *"why was the expensive model used?"* and *"why was this `CRITICAL`?"* are answerable from the record.
- **It is platform-neutral.** The classifier is a shell script, and the tiers are roles rather than model names, so a Codex / OpenAI session routes the same task the same way. `codex/agents/*.toml` binds the two tiers to that platform's controls, and a session that cannot change the model for one step records what it applied instead rather than claiming an override.

A caller who disagrees overrides it: `--thorough` runs the complete pipeline regardless of the classification, and `--fast` / `--standard` / `--critical` name a tier directly. An escalating override always applies; a de-escalating one is refused when a sensitive-area force fired, and the refusal is printed rather than silent.

**What this trades away, stated rather than hidden:** on a `FAST` run nobody reads the diff with a reviewer's eye, and the merge gate lets such a pull request through on the strength of the deterministic gates plus a classifier re-run it performs itself. That is the saving, and it is the reason the force and the re-classification are written the way they are.

## Deterministic stages — what no longer costs a session

Three stages used to be agent dispatches whose whole job was to run commands and read exit codes:

| Stage | Helper | Escalates to an agent when |
|---|---|---|
| Validation | `skills/_shared/run-validation.sh` | a check failed and the failure needs interpreting, or the manifest is invalid, stale, or refused |
| Route planning | `skills/_shared/plan-route.sh` | never — it emits the stage list the tier implies |
| Completion report | `skills/_shared/render-report.sh` | the audience needs real writing, or the language is one it does not carry |

`donatello` writes a **validation manifest** (`head_sha` plus the `tests` / `static_analysis` / `lint` commands covering its own diff) as part of its handoff. `splinter` runs it and dispatches a session only on the runner's own `escalate` verdict. The manifest is never passed to a shell: commands are split into an argv array, matched against an allow-list of project-local tools, and refused outright if they carry a shell metacharacter — the manifest is written by an agent whose context includes tracker text anyone can write, so it is data to execute, never a script.

Handoffs are structured and bounded (`skills/_shared/check-handoff.sh`, default 600 prose-equivalent words): decisions and artifact paths, never a diff, test output, or a quoted prior handoff. A handoff is re-read by every later stage, so anything it carries is re-tokenised once per stage.

Each completed run appends counts to a local metrics store outside the repository (`skills/_shared/record-metrics.sh`, summarised by `ai-olympus stats`). It holds tiers, outcomes, dispatch and escalation counts, review rounds, and finding counts — never source, diffs, prompts, tracker text, branch names, or URLs.

## Context-efficient orchestration (the default)

This used to be an opt-in *savings mode*. It is now how every run works, because nothing it does is a trade: it skips no gate, changes no reviewer, and weakens no convergence criterion, so an opt-in only ever meant some runs paid for overhead nobody wanted. A full run costs roughly the same subagent-token budget whether the diff is a one-line UI tweak or a multi-file feature, because most of the cost is orchestration overhead, not work proportional to the change. **Savings mode** (`@rules/compound-engineering/orchestration.md` *Savings mode*) is an opt-in variant of the exact same pipeline — same agents, same order, same convergence gate — that removes four concrete sources of that overhead. It is off by default and engages only on an explicit user request; this section explains **why** each mechanism actually reduces tokens, the canonical rule states **what** each agent must do.

| Waste source | Mechanism | Why it saves tokens without reducing review depth |
|---|---|---|
| The orchestrator narrates a plan instead of executing it | *Orchestrator turns must end in a result or a hard blocker* (unconditional) | A turn that only restates "next I will dispatch X" burns a full context window (re-reading the plan, the brief, prior handoffs) and returns nothing executed. Forcing every turn to end in a completed dispatch or a real blocker removes that wasted turn entirely — it is a correctness fix, not a quality trade-off, so it applies whether or not savings mode is on. |
| The orchestrator hands the reviewer a tracker link and it re-derives the diff / acceptance criteria / invariants itself | Scoped context assembled from the run manifest | Deriving the diff, the assignment, and the acceptance criteria from the tracker is pure input-token cost that the gather phase has already paid once — handing `leonardo` the finished pack removes the second derivation. (This mechanism used to also split *overlapping* invariants between two parallel reviewers so one defect was not reported twice; collapsing the review into a single pass removed that duplication at the source, so there is nothing left to split.) |
| A read-only CR reviewer in an isolated worktree cannot run tests, so its coverage verdict is a static read, and the `donatello` scoped pass re-derives the real number anyway | Single coverage-verdict owner | Paying for a static-read coverage guess that a later step re-derives by actually running the suite is pure duplicate cost with no accuracy benefit — the static guess is strictly worse evidence than the real run that already happens. Naming the `donatello` scoped validation pass (or CI) the sole owner of the *executed* verdict removes the guess, not the check: the real, authoritative measurement still runs exactly where it already did. |
| The orchestrator re-derives the workflow from prose at every step | The deterministic route plan (`skills/_shared/plan-route.sh`) | Once a run has no remaining branching decision, restating the full plan and its justification at every step transition is pure narration cost — the specialist being dispatched reads the same plan from the brief anyway. Reading the handoff, checking the one pre-named gate, and dispatching removes the restatement, not the dispatch, the specialist, or the gate; a genuine branching decision still gets full reasoning whenever one actually arises. |

None of the four mechanisms removes a reviewer, a skill, or a gate — every one of them removes **duplicate re-derivation or duplicate execution** of work already done once. A run and the identical run under `--verbose-orchestration` converge on the same diff with the same Critical / Moderate finding count — verbosity changes what is narrated, never the result.

**The build-gate dedup mechanisms are retired.** Deferring the quality gate to the end of the work (`@skills/resolve-issue/references/quality-gates.md` *Gate placement — deferred to the merge boundary*) left one gate run per branch, so the tree-hash build-gate cache (#119), the head-SHA push-level dedup (#212), and the loop-gate CI reuse (#124) each lost the repeated build they existed to remove. The one reuse that remains — the merge accepting the run recorded by `@skills/process-code-review/SKILL.md` *Finalization* for the exact head commit — is keyed to the head SHA and needs no cache.

## Troubleshooting — subagent file writes blocked

**Symptom:** a write-capable agent (`donatello`) reports it cannot write files — *"sandbox blocking file writes"* — and the run stops with a `Blocked: sandbox denied file write` handoff (or the main thread is tempted to finish the implementation itself).

**Cause:** the agent declares `Write` / `Edit` in its frontmatter, but those tools are *capabilities*, not grants. A dispatched subagent runs **non-interactively** — when its `Edit` / `Write` is not already pre-allowed for the path it targets, it cannot fall back to an interactive approval the way the main thread can, so the write is denied at runtime. This is an environment setting, not something the agent definition or this package can grant.

**Correct behaviour (already enforced):** the blocked agent returns `Blocked: sandbox denied file write` and the orchestrator escalates it — the work is **never** silently completed outside the delegated, reviewed pipeline (`@rules/compound-engineering/general.md` *Blocked delegation is a hard stop*).

**Remediation (the human enables subagent writes) — pre-allow scoped `Edit` / `Write` on the working tree.** Add two scoped allow entries to **`permissions.allow`** in the project's `.claude/settings.local.json`, naming the project's absolute path:

```json
{
  "permissions": {
    "allow": [
      "Edit(//Users/me/Projects/my-app/**)",
      "Write(//Users/me/Projects/my-app/**)"
    ]
  }
}
```

This is the permanent, recommended fix: a dispatched subagent then writes the working tree without an interactive prompt. `settings.local.json` (personal, git-ignored) is the right home because the entries carry your machine-absolute path. A blanket `acceptEdits` permission mode also works for an interactive session, but the scoped allow entries survive across sessions and headless runs. See the Claude Code [permissions](https://code.claude.com/docs/en/permissions) and [subagents](https://code.claude.com/docs/en/sub-agents) docs.

**Installer shortcut (opt-in).** The fix above can be applied for you: run the installer with `--allow-subagent-writes` and it prepends `Edit(//<project>/**)` and `Write(//<project>/**)` to `permissions.allow` in the project's `.claude/settings.local.json`, validating the result so it can never be written malformed. It leaves existing allow entries untouched and is idempotent. This package still grants **nothing by default** — the flag is the explicit, human-owned opt-in, never automatic.

## Restricting outbound network Bash — installer shortcut (opt-in)

**Symptom:** the *Capability model* above says every agent's "no raw network call via Bash" stance is advisory, and you want at least the obvious commands to be refused by the harness rather than by the agent's own good behaviour.

**Installer shortcut (opt-in).** Run the installer with `--deny-network-bash` and it appends ten `permissions.deny` patterns — `Bash(curl:*)`, `Bash(wget:*)`, `Bash(nc:*)`, `Bash(ncat:*)`, `Bash(netcat:*)`, `Bash(telnet:*)`, `Bash(ssh:*)`, `Bash(scp:*)`, `Bash(sftp:*)`, `Bash(openssl s_client:*)` — to the project's `.claude/settings.local.json`, preserving existing `allow` and foreign `deny` entries and validating the result after writing. It is idempotent, and this package still grants nothing by default: the flag is the explicit, human-owned opt-in, never automatic.

**The trade-off you are accepting — session-wide, project-scoped, never per agent.** A `permissions.deny` rule is a property of the *session*, not of an agent. Inside that project it restricts **every agent and your own interactive Bash identically** — `curl` in your own Claude Code prompt is refused just as `donatello`'s would be. Your ordinary terminal outside Claude Code is unaffected, and no other project on the machine is touched (which is exactly why the flag writes the project-local file rather than `~/.claude/settings.json`, where a deny could never be relaxed again).

**What it does not buy.** It is not an egress control and does not make the Bash boundary per-agent — the rule matches command strings, not process trees, so `gh`, `git`, `composer`, `php -r`, `node -e`, `bash -c 'curl …'`, `/usr/bin/curl`, `/dev/tcp`, and unlisted tools such as `socat` all stay open, and the package's own Bugsnag / attachment scripts keep working for the same reason. The full bypass list and the manual undo procedure live in `SECURITY.md` *`--deny-network-bash`*; read it before turning the flag on so the expectation matches what the harness actually enforces.

## Distribution

The installer copies the canonical `agents/*.md` definitions to `.claude/agents/` and `.codex/agent-instructions/`. Small project-scoped TOML adapters in `.codex/agents/` teach Codex the path and collaboration-tool mappings, so both harnesses execute the same role definitions instead of maintaining two prompt copies.

## Adding a new agent

1. Pick a Ninja Turtles character whose personality matches the job; use a stable lowercase identifier.
2. Create `agents/<name>.md` with the frontmatter + an orchestration-only system prompt that delegates to skills and returns a handoff.
3. Add it to the README *Claude Code and Codex Subagents* roster (an avatar + role card).
4. Add a test asserting the file ships with its required frontmatter (mirror the `leonardo` test in `tests/Installer/AgentsTest.php`).
5. Run `vendor/bin/pest tests/Installer` — the installer file-count tests pick up the new agent automatically. The full build runs once at the merge boundary, not here.
