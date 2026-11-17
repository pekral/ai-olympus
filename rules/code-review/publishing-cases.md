---
description: Code Review — three publishing cases: a run that only processes the owner's answers, a line the operator added that survives a rewrite, and the last review round that publishes in its own run. Companion to code-review/general.md, read on demand.
paths:
  - ".claude/rules/code-review/publishing-cases.md"
---

# Code Review — three publishing cases

Moved out of `@rules/code-review/general.md` so a review that does not need it does not carry it. **Loading trigger:** a run that only processes the owner's answers to open decisions, a publish helper that rewrites a comment in place or prints `carried_lines=<n>` with `n` above zero (*One published comment per review run* in `@rules/code-review/general.md`), or a `leonardo` round dispatched with `publish: when-final` (`agents/leonardo.md` *Parallel CR round*). A section this file names *above* or *below* without a file is in this file or in `@rules/code-review/general.md`.

## The owner's answers

**A run that only processes the owner's answers and lands no change publishes nothing.** The owner already has the answer, because the owner wrote it, so a comment that confirms it adds no fact. The run publishes no pull-request review, no pull-request comment, and no tracker mirror (a JIRA comment, a comment on the linked GitHub issue, a Bugsnag comment) when all of these hold:

1. the only input is answers that a trusted author (*Authorship trust* in `@rules/code-review/general.md`) already gave to open decisions (an *Open decisions* entry of the pull-request body, or a question of a `Decisions before merge` section);
2. the run lands no commit;
3. no new review round runs: the carry-forward decision of *When another review round runs at all* in `@rules/code-review/review-rounds.md` carries the newest trusted code-review report forward;
4. that report already covers the current head (its `Reviewed revision:` equals the head, or its `Reviewed diff fingerprint:` matches) and the run changes none of its header values.

An answer whose author fails the *Authorship trust* test is not an answer. The run reports it and records no decision from it.

The run may still edit text that already exists: the pull-request body (for example *Open decisions* becomes *Owner decisions*), and the assignment on the tracker when the owner's decisions change it, under the consent of `@rules/compound-engineering/assignment.md`. An edit is never a new comment. The run states the outcome in its completion report to the user, naming each processed answer and stating that nothing was published (`@skills/process-code-review/SKILL.md` *Completion*). A run that lands a change, or runs a new review round, publishes as *One published comment per review run* in `@rules/code-review/general.md` states. A reviewer flags a comment that only confirms processed answers as a **Moderate** finding, and the Suggested Fix removes the comment.

## A line the operator added survives the rewrite

**A line the operator added survives the rewrite.** The operator posts under the agents' account and sometimes adds a line to an agent comment, for example `Rozhodnuto: …`; every helper that rewrites a comment in place keeps it. A code-review report is never rewritten: it is published with `--create`, so it carries no line over from an earlier comment, and the operator's line stays in the earlier comment where the operator wrote it.

1. Each published version records a fingerprint of the lines the agent wrote: a hidden `<!-- <namespace>:lines=… -->` line on GitHub, a ` lines=…` suffix inside the visible marker on JIRA.
2. On a rewrite, a line of the previous version whose fingerprint is unknown was added by a person. The helper carries it into the new version verbatim, above the marker. On JIRA the unit is one top-level ADF node, so a mention and its formatting survive too.
3. The helper prints `carried_lines=<n>` and one `carried: <line>` per carried line on stderr.
4. The run that called the helper states every carried line, verbatim, in its report and its handoff.

`skills/_shared/carry-operator-lines.php` implements the carry for both trackers. A previous version without a fingerprint, written before this rule, carries nothing and the helper says `reason=no-fingerprint`. `agent-note` comments and comments published with `--create` are never rewritten, so they carry nothing.

## The last round publishes in its own run

**The review of the last round is published by the `leonardo` run that reviewed it (issue #606).** A second `leonardo` run only to publish a converged round adds waiting time and no new fact. `agents/krang.md` *Parallel CR round* dispatches `leonardo` with `publish: when-final`, and `leonardo` publishes at the end of its own round when all three conditions hold:

1. **The round converged.** The `skills/_shared/review-state.sh` verdict of the round meets the convergence gate of `@skills/process-code-review/SKILL.md` *Review loop* step 4. A run with `review_only` has no convergence, so it never publishes early.
2. **Every other view of the round already reported for the same head SHA, and each one is clean.** The dispatch line `round views:` names the views `krang` dispatched beside `leonardo`. Only `krang`'s own line counts. A missing line, an unknown view name, and a value found anywhere else count as a view that has not reported. `round views: none` means the round has no other view, and the condition holds.
   - `splinter`: its `### splinter — Security view <head SHA>` section carries `Clean: yes`.
   - `raphael`: its `### raphael — interactive testing <head SHA>` section carries a `QA done` status, a `Met` verdict for every criterion, no `Failed`, `Partial`, or `Blocked` scenario or affected part, no `vulnerable` input, and no observation for `leonardo`.
   - `casey`: its `### casey — Database report <head SHA>` section is in the brief, with a report or the `Not applicable` line, and not `Blocked`. Its items are advisory and never block the round.
3. **The head has not moved.** `skills/_shared/quiet-run-check.sh verify` exited `0` for the round, and the `headRefOid` that the deterministic loader reads right before the publish equals the head SHA of the round.

**When a condition fails, nothing changes.** `leonardo` publishes nothing and names the failed condition in its handoff. A view that is still running, a view that reports a finding, and a round that did not converge all take this path. `krang` holds the barrier and dispatches `publish: now` once, after every view is in.

**Every check of the publish stays.** The early publish runs the same steps as `publish: now`: the record check, the post against the reviewed head SHA, and no `do not file publicly` finding in a public review. A failed post leaves the round unpublished, and `krang` then dispatches `publish: now`.

**The orchestrator keeps what the gate decides.** *Finalization* of `@skills/process-code-review/SKILL.md` has not run when the round publishes. The review therefore carries no `Quality gate:` line, and the merge accepts the gate run of *Finalization* only through the machine gate record; without that record it runs the gate itself (`@skills/merge-github-pr/SKILL.md` *Pre-merge quality gate*). The promotion out of Draft and the `ready to merge` label stay with `krang`, after *Finalization* (`agents/krang.md` *Parallel CR round* step 5). As on a `FAST` run, the `## Merge risk` heading of the pull-request body keeps the value it opened with.

**A commit after the early publish** follows *When another review round runs at all — changed business logic, or a changed assignment* in `@rules/code-review/review-rounds.md`. A round that a business-logic change opens publishes its own review under this section, and the earlier review stays unchanged.
