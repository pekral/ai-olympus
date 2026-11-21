# AGENTS.md

Behavioral guidelines to reduce common LLM coding mistakes. Merge with project-specific instructions as needed.

**Tradeoff:** These guidelines bias toward caution over speed. For trivial tasks, use judgment.

## Codex integration

- Before changing files, inspect `.codex/rules/` and read every rule whose `paths` frontmatter matches the files in scope. Rules without `paths` apply to every task.
- Reusable workflows live in `.agents/skills/`. Invoke a matching skill with `$skill-name`, and follow its `SKILL.md` in full. Before a user-facing workflow starts, load its command contract from `.codex/commands/`; commands own routing and skills own only bounded work.
- Project-scoped custom agents live in `.codex/agents/`. Use the specialist named by the workflow and preserve the role boundaries in its instructions.
- AI Olympus run state lives in `.codex/run/`. In imported role instructions, interpret `.claude/run/` as `.codex/run/`, `@skills/` as `.agents/skills/`, `@rules/` as `.codex/rules/`, `@commands/` as `.codex/commands/`, and `agents/<name>.md` as `.codex/agent-instructions/<name>.md`.
- A rule, skill, or command that names a shipped script by a **bare** path — `commands/_shared/classify-risk.sh`, `skills/code-review-github/scripts/load-issue.sh` — means the matching file below `.codex/commands/` or `.agents/skills/` by its first path segment. Run it with the shell; these are deterministic helpers, not skills to invoke.
- Deterministic work is not an LLM's job. Before dispatching an agent for validation, routing, context gating, or a routine completion report, run the helper that owns it: `run-validation.sh`, `plan-route.sh`, `gate-context.sh`, `render-report.sh`. Each one returns a machine-readable verdict and says when a model is actually needed; a green verdict needs no session.
- A **model tier** named in a rule (*default tier* / *escalated tier*) is a role, never a model. Which model each tier means for a given specialist is stated in that specialist's own definition for this platform — `.codex/agents/<name>.toml` — never here and never in the rule.

## 1. Think Before Coding

**Don't assume. Don't hide confusion. Surface tradeoffs.**

Before implementing:

- State your assumptions explicitly. Ask only when the issue, the codebase, the configuration, and the conversation so far cannot settle the point.
- If multiple interpretations exist, present them; don't pick silently.
- If a simpler approach exists, say so. Push back when warranted.
- If something stays unclear after that, name what is confusing and ask, with every question in one round.

## 2. Simplicity First

**Minimum code that solves the problem. Nothing speculative.**

- No features beyond what was asked.
- No abstractions for single-use code.
- No flexibility or configurability that wasn't requested.
- No error handling for impossible scenarios.
- If you write 200 lines and it could be 50, rewrite it.

Ask yourself: "Would a senior engineer say this is overcomplicated?" If yes, simplify.

## 3. Surgical Changes

**Touch only what you must. Clean up only your own mess.**

When editing existing code:

- Don't improve adjacent code, comments, or formatting.
- Don't refactor things that aren't broken.
- Match existing style, even if you'd do it differently.
- If you notice unrelated dead code, mention it; don't delete it.

When your changes create orphans:

- Remove imports, variables, and functions that your changes made unused.
- Don't remove pre-existing dead code unless asked.

Every changed line should trace directly to the user's request.

## 4. Goal-Driven Execution

**Define success criteria. Loop until verified.**

Transform tasks into verifiable goals:

- "Add validation" becomes "write tests for invalid inputs, then make them pass."
- "Fix the bug" becomes "write a test that reproduces it, then make it pass."
- "Refactor X" becomes "ensure tests pass before and after."

For multi-step tasks, name the check that verifies each step.

Strong success criteria let you loop independently. Weak criteria require constant clarification.

## Project conventions

- The quality gate runs once, before the merge; before every push only the pre-push gate runs. Do not run the project's gate, fixers, or checkers while implementing or while working through a code review. `$process-code-review` *Finalization* runs the full gate once, after the review converges; `$merge-github-pr` accepts that record only for the exact head commit being merged (or for a rebase that only moved the base, per `@skills/resolve-issue/references/quality-gates.md` *Rebase that moves the head — analyse the incoming changes first*), or runs the gate itself. `.agents/skills/resolve-issue/references/quality-gates.md` defines how the project's gate command is discovered.
- Before each push run the `pr-gate` fixers on every repository: read the key with `commands/_shared/read-manifest.sh`, run its commands through `commands/_shared/run-gate.sh --exclusive`, and commit what they change. Without `pr-gate` no fixer runs, and `krang` proposes the key through a pull request (`commands/_shared/orchestration/pr-gate-discovery.inc`). Then run the project's `fast-checks` on the changed files through `commands/_shared/run-fast-checks.sh` on every repository, and fix what they report before the push; without `fast-checks` no fast check runs. Other checkers and tests run before a push only where CI cannot check: without CI they run, unless green CI on the PR head skips them, except after a rebase that resolved a conflict (*Skip the pre-push gate when CI is green* in `quality-gates.md`). On a repository with CI no local pre-push gate runs, so no other checker and no test: push and wait for the CI result on the pushed head; only a CI billing failure runs the local checks, and a rebased branch is force-pushed back into its own pull request (*A repository with CI waits for CI instead of a local pre-push gate* in `quality-gates.md`). The full gate runs through `commands/_shared/run-gate.sh --tier full`, and every later step asks `commands/_shared/verify-gate.sh --tier full <sha>` before running it again; exit `5` from either means the built-in path applies (*Machine gate record* in `quality-gates.md`), and its gate commands, a dependency install, and a full test suite run through `commands/_shared/run-gate.sh --exclusive --command "<command>"`. That holds the one gate lock that every worktree of the repository shares, so one heavy job runs on the machine at a time.
- Git worktrees are supported, but create one only when the user explicitly requests it.
- The code-review memory is a per-user, git-ignored SQLite database that `krang` reads only when a code review opens a Critical finding. Do not read or write `docs/memory/PROJECT_MEMORY.md`; it is only the source of the one-time migration (`ai-olympus memory migrate`).
