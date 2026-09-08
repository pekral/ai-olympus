# Plan: first-class Bugsnag integration (parity with the GitHub and JIRA skills)

## Goal

Promote Bugsnag from today's "pass-through to a mirrored GitHub issue" to a **full tracker** that does
everything GitHub and JIRA already do: load the error, its comments, and its context (events, stack trace,
metadata), analyse and resolve the problem through `resolve-issue`, and publish back — the non-technical
report as a comment on the Bugsnag error itself and, optionally, marking the error *Fixed* or assigning it.
The integration must be deterministic and agent-friendly through a CLI or MCP, never an ad-hoc REST call.

## Architecture

The integration **copies the existing tracker pattern** rather than introducing a new one (compound
engineering: reach for the part of the system that already fits). Each tracker is a pair of bash scripts
(`load-issue.sh` and `upsert-comment.sh`) with a fixed JSON contract, a `code-review-<tracker>` wrapper skill
above them, and the shared orchestrators (`resolve-issue`, `pr-summary`, `assignment-compliance-check`,
`prepare-issue-context`).

Where the change lives:

- **A new script pair**, `skills/code-review-bugsnag/scripts/load-issue.sh` and `.../upsert-comment.sh`,
  following `skills/code-review-github/scripts/*` and `skills/code-review-jira/scripts/*` exactly.
- **Script backend:** primarily the `bugsnag` CLI (`yoanbernabeu/bugsnag-cli` — JSON by default,
  deterministic exit codes) for reads and `comments create`; for the writes the CLI cannot do (changing the
  error status, assigning it), and as a no-binary fallback, a direct `curl` against
  `https://api.bugsnag.com` with the `Authorization: token $BUGSNAG_TOKEN` header. An MCP server
  (`tgeselle/bugsnag-mcp` or the official SmartBear MCP) is the documented fallback, exactly as the GitHub
  and JIRA MCPs are today.
- **A new wrapper**, `skills/code-review-bugsnag/SKILL.md` (the parallel of `code-review-jira`): the review
  runs over the linked GitHub PR, the technical report goes to that PR, and the non-technical one to the
  Bugsnag error.
- **Edits to the shared points** (4–5 files): `resolve-issue/references/source-detection.md` (a Bugsnag row
  instead of the pass-through), `resolve-issue/SKILL.md` (the report-publishing section), `pr-summary` (a new
  `templates/pr-summary-bugsnag.md` plus Bugsnag as a destination), `prepare-issue-context`,
  `assignment-compliance-check`, and `code-review/SKILL.md`. Plus a new rule,
  `.claude/rules/bugsnag/general.md` (the parallel of `rules/jira/general.md`): tooling (`bugsnag` CLI with
  an MCP fallback), comment format, and the default **"only a human changes the error status"**, matching
  the JIRA stance.

The data mapping from Bugsnag onto the existing contract: a Bugsnag **Error** is the "issue"; Bugsnag
**Events / occurrences** carry the stack trace, breadcrumbs, and app/device metadata (the input for a TDD
reproduction); Bugsnag **Comments** are `comments[]`; and the linked GitHub PR is the destination of the
technical review.

## Implementation steps

1. **Decide the tooling and auth** — confirm the `bugsnag` CLI as the primary backend, `BUGSNAG_TOKEN` as
   the env var, and `https://api.bugsnag.com` as the base URL. Verify the token (a personal / data-access
   token, not a notifier API key) is scoped for reading errors, events, and comments, and for writing
   (updating an error, creating a comment).
2. **`load-issue.sh`** — input: a Bugsnag URL or error ID; output: one JSON document with a stable shape
   (`kind`, `id`, `url`, `title`, `body` / error class + message, `status`, `severity`, `assignee`,
   `comments[]`, `latestEvent` with stack trace / breadcrumbs / metadata, `relatedGitHubIssue` /
   `relatedPullRequest`, `createdAt`, `firstSeen`, `lastSeen`). Exit codes 1 = usage, 2 = missing tool,
   3 = API failure, falling back to MCP on 2 and 3.
