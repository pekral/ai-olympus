# Defect screenshots

Expanded procedure for `@skills/interactive-testing/SKILL.md` *Report* and `agents/raphael.md` *Capture evidence for every failure*. A reproduced UI defect can show its verified screenshot under its GitHub finding. The JIRA summary carries the text finding through acli. This file owns what may be captured, what may be published, and how. The publishers enforce the same conditions in code (`skills/_shared/screenshot-guard.sh`).

## Which finding gets a screenshot

- **Errors only.** A scenario that passed gets no screenshot and no line in a comment. A finding the walkthrough did not reproduce gets none either, and neither does a finding found by reading the code.
- **One screenshot per state.** A defect has one screenshot, taken on the step that fails, never on a page before or after it. A regression has two screenshots of the same step: `Default branch (<default>)` first, then `This change`. The finding text says it is a regression against the default branch.
- **An API-only defect gets text only.** There is no screen to show.
- **A security finding never gets a screenshot.** Neither does a finding marked `do_not_file_publicly`, on GitHub and in JIRA alike. Such a screenshot can show another person's data or the path to an exploit, and a public image stays public.

## The publication gate — an allow-list, never a deny-list

A screenshot is published only when **all** of these hold. A condition that is unmet or cannot be verified leaves the finding text-only, and the handoff names the condition.

1. **The page is no error or debug view.** A framework error page (Ignition, Whoops, a Symfony or Django or Rails exception page) and a page with a debug toolbar (`phpdebugbar`, `sf-toolbar`, `sf-wdt`, a debug panel) are never captured and never published. Detect them before the capture: the title or the body holds `Whoops`, `Ignition`, `Stack trace`, `Request headers`, `Environment`, or one of the toolbar identifiers above. Such a page shows request headers, cookies, session data, SQL bindings, and environment values.
2. **The capture is cropped.** It covers the failing element within the tested viewport, never the full page (`fullPage: true`).
3. **The cropped area holds only data this run created.** That means the test account and the records of `@skills/interactive-testing/SKILL.md` *Missing test data*. The local database is often a copy of production, so a name, an address, an order number, or any other value of a record this run did not create counts as personal data. Cover such a value before the file exists, or leave the finding text-only.
4. **Masking ran in the browser session that writes the file, right before the capture.** One page script covers the values of every `input`, `textarea`, and `select`, the text of an element that is spread over several child nodes, and every open shadow root. It paints a canvas, an `img`, a `video`, SVG text, and a cross-origin iframe in the cropped area over as a whole. A password, a token, an API key, an e-mail address, and a phone number are masked in every case. A secret value of the environment is never shown.
5. **You looked at the file.** Use the guard to re-encode the crop before a visual read. Verify that all readable content is expected data created by this run. A page change during capture, an uninspectable object or an unverified pixel region means text-only. This check comes after the four conditions above and replaces none of them. When the browser tool cannot save the capture as a PNG file, the finding stays text-only.

## The manifest

For each screenshot raphael adds one row to its Evidence section, and `leonardo` copies it into the screenshot manifest of the review (`screenshots`). The row carries `finding_id`, `finding_type` (`bug`, `tests`, `refactor`, `performance`, or `documentation`), `do_not_file_publicly`, `state` (`head` or `base`), `default_branch` (the resolved default branch for `base`, else `null`), `file`, `masking` (`verified`), `crop` (`element` only), `own_data_only`, `not_error_page`, and `masked_in_capture_session`. The file lies directly in `.claude/run/<source-slug>.artifacts/` and its name matches `^[a-z0-9-]{1,100}\.png$`. `skills/_shared/screenshot-guard.sh manifest` refuses an entry that misses a field or a condition, and the publishers print the reason.

Raphael writes the independent `<slug>.screenshot-evidence.json` beside `<slug>.screenshots.json` in the run directory after inspecting the re-encoded crop.
The document records `target` (the validated PR URL), `reviewed_head` and `captures`. Each capture records the same `finding_id`, `finding_type`, `do_not_file_publicly`, `file` and `state` as the manifest, its `revision`, `viewport` (desktop or mobile), exact safe `step` name used in the review’s interactive-testing table, `kind: ui`, `verdict: failed` for HEAD or `passed` for BASE, `own_pixels: true`, `visually_verified: true` and the `sha256` of the canonical image actually inspected. Do not derive this record from the manifest, ticket, browser text or application files. The run owner passes the tested revision, target and artifact names directly. Reuse neither a stale record nor an existing candidate from another run.

## The baseline of a regression

- **The same test worktree.** Walk the same step on the default branch with the test worktree of `@skills/interactive-testing/SKILL.md` *Local readiness* item 1, moved to `origin/<default>` with `skills/_shared/branch-worktree.sh open`. No further worktree exists, so `krang` *Run cleanup* removes this one as before.
- **Before the migrations of the head.** Take the base screenshot before the pending migrations of the head run against the local database. When that database already holds a migration the base commit does not have, the base walk shows a state that never existed. Then either create a database for the base walk with the project's documented setup and drop it when the walk ends, or leave the baseline out and say so. A rollback and `migrate:fresh` stay forbidden.
- **The label.** `Default branch (<default>)` takes the name of the resolved default branch, and it matches `^[A-Za-z0-9._/-]{1,100}$`.

## Evidence URL

Name the user action and viewport in the handoff. Do not include the source URL, its host, path, query or fragment.

## Where the image goes, and when

- **Upload happens only inside the publishing step.** The publisher uploads after its record check, its withheld-finding check, and its head check have passed, and never before. A quiet review round uploads nothing. If the reviewed head moves during publication, the publisher stops and reports already confirmed assets.
- **GitHub.** `skills/code-review-github/scripts/upload-review-asset.sh` writes the file to the branch `cr-assets` of the base repository of the pull request through the GitHub Contents API, outside the diff of every pull request. It runs no `git push`, no hook, and no force. The review links the commit-pinned URL under its finding. Only `post-inline-review.sh` renders it, and every other image form in a finding stays inert.
- **JIRA.** Publish only the text finding through acli. The installed CLI cannot upload an attachment or obtain the stable identity of an ADF media item.
- **A refused write is never a failed review.** A missing right, a protected branch, or a conflict leaves the finding text-only, and the handoff names the reason.
- **Size.** A PNG is at most 2 MiB, a finding has at most two, and a review has at most 20. Every full clone of the repository downloads `cr-assets`, so the screenshots add to its size.
