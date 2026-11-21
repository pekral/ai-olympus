h2. Acceptance criteria

[Render this heading and everything under it only for explicit requirements that still need a change or an answer. Omit satisfied requirements, positive verdicts, and completion counts.]

* [Only a criterion that is NOT met, is partially met, or needs a human to confirm it. What is missing and why the review judged it so, in plain prose. When a question for a human is open, it is the closing sentence of this bullet.]

[Only when a criterion is not met or partially met: one sentence mentioning the author of the changes as [~accountid:<id>], asking them to confirm and fix the gap, or refute the claim in a reply.]

h2. Review findings

* [One point that must be resolved before the merge, per bullet, as a TL;DR of its solution: what is wrong, what the fix is, and what the fix changes for the user or the business. Name the evidence in plain words, for example "an order created without a customer fails". A point that blocks the merge comes first and says so in words. A security point that still needs verification and blocks the merge is the first bullet, named as a blocker without the detail of the suspicion.]
* [Next point. Only a defect or an open question that needs an answer; never an assumption, a hypothesis, or a point that needs no action. A question that concerns no criterion is the closing sentence of the point it belongs to.]

h3. Already reported in an earlier review

* [Only when a finding was carried over from an earlier review. Same bullet shape. A finding with a recorded answer ends with one sentence that says when and where the answer was recorded, and that the point still blocks the merge when it does.]

h2. TL;DR

[One paragraph of at most 600 characters combining observable changes and deployment impact: what behaves differently, which features it affects, and what a user should watch after release.]