3. **`upsert-comment.sh`** — input: an error ID / URL, the body on stdin, and a marker; behaviour: a fresh
   comment per run (the GitHub model, not edit-in-place — Bugsnag comments are not keyed per actor). Exit
   codes as on GitHub.
4. **`code-review-bugsnag/SKILL.md`** — a wrapper modelled on `code-review-jira`: detect the linked GitHub
   PR, run the review chain, publish the technical report to the PR and the non-technical one through
   `pr-summary` onto the Bugsnag error.
5. **`pr-summary`** — add `templates/pr-summary-bugsnag.md` and Bugsnag as a recognised destination,
   publishing through `code-review-bugsnag/scripts/upsert-comment.sh`.
6. **Update source detection** in `resolve-issue/references/source-detection.md`: the Bugsnag row becomes
   `Load context via skills/code-review-bugsnag/scripts/load-issue.sh <URL|ID>; fall back to Bugsnag MCP`.
   Unify the detection regex wherever it repeats (`prepare-issue-context`, `assignment-compliance-check`,
   `pr-summary`).
7. **`rules/bugsnag/general.md`** — tooling, comment format, and the default "only a human changes the
   status" (the optional write is enabled explicitly).
8. **`resolve-issue/SKILL.md`** — the report-publishing section: Bugsnag posts the comment directly onto the
   Bugsnag error (instead of "the linked GitHub issue if available"), and optionally marks the error *Fixed*
   through the API after the merge.
9. **Tests and verification** — fixtures for the loader's JSON output, a clean `composer build`, and a
   dry run of the whole `resolve-issue` flow over a real Bugsnag error.

## Sources

- The existing tracker pattern: `skills/code-review-github/scripts/load-issue.sh`,
  `skills/code-review-github/scripts/upsert-comment.sh`, `skills/code-review-jira/scripts/load-issue.sh`,
  `skills/code-review-jira/scripts/upsert-comment.sh`, `skills/pr-summary/SKILL.md`,
  `skills/pr-summary/templates/pr-summary-{github,jira}.md`.
- Detection and orchestration: `skills/resolve-issue/SKILL.md`,
  `skills/resolve-issue/references/source-detection.md`, `skills/prepare-issue-context/SKILL.md`,
  `skills/assignment-compliance-check/SKILL.md`, `skills/code-review/SKILL.md`.
- The JIRA rule as the model: `.claude/rules/jira/general.md`.
- Bugsnag Data Access API: base `https://api.bugsnag.com`, auth `Authorization: token <TOKEN>`
  (Getting Started — developer.smartbear.com/bugsnag/docs/getting-started). Endpoints: organizations,
  projects, errors (list / get / **update** status fix, open, ignore, snooze, plus assign), events
  (list / get with the full stack trace), comments (list / **create**), pivots, trends, releases, stability
  (bugsnagapiv2.docs.apiary.io; tshddx/bugsnag-api; the bugsnag/bugsnag-api-ruby README).
- CLI: `yoanbernabeu/bugsnag-cli` — agent-friendly, JSON by default, reads plus `comments create`, but
  **no** write of the error status. MCP: `tgeselle/bugsnag-mcp` (npx, read / investigate) and the official
  SmartBear MCP.

## Success criteria

- `skills/code-review-bugsnag/scripts/load-issue.sh <bugsnag-url>` returns valid JSON in the same contract
  shape as the GitHub and JIRA loaders (verifiable with `jq`), exit 0.
- `resolve-issue` with a Bugsnag URL or ID loads the error, its comments, and the latest event with its
  stack trace, resolves it through TDD, and opens a PR — with no manual rewrite to a GitHub issue.
- The non-technical report is published **directly onto the Bugsnag error** as a comment, not only onto the
  linked GitHub issue.
- `assignment-compliance-check`, `pr-summary`, and `prepare-issue-context` recognise Bugsnag as a
  first-class source and destination.
- No new ad-hoc REST call outside the script pair; auth through `BUGSNAG_TOKEN` only; no secret in the
  repository.
- `composer build` clean; 100% coverage for new and changed code.
