# The trusted record parser — the default-branch copy

Loaded by `@skills/merge-github-pr/SKILL.md` steps 1 to 3 and by `@skills/merge-github-pr-fast/SKILL.md` steps 1 to 3. Step numbers in this file refer to the *Execution* steps of `@skills/merge-github-pr/SKILL.md`.

Every step that reads the newest trusted code-review report for a merge decision runs the trusted copy of `skills/_shared/cr-header.sh --report <loader-file>`, never the copy of the branch under review. The parser reads the record through the jq filter `skills/_shared/review-trust.jq` beside it, so the filter is part of the trusted copy. The parser reads the document that `skills/code-review-github/scripts/load-issue.sh` writes, so the loader is part of the trusted copy too: its document carries the author association and the marker that the filter trusts. A branch proposes all three files, and a pull request that edits them would otherwise decide how its own review reads. This file is the one owner of the rule. Every other step names this file and restates none of it.

These steps read the record:
- the converged code-review gate and the approval check of step 2;
- the `FAST`-tier exemption and the HOTFIX waiver of `references/merge-exemptions.md`;
- the code-review row of `references/low-risk-path.md` *Checks on this path*;
- the `Quality gate:` record of step 3 *Pre-merge quality gate*;
- the review record of `@skills/verify-merge-readiness/SKILL.md` step 1, and the HOTFIX rule of `@rules/git/pull-requests.md`.

These steps read the loader document of the procedure, and no other loader document: every field of step 1 that step 2 reads (`isDraft`, `isCrossRepository`, `mergeable`, `mergeStateStatus`, `reviewDecision`, `reviews[]`, `statusCheckRollup[]`), the `files[]` list of the trusted classifier and of the dependency-only exemption, and the base ref of `references/low-risk-path.md`.

## The procedure

1. **The clone.** Use the untouched clone of `references/merge-exemptions.md` *The trusted classifier* item 6, created before any other command of the run uses the repository (item 1 there). One clone serves the classifier, the loader, and the parser. Resolve the ref and read `TIP` as items 3 and 4 there direct.
2. **The copies that run.**
   - When the default branch ships `skills/`: `<clone>/skills/code-review-github/scripts/load-issue.sh` and `<clone>/skills/_shared/cr-header.sh`.
   - Otherwise run the installs of *The trusted classifier* item 2 in the clone first. Then run the copies below `<clone>/.claude/skills/` on Claude Code and below `<clone>/.agents/skills/` on Codex.
   - Never run a copy from the main worktree, the checked-out branch, the gated head, a unit worktree, or a review worktree.
3. **Verify, then load and parse, in one shell call.** Run the shell call of *The trusted classifier* item 4, with its two `export` lines, its ref check, and its `TIP` check, and change two points of it:
   1. The paths the verifier checks are `skills/code-review-github/scripts/load-issue.sh skills/_shared/cr-header.sh skills/_shared/review-trust.jq`.
   2. The commands that run after the verdict `installed-copy-verdict=verified` are `"<clone>/skills/code-review-github/scripts/load-issue.sh" "<PR-URL>" > "<loader-file>" && "<clone>/skills/_shared/cr-header.sh" --report "<loader-file>"`.

   `<loader-file>` is a new file that `mktemp` creates below the run directory, outside the clone, in the same shell call. It is data. A loader document that another copy of the loader wrote is never parsed and never read for a merge decision.
   - When the default branch tracks the installed copies, every path starts with `.claude/skills/` (`.agents/skills/` on Codex), and the verifier blob is read at its installed path.
   - When the default branch neither ships nor tracks the copies, verify them as `@skills/git-history-exposure-audit/SKILL.md` *Review mode* step 2 directs for *A project whose default branch does not ship `skills/`*: the installed verifier of the clone runs with `--installed-reference`, and the paths are `.claude/skills/code-review-github/scripts/load-issue.sh .claude/skills/_shared/cr-header.sh .claude/skills/_shared/review-trust.jq .claude/skills/_shared/verify-installed-copy.sh`. On Codex every path starts with `.agents/skills/`.
