# Direct skill invocation

Match the original user invocation against the table first. A skill passed as an assigned work step continues in its current executor. An unmapped user invocation also stays in its current executor and applies the bounded skill directly. Neither case adopts a new role.

For a matching top-level invocation, hold the `krang` role, bind the original arguments and mode, and continue with the matching command contract. Resolve `commands/_shared/` beside this file. The public Markdown commands reside one directory above that shared directory, except in a Composer Claude installation, where the public files are in the sibling `.claude/commands/` directory.

| User invocation | Command contract |
| --- | --- |
| `$do-it` | `commands/do-it.md` |
| `$deliver-page-redesign` | `commands/redesign-page.md` |
| `$test-assignment` | `commands/test-assignment.md` |
| `$verify-merge-readiness` | `commands/prepare-issue-for-merge.md` |
| `$code-review-github`, `$code-review-jira`, `$code-review-bugsnag` | `commands/report-code-review.md` with the original source and fresh flag |
| `$github-issue-triage` when the user asks for pending analysis | `commands/_shared/orchestration/pending-analysis.inc` |
| `$merge-github-pr` when the user requests a merge | `commands/_shared/orchestration/merge-request.inc`, then the bounded merge checks |
| `$auto-interactive-testing` | `commands/_shared/orchestration/auto-interactive-testing.inc` |
| `$process-code-review` | `commands/_shared/orchestration/process-code-review.inc` |
| `$simplification-audit` | `commands/_shared/orchestration/simplification-audit.inc` with the requested scope |
| `$security-audit` | `commands/_shared/orchestration/security-audit.inc` with the requested scope and profile |

For an explicit merge request, read `commands/_shared/orchestration/merge-request.inc` and `commands/_shared/security-delivery.inc` before the applicable merge work contract.

A direct merge, a hotfix or an explicitly requested batch merge must satisfy its original user-intent and gate checks. When a public command invokes a skill, it continues from the current command step.
