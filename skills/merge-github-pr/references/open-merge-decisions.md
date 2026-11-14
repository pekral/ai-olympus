# Open merge decisions

Loaded by `@skills/merge-github-pr/SKILL.md` step 2 (*Open merge decisions*). This is a hard gate with no exemption, on the full path and on the low-risk path.

`@skills/verify-merge-readiness/SKILL.md` does not implement points outside the assignment. It publishes them as questions in the `Decisions before merge` section of the `merge-readiness` comment — on the source GitHub issue, or on the pull request itself when the source is a JIRA ticket. A human decides each one before the merge.

1. Load the newest `merge-readiness` comment by the agent actor: on the source GitHub issue, or on the pull request when the source is a JIRA ticket. When none exists, or its section states that no decision is required, the gate passes.
2. Take an answer to each question from one of two sources only:
   - the user's own instruction in this session;
   - a comment on the source issue or on the PR, posted after that `merge-readiness` comment by a trusted human: no agent marker, and on GitHub an `authorAssociation` of `OWNER`, `MEMBER`, or `COLLABORATOR` (`@rules/compound-engineering/tracker.md` *Resolving trust per tracker*).

   Each `### Already decided` line of that comment cites an earlier answer (`@skills/verify-merge-readiness/SKILL.md` *Look for the answer before you ask*). Open the cited comment and apply the answer only when the comment exists, says that answer, and its author passes the trusted-human test above. A citation that fails the check is an unanswered question.

   Never answer a question yourself. Never take the recommendation, or silence, as an answer.
3. When a question has no answer, do not merge. Output the unanswered questions in a separate `## Decisions required before merge` section: numbered, verbatim, each with its options and its recommendation. In an interactive session, ask the user for all answers in one round.
4. Apply each answer:
   - **A — implement in this PR:** do not merge. Report that the point returns to implementation and that the PR needs another `/prepare-issue-for-merge` run.
   - **B — file as a follow-up issue:** file it in the source tracker through `@skills/resolve-issue/references/deferred-follow-up.md` (deduplicate, file, verify, link the PR). The human answer replaces the filing bar. A point that cannot be filed blocks the merge.
   - **C — drop:** take no action.
5. The merge report lists every question with its answer and the person who answered it.
