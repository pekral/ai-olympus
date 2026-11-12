---
description: Prepare one or more GitHub or JIRA issues' pull requests for merge, merging only on request, then consolidate the preparation comments
argument-hint: [GitHub issue or pull request URL, or JIRA issue key or URL] [more references] [--merge]
---

With exactly one reference and no flag, this session holds the `krang` role for this request
and never starts `krang` as a subagent, per `@rules/compound-engineering/orchestration.md`
*Starting the orchestrator — the top-level role and the nested hand-over*.
Run `@skills/verify-merge-readiness/SKILL.md` with these arguments:

$ARGUMENTS

If the reference is missing, or is not a full GitHub issue or pull-request URL or a JIRA issue key or
URL, stop and ask for it. With one reference and no flag, follow the skill in full. Bring the linked
pull request to a verified merge-ready state, publish and verify its single source-issue TL;DR, remove only the
superseded actor-owned preparation comments the skill permits, and stop before merge.

With more than one reference, or with `--merge`, this session still holds the `krang` role. It holds
it for every reference at once and starts no `krang` as a subagent. Load
`skills/_shared/orchestration/batch-preparation.md` and follow it in full. It runs one pipeline for
each reference, each in its own git worktree, and it advances each pipeline when the handoff of its
last dispatch arrives. `krang` only orchestrates and delegates every step to the specialists
(`donatello`, `leonardo`, `splinter`, `casey`, `raphael`, `april`). Every gate, install, and test
command of every unit runs only through `skills/_shared/run-gate.sh`, so two worktrees never run a
heavy job at the same time. The session publishes one aggregated report for the batch, and a blocker
on one issue never stops the others.

The flag `--merge` is optional. Without it the command stops before merge, as it does for one
reference. With it, a single reference runs as a batch of one unit, and each unit rebases onto the
latest default branch, verifies the gate on its new head, and merges through
`@skills/merge-github-pr/SKILL.md`, with every gate of that skill unchanged.

Change only what the assignment's acceptance criteria and the merge gate require. Do not implement
an optimization, a refactoring, a pre-existing problem, or a nice-to-have point. Publish each one as a
question in the separate `Decisions before merge` section — on the source GitHub issue, or on the
pull request for a JIRA source, where the JIRA ticket shows only the Critical ones — so a human
decides it. The merge requires an answer to every question.

When the issue carries no pull request, `krang` resolves the task first — the proposal review of
`skills/_shared/orchestration/proposal-review.md`, implementation, and the review-and-fix loop to
convergence — and then prepares the pull request that delivery opens.

For Codex, invoke `$verify-merge-readiness` with the same references and the same optional
`--merge` flag. The root session holds the `krang` role under the same section, for one reference or
for every reference of a batch, and spawns the registered specialists itself.
