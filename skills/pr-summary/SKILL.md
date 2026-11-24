---
name: pr-summary
description: "Use when summarizing current PR changes for the development and product team. Analyzes all commits in the current branch, explains what broke and what the fix changes, and produces a human-readable report that can be posted as a GitHub PR comment (Markdown), a JIRA comment (ADF), or a Bugsnag comment (plain text)."
license: MIT
metadata:
  author: "Petr Král (pekral.cz)"
---

## TL;DR

Read the branch's commits and its linked tracker. Write one non-technical comment. Publish it.

- **GitHub and Bugsnag render the same two sections** → `What changed`, then `How to test`.
- **`What changed`** → `Problem`, `Cause`, `Result`, `What I fixed`, plus two conditional fields.
- **JIRA renders one shape on every run** → a deploy verdict paragraph, then `Acceptance criteria` (only for requirements that still need action), `Review findings`, `What changed and what it affects`. An open question closes the bullet it belongs to. Its reader is a product manager, so only requests for changes or answers are listed; a few bullets with no TL;DR label combine observable changes with what to watch after the release. JIRA renders no `How to test`.
- **`How to test`** (GitHub and Bugsnag) → a scenario a human follows: every step names a concrete input and a must-hold outcome.
- **A closing line** links the PR and the source issue.
- Only the delivery format differs per target: GitHub Markdown, JIRA ADF, Bugsnag plain text.
- Prose is terse. Business "why" first, enough technical context to locate the change, nothing more.
- No code snippets, file paths, line numbers, diff fragments.
- Length follows the facts the report carries. There is no word budget on GitHub and Bugsnag; JIRA caps the comment at 3 000 characters.
- Publish through `upsert-comment.sh --create` — every run posts a new comment on GitHub and JIRA and leaves the comment of an earlier run unchanged.

---

## Constraints

### Rules to apply

Each line states what the rule actually decides here, so its relevance is clear without opening it.

- Apply @rules/php/core-standards.md — the package-wide prose and code standards every skill is held to.
- Apply @rules/git/general.md — how the base branch is resolved and what the commit history this skill reads is expected to look like.
- Apply `@skills/agent-voice/SKILL.md` to the prose of every comment this skill publishes, which is the sentence shape of every line this skill authors for a human to read. The length of the report follows *Length follows the facts* below and `@rules/reports/general.md`. Every marker, heading, field, identifier, link, and language rule stated in this skill stays unchanged.
- Apply @rules/jira/general.md when the target is a JIRA issue. The template uses a constrained Wiki Markup source format. The canonical helper converts that source to ADF and publishes it through `--body-adf`.
- Apply @rules/reports/general.md — the published comment is written in the language of the source assignment (Czech assignment → Czech comment; English assignment → English comment). Code identifiers stay verbatim per the rule's *Scope clarifications*.
- If the current project uses Laravel, also apply `@rules/laravel/laravel.md`, `@rules/laravel/architecture.md`, `@rules/laravel/filament.md`, and `@rules/laravel/livewire.md` — they tell the summary what a change to an Action, a Livewire component, or a Filament resource means in business terms.

### What renders where

GitHub and Bugsnag carry the **same** structure and differ only in markup — no target gets a reduced shape, because the report carries nothing a tracker would need to omit. JIRA carries its own section order, for the reason *The JIRA shape* below states.

| Element | GitHub PR comment | JIRA comment | Bugsnag comment |
|---|---|---|---|
| Status line | no | the deploy verdict paragraph opens the comment | no |
| `Acceptance criteria` | no | only for requirements that still need action — first section | no |
| `Review findings` | review-only run only, in place of `How to test` | yes — every run, after any unmet criteria | review-only run only, in place of `How to test` |
| `What changed` | yes — four fields, two conditional | included in `What changed and what it affects` | yes — four fields, two conditional |
| `What changed and what it affects` | no | a few bullets combining changes and deployment impact, at most 600 characters | no |
| `How to test` | yes, except a review-only run | no | yes, except a review-only run |
| `{embedded_blocks}` | conditional — exactly as the wrapper passed them | no — each question closes its criterion or finding bullet | conditional — exactly as the wrapper passed them |
| Closing links line | yes | yes | yes |
| Footer | no | yes — the comment is generated, for the ticket owner | no |
| Length cap | none | 3 000 characters | none |
| Markup | GitHub Markdown | ADF at the API boundary; the template's Wiki Markup is intermediate input only | plain text; no markup at all |
| Template | `templates/pr-summary-github.md` | `templates/pr-summary-jira.md` | `templates/pr-summary-bugsnag.md` |

