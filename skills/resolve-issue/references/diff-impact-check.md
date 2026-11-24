# Diff impact check

Referenced from `skills/resolve-issue/SKILL.md` step 17. The Map step of the pre-flight covers the change you planned. This check covers the change you made: the actual diff often touches a symbol the plan did not name.

It runs after the tests of step 16 and before the Pre-PR self-check. It is not a review: it raises no finding and grades no severity. `@rules/code-review/core-analysis.md` *Behaviour changed outside the diff* owns the walk, and the review runs it again on the pull request.

## Procedure

1. Read the actual diff against the base branch: `git diff "origin/$DEFAULT_BRANCH"...HEAD`, plus the uncommitted changes.
2. Run steps 1 to 3 of the walk in `@rules/code-review/core-analysis.md` *Behaviour changed outside the diff* over that diff. The result is the list of callers and consumers outside the diff whose observable behaviour the diff changes.
3. Settle each affected place with one of these outcomes:
   - **The assignment asks for the change.** Run the test that covers the place. When no test covers it, add one in the commit of the criterion that asks for the change.
   - **The diff broke the place.** Repair it in this pull request and prove the repair with a test. A regression the diff causes is part of the assignment.
   - **The assignment does not ask for the change, and nothing is broken.** Keep the old behaviour for that place.
4. Search the project by behaviour for every other place that carries the defect or the gap the diff fixes: a second entry point, a sibling class, a copy of the same logic.
   - A place that an acceptance criterion covers gets the same fix in this pull request, with its own test.
   - A place that no criterion covers stays unchanged. Record it as a deferred item (`@rules/compound-engineering/general.md` *The assignment is the scope*).
   - A place that carries a security vulnerability follows `references/pre-existing-issue-handling.md` instead. A vulnerability that stays unfixed never enters the pull request body or any other public text, and step 5 does not record it.
5. Record the result in the handoff and in the **Testing** section of the pull request: each affected place as `file:line`, its outcome, and the test that proves it. When the walk finds no affected place, write that the walk ran and found none.

## Stop conditions

- An affected place that you can neither prove working nor repair is a `Blocked` stop. Name the place and what is missing.
- A repair that needs a change the assignment does not cover is an open decision. Never widen the scope silently.
