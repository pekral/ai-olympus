---
description: Unified Git workflow, commits and pull request rules
---

## Branch Context
- If working outside `main`, always analyze commits in the current branch before making changes to understand context.

## Git Rules
- Never push directly to `main` branch. NEVER.
- **Commit granularity is the author's judgment; no rule prescribes how the work is divided.** This package used to require one commit per phase and one per enumerated assignment point, ordered so each commit was independently cherry-pickable, with no commit shipping a symbol its own tree did not yet call. None of it was ever a review criterion — the review reads the diff that will be merged, and the granularity of the commits under it changes nothing about what that diff does. What it did cost was real: a plan table written before the first line of code, a reconciliation pass against that table before the PR, and a rebase whenever the plan turned out wrong. Write the history the way it reads best for the next human, and spend the effort on the change instead.
  **What is lost, stated rather than hidden:** a merged branch's commit list no longer maps one-to-one onto the assignment's points, so a reviewer cannot check the assignment off against `git log`, and a single mid-branch commit is not known to be cherry-pickable on its own. The assignment is checked against the diff and the PR's own `## Changes` list instead.
- **The merged head is green; intermediate commits are not gated.** A history where the branch tip passes but the commits under it were never checked is not a deployable sequence in the strict sense — but proving every commit green means running the project's full gate once per commit, which on a larger task dominates the cost of delivering the change for a guarantee almost nothing consumes. The gate therefore runs **once, on the exact head commit being merged** (`@skills/resolve-issue/references/quality-gates.md` *Gate placement — deferred to the merge boundary*, executed by `@skills/merge-github-pr/SKILL.md` *Pre-merge quality gate*), and the fixes it produces land as their own commit on the branch. Three obligations survive that change unaltered.
  - **A test and the change that makes it pass land in the same commit.** Never commit a failing test, and never commit a test written to fail: no assertion of the buggy behaviour so a later commit can "fix" it, no `markTestIncomplete()` / `$this->fail()` placeholder standing in for the real assertion, no skipped or commented-out test left to be enabled later. This is not a gate-placement rule and is not relaxed by one — it is about not encoding a lie in the history. The RED step of `@skills/test-driven-development/SKILL.md` stays mandatory — it is a state of the **working tree**, never a commit. Write the failing test, watch it fail, write the fix, then commit both together.
  - **A history rewrite re-runs the gate.** A rebase, an `--autosquash`, a reorder, a squash, or a split changes the tree of the head commit, so a gate that passed before the rewrite proves nothing about the history that came out of it. Whenever the branch is rebased, the pre-merge gate runs again on the new head — a reshaped branch never inherits an earlier run's verdict. Replaying the whole range (`git rebase --exec '<the project gate>' <base>`) is available when a genuinely bisectable history is wanted, and is no longer required by default.
  - **The gate is the project's own.** Run what `composer build`, the Phing target, or the CI workflow runs — not a hand-picked subset. A merge that passed only the tests its author remembered is exactly the merge the next deploy breaks on.

  **What this trades away, stated plainly:** an arbitrary commit picked out of a merged branch is no longer known to be green, so `git bisect` over such a range can land on a commit that fails for reasons unrelated to the bug, and a cherry-pick of a single mid-branch commit may need its own gate run. Cherry-pick *independence* was a separate requirement and has since been withdrawn with the rest of the granularity mandate (*Commit granularity* above), so neither property is guaranteed today. When a specific branch genuinely needs a bisectable history, run the range replay above and say so in the PR.

  Severity in code review: a committed failing or simulated-failing test is **Critical**. A merge performed without a passing gate run on the merged head commit is **Critical**. Intermediate commits that do not individually pass the gate are **not** a finding.
- **Nothing is left in the working tree, and a branch under review is not rewritten.** Every change the run makes — a review-loop fix, a correction, a CHANGELOG entry — is committed before the branch is handed on; how it is grouped is your call, per *Commit granularity* above. Two constraints survive that freedom:
  - **While the branch is yours, rewriting it is fine.** `git commit --amend` for the tip and `git commit --fixup=<sha>` + `git rebase --autosquash <base>` for an earlier commit are available whenever the branch is unpushed, or pushed but not yet under review.
  - **Once it is pushed and under review, add a commit instead.** A force-push moves every later SHA, which detaches review-thread anchors, invalidates any SHA already cited in a posted report or PR description, and can silently drop a reviewer's in-flight comment. When a rewrite is genuinely needed there, it is a deliberate, stated decision — never an incidental side effect of tidying — and it publishes with `git push --force-with-lease` (never plain `--force`), after which every SHA you have already cited is re-derived.
