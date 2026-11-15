Closes #<N>

## Merge risk <🟢 low | 🟡 medium | 🔴 high>

**Door:** <one-way | two-way> <rollback signals with their files, or no rollback signal>
**Blast radius:** <tier> (<signal names>)

## Summary

* **New Features**
  * <one bullet per behaviour the change adds>
* **Bug Fixes**
  * <one bullet per defect the change fixes>
  * Pre-existing security fix: `<fix commit subject>` — <one-line rationale>
* **Documentation**
  * <one bullet per documentation change>

<one or two sentences: what stays unchanged, and the pull request this one replaces, when it replaces one>

## Open decisions for the operator

1. **<topic>:** <the choice made without a fact, and the step that confirms it>

<details>
<summary><strong>Walkthrough</strong></summary>

|Layer / File(s)|Summary|
|:---|:---|
|**<layer>** <br> `<file>`, `<file>`|<one row per in-scope change, in the assignment's own wording>|

</details>

<details>
<summary><strong>✅ Testing</strong></summary>

<how to test>
Evidence: <regression test that failed before the fix and passes after it, with the command that ran it>

</details>

## Security acceptance checklist
- [x] [Critical] <item> — <file:line or test name>
- [ ] [Moderate] <item> — <pointer>
Plan: <plan issue link>

## Safeguard
<proposal link and key, original findings, level and limitation, the four proofs with commands and results>

## TODO
- [ ] <deferred point> — filed as <follow-up issue URL>

<details>
<summary><strong>Audit</strong></summary>

<transcript of the .claude/run/<source-slug>.audit ledger, with its declared incompleteness>

</details>

<!-- First line: the tracker reference, as skills/resolve-issue/references/pull-request.md states for each tracker. -->
<!-- Merge risk heading: filled as the Merge risk bullet of skills/resolve-issue/references/pull-request.md states. -->
<!-- Summary: a TL;DR for a reader who opens only this section. Each bullet is one or two short sentences in plain words on what changes for the user of the project, never which file changed. A category only when it has a bullet; Tests, Chores, and Refactoring follow the same shape. The pre-existing bullet only when a pre-existing security fix landed. -->
<!-- Evidence line: only on a bug fix. The pull request this one replaces: only when it replaces one. Open decisions: only when the run made a choice without a fact. Security acceptance checklist: only when a pre-implementation security plan was passed into the run. Safeguard: only when the source issue is a prevention proposal. TODO: only when a point was deferred. -->
<!-- Merge risk, Summary, Walkthrough, Testing, and Audit are always present. -->
<!-- The body follows the language rule of rules/git/pull-requests.md Pull Requests. -->
<!-- The whole body is at most 6,500 characters (rules/git/pull-requests.md PR Content Requirements): shorten the wording, never drop a section. -->
