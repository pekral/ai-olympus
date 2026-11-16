# Inline review comment

> One inline review comment on the changed line of a finding, on a GitHub pull request. `skills/code-review-github/scripts/post-inline-review.sh` renders it, one comment for each finding, and posts every comment of a round as one pull-request review. This file fixes the two shapes: the lines, their order, and the markers. Canonical contract: `@skills/code-review-github/references/cr-wrapper-contract.md` *Inline review comments*.
>
> **Write it as a person would.** `**Problem:**` holds one or two plain sentences, `**Impact:**` one sentence, and the faulty example at most 5 lines in a fenced block (its language from `faulty_example_lang`, when set). The icon is the severity: 🔴 Critical, 🟠 Moderate. Nothing else is visible: no Rule, Provenance, Answered, Raised by, Level, Evidence, Expected behavior, Test hint, or Suggested fix line, and no `suggestion` block. The full finding lives in the hidden record of the review body (`templates/pr-comment-output.md`).
>
> **Name no tooling of this package.** The visible text describes each step in plain words and names no script, skill, agent, rule, or installed directory of this package. The reviewed project's own commands and paths stay. The wrapper refuses the rest before the publish (`@rules/code-review/general.md` *Visible review text names none of the package's own tooling*).
>
> **A DRY / reuse finding takes the second shape** (`kind: "dry"`, `existing` = the `<path>:<line>` of the existing implementation). It is Moderate (`@rules/code-review/general.md` *Reuse Existing Logic*). A duplicated security control keeps its security severity and takes the first shape.
>
> **The mention line renders only when the finding has an `author`** — a missing-test or DRY finding of a review-only run (`@rules/code-review/general.md` *The author of the changes is the account that pushed the pull request* → *Second exception*).
>
> **The markers close the body.** `<!-- cr-inline:actor=<login> -->` is the agent marker of `@rules/code-review/general.md` *Authorship trust*. `<!-- cr-inline:finding=<finding id> -->` names the finding for `resolve` and for the duplicate check. A body holds exactly these two markers and no other `cr-inline` marker.

Every finding except DRY:

````markdown
{🔴 | 🟠} **Problem:** {the problem, in one or two plain sentences}

**Impact:** {what breaks or what risk this introduces, in one sentence}

```{language}
{the faulty code, at most 5 lines}
```

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

A finding that cannot anchor to a line of the diff is one line of the review body instead, followed by its finding marker:

````markdown
{🔴 | 🟠} **Problem:** {the problem} **Impact:** {the impact} (`{file:line}`, outside the diff | no line) @{login}
<!-- cr-inline:finding={finding id} -->
````
