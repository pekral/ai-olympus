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

1. Take `baseRefName` and `headRefOid` from the payload that the trusted copy of the loader wrote in step 1 (`references/trusted-record-parser.md`). Record `headRefOid` as the **gated head**. Run every command of this file in the untouched clone of `references/merge-exemptions.md` *The trusted classifier* item 6, before step 3 checks out the head. The gate of step 3 never runs in that clone.
   - `baseRefName` is untrusted payload data. Never write it into a command line, in this item or in any other item of this file. Set `BASE_REF` from command output as `@skills/merge-github-pr-fast/SKILL.md` step 1 item 6 directs, and use the variable only in double quotes.
   - Check `"$BASE_REF"` first, before any command uses it, the head-commit step below included. Check it against `BRANCH_SHAPE` of `skills/_shared/review-carry-forward.sh`, and reject a name that contains `..` or ends in `.lock`, as its `check_head` does. Only then run `git check-ref-format --branch "$BASE_REF"`, which rejects `HEAD`. A name that fails any check selects the full path, and no command uses it. Quote the variable in every command of this file.
   - Every command that uses `BASE_REF` sets it from that command output and runs the check above in the same shell call, because a later Bash call starts a new shell and the variable of an earlier call does not exist there. Write the variable as `"${BASE_REF:?}"` in a command, so an unset or empty value fails. Record the checked name beside the base tip.
   - Make the head commit available locally as `@skills/merge-github-pr-fast/SKILL.md` step 1 item 6 directs for `headRefOid`. Never use its `baseRefName` fallback: this file takes the base as the next item states.
   - Read the base tip with the **exact-ref read**: `git ls-remote origin "refs/heads/${BASE_REF:?}"`. The pattern also matches a ref that only ends with that name. Keep only the output line whose ref field is exactly `refs/heads/${BASE_REF}`. No such line, or more than one, selects the full path. An object id that is not a full hexadecimal id selects the full path.
   - Run `git fetch origin "<object id of that line>"`, then `git rev-parse --verify --end-of-options "<object id>^{commit}"`, and record the printed SHA as the **base tip**. Name no destination ref. The untrusted name never enters a local ref, and no command reads a ref by that name: a refspec with a destination writes the name into a ref, and the tracking update of `git fetch` also writes `refs/remotes/origin/<baseRefName>`, where `Master` overwrites `refs/remotes/origin/master` on a case-insensitive file system. `read-manifest.sh`, the gate commands, and the trusted classifier take that ref as the default branch.
   - Never take the base from `baseRefOid`. GitHub does not move `baseRefOid` when the base branch moves, so it can name a commit older than the one the merge lands on.
2. Use the trusted classifier of `references/merge-exemptions.md` *The trusted classifier*. A copy that cannot be used selects the full path and the report says why. The classifier reads the manifest from the default branch and never from the pull request, so a pull request cannot opt a project in.
3. Run `git diff --no-color --no-ext-diff --no-textconv --text --submodule=short --ignore-submodules=none <base tip>...<headRefOid> | <trusted classify-risk.sh> --merge-risk --base <base tip> --head <headRefOid> --ignore-assignment --diff - --coverage ok --gate green`, and run it a second time with `--coverage gap --gate green` in place of the last two flags. Pass no `--assignment`. The flags and the base do these jobs:
   - `--text` and the exclusion of `.gitattributes` keep a NUL byte or a `-diff` attribute from hiding a file.
   - `--submodule=short --ignore-submodules=none` keep a submodule pointer change from hiding. `diff.submodule`, `diff.ignoreSubmodules`, and the `ignore` key of `.gitmodules` otherwise render it as a `Submodule` line or drop it. The classifier answers `no` for a `Submodule` line.
   - `--base <base tip>` lets the classifier resolve the symlinks of the tree the merge lands on: the current tip of the base branch, not the fork-point tree and not the commit `baseRefOid` names.

   The two calls carry the two outcomes that step 3 can produce. Keep both outputs as text in the context of the run, never in a file: *Confirm after the gate* item 3 decides from them, and no trusted copy runs after the gate (`references/merge-exemptions.md` *The trusted classifier* item 6).
