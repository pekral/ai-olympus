# Agents

Agents are **Claude Code subagents** that act as a thin orchestration layer over the existing skills. They run in their own context window, delegate the real work to skills, and hand a clean result back to the caller.

```text
Rules  = long-lived project standards
Skills = reusable workflows
Agents = specialised orchestration roles over multiple skills
```

## Agent roster

Every agent has its own avatar under [`assets/agents/`](../assets/agents). When no custom artwork has been supplied yet, the slot falls back to the universal placeholder ([`placeholder.svg`](../assets/agents/placeholder.svg)) — swap `assets/agents/<name>.svg` to give an agent its own face.

### <img src="../assets/agents/athena.png" alt="athena avatar" width="48" align="left"> `athena` — code-review sentinel & security analyst

The strategic sentinel and **the roster's only code-review agent**, named after **Athena**, goddess of wisdom and strategic defence. It owns the review domain in **two modes**. (1) **Security analysis (pre-implementation)** — dispatched on demand when the task carries a cyber-security question: it scopes the security risk through all security skills, frames the smallest safe remediation via `analyze-problem`, publishes a plan artifact, and hands back a `Security analysis done` summary that `hephaestus` implements. (2) **Code review (post-implementation)** — given a PR from the current context or a tracker link, it runs the matching `code-review-*` wrapper (which drives the full CR skill set, security pass included), adds the three security skills the wrapper does not run, applies every security rule, labels each finding (Critical / Moderate / Minor), publishes **one** consolidated review, drives the fix loop to convergence, and hands back a `CR done` summary. There is no second CR pass to wait for, no barrier, and nothing to consolidate from a peer.

- **Trigger:** a pull request needs reviewing, or a task carries a cyber-security question and needs a pre-implementation security-risk analysis.
- **Orchestrates:** `code-review-github`, `code-review-jira`, `code-review-bugsnag`, `process-code-review`, `security-review`, `laravel-security`, `security-bounty-hunter`, `security-threat-analysis` (plus `analyze-problem` in analysis mode).
- **Rules applied:** `@rules/security/backend.md`, `@rules/security/frontend.md`, `@rules/security/mobile.md`, `@rules/security/general.md`.
- **Safety:** read-only — never edits, commits, pushes, or merges (`hephaestus` implements what it analyses and fixes what it finds).
- **Registration dependency:** dispatchable only after the installer copies `agents/athena.md` to `.claude/agents/`. Until then, the review runs inline in `code-review-github` (the continuity fallback), and the pre-implementation analysis mode is skipped — `hephaestus`'s pre-PR self-check still runs `code-review` + `security-review` over its own diff.

### <img src="../assets/agents/hephaestus.png" alt="hephaestus avatar" width="48" align="left"> `hephaestus` — code-writing implementer

The tireless smith of the gods, named after **Hephaestus**, who forged at his own anvil what the other gods only asked for. Give it a source — a tracker link (GitHub, JIRA, Bugsnag) or the current task — and it implements the fix or feature, runs local checks (`composer build`: tests, phpstan, pint, rector, phpcs, skill-check) and fixes their errors, opens a pull request, and hands back an `Impl done` summary with links. The whole code review — quality, architecture, optimisation and security — belongs to `athena` — `hephaestus` does not own it. Scoped validation is `hephaestus`'s own second dispatched mode since `apollon` was retired; the post-convergence report went to `hermes`, not here — `hephaestus` writes code and tests and never publishes to a tracker. The read-only CR run by `athena` may isolate in a worktree; write-capable runs are serialised via the working-tree write-lock (rule #627). It is the write-side counterpart to `athena`: `athena` is the tireless eye (review), `hephaestus` the tireless hands (implementation).

- **Trigger:** an issue or task needs implementing, or a landing step needs a fast scoped validation pass.
- **Orchestrates:** `resolve-issue`; plus `create-test` / `create-missing-tests-in-pr` and `e2e-testing` for the test coverage it authors.
- **Two modes:**
  - **Implementation (default)** — the full `resolve-issue` pipeline, ending at the Draft PR.
  - **Fast scoped validation gate (push-level)** — `daedalus` re-dispatches `hephaestus` after a landing step: after the PR opens only when `daedalus` classified the change as high-risk (shared / core / config surface, more than 10 files, or security impact), and always once after the `athena` CR converges. In this mode `hephaestus` derives the changed surface from the diff, runs only the affected tests, and verifies the relevant acceptance criteria against the diff. Full `composer build` is used only when the change is broad. This gate runs at push-level granularity — inside the `athena` loop itself would violate the one-level nesting rule, so `daedalus` is the dispatcher, not `athena`. Handoff: `Tests done (scoped)` or `Blocked`.
- **Safety:** stops at the PR — never reviews its own work, never merges, and never publishes to a tracker. If a caller explicitly instructs a merge, the only permitted path is `@skills/merge-github-pr/SKILL.md` — never `gh pr merge` or bare CLI.

### <img src="../assets/agents/daedalus.png" alt="daedalus avatar" width="48" align="left"> `daedalus` — engineering-workflow orchestrator

