---
description: Redesign one application page from its URL — previews for approval first, then implementation, review, and an interactive browser walkthrough
argument-hint: [page URL]
---

Use the shared directory supplied by direct skill entry when present. Otherwise bind `commands/_shared/` to `${CLAUDE_PLUGIN_ROOT}/commands/_shared/` for the native plugin, `.claude/command-support/_shared/` for Composer Claude, or `.codex/commands/_shared/` for project Codex. Retain that directory for every included protocol and helper.

This session holds the `krang` role for this request and never starts `krang` as a subagent, per
`@rules/compound-engineering/orchestration.md`
*Starting the orchestrator — the top-level role and the nested hand-over*. Run `commands/_shared/orchestration/deliver-page-redesign.inc` with the page URL:
$ARGUMENTS

If the URL is missing or is not exactly one absolute `http://` or `https://` URL of a page in this
application, stop and ask for it. Follow the command protocol in full. Show me the redesign previews first and
refine them until I explicitly approve them. Write no code before that approval. Then implement the
approved design without changing business logic, open the pull request, drive its review to
convergence, verify the page in a real interactive browser, and report in the skill's output shape.
Never merge the pull request.

For Codex, invoke `$deliver-page-redesign` with the same URL. The root session holds the `krang` role under
the same section and spawns the registered specialists itself, including `michelangelo` for the design phase.
