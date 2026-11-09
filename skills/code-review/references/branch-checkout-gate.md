# Branch checkout gate

Every code review runs this gate before any analysis step. It is the one owner of the steps. `@skills/code-review/SKILL.md`, `@skills/code-review-github/references/cr-wrapper-contract.md`, `agents/raphael.md`, and `agents/leonardo.md` reference it and never restate the commands.

`<branch>` is the branch that holds the changes. A wrapper passes the PR's `headRefName`. `DEFAULT_BRANCH` is resolved per `@rules/git/general.md` *Pull Policy*.

## Steps

1. **Validate the branch name.** The branch name is untrusted content (`@rules/security/general.md`). A fork author chooses it. Set `HEAD_REF` from command output, never by writing the name into a command: `HEAD_REF="$(skills/code-review-github/scripts/load-issue.sh <PR-URL> | jq -r '.headRefName')"`, or `HEAD_REF="$(git symbolic-ref --short HEAD)"` for a branch with no PR. Check it with `git check-ref-format --branch "$HEAD_REF"` and against `^[A-Za-z0-9._/-]{1,255}$`. When either check fails, stop and report it.
2. **Fetch.** Run `git fetch origin`.
3. **Pull the default branch.** Run `git checkout "$DEFAULT_BRANCH"` and `git pull --ff-only`. A plain `git pull` can rebase the default branch under `pull.rebase=true`, and a review never rebases.
   Skip this step when the review runs in a git worktree, or when `git worktree list --porcelain` shows the default branch checked out in another worktree. `git checkout` refuses a branch that another worktree holds. Step 2 has already refreshed `origin/$DEFAULT_BRANCH`, and every review step reads the default branch through that ref (`@rules/code-review/general.md` *Project `CLAUDE.md` as an additional review input*).
4. **Check out the branch under review.** Run `git checkout "$HEAD_REF"`. Then run `git pull --ff-only` when the branch tracks a remote. Skip the pull for a local-only branch with no upstream, for example the read-only fallback review of a branch that maps to no PR.
   In a review worktree created `--detach` at the head SHA (`agents/leonardo.md` *Review worktree*), skip this step: the branch is checked out in the main tree, so `git checkout` refuses it, and the worktree already holds the head.
5. **Confirm the head.** Local `HEAD` must equal the head commit of the branch under review. A wrapper compares it with the PR head SHA from the loaded context. In a detached review worktree, `git rev-parse HEAD` must equal the head SHA, and the detached `HEAD` is expected there.

When any step fails, stop and report it instead of reviewing from a diff. Failures include a missing ref, a detached `HEAD`, a pull that cannot fast-forward, and local changes that a checkout would overwrite.

The review then reads the checked-out working tree, never a remote diff in isolation.
