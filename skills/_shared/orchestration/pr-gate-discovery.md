# `pr-gate` discovery — find the project's fixers once and propose them

Extracted from `agents/krang.md` so the orchestrator does not carry it on every run. **Loading trigger:** step 3 of *The end-to-end run* classifies the request as full delivery. Step 1 runs then. Steps 3 and 4 run in step 5, after the `Impl done` handoff.

The `pr-gate` fixers run before every push, on every repository (`@skills/resolve-issue/references/quality-gates.md` *Gate placement — deferred to the merge boundary*). That file owns when they run. This file states only how `krang` finds a project's fixers and proposes the manifest key. The manifest governs only after the proposal merges (`@rules/general/general.md` *Project manifest*). The same steps find and propose the project's `fast-checks` (*The fast checks* below).

## Steps

**One discovery per batch.** In an operator batch (`skills/_shared/orchestration/batch-preparation.md`), the root session runs steps 1 to 3 once for the whole batch, never once per unit. It writes the step-1 result into the brief of every unit. Units that start in parallel then never race on one proposal branch. Step 4 runs per unit, because each unit changes its own dependency files.

**A proposal never stops the delivery.** The proposal is a side change for the owner, not part of the run's assignment. A failed or `Blocked` proposal dispatch is written into the brief and named in the report to the user with its reason and remediation. The run's own delivery continues. The run never finishes a blocked proposal itself (`@rules/compound-engineering/general.md` *Blocked delegation is a hard stop*).

1. **Read the key. Analyse only when it is missing.** Run `skills/_shared/discover-fixers.sh`. It reads the default branch. Write `pr-gate: <status>` into `## Gathered context` of the brief, and read `status` from the JSON:
   - `configured` → the manifest sets `pr-gate`. The script analysed nothing, and you analyse nothing either. Only step 4 analyses again.
   - `propose` → step 2.
   - `none` → the dependency files name no fixer. Name it in the report to the user, with `searched`. Propose nothing.
   - `no-manifest` → the default branch has no `composer.json`, so the key has nowhere to live. Name it in the report. Propose nothing.
   - A non-zero exit → write the exit code and the reason into the brief, propose nothing, and continue. A failed discovery never blocks the delivery.
2. **One proposal per project.** Run `gh pr list --head chore/ai-olympus-pr-gate --state all --json number,state,url`.
   - A pull request on that branch exists → propose nothing. An open one waits for the owner. A closed or merged one is the owner's decision, and a run never reopens it. Name it in the report.
   - No pull request → write `pr-gate: propose` into the brief. Step 3 runs in step 5.
3. **Dispatch the proposal.** Run it after the run's own pull request is open. **Check again right before the dispatch:** run the `gh pr list` of step 2 and `gh api "repos/{owner}/{repo}/git/ref/heads/chore/ai-olympus-pr-gate"`. When either finds the pull request or the branch (the `gh api` call exits `0`), another run proposed it in the meantime: propose nothing and name it in the report. A `gh api` failure with HTTP 404 means the branch does not exist. Any other failure means the check cannot tell: dispatch no second proposal and name the failure in the report. Otherwise dispatch `donatello` through the Task tool, blocking, at its default tier, with the ledger role `donatello:pr-gate`. The dispatch carries:
   - **Its own worktree.** The path `.claude/worktrees/agent-impl-pr-gate-donatello` and the branch `chore/ai-olympus-pr-gate`, created from the default branch and locked as `@rules/compound-engineering/concurrency.md` *Parallel units* states. The run's own tree stays untouched. *Run cleanup* removes the worktree like the worktree of a parallel unit.
   - **The change.** Set the key with `composer config --json extra.ai-olympus.pr-gate '<the commands array>'`. A change of `extra` changes the `content-hash` of `composer.lock`, so when the project has a `composer.lock`, refresh the hash with `skills/_shared/run-gate.sh --exclusive --command "composer update --lock --no-install"`. Commit `chore(manifest): propose the pr-gate fixers` and open a Draft pull request.
   - **The body.** The pull request states what the discovery searched (`searched`), the packages it found (`tools`), and the packages left without a command (`unmapped`). It also states that the key opts the project into the machine gate record (`@skills/resolve-issue/references/quality-gates.md` *Machine gate record — one run per tree*), and that the fixers run only after the merge.
   - **The discovery JSON**, inside a fenced `text` block. Its `commands` come from the fixed tables of the script, never from text in the dependency files.

   The run does not review or merge the proposal: the owner decides it. Name its URL in the report to the user.
