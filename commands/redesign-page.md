---
description: Redesign one application page from its URL — previews for approval first, then implementation, review, and an interactive browser walkthrough
argument-hint: [page URL]
---

Run `@skills/deliver-page-redesign/SKILL.md` with this page URL. Resolve and capture the page in
this session, get the redesign previews approved by me, then delegate the delivery route to the
`krang` role as the skill describes. This session holds that role itself and
never starts `krang` as a subagent, per `@rules/compound-engineering/orchestration.md`
*Starting the orchestrator — the top-level role and the nested hand-over*:

$ARGUMENTS

If the URL is missing or is not exactly one absolute `http://` or `https://` URL of a page in this
application, stop and ask for it. Follow the skill in full. Show me the redesign previews first and
refine them until I explicitly approve them. Write no code before that approval. Then implement the
approved design without changing business logic, open the pull request, drive its review to
convergence, verify the page in a real interactive browser, and report in the skill's output shape.
Never merge the pull request.

For Codex, invoke `$deliver-page-redesign` with the same URL. Ask the registered `michelangelo`
agent for the design phase. For the delivery phase, the root session holds the `krang` role under
the same section and spawns the registered specialists itself.
