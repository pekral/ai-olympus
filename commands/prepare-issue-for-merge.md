---
description: Prepare one GitHub issue's pull request for merge without merging, then consolidate the preparation comments
argument-hint: [GitHub issue or pull request URL]
---

Delegate this request to the `daedalus` agent and run
`@skills/verify-merge-readiness/SKILL.md` with this reference:

$ARGUMENTS

If the reference is missing or is not exactly one full GitHub issue or pull-request URL, stop and
ask for it. Follow the skill in full. Bring the linked pull request to a verified merge-ready state,
publish and verify its single source-issue TL;DR, remove only the superseded actor-owned preparation
comments the skill permits, and stop before merge.

For Codex, invoke `$verify-merge-readiness` with the same URL and ask the registered `daedalus`
agent to orchestrate it.
