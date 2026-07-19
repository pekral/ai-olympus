# Agents

Agents are **Claude Code subagents** that act as a thin orchestration layer over the existing skills. They run in their own context window, delegate the real work to skills, and hand a clean result back to the caller.

```text
Rules  = long-lived project standards
Skills = reusable workflows
Agents = specialised orchestration roles over multiple skills
```

Each agent is a **standalone specialist**: you (or the orchestrating harness) dispatch the one that matches the step at hand. There is no dedicated orchestrator agent — the harness itself sequences the specialists and the skills, so the end-to-end run is driven by the harness (and the `scripts/resolve-and-review.sh` pipeline template), not by an agent that dispatches other agents. See *End-to-end run* below.

## Agent roster

Every agent has its own avatar under [`assets/agents/`](../assets/agents). When no custom artwork has been supplied yet, the slot falls back to the universal placeholder ([`placeholder.svg`](../assets/agents/placeholder.svg)) — swap `assets/agents/<name>.svg` to give an agent its own face.

### <img src="../assets/agents/argos.png" alt="argos avatar" width="48" align="left"> `argos` — code-review gatekeeper

The all-seeing code-review gatekeeper, named after **Argos Panoptes**, the hundred-eyed watcher nothing escaped. Give it a PR — from the current context or a tracker link (GitHub, JIRA, Bugsnag) — and it loads the source, runs the matching `code-review-*` wrapper skill, posts the findings to the PR, and hands back a `CR done` summary with links and Critical / Moderate / Minor counts. Argos focuses on **code quality, architecture, and optimisation**; security is owned by `athena`, which runs as a separate security pass.

- **Trigger:** a pull request needs reviewing (code quality, architecture, optimisation).
- **Orchestrates:** `code-review-github`, `code-review-jira`, `code-review-bugsnag`.
- **Safety:** read-only — never edits, commits, pushes, or merges.

### <img src="../assets/agents/athena.png" alt="athena avatar" width="48" align="left"> `athena` — security analyst & CR sentinel

The strategic security sentinel, named after **Athena**, goddess of wisdom and strategic defence. It owns the security domain in **two modes**. (1) **Security analysis (pre-implementation)** — run on demand when the task carries a cyber-security question: it scopes the security risk through all security skills, frames the smallest safe remediation via `analyze-problem`, publishes a plan artifact, and hands back a `Security analysis done` summary that `talos` implements. (2) **Security review (post-implementation)** — given a PR from the current context or a tracker link, it orchestrates all security skills, applies all security rules, labels each finding (Critical / Moderate / Minor), publishes the security review to the tracker-matching CR channel, and hands back a `Security CR done` summary. The caller passes its findings to the agents that need them.

- **Trigger:** a task carries a cyber-security question and needs a pre-implementation security-risk analysis, or a pull request needs a dedicated security review.
- **Orchestrates:** `security-review`, `laravel-security`, `security-bounty-hunter`, `security-threat-analysis` (plus `analyze-problem` in analysis mode).
- **Rules applied:** `@rules/security/backend.md`, `@rules/security/frontend.md`, `@rules/security/mobile.md`.
- **Safety:** read-only — never edits, commits, pushes, or merges (`talos` implements what it analyses).
- **Registration dependency:** dispatchable only after the installer copies `agents/athena.md` to `.claude/agents/`. Until then, security runs inline in `code-review-github → security-review` (the continuity fallback).

### <img src="../assets/agents/talos.png" alt="talos avatar" width="48" align="left"> `talos` — code-writing implementer

The tireless bronze automaton, named after **Talos**, the forged guardian that worked without rest. Give it a source — a tracker link (GitHub, JIRA, Bugsnag) or the current task — and it implements the fix or feature, runs local checks (`composer build`: tests, phpstan, pint, rector, phpcs, skill-check) and fixes their errors, opens a pull request, and hands back an `Impl done` summary with links. Code review (quality, architecture, optimisation) belongs to `argos`; security CR belongs to `athena`; test authoring and validation belong to `apollon` — `talos` does not own any of these. It is the write-side counterpart to `argos`: `argos` is the tireless eye (review), `talos` the tireless hands (implementation).

- **Trigger:** an issue or task needs implementing.
- **Orchestrates:** `resolve-issue`.
- **Safety:** stops at the PR — never reviews its own work and never merges. If a caller explicitly instructs a merge, the only permitted path is `@skills/merge-github-pr/SKILL.md` — never `gh pr merge` or bare CLI.

### <img src="../assets/agents/apollon.png" alt="apollon avatar" width="48" align="left"> `apollon` — test engineer

