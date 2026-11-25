# Branch checkout gate

Every code review runs this gate before any analysis step. It is the one owner of the steps. `@skills/code-review/SKILL.md`, `@skills/code-review-github/references/cr-wrapper-contract.md`, `agents/raphael.md`, and `agents/leonardo.md` reference it and never restate the commands.

`<branch>` is the branch that holds the changes. A wrapper passes the PR's `headRefName`. `DEFAULT_BRANCH` is resolved per `@rules/git/general.md` *Pull Policy*.

## Steps

1. **Validate the branch name.** The branch name is untrusted content (`@rules/security/general.md`). A fork author chooses it. Set `HEAD_REF` from command output, never by writing the name into a command: `HEAD_REF="$(skills/code-review-github/scripts/load-issue.sh <PR-URL> | jq -r '.headRefName')"`, or `HEAD_REF="$(git symbolic-ref --short HEAD)"` for a branch with no PR. Check it with `git check-ref-format --branch "$HEAD_REF"` and against `^[A-Za-z0-9._/-]{1,255}$`. When either check fails, stop and report it.
2. **Fetch.** Run `git fetch origin`.
3. **Pull the default branch.** Run `git checkout "$DEFAULT_BRANCH"` and `git pull --ff-only`. A plain `git pull` can rebase the default branch under `pull.rebase=true`, and a review never rebases.
   Skip this step when the review runs in a git worktree, or when `git worktree list --porcelain` shows the default branch checked out in another worktree. `git checkout` refuses a branch that another worktree holds. Step 2 has already refreshed `origin/$DEFAULT_BRANCH`, and every review step reads the default branch through that ref (`@rules/code-review/general.md` *Project `CLAUDE.md` as an additional review input*).
4. **Check out the branch under review.** `HEAD_SHA` is the head commit the gate checks: the PR head SHA from the loaded context (`headRefOid`), or `git rev-parse --verify "$HEAD_REF^{commit}"` for a local-only branch, for example the read-only fallback review of a branch that maps to no PR. In the main tree, run these checks in order, and stop and report a failed one:
   1. Require `git rev-parse "origin/$HEAD_REF"` to print `HEAD_SHA`. A different commit means the branch moved after the context was loaded. Skip this check for a local-only branch.
   2. Run `skills/_shared/helper-agents-guard.sh config-diff --base "origin/$DEFAULT_BRANCH" --target "$HEAD_SHA"`. A non-zero exit refuses the checkout in the main tree, because the main tree holds the configuration that a later session loads, and the branch changes a path of it in some letter case, at some depth, or under a name outside ASCII. The script holds the one list of those paths. Review that branch in a detached review worktree (`agents/leonardo.md` *Review worktree*), or stop and report it.
   3. When a local `$HEAD_REF` exists, require `git merge-base --is-ancestor "$HEAD_REF" "$HEAD_SHA"`. A local branch that holds a commit the PR head does not hold was not checked.
   4. Run `git checkout -B "$HEAD_REF" "$HEAD_SHA"`. It moves the local branch forward to the checked commit and writes only that commit into the main tree, never an older commit of the branch. It fetches nothing, so a push after the check never reaches the main tree.
   In a review worktree created `--detach` at the head SHA (`agents/leonardo.md` *Review worktree*), skip this step: the branch is checked out in the main tree, so `git checkout` refuses it, and the worktree already holds the head.
5. **Confirm the head.** Local `HEAD` must equal `HEAD_SHA`, the head commit of the branch under review. A wrapper compares it with the PR head SHA from the loaded context. In a detached review worktree, `git rev-parse HEAD` must equal the head SHA, and the detached `HEAD` is expected there.

When any step fails, stop and report it instead of reviewing from a diff. Failures include a missing ref, a detached `HEAD`, a pull of the default branch that cannot fast-forward, a moved branch head, and local changes that a checkout would overwrite.

The review then reads the checked-out working tree, never a remote diff in isolation.