### The JIRA shape

A JIRA ticket is read by the person who asked for the work, so its comment has its own shape on every run. `references/jira-shape.md` owns that shape, and `templates/pr-summary-jira.md` renders it.

### The section names are translated, the concepts are not

The headings and field labels above are this skill's canonical **concepts**, written in English because the skill itself is. The rendered comment follows the assignment language per `@rules/reports/general.md`, headings and field labels included — a Czech assignment renders `Co se změnilo` / `Problém` / `Příčina` / `Výsledek` / `Co jsem opravil` / `Vedlejší přínos` / `Na jiný ticket` / `Jak otestovat`, and a Czech JIRA ticket renders `Akceptační kritéria` / `Nálezy z kontroly kódu` / `Co se změnilo a co to ovlivní`. Never publish an English heading above assignment-language prose; that is the bilingual mixing the rule bans.

### What changed — four required fields, two conditional ones (GitHub and Bugsnag)

Required, always rendered, in this order. On JIRA this content joins the deployment impact in `What changed and what it affects` instead — see *The JIRA shape* above:

- **Problem** — what the reader's own world looked like when it was broken: who hit it, on what, what they saw instead. Name the scale when it is known (how many attempts, over how long, how many accounts).
- **Cause** — why it happened. One mechanism, stated so a non-developer follows it. This is where a missing or partially met acceptance criterion is named in prose, never as a verdict field.
- **Result** — what the cause actually cost: the number, the runtime, the silent failure it produced. When a failure was silent, say why it was silent.
- **What I fixed** — a bullet list, one bullet per change the reader can observe. Each bullet states the new behaviour and, where a number exists, the before-and-after.

Conditional — render only when it has content, never as an empty field:

- **Side benefit** — an improvement the fix produces that the assignment never asked for (load removed from a shared system, a class of failure that can no longer happen).
- **Filed separately** — a genuinely unrelated defect found during the work and filed on its own ticket. Name the ticket and state that it is linked.

### How to test — a scenario, not a checklist of tests

`How to test` is what a human does, in the application's own domain. It is never a list of automated tests and never a code-level instruction. It renders on GitHub and Bugsnag; a JIRA comment carries none (*The JIRA shape* above).

- Every step names **concrete inputs**: the account, the URL, the entity name, the value typed in.
- Every step states an explicit **must-hold outcome** — what the tester must see for the step to pass.
- Order the steps by what matters. When one step is the important one, say so in that step.
- When the change is reachable only behind a test parameter — a feature flag, an ENV switch, a query-string parameter, a request header, an A/B variant, a beta toggle, or an allow-listed account — the **first** step enables it, naming the exact toggle and the value required. There is no separate metadata line for it on any target; the toggle lives inside the step that enables it.
- Cover the regression too: name the neighbouring flows the tester should exercise to confirm nothing else moved.
- **A review-only run replaces the section.** See *Review-only run* below.
- **Caller-supplied steps win.** When the caller (for example `april` in post-convergence reporting mode) passes pre-authored steps derived from designed test scenarios, use those steps as passed — never compressed, never rewritten. The caller's scenarios are the source of truth for this section.

### An unmet criterion addresses the author

When the assignment verdict names a criterion that is not met or partially met, the comment states why and mentions the author of the changes (`@rules/code-review/general.md` *The author of the changes is the account that pushed the pull request*).

- **State why.** Each unmet criterion names what the change does not do and what the review observed that shows it, in plain prose.
- **Mention the author once, after the unmet criteria, on GitHub and Bugsnag.** One sentence asks the author to confirm the claim and fix the gap, or to refute it in a reply. It follows the embedded blocks. A JIRA comment carries no such sentence (`references/jira-shape.md` item 2).
- **The mention syntax is the tracker's own.**
  1. GitHub: `@<login>` of the pull request's `author.login`.
  2. Bugsnag: the author's name as plain text, because the helper carries no mention.
