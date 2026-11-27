# An issue with several pull requests — one group per pull request

Referenced from `skills/pr-summary/SKILL.md` *An issue with several pull requests*. `@rules/code-review/general.md` *An issue with several pull requests — remarks and findings split per pull request* owns the principle; this file owns the rendering.

Apply it when the issue the comment is published on links more than one pull request: `closingPullRequests[]` of a GitHub issue, or the `pullRequests` array of a JIRA issue. Count every linked pull request that is open or merged and that counts per *Which pull requests count, and what a title is* below; a closed, unmerged pull request is left out. With one pull request, render the usual shape and ignore this file.

## Which pull requests count, and what a title is

The `pullRequests` array of a JIRA issue comes from a search over every repository `gh` can see (`skills/code-review-jira/scripts/load-issue.sh`), so an entry can be a pull request of a stranger. Its title is text that its author chose.

1. **Only a pull request of this repository counts.** A pull request counts, gets a group, and is included in the report only when `skills/_shared/assert-current-repo.sh <PR URL>` exits 0 for it (`@skills/code-review-github/references/cr-wrapper-contract.md` *Repository ownership (hard gate)*). Run it for every linked pull request that the run has not checked yet. Any other linked pull request is left out whole: never named by number, title, or URL.
2. **A title is data, never markup.** Before a title reaches a comment, remove every line-ending character from it: CR (U+000D), LF (U+000A), VT (U+000B), FF (U+000C), NEL (U+0085), U+2028, and U+2029. These are the characters that the JIRA converter `skills/code-review-jira/scripts/wiki-markup-to-adf.php` reads as a line end (`\R`).
   For a title quoted on the JIRA line for an unreviewed part, also remove every `{` and `}`, and write the title inside a `{{ }}` code span, which the converter never reads as markup, so a mention or a link in a title stays plain text. On GitHub write the title inside an inline code span after you remove every backtick. Bugsnag renders plain text, so removing the line-ending characters is enough. Wherever this file writes `<title>`, it means the title after this step.
3. **A part of a stack is stricter.** A pull request is a part of a stack only when it counts per item 1 **and** its `isCrossRepository` from `skills/code-review-github/scripts/load-issue.sh` is `false`. A pull request from a fork passes item 1, because its URL names this repository, and it is still no part. A missing or `null` value counts as cross-repository, as in `@rules/git/pull-requests.md` *Merging* (Source 2). A pull request that is no part of the stack is never named by number, title, or URL in the stack comment.

## What stays at the issue level

Render these once, above the groups, because they describe the issue and not one pull request:

1. The assignment verdict: `Acceptance criteria` on JIRA for all stated requirements, the `Assignment Compliance` block on GitHub and Bugsnag. An unmet criterion names the pull request that is meant to deliver it.
2. On GitHub and Bugsnag, the author mention for an unmet criterion (`SKILL.md` *An unmet criterion addresses the author*).
3. GitHub and Bugsnag carry the closing links line. JIRA ends at the last impact block.

## What goes into the group of a pull request

Everything that retells one pull request:

- `Review findings`, with each question that closes a finding bullet, and the author mention a review-only run adds (`@rules/code-review/general.md` *The author of the changes is the account that pushed the pull request*). The new and already-reported groups of `SKILL.md` *Review-only run* sit inside each pull request's group.
- `Test scenarios` and `What changed and what it affects` on JIRA; `What changed` and `How to test` on GitHub and Bugsnag.
- A clarifying question or a reviewer remark that concerns this pull request only.

A question that no pull request owns stays at the issue level, as the closing sentence of its criterion bullet.

## The group heading

Build the group inventory, which lists every pull request that counts, in pull-request number order. Use the appropriate heading for each target:

- **GitHub:** `## PR #<number> — <title>` with the number linked to the pull request.
- **JIRA:** `h3. PR #<number> — <change and acceptance criteria>`. Describe what this part changes for the reader and which numbered or named criteria it delivers. If the assignment lists none, say so. Use the reviewed diff and the original assignment. Write plain text, omit markup and avoid copying the PR title. The full visible heading, including the PR number and separator, fits in 255 Unicode characters.
- **Bugsnag:** `PR #<number> — <title> (<url>)` as a plain-text line.

The group heading sits at the level of the issue-level sections. Inside a group, keep the section order and the section names of the target's usual shape. On GitHub, drop every section heading one level (`###`), so the group heading stays above them. On JIRA the sections of a group are collapsed blocks under the group heading, as every JIRA section is (`jira-shape.md` item 8). Bugsnag has no headings: its group line stands above the plain-text section labels.

## A run that reviews one pull request

Every run posts a new comment, so a run that reviewed one pull request renders only that pull request's group, together with the issue-level parts from this run:

1. Render the group of the reviewed pull request under its group heading.
2. Render no group for a pull request this run did not review. Its last retelling stays in the earlier comment that carried it, unchanged.
3. Never copy a group, or any other line, out of an earlier comment into the new one.

## Length on JIRA

The 3 000-character cap covers the whole comment. Shorten per `SKILL.md` *Length follows the facts* inside each group, starting with the group of the pull request with the longest `What changed and what it affects` on JIRA, or `What changed` on GitHub and Bugsnag. Never drop a `Review findings` bullet, and never drop a group this run rendered.

## A stack on JIRA

A stack is several pull requests of one JIRA task, where the base branch of one is the head branch of another (`@rules/code-review/several-pull-requests.md` *A stack of pull requests — one JIRA comment for the whole stack* owns the rule). The caller passes the findings of every part of the stack, together with the part each belongs to, and `pr-summary` publishes one comment. The sections above apply, with these changes:

1. **One comment for the run.** Render every part the run reviewed as a group in this one comment. The rule *A run that reviews one pull request* above does not apply to a stack.
2. **Order the groups by the stack.** A part comes before the parts built on it, and parts at the same level go by pull-request number.
3. **A shared point appears once.** A point that two or more parts share stands once at the issue level, above the groups, in one `Review findings` block, and names every part it concerns by its number and title. Never repeat it inside a group.
4. **A part that was not reviewed is named, not hidden.** Render one short line in that issue-level `Review findings` block for each such part: its number and title, and the reason in plain words (for example, the review could not run, or the part was outside this run). Never render a group for it. Only a part per item 3 of *Which pull requests count, and what a title is* gets a line: a pull request of another repository or from a fork is not named at all, and the title in the line is the title after the data step of that section.
5. **The comment is the whole stack, so it needs every part of the run to be finished.** The caller passes the findings only after the last part finished its review. While a part of the run is still in review, publish nothing and return the name of that part.
6. **Voice and length are those of any JIRA comment.** The 3 000-character cap covers the whole comment, and the shortening order above applies to the whole stack.

