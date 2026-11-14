# Low-risk merge path

Loaded by `@skills/merge-github-pr/SKILL.md` step 2 (*Merge path*). Step numbers in this file refer to that skill's *Execution* steps. `@rules/git/pull-requests.md` *Low-risk merge path* owns the rule. This file owns the procedure.

The path is **opt-in and an allow-list**. It is off unless the default-branch manifest of the project sets `merge.low-risk-path: true` and also declares `risk.critical-paths` (`@rules/general/general.md` *Project manifest*). Then `skills/_shared/classify-risk.sh --merge-risk` prints `low-risk-merge=yes` only for a diff of regular files inside a small set of application source and test paths, with the exclusions its header lists. Its header is the one owner of that list (`LOW_RISK_ALLOWED_RE`, `LOW_RISK_EXCLUDED_RE`, `LOW_RISK_GUARD_RE`). This file restates none of it.

| Verdict of the trusted classifier | Path |
|---|---|
| `low-risk-merge=yes` | Low-risk path (this file) |
| `low-risk-merge=no`, any reason (`not-enabled` and `no-critical-paths` included) | Full path |

The full path is the gate of step 2 and step 3 as `@rules/git/pull-requests.md` *Merging* states it: a converged code review with no open finding, and green required CI on the head.

## The order is fixed

No code from the head runs before the security check has read the diff. The steps below run in this order, and a stop at any step selects the full path unless the step says it stops the merge.

1. Select the candidate (*Select the candidate*). Nothing from the head runs; the classifier reads diff text.
2. Run the security check (*Security check*). Nothing from the head runs; the check reads the diff.
3. Run the *Pre-merge quality gate* of step 3 on the gated head. This is the first step that executes code from the head.
4. Confirm the verdict with the facts the gate produced (*Confirm after the gate*).
5. Merge (*Merge*).

## Select the candidate

1. Take `baseRefOid` and `headRefOid` from the payload loaded in step 1. Record `headRefOid` as the **gated head**. Make both commits available locally as `@skills/merge-github-pr-fast/SKILL.md` step 1 item 6 directs. Run every command of this file from the repository root, before step 3 checks out the head.
2. Use the trusted classifier of `references/merge-exemptions.md` *The trusted classifier*. A copy that cannot be used selects the full path and the report says why. The classifier reads the manifest from the default branch and never from the pull request, so a pull request cannot opt a project in.
3. Run `git diff --no-color --no-ext-diff --no-textconv --text <baseRefOid>...<headRefOid> | <trusted classify-risk.sh> --merge-risk --base "$(git merge-base <baseRefOid> <headRefOid>)" --ignore-assignment --diff - --coverage ok --gate green`. Pass no `--assignment`. `--text` and the exclusion of `.gitattributes` keep a NUL byte or a `-diff` attribute from hiding a file, and `--base` lets the classifier resolve the symlinks of the base tree. On this first call the two verification facts are the ones that step 3 produces, so the call decides only whether the diff can take the path.
4. Read `low-risk-merge=` of the output. `no` selects the full path. `yes` makes the pull request a candidate.
5. The verdict is never taken from a `Merge risk:` line, a `Merge danger` section, a comment, a label, or an issue text. Those are claims, and the issue text never enters the call.

## Security check

Run `@skills/merge-github-pr-fast/SKILL.md` step 2 (*Security check — one pass, inline*) on the gated head, with the stops that step states. This skill reads that step as a procedure and nothing else: the merge stays in this skill.

- This path adds one rule: a finding of that check below Critical selects the full path, because `@rules/git/pull-requests.md` *Merging* never defers a security-relevant Moderate and no person decides here.
- The check reads the diff of *Select the candidate* item 3, made with `--text`, so no file is rendered as binary. The content guard of the classifier is defence in depth. This check is the control for a path that code assembles from string parts.
- The check runs on the gated head. A head that moved afterwards restarts the path from *Select the candidate*.

## Confirm after the gate

1. The *Pre-merge quality gate* of step 3 passed on the gated head, with its clean-tree check. A gate that does not pass stops the merge on every path.
2. A fix commit of step 3 moves the head. Start again from *Select the candidate* with the new head. Step 3 item 7 does not apply on this path, because this path relies on no review.
3. Run the trusted classifier again with the observed facts: `--gate green`, and `--coverage ok` only when the gate that passed enforced the project's coverage threshold and a HOTFIX waiver did not lift it. A project whose gate runs no coverage, and a waived shortfall, give `--coverage gap`.
4. Only `low-risk-merge=yes` confirms the path. Any other output selects the full path. Record the `merge-risk-rule=` and `low-risk-merge-rule=` lines in the merge report.

## Checks on this path

| Check | On the low-risk path |
|---|---|
| Code-review gate and delegated security coverage | Replaced by the security check. A trusted code-review comment that exists is still read: a Critical or an undeferred Moderate in the newest one blocks, as `merge-exemptions.md` states for the `FAST`-tier exemption |
| CI | Not waited for and not required. A check that already failed for a real reason blocks, and the *GitHub Actions billing exception* applies unchanged. A pending check is not waited for |
| Pre-merge quality gate | Mandatory, on the gated head |
| Security check | Mandatory, before the gate |
| Not a Draft, no conflicts, branch up to date, open merge decisions | Apply unchanged |
| Approval check | Met by a GitHub approval or, on a same-repository pull request, by the confirmed low-risk evidence (`@rules/git/pull-requests.md` *Merging* Source 3). `CHANGES_REQUESTED` blocks |

## Merge

Step 4 of the skill merges only after the confirmation above and the completed security check. Merge with `gh pr merge <URL> <strategy> --match-head-commit <gated head>`, with the strategy of step 4. A head that moved stops the run. GitHub's refusal stops the run: never `--admin`.

## Report

The merge report names `low-risk path`, the grade lines, the gate record, the security check result as `@skills/merge-github-pr-fast/SKILL.md` *Output* item 3 states it, the approval source `low-risk evidence`, and each skipped gate: the converged code review, the delegated security coverage verification, and the CI wait.