- **Every criterion met: no mention.** The author stays internal information.

### Review-only run — `Review findings` in place of `How to test`

A review-only run (`/report-code-review`) passes `review-only` together with the findings of the published GitHub pull-request comment. Then:

- **Skip step 7.** Render no `How to test` section on any target. A test-parameter toggle has no step to live in, so it is not rendered either.
- **Render `Review findings` in the position of `How to test`.** The section retells the points of the technical GitHub report that must be resolved, so a non-technical reader knows what to do and can respond to it. A JIRA comment carries the section on every run already, in the shape *The JIRA shape* above defines; the bullets below apply to all three targets.
  - **List only what must be resolved before the merge.** This skill is the one owner of the rule; the wrappers and the templates only point here.
    1. **Two kinds of point are listed:** a defect (a finding of the technical report with evidence that blocks the merge) and an open question that needs an answer.
    2. **Every such point is listed.** Read the whole technical report, and leave out none of the points that must be resolved.
    3. **A point that needs no action is not listed:** a resolved point, a deferred point, and an informational point. This holds on every target. A known point is the one exception, and *Known points* below owns it. The review carries no other out-of-scope point and no follow-up proposal (`@rules/code-review/general.md` *What a code review checks, and nothing else*), so none is listed.
    4. **An assumption, a hypothesis, or an unverified suspicion is never listed** (`@rules/general/general.md` *Verified statements only*). Such a point stays in the technical report, under `## Needs verification` and on the `Not verified:` line. The one exception is on JIRA (*The JIRA shape*): a security point that needs verification and blocks the merge (`@rules/code-review/general.md` S1–S3) is the first `Review findings` bullet, named as a blocker, without the detail of the suspicion.
    5. **Interactive testing that did not run is never listed.** When the walkthrough did not run because no local or test environment was available, the comment says nothing about it on any target: no bullet, no question, and no sentence in another section (`@rules/code-review/general.md` *What a code review checks, and nothing else*).
  - Write one bullet per listed point, as a TL;DR of its solution for a human, backed by evidence (*A finding bullet is a TL;DR of the solution, backed by evidence* below). Plain prose only.
  - Put the points that block the release first, and say in words that they block it. On JIRA the findings follow the type order of `references/jira-shape.md` item 11 instead, and the deploy verdict says what blocks. Never write a severity label.
  - The list in `@rules/reports/general.md` *Never in a JIRA comment* binds this section on every target: no severity labels, finding counts, code names, file paths, or rule references.
  - On GitHub and Bugsnag, end with one sentence that asks the reader to reply in a comment when they disagree with a point or know something that changes it. On JIRA the footer carries that role, so no closing sentence is added.
  - On GitHub and Bugsnag, end the bullet that retells a missing-test or a DRY finding with the author mention; on JIRA only a `(refactor)` or a `(tests)` finding mentions the author, right after its type (`references/jira-shape.md` item 12). The syntax is the one *An unmet criterion addresses the author* above defines (`@rules/code-review/general.md` *The author of the changes is the account that pushed the pull request* → *Second exception*).
  - When the review has no point to resolve, render one sentence that says so, and no bullets.
- **A finding bullet is a TL;DR of the solution, backed by evidence.** This skill is the one owner of the rule; the wrappers and the templates only point here. The reader decides on the bullet, so the bullet carries data and never an assumption.
  1. **Three statements per bullet:** what is wrong, what the fix is, and what the fix changes for the user or the business, each in plain language. Retell the fix from the finding's suggested fix, or from its expected behaviour when no fix snippet exists.
  2. **Every bullet names its evidence in plain words:** the observed value, the reproduced behaviour, the failing or passing test, the failing analyser check, the traced case, the written rule, or the query result. Example: *an order created without a customer fails.* The words follow the technical report's `Evidence` value and describe the behaviour, never a test name, a file, or a count.
  3. **The caller passes the evidence, and this skill never derives it.** The caller passes each finding with its `Evidence` value, its `Expected behavior`, and its suggested fix (`@skills/code-review-github/references/cr-wrapper-contract.md`). This skill reads no diff and runs no check to fill a gap.
  4. **A point without evidence is never listed.** A finding that arrives with no `Evidence`, and every point the review reported as not verified, is left out of this section. The caller still passes them, and this skill renders no heading for them.
  5. **The caps stay.** The bullets add no file path, code name, severity label, count, or test name. On JIRA the 3 000-character cap applies as above (item 8 below), and a listed point is never dropped, because the reader decides on it.
  6. **A clean review keeps its one sentence.** When the review has no point to resolve, render the sentence that says so, with no TL;DR.
  7. **The rule covers the findings of a code review only.** An open decision that `@skills/verify-merge-readiness/SKILL.md` or `@agents/april.md` passes keeps the question-and-recommendation shape its caller defines. It carries no `Evidence`, and it is listed as an open question.
  8. **When a JIRA body still overflows 3 000 characters, shorten each finding bullet to two sentences:** what is wrong with its evidence, and the fix with what it changes.
