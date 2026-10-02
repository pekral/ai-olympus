---
description: Review one task's pull request and report the result to its tracker and GitHub, without fixing anything
argument-hint: [GitHub issue or pull request URL, JIRA issue key or URL, or Bugsnag error URL]
---

Delegate this request to the `splinter` agent in review-only mode and ensure a code review of the task
with this reference:

$ARGUMENTS

If the reference is missing, or is not exactly one full GitHub issue or pull-request URL, one JIRA
issue key or URL, or one Bugsnag error URL, stop and ask for it.

Always review the current code of the pull request branch. Before the review, the run switches the
current working tree to the pull request's head branch: `git fetch origin`, `git checkout
<headRefName>`, and `git pull`. It then confirms that local `HEAD` equals the pull request's head SHA.
Never review from the remote diff alone, from a stale local branch, or from a separate worktree. When
the checkout fails, for example because local changes would be overwritten, stop and report it.

Do not fix anything. `splinter` dispatches `leonardo` once, and `leonardo` runs the code-review
wrapper that matches the tracker: `@skills/code-review-github/SKILL.md`,
`@skills/code-review-jira/SKILL.md`, or `@skills/code-review-bugsnag/SKILL.md`. The wrapper
publishes the result in the output each tracker requires: the technical findings on the GitHub pull
request, and the non-technical summary on the source issue. The summary carries no `How to test`
section. In its place, `Review findings` retells the GitHub report in plain language, so a
non-technical reader understands what the review reported and can reply with feedback. The wrapper
passes `review-only` to `@skills/pr-summary/SKILL.md`. Dispatch no implementation, run no
fix loop, change no tracker status, do not promote the pull request, and never merge. When the task
has no pull request, stop and report that there is nothing to review.

For Codex, invoke the matching wrapper — `$code-review-github`, `$code-review-jira`, or
`$code-review-bugsnag` — with the same reference and ask the registered `splinter` agent to orchestrate
it.
