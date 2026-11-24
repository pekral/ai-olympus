# An issue with several pull requests — one group per pull request

Referenced from `skills/pr-summary/SKILL.md` *An issue with several pull requests*. `@rules/code-review/general.md` *An issue with several pull requests — remarks and findings split per pull request* owns the principle; this file owns the rendering.

Apply it when the issue the comment is published on links more than one pull request: `closingPullRequests[]` of a GitHub issue, or the `pullRequests` array of a JIRA issue. Count every linked pull request that is open or merged; a closed, unmerged pull request is left out. With one pull request, render the usual shape and ignore this file.

## What stays at the issue level

Render these once, above the groups, because they describe the issue and not one pull request:

1. The assignment verdict: `Acceptance criteria` on JIRA only for requirements that still need action, the `Assignment Compliance` block on GitHub and Bugsnag. An unmet criterion names the pull request that is meant to deliver it.
2. The author mention for an unmet criterion (`SKILL.md` *An unmet criterion addresses the author*).
3. The closing links line, which lists every pull request, and the JIRA footer.

## What goes into the group of a pull request

Everything that retells one pull request:

- `Review findings`, with each question that closes a finding bullet, and the author mention a review-only run adds (`@rules/code-review/general.md` *The author of the changes is the account that pushed the pull request*). The new and already-reported groups of `SKILL.md` *Review-only run* sit inside each pull request's group.
- `TL;DR` on JIRA; `What changed` and `How to test` on GitHub and Bugsnag.
- A clarifying question or a reviewer remark that concerns this pull request only.

A question that no pull request owns stays at the issue level, as the closing sentence of its criterion bullet.

## The group heading

One heading per pull request, in pull-request number order, naming the number and the title with a link:

- **GitHub:** `## PR #<number> — <title>` with the number linked to the pull request.
- **JIRA:** `h2. [PR #<number>|<url>] — <title>` in the intermediate source.
- **Bugsnag:** `PR #<number> — <title> (<url>)` as a plain-text line.

The group heading sits at the level of the issue-level sections. Inside a group, keep the section order and the section names of the target's usual shape, and drop every section heading one level (`###` on GitHub, `h3.` on JIRA), so the group heading stays above them. Bugsnag has no headings: its group line stands above the plain-text section labels.

## A run that reviews one pull request

Every run posts a new comment, so a run that reviewed one pull request renders only that pull request's group, together with the issue-level parts from this run:

1. Render the group of the reviewed pull request under its group heading.
2. Render no group for a pull request this run did not review. Its last retelling stays in the earlier comment that carried it, unchanged.
3. Never copy a group, or any other line, out of an earlier comment into the new one.

## Length on JIRA

The 3 000-character cap covers the whole comment. Shorten per `SKILL.md` *Length follows the facts* inside each group, starting with the group of the pull request with the longest `TL;DR` on JIRA, or `What changed` on GitHub and Bugsnag. Never drop a `Review findings` bullet, and never drop a group this run rendered.

## A stack on JIRA

A stack is several pull requests of one JIRA task, where the base branch of one is the head branch of another (`@rules/code-review/several-pull-requests.md` *A stack of pull requests — one JIRA comment for the whole stack* owns the rule). The caller passes the findings of every part of the stack, together with the part each belongs to, and `pr-summary` publishes one comment. The sections above apply, with these changes:

1. **One comment for the run.** Render every part the run reviewed as a group in this one comment. The rule *A run that reviews one pull request* above does not apply to a stack.
2. **Order the groups by the stack.** A part comes before the parts built on it, and parts at the same level go by pull-request number.
3. **A shared point appears once.** A point that two or more parts share stands once at the issue level, above the groups, and names every part it concerns by its number and title. Never repeat it inside a group.
4. **A part that was not reviewed is named, not hidden.** Render one short line at the issue level for each such part: its number and title, and the reason in plain words (for example, the review could not run, or the part was outside this run). Never render a group for it.
5. **The comment is the whole stack, so it needs every part of the run to be finished.** The caller passes the findings only after the last part finished its review. While a part of the run is still in review, publish nothing and return the name of that part.
6. **Voice and length are those of any JIRA comment.** The 3 000-character cap covers the whole comment, and the shortening order above applies to the whole stack.