- **Group the findings by provenance.** This skill is the one owner of the grouping; the rule and the wrappers only point here. The caller passes each finding with its `Provenance` value (`@rules/code-review/general.md` *Every finding declares its provenance*).
  1. **New in this review** — `regression — introduced in this revision` and `pre-existing — untouched by this revision`. Render this group first, without a heading of its own.
  2. **Already reported in an earlier review** — `pre-existing — carried from round N`. Render it under its own heading, and only when it has a finding.
  - The grouping is plain language only. It adds no round number, no provenance value, no severity label, and no count. Order and blocking stay as above inside each group, and the section still carries every finding.
- **An answered finding names its answer in one sentence.** A finding in the second group that carries an `Answered` reference (`@rules/code-review/general.md` *An answered finding stays in the report — the answer is shown, never applied*) ends with one sentence: when the answer was recorded, where, and that the point still blocks the release when it does.
  - The sentence never quotes or paraphrases the answering comment, and it names no account with a mention: in backticks on GitHub, as plain text on JIRA and Bugsnag, never as `[~accountid:]`.
  - It links the answer only where the target allows a link. On JIRA, a JIRA answer is linked, under exception 3 of `@rules/reports/general.md` *Three exceptions, and there is no fourth*; a GitHub answer is named in words only. On GitHub, the URL from the `Answered` reference is linked. On Bugsnag, the URL is written as plain text.
  - **Restricted content never reaches a public mirror.** The caller passes the `github_allowed` value that `skills/_shared/review-carry-forward.sh answer` printed with each answer. The script reads the repository visibility from the GitHub loader's `repo.isPrivate`, and a repository whose visibility cannot be read counts as public. On a GitHub target, an answer whose `github_allowed` is `false` gets no link and no words: the finding is rendered without the answer sentence.
- **A new comment on every run.** A review-only run publishes through the GitHub and JIRA helpers like any other run, with `--create`, so a later run adds a new comment and leaves the comment of an earlier run unchanged. The Bugsnag helper posts a new comment on every run.
- **Length on JIRA.** When the comment overflows the 3 000-character cap, shorten the wording of `What changed and what it affects` first, keeping each of its facts, then shorten the findings per item 8 above. Never drop a `Review findings` bullet.

### Known points

