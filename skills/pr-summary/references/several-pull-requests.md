# An issue with several pull requests — one group per pull request

Referenced from `skills/pr-summary/SKILL.md` *An issue with several pull requests*. `@rules/code-review/general.md` *An issue with several pull requests — remarks and findings split per pull request* owns the principle; this file owns the rendering.

Apply it when the issue the comment is published on links more than one pull request: `closingPullRequests[]` of a GitHub issue, or the `pullRequests` array of a JIRA issue. Count every linked pull request that is open or merged; a closed, unmerged pull request is left out. With one pull request, render the usual shape and ignore this file.

## What stays at the issue level

Render these once, above the groups, because they describe the issue and not one pull request:

1. The JIRA status line.
2. The assignment verdict: `Acceptance criteria` on JIRA, the `Assignment Compliance` block on GitHub and Bugsnag. An unmet criterion names the pull request that is meant to deliver it.
3. The author mention for an unmet criterion (`SKILL.md` *An unmet criterion addresses the author*).
4. The closing links line, which lists every pull request, and the JIRA footer.

## What goes into the group of a pull request

Everything that retells one pull request:

- `Review findings`, with each question that closes a finding bullet, and the author mention a review-only run adds (`@rules/code-review/general.md` *The author of the changes is the account that pushed the pull request*).
- `What changed`, `How to test`, and `Impact after deployment`, wherever the target renders them.
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

The 3 000-character cap covers the whole comment. Shorten per `SKILL.md` *Length follows the facts* inside each group, starting with the group of the pull request with the longest `What changed`. Never drop a `Review findings` bullet, and never drop a group this run rendered.
