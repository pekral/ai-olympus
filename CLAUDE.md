# CLAUDE.md

Behavioral guidelines to reduce common LLM coding mistakes. Merge with project-specific instructions as needed.

**Tradeoff:** These guidelines bias toward caution over speed. For trivial tasks, use judgment.

## 1. Think Before Coding

**Don't assume. Don't hide confusion. Surface tradeoffs.**

Before implementing:
- State your assumptions explicitly. If uncertain, ask.
- If multiple interpretations exist, present them - don't pick silently.
- If a simpler approach exists, say so. Push back when warranted.
- If something is unclear, stop. Name what's confusing. Ask.

## 2. Simplicity First

**Minimum code that solves the problem. Nothing speculative.**

- No features beyond what was asked.
- No abstractions for single-use code.
- No "flexibility" or "configurability" that wasn't requested.
- No error handling for impossible scenarios.
- If you write 200 lines and it could be 50, rewrite it.

Ask yourself: "Would a senior engineer say this is overcomplicated?" If yes, simplify.

## 3. Surgical Changes

**Touch only what you must. Clean up only your own mess.**

When editing existing code:
- Don't "improve" adjacent code, comments, or formatting.
- Don't refactor things that aren't broken.
- Match existing style, even if you'd do it differently.
- If you notice unrelated dead code, mention it - don't delete it.

When your changes create orphans:
- Remove imports/variables/functions that YOUR changes made unused.
- Don't remove pre-existing dead code unless asked.

The test: Every changed line should trace directly to the user's request.

## 4. Goal-Driven Execution

**Define success criteria. Loop until verified.**

Transform tasks into verifiable goals:
- "Add validation" → "Write tests for invalid inputs, then make them pass"
- "Fix the bug" → "Write a test that reproduces it, then make it pass"
- "Refactor X" → "Ensure tests pass before and after"

For multi-step tasks, state a brief plan:
```
1. [Step] → verify: [check]
2. [Step] → verify: [check]
3. [Step] → verify: [check]
```

Strong success criteria let you loop independently. Weak criteria ("make it work") require constant clarification.

---

**These guidelines are working if:** fewer unnecessary changes in diffs, fewer rewrites due to overcomplication, and clarifying questions come before implementation rather than after mistakes.
**The quality gate runs once, before the merge — not before every push, unless the project sets `pr-gate`.** Do not run the project's gate, fixers, or checkers while implementing or while working through a code review; author the change, commit it, push it. The one exception is a project whose manifest sets `pr-gate`: the implementer runs `skills/_shared/run-gate.sh --tier pr` before each push. Green CI on the PR head skips it, except after a rebase that resolved a conflict; missing or red CI runs it (`@skills/resolve-issue/references/quality-gates.md` *Skip the pre-push gate when CI is green*). On a repository that is public on GitHub no local pre-push gate runs: push and wait for the CI result on the pushed head (`@skills/resolve-issue/references/quality-gates.md` *A public repository waits for CI instead of a local pre-push gate*). A project that opts into the shared machine gate record (`pr-gate`, `gate-fresh`, or `gate-evidence`) records every gate run with `run-gate.sh`, keyed to the git tree, and the merge accepts it only through `skills/_shared/verify-gate.sh`; `@skills/resolve-issue/references/quality-gates.md` *Machine gate record — one run per tree* owns the details. `@skills/process-code-review/SKILL.md` *Finalization* runs the full gate once, after the review converges, and commits the fixes it produces as their own commit. `@skills/merge-github-pr/SKILL.md` *Pre-merge quality gate* accepts that record only for the exact head commit being merged (or for a rebase that only moved the base, per `@skills/resolve-issue/references/quality-gates.md` *Rebase that moves the head — analyse the incoming changes first*), or runs the gate itself, and never merges without it green — never ignore its errors. See `@skills/resolve-issue/references/quality-gates.md` *Gate placement — deferred to the merge boundary*; the same file defines how the project's gate command is discovered.

**One heavy job at a time on the machine.** Every gate run, every dependency install, and every full test suite holds one lock that all git worktrees of the repository share. A project that sets no gate runs those commands through `skills/_shared/run-gate.sh --exclusive --command "<command>"` (`@skills/resolve-issue/references/quality-gates.md` *Machine gate record — one run per tree*). This applies to `composer build` in this repository too.

- This project supports git worktrees. Isolating a work unit in its own worktree (`git worktree add`) is a supported way of working here. The default stays unchanged: per `@rules/git/general.md` an agent works in the current tree and creates a worktree only when the user explicitly asks for one, then removes it once the work unit's PR is merged.
- Files in the generated `.claude/`, `.codex/`, and `.agents/` directories are not tracked by Git. Edit the tracked `rules/`, `skills/`, `agents/`, and `codex/` sources instead.
- The code-review memory is a per-user, git-ignored SQLite database that `krang` reads only when a code review opens a Critical finding (`rules/compound-engineering/memory.md`). `docs/memory/PROJECT_MEMORY.md` is only the source of its one-time migration (`ai-olympus memory migrate`): do not read or write it.

## AI Olympus repository maintenance

These instructions apply only to development of this repository. Use `composer build` for automatic PHP fixes. It synchronizes the installer files, runs the configured fixers, and runs the full checks. When a PHP checker or CI reports an automatically fixable error, run this script instead of manually applying formatting fixes or invoking individual fixers. Fix any remaining errors and rerun the build until it passes.

This repository-specific correction step may run when a checker or CI reports an error, and it takes precedence over the deferred-gate timing above. It is never required before a push: this repository is public on GitHub, so CI is the check after a push. The pre-merge quality gate still applies.

The installer never generates a `CLAUDE.md` in consuming projects. This root `CLAUDE.md` holds maintenance instructions for this repository only.
