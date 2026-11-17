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
5. **Push only then.** The class, its instances, and its test land together in this fix round.

## Limits

- **A finding whose Test Hint reads `none is needed`** changes no behaviour, so it needs no test (`@skills/test-audit/SKILL.md`). Steps 1 to 3 still apply.
- **A free-form reviewer thread** that describes a behaviour bug gets the same steps. A naming, readability, or dead-code remark has no class to close.
- **The class is the defect's own kind, never a refactoring of nearby code.** A cleanup that no instance needs is outside the assignment.

## The handoff

For each finding, the fix handoff names the class sentence, the number of instances found and fixed, and the test that proves the class closed. A finding without those three items is reported as not closed.
