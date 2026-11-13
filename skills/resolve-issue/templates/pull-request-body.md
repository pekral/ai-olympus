Closes #<N>

## Summary
<concise overview of what changed and why>

## Changes
- <one entry per in-scope change, in the assignment's own wording, in the order a reader follows them>

## Testing
<how to test>
Evidence: <regression test that failed before the fix and passes after it, with the command that ran it>

## Merge danger
**Door:** <one-way | two-way> <rollback signals with their files, or no rollback signal>
**Blast radius:** <tier> (<signal names>)

## Pre-existing fixes
- <fix commit> — <one-line rationale>

## Security acceptance checklist
- [x] [Critical] <item> — <file:line or test name>
- [ ] [Moderate] <item> — <pointer>
Plan: <plan issue link>

## Safeguard
<proposal link and key, original findings, level and limitation, the four proofs with commands and results>

## TODO
- [ ] <deferred point> — filed as <follow-up issue URL>

## Audit
<transcript of the .claude/run/<source-slug>.audit ledger, with its declared incompleteness>

<!-- First line: GitHub task, the literal English Closes #<N>. JIRA task: the key goes in the pull request title and the issue URL in the body. Bugsnag task: the error URL, plus Closes #<N> of a linked mirrored issue. No tracker: state that the task carries no tracker item. -->
<!-- Evidence line: only on a bug fix. Merge danger: if the script cannot run, write Merge danger: not computed — <reason>. -->
<!-- Omit Pre-existing fixes, Security acceptance checklist, Safeguard, and TODO when they do not apply. Summary, Changes, Testing, Merge danger, and Audit are always present. -->
<!-- The body follows the language rule of rules/git/pull-requests.md Pull Requests. -->
