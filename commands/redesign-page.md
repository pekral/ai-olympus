---
description: Redesign one application page from its URL — proposal, implementation, review, and an interactive browser walkthrough
argument-hint: [page URL]
---

Run `@skills/deliver-page-redesign/SKILL.md` with this page URL. Resolve and capture the page in
this session, then delegate the delivery route to the `splinter` agent as the skill describes:

$ARGUMENTS

If the URL is missing or is not exactly one absolute `http://` or `https://` URL of a page in this
application, stop and ask for it. Follow the skill in full. Redesign the page presentation and
interaction without changing business logic, open the pull request, drive its review to
convergence, verify the page in a real interactive browser, and report in the skill's output shape.
Never merge the pull request.

For Codex, invoke `$deliver-page-redesign` with the same URL and ask the registered `splinter`
agent to orchestrate it.
