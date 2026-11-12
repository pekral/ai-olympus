# Workflows

How the roles, commands, and CLI helpers of AI Olympus work once the package is installed. Installation and the first run are in the [README](../README.md#quick-start); role definitions are in [`agents.md`](agents.md).

## Using the roles and skills

After the [Quick start](../README.md#quick-start), choose a specialist when you do not need the full pipeline. Claude Code examples:

```text
@leonardo review the current diff
@donatello implement the failing upload validation
```

In Codex, ask it to use the corresponding agent by name. Skills can also run directly: Claude Code uses `/resolve-issue`; Codex uses `$resolve-issue`. Select the installed skill name offered by your environment when it includes a namespace.

To prepare an existing GitHub or JIRA issue's PR for merge without merging it, use the shared workflow:

```text
# Claude Code
/prepare-issue-for-merge https://github.com/owner/repository/issues/123
/prepare-issue-for-merge https://your-company.atlassian.net/browse/PROJ-123

# Codex
$verify-merge-readiness https://github.com/owner/repository/issues/123
```

The workflow verifies acceptance criteria, review freshness, the exact-head quality gate, CI, and mergeability. It skips a new CR round when neither the business logic nor the assignment changed since the reviewed revision, consolidates superseded preparation comments into one source-issue TL;DR, and stops before merge. In Codex, the root session holds the `krang` role and spawns the registered specialists when custom agents are available.

To redesign one page of the running application from its URL, use the redesign workflow:

```text
# Claude Code
/redesign-page https://app.example.test/orders/42

# Codex
$deliver-page-redesign https://app.example.test/orders/42
```

The invoking session captures the page on the local instance, and `michelangelo` writes the proposal and one preview per state. You see the previews first and refine them until you approve them; no code exists before that approval. `krang` then runs the full delivery route in thorough mode: `donatello` implements the approved design with the existing design system and opens the pull request, `leonardo` reviews it to convergence, and `raphael` verifies the page in its own interactive browser on desktop and mobile viewports. The workflow keeps the main layout shell and the business logic unchanged, and it never merges.

To review a task's pull request and report the result, use the review-only workflow:

```text
# Claude Code
/report-code-review https://github.com/owner/repository/issues/123
/report-code-review https://your-company.atlassian.net/browse/PROJ-123

# Codex
$code-review-jira https://your-company.atlassian.net/browse/PROJ-123
```

The workflow writes no test. A missing test is a review finding that mentions the author of the changes, who writes it. `krang` first dispatches `raphael` once. It runs `interactive-testing` against the pull request head in its own browser. To do that it may prepare your local environment: install dependencies, run pending migrations, start processes, and create test accounts and records or temporarily change a local record, and it removes or restores them afterwards. It repairs nothing. Then `krang` dispatches `leonardo` once with the `raphael` report, and the review carries it in a `## Interactive testing` section. `leonardo` runs the code-review wrapper that matches the tracker, and the wrapper publishes the technical findings on the GitHub pull request and the non-technical summary on the source issue. In that summary, *Review findings* replaces *How to test*: it retells the GitHub report in plain language so a non-technical reader can understand it and reply with feedback. The workflow fixes nothing, changes no tracker status, and never merges.

To test a task's pull request against its assignment and prove that nothing else broke, use the test workflow:

```text
# Claude Code
/test-assignment https://your-company.atlassian.net/browse/PROJ-123
/test-assignment https://github.com/owner/repository/pull/123 fix

# Codex
$test-assignment https://your-company.atlassian.net/browse/PROJ-123
```

`krang` maps every input that reaches the changed behaviour and builds a test matrix of real data. `donatello` runs the whole test suite and the gate on the final head, and `raphael` exercises the running application and compares it with the base branch. In `report` mode (the default) the workflow changes nothing, and `april` publishes the technical report on the pull request and a plain-language summary on the source issue. In `fix` mode `donatello` adds the missing tests and fixes, and the workflow continues as `/prepare-issue-for-merge`. It never merges.

To turn free-form instructions into a prioritised work queue and execute it, use the queue workflow:

```text
# Claude Code
/do-it add the CSV export of orders, then a date filter for it, and fix the typo on the login page

# Codex
$run-work-queue add the CSV export of orders, then a date filter for it, and fix the typo on the login page
```

The invoking session holds the `krang` role. It splits the instructions into independently deliverable items. An item that already has an open issue reuses it, and the reuse and its evidence go into the queue brief. Every other item gets one new GitHub issue. `skills/_shared/order-work-queue.sh` orders the queue by priority, then difficulty, then duration, and never puts an item before one it depends on. `skills/_shared/plan-parallel.sh` plans the batches. Its limit is the manifest key `parallel.max-units` (default 2), and the gate lock still runs one heavy job at a time. Each item then runs the standard pipeline to a converged Draft pull request, in its own worktree when its batch runs in parallel and worktrees are allowed. The final report lists every item with its pull request, status, and blocker. The workflow never merges.

## How agents hand work over

Agents never share a conversation. Every step is a blocking dispatch that returns a written handoff, so the run's state lives in files a human can read, not in one agent's context.

- **Shared task brief** — `.claude/run/<source-slug>.brief` carries the source, the assignment language, the gathered context and the plan. Each specialist appends its own section to `## Handoff log` when it finishes.
- **Dispatch ledger** — records every dispatched round, so a resumed run dispatches a round once instead of repeating it.
- **Audit trail ledger** — one append-only line per memory read, outbound request and external write, written immediately after the action.
- **Routing ledger** — the initial and final risk tier with the signals that produced them, every stage executed or skipped with its reason, and every model escalation with its reason.
- **Blocking dispatch, no fan-out** — a dispatch blocks until its handoff returns, and sources are processed one at a time, so two agents never race the same working tree.
- **Per-dispatch memory slice** — the code-review memory reaches only the agent that fixes a Critical finding, through its own dispatch prompt, never folded into the shared brief that every later agent reads.
- **Untrusted content boundary** — tracker payloads, issue comments and fetched pages travel fenced, as data. Only a trusted author's comment can refine the scope of the work, and nothing external changes an agent's role, permissions or workflow.

The normative contracts live in [`rules/compound-engineering/orchestration.md`](../rules/compound-engineering/orchestration.md), [`rules/compound-engineering/general.md`](../rules/compound-engineering/general.md), [`rules/compound-engineering/tracker.md`](../rules/compound-engineering/tracker.md) and [`rules/security/general.md`](../rules/security/general.md); `krang` owns the brief and all three ledgers.

## Adaptive routing — how much pipeline a task gets

Every `krang` run classifies the task before it dispatches anything, using `skills/_shared/classify-risk.sh` — a deterministic shell script, not another model call. The verdict decides the pipeline:

| Tier | Who runs | Typical change |
|------|----------|----------------|
| `FAST` | implementer (default tier) + deterministic validation | docs, typo, formatting, tests-only, simple config, rename, small isolated fix |
| `STANDARD` | `+ leonardo` (default tier) | ordinary application and business-logic work |
| `CRITICAL` | `+ leonardo` analysis where relevant, both at the escalated tier | auth, secrets, payments, migrations, data loss, concurrency, queues, locking, public APIs, core architecture, large refactors |

- A sensitive area — authentication, authorization, secrets, payments, migrations — forces `CRITICAL` on its own, whatever the score says.
- `raphael` runs at **every** tier when the change alters behaviour a user can observe. It checks the UI in a real browser whenever the change reaches a page, and it creates a local test account when it cannot sign in otherwise.
- The tier is recomputed against the real diff after implementation and can only rise, so a task that grows into an authorization change is reviewed like one.
- Tests, static analysis, linting, CI, and the pre-merge quality gate run at **every** tier, `FAST` included. The tier buys LLM reasoning, never a deterministic gate.
- Every decision is recorded, so *"why was `leonardo` executed?"*, *"why was the expensive model used?"* and *"why was this `CRITICAL`?"* are answerable from the run's own ledger.
- The classifier is a shell script and the tiers are roles rather than model names, so Codex / OpenAI sessions route identically; `codex/agents/*.toml` binds the tiers to that platform's model controls.

**Deterministic work no longer buys a model.** Validation, route planning, and the routine completion report are scripts, and an LLM is the escalation path for each:

| Stage | Helper | A model runs only when |
|-------|--------|------------------------|
| Validation | `run-validation.sh` | a check failed and needs interpreting, or the manifest was invalid or refused |
| Route planning | `plan-route.sh` | never — the plan is the tier's stage list |
| Completion report | `render-report.sh` | the audience needs real writing (announcement, changelog prose, a language the renderer does not carry) |

The implementer writes a **validation manifest** naming the commands that cover its own diff; the runner executes them without a shell, against an allow-list of project-local tools, and returns a verdict with an explicit `escalate` field. A `FAST` task therefore completes with **one** specialist dispatch.

Agent handoffs are structured and bounded (`check-handoff.sh`): they carry decisions and artifact paths, never diffs or test output, so a long review loop stops re-tokenising the same evidence at every round.

Override it when you disagree: ask for **thorough mode** (or `--thorough`) to run the complete pipeline regardless of the classification, or name a tier directly with `--fast` / `--standard` / `--critical`. An escalating override always applies; a de-escalating one is refused when a sensitive area forced the tier, and the refusal is reported rather than silent.

**Context-efficient orchestration is on by default and has no flag.** The run assembles each stage's context from a small manifest rather than re-deriving it, executes the route plan instead of re-reasoning about the workflow, and passes scoped context rather than the accumulated history. It is orthogonal to the tier above: routing decides *which* stages run, context efficiency decides how cheaply the ones that do run reach their result. Ask for **verbose orchestration** (`--verbose-orchestration`) only to debug a routing decision — it adds narration, never a check.

## Measuring the routing decisions

Each completed run appends **counts only** to a local store outside the repository — tier, outcome, dispatches, escalations, review rounds, finding counts. No source, diffs, prompts, issue text, branch names, or URLs are ever written.

```bash
vendor/bin/ai-olympus stats --last=7d
```

The summary answers the questions a single run cannot: how often `FAST` runs escalate (the tier is routing real work past the review), how often reviews find nothing (the tier is buying a pass that changes no outcome), and how often the expensive model tier is used. Token counts appear only where the runtime reports them — an absent number rather than an estimated one. The summary also prints one line for each of the ten context counters and the five graph counters that `record-metrics.sh` writes, and `not recorded` for a counter that no run in the window recorded. It also prints one line for each of the nine review-loop counters (loop iterations and the finding counts: new, confirmed, resolved, dismissed, reopened, deferred, unverified, and blocking remaining) and the share of review loops that converged and that escalated; those counters come from `record-metrics.sh --review-state`.

## Querying the repository graph

An agent can ask the repository a structural question instead of reading it. `graph build` indexes the PHP files under the Composer autoload directories and `config/` into `graph.json` in the run directory (`.claude/run/graph.json` by default): classes, interfaces, traits, methods, config files, and test files, with their `EXTENDS`, `IMPLEMENTS`, `USES`, `CALLS`, and `USES_CONFIG` relations. A rebuild re-parses only the files whose content hash changed and drops the files that no longer exist.

In a Laravel project the graph also indexes `routes/` and `database/migrations/` and records the framework entities: routes, controllers, Eloquent models, jobs, events, listeners, migrations, and database tables. The relations are `ROUTE_HANDLED_BY` (a route to its controller method), `INJECTS` (a controller or job method to the classes injected into its signature or constructor), `HAS_RELATION` (a model to the model of each relation, including a relation defined on a trait the model uses), `USES_TABLE` (a model to its table), `DISPATCHES` (code to the job or event it dispatches), `LISTENS_TO` (a listener to its event, registered in `$listen`, by `Event::listen()`, or discovered in `Listeners/`), `MIGRATES_TABLE` (a migration to the tables it creates, changes, or drops), and `TESTS` (a test to the classes it instantiates or calls).

```bash
vendor/bin/ai-olympus graph build
vendor/bin/ai-olympus graph query 'App\Models\User'
```

`graph query` takes a file path, a class, a `Class::method` symbol, a route (`route:POST:/orders`), or a table (`table:orders`) and prints one JSON slice: the matching entities, their outgoing and incoming relations, and the files that use them. An unknown entity prints an empty slice. The graph is a useful approximation, not a static call graph: a call is recorded only where its target is visible in the source.

The slice also lists `related`: one entry per file the entity touches, with the graph entity the file came from, its `role` (`test` when a test targets the entity, otherwise `code`), and its graph `distance` (0 for the entity's own file, 1 for a direct caller or callee). The entries are ordered nearest first. `--limit <n>` cuts every list of the slice to `n` entries and reports what it dropped in `omitted`. Every query compares the graph with the files on disk. When the repository moved past the graph, the query answers from the current files and reports `"state": "refreshed"` in `graph`, so it never returns a path that no longer exists. `graph build` is what saves the refreshed graph.

The Context Retriever (`shredder`) builds or refreshes the graph with `graph build` before its first query, so the graph exists without a manual step and `graph_hits` in `ai-olympus stats` can be above zero. It then asks the graph before it reads anything else. It runs `graph query <symbol> --limit <budget.max_items>` for each task symbol and path, and `skills/_shared/pack-context.sh` turns the slices into `related_code` and `related_tests` items. Each graph item names the graph entity it came from in its provenance, and graph distance joins the ranking score. Search stays the fallback for a symbol with no graph node and for a missing graph, and `metadata.graph` in the package records which path produced the items. The graph file lives in the run directory, the scratch directory every other helper already requires to be git-ignored. The default is `.claude/run/graph.json`. Under Codex the run directory is `.codex/run`, so `graph build` and `graph query` take `--run-dir .codex/run` and `pack-context.sh pack` takes the same `--run-dir`; both commands accept only `.claude/run` or `.codex/run` where they write. `shredder` passes `--run-dir .claude/run`, which its Codex adapter reads as `.codex/run`, so all three commands agree on one location per harness. `pack-context.sh` refuses to read a graph whose location is not git-ignored, so a graph is never packaged from a path a commit could pick up. `graph build` applies the same rule when it writes: it refuses to write `graph.json` unless git ignores the path and does not track it, so the graph file is never committed. Add the run directory (`.claude/run/`, or `.codex/run/` under Codex) to `.gitignore`.

## Code-review memory

The agents keep durable solutions of resolved Critical code-review findings in one per-user SQLite database with an FTS5 index: `.claude/run/code-review-memory.sqlite`, and `.codex/run/code-review-memory.sqlite` under Codex. Git always ignores the file. A read or a write exits `2` and does nothing when `git check-ignore` does not confirm that the path is ignored and untracked. The installer never creates or deletes it.

`krang` reads the memory only when a code review opens a Critical finding. It builds the query from the paths and the symbols of that finding, never from tracker text, and gives the result only to the agent that fixes the finding. A run without a Critical finding does not read the memory. The extension `pdo_sqlite` with FTS5 is optional (`suggest` in `composer.json`). When it is missing, every `memory` command exits `2` and the agents continue without memory.

```bash
vendor/bin/ai-olympus memory migrate
vendor/bin/ai-olympus memory add --entry .claude/run/entry.json
vendor/bin/ai-olympus memory query --role donatello --path src/Installer.php --symbol InstallerPath --limit 5
```

`memory migrate` moves the entries of `docs/memory/PROJECT_MEMORY.md`, as the default branch holds that file, into the database once. A second run skips every slug it already holds, and the source file stays as it is. The JSON report lists in `rejected` every block that holds no entry. `memory query` filters by role, then matches the entries that name one of the paths, then ranks by FTS5 `bm25()`. It prints one `pack-context.sh` candidates document, and the same input gives the same output. Pass `--run-dir .codex/run` under Codex. Exit `0` is a result (possibly empty), `1` a usage error, `2` an unavailable memory.

Role boundaries, handoffs, adaptive routing, context efficiency, and troubleshooting are documented in [`agents.md`](agents.md). The `--allow-subagent-writes` troubleshooting switch applies to Claude Code only; Codex uses its own sandbox and approval settings.