The master craftsman who runs the workshop, named after **Daedalus**, the legendary engineer who designed the work and directed the makers. It is the **entry point** for a free-form engineering request — *"resolve a random issue"*, *"resolve this URL"*, *"implement this"* — and the conductor that drives the job to a clean, reviewed result. It resolves a concrete source, decides whether a security-focused task needs a remediation plan first, then **delegates each step by dispatching the matching specialist agent** through the Task tool — `hephaestus` (implementation, then again for fast scoped validation — after PR-open for high-risk changes only, always after the CR converges), `athena` (the single CR pass after `hephaestus`, plus a pre-implementation security-risk analysis on demand when the task carries a cyber-security question), and `hermes` (the post-convergence report) — and reports the result to the user. It also owns the **backlog tier** itself and runs it **inline, in its own context** — triage over the open backlog, and splitting a subject too broad for one PR into deliverable issues, after which the run ends at those issues, each re-entering later as its own run. That tier used to be a peer agent (`zeus`, retired — *Retired agents* below); with no peer left to dispatch it to, and no third nesting level to invent one on, `daedalus` performs it itself as the second of the two named exceptions to its own delegate-everything rule. The roster still carries no general (non-security) analysis agent — the backlog tier owns *what* is worked on, never the problem — so a pure analysis request is answered by running `@skills/analyze-problem` in the top-level session. When resolving multiple linked issues, it plans a dependency-aware resolve order (reading `## Dependencies` from each issue) that takes precedence over strict oldest-first. `hephaestus` the hands (and the scoped-validation gate), `athena` the eyes, `hermes` the voice; `daedalus` the workshop lead that directs them.

- **Trigger:** a free-form engineering request — from a vague idea to a tracker link — that should be carried end to end; or a backlog decision — *"triage the open issues"*, *"what should we work on next"*, *"this assignment is too big for one PR, split it"* — which it answers inline and ends there, without opening a pull request.
- **Orchestrates (dispatches via the Task tool):** `hephaestus` (implementation step — owns `resolve-issue`, which runs a pre-PR self-check with `code-review` + `security-review` over its own diff — a single-pass self-validation, not the authoritative review that `athena` owns; the PR opens only with every surfaced Critical/Moderate finding resolved, and full-diff convergence belongs to the `athena` loop), `hephaestus` again as the **fast scoped validation gate** — dispatched after PR-open only for high-risk changes and always after the CR converges; runs only the tests covering the diff and verifies the relevant acceptance criteria; full `composer build` only for broad changes; each re-dispatch is recorded in the ledger under its moded role (`hephaestus:scoped`) so it is never mistaken for a repeat of the implementation round), `hermes` (the **post-convergence report** — dispatched last, to publish the human-readable non-technical summary to the source tracker via `pr-summary`; ledger role `hermes:reporting`), `athena` (the **single CR pass** — code quality, architecture, optimisation and security in one run — part of the `hephaestus` ↔ `athena` convergence loop, owns `process-code-review` / `code-review-github`, `maxIterations = 3`; and on demand a pre-implementation **security analysis** (security skills + `analyze-problem` → remediation plan that `hephaestus` implements) when the task carries a cyber-security question; active only after the installer registers it — fallback: the review runs inline in `code-review-github`); resolves the source itself using an oldest-open-issue selection (label `Resolve_by_AI`, excluding already-claimed issues) and `resolve-issue` source detection.
- **Runs inline (not dispatched):** `github-issue-triage` (triage mode), `create-issues-from-text` / `create-issue` (decomposition mode) — the backlog tier absorbed from the retired `zeus`. Handoff: `Triage done` / `Breakdown done`, and the run ends there.
- **Convergence gate:** the run is done only at **0 Critical + 0 Moderate** (security Critical findings arrive in the same `athena` handoff and count identically); on `maxIterations` or a blocker it stops and escalates rather than reporting success. Merging stays a separate, explicit step — when instructed, always via `@skills/merge-github-pr/SKILL.md`, never ad-hoc CLI.
- **Safety:** read-only orchestrator — never analyses, implements, or reviews itself; it delegates each step by dispatching the matching specialist agent, the iteration loop is skill-driven (state lives in the skill the specialist owns), and it must be the top-level agent (not a nested subagent) per the one-level nesting rule below — that single level is what it spends to dispatch `hephaestus` / `athena`. The inline backlog tier does not soften that: it holds no `Write` / `Edit` tool, its only writes are the labels and issues the three backlog skills create on the tracker, and it never analyses, diagnoses, or designs.

### <img src="../assets/agents/argus.svg" alt="argus avatar" width="48" align="left"> `argus` — acceptance tester / QA

The hundred-eyed watchman who never closed every eye at once, named after **Argus Panoptes**. It is the only agent that **runs the application**: it starts a local instance and tests it the way a real tester would — the **API through an HTTP client that actually crosses the network** (recording the exact request and response), the **UI through a real browser** (navigating, filling, clicking, observing what renders and watching the network for a click that silently 404s) — driven by the project's own automation when it has one, otherwise by the `skills/_shared/browser-drive.sh` runner this package ships, which needs no config file and no dev-dependency inside the project under test — and returns a per-criterion `Met` / `Not met` / `Partial` / `Blocked` verdict with the steps it performed. Neither channel substitutes for the other: a UI criterion that cannot be driven in a browser is `Blocked`, never satisfied by calling the endpoint behind the page. Read-only with respect to code — it never edits a file, authors a test, commits, merges, or publishes.

