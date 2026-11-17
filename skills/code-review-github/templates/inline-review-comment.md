# Inline review comment

> One inline review comment on the changed line of a finding, on a GitHub pull request. `skills/code-review-github/scripts/post-inline-review.sh` renders it, one comment for each finding, and posts every comment of a round as one pull-request review. This file fixes the two shapes: the lines, their order, and the markers. The visible body of the review (the TL;DR, the Merge Risk, the Walkthrough, and the Actionable comments) is fixed in `templates/review-body.md`. Canonical contract: `@skills/code-review-github/references/cr-wrapper-contract.md` *Inline review comments*.
>
> **Write it as a person would.** `**Problem:**` holds one or two plain sentences, `**Impact:**` one sentence, and `**Fix:**` one plain sentence that says what to change. The icon is the severity: 🔴 Critical, 🟠 Moderate. Nothing else is visible: no Rule, Provenance, Answered, Raised by, Level, Evidence, Expected behavior, Test hint, or Suggested fix line. The full finding lives in the hidden record of the review body (`templates/pr-comment-output.md`).
>
> **A review carries no code.** An inline comment holds no fenced block, no `suggestion` block, and no replacement snippet: the fix is a sentence (`@rules/code-review/general.md` *A review carries no code*). The wrapper refuses a finding that holds a fence, and a findings file that sets `suggestion` or `faulty_example`.
>
> **Name no tooling of this package.** The visible text describes each step in plain words and names no script, skill, agent, rule, or installed directory of this package. The reviewed project's own commands and paths stay. The wrapper refuses the rest before the publish (`@rules/code-review/general.md` *Visible review text names none of the package's own tooling*).
>
> **A DRY / reuse finding takes the second shape** (`kind: "dry"`, `existing` = the `<path>:<line>` of the existing implementation). It is Moderate (`@rules/code-review/general.md` *Reuse Existing Logic*). A duplicated security control keeps its security severity and takes the first shape.
>
> **The mention line renders only when the finding has an `author`** — a missing-test or DRY finding of a review-only run (`@rules/code-review/general.md` *The author of the changes is the account that pushed the pull request* → *Second exception*).
>
> **The markers close the body.** `<!-- cr-inline:actor=<login> -->` is the agent marker of `@rules/code-review/general.md` *Authorship trust*. `<!-- cr-inline:finding=<finding id> -->` names the finding for `resolve` and for the duplicate check. A body holds exactly these two markers and no other `cr-inline` marker.
>
> **A finding that cannot anchor has no inline comment.** A finding whose line is not in the diff, and a finding with no line, is an entry of the Actionable comments block of the review body (`templates/review-body.md`) and nothing else.

Every finding except DRY:

````markdown
{🔴 | 🟠} **Problem:** {the problem, in one or two plain sentences}

**Impact:** {what breaks or what risk this introduces, in one sentence}

**Fix:** {what to change, in one plain sentence}

@{login}

<!-- cr-inline:actor={login} -->
<!-- cr-inline:finding={finding id} -->
````

A DRY / reuse finding:

````markdown
🟠 **Moderate · DRY:** This repeats the logic of `{existing file:line}`. Please unify it and call the existing implementation instead.

@{login}

<!-- cr-inline:actor={login} -->
<!-- cr-inline:finding={finding id} -->
````