The test engineer who reveals the truth about a change, named after **Apollo**, the god of truth, prophecy, and order, and the unerring archer who never misses the mark. Give it a change — an issue, a PR, or the current task — and it authors the test coverage and validates the behaviour: it designs the test scenarios (edge cases, regression) from the assignment, writes the PHPUnit / Pest tests, generates the browser test scenarios, and verifies every acceptance criterion — understanding **both the code and the product assignment**. It hands back a `Tests done` summary with the authored tests and the acceptance-criteria coverage.

- **Trigger:** a change needs test coverage authored and its behaviour validated — design tests, write PHPUnit/Pest tests, generate browser scenarios, verify acceptance criteria.
- **Orchestrates:** `create-test` / `create-missing-tests-in-pr` (PHPUnit/Pest authoring), `e2e-testing` (browser scenarios when Playwright is present).
- **Safety:** write-capable for **test code only** — never touches application code, never merges, never pushes to a protected default branch.
- **Model:** `sonnet` at the lowest effort — its work (authoring and running scoped tests) does not need maximum reasoning depth, so it runs fast and cheap.

### <img src="../assets/agents/hermes.png" alt="hermes avatar" width="48" align="left"> `hermes` — release announcer / publicista

The messenger who carries the message after the work is done, named after **Hermés (posel bohů / messenger of the gods)**, the swift divine messenger whose sole role was to deliver the official announcement. Give it a merged change, a release, or a shipped feature — from the current context or a tracker link — and it loads the source read-only, composes the announcement content (Twitter/X tweet ≤280 chars + thread, release notes, marketing summary with **pekral.cz** promotion), and hands back an `Announce done` summary with all drafts inline. It runs **post-delivery**, outside the CR loop — after the change has merged or after a release tag is cut.

- **Trigger:** a merged change or release needs announcement content — tweet, thread, release notes, or marketing summary.
- **Orchestrates:** `resolve-issue/references/source-detection` (source loading, read-only).
- **Safety:** read-only — never edits, commits, pushes, or merges. Publishes only when explicitly asked and only through the canonical `upsert-comment.sh` wrapper — never raw `gh ... comment`.
- **Registration dependency:** dispatchable only after the installer copies `agents/hermes.md` to `.claude/agents/`.

## Naming convention — Greek mythology

Every agent is named after a figure from **Greek mythology**, chosen so the figure's role matches the agent's function. Use the lowercase name as the agent `name:` and file id (`agents/<name>.md`).

| Agent | Greek figure | Why it fits |
|---|---|---|
| `argos` | Argos Panoptes, the hundred-eyed all-seeing watcher | nothing escapes his gaze → thorough PR inspection (quality, architecture, optimisation) |
| `talos` | Talos, the bronze automaton forged to work and guard without rest | tireless artificial labourer → forges working code |
| `apollon` | Apollo, god of truth, prophecy, and order, and the unerring archer | reveals the truth about a change and hits the acceptance mark → test authoring & validation |
| `athena` | Athena, goddess of wisdom and strategic defence | wisdom + strategic vigilance → security analyst (pre-implementation, on demand) and dedicated security CR sentinel |
| `hermes` | Hermés (posel bohů / messenger of the gods) | swift divine messenger, carries the message after the work is done → release announcer & publicista |

Naming ideas for future agents: `themis` (order / verdict), `rhadamanthys` (fair judge), `iris` (delivery / merge).

## Anatomy of an agent

An agent is a Markdown file with frontmatter + a system prompt:

```markdown
---
name: argos
description: When to auto-delegate to this agent (the trigger sentence).
tools: Read, Glob, Grep, Bash
model: opus
effort: max
---

System prompt: what the agent does, which skills it orchestrates, and the handoff it returns.
```

- **`name`** — lowercase, the id used as `subagent_type` / `@name`.
- **`description`** — drives auto-delegation; phrase it as the situation that should trigger the agent.
- **`tools`** — restrict to what the agent needs. A read-only reviewer needs `Read, Glob, Grep, Bash` only.
- **`effort`** — reasoning effort while the agent is active (`low` / `medium` / `high` / `xhigh` / `max`); set to `max` on every agent so each runs at maximum reasoning depth. The one exception is `apollon`, which runs at `low` — its scoped test authoring and validation does not need deep reasoning, so it stays fast and cheap. The runtime clamps to the highest level the agent's `model` supports.
- **System prompt** — orchestration only. Delegate to skills via `@skills/<name>/SKILL.md`; **never duplicate a skill's rules** — defer to the skill as the source of truth.

## Handoff contract

An agent's final message is returned to the caller as the tool result, so it must be a self-contained handoff the next step can act on without re-deriving context:

