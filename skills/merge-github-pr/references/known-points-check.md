# Known-points check — before every merge

Loaded by `@skills/merge-github-pr/SKILL.md` step 2 (*Known points are checked again*). Step numbers in this file refer to that skill's *Execution* steps. `@rules/git/pull-requests.md` *Merging* owns the rule. This file owns the procedure.

A known point is a finding that a trusted, open linked issue already reports, a security finding included (`@rules/code-review/review-process.md` *Known points — a finding that a linked issue already reports (issue #682)*). It never blocks the merge. That is only true while the pull request does not close the linked issue itself. A closing keyword can reach the pull request after the last review round, so the merge checks every known point again, on the current text of the pull request.

## What runs

`skills/_shared/review-state.sh known --state <state file> --verify --pr <PR URL>` runs the checks of `known` on every known point of the state again, through the loaders: the same function that marks a point and that every round and every evaluation use. It reads the pull request fresh. It writes nothing. It prints `{"checked", "urls", "refused"}` and exits `0` when every point holds, and `4` when a point is refused or a loader fails.

It exits `3` when the state is missing or invalid, when its path is one that every writing command refuses (a file that git tracks, a symbolic link, a path outside the git-ignored run directory), when its repository or pull request is not `<PR URL>`, and when a finding has the status `known` without exactly one known point. The state alone never selects the pull request: `<PR URL>` is the URL from the trusted loader output of step 1, and without it the command is a usage error (exit `1`).

## The procedure

1. **Find the state.** Use the state file that the review loop wrote for this pull request, in the tree where it wrote it: the run directory of the trusted tree of the orchestrator (`@skills/process-code-review/references/review-state.md` *The commands, in loop order*). Never look for it in the checked-out head, which the branch controls, and never take a file the branch adds. The script binds a state to its pull request by the repository and the number inside it (`--pr`), never by the file name, so the name that the orchestrator gave it needs no second source. Read the `## Known points` section of the newest trusted code-review report that step 2 already parsed, and note each link in it.
2. **Decide whether a check is due.**
   - A state file exists: run the check.
   - No state file, and the report has no `## Known points` entry (or no report exists): nothing is checked, and the merge report says `known points: none`.
   - No state file, and the report lists a known point: the point cannot be checked. Do not merge. Report that the review state is missing and that the review loop must run again on this machine.
3. **Run the trusted copy**, never the copy of the branch under review, in the same way as `references/trusted-record-parser.md` *The trusted gate-record check*. Use its clone and its shell call, and change two points:
   1. The paths the verifier checks are `skills/_shared/review-state.sh skills/_shared/state-path.sh skills/_shared/extract-findings.sh skills/_shared/cr-header.sh skills/_shared/finding-defs.jq skills/_shared/review-trust.jq skills/_shared/read-manifest.sh skills/_shared/classify-risk.sh skills/_shared/domain-vocabulary.sh skills/code-review-github/scripts/load-issue.sh skills/code-review-jira/scripts/load-issue.sh skills/code-review-jira/scripts/jira-actor.sh`: the script and every file it sources, runs, or includes.
   2. The command that runs after the verdict `installed-copy-verdict=verified` is `( cd "<state root>" && "<clone>/skills/_shared/review-state.sh" known --state "<state file>" --verify --pr "<PR URL>" )`. `<state root>` is the git toplevel of the tree that holds the state, `<state file>` is its absolute path, and `<PR URL>` is the pull request URL of the trusted loader output of step 1. Never run it with `cd` into the checked-out head.
4. **Run it before step 3 runs any code from the head**, on the head that step 1 loaded, and keep the output as text in the context of the run. Run it again after the wait for CI, with the other reads of step 2.
5. **Read the verdict.** All of these stop the merge, and the merge report names the reason:
   - an exit other than `0`, a copy that is not verified, a state that cannot be read or that belongs to another pull request, or a loader that fails: the check did not pass;
   - a non-empty `refused` list: name each `url` and its `reason`, and say that the pull request or the link changed since the review;
   - a link in the `## Known points` section that is not in `urls`: the state does not hold that point.
   The review then runs again to convergence (`@skills/process-code-review/SKILL.md`), and the finding is a finding again until a round marks it known.
6. **Pin the merge to the head that was checked.** Step 4 merges with `--match-head-commit`, so a commit that the pull request gains after this check cannot reach the merge. Step 3 item 5 can add one commit of its own after this check. The merge pins the head that includes it, and the subjects that item prescribes name no closing keyword.

## Which merge paths it covers

- **Full path:** yes. The converged review is the evidence that holds the known points.
- **`FAST`-tier and dependency-only exemptions:** no review ran, so the state and the report hold no known point. Item 2 applies unchanged: a state file that exists is still checked.
- **Low-risk path:** yes. The newest trusted report is still read (`references/low-risk-path.md` *Checks on this path*), and its counts leave out a known point. The check runs before the gate, with the other trusted reads.
- **`@skills/merge-github-pr-fast/SKILL.md`:** no. It reads no review record and no review state, and no known point counts toward its gate. Its security check reads the diff and stops on a Critical finding, whether or not a linked issue reports the same problem.

## What stays outside this check

- **The message of a squash or merge commit.** `@rules/git/pull-requests.md` *Merging* names the rebase strategy, and this skill passes no `--subject` and no `--body` to `gh pr merge`. A squash or merge commit takes its message from GitHub, and a person can edit it in the merge dialog. This skill cannot see that edit, so a project that merges with another strategy leaves it outside the check. The check still refuses a closing keyword in the title, the body, a commit, or the branch name, whatever the strategy. Not verified in this run: what GitHub writes into the message of each strategy.
- **A text that changes between the check and the merge.** The pin covers commits. The title and the body of the pull request can change in that window.
- **A linked issue that is not in the state.** The check covers the known points of the review state. A link that no round marked is not a known point.
