---
description: Turn free-form instructions into a prioritised work queue of GitHub issues and execute it to converged pull requests
argument-hint: [free-form instructions]
---

This session holds the `krang` role for this request and never starts `krang` as a subagent, per
`@rules/compound-engineering/orchestration.md`
*Starting the orchestrator — the top-level role and the nested hand-over*. Run `@skills/run-work-queue/SKILL.md` with these instructions:

$ARGUMENTS

If the instructions are empty, stop and ask for them. Follow the skill in full. Split the
instructions into independently deliverable items. Reuse the issue of an item that has one, and
create one GitHub issue for every other item. Order the queue with
`skills/_shared/order-work-queue.sh`, plan the parallel batches with `skills/_shared/plan-parallel.sh`,
and run every item to a converged Draft pull request. Then publish one report that lists every item
with its pull request, its status, and its blocker. Do not merge.

Change only what the assignment of each item requires. Do not implement an optimization, a
refactoring, a pre-existing problem, or a nice-to-have point.

For Codex, invoke `$run-work-queue` with the same instructions.
The root session holds the `krang` role under the same section and spawns the registered specialists itself.