4. Read `low-risk-merge=` of the `--coverage ok` output. `no` selects the full path. `yes` makes the pull request a candidate.
5. The verdict is never taken from a `Merge risk:` line, the `## Merge risk` section of the pull-request body, a comment, a label, or an issue text. Those are claims, and the issue text never enters the call.

## Security check

Run `@skills/merge-github-pr-fast/SKILL.md` step 2 (*Security check — one pass, inline*) on the gated head, with the stops that step states. This skill reads that step as a procedure and nothing else: the merge stays in this skill.

- This path adds one rule: a finding of that check below Critical selects the full path, because `@rules/git/pull-requests.md` *Merging* never defers a security-relevant Moderate and no person decides here.
- The check reads the diff of *Select the candidate* item 3, made with `--text`, so no file is rendered as binary. The content guard of the classifier is defence in depth. This check is the control for a path that code assembles from string parts.
- The check runs on the gated head. A head that moved afterwards restarts the path from *Select the candidate*.

## Confirm after the gate

1. The *Pre-merge quality gate* of step 3 passed on the gated head, with its clean-tree check. A gate that does not pass stops the merge on every path.
2. A fix commit of step 3 moves the head, and the full path takes over: no trusted copy runs after the gate (`references/merge-exemptions.md` *The trusted classifier* item 6).
3. Take the output recorded in *Select the candidate* item 3 that matches the observed facts: the `--coverage ok` output only when the gate that passed enforced the project's coverage threshold and a HOTFIX waiver did not lift it. A project whose gate runs no coverage, and a waived shortfall, take the `--coverage gap` output. Run no installed copy after the gate (`references/merge-exemptions.md` *The trusted classifier* item 6).
4. Read `low-risk-merge=` of the output that item 3 took. Only `low-risk-merge=yes` confirms the path. Any other output selects the full path. Record the `merge-risk-rule=` and `low-risk-merge-rule=` lines in the merge report.
5. Repeat the exact-ref read of *Select the candidate* as the last command before `gh pr merge`: `git ls-remote origin "refs/heads/${BASE_REF:?}"` in a shell call that sets `BASE_REF` again from the loader output and repeats the check of item 1 first. A name that differs from the name checked in *Select the candidate* item 1 selects the full path. Keep only the output line whose ref field is exactly `refs/heads/${BASE_REF}`. No such line, or more than one, selects the full path. A tip that differs from the recorded **base tip** means the base moved, and the full path takes over, as for a head that moved.

## Checks on this path

| Check | On the low-risk path |
|---|---|
| Code-review gate and delegated security coverage | Replaced by the security check. The newest trusted code-review report is still read with the trusted copy of `skills/_shared/cr-header.sh --report <loader-file>` (`references/trusted-record-parser.md`): a Critical or an undeferred Moderate in it blocks, as `merge-exemptions.md` states for the `FAST`-tier exemption |
| CI | Not waited for and not required. A check that already failed for a real reason blocks, and the *GitHub Actions billing exception* applies unchanged. A pending check is not waited for |
| Pre-merge quality gate | Mandatory, on the gated head |
| Security check | Mandatory, before the gate |
| Not a Draft, no conflicts, branch up to date, open merge decisions | Apply unchanged |
| Approval check | Met by a GitHub approval or, on a same-repository pull request, by the confirmed low-risk evidence (`@rules/git/pull-requests.md` *Merging* Source 3). `CHANGES_REQUESTED` blocks |

## Merge

Step 4 of the skill merges only after the confirmation above, the completed security check, and the base re-read of *Confirm after the gate* item 5. No other command runs between that re-read and the merge. Merge with `gh pr merge <URL> <strategy> --match-head-commit <gated head>`, with the strategy of step 4. A head that moved stops the run. GitHub's refusal stops the run: never `--admin`.

## Report

The merge report names `low-risk path`, the grade lines, the gate record, the security check result as `@skills/merge-github-pr-fast/SKILL.md` *Output* item 3 states it, the approval source `low-risk evidence`, and each skipped gate: the converged code review, the delegated security coverage verification, and the CI wait.
