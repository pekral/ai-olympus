---
description: Review one task's pull request and report the result to its tracker and GitHub; changes nothing in the pull request
argument-hint: [GitHub issue or pull request URL, JIRA issue key or URL, or Bugsnag error URL]
---

Delegate this request to the `krang` agent in review-only mode and ensure a code review of the task
with this reference:

$ARGUMENTS

If the reference is missing, or is not exactly one full GitHub issue or pull-request URL, one JIRA
issue key or URL, or one Bugsnag error URL, stop and ask for it.

`agents/krang.md` *Review-only mode — `/report-code-review`* is the one owner of this run. It
defines which agents run, in which order, what each one publishes, and what the run never does. Pass
the reference and the review-only mode to `krang`, and nothing else. Do not restate, shorten, or
extend those steps in the dispatch prompt: a step that this command and the agent definition state
differently makes the run follow the wrong one. When `krang` is not registered, stop and report
it. Never run the steps in the main session instead.

For Codex, invoke the matching wrapper — `$code-review-github`, `$code-review-jira`, or
`$code-review-bugsnag` — with the same reference and ask the registered `krang` agent to orchestrate
it.