- **Trigger:** dispatched by `daedalus` after the post-convergence scoped pass, **only when the change alters behaviour a user can observe**. The browser is gated once more inside the run: it starts only when the diff shows a UI surface changed, so an API-only task is still exercised over HTTP but never pays for a browser run that would verify the previous release. A pure refactor, dependency bump, docs change, test-only change, or CI change is skipped with the reason named in the route.
- **Orchestrates:** `tester-cookbook` (report shape), `e2e-testing` (only when the project has already adopted Playwright).
- **Why it is a separate agent:** its **input** differs from every other agent's. `athena` reads the diff; `hephaestus` reads the diff plus the suite it wrote. `argus` reads the **running system**, which is where the defects that survive a green build live — a missing migration, a config key absent outside the test environment, a queue worker nobody starts, a permission that reads correctly and denies the wrong user. The boundary it must guard is not `athena`'s review but `hephaestus`'s scoped validation, which checks the same acceptance criteria *against the diff*; `argus` checks them *against the running system*, and never accepts a criterion on the strength of the diff or a passing test.
- **Safety:** local instance only — never a shared, staging, or production host, and never a third-party endpoint. A criterion it did not exercise is reported `Blocked`, never `Met`.

### <img src="../assets/agents/hermes.png" alt="hermes avatar" width="48" align="left"> `hermes` — release announcer / publicista

The messenger who carries the message after the work is done, named after **Hermés (posel bohů / messenger of the gods)**, the swift divine messenger whose sole role was to deliver the official announcement. It is the roster's **only publishing agent**: every other agent hands its result back through the shared brief or its handoff, and anything that reaches a tracker audience routes through `hermes`. Give it a merged change, a release, or a shipped feature and it loads the source read-only, composes the announcement content (Twitter/X tweet ≤280 chars + thread, release notes, marketing summary with **pekral.cz** promotion), and hands back an `Announce done` summary with all drafts inline. It runs **post-delivery**, outside the CR loop.

- **Trigger:** a merged change or release needs announcement content — tweet, thread, release notes, or marketing summary — or a converged run needs its post-convergence report published.
- **Orchestrates:** `resolve-issue/references/source-detection` (source loading, read-only), `pr-summary` (post-convergence reporting to the source tracker).
- **Two modes:**
  - **Announcement (default)** — drafts the tweet / thread / release notes / marketing summary and returns them inline. Publishing needs an explicit ask (L2).
  - **Post-convergence reporting** — `daedalus` dispatches `hermes` as the final step of a full-delivery run, after the CR converges and `hephaestus`'s scoped validation confirms `Tests done (scoped)`. It composes the human-readable, non-technical summary (what changed + how to test) in the language from the brief `## Language` and publishes it to the source tracker via `@skills/pr-summary/SKILL.md`. Publishing is the deliverable of this dispatch, so it is pre-approved (L1). With no linked tracker it returns the summary inline and `daedalus` passes it to the user. Handoff: `Reporting done` or `Reporting done (no tracker)`.
- **Why `model: haiku` is the right tier.** In both modes `hermes` composes prose from evidence other agents already produced — the brief's `## Gathered context`, the converged PR, and `hephaestus`'s scoped-validation handoff (the executed tests, the coverage verdict, the acceptance-criteria statuses). It runs no suite, authors no test, and derives no new fact, so the job is summarisation of existing text rather than reasoning about code. Raising the tier would buy nothing; if a future change makes `hermes` derive facts of its own, revisit the tier in the same change.
- **Safety:** read-only — never edits, commits, pushes, or merges. Publishes only through the canonical `upsert-comment.sh` wrapper — never raw `gh ... comment`.
- **Registration dependency:** dispatchable only after the installer copies `agents/hermes.md` to `.claude/agents/`.

## Retired agents

An agent removed from the roster is recorded here rather than deleted without a trace, so an agent (or a human) that finds a stale `@apollon` reference in an old PR, brief, or memory entry can resolve it to what replaced it instead of guessing — or worse, trying to dispatch a `subagent_type` that no longer exists.

| Agent | Retired | Where its work went |
|---|---|---|
| `apollon` — test engineer & post-convergence reporter | 2026-08-07 | **Split across `hephaestus` and `hermes`, along the roster's capability line.** Test authoring is part of the implementation run (`resolve-issue` already drove the TDD and coverage gates), and the *fast scoped validation gate* became `hephaestus`'s second dispatched mode (`agents/hephaestus.md`) — both are code work. *Post-convergence reporting* went to `hermes` (`agents/hermes.md`), the roster's only publishing agent, so the write-capable implementer never gains the right to post on a tracker. The savings-mode coverage deferral now reads `deferred to hephaestus`. |
| `argos` — quality / architecture / optimisation reviewer | superseded by the single-CR-pass consolidation | **`athena`**, which runs the whole review — quality, architecture, optimisation **and** security — in one pass, with no barrier and nothing to consolidate from a peer. |
| `zeus` — backlog owner / project manager | 2026-08-25 | **Both modes to `daedalus`, run inline rather than dispatched.** Triage and decomposition were the whole of the job, and the successor is the orchestrator that used to dispatch them — so there is nobody left to delegate them to. `agents/daedalus.md` *Backlog tier — triage and decomposition, run inline* carries them verbatim as the **second** named exception to its own "never run the work in your own context" rule (the first is resolving the source), together with zeus's negative boundary: the roster still has no general (non-security) analysis agent, and absorbing the backlog tier does not make `daedalus` one. Its `Bash boundary` gained exactly zeus's two tracker writes — `gh label` / `gh issue create`, only through `github-issue-triage` / `create-issues-from-text` / `create-issue` — and no `Write` / `Edit` tool. The handoff statuses `Triage done` / `Breakdown done` are unchanged; they are now `daedalus`'s. |

