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

<!-- First line: the tracker reference, as skills/resolve-issue/references/pull-request.md states for each tracker. -->
<!-- Evidence line: only on a bug fix. Merge danger: if the script cannot run, write Merge danger: not computed — <reason>. -->
<!-- Pre-existing fixes: only when a pre-existing security fix landed. Security acceptance checklist: only when a pre-implementation security plan was passed into the run. Safeguard: only when the source issue is a prevention proposal. TODO: only when a point was deferred. -->
<!-- Summary, Changes, Testing, Merge danger, and Audit are always present. -->
<!-- The body follows the language rule of rules/git/pull-requests.md Pull Requests. -->
