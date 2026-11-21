---
name: auto-interactive-testing
description: "Use when every open GitHub issue labelled interactive-testing must be worked through as one unattended batch. Fixes and merges the defects the issues already link, runs each issue's scenarios with the interactive-testing skill on the freshly pulled default branch, resolves and merges every new defect it finds, re-tests, cleans up its test data, then posts the verdicts on each issue and closes it when every scenario passed."
license: MIT
metadata:
  author: "Petr Král (pekral.cz)"
---

For an explicit user invocation, run `bash ../_shared/resolve-command-helper.sh --entry` from this skill directory and follow the returned command contract with the original arguments. For a bounded work step already in progress, use the method below.

## Constraints

Use the local test environment and preserve the application data cleanup and evidence requirements of `@skills/interactive-testing/SKILL.md`.

## Execution

### 4. Prepare the environment

Check and prepare the local environment as `@skills/interactive-testing/SKILL.md` *Local readiness* states, before the first scenario and again after every merge. Check these too:

1. The working tree is the default branch, freshly pulled and clean.
2. The queue workers restart after a merge. Start the processes a scenario needs in a terminal the user can see.
3. Credits, feature switches, and similar settings that a scenario needs are set. Record the original value of every setting you change.
4. Before a scenario that sends mail, read the effective mailer configuration and confirm that it writes only to a log or a test sink. When it does not, the scenario is `Blocked`. Change the mail configuration only when a project instruction documents that switch.

### 5. Run the scenarios

Run `@skills/interactive-testing/SKILL.md` for the issue on the default branch:

- **UI scenarios** run in the agent's own interactive browser. Check desktop 1280 × 720 and mobile 390 × 844 when the issue asks for them. Reset the viewport afterwards.
- **Tooling scenarios** run in the terminal. Work on a throwaway branch from the default branch, never push it, and delete it at the end. Revert every temporary edit and confirm a clean `git status`.
- **Test data** is created, changed, and restored as `@skills/interactive-testing/SKILL.md` *Missing test data* states. Record the identifier of every record you create or change.
- **A background or AI run** that a scenario needs is switched on only for one call, in the way the project documents. Stop the queue workers during that call when they could process real incoming data.
- **An environment limitation**, for example a websocket server without TLS or an artifact of a log mailer, is reported as such. It is not a defect of the change under test.

### 7. Clean up

1. Restore every record that this run changed, as `@skills/interactive-testing/SKILL.md` *Missing test data* states. Delete every record that this run created. Run this cleanup through `@skills/interactive-testing/SKILL.md` *Scripted cleanup*. When the browser fallback runs, use the application's own delete path. When no such path exists, delete only the records this run created, through their model and storage, and check for orphaned stored files.
2. Restore every setting you changed, then read it back.
3. Stop the processes this run started, and close its terminal tabs. Close every browser tab this run opened, as `@skills/interactive-testing/SKILL.md` *Browser tabs* states.
4. Delete the throwaway branches after you confirm that no remote copy exists.
5. List every item that stays, with the reason.

## Output

**The comment on each issue** is rendered from `skills/auto-interactive-testing/templates/test-result-comment.md`.

**The final report to the user** contains:

- one row for each issue: the verdicts, the URL of the comment, and the issue state,
- every defect found or fixed, with the pull request and the merge commit,
- every CI check that was skipped, with its message,
- the issues that were skipped because another run claimed them,
- the leftovers, and every open question, all in one round.

## Done when

- Every issue in the queue is closed with all scenarios `Passed`, or is open with a named blocker.
- Every defect found in the run is merged and re-tested, or is open with a named reason.
- Every setting is restored, the test data is deleted or listed, and no throwaway branch remains.
- No issue that this run processed still carries the claim label.
- The final report is delivered.