**Retiring an agent here does not un-install it.** The installer copies `agents/` into `.claude/agents/` but only *removes* a file the source no longer ships when it is run with `--prune` (it otherwise prints `N file(s) across the target directories no longer exist in source. Re-run with --prune to remove them.`). A project that upgrades without that flag therefore keeps a stale `.claude/agents/<retired>.md`, and the retired agent stays dispatchable there with its old prompt — pointing at a pipeline position nothing dispatches any more. **Run `vendor/bin/ai-olympus install --prune` after upgrading past a retirement**, or delete the file by hand.

Rules for retiring an agent, so a removal never leaves the roster half-consistent:

1. **Name the successor before deleting anything, per responsibility, not per agent.** Every responsibility the agent owned moves to a named agent or is explicitly dropped — a responsibility with no owner is the failure this table exists to prevent. Route each one along the roster's existing capability line rather than handing the whole agent to one survivor: `apollon`'s test work went to `hephaestus` and its publishing work to `hermes`, precisely so the write-capable implementer did not also acquire the right to post on a tracker.
2. **Sweep the whole tree, not just `agents/`.** A retired name lives in the other agents' prompts, in `rules/`, in `skills/`, in the content-pin tests, and in `docs/memory/PROJECT_MEMORY.md` (`Role:` fields and `Rule:` guidance). `grep -ri <name> .` is the check; the content-pin tests are what keep it from drifting back.
3. **Re-key the dispatch ledger when a survivor gains a mode.** `agents/daedalus.md` *Dispatch ledger* keys a round on `{role, pr-head-sha, round}` — an agent dispatched more than once per run writes a moded role (`hephaestus:impl` / `hephaestus:scoped`), or the second dispatch reads as a repeat of the first and is suppressed.
4. **Add the row here and a CHANGELOG entry.** This table is what an agent reads; the CHANGELOG entry is what a human reads.

## Renamed agents

A rename is not a retirement. The agent keeps its role, its dispatched modes, and its handoff contract — only the name changes — so it gets its own table rather than a row above, where every entry means "this work moved to someone else".

| Old name | New name | Renamed | Note |
|---|---|---|---|
| `talos` | `hephaestus` | 2026-08-11 | Nothing about the role changed: same implementation run, same scoped-validation mode, same `Impl done` handoff. The Greek figure did. `Talos` was the bronze automaton; `Hephaestus` is the smith who forged him, which is the better match for the roster's builder. `Role: talos` in `docs/memory/PROJECT_MEMORY.md` now reads `Role: hephaestus`, so a lesson recorded for the implementer still reaches it. |

**A rename leaves the same stale file behind as a retirement**, for the same reason and with the same fix. The installer never removes `.claude/agents/talos.md` on its own, so an upgraded project carries both names and can still dispatch the old one against a prompt nothing updates. Run `vendor/bin/ai-olympus install --prune`.


Rules 2 and 3 above apply unchanged — a rename is a full-tree sweep, and a moded role (`hephaestus:impl` / `hephaestus:scoped`) is re-keyed with it. Rule 1 does not: no responsibility moves, so there is no successor to name.

## Naming convention — Greek mythology

Every agent is named after a figure from **Greek mythology**, chosen so the figure's role matches the agent's function. Use the lowercase name as the agent `name:` and file id (`agents/<name>.md`).

| Agent | Greek figure | Why it fits |
|---|---|---|
| `hephaestus` | Hephaestus, the divine smith who forged at his anvil what the other gods only asked for | the one who actually builds → forges working code |
| `argus` | Argus Panoptes, the hundred-eyed watchman set to keep watch and never close every eye at once | all eyes on the running system → exercises the delivered behaviour a user will meet |
| `daedalus` | Daedalus, the master craftsman who runs the workshop and directs the makers | head of production → routes engineering work to the right specialist |
| `athena` | Athena, goddess of wisdom and strategic defence | wisdom + strategic vigilance → the roster's single code-review sentinel (quality, architecture, optimisation, security) and pre-implementation security analyst |
| `hermes` | Hermés (posel bohů / messenger of the gods) | swift divine messenger, carries the message after the work is done → release announcer & the roster's only publishing agent |

Naming ideas for future agents: `themis` (order / verdict), `rhadamanthys` (fair judge), `iris` (delivery / merge). A retired name is **not** an idea: `zeus`, `argos`, and `apollon` stay out of this table and are never repurposed for a different role, or a stale reference in an old PR, brief, or memory entry would resolve to the wrong agent.

> **`argus` is not the retired `argos`.** Both transliterate Argus Panoptes, but they are different roles from different eras of this roster: `argos` was the quality / architecture / optimisation **reviewer**, retired when `athena` absorbed the whole review into one pass (*Retired agents* above); `argus` is the **acceptance tester**, which reviews nothing and instead runs the application. A stale `@argos` reference in an old PR, brief, or memory entry resolves to `athena`, never to `argus`.

## Anatomy of an agent

An agent is a Markdown file with frontmatter + a system prompt:

