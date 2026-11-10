---
description: Review one task's pull request and report the result to its tracker and GitHub; changes nothing in the pull request
argument-hint: [GitHub issue or pull request URL, JIRA issue key or URL, or Bugsnag error URL] [fresh]
---

This session holds the `krang` role in review-only mode and never starts `krang` as a subagent, per
`@rules/compound-engineering/orchestration.md`
*Starting the orchestrator — the top-level role and the nested hand-over*. Ensure a code review of the task with this reference:

$ARGUMENTS

If the reference is missing, or is not exactly one full GitHub issue or pull-request URL, one JIRA
issue key or URL, or one Bugsnag error URL, stop and ask for it. The one optional word after the
reference is `fresh`: it is the user's own request for a fresh review. Any other word after the
reference makes the request invalid: stop and ask for it.

`agents/krang.md` *Review-only mode — `/report-code-review`* is the one owner of this run. It
defines which agents run, in which order, what each one publishes, and what the run never does. Run that
mode with the reference, and with the fresh-review request when the user gave `fresh`, and nothing
else. Do not restate, shorten, or
extend those steps in the dispatch prompt: a step that this command and the agent definition state
differently makes the run follow the wrong one. When `agents/krang.md` is not installed, stop and
report it. Never perform a specialist's step in this session.

For Codex, invoke the matching wrapper — `$code-review-github`, `$code-review-jira`, or
`$code-review-bugsnag` — with the same reference. The root session holds the `krang` role under
the same section and spawns the registered specialists itself.
