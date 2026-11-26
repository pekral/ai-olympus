[Deploy verdict: one paragraph with no heading. Whether the change can be deployed, with the reviewer's own short verdict; how many findings block the merge, with a one-sentence plain summary of them in the order of the findings; and that nothing is merged or deployed. Never what the review ran or did not test.]

{expand:Acceptance criteria}
[Render this block only for explicit requirements that still need a change or an answer. Omit satisfied requirements, positive verdicts, and completion counts.]

* [Only a criterion that is NOT met, is partially met, or needs a human to confirm it. What is missing and why the review judged it so, in plain prose. When a question for a human is open, it is the closing sentence of this bullet.]
{expand}

{expand:Findings from the code review}
You can go through the whole change here: [code in the pull request|PR_URL/files]

* *(bug):* [The change type of the point (references/jira-shape.md, item 10), then one point that must be resolved before the merge, per bullet, as a TL;DR of its solution: what is wrong, what the fix is, and what the fix changes for the user or the business. Name the evidence in plain words, for example "an order created without a customer fails". Order the bullets by type: security, bug, tests, refactor, performance, documentation. A security point that still needs verification and blocks the merge is the first bullet, named as a blocker without the detail of the suspicion.] [View in code →|CODE_LINK]
* *(refactor):* [~accountid:<id>] [Next point; a refactor or a tests finding mentions the author of the changes right after its type. Only a defect or an open question that needs an answer; never an assumption, a hypothesis, or a point that needs no action. A question that concerns no criterion is the closing sentence of the point it belongs to.] [View in code →|CODE_LINK]

h3. Already reported in an earlier review

* *(tests):* [Only when a finding was carried over from an earlier review. Same bullet shape. A finding with a recorded answer ends with one sentence that says when and where the answer was recorded, and that the point still blocks the merge when it does.] [View in code →|CODE_LINK]
{expand}

{expand:What changed and what it affects}
* [One fact per bullet, a few bullets and at most 600 characters in total: what behaves differently, which features it affects, and what a user should watch after release. Each fact appears once.]
{expand}

[PR #123|PR_URL] · [ISSUE-KEY|ISSUE_URL]

----

_This comment is generated automatically. It is written for the person who owns this ticket, not for a developer._

{expand:Technical marker for AI agents}
{agent-marker}
{expand}

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
  The deploy verdict paragraph, then Acceptance criteria (only for
  requirements that still need action), then Review findings, then What
  changed and what it affects,
  then the closing links line and the footer. There is no separate
  section for questions: each open question sits in the bullet it belongs to. A review-only run, a code-review mirror,
  the post-convergence report, and the merge-readiness TL;DR all render this
  one shape. The requests for changes come first so the reader knows what remains. A JIRA comment carries no How to test section: the
  steps a tester follows live on the GitHub pull request, whose description
  carries them (@rules/git/pull-requests.md, Testing). This order
  is JIRA's own; the GitHub and Bugsnag templates keep their What changed /
  How to test shape.

Collapsed blocks
  Every section is a collapsed block: a {expand:<section name>} line, the whole
  section, and a {expand} line. The reader opens a block only to read its
  detail, and every agent still reads the text inside it. A block never nests
  in another block. Only the deploy verdict, a pull-request group heading, the
  closing links line, and the footer stand outside a block.

Deploy verdict
  The one paragraph above the first block (references/jira-shape.md, item
  1). It says whether the change can be deployed with the reviewer's own short
  verdict, how many findings block the merge with a one-sentence summary that
  follows the order of the findings and takes its facts from them only, and
  that nothing is merged or deployed. It never describes what the review ran
  or did not test, and no closing sentence follows the sections.

Acceptance criteria
  Rendered only for explicit requirements that are unmet, partially met, or
  need an answer before acceptance. Each bullet states the remaining change
  or answer and its evidence. Omit satisfied requirements, positive verdicts,
  and completion counts throughout the report. When every requirement is
  satisfied, omit the whole section. Omit it too when no criteria are stated.
  An unmet or partially met criterion states why. The block carries no call
  to the author of the changes: no sentence asks the author to confirm and fix
  the gap, or refute it in a reply (references/jira-shape.md, item 2).
  The caller passes the full @skills/assignment-compliance-check/SKILL.md
  verdict internally; only its requests
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
  - When the issue links more than one pull request, the Review findings and
    What changed and what it affects blocks sit under one h2. group heading per
    pull request, below any
    Acceptance criteria block (references/several-pull-requests.md).
  - A stack of pull requests gets one comment for the whole run. A point that
    several parts share stands once in a Review findings block above the groups
    and names its parts, and a part the run did not review is named there with
    the reason, never given a group (references/several-pull-requests.md, A
    stack on JIRA).
  - Every bullet opens with the change type of its finding in parentheses, in
    bold, with a colon: *(security):*, *(bug):*, *(tests):*, *(refactor):*,
    *(performance):*, or *(documentation):* (references/jira-shape.md, item
    10). The finding text after it stays unchanged.
  - The first line of the block links the whole change of the pull request,
    and every finding ends with "View in code →" linked to its file and line
    in the changes of the pull request. A finding about code outside this
    one ends with a link to the pull request that fixes it, or, when none
    exists, with "View in code →" linked to its file and line at the reviewed
    head commit (references/jira-shape.md, item 13).
    Build PR_URL/files and CODE_LINK with
    skills/pr-summary/scripts/pr-code-link.php, never by hand.
  - Every *(refactor):* and *(tests):* finding mentions the author of the
    changes right after its type, as [~accountid:<id>]
    (references/jira-shape.md, item 12). The
    ID comes only from a confirmed person match. Without one the finding
    carries no mention, and the run reports that.
    No other line of the comment mentions anyone.
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
  - Order the findings by the severity of their type: security, bug, tests,
    refactor, performance, documentation, with the findings of one type in
    their original order (references/jira-shape.md, item 11). The order
    restarts under "Already reported in an earlier review". Never write a
    severity label.
  - Interactive testing that the project switched off is stated in one sentence
    (@skills/pr-summary/SKILL.md, What the comment carries).
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

What changed and what it affects
  A short summary of a few bullets, at most 600 characters in total, combining
  observable changes and deployment impact. One fact per bullet, each fact
  once, and no fact of either part lost or changed. State the affected
  features and what a user should watch after release. Use before -> after
  where useful. Keep mechanisms on the pull request. The block carries no
  TL;DR label.
  Source: the features the diff changes, plus the newest review's "Affected
  behaviour outside the diff" (@rules/code-review/core-analysis.md).
  When no code-review comment exists for the current head yet, derive the
  parts from the diff and say the review has not confirmed them.
  When the review comment carries no "Affected behaviour outside the diff",
  the review found no affected part outside the diff: include only features
  the diff changes, with no caveat.

Screenshots
  Only a finding that the interactive test reproduced has one, and only inside the rules of
  @skills/interactive-testing/references/defect-screenshots.md (references/jira-shape.md, item 14). Directly under its
  bullet, write one slot line per image: {screenshot:<finding id>:head}, and for a regression
  {screenshot:<finding id>:base} above it. Write no slot for a passed scenario, a code-only finding, a security
  finding, or a finding marked do not file publicly. The publisher fills the slot or removes it.

Headings and field labels
  Translate them into the assignment language per @rules/reports/general.md. The
  block titles are "Acceptance criteria", "Findings from the code review", and
  "What changed and what it affects" in English. A Czech assignment renders
  "{expand:Akceptační kritéria}", "{expand:Nálezy z kontroly kódu}", "{expand:Co
  se změnilo a co to ovlivní}", "{expand:Technická značka
  pro AI agenty}", and the footer "Tento
  komentář je generovaný automaticky. Je psaný pro vlastníka ticketu, ne pro
  vývojáře." Never mix an English heading with assignment-language prose.

Length
  3 000 characters, counted over the visible text of the published body: a
  link counts its label, never its address. When the comment overflows,
  shorten the wording of What changed and what it affects first, keeping each
  of its facts,
  then write each Review findings bullet in two sentences —
  never drop an Acceptance criteria bullet or a Review findings bullet. @rules/reports/general.md owns this cap, and
  @skills/pr-summary/SKILL.md *Length follows the facts* names JIRA as the
  one target it applies to.

Check before the publish
  Run php skills/pr-summary/scripts/check-jira-comment.php --criteria
  <unmet|none> --author <account-id|none> --repository <owner/name>
  --pull-request <number> --jira-issue <issue-url> --revision <commit>
  <source-file> on the rendered source, with the Reviewed revision of the
  code-review report as the commit, and publish only a source it accepts
  (@skills/pr-summary/SKILL.md, Publishing). The script checks the
  cap over the visible text of the converted comment, the one deploy-verdict paragraph, the collapsed blocks and the text
  outside them, the criteria block against the flag, the
  bullets of What changed and what it affects, at most 600 characters and no
  TL;DR label, the change type and the order of every finding, the author
  mention on every refactor and tests finding and on nothing else, the whole-change line
  and the code links, every link and mention of the converted comment against
  the task's pull requests, the reviewed commit, its JIRA site, and the author, leaked Markdown, code
  macros, commit hashes, the footer, and the closing agent-marker block.

No embedded blocks
  The JIRA template has no {embedded_blocks} slot. The Assignment Compliance
  result supplies only the requirements that still need action in Acceptance
  criteria; satisfied criteria and positive verdicts are omitted. Every open
  question sits in a bullet per Questions above.

  Do not add an Authors line, an Available behind line, a Summary of changes
  section, severity counts, file paths, line numbers, or code snippets — none of
  those belongs on any target this skill publishes to.

Technical marker for AI agents
  The last block of the comment, after the footer, holding only the
  {agent-marker} slot line. The JIRA publish helper writes the agent marker in
  place of the slot (@rules/jira/general.md, Comments Format), so a reader sees
  the marker only when they open the block, and every agent that finds,
  updates, or deletes its own comment by the marker still finds it. Translate
  the block title like every other title; never fill the slot by hand.

Closing links line
  One line, the pull request and the source tracker item, separated by " · ", in
  intermediate link form ([label|url]). Render whichever of the two links exists;
  omit the line when neither does. It sits above the footer separator. It is
  navigation rather than a technical note, like the links into the pull request
  in Review findings.

The canonical statement of every rule above lives in
@skills/pr-summary/SKILL.md. This block restates the slot mechanics for whoever
is filling the template in; the skill is the source of truth if the two ever
disagree.
