# Close the whole class of defect — in the first fix round

Referenced from `SKILL.md` *Reproducer extraction* and from `agents/donatello.md` *Fix round*. This file is the one owner of the rule. Every other file points here and never restates it.

A review finding names one example of a defect. A fix of that example alone leaves the other instances of the same kind open. The next round then finds one more edge case of the same kind, or the regression that the narrow fix brought in, and each of those costs a full review round. A fix round therefore closes the whole class of defect, and proves it with a test, in the first round that sees the finding.

## For each finding, before the push

1. **Name the class.** State the defect independent of the cited line, in one sentence: for example *a user value reaches a shell command unescaped*, or *a status branch has no case for one of the states*. The finding's `Rule`, `Faulty Example`, and `Expected Behavior` are the starting point. Write the sentence into the commit body of the fix and into your handoff.
2. **Find every instance.** Search the whole PR diff, and every file the diff changes, for the same construct. Match on behaviour, never on names. Every caller of a function the fix changes is part of the search. List each instance with its `file:line`.
3. **Fix every instance, not only the cited one.** The scope stays the assignment (`@rules/compound-engineering/general.md` *The assignment is the scope*). An instance outside the assignment that is a security vulnerability is fixed in its own `pre-existing — ` commit (`@skills/resolve-issue/references/pre-existing-issue-handling.md`). Any other instance outside the assignment is left as it is.
4. **Prove the class closed with one test over its variants.** The test enumerates the variants of the class, not only the reported example. Use one of these shapes:
   - a table test: a Pest dataset, or a shell loop over a list of cases;
   - a differential test: the fixed code and a reference implementation give the same result for every variant;
   - a property test: an invariant holds for generated inputs.

   The variants are the reported example, every instance of step 2, and the boundaries of the class: the empty input, the value at the limit, the value one over the limit, and an input of an unexpected type or shape. The test fails on the code before the fix and passes after it (`@skills/test-driven-development/SKILL.md`).
5. **Attack the fix.** Run the short red tests of *Attack the fix before the push* below when that section applies.
6. **Push only then.** The class, its instances, and its test land together in this fix round.

## Attack the fix before the push

A fix can close the reported case and leave a way around it open. A short set of attacks on the whole class finds that hole before the next review round does.

**When the step applies:**

- A security finding always gets it.
- Any other finding gets it when its class has more than one form: the same defect reaches the code through more than one input form, encoding, entry point, or state.
- A finding whose class has one form skips it. The handoff names that reason.

**How to run it:**

1. **Write a handful of attacks.** Each attack is one short case against the class, not only against the reported case. Write it as a row of the class test of step 4. Keep the set small and fast, so it runs locally before the push.
2. **Try to get around the fix itself.** Write the same input in another form: another encoding, another letter case, added whitespace or separators, a nested or repeated form, another entry point to the same code, or a value of a type the fix does not check.
3. **Make each attack fail on the code before the fix.** A failure there shows that the attack reproduces the defect. An attack written before the fix, as the RED step of `@skills/test-driven-development/SKILL.md`, already ran on that code and needs no swap. For an attack written after the fix, swap the fix out of your own working tree only, and never touch a ref or a directory that another worktree or session reads or writes. Run the swap before you commit the fix, while `HEAD` still holds the code before the fix:
   1. Create a fresh directory for this swap, and never reuse one that an earlier swap or another unit created. Create it inside your own worktree with the symbolic-link check of `@rules/code-review/review-process.md` *No symbolic link may move the scratch directory*, and with `attack` in place of `settle`. `<top>` is the `pwd -P` of your own worktree's toplevel. Create each missing part of `<top>/.claude/run/attack` with `mkdir`, never with `mkdir -p`. Require that `pwd -P` in it prints the same path composed from `<top>`, then run `mktemp -d <top>/.claude/run/attack/XXXXXXXX` (`.codex/run/` on Codex). When a check fails, stop `Blocked`, write nothing, and name the refused path.
      Copy every fixed source file, tracked and new, into that directory, each at its repository-relative path, and save the `git diff` of the fixed tracked files there. The attack tests stay in place.
   2. Check the saved copy before anything is restored. The check passes only when all of these hold: the swap directory holds one copy of every fixed file, tracked and new, at its repository-relative path; each copy matches its source in the working tree byte for byte; the saved `git diff` file exists and equals the `git diff` of the fixed tracked files now. When one of them fails, the copy is incomplete: restore nothing, move nothing, leave the working tree as it is, and stop `Blocked` with the stop report of *A stopped swap keeps its saved copy* below.
   3. Restore the fixed tracked files with `git restore --source=HEAD --worktree -- <the fixed tracked files>`, and move the new files out of the working tree into the swap directory. Run this step only after the check of swap step 2 passed.
   4. Run the attacks.
   5. Run the `pwd -P` check on the swap directory again, then copy every file back from the swap directory only. Confirm that the `git diff` of the fixed tracked files equals the saved diff and that each new file matches its copy. When the round-trip check fails, stop `Blocked` and commit nothing, name the swap path in the handoff, and keep the worktree: the swap directory in its ignored run directory holds the fix for recovery, so `krang` removes no worktree that holds a `Blocked` swap. Delete the swap directory after the check passes.

   Run every attack, in this step and in step 4, with the project's test command through `skills/_shared/run-gate.sh --exclusive --command "<command>"` (`@skills/resolve-issue/references/quality-gates.md` *Targeted checks take the machine lock too*). An attack that passes on the code before the fix reproduces nothing: rewrite it until it fails, or drop it.