- Use meaningful branch names, always written in English regardless of the assignment language.

## Worktrees / Workspaces
- **Do not create git worktrees or separate workspaces automatically.** By default an agent works in the current branch and working tree (a feature / fix branch off the default branch is enough); never run `git worktree add`, clone into a side directory, or spin up an isolated workspace on your own initiative.
- Create an isolated worktree **only on explicit request** — when the caller / user asks for it, or when a workflow the user explicitly opted into (e.g. parallel multi-unit orchestration) genuinely requires per-unit isolation to avoid corrupting a shared tree. Absent that explicit request, stay in the current tree.
- When an isolated worktree *was* requested, remove it after the PR for the work unit has been merged (deployed) — the post-merge step of `@skills/merge-github-pr/SKILL.md` owns this. Before removing, verify the worktree is not the currently active working tree and has no uncommitted changes; never pass `--force`. Run `git worktree remove <path>` followed by `git worktree prune` to clean up metadata. This keeps git clean and leaves no orphaned trees behind.

## Pull Policy
- Resolve the default branch by name first — it is `main` on some repos and `master` on others. Never hardcode `origin/main`; on a `master`-default repo that reference does not exist and the command fails. Derive it once: `DEFAULT_BRANCH="$(git symbolic-ref --short refs/remotes/origin/HEAD | sed 's@^origin/@@')"`.
- The default branch is pulled directly: `git checkout "$DEFAULT_BRANCH" && git pull`. No rebase step applies to it.
- Before pulling any **other** branch you are working on (a feature / fix / PR branch), sync it so it always carries the latest default branch, in this exact order:
    1. `git fetch origin`
    2. Take the branch's own remote **first**, so the rebase in step 3 is not undone later: `git pull --rebase` (keeps history linear; a no-op when you are the sole contributor and nothing new was pushed).
    3. Rebase the default branch into the side branch: `git rebase "origin/$DEFAULT_BRANCH"` (replays the branch's commits on top of the newest default branch).
    4. Resolve any conflicts (`@skills/git-workflow/SKILL.md`), then `git rebase --continue`.
- Do **not** run `git pull` again after step 3 — pulling after the rebase replays your commits back onto the branch's old remote tip and discards the sync. The branch's own remote was already taken in step 2; the only remaining publish step is the force-push below.
- If the rebase changed `composer.lock` (the default branch updated dependencies), run `composer install` immediately afterwards so the installed packages match the new lockfile. Resolve a `composer.lock` conflict before installing.
- Publishing the rebased branch to its remote uses `git push --force-with-lease` (never plain `--force`, and only when you are the sole contributor — see `@skills/git-workflow/SKILL.md`).
- **Read-only review skills are exempt.** A read-only skill switches to the branch and runs `git pull` only to read the diff; it must never rebase, `composer install`, or otherwise rewrite the branch or working tree.

## Commit Messages
- Language: always English, regardless of the assignment language. Unlike the PR description (which follows the assignment language), commit messages and PR titles are never translated.
- Format: `type(scope): short description`
- Keep messages concise and specific.
- Use lowercase for `type` and `scope`.
- Do not end with a period.
- Never include signatures or attribution. This covers AI co-author trailers: no `Co-Authored-By:` lines and no "Generated with"/"Made with" notes (e.g. "Made with Cursor", "Generated with Claude Code").

### Allowed Types
- feat: New feature
- fix: Bug fix
- docs: Documentation changes
- style: Formatting or style-only changes
- refactor: Code changes without behavior change
- test: Adding or updating tests
- chore: Maintenance tasks

## Pull requests and merging — the companion file

The sections that govern a pull request live in `@rules/git/pull-requests.md`, not in this file: *Issue Linking*, *Pull Requests* (with *Draft pull requests*), *PR Lifecycle*, and *Merging* (with the *HOTFIX*, *`FAST`-tier*, and *Dependency-only* pull-request cases). That file loads on demand, so this file stays inside the total instruction budget Claude Code enforces across every always-on file.

- **A run that opens, describes, links, reviews, promotes, or merges a pull request reads and applies `@rules/git/pull-requests.md` first.** Every skill and agent that performs one of those steps names the file.

## Cleanup
- Remove any temporary `.md` files created during PR preparation.

## Tooling
- Use GitHub CLI (`gh`) as the primary tool for all operations.
- If `gh` is unavailable, use a GitHub MCP server.
- If no GitHub tool is available, stop and report that GitHub access is not available.