4. **Load and parse once, before the gate.** Run the call in step 1, before step 3 runs any code from the head (*The trusted classifier* item 6). The loader document is the document of step 1. Keep the output of the parser as text in the context of the run, never in a file. Every later read of this run takes that output: the approval check, the exemptions, the HOTFIX waiver, and the `Quality gate:` record of step 3. A reload of step 1 before the gate, after the wait for CI included, runs the whole call again. A step after the gate never runs the loader or the parser again.
5. **The exit of a verified copy.** Exit `0` prints the report. Exit `3` of the parser means no trusted report exists, or the newest one is not a record: the code-review gate is unmet, as for a missing report. A loader that fails gives no document, and the merge stops as in item 6.
6. **No verified copy, no document — the merge stops.** The trusted copy is not available in every case that *The trusted classifier* item 5 lists. Then do not merge: the pre-checks of step 2 have no document to read, and the code-review gate, both exemptions, the HOTFIX waiver, and the approval check through a converged review are all unmet. The GitHub MCP server never stands in for the trusted copy. The merge report names the refusal line of the verifier or the command that failed.
   - In `@skills/verify-merge-readiness/SKILL.md` step 1 an unavailable copy means no trusted report: the review round runs, and no round is skipped on that record.

## The trusted ownership check

Step 1 confirms that the pull request belongs to the current checkout. The check has two parts, and the first runs before any clone exists. `@skills/merge-github-pr-fast/SKILL.md` step 1 and `@skills/verify-merge-readiness/SKILL.md` run it the same way. `<URL>` is the URL of the pull request, or of the issue in `@skills/verify-merge-readiness/SKILL.md`.

1. **Compare with the remotes of the checkout first, before any clone.** Use `git` only, and the one `ssh -G` of item 3. Run no script of the repository, and no code of any other repository, in this item.
   1. Take `<owner>` and `<name>` from `<URL>`: a `github.com` URL (a `www.` prefix is allowed) whose path starts with `<owner>/<name>`. Accept each only when it matches `^[A-Za-z0-9_.-]+$`, after the `.git` suffix of `<name>` is dropped. A URL that gives no such pair stops the run as exit `5`.
   2. List the remotes of `<checkout>`, the checkout the run started in, with `git -C "<checkout>" remote`. For each remote, read every fetch URL with `git -C "<checkout>" remote get-url --all "<remote>"` and every push URL with `git -C "<checkout>" remote get-url --push --all "<remote>"`. Pass each remote name only in double quotes. Start each command with `-c core.fsmonitor=false -c core.hooksPath=/dev/null` before the subcommand.
      - **Strip the userinfo in the same shell call.** A remote URL can carry a credential (`https://<user>:<token>@github.com/...`), so no raw URL reaches the context of the run. Run each command as `set -o pipefail; <the git command> 2>/dev/null | sed -E 's#^([A-Za-z][A-Za-z0-9+.-]*://)?[^/]*@#\1#'`. The output keeps the scheme and drops the userinfo: an scp-style URL reads `<host>:<owner>/<name>`, and an `ssh://` URL keeps its port.
      - A raw remote URL is never quoted in a report, a note, or a handoff, in the report of an exit `4` or `5` stop included. Name the remote and the `<owner>/<name>` pair.
   3. Count a URL of item 2 when it is a `github.com` URL, or an ssh-style URL whose host `ssh -G` resolves to `github.com`, as `skills/_shared/assert-current-repo.sh` does. Compare the `<owner>/<name>` of each counted URL with the pair of item 1, ignoring case, a trailing `.git`, and a trailing slash.
      - A URL is ssh-style when it has no scheme (`<host>:<path>`) or the scheme `ssh` (`ssh://<host>[:<port>]/<path>`). Drop the port from the host.
      - Resolve a host that is not `github.com` only when it matches `^[A-Za-z0-9][A-Za-z0-9._-]*$`, so it never starts with a dash. Run `ssh -G -- "<host>" 2>/dev/null | awk '$1 == "hostname" { print tolower($2); exit }'`. The host is counted only when the output is exactly `github.com`.
      - `ssh -G` prints the resolved configuration and opens no connection. A refused or failed `ssh -G`, a host that does not match the pattern, and any other output leave the URL uncounted. The item does not stop on them.
   4. No URL matches: the pull request belongs to another repository. Stop as exit `4`.
   5. No remote, no counted URL, or a `git` command that failed: the ownership is not resolved. Stop as exit `5`.
