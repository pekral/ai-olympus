# Phase planning (commit plan)

Referenced from `skills/resolve-issue/SKILL.md` *Phase planning (commit plan)*. Extracted to keep the skill body under the skill-check token limit (issue #59); the procedure is unchanged.

Before writing any code, decide how the in-scope work will be split into commits within the PR, applying the **one phase = one commit** rule from `@rules/git/general.mdc` *Git Rules*.

1. **Detect existing phases** in the issue description and the kept comments. Phase markers include explicit headings such as `Phase 1`, numbered milestones, ordered acceptance-criteria blocks, or a step-by-step plan written by the reporter.
2. **If phases exist:** treat each phase as exactly **one commit**. Keep the original phase order as commit order. Do not merge, reorder, or re-scope phases.
3. **If no phases exist but the assignment is long or covers multiple distinct concerns:** propose a phased breakdown — each phase must be independently reviewable and yield a working state — then map **one phase per commit**.
4. **If the assignment is small and atomic:** keep it as a single commit. Do not invent artificial phases.
5. Record the planned phases as a numbered list (one line per commit, with the intended commit message in `type(scope): description` form per `@rules/git/general.mdc`) **before** starting implementation. This list is the commit plan for step 11.
6. During implementation, commit at the end of each phase. Run pre-push fixers and tests on the changes belonging to that phase before moving on.