```markdown
---
name: athena
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
- **`disallowedTools`** — the second, harness-enforced layer (issue #163): names tools the agent must never receive even if a later edit to `tools:` (or an inherited default) would otherwise grant them. Every shipped agent carries one — read-only agents (`athena`, `hermes`, `daedalus`) list `Write, Edit`; agents with no documentation-fetch need (`hephaestus`) list `WebSearch, WebFetch`. It does **not** restrict what an agent can do through `Bash` — see *Capability model* below.
- **`memory`** — do **not** add this field to any agent in this roster. It automatically grants `Read`, `Write`, **and** `Edit` regardless of what `tools:` says, so adding it to a read-only agent silently reintroduces write access without ever touching the `tools:` line a content pin would catch.
- **`effort`** — reasoning effort while the agent is active (`low` / `medium` / `high` / `xhigh` / `max`); set to `high` on every agent (issue #179), the level that keeps the reasoning depth the pipeline needs without paying for `max` on every dispatch. The runtime clamps to the highest level the agent's `model` supports.
- **System prompt** — orchestration only. Delegate to skills via `@skills/<name>/SKILL.md`; **never duplicate a skill's rules** — defer to the skill as the source of truth.

## Capability model

Every agent's declared capability sits on one of two footings — **harness-enforced** (the runtime actually blocks the tool call) or **advisory** (the agent's own instructions state a boundary the harness does not check). Confusing the two is the exact gap issue #163 closed: a "read-only" or "no internet" claim is only as strong as what is listed below as enforced.

| Agent | `tools:` | `disallowedTools:` | Bash is for | Harness-enforced | Advisory only |
|---|---|---|---|---|---|
| `athena` | `Read, Glob, Grep, Bash, WebSearch, WebFetch` | `Write, Edit` | loaders, `gh` reads, publishing via `upsert-comment.sh`, `git worktree` on its own review checkout | no `Write`/`Edit` tool at all; `disallowedTools` blocks them a second way | never running a Bash write / network call outside its declared purpose |
| `hephaestus` | `Read, Write, Edit, Glob, Grep, Bash` | `WebSearch, WebFetch` | `gh`/`acli` via `resolve-issue`, write `git` on the feature branch, `composer build`, running the diff-scoped tests | no `WebSearch`/`WebFetch` tool at all | never `git push --force*`, never a raw network call via Bash, never a tracker publish (that is `hermes`'s) |
| `daedalus` | `Task, Read, Glob, Grep, Bash` | `Write, Edit` | resolving the source, maintaining the brief/ledgers under `.claude/run/*`, and the backlog tier's tracker writes (`gh label` / `gh issue create`) via `github-issue-triage` / `create-issues-from-text` / `create-issue` | no `Write`/`Edit` tool at all | never writing any tracked file through Bash redirection, never a bare `gh` write it composed itself |
| `hermes` | `Read, Glob, Grep, Bash` | `Write, Edit` | loaders, `gh` reads, publishing via `upsert-comment.sh` / `pr-summary` when explicitly asked (a reporting-mode dispatch being that ask) | no `Write`/`Edit` tool at all | never a `git` write op, never publishing without an explicit ask |

**Why Bash stays advisory.** All five agents carry `Bash`, and Bash subsumes both write access and network access no matter what `tools:` / `disallowedTools:` say — a "read-only" agent's own words do not stop `cat > file`, and a "no internet" agent's own words do not stop `curl`. Verified against the current harness: the frontmatter `tools:` field has no syntax for a scoped Bash command pattern (`Bash(gh:*)` is not expressible, and an unresolvable `tools:` entry prevents the agent from starting at all); `permissions.allow` / `permissions.deny` patterns are pattern-capable — this package's own installer already writes one via `--allow-bundled-scripts` — but apply **session-wide, never per agent**; and the only genuinely per-agent mechanism, a `hooks: PreToolUse` validator script, is a runtime component this instructions-only package does not ship. `@rules/compound-engineering/orchestration.md` *Bash capability boundary* is the normative contract every agent references; each `agents/<name>.md` carries its own `## Bash boundary` block naming the concrete purpose its Bash use serves. Of the two mechanisms that would narrow this gap, the first now exists as an **opt-in installer flag**: `--deny-network-bash` writes session-wide `permissions.deny` entries for ten literal network commands (`curl`, `wget`, `nc`, `ssh`, `scp`, `openssl s_client`, …), which the harness genuinely refuses. It is off by default and it does **not** make the boundary per-agent — the rule restricts every agent and the human's own interactive Bash in that project identically, and it matches command strings rather than process trees, so child processes of allowed commands, unstripped wrappers (`bash -c 'curl …'`), absolute paths, `/dev/tcp`, and unlisted tools stay open. Everything outside those ten command strings remains exactly as advisory as before. The second mechanism, a per-agent `PreToolUse` hook, does **not** exist here. This package shipped one behind `--enforce-agent-bash-boundary` and removed it again (issue #265): it was runtime code in an instructions-only package, it failed open in eleven separate cases, and it asked the user to confirm ordinary commands it could not read. The per-agent half of the boundary is therefore advisory in full. The OS-level tier (Claude Code sandboxing) remains unconfigured by this package and remains the only tier that would cover child processes. Full scope, the bypass list, and undo instructions: `SECURITY.md` *`--deny-network-bash`*, plus *Agent capability model & residual risk*.

## Architecture constraint

**This package ships instructions, never a runtime.** `rules/`, `skills/`, `agents/`, and `CLAUDE.md` are text an agent reads; the installer copies them into a consuming project and stops there. It is deliberately not a permission engine, a logging daemon, or a consent broker — which is why `@rules/compound-engineering/orchestration.md` *Audit trail for memory reads, outbound requests, and external writes* is a self-reported obligation rather than an interceptor, and why *Why Bash stays advisory* above describes a declared boundary rather than one the default install enforces.

## Handoff contract

An agent's final message is returned to the caller as the tool result, so it must be a self-contained handoff the next agent can act on without re-deriving context:

- **Status** — e.g. `CR done`.
- **Links** — the PR and the originating source (GitHub / JIRA / Bugsnag).
- **Result summary** — the numbers the caller needs (e.g. Critical / Moderate / Minor counts, a verdict).

**Language of the handoff / report.** Every agent writes the human-facing prose of its handoff and any end-user report in the **same natural language the assignment was given in** (if the request came in Czech, the handoff is in Czech). Identifiers stay verbatim regardless of that language — branch names, ticket / issue keys, links, severity labels, CLI commands, and skill / agent names are never translated, and two natural languages are never mixed inside a single handoff.

**How the language survives delegation.** When `daedalus` orchestrates, the assignment's natural language is not re-guessed at each hop — `daedalus` records it once in the shared brief's `## Language` field, writes every `Task` dispatch prompt in that language, and each specialist takes the brief's `## Language` field as the authoritative source for its reply. So a Czech request produces Czech output through the whole `hephaestus → athena` chain, not just in `daedalus`'s own final report.

## Shared task brief (inter-agent memory)

The handoff above is the *return* channel. For the *forward* channel — passing context **into** each agent efficiently — `daedalus` writes a **shared task brief** that every dispatched specialist reads, so the run's data is gathered once instead of re-derived by each agent.

- **Owner & gather phase.** Right after it resolves the source and **before the first dispatch**, `daedalus` runs a gather phase: it collects everything the task needs solved — the tracker payload and acceptance criteria (via the deterministic loaders), the relevant files / symbols / reproduction, known constraints, and its own **work-breakdown plan** (which specialist does what, with each one's success gate).
- **Location & lifecycle.** The brief lives at `.claude/run/<source-slug>.md`. `.claude/` is git-ignored, so it is **ephemeral and never committed**; `daedalus` removes it (`rm -f`) after the final report or a `Blocked` stop.
- **Read-then-append.** `daedalus` passes the brief's absolute path in every `Task` dispatch prompt. Each specialist **reads it first** as authoritative shared context, then **appends its own handoff section** (`### <agent> — <status>`) when it finishes, so the next specialist in the chain inherits the full history — source, plan, and every prior handoff — without `daedalus` re-passing it.
- **No new write scope.** Every agent already carries `Bash`, so the brief is created and appended through `Bash` redirection (`cat >> "$BRIEF" <<'EOF' … EOF`) to the git-ignored scratch path. No agent gains `Write` / `Edit` over the codebase from this — the read-only reviewer (`athena`) and the read-only orchestrator (`daedalus`) keep their read-only-codebase stance; the files they touch are the brief and the run's audit-trail ledger (`.claude/run/<source-slug>.audit`, per `@rules/compound-engineering/orchestration.md` *Audit trail for memory reads, outbound requests, and external writes* — every agent appends its own lines through the same `cat >>` redirection), plus, for `athena` alone, the optional read-only review worktree (`agents/athena.md` *Review worktree*). None of the three is source, and none of them changes a tracked file.
- **Top-level runs only.** The brief's value — a single gather shared across **separate** dispatched subagents — materialises only when `daedalus` runs **top-level** and dispatches `hephaestus` / `athena` as real Task subagents (separate processes, shared filesystem). A `daedalus` invoked **as a subagent itself** has already spent the one nesting level, so it cannot dispatch separate specialists and instead returns a routing handoff (*Subagents of an agent*, case (b)) — there is no second process to read or append the brief, so the read-then-append loop does not apply to that nested case.

## Concurrency — working-tree write-lock

Several top-level `daedalus` runs can target the **same project at once** (interactively). **The writing path never uses git worktrees**, so every writing run shares **one git working tree** and two runs that both write to it would corrupt each other's checkout and uncommitted edits. `daedalus` guards this with a **scope-conditioned write-lock**, and processes the sources of a single request **sequentially, never fanning out**. The read-only code-review agent (`athena`) **may** opt into a throwaway read-only worktree for its review — it carries no write-lock, so it never contends here, and `daedalus` removes any CR worktree during its post-run cleanup:

- **Read-only runs overlap.** An analysis-only run (dispatching `athena` in its security analysis mode) never modifies the working tree, so it takes **no** lock — any number of independent analysis runs overlap freely, with each other and with a writing run. When a single request resolves multiple sources, they are still processed **one at a time** (no parallel fan-out); when multiple linked issues exist, `daedalus` plans a dependency-aware resolve order (reading `## Dependencies` from each issue) that takes precedence over oldest-first when issues are interlinked.
- **Writing runs serialise.** A full-delivery run (dispatching `hephaestus`) acquires a lock before the dispatch and runs one at a time. A second writing run that finds a live holder stops with `Blocked` and a remediation (**wait for the holder to finish and retry** — the writing path takes no worktree, so there is no isolated-worktree escape to run writing work in parallel) instead of dispatching `hephaestus` into another run's changes.
- **Keyed to the toplevel.** The lock is a directory at `.claude/run/.daedalus-write.lock` inside the current toplevel's git-ignored `.claude/run/`. Because the writing path never uses worktrees, every full-delivery run resolves to the same toplevel and the same lock, so concurrent writing runs always serialise on the shared tree. Acquire is atomic (`mkdir`), a stale lock from a crashed run is reclaimed via a `kill -0` PID probe, and the lock is released on the final report and on any `Blocked` stop. See `agents/daedalus.md` *Concurrency & the working-tree write-lock* for the mechanism.

## Subagents of an agent

Claude Code subagents invoked via the Task tool generally **cannot spawn their own subagents** (one level of nesting). This shapes how the roster composes:

1. **A top-level orchestrator dispatches specialists through the Task tool.** `daedalus` runs as the top-level agent the user talks to, and spends its single nesting level dispatching `hephaestus` / `athena` directly. Each specialist then orchestrates its own skills inline — `hephaestus` runs `resolve-issue`, `athena` runs `code-review-github`, and so on.
2. **Lens skills called inline** by an orchestrating skill — e.g. `code-review-github` already runs `code-review`, `security-review`, `api-review`, `assignment-compliance-check` inline. This is what each dispatched specialist does in its own context, and it is also the fallback when no further nesting level is available.
3. **Parallel fan-out via the Workflow tool** — a DAG of agents for heavy runs that genuinely need concurrency.

Because of the one-level limit, an orchestrator like `daedalus` must be the **top-level agent the user talks to** — it delegates each step by dispatching the matching specialist agent (or, if `daedalus` was itself invoked headless and the nesting level is already spent, returns a routing handoff for the caller to execute), never by becoming a nested subagent that tries to spawn `hephaestus` / `athena` from inside another agent. The same limit is why **`daedalus` performs backlog work inline instead of delegating it**. A backlog tier stacked above the orchestrator (`backlog agent → daedalus → specialist`) would need three Task-subagent levels, which the runtime does not allow; a backlog tier beside it (the retired `zeus`) fitted, but only as one more dispatched specialist. With that peer retired into `daedalus` itself (*Retired agents* above), there is no level left to spend on delegating triage or decomposition to anyone — so `daedalus` runs them in its own context and spends its single nesting level where it is actually needed, on `hephaestus` / `athena`.

### End-to-end run (agent-dispatched, skill-owned loop)

The `daedalus` run carries a request all the way to a clean, reviewed result. `daedalus` resolves the source itself, then **dispatches each step as the matching specialist agent through the Task tool**; the iterative `hephaestus` ↔ `athena` review-and-fix loop is **owned by the skill the dispatched specialist drives** (its state lives there), not modelled as agents calling agents:

```text
user → daedalus                                         (top-level; resolves source, then dispatches via Task tool)
         │  resolve source (oldest-open-issue selection / resolve-issue source-detection); classify, never execute here
         │  gather context → shared brief .claude/run/<slug>.md   (written before any branch below runs)
         │  backlog request (triage / prioritise), or the source classified too broad for one PR? ── yes ─→ run the backlog tier inline, no PR:
         │       github-issue-triage → Triage done (ordered queue)  |  create-issues-from-text → Breakdown done (created issues, re-run per piece)
         │     │ no
         │  security-focused? ── yes ─→ Task ▶ athena (security analysis mode = security skills + analyze-problem → remediation plan; Security analysis done) → feeds hephaestus
         │     │ no
         ▼     ▼
       Task ▶ hephaestus   (= resolve-issue)
         │        └─ pre-PR self-check: code-review + security-review (single pass, not the authoritative review) → 0 Critical/Moderate → opens PR
         ▼
       Task ▶ hephaestus    (fast scoped validation mode — high-risk changes only; diff-targeted tests + acceptance-criteria check; full build only for broad changes)
         │        └─ Tests done (scoped) → proceed | Blocked → escalate to hephaestus
         ▼
       Task ▶ athena  (= process-code-review / code-review-github — the single CR pass: quality / architecture / optimisation + laravel-security + security-bounty-hunter + security-threat-analysis — the hephaestus ↔ athena loop)
         │        └─ athena: convergence loop (code-review-github + fixes, maxIterations 3) → one published review → 0 Critical/Moderate
         │           (athena dispatch guarded by registration check — fallback: review inline in code-review-github)
         ▼
       Task ▶ hephaestus    (fast scoped validation mode — final gate after convergence)
         │        └─ Tests done (scoped) → proceed | Blocked → escalate to user
         ▼
       Task ▶ hermes   (post-convergence reporting — publishes human-readable "co se změnilo + jak otestovat" to source tracker via pr-summary, built from the brief + hephaestus's scoped handoff; fallback: inline summary in handoff when no tracker)
         │        └─ Reporting done (tracker comment link) | Reporting done (no tracker) (inline)
         ▼
       daedalus → reports result to the user   (merge stays a separate, explicit step — always via @skills/merge-github-pr/SKILL.md)
```

The scoped validation dispatch runs at **push-level granularity** — after `hephaestus` opens the PR (high-risk changes only) and once after the `athena` CR converges (every run). Running it inside the `athena` loop would require `athena` to dispatch a subagent, which violates the one-level nesting rule (the nesting level is already spent on dispatching `athena` from `daedalus`). `daedalus` is therefore the correct dispatcher for both scoped passes.

The convergence gate is **0 Critical + 0 Moderate**; on `maxIterations` or a blocker the run stops and escalates instead of reporting success.

## Savings mode (opt-in, token-efficient orchestration)

A full run of the pipeline above costs roughly the same subagent-token budget whether the diff is a one-line UI tweak or a multi-file feature, because most of the cost is orchestration overhead, not work proportional to the change. **Savings mode** (`@rules/compound-engineering/orchestration.md` *Savings mode*) is an opt-in variant of the exact same pipeline — same agents, same order, same convergence gate — that removes five concrete sources of that overhead. It is off by default and engages only on an explicit user request; this section explains **why** each mechanism actually reduces tokens, the canonical rule states **what** each agent must do.

| Waste source | Mechanism | Why it saves tokens without reducing review depth |
|---|---|---|
| The orchestrator narrates a plan instead of executing it | *Orchestrator turns must end in a result or a hard blocker* (unconditional, not gated by savings mode) | A turn that only restates "next I will dispatch X" burns a full context window (re-reading the plan, the brief, prior handoffs) and returns nothing executed. Forcing every turn to end in a completed dispatch or a real blocker removes that wasted turn entirely — it is a correctness fix, not a quality trade-off, so it applies whether or not savings mode is on. |
| The orchestrator hands the reviewer a tracker link and it re-derives the diff / acceptance criteria / invariants itself | Shared context pack | Deriving the diff, the assignment, and the acceptance criteria from the tracker is pure input-token cost that the gather phase has already paid once — handing `athena` the finished pack removes the second derivation. (This mechanism used to also split *overlapping* invariants between two parallel reviewers so one defect was not reported twice; collapsing the review into a single pass removed that duplication at the source, so there is nothing left to split.) |
| The full build gate reruns on a nearly-identical tree 3–4 times per PR | Build-gate cache keyed by the tree content hash | A full `composer build` (install, fixers, static analysis, full-suite coverage) is the most expensive single step in the pipeline and is usually re-run on a tree that differs from the previous run by a comment or a docblock. Reusing a cached passing result for an *exactly identical* tree removes the repeated execution cost without ever certifying a tree that was not actually built — the cache dedupes only intermediate runs, and the final run on the literal head SHA before merge is never skippable (see `@skills/merge-github-pr/SKILL.md` *GitHub Actions billing exception*). |
| A read-only CR reviewer in an isolated worktree cannot run tests, so its coverage verdict is a static read, and the `hephaestus` scoped pass re-derives the real number anyway | Single coverage-verdict owner | Paying for a static-read coverage guess that a later step re-derives by actually running the suite is pure duplicate cost with no accuracy benefit — the static guess is strictly worse evidence than the real run that already happens. Naming the `hephaestus` scoped validation pass (or CI) the sole owner of the *executed* verdict removes the guess, not the check: the real, authoritative measurement still runs exactly where it already did. |
| The orchestrator adds its own context window just to pass an already-known linear plan along | Thin orchestration reasoning | Once a run has no remaining branching decision, restating the full plan and its justification at every step transition is pure narration cost — the specialist being dispatched reads the same plan from the brief anyway. Reading the handoff, checking the one pre-named gate, and dispatching removes the restatement, not the dispatch, the specialist, or the gate; a genuine branching decision still gets full reasoning whenever one actually arises. |

None of the five mechanisms removes a reviewer, a skill, or a gate — every one of them removes **duplicate re-derivation or duplicate execution** of work already done once. A run with savings mode on and the identical run with it off converge on the same diff with the same Critical / Moderate finding count, the same reviewer, the same convergence gate, and the same final build gate before merge; only the token cost of reaching that point differs. The pipeline diagram above is unaffected by savings mode — the dispatch sequence, the agents, and the convergence gate are identical either way.

**The build-gate cache has one consumer.** Since the quality gate was deferred to the merge boundary (`@skills/resolve-issue/references/quality-gates.md` *Gate placement — deferred to the merge boundary*), the full build runs once per branch rather than once per phase, so the opt-in tree-hash cache in the table above is read only by `@skills/merge-github-pr/SKILL.md` *Pre-merge quality gate*, under that skill's own provenance rules, and `security-audit` always runs fresh regardless of any entry. The always-on head-SHA push-level dedup that used to sit beside it (issue #212) is **retired** with the repeated builds it deduplicated — the branch now has a single gate execution, so there is no second run on the same commit to skip.

## Troubleshooting — subagent file writes blocked

**Symptom:** a write-capable agent (`hephaestus`) reports it cannot write files — *"sandbox blocking file writes"* — and the run stops with a `Blocked: sandbox denied file write` handoff (or the main thread is tempted to finish the implementation itself).

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

**The trade-off you are accepting — session-wide, project-scoped, never per agent.** A `permissions.deny` rule is a property of the *session*, not of an agent. Inside that project it restricts **every agent and your own interactive Bash identically** — `curl` in your own Claude Code prompt is refused just as `hephaestus`'s would be. Your ordinary terminal outside Claude Code is unaffected, and no other project on the machine is touched (which is exactly why the flag writes the project-local file rather than `~/.claude/settings.json`, where a deny could never be relaxed again).

**What it does not buy.** It is not an egress control and does not make the Bash boundary per-agent — the rule matches command strings, not process trees, so `gh`, `git`, `composer`, `php -r`, `node -e`, `bash -c 'curl …'`, `/usr/bin/curl`, `/dev/tcp`, and unlisted tools such as `socat` all stay open, and the package's own Bugsnag / attachment scripts keep working for the same reason. The full bypass list and the manual undo procedure live in `SECURITY.md` *`--deny-network-bash`*; read it before turning the flag on so the expectation matches what the harness actually enforces.

## Distribution

The installer always copies `agents/` to `.claude/agents/` — Claude Code is the only editor this package targets.

## Adding a new agent

1. Pick a Greek figure whose myth matches the job; use the lowercase name.
2. Create `agents/<name>.md` with the frontmatter + an orchestration-only system prompt that delegates to skills and returns a handoff.
3. Add it to the README *Claude Code Subagents* roster (an avatar + role card).
4. Add a test asserting the file ships with its required frontmatter (mirror the `athena` test in `tests/Installer/AgentsTest.php`).
5. Run `composer build` — the installer file-count tests pick up the new agent automatically.