A known point is a finding that a linked issue already reports (`@rules/code-review/review-process.md` *Known points — a finding that a linked issue already reports (issue #682)*). This skill is the one owner of how the tracker comment shows it; the templates only point here.

- **The caller passes each known point** with its link and its one sentence on what the linked issue covers, from the `known_points` of the loop state. The review does not count it, so the comment never counts it either.
- **A security known point shows only its link, its owner, and one neutral sentence:** the linked issue already tracks it, and the owner it names owns it there. It carries no title, no place, and nothing of what the issue covers, on every target, because the point merges unfixed (`@rules/code-review/review-process.md` *Known points*). The caller passes it as such.
- **New findings come first, then the known points.** On GitHub and Bugsnag the section `Already reported in a linked issue` follows `How to test`, or `Review findings` in a review-only run. On JIRA it is the group `Already reported in a linked issue` of `Review findings`, after every new finding and after the group of earlier findings. Render the section or the group only when there is a known point.
- **One bullet per point, in plain language:** what the point is, what the linked issue covers, and the link of the issue. The link is the one of exception 3 in `@rules/reports/general.md` *Three exceptions, and there is no fourth*. On Bugsnag the URL is plain text.
- **A known point is no point to resolve.** It carries no severity label and no count, and it never makes the comment say that the release is blocked. A review with no new finding still renders the sentence that says there is no point to resolve, and then the known points.
- **Length on JIRA.** A known point is shortened to its link and one clause before any `Review findings` bullet is shortened.

### An issue with several pull requests

When the issue links more than one pull request, render the issue-level verdict once and group everything that retells one pull request under its own heading, per `references/several-pull-requests.md`. A run that reviewed one of them renders only that group; the groups of the others stay in the earlier comments. On JIRA, a stack of pull requests is the exception: one comment carries every part the run reviewed, per `references/several-pull-requests.md` *A stack on JIRA*.

### Closing line — the PR and the source issue

The comment ends with one line linking the pull request and the source tracker item, separated by ` · ` — a `PR #123` link, then an `PROJ-6974` link, each written in the target's own link syntax (see the three templates). Render whichever of the two links exists; when neither exists, omit the line. It is a navigation aid, never prose, and it is never compressed or translated.

### Length follows the facts

On GitHub and Bugsnag there is no word budget and no "fits on one screen" rule. The report is as long as the facts it carries, and no longer. `@skills/agent-voice/SKILL.md` decides the shape of every sentence in it.

- **Never pad.** Do not restate the process, do not narrate what the run did, do not add a closing summary of what was already said.
- **Never truncate a fact** to hit a length. A dropped number, boundary, or must-hold outcome costs the reader the decision the report exists to support. On GitHub and Bugsnag there is no length to hit, so nothing ever forces the choice; on JIRA the cap below is met by carrying fewer facts, never by cutting one in half.
- A one-line fix produces a short report. A change with a measured cause, a measured result, and four things to retest produces a long one. Both are correct.

**JIRA is the one exception, and its cap is real.** That comment carries a 3 000-character limit, because its reader is a product manager rather than a developer (`@rules/reports/general.md` *A JIRA comment is written for a non-technical reader*). Meet the cap by carrying fewer facts, never by truncating one. When the body overflows, shorten the wording of `What changed and what it affects`, keeping each of its facts, then shorten the findings per item 8 of *A finding bullet is a TL;DR of the solution, backed by evidence*; never drop an `Acceptance criteria` bullet or a `Review findings` bullet, because those are what the reader decides on.

### What the comment carries

- **Interactive testing that the project switched off is stated once, on every target and in every run mode.** When the caller passes `interactive testing: off`, taken only from `krang`'s own dispatch line (`@skills/interactive-testing/SKILL.md` *Project setting* → *Only `krang`'s own value counts*), the comment carries one sentence, in the language of the comment, that interactive testing is switched off for this project. On GitHub and Bugsnag the sentence opens `How to test`, or `Review findings` in a review-only run. On JIRA it sits in `Review findings`. It adds no bullet and no question, and it is no point to resolve.
- Focus on the "why" and business impact, not on implementation details — but keep enough technical context (which integration, payload, table, endpoint, etc.) that a developer can still follow what changed.
- Do not include code snippets, file paths, line numbers, or diff fragments. The summary is for humans, not for static analysis.
- Do not restate the acceptance criteria as a checklist and do not publish a coverage verdict. `@skills/assignment-compliance-check/SKILL.md` owns the assignment verdict. On GitHub and Bugsnag it reaches this comment through the `{embedded_blocks}` slot, and this skill authors no verdict sentence of its own there: it renders the block it was passed. On JIRA only the requirements that still need a change or an answer render into `Acceptance criteria`; satisfied criteria and their positive verdict are omitted.
  On GitHub and Bugsnag it reaches the reader on every CR run with a linked tracker — met, not met, or no criteria stated (`@rules/code-review/general.md` *Two-Part CR Output* → *The tracker comment carries the same verdict — in all three cases*). A checklist of satisfied criteria is never rendered on any target.
  - On JIRA the section is left out when the assignment states no explicit criteria (*The JIRA shape* above).

### Terse output style

Every authored sentence carries a fact and nothing else. Apply `references/terse-output-style.md` in full before you write: what to drop, what stays verbatim, and the auto-clarity carve-outs (`Cause`, `Result`, security warnings, must-hold outcomes).

### Output shape per target

- GitHub and Bugsnag output the **two sections plus the closing line**: `What changed` (four required fields, two conditional), `How to test`, the conditional `Already reported in a linked issue` (*Known points* above), then the PR / issue links. JIRA outputs the shape `references/jira-shape.md` owns.
- No target renders an `Authors` line, an `Available behind` line, a `Summary of changes` section, a categories list, a breaking-changes section, or a testing-notes section. This skill resolves no authorship — a caller that needs an author set resolves it itself.
- Between GitHub and Bugsnag the only difference is markup and template. JIRA differs in section order and content too, per *The JIRA shape* above.

### No leaked markup on JIRA

The JIRA body uses only the helper's supported Wiki Markup subset, and the reader never sees a raw `**` or `#`. Before publishing, convert or reject every leaked Markdown construct per `references/markup-per-target.md` and `@rules/jira/general.md`.

### No markup at all on Bugsnag

Bugsnag renders plain text: no headings, bold, inline code, or bracket links. `references/markup-per-target.md` defines the shape.

### Embedded blocks (consolidation contract — issue #498)

On GitHub and Bugsnag, when the calling CR wrapper passes extra markdown blocks (the `Clarifying questions` block and/or the `Assignment Compliance` block returned by `@skills/assignment-compliance-check/SKILL.md`), append them **verbatim** after `How to test` and **before** the closing links line.

- **On JIRA there is no slot.** The `Assignment Compliance` result contributes only requirements that still need action to the `Acceptance criteria` section, and each `Clarifying questions` entry becomes the closing sentence of the criterion or finding bullet it belongs to (*The JIRA shape* above). The caller passes each question together with the criterion or the finding it concerns. A merge-preparation run passes its JIRA questions the same way (`@skills/verify-merge-readiness/SKILL.md` step 4). Only its requirements that still need action render into the `Acceptance criteria` section instead of travelling through the slot, per *The JIRA shape* above.
- Each embedded block must already use the target tracker's source format (GitHub Markdown for GitHub, the supported intermediate Wiki Markup subset for JIRA, plain text for Bugsnag), and carries only the part above its `=== END OF COMMENT BODY` line.
- The resulting comment is published once per linked tracker target — that single consolidated comment is the only non-technical artifact a CR run posts on each linked issue, JIRA ticket, or Bugsnag error.
- When no embedded blocks are passed, the template renders without that slot. This is the shape of a non-CR invocation, never of a clean CR result. `april` in post-convergence reporting mode passes the review's `Assignment Compliance` block (`agents/krang.md` step 6a).
- On GitHub and Bugsnag this slot is how the assignment verdict reaches the reader, and on a CR run it carries that verdict on **every** run with a linked tracker — the affirmative one included; on JIRA only requirements that still need action are published, and the full verdict remains on the pull request. This skill authors no verdict, no banner, and no "satisfies the assignment" sentence of its own — it renders the passed block, filtered for the target as *The JIRA shape* defines.

---

## Steps

Eight numbered steps, three independent jobs. The headings below are the jobs; the numbering runs continuously so a step can still be cited by number.

### Load context (1–4)

1. Identify the current branch and its base branch (usually `master` or `main`).
2. Load all commits in the current branch since it diverged from the base branch (`git log base..HEAD`).
3. For each commit, read the commit message and the diff to understand what changed and why.
4. If a PR already exists for this branch, load the PR description and linked issue(s) for additional context (business motivation, acceptance criteria, reporter's expectations):
   - **GitHub:** `skills/code-review-github/scripts/load-issue.sh <URL>` — always the full GitHub URL, never a bare number (the loader rejects it); read `body`, `comments[]`, and `closingIssues[]` off the resulting JSON document.
   - **JIRA:** `skills/code-review-jira/scripts/load-issue.sh <KEY|URL>` — read `descriptionText`, `comments[]`, and linked PRs. Also load the pull request through `skills/code-review-github/scripts/load-issue.sh <PR_URL>` and read the newest trusted code-review report through `skills/_shared/cr-header.sh --report <loader-file>` (the hidden record of a pull-request review, or a legacy `cr-comment` comment): it is the source of the JIRA `Review findings` section, and its `## Affected behaviour outside the diff` section feeds `What changed and what it affects`.
   - **Bugsnag:** `skills/code-review-bugsnag/scripts/load-issue.sh <URL|TRIPLE>` — read the error context, its `comments[]`, and its linked issues / PRs.
   - Never call `gh pr view`, `gh issue view`, or `acli` directly; fall back to the GitHub / JIRA / Bugsnag MCP server only when the loader is unavailable (exit code 2/3).

### Decide the target (5)

5. Detect the **target tracker** for the comment by following the table in `@skills/resolve-issue/references/source-detection.md` (branch name / PR description / linked issue trail):
   - **JIRA** — the branch or PR description matches a JIRA issue-key regex (e.g. `^[A-Z][A-Z0-9_]+-\d+$`), or the JIRA loader from step 4 returns a non-empty document. Use `templates/pr-summary-jira.md` as intermediate source for the ADF publisher.
   - **Bugsnag** — the caller named a Bugsnag error URL or `organization/project/error` triple, or the Bugsnag loader from step 4 returns a non-empty document. Use `templates/pr-summary-bugsnag.md` (plain text).
   - **GitHub** — otherwise, or when the user explicitly asks for a PR comment. Use `templates/pr-summary-github.md` (GitHub Markdown).
   - If several signals match (a cross-tracker PR), prefer the tracker named in the user's invocation. Absent that, prefer the tracker the assignment itself came from, so the reporter reads the answer where they asked the question.

### Write and publish (6–8)

6. **Write the body sections.** On GitHub and Bugsnag, write `What changed` — the four required fields in order (`Problem`, `Cause`, `Result`, `What I fixed`), then the two conditional ones (`Side benefit`, `Filed separately`) only when each has content. On JIRA, write the deploy verdict paragraph first, then `Acceptance criteria` only for requirements that still need action, then `Review findings` from the newest pull-request review per *The JIRA shape*, then `What changed and what it affects` as a few bullets combining observable changes and deployment impact. Derive every field from the commits, the diff, and the tracker context loaded in steps 1–4. State numbers where the run measured them; never invent one.
7. **Write `How to test`** (GitHub and Bugsnag; never on JIRA) — an ordered scenario per *How to test* above: concrete inputs, an explicit must-hold outcome per step, the gating toggle enabled in step 1 when the change is gated, and the regression flows named at the end. Use caller-supplied steps as passed when the caller provided them.
8. **Assemble and render.** On GitHub and Bugsnag, place the embedded blocks, when the caller passed any, directly above the closing links line, separated by a single blank line — each rendered exactly as received, with no re-formatting, no language conversion, and no re-ordering. On JIRA, write each open question as the closing sentence of the criterion or finding bullet it belongs to. Close with the PR / issue links line. Translate the headings and field labels into the assignment language, then convert the whole body to the target's markup and verify nothing leaked. On JIRA, close with the footer, measure the published body against the 3 000-character cap, and shorten it per *Length follows the facts* when it overflows, never an `Acceptance criteria` or a `Review findings` bullet.

---

## Output format

- **GitHub PR comments** — `templates/pr-summary-github.md`, in GitHub Markdown.
- **JIRA issue comments** — `templates/pr-summary-jira.md`, converted to ADF by the JIRA helper. Never pass the intermediate Wiki Markup directly to `acli` or the JIRA MCP server.
- **Bugsnag error comments** — `templates/pr-summary-bugsnag.md`, in plain text.

GitHub and Bugsnag carry the same two sections and the same closing line, differing only in markup. JIRA carries its own sections, its own order, and its own length cap, per *The JIRA shape* above.

---

## Publishing

**A GitHub target in a foreign repository can restrict AI text.** Before the first publish to a GitHub pull request or issue, run `skills/_shared/contribution-policy.sh --base <owner/repo of the target> [--head <owner/repo of the branch> | --fork]`. On `repository=own`, or on `ai-policy=none`, publish as below. On every other result, apply `@rules/git/pull-requests.md` *Foreign repository with a contribution policy — a human writes the description*: publish nothing in that repository and return the rendered body to the caller with the policy source, so it reaches the user in the final report. A JIRA or Bugsnag target is unchanged.

Post the summary as a comment to the related PR or issue if available, using the template that matches the target tracker. Publish through the shared helpers so each tracker receives its tracker-native markup — never via raw `gh issue comment` / `gh pr comment` / `acli jira workitem comment add` calls.

- **GitHub target** (PR comment or linked-GitHub-issue mirror): pipe the rendered body into `skills/code-review-github/scripts/upsert-comment.sh --create <NUMBER|URL> -`. The helper detects the current GitHub actor (`gh api user --jq .login`), appends the marker `<!-- cr-comment:actor=<gh-login> -->`, and **POSTs a new comment**; it never PATCHes the comment of an earlier run. Fall back to the GitHub MCP server only when the helper exits with code 2 (missing tool) or 3 (API failure), and only when the read-back of `@rules/code-review/general.md` *A retry identifies the comment of this run* finds no comment of this run: `addIssueComment`, never `updateIssueComment`.
  Append the same `<!-- cr-comment:actor=<gh-login> -->` marker to the body on either fallback call, per `@rules/compound-engineering/tracker.md` *Every comment an agent publishes on GitHub or JIRA carries a marker*.
- **Check a JIRA source before the publish.** Write the rendered source to a file and run `php skills/pr-summary/scripts/check-jira-comment.php --criteria <unmet|none> --author <account-id|none> --repository <owner/name> --pull-request <number> --jira-issue <issue-url> --revision <commit> <source-file>`.
  - `--criteria unmet` when an explicit requirement still needs a change or an answer, and `none` otherwise, including when every criterion is satisfied.
  - `--author` with the account ID of the confirmed person match (`references/jira-shape.md` item 12), or `none` without one. In an orchestrated run the publishing agent takes it only from `krang`'s own dispatch line `jira author account: <id|none>`.
  - `--repository` and one `--pull-request` per pull request, from the validated pull-request URLs of `references/jira-shape.md` item 13: the pull request of the task and each pull request a finding links to.
  - `--jira-issue` with the `url` of the JIRA issue from the loader.
  - `--revision` with the `Reviewed revision:` of the code-review report, the commit `pr-code-link.php` takes for a finding outside the pull request. Without it the check refuses every link to a file at a commit.
  - The check converts the source with the converter the publish uses. It refuses every link outside those pull requests, the task repository at that one commit, and the issues of that JIRA site, every code link that does not end a finding, and every mention of another account.
  - Exit `0` means the source passed. Exit `2` lists each violation of the shape `references/jira-shape.md` owns; the template names every check. Fix the source and run the check again. Never publish a source the check refused.
- **JIRA target**: pipe the rendered source into `skills/code-review-jira/scripts/upsert-comment.sh --create <KEY|URL> -`. The helper writes the marker line `_cr-comment:actor=<actor-digest>_` (a digest of the account e-mail, never the address) in place of the template's `{agent-marker}` slot, inside the collapsed marker block, converts the source to ADF, creates a new comment, and applies the ADF to that new comment through `--body-adf`; it never updates the comment of an earlier run.
  Fall back to the JIRA MCP server only when the helper exits with code 2 (missing tool) or 3 (API failure), and only when the read-back of that same rule finds no comment of this run, creating a new comment with an ADF document rather than Wiki Markup — put the same `_cr-comment:actor=<actor-digest>_` marker line into the marker block of that ADF, per the same rule, and read the stored comment back for that marker as `@rules/jira/general.md` states.
- **Bugsnag target**: pipe the rendered body into `skills/code-review-bugsnag/scripts/upsert-comment.sh <URL|TRIPLE> -`. The helper POSTs a new comment on every run. Fall back to the Bugsnag MCP server only when the helper exits with code 2 (missing tool) or 3 (API failure) — also as a fresh post.
- Comments that earlier runs published are left untouched.
- Log the action (`created`) plus the resulting comment URL in the CR wrapper's summary line.

---

## Principles

- Explain what broke, why it broke, and what the reader can now observe instead
- Focus on business impact, not technical detail — but keep enough "what" that a developer can locate the change without reading the diff
- Every sentence carries a fact; the report is as long as its facts and no longer
- Make the test scenario reproducible by a non-developer tester, with concrete inputs and explicit must-hold outcomes
- Match the formatting to the target tracker (Markdown for GitHub, ADF for JIRA, plain text for Bugsnag)

## Output Humanization
- Use [blader/humanizer](https://github.com/blader/humanizer) for all skill outputs to keep the text natural and human-friendly.
