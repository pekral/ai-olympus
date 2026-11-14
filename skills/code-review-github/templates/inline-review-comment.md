# Inline review comment

> One inline review comment on the changed line of a finding, on a GitHub pull request. `skills/code-review-github/scripts/post-inline-review.sh` renders it, one comment for each finding, and posts every comment of a round as one pull-request review. This file fixes the shape: the fields, their order, and the markers. Canonical contract: `@skills/code-review-github/references/cr-wrapper-contract.md` *Inline review comments*.
>
> **Required lines:** the heading line, `**Problem:**`, `**Suggested fix:**`, and the two markers. **Optional lines** render only when the finding has the field, in this order, with the same field names as `templates/pr-comment-output.md`. A `suggestion` block renders only when the Suggested fix replaces exactly the anchored lines, and only on the `RIGHT` side.
>
> **The markers close the body.** `<!-- cr-inline:actor=<login> -->` is the agent marker of `@rules/code-review/general.md` *Authorship trust*. `<!-- cr-inline:finding=<finding id> -->` names the finding for `resolve` and for the duplicate check. A body holds exactly these two markers and no other `cr-inline` marker.

````markdown
{🔴 | 🟠} **{Critical | Moderate}** · `{finding id}` — {short title}

- **Rule:** `{rule file and section}`
- **Provenance:** {regression — introduced in this revision | pre-existing — carried from round {n} | pre-existing — untouched by this revision}
- **Answered:** {comment URL} — `{login}`, {date}
- **Raised by:** `{splinter | leonardo | both}`
- **Level:** `{High | Medium | Low}`
- **Author:** @{login}

**Problem:** {the problem, in one or two sentences}

**Impact:** {what breaks or what risk this introduces}

**Evidence:** {the evidence another reader can check}

**Faulty example:**
```
{minimal code or input that reproduces the issue}
```

**Expected behavior:** {single assertable statement}

**Test hint:** {test layer and entry point}

**Suggested fix:** {the fix, in one or two sentences}

```suggestion
{the exact replacement of the anchored lines}
```

<!-- cr-inline:actor={login} -->
<!-- cr-inline:finding={finding id} -->
````
