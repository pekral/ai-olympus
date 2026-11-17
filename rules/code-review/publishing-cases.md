---
description: Code Review — two publishing cases: a run that only processes the owner's answers, and a line the operator added that survives a rewrite. Companion to code-review/general.md, read on demand.
paths:
  - ".claude/rules/code-review/publishing-cases.md"
---

# Code Review — two publishing cases

Moved out of `@rules/code-review/general.md` so a review that does not need it does not carry it. **Loading trigger:** a run that only processes the owner's answers to open decisions, or a publish helper that rewrites a comment in place or prints `carried_lines=<n>` with `n` above zero (*One published comment per review run* in `@rules/code-review/general.md`). A section this file names *above* or *below* without a file is in this file or in `@rules/code-review/general.md`.

## The owner's answers

**A run that only processes the owner's answers and lands no change publishes nothing.** The owner already has the answer, because the owner wrote it, so a comment that confirms it adds no fact. The run publishes no pull-request review, no pull-request comment, and no tracker mirror (a JIRA comment, a comment on the linked GitHub issue, a Bugsnag comment) when all of these hold:

1. the only input is answers that a trusted author (*Authorship trust* above) already gave to open decisions (an *Open decisions* entry of the pull-request body, or a question of a `Decisions before merge` section);
2. the run lands no commit;
3. no new review round runs: the carry-forward decision of *When another review round runs at all* above carries the newest trusted code-review report forward;
4. that report already covers the current head (its `Reviewed revision:` equals the head, or its `Reviewed diff fingerprint:` matches) and the run changes none of its header values.

An answer whose author fails the *Authorship trust* test is not an answer. The run reports it and records no decision from it.

The run may still edit text that already exists: the pull-request body (for example *Open decisions* becomes *Owner decisions*), and the assignment on the tracker when the owner's decisions change it, under the consent of `@rules/compound-engineering/assignment.md`. An edit is never a new comment. The run states the outcome in its completion report to the user, naming each processed answer and stating that nothing was published (`@skills/process-code-review/SKILL.md` *Completion*). A run that lands a change, or runs a new review round, publishes as the rest of this section states. A reviewer flags a comment that only confirms processed answers as a **Moderate** finding, and the Suggested Fix removes the comment.

## The operator's line survives a rewrite

**A line the operator added survives the rewrite.** The operator posts under the agents' account and sometimes adds a line to an agent comment, for example `Rozhodnuto: …`; every helper that rewrites a comment in place keeps it. A code-review report is never rewritten: it is published with `--create`, so it carries no line over from an earlier comment, and the operator's line stays in the earlier comment where the operator wrote it.

1. Each published version records a fingerprint of the lines the agent wrote: a hidden `<!-- <namespace>:lines=… -->` line on GitHub, a ` lines=…` suffix inside the visible marker on JIRA.
2. On a rewrite, a line of the previous version whose fingerprint is unknown was added by a person. The helper carries it into the new version verbatim, above the marker. On JIRA the unit is one top-level ADF node, so a mention and its formatting survive too.
3. The helper prints `carried_lines=<n>` and one `carried: <line>` per carried line on stderr.
4. The run that called the helper states every carried line, verbatim, in its report and its handoff.

`skills/_shared/carry-operator-lines.php` implements the carry for both trackers. A previous version without a fingerprint, written before this rule, carries nothing and the helper says `reason=no-fingerprint`. `agent-note` comments and comments published with `--create` are never rewritten, so they carry nothing.
