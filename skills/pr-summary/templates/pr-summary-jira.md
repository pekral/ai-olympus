[Status line: one to three short sentences — the state of the work, whether the change is ready to merge, and that nothing was merged or deployed. Example: "Code review is done. The change is not ready to merge yet. Nothing was merged or deployed."]

h2. Acceptance criteria

[One sentence carrying the verdict: "All N criteria are met and an automated test verifies each of them." or "N of M met." or, when the assignment states no explicit criteria, the basis the state was judged on.]

* [Only a criterion that is NOT met, is partially met, or needs a human to confirm it. What is missing and why the review judged it so, in plain prose. When a question for a human is open, it is the closing sentence of this bullet.]

[Only when a criterion is not met or partially met: one sentence mentioning the author of the changes as [~accountid:<id>], asking them to confirm and fix the gap, or refute the claim in a reply.]

h2. Review findings

* [One point that must be resolved before the merge, per bullet, as a TL;DR of its solution: what is wrong, what the fix is, and what the fix changes for the user or the business. Name the evidence in plain words, for example "an order created without a customer fails". A point that blocks the merge comes first and says so in words.]
* [Next point. Only a defect or an open question that needs an answer; never an assumption, a hypothesis, or a point that needs no action. A finding outside the assignment appears only when it is Critical. A question that concerns no criterion is the closing sentence of the point it belongs to.]

h3. Already reported in an earlier review

* [Only when a finding was carried over from an earlier review. Same bullet shape. A finding with a recorded answer ends with one sentence that says when and where the answer was recorded, and that the point still blocks the merge when it does.]

h2. What changed

* [One observable change in behaviour. Where a before exists: before -> after.]
* [Next. Three to five bullets in total.]

h2. Impact after deployment

* [One part of the application a production deployment of this change can affect: the feature in plain language, and what a user there may notice — or that nothing visible should change.]
* [Next part. At most five bullets, one feature per bullet.]

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
  Status line, then Acceptance criteria, then Review findings, then What
  changed, then Impact after deployment, then the closing links line and the
  footer. There is no separate
  section for questions: each open question sits in the bullet it belongs to. A review-only run, a code-review mirror,
  the post-convergence report, and the merge-readiness TL;DR all render this
  one shape. The verdict comes first because it is the one thing the reader
  opens the ticket for. A JIRA comment carries no How to test section: the
  steps a tester follows live on the GitHub pull request, whose description
  carries them (@rules/git/pull-requests.md, Testing). This order
  is JIRA's own; the GitHub and Bugsnag templates keep their What changed /
  How to test shape.

Status line
  One to three short sentences, above the first heading. It states the state of
  the work, whether the change is ready to merge or what blocks it, and whether
  anything was merged or deployed. Never a heading of its own, and never an
  English headline above assignment-language prose.

