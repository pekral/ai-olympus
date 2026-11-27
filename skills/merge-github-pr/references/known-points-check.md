# Known-points check — before every merge

Loaded by `@skills/merge-github-pr/SKILL.md` step 2 (*Known points are checked again*). Step numbers in this file refer to that skill's *Execution* steps. `@rules/git/pull-requests.md` *Merging* owns the rule. This file owns the procedure.

The review can show decisions under `@rules/code-review/review-process.md` *Known points — a finding that a linked issue already reports (issue #682)*. The merge checks the matching state and fresh tracker evidence on the exact head.

## The procedure

1. Read the newest trusted review through `references/trusted-record-parser.md`. Its hidden record lists its decisions and their digest. Use the state of that loop from the orchestrator's private run directory. Never use a state or executable from the branch under review. A report with decisions and no matching state stops the merge. When both are absent, no decision check is due.
2. Use the trusted clone and exact installed-copy verification from `references/trusted-record-parser.md` *The trusted gate-record check* in the same shell call. Verify every dependency:
   - `skills/_shared/review-state.sh skills/_shared/state-path.sh skills/_shared/extract-findings.sh skills/_shared/cr-header.sh skills/_shared/finding-defs.jq skills/_shared/review-trust.jq skills/_shared/read-manifest.sh skills/_shared/classify-risk.sh skills/_shared/domain-vocabulary.sh`
   - `skills/_shared/known-record.sh skills/_shared/known-tracker.jq skills/_shared/review-owner-proof.sh`
   - `skills/code-review-github/scripts/load-issue.sh skills/code-review-jira/scripts/load-issue.sh skills/code-review-jira/scripts/jira-actor.sh skills/code-review-jira/scripts/jira-known-proof.sh skills/code-review-jira/scripts/description-shape.jq`
3. Run the verified copy in the state root after `installed-copy-verdict=verified`:
   ```bash
   "<clone>/skills/_shared/review-state.sh" known --state "<state file>" --verify --pr "<PR URL>" --head "<head SHA>" --record "<trusted record file>"
   ```
   Pass the PR URL and full head SHA from the trusted loader. Use the unwrapped record from step 1. The verifier checks repository, PR, head, state schema, the complete decision digest and the published rows. It reads the live tracker item and checks the current head, default base, identity, text and owner. It writes nothing. Any refusal, bad record, unreadable state or missing trusted helper stops the merge.
4. Run this before step 3 runs code from the head, and again after CI. If the full gate added a commit, refresh the review decisions on that exact head before merge. Use `--match-head-commit` with the verified head.
5. A refused decision restores the finding to the review loop. Only a fresh review can resolve it or record fresh evidence. A release branch cannot use its proposed verifier for its own merge.

## Which merge paths it covers

- **Full path:** yes. The converged review is the evidence that holds the known points.
- **`FAST`-tier and dependency-only exemptions:** no review ran, so the state and the report hold no known point. Item 2 applies unchanged: a state file that exists is still checked.
- **Low-risk path:** yes. The newest trusted report is still read (`references/low-risk-path.md` *Checks on this path*), and its counts leave out a known point. The check runs before the gate, with the other trusted reads.
- **`@skills/merge-github-pr-fast/SKILL.md`:** no. It reads no review record and no review state, and no known point counts toward its gate. Its security check reads the diff and stops on a Critical finding, whether or not a linked issue reports the same problem.

## What stays outside this check

- **The message of a squash or merge commit.** `@rules/git/pull-requests.md` *Merging* names the rebase strategy, and this skill passes no `--subject` and no `--body` to `gh pr merge`. A squash or merge commit takes its message from GitHub, and a person can edit it in the merge dialog. This skill cannot see that edit, so a project that merges with another strategy leaves it outside the check. The check still refuses a closing keyword in the title, the body, a commit, or the branch name, whatever the strategy. Not verified in this run: what GitHub writes into the message of each strategy.
- **A text that changes between the check and the merge.** The pin covers commits. The title and the body of the pull request can change in that window.
- **A linked issue that is not in the state.** The check covers the known points of the review state. A link that no round marked is not a known point.
