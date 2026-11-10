# Git history exposure audit — `<owner>/<repo>`

<!--
Template for the full audit of the git-history-exposure-audit skill. Render it from the output of
`scripts/history-scan.sh full` into this run's output only. Never publish it: no tracker comment, no
issue, no tracked file, no report file. Write it in the language of the user's request; class names,
rule ids, commit SHAs, paths, and commands stay verbatim. A finding never carries a value: copy the
fingerprint or the printed term, never anything else.
-->

- **Repository:** `<owner>/<repo>`, default branch `<branch>`, `<public | private>`, `<archived | not archived>`, `<fork | not a fork>`
- **Scan:** `history-scan.sh full`, exit `<code>`, `<n>` findings, engine `history-scan.sh`
- **Secret scanning:** `<enabled | disabled>`, **push protection:** `<enabled | disabled>` — or the line under *Not verified* when the API returned no status
- **Published:** nothing. This report exists in this run's output only.

## Findings

Order the findings by class: credential, sensitive-file, infrastructure, identity, named-term. Inside a class, keep the order the script printed (first commit first). One row per finding line.

| # | Class | Rule | Surface | First commit | Location | Fingerprint or term | Default-branch head | Tags | Occurrences |
|---|---|---|---|---|---|---|---|---|---|
| 1 | credential | `<rule>` | `<line \| path \| message \| tag \| github>` | `<sha>` | `<path, ref, or URL>` | `<fingerprint>` | `<yes \| no \| ->` | `<tags \| ->` | `<n>` |

`head=-` and `tags=-` mean the surface is a GitHub text: it lives outside the git history.

## Remediation per finding

Give every finding the steps of its class, in this order. The skill performs none of them; the owner decides and acts.

### Credential or sensitive file
1. **Revoke or rotate the credential first.** For a sensitive file, rotate every credential the file held. Removing a value from the history does not make a copied value safe: every clone, fork, cache, and CI log made before the rewrite keeps it.
2. Then remove the value from the history, following *Git history* below.

### Infrastructure, identity, or named term
1. Decide whether the detail must stay private. A detail that is already public elsewhere may stay.
2. When it must go, remove it from the history, following *Git history* below, and from the GitHub texts, following *GitHub text* below.

### GitHub text
1. Edit the text so it no longer carries the value.
2. Delete the earlier revision from the item's edit history. The edit history keeps every earlier revision readable.

### Git history
1. Rewriting the history and re-tagging removes the value from the repository. It is the owner's decision.
2. **The consequences:** every later commit SHA changes; every existing clone and fork keeps the old objects; GitHub keeps an unreferenced commit readable by its SHA until GitHub Support removes the cached views; a closed pull request's `refs/pull/<n>/head` keeps the commits it pointed at.
3. Work through the *History rewrite checklist* below before the rewrite.

## History rewrite checklist

The owner verifies every item **before** the rewrite. When one item fails, stop: do not rewrite, and settle that item first.

- [ ] The credential is rotated, and the old value no longer grants access anywhere.
- [ ] No other contributor has unpublished work on the branches the rewrite touches, and each contributor knows the rewrite is coming.
- [ ] Every open pull request based on the rewritten commits is listed, with how it moves to the new history.
- [ ] Every tag and release that contains the rewritten commits is listed, with how it is re-created.
- [ ] Every review-thread anchor and every SHA already cited in a report, an issue, or a pull-request description is listed, with what happens to it.
- [ ] Every CI run, deployment, and artefact that references an old SHA is listed, with what happens to it.
- [ ] Every fork and clone that keeps the old objects is listed, with who asks its owner to delete or re-clone it.
- [ ] The push uses `git push --force-with-lease=<branch>:<expected remote SHA>`, never `--force`, so a push someone else made in the meantime is refused, not overwritten.
- [ ] The project's quality gate and CI run again on every new head before anything merges.

`git push --force-with-lease` is named here, behind this checklist, and nowhere else in this skill. The skill itself never runs it.

## Not verified

List every `not-verified:` line the script printed, each with the step that settles it. The script always lists the surfaces it cannot read: the edit history of edited GitHub texts, deleted texts, commits no ref reaches, private forks, the wiki, gists, Actions logs and artifacts, packages, and the discussion comments past the first page. It adds every surface this run failed to read, the binary blobs it did not scan, and the lines it cut at the line cap.

- `<surface>` — `<reason>`; `<the step that settles it>`
