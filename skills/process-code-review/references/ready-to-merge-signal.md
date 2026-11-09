# Ready-to-merge signal on the pull request

Referenced from `skills/process-code-review/SKILL.md` *Promote the PR out of Draft*. Extracted to keep the skill body under the skill-check token limit.

It runs at exactly one moment: the Review loop converged (`skills/process-code-review/SKILL.md` *Review loop* step 4 — zero Critical, zero unfulfilled reviewer comments, no undeferred Moderate), and the merge has not happened yet. The signal lands on the pull request only. No agent writes a status on the source tracker item once the code review has started (`@rules/compound-engineering/tracker.md` *No status write once the code review has started*): no GitHub issue label, no JIRA transition, and no Bugsnag status.

The write is unconditional, idempotent, and verified. A zero exit code is not evidence: an external write can be silently blocked in auto-mode.

### Pull-request label
The pull request is on GitHub whatever the source tracker is, so this write runs for a GitHub, a JIRA, and a Bugsnag source, and also when there is no source tracker item.
1. **Create the label when the repository lacks it**, then ignore the "already exists" outcome on every later run: `gh label create "ready to merge" --description "The code review converged; the PR is waiting to be merged" --color 1d76db`.
2. **Apply it to the pull request:** `gh pr edit <PR-NUMBER|URL> --add-label "ready to merge"`.
3. **Re-read and verify the label landed** via `skills/code-review-github/scripts/load-issue.sh <PR-URL>`. When it did not land, report the failed write in the completion report; the PR stays promoted.

### Revert when the review re-opens
A commit landing after the promotion can re-open the review (*Finalization* — **Only a gate fix that changes business logic re-opens the review**). The pull-request signal then states something untrue, so withdraw it:

1. `gh pr ready --undo <PR-NUMBER|URL>` returns the PR to Draft, and `gh pr edit <PR-NUMBER|URL> --remove-label "ready to merge"` removes the label.
2. Verify the withdrawal by re-reading through the deterministic loader. When it does not land, report the stale signal in the completion report and never report convergence.

The source tracker item is left as it is in both directions.

A fix commit carrying only the verbatim output of the project's fixers re-opens nothing, so it never triggers this revert — the converged verdict stands and the signal stays.

**The detector is not always the owner.** The role that notices that the effective PR diff fingerprint changed may sit outside this skill (typically the implementing agent, during the pre-merge quality gate). It reports the staleness; it does not write or withdraw the signal itself. A content-identical history rewrite preserves the signal and starts no CR round.
