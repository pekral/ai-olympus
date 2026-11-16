# The trusted record parser — the default-branch copy

Loaded by `@skills/merge-github-pr/SKILL.md` step 2 (*Pre-checks*). Step numbers in this file refer to that skill's *Execution* steps.

Every step that reads the newest trusted code-review report for a merge decision runs the trusted copy of `skills/_shared/cr-header.sh --report <loader-file>`, never the copy of the branch under review. The parser reads the record through the jq filter `skills/_shared/review-trust.jq` beside it, so the filter is part of the trusted copy. A branch proposes both files, and a pull request that edits them would otherwise decide how its own review reads. This file is the one owner of the rule. Every other step names this file and restates none of it.

These steps read the record:
- the converged code-review gate and the approval check of step 2;
- the `FAST`-tier exemption and the HOTFIX waiver of `references/merge-exemptions.md`;
- the code-review row of `references/low-risk-path.md` *Checks on this path*;
- the `Quality gate:` record of step 3 *Pre-merge quality gate*;
- the review record of `@skills/verify-merge-readiness/SKILL.md` step 1, and the HOTFIX rule of `@rules/git/pull-requests.md`.

## The procedure

1. **The clone.** Use the untouched clone of `references/merge-exemptions.md` *The trusted classifier* item 6, created before any other command of the run uses the repository (item 1 there). One clone serves the classifier and the parser. Resolve the ref and read `TIP` as items 3 and 4 there direct.
2. **The copy that runs.**
   - When the default branch ships `skills/`: `<clone>/skills/_shared/cr-header.sh`.
   - Otherwise run the installs of *The trusted classifier* item 2 in the clone first. Then run `<clone>/.claude/skills/_shared/cr-header.sh` on Claude Code and `<clone>/.agents/skills/_shared/cr-header.sh` on Codex.
   - Never run a copy from the main worktree, the checked-out branch, the gated head, a unit worktree, or a review worktree.
3. **Verify, then parse, in one shell call.** Run the shell call of *The trusted classifier* item 4, with its two `export` lines, its ref check, and its `TIP` check, and change two points of it:
   1. The paths the verifier checks are `skills/_shared/cr-header.sh skills/_shared/review-trust.jq`.
   2. The command that runs after the verdict `installed-copy-verdict=verified` is `"<clone>/skills/_shared/cr-header.sh" --report "<loader-file>"`.

   `<loader-file>` is the output of `skills/code-review-github/scripts/load-issue.sh` from step 1. It is data, so it may live outside the clone.
   - When the default branch tracks the installed copies, every path starts with `.claude/skills/` (`.agents/skills/` on Codex), and the verifier blob is read at its installed path.
   - When the default branch neither ships nor tracks the copies, verify them as `@skills/git-history-exposure-audit/SKILL.md` *Review mode* step 2 directs for *A project whose default branch does not ship `skills/`*: the installed verifier of the clone runs with `--installed-reference`, and the paths are `.claude/skills/_shared/cr-header.sh .claude/skills/_shared/review-trust.jq .claude/skills/_shared/verify-installed-copy.sh`. On Codex every path starts with `.agents/skills/`.
4. **Parse once, before the gate.** Run the parse in step 2, before step 3 runs any code from the head (*The trusted classifier* item 6). Keep the output as text in the context of the run, never in a file. Every later read of this run takes that output: the approval check, the exemptions, the HOTFIX waiver, and the `Quality gate:` record of step 3. A step after the gate never runs the parser again.
5. **The exit of a verified copy.** Exit `0` prints the report. Exit `3` means no trusted report exists, or the newest one is not a record: the code-review gate is unmet, as for a missing report.
6. **No verified copy, no report — the merge stops.** The trusted copy is not available in every case that *The trusted classifier* item 5 lists. Then do not merge: the code-review gate, both exemptions, the HOTFIX waiver, and the approval check through a converged review are all unmet. The merge report names the refusal line of the verifier or the command that failed.
   - In `@skills/verify-merge-readiness/SKILL.md` step 1 an unavailable copy means no trusted report: the review round runs, and no round is skipped on that record.
