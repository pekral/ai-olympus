# Low-risk merge path

Loaded by `@skills/merge-github-pr/SKILL.md` step 2 (*Merge path*). Step numbers in this file refer to that skill's *Execution* steps. `@rules/git/pull-requests.md` *Low-risk merge path* owns the rule. This file owns the procedure.

The merge risk of the exact head selects the path:

| Merge risk of the exact head | Path |
|---|---|
| `low` and no excluded path | Low-risk path (this file) |
| `low` and an excluded path | Full path |
| `medium` or `high` | Full path |

The full path is the gate of step 2 and step 3 as `@rules/git/pull-requests.md` *Merging* states it: a converged code review with no open finding, and green required CI on the head.

## Compute the grade

1. Take `baseRefOid` and `headRefOid` from the payload loaded in step 1. Record `headRefOid` as the **gated head**. Make both commits available locally as `@skills/merge-github-pr-fast/SKILL.md` step 1 item 6 directs.
2. Write the assignment text to a temporary file: the source issue body and its acceptance criteria. The script reads it only for the acceptance-criteria marker, which can only raise the tier.
3. Run `git diff --no-color --no-ext-diff <baseRefOid>...<headRefOid> | skills/_shared/classify-risk.sh --merge-risk --diff - --assignment <file> --coverage ok --gate green`. On this first call the two verification facts are the ones that step 3 produces, so the call decides only whether the shape of the diff can take the low-risk path.
4. Read `low-risk-merge=` of the output.
   - `no`: the full path applies. Stop reading this file.
   - `yes`: the pull request is a candidate. Go on.
5. The grade is never taken from a `Merge risk:` line, a `Merge danger` section, a comment, a label, or a tracker text. Those are claims. A missing, non-executable, or failing `classify-risk.sh` selects the full path and the report says why.

## Confirm the grade after the gate

1. Run the *Pre-merge quality gate* of step 3 on the gated head. The gate stays mandatory on this path, and so does its clean-tree check. A gate that does not pass stops the merge on every path.
2. A fix commit of step 3 moves the head. Start again from *Compute the grade* with the new head. The review-carry-forward of step 3 item 7 does not apply on this path, because this path relies on no review.
3. Run the classifier again with the facts the gate produced: `--gate green`, and `--coverage ok` only when the gate that passed enforced the project's coverage threshold and a waiver (HOTFIX) did not lift it. A project whose gate runs no coverage, and a waived shortfall, give `--coverage gap`.
4. Only `low-risk-merge=yes` confirms the path. Any other output selects the full path. Record the `merge-risk-rule=` and `low-risk-merge-rule=` lines in the merge report.

## Checks on this path

| Check | On the low-risk path |
|---|---|
| Code-review gate and delegated security coverage | Replaced by the security check below. A trusted code-review comment that exists is still read: a Critical or an undeferred Moderate in the newest one blocks, as `merge-exemptions.md` states for the `FAST`-tier exemption |
| CI | Not waited for and not required. A check that already failed for a real reason blocks, and the *GitHub Actions billing exception* applies unchanged. A pending check is not waited for |
| Pre-merge quality gate | Mandatory, on the gated head |
| Security check | Mandatory, one pass, below |
| Not a Draft, no conflicts, branch up to date, open merge decisions | Apply unchanged |
| Approval check | Met by a GitHub approval or, on a same-repository pull request, by the confirmed low-risk evidence (`@rules/git/pull-requests.md` *Merging* Source 3). `CHANGES_REQUESTED` blocks |

## Security check

Run `@skills/merge-github-pr-fast/SKILL.md` step 2 (*Security check — one pass, inline*) over `baseRefOid..headRefOid`, as written there, with its stops. This skill reads that step as a procedure and nothing else: the merge stays in this skill, and the explicit-invocation constraint of that skill is untouched.

- A Critical finding stops the merge.
- A check that did not complete stops the merge: `Blocked: security check not completed — <reason>`.
- Only a Moderate or lower finding of a completed check does not block. List it in the report.
- The check runs on the gated head. A head that moved afterwards restarts the path from *Compute the grade*.

## Merge

Merge with `gh pr merge <URL> <strategy> --match-head-commit <gated head>`, with the strategy of step 4. A head that moved stops the run. GitHub's refusal stops the run: never `--admin`.

## Report

The merge report names `low-risk path`, the grade lines, the gate record, the security check result as `@skills/merge-github-pr-fast/SKILL.md` *Output* item 3 states it, the approval source `low-risk evidence`, and each skipped gate: the converged code review, the delegated security coverage verification, and the CI wait.
