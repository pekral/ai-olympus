# Branch checkout gate

Every code review runs this gate before any analysis step. It is the one owner of the steps. `@skills/code-review/SKILL.md`, `@skills/code-review-github/references/cr-wrapper-contract.md`, `@skills/interactive-testing/SKILL.md`, `agents/raphael.md`, `agents/leonardo.md`, and `agents/splinter.md` reference it and never restate the commands.

`<branch>` is the branch that holds the changes. A wrapper passes the PR's `headRefName`. `DEFAULT_BRANCH` is resolved per `@rules/git/general.md` *Pull Policy*.

**The main working tree is never checked out on a branch under review.** The main tree holds the configuration that a later session loads, and a checkout of the branch would write the branch's files over it. A review and a walkthrough read the branch in a detached worktree below `.claude/worktrees/` instead, and the main tree stays on its own branch.

## Steps

1. **Validate the branch name.** The branch name is untrusted content (`@rules/security/general.md`). A fork author chooses it. Set `HEAD_REF` from command output, never by writing the name into a command: `HEAD_REF="$(skills/code-review-github/scripts/load-issue.sh <PR-URL> | jq -r '.headRefName')"`, or `HEAD_REF="$(git symbolic-ref --short HEAD)"` for a branch with no PR. Check it with `git check-ref-format --branch "$HEAD_REF"` and against `^[A-Za-z0-9._/-]{1,255}$`. When either check fails, stop and report it.
2. **Fetch.** Run `git fetch origin`. Every review step reads the default branch through `origin/$DEFAULT_BRANCH` (`@rules/code-review/general.md` *Project `CLAUDE.md` as an additional review input*).
3. **Resolve the head commit.** `HEAD_SHA` is the head commit the gate opens: the PR head SHA from the loaded context (`headRefOid`), or `git rev-parse --verify "$HEAD_REF^{commit}"` for a local-only branch, for example the read-only fallback review of a branch that maps to no PR. Require `git rev-parse "origin/$HEAD_REF"` to print `HEAD_SHA`. A different commit means the branch moved after the context was loaded. Skip this check for a local-only branch.
4. **Open the worktree at the head.** Run `skills/_shared/branch-worktree.sh open --path <worktree> --head "$HEAD_SHA"` in the main tree. `<worktree>` is the caller's own worktree: the review worktree of `agents/leonardo.md` *Review worktree* or `agents/splinter.md` *Review worktree*, the test worktree of `@skills/interactive-testing/SKILL.md` *Local readiness*, or the unit worktree a `Batch unit` dispatch names. The script creates the worktree detached at that commit, or moves an existing one there. It runs no program of the branch: the table in its header is the one list of the git mechanisms that run a program, and of what the script does with each.
   The script needs git 2.41 or later, which has `--attr-source`, and a partial clone needs git 2.45 or later. It refuses an older git, and then the review stops as the failures below say.
   A git older than 2.56 has no switch by event for a hook of another name. The script therefore creates a new worktree without a checkout, switches off by name each hook that the worktree configuration names, and only then checks the commit out.
   Run every later git read in that worktree, such as `git status`, `git diff`, `git show`, or `git log`, as `skills/_shared/branch-worktree.sh git --path <worktree> -- <command>`.
5. **Confirm the head.** `git -C <worktree> rev-parse HEAD` must equal `HEAD_SHA`. A wrapper compares it with the PR head SHA from the loaded context. The detached `HEAD` is expected there.

When any step fails, stop and report it instead of reviewing from a diff. Failures include a missing ref, a moved branch head, a refusal of the script, and a change to a tracked file in the worktree that a move would overwrite.

The review then reads the files of that worktree, never a remote diff in isolation and never the main tree.