4. **Make each attack pass on the fixed code.** An attack that still fails got through the fix. Change the fix before the push, then run every attack again.
5. **Keep every attack as a regression test.** The attacks stay in the class test and land in the fix commit.

### A stopped swap keeps its saved copy

The swap directory holds the only copy of the fix while the attacks run. This subsection owns what a stop of the swap reports and what it never deletes.

- **A stop of the swap is a `Blocked` stop with a stop report.** It covers an incomplete saved copy (swap step 2), a failed round-trip check (swap step 5), and any other stop between the creation of the swap directory and the end of swap step 5.
- **The stop report names these four items:**
  1. the absolute path of the swap directory, which is the saved copy;
  2. the absolute path of the worktree that holds that swap directory;
  3. the reason that worktree is kept: it holds the only saved copy of an uncommitted fix;
  4. what was restored: nothing, or the tracked files restored by swap step 3 before the stop.
- **A refused scratch path creates no swap directory.** That stop names the refused path, and no worktree is kept for it.
- **No saved work is deleted or overwritten without a check.** The run deletes a swap directory only after the check of swap step 5 passed. It never reuses a swap directory, never writes into one a check did not pass, and never removes the worktree that holds a swap directory that was not deleted.
- **A worktree that holds a swap directory is never removed.** The swap directory is git-ignored, so `git status --porcelain` prints nothing for it, and a removal that checks only for a clean tree deletes it. Before any removal of a worktree, run `find <path>/.claude/run/attack -mindepth 1 -maxdepth 1` (`.codex/run/attack` on Codex). A worktree for which it prints an entry stays in place, whatever else is true of it, and the report names the worktree, that entry, and this reason. The removal paths are `krang` *Run cleanup*, the startup sweep, the removal of a batch unit, and the removal after a merge. Each of them runs this check and points here.
- **The operator finds the work from the report alone.** The recovery is a copy from the named swap directory into the named worktree. `krang` keeps that worktree and names it with the reason (`agents/krang.md` *Run cleanup*).

## Limits

- **A finding whose Test Hint reads `none is needed`** changes no behaviour, so it needs no test (`@skills/test-audit/SKILL.md`). Steps 1 to 3 still apply.
- **A free-form reviewer thread** that describes a behaviour bug gets the same steps. A naming, readability, or dead-code remark has no class to close.
- **The class is the defect's own kind, never a refactoring of nearby code.** A cleanup that no instance needs is outside the assignment.
- **The attacks never replace the review.** The next review round still checks the fix on its own.

## The handoff

For each finding, the fix handoff names the class sentence, the number of instances found and fixed, and the test that proves the class closed. A finding without those three items is reported as not closed.

For a finding that *Attack the fix before the push* covers, the handoff also lists the attacks tried. Each attack takes one line: the case, its result on the code before the fix (fails), and its result on the fixed code (passes). The list names every attack that got through the first version of the fix, and every attack that was dropped. A covered finding without that list is reported as not closed too. A finding that skips the step names its one form.

A fix attempt that stopped during the swap also hands back the stop report of *A stopped swap keeps its saved copy*: the swap directory, the worktree that holds it, the reason the worktree is kept, and what was restored.