- **Status** — e.g. `CR done`.
- **Links** — the PR and the originating source (GitHub / JIRA / Bugsnag).
- **Result summary** — the numbers the caller needs (e.g. Critical / Moderate / Minor counts, a verdict).

**Language of the handoff / report.** Every agent writes the human-facing prose of its handoff and any end-user report in the **same natural language the assignment was given in** (if the request came in Czech, the handoff is in Czech). Identifiers stay verbatim regardless of that language — branch names, ticket / issue keys, links, severity labels, CLI commands, and skill / agent names are never translated, and two natural languages are never mixed inside a single handoff.

## Subagents of an agent

Claude Code subagents invoked via the Task tool generally **cannot spawn their own subagents** (one level of nesting). This shapes how the roster composes:

1. **The top-level harness dispatches specialists through the Task tool.** The harness (the session you talk to) spends its single nesting level dispatching `talos` / `argos` / `athena` / `apollon` / `hermes` directly. Each specialist then orchestrates its own skills inline — `talos` runs `resolve-issue`, `argos` runs `code-review-github`, and so on.
2. **Lens skills called inline** by an orchestrating skill — e.g. `code-review-github` already runs `code-review`, `security-review`, `api-review`, `assignment-compliance-check` inline. This is what each dispatched specialist does in its own context, and it is also the fallback when no further nesting level is available.
3. **Parallel fan-out via the Workflow tool** — a DAG of agents for heavy runs that genuinely need concurrency.

Because of the one-level limit, no agent tries to become a nested orchestrator that spawns other agents from inside another agent. The harness is the single dispatch level; a specialist that needs another specialist's output returns a handoff for the harness to act on, rather than dispatching it itself.

### End-to-end run (harness-driven, skill-owned steps)

A full request is carried to a clean, reviewed result by the **harness**, which sequences the standalone specialists and the skills they own. The canonical order is captured as a runnable checklist in [`scripts/resolve-and-review.sh`](../scripts/resolve-and-review.sh) — a template / wrapper that validates the input, detects the tracker, and prints the exact skills to run in order (it does not invoke Claude itself):

```text
1. /resolve-issue <issue-ref|text>     → talos (= resolve-issue): implement + open the PR (always)
2. /code-review-<tracker> <PR|ref>      → argos (+ athena security pass): fresh CR round on the PR
                                          (code-review-github <PR> | code-review-jira <KEY> |
                                           code-review-bugsnag <error> — picked from the source's
                                           tracker; the JIRA/Bugsnag wrappers resolve the linked PR)
3. /process-code-review <PR>            → resolve the findings when the CR round reported any
4. /merge-github-pr <PR>                → merge into the base branch, only when a merge was requested
```

The convergence gate is **0 Critical + 0 Moderate**: step 3 iterates the review-and-fix loop (owned by `process-code-review`, `maxIterations = 3`) until it converges, and the merge in step 4 is gated on it. Test authoring / validation (`apollon`) and release announcements (`hermes`) are dispatched on demand around this spine when a change needs them.

## Troubleshooting — subagent file writes blocked

**Symptom:** a write-capable agent (`talos`) reports it cannot write files — *"sandbox blocking file writes"* — and the run stops with a `Blocked: sandbox denied file write` handoff (or the main thread is tempted to finish the implementation itself).

**Cause:** the agent declares `Write` / `Edit` in its frontmatter, but those tools are *capabilities*, not grants. A dispatched subagent runs **non-interactively** — when its `Edit` / `Write` is not already pre-allowed for the path it targets, it cannot fall back to an interactive approval the way the main thread can, so the write is denied at runtime. This is an environment setting, not something the agent definition or this package can grant.

**Correct behaviour (already enforced):** the blocked agent returns `Blocked: sandbox denied file write` and the harness escalates it to the user — the work is **never** silently completed outside the delegated, reviewed pipeline (`@rules/compound-engineering/general.mdc` *Blocked delegation is a hard stop*).

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

## Distribution

The installer always copies `agents/` to `.claude/agents/` — Claude Code is the only editor this package targets.

## Adding a new agent

1. Pick a Greek figure whose myth matches the job; use the lowercase name.
2. Create `agents/<name>.md` with the frontmatter + an orchestration-only system prompt that delegates to skills and returns a handoff.
3. Add it to the README *Claude Code Subagents* roster (an avatar + role card).
4. Add a test asserting the file ships with its required frontmatter (mirror the `argos` test in `tests/Installer/AgentsTest.php`).
5. Run `composer build` — the installer file-count tests pick up the new agent automatically.