[PR #123|PR_URL] · [ISSUE-KEY|ISSUE_URL]

----

_This comment is generated automatically. It is written for the person who owns this ticket, not for a developer._

=== END OF COMMENT BODY — NOTHING BELOW THIS LINE IS EVER PUBLISHED ===

TEMPLATE GUIDANCE. The published comment ends at the line above. The intermediate
source format has no comment syntax, so this guidance cannot be hidden the way the GitHub
template hides its own in an HTML comment — the boundary is this marker line, and
it is structural, not decorative. Written as plain text on purpose: the Wiki
Markup quote macro is functional markup, so
guidance wrapped in one renders as a real, official-looking quotation if it ever
reaches the published body - which is exactly how meta-instructions leak
unnoticed. That macro's name is deliberately not written out anywhere in this
file: a single unpaired opening token would swallow everything after it.

Who reads this comment
  A product manager, not a developer. @rules/reports/general.md ("A JIRA comment
  is written for a non-technical reader") is binding on every line above the
  marker: it lists the content that never appears here, its exceptions, and
  the 3 000-character cap. Read it before filling this template in. The
  technical evidence a reviewer or a merge gate needs is not lost — it lives on
  the GitHub pull-request comment, which is where @skills/merge-github-pr reads
  it.

Section order is binding, and it is the same on every run
  Acceptance criteria (only for requirements that still need action),
  then Review findings, then TL;DR, then
  the closing links line and the footer. The comment opens with its first
  heading. There is no separate
  section for questions: each open question sits in the bullet it belongs to. A review-only run, a code-review mirror,
  the post-convergence report, and the merge-readiness TL;DR all render this
  one shape. The requests for changes come first so the reader knows what remains. A JIRA comment carries no How to test section: the
  steps a tester follows live on the GitHub pull request, whose description
  carries them (@rules/git/pull-requests.md, Testing). This order
  is JIRA's own; the GitHub and Bugsnag templates keep their What changed /
  How to test shape.

No status line
  No sentence stands above the first heading: not the state of the work, not
  whether the change is ready to merge, and not that nothing was merged or
  deployed. A point that blocks the merge is a Review findings bullet.

Acceptance criteria
  Rendered only for explicit requirements that are unmet, partially met, or
  need an answer before acceptance. Each bullet states the remaining change
  or answer and its evidence. Omit satisfied requirements, positive verdicts,
  and completion counts throughout the report. When every requirement is
  satisfied, omit the whole section. Omit it too when no criteria are stated.
  An unmet or partially met criterion states why, and one closing sentence
  mentions the author of the changes (@skills/pr-summary/SKILL.md, An unmet
  criterion addresses the author).
  The caller passes the full assignment verdict internally; only its requests
  for changes render here. The pull request retains its functional verdict.

Questions
  A JIRA comment has no Clarifying questions section. The caller passes each
  open question together with what it concerns, and the question becomes the
  closing sentence of one bullet:
  - A question about an acceptance criterion closes that criterion's bullet in
    Acceptance criteria. The criterion is then reported as unmet, partially met,
    or awaiting a human's confirmation, even when the code meets its wording.
  - A question that concerns no criterion — a documentation mismatch, a blocking
    documentation request, a Critical decision outside the assignment, or any
    question on a ticket that states no explicit criteria — closes the Review
    findings bullet it belongs to.
  A question may carry one recommendation sentence after it. Never render a
  question twice, and never as a bullet of its own.

Review findings
  A plain-language retelling of the newest GitHub pull-request review, so the
  reader understands what the review reported and can respond to it.
  - When the issue links more than one pull request, Review findings and TL;DR sit in one h2. group per pull
    request, below any Acceptance criteria, and their headings drop to h3.
    (references/several-pull-requests.md).
  - One bullet per finding, as a TL;DR of its solution backed by evidence: what
    is wrong, what the fix is, what the fix changes for the user or the
    business, and the evidence in plain words (an observed value, a reproduced
    behaviour, a failing or passing test, a query result). Plain prose only.
  - Only a point that must be resolved before the merge is listed: a defect
    or an open question that needs an answer. Every such point of the technical
    review is listed. A point that needs no action (resolved, deferred,
    informational) is not. In a partially resolved finding, list only the
    remaining action; omit repaired parts and their history. An assumption, a hypothesis, or a point with no
    evidence is never listed. The rule is owned by @skills/pr-summary/SKILL.md
    (Review-only run).
  - A point that blocks the merge comes first and says in words that it blocks
    the merge. Never write a severity label.
  - Interactive testing that did not run because no local or test environment
    was available is never mentioned, in this section or in any other
    (@rules/code-review/general.md, What a code review checks, and nothing
    else).
  - The review carries no out-of-scope point and no follow-up proposal
    (@rules/code-review/general.md, What a code review checks, and nothing
    else), so none is listed.
  - When the review has no point to resolve, or no review has run yet, render
    one sentence that says so, and no bullets.
  - The findings new in this review come first. The heading "Already reported
    in an earlier review" renders only when that group has a finding. The two
    groups and the answer sentence are owned by @skills/pr-summary/SKILL.md
    (Review-only run); never add a round number, a provenance value, or a
    count.

TL;DR
  One paragraph of at most 600 characters combining observable changes and
  deployment impact. State the affected features and what a user should watch
  after release in connected prose, with no list or repeated facts. Use
  before -> after where useful. Keep mechanisms on the pull request.
  Source: the features the diff changes, plus the newest review's "Affected
  behaviour outside the diff" (@rules/code-review/core-analysis.md).
  When no code-review comment exists for the current head yet, derive the
  parts from the diff and say the review has not confirmed them.
  When the review comment carries no "Affected behaviour outside the diff",
  the review found no affected part outside the diff: include only features
  the diff changes, with no caveat.

Headings and field labels
  Translate them into the assignment language per @rules/reports/general.md — a
  Czech assignment renders "h2. Akceptační kritéria", "h2. Nálezy z kontroly
  kódu", "h2. TL;DR", and
  the footer "Tento
  komentář je generovaný automaticky. Je psaný pro vlastníka ticketu, ne pro
  vývojáře." Never mix an English heading with assignment-language prose.

Length
  3 000 characters, counted over the published body. When the comment overflows,
  shorten the combined TL;DR paragraph first,
  then write each Review findings bullet in two sentences —
  never drop an Acceptance criteria bullet or a Review findings bullet. @rules/reports/general.md owns this cap, and
  @skills/pr-summary/SKILL.md *Length follows the facts* names JIRA as the
  one target it applies to.

Check before the publish
  Run php skills/pr-summary/scripts/check-jira-comment.php --criteria
  <unmet|none> <source-file> on the rendered source, and publish only a source
  it accepts (@skills/pr-summary/SKILL.md, Publishing). The script checks the
  cap, the missing status line, the criteria section against the flag, the
  single TL;DR paragraph of at most 600 characters, leaked Markdown, code macros, commit hashes, and the footer.

No embedded blocks
  The JIRA template has no {embedded_blocks} slot. The Assignment Compliance
  result supplies only the requirements that still need action in Acceptance
  criteria; satisfied criteria and positive verdicts are omitted. Every open
  question sits in a bullet per Questions above.

  Do not add an Authors line, an Available behind line, a Summary of changes
  section, severity counts, file paths, line numbers, or code snippets — none of
  those belongs on any target this skill publishes to.

Closing links line
  One line, the pull request and the source tracker item, separated by " · ", in
  intermediate link form ([label|url]). Render whichever of the two links exists;
  omit the line when neither does. It sits above the footer separator. This is
  the one link the comment carries, and it is navigation rather than a technical
  note.

The canonical statement of every rule above lives in
@skills/pr-summary/SKILL.md. This block restates the slot mechanics for whoever
is filling the template in; the skill is the source of truth if the two ever
disagree.