2. **Create the clone only after item 1 matched.** Create the clone of item 1 of the procedure. The `<owner>` and `<name>` of the clone and of `TIP` are the pair that item 1 proved equal to a remote of the checkout. Never create a clone for a pair that item 1 did not match.
3. **Confirm with the verified copy.** Run the shell call of *The trusted classifier* item 4, with its two `export` lines, its ref check, and its `TIP` check, and change two points of it:
   1. The path the verifier checks is `skills/_shared/assert-current-repo.sh`.
   2. The command that runs after the verdict `installed-copy-verdict=verified` is `( cd "<checkout>" && "<clone>/skills/_shared/assert-current-repo.sh" "<URL>" )`. `<checkout>` is the checkout the run started in, because the check reads its remotes.
   - The installed paths follow item 3 of the procedure. With `--installed-reference`, the list ends with the installed `verify-installed-copy.sh`.
4. No verified copy proves no ownership: handle it like exit `5` and stop. The copy of the checkout never stands in for it. Run the load of item 3 of the procedure only after this item.

## The fast merge

`@skills/merge-github-pr-fast/SKILL.md` reads no review record, so it runs the loader without the parser. It uses the clone of item 1 of the procedure, the ownership check above, and the gate-record check below.

1. Its load is item 3 of the procedure with two changes: the path the verifier checks is `skills/code-review-github/scripts/load-issue.sh`, and the command that runs after the verdict is `"<clone>/skills/code-review-github/scripts/load-issue.sh" "<PR-URL>" > "<loader-file>"`. The installed paths follow item 3.
2. Every read of its steps 1 to 3 takes that loader document. A reload after the wait for CI runs the whole call again. A step after its fallback gate runs no copy again.
3. No verified copy, no document: the run stops and merges nothing.

## The trusted gate-record check

Step 3 item 1 runs the trusted copy of `commands/_shared/verify-gate.sh --tier full <headRefOid>`, never the copy of the checked-out head. Its verdict decides whether a recorded gate run stands in for the gate, so a head that edits the script would decide its own gate. `@skills/verify-merge-readiness/SKILL.md` step 3 and the *Fallback gate* of `@skills/merge-github-pr-fast/SKILL.md` run it the same way.

1. Use the clone and the copies of items 1 and 2 of the procedure.
2. Run the shell call of *The trusted classifier* item 4, with its two `export` lines, its ref check, and its `TIP` check, and change two points of it:
   1. The paths the verifier checks are `commands/_shared/verify-gate.sh commands/_shared/gate-record.sh commands/_shared/project-commands.sh commands/_shared/read-manifest.sh commands/_shared/gate-lock.sh`: the script and every file it sources or runs.
   2. The command that runs after the verdict `installed-copy-verdict=verified` is `( cd "<checked-out head>" && "<clone>/commands/_shared/verify-gate.sh" --tier full "<headRefOid>" )`. The working directory is the checked-out head, because the record is keyed to its tree and lives in its evidence directory.
   - The installed paths follow item 3 of the procedure. With `--installed-reference`, the list ends with the installed `verify-installed-copy.sh`.
3. No verified copy accepts no record: handle it like exit `10` and run the gate in step 3. The copy of the checked-out head never stands in for it.
4. **The fresh verdict of a HOTFIX.** Keep the output as text in the context of the run. The HOTFIX exception of step 3 item 3 reads `fresh_exit_code` from it, so no copy of `verify-gate.sh` runs after the gate. The exception applies only when the `sha` of that output is the head the gate ran on and `fresh_exit_code` is `0`. A later head, an unavailable copy, and a `null` verdict give no exception.