Acceptance criteria
  One verdict sentence, then a bullet only for a criterion that is unmet,
  partially met, or awaiting a human's confirmation. Satisfied criteria are
  never enumerated one by one — the verdict is the whole report for them.
  An unmet or partially met criterion states why, and one closing sentence
  mentions the author of the changes (@skills/pr-summary/SKILL.md, An unmet
  criterion addresses the author). When
  the assignment states no explicit criteria, say so and name the basis judged
  instead (the described expected behaviour, the reporter's example, the
  reproduction steps), so "no criteria" never reads as "nobody checked".
  This section is the only route the assignment verdict takes into this comment,
  and a CR run with a linked tracker always carries it — met, not met, or no
  criteria stated (@rules/code-review/general.md, Two-Part CR Output, "The
  tracker comment carries the same verdict — in all three cases"). The
  @skills/assignment-compliance-check/SKILL.md result is what fills it; on JIRA
  that result is rendered into this section rather than appended as an embedded
  block.

Questions
  A JIRA comment has no Clarifying questions section. The caller passes each
  open question together with what it concerns, and the question becomes the
  closing sentence of one bullet:
  - A question about an acceptance criterion closes that criterion's bullet in
    Acceptance criteria. The criterion is then reported as unmet, partially met,
    or awaiting a human's confirmation, even when the code meets its wording.
  - A question that concerns no criterion — a documentation mismatch, a blocking
    documentation request, a Critical decision outside the assignment — closes
    the Review findings bullet it belongs to.
  A question may carry one recommendation sentence after it. Never render a
  question twice, and never as a bullet of its own.

Review findings
  A plain-language retelling of the newest GitHub pull-request review, so the
  reader understands what the review reported and can respond to it.
  - When the issue links more than one pull request, Review findings, What
    changed, and Impact after deployment sit in one h2. group per pull
    request, below Acceptance criteria, and their headings drop to h3.
    (references/several-pull-requests.md).
  - One bullet per finding, as a TL;DR of its solution backed by evidence: what
    is wrong, what the fix is, what the fix changes for the user or the
    business, and the evidence in plain words (an observed value, a reproduced
    behaviour, a failing or passing test, a query result). Plain prose only.
  - Only a point that must be resolved before the merge is listed: a defect
    or an open question that needs an answer. Every such point of the technical
    review is listed. A point that needs no action (resolved, deferred,
    informational) is not. An assumption, a hypothesis, or a point with no
    evidence is never listed. The rule is owned by @skills/pr-summary/SKILL.md
    (Review-only run).
  - A point that blocks the merge comes first and says in words that it blocks
    the merge. Never write a severity label.
  - A finding about behaviour the assignment does not ask for appears only when
    it is Critical. The review carries no other out-of-scope point and no
    follow-up proposal (@rules/code-review/general.md, What a code review checks,
    and nothing else), so none is listed.
  - When the review has no point to resolve, or no review has run yet, render
    one sentence that says so, and no bullets.
  - The findings new in this review come first. The heading "Already reported
    in an earlier review" renders only when that group has a finding. The two
    groups and the answer sentence are owned by @skills/pr-summary/SKILL.md
    (Review-only run); never add a round number, a provenance value, or a
    count.

What changed
  Three to five bullets, observable behaviour only, in before -> after form
  where a before exists. It states observable behaviour, never mechanism — a bullet that
  explains how the code works belongs on the pull request instead. The JIRA
  shape carries no Problem / Cause / Result fields: Cause asks for a mechanism,
  and a mechanism is what invites method names into a product manager's ticket.

Impact after deployment
  The parts of the application a production deployment of the change can
  affect, so the ticket owner knows what to watch after the release. What
  changed says what behaves differently; this section says where.
  - One bullet per affected feature, at most five: the feature in plain
    language, and what a user there may notice. When a feature runs through
    the changed code but nothing visible should change, say that.
  - Source: the features the diff itself changes, plus the parts the newest
    pull-request review lists under "Affected behaviour outside the diff"
    (@rules/code-review/core-analysis.md, Behaviour changed outside the diff).
    Retell each part as a feature, never as a file, a class, or a method.
  - When no code-review comment exists for the current head yet, derive the
    parts from the diff, and say in the first bullet that the review has not
    confirmed them.
  - When the review comment carries no "Affected behaviour outside the diff",
    the review found no affected part outside the diff: list only the features
    the diff changes, with no caveat.

Headings and field labels
  Translate them into the assignment language per @rules/reports/general.md — a
  Czech assignment renders "h2. Akceptační kritéria", "h2. Nálezy z kontroly
  kódu", "h2. Co se změnilo", "h2. Co může změna ovlivnit po nasazení", and
  the footer "Tento
  komentář je generovaný automaticky. Je psaný pro vlastníka ticketu, ne pro
  vývojáře." Never mix an English heading with assignment-language prose.

Length
  3 000 characters, counted over the published body. When the comment overflows,
  shorten What changed first, then merge Impact after deployment bullets,
  then write each Review findings bullet in two sentences —
  never drop an Acceptance criteria bullet or a Review findings bullet. @rules/reports/general.md owns this cap, and
  @skills/pr-summary/SKILL.md *Length follows the facts* names JIRA as the
  one target it applies to.

No embedded blocks
  The JIRA template has no {embedded_blocks} slot. The Assignment Compliance
  verdict is the Acceptance criteria section above, which is why that section is
  first and always rendered, and every open question sits in a bullet per
  Questions above.

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
