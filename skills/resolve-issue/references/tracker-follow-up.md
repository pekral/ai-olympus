# Per-tracker follow-up after the PR is open

Referenced from `skills/resolve-issue/SKILL.md` *Per-tracker follow-up*. Extracted to keep the skill body under the skill-check token limit; the procedure is unchanged. Every step here runs once the pull request exists, right after the non-technical report is posted.

### GitHub-specific follow-up
- Once the PR is open, mark the source issue as waiting for code review (per `@rules/compound-engineering/general.md` *Tracker status tracks the phase of work*). This is phase 2 of that invariant, so it always runs — never conditional on the repository already carrying the label:
  1. **Create the label when the repository lacks it**, then ignore the "already exists" outcome on every later run: `gh label create "ready for review" --description "The PR for this issue is open and waiting for a reviewer" --color 0e8a16`. This mirrors the `EPIC` label creation in `@skills/create-issues-from-text/SKILL.md` *EPIC parent & sub-issues*.
  2. **Apply it to the source issue:** `gh issue edit <N> --add-label "ready for review"`.
  3. **Re-read and verify the label landed** via `skills/code-review-github/scripts/load-issue.sh <URL>` — external writes can be silently blocked in auto-mode, so the command's exit code is not evidence. This is the same apply-then-verify discipline the claim label uses in step 1. When it did not land, report the failed phase write in the handoff; the PR itself stays open and is not rolled back.
- Leave the `Resolve_by_AI:in-progress` claim label in place. It is no longer the active work-state signal once `ready for review` is applied, and removing it would make the issue an unclaimed candidate again.

### JIRA-specific follow-up
- Link the created PR back to the JIRA issue.
- Once the PR is open, move the issue to the project's Code Review status by running `skills/code-review-jira/scripts/transition-to-code-review.sh <KEY|URL>`.
  This is JIRA's phase-2 write under `@rules/compound-engineering/general.md` *Tracker status tracks the phase of work*. This is the second sanctioned status transition (the first is the In Progress claim at the start of work via `skills/code-review-jira/scripts/transition-to-in-progress.sh`; per `@rules/jira/general.md`); the helper refuses any non-review target and only reports success after confirming the issue actually reached the review column. When it exits 5 — the review-status name differs for this project and could not be auto-resolved — discover the real name via the JIRA MCP server's available-transitions and re-run with it as the `STATUS` argument, or ask a human. Perform no other status transition; all others remain human-only.

### Bugsnag-specific follow-up
- The created PR is linked through the Bugsnag error's existing GitHub integration (`linkedIssues[]`); do not invent a second link.
- Do not change the Bugsnag error status (fixed / ignored / snoozed) automatically — like JIRA transitions, marking an error fixed is left to a human after the fix is verified in production. Bugsnag therefore has no review-waiting phase write: its status enum carries no in-review value, so the comment posted above on the error and on the linked GitHub issue is the substitute signal. This is the named Bugsnag exception in `@rules/compound-engineering/general.md` *Tracker status tracks the phase of work*.