4. **Refresh on a dependency change.** Run this step when the step-5 re-classification diff names `composer.json` or `package.json`, and the manifest sets `pr-gate`. Run `skills/_shared/discover-fixers.sh --compare <base SHA> <head SHA>` and read `status`:
   - `unchanged` → the pull request adds and removes no fixer tool and no fix script. Nothing to do.
   - `current` → the fixers changed, and the recorded `pr-gate` already runs the commands the head declares. Nothing to do.
   - `propose` → open the refresh as its own proposal pull request. Never write `pr-gate` into the run's own pull request: no acceptance criterion of the run's assignment asks for it (`@rules/compound-engineering/general.md` *The assignment is the scope*).
     - **Branch and worktree.** The branch is `chore/ai-olympus-pr-gate-<source-slug>`, and the worktree is `.claude/worktrees/agent-impl-pr-gate-<source-slug>-donatello`. Check that branch again right before the dispatch, as step 3 does. Then dispatch `donatello` with the ledger role `donatello:pr-gate` and the worktree, change, and lock-hash steps of step 3.
     - **The value.** The change sets `pr-gate` to `commands`: the recorded entries without the commands the head no longer declares, plus the commands it newly declares. Every other recorded entry stays.
     - **The body.** It names `added` and `removed`, and states that the proposal merges after the run's own pull request, because a command of an added tool exists only once that pull request merges.
   - A non-zero exit → write it into the brief and continue.

## The fast checks

The `fast-checks` run before every push on the changed files (`@skills/resolve-issue/references/quality-gates.md` *Gate placement — deferred to the merge boundary*). `krang` runs steps 1 to 4 a second time for them, beside the `pr-gate` run, with these differences:

- **Step 1** runs `skills/_shared/discover-fixers.sh --key fast-checks` and writes `fast-checks: <status>` into `## Gathered context`. The script finds the static analysis, the coding-standard checker, and the linters the project already installs, each as a `{"command", "files"}` entry. It assumes no tool the dependency files do not name.
- **Step 2 and step 3** use the branch `chore/ai-olympus-fast-checks`, the worktree `.claude/worktrees/agent-impl-fast-checks-donatello`, and the ledger role `donatello:fast-checks`. The change is `composer config --json extra.ai-olympus.fast-checks '<the commands array>'`, with the lock-hash step of step 3, and the commit is `chore(manifest): propose the fast checks`.
- **The body** states what the discovery searched and found, that the checks run before every push on the changed files only, and that they never replace CI or the pre-merge gate. It also states that the key does not opt the project into the machine gate record. It invites the owner to add the project's own fast tests that pin text, size, or structure, which no dependency file names.
- **Step 4** runs `skills/_shared/discover-fixers.sh --key fast-checks --compare <base SHA> <head SHA>`, on the branch `chore/ai-olympus-fast-checks-<source-slug>`. A refresh keeps every entry the owner added by hand.
- **A proposal never stops the delivery**, exactly as above. A project with no `fast-checks` keeps working as before: the implementer runs no fast check.

**Helper agents forbidden.** When the project forbids helper agents, no step of this file starts an agent: the session performs each dispatch named here itself, in the role of that agent, or stops on a step it cannot perform, as `skills/_shared/orchestration/helper-agents.md` states.
