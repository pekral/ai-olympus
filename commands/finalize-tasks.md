---
description: Drive the tracker tasks at the supplied link to a merge-ready state and consolidate the review and testing comments into one
argument-hint: [issue or PR link(s)]
---

Delegate this run to the `daedalus` agent.

Prepare for merge the issue-tracker tasks at this link: $ARGUMENTS

Do exactly this, in order:

1. Read the tracker link out of the arguments above.
2. When no link is there, stop and ask me for it. This command never guesses a link.
3. When the link names more than one task, work through them systematically: one task at a time,
   in the order they are given. Never start the next task before the current one reaches the
   result of step 6.
4. Rebase the task's pull request onto the current code base of the main branch, and install the
   project's current dependencies.
5. Drive the task to a merge-ready state.
6. Consolidate into a **single** tracker comment every comment of mine on that task that is about
   code review or testing. That comment states the assignment as a TLDR, it carries the status of
   the acceptance criteria, and it says whether a direct merge is recommended or a review by the
   person who solved the task. Follow the summary template below, and obey the own-comments rule
   below before you edit or delete anything.

Whenever you are unsure about anything, ask me.

Reply in the issue tracker in the language the assignment was written in.

## Only your own comments may be edited or deleted

Never edit and never delete a comment written by anybody else. You may edit or delete only a
comment whose author is the account the tracker tooling authenticates as. That account is also how
you tell which comments are mine, because it is the account I write them under.

1. Resolve that account before you touch any comment — `gh api user --jq .login` for GitHub,
   `acli jira auth status` for JIRA.
2. Compare every candidate comment's author against that account. Consolidate only the comments
   that match it, and delete only the ones the consolidation replaces.
3. Treat every other comment as read-only input. Quote it or cite it in the consolidated comment
   when it carries a fact the reader needs. Never rewrite it, never fold it away, never remove it.
4. Post the consolidated comment as a new comment and delete nothing in two cases: the account
   does not resolve, or a comment's author does not match it beyond doubt. GitHub carries a login
   on both sides, so the match there is exact. JIRA carries only a display name on a comment, so
   the match there is corroboration and never proof — treat any doubt there as a mismatch.

## Summary template

Follow this shape. It is plain text on purpose — a tracker that renders the comment as plain text
shows Markdown markers literally, which is what this layout avoids. Copy the section headings and
the plain-text layout, never the example's own facts: the account name, the steps and the findings
below come from a past run and belong to it. The leading paragraph belongs in the comment only
when it supersedes an earlier, unreadable comment; drop it otherwise.

```text
Formatting fix for the previous comment — it was sent as plain text, so the formatting markers
showed up literally in it. The same content is readable below. Please ignore the previous comment.

HOW TO TEST

1) Take the ocelnictvi account — its order resync is already done, so the data on it has the right
   shape.
2) Find a contact on it with a recent order.
3) Send that contact a test campaign that contains the merge tag of the last purchased products.
4) In the delivered e-mail, check that the product block really rendered and is not empty.
5) While you are there, it pays to look at the product analytics too, to see whether the products
   match on those orders.
6) Write the result here in the ticket — the account name and the date are enough.

OPEN QUESTION

Has the merge tag already been verified on a real account? I read the whole ticket and the whole
pull request, and there is no record of it anywhere. If you verified it elsewhere, just write here
where, and this question falls away.

ASSIGNMENT COMPLIANCE

Unification of the product code between order items and the catalogue: done.
Decision on historical data: done, it runs through the existing resync, ocelnictvi is finished,
23 accounts remain.
Tests: done, they passed.
Verification of the merge tag on a real account: still missing. This is the only thing that blocks
completion, and a change in the code cannot resolve it. A person with access to the running
application has to do it — an automated agent does not reach it.

Beyond that I found one more thing to fix in the code: around empty and invisible characters in the
product code, the value that gets stored and the check that decides whether it is found do not
agree. The details and the proposed fix are in the comment on the pull request.
```
