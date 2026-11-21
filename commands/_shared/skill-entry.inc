# Direct skill invocation

Only the root session reads this adapter for a user invocation. It holds the `krang` role. A skill named in an assigned specialist step is its bounded work contract.

| User invocation | Command contract |
| --- | --- |
| `$do-it` | `commands/do-it.md` |
| `$deliver-page-redesign` | `commands/redesign-page.md` |
| `$test-assignment` | `commands/test-assignment.md` |
| `$verify-merge-readiness` | `commands/prepare-issue-for-merge.md` |
| `$code-review-github`, `$code-review-jira`, `$code-review-bugsnag` | `commands/report-code-review.md` with the original source and fresh flag |
| `$auto-interactive-testing` | `commands/_shared/orchestration/auto-interactive-testing.inc` |
| `$process-code-review` | `commands/_shared/orchestration/process-code-review.inc` |
| `$simplification-audit` | `commands/_shared/orchestration/simplification-audit.inc` with the requested scope |
| `$security-audit` | `commands/_shared/orchestration/security-audit.inc` with the requested scope and profile |

For an explicit merge request, read `commands/_shared/orchestration/merge-request.inc` and `commands/_shared/security-delivery.inc` before the applicable merge work contract.

For another bounded skill, retain the requested task, modes and permissions and apply its work contract once. A direct merge, a hotfix or an explicitly requested batch merge must satisfy its original user-intent and gate checks. When a public command invokes a skill, it continues from the current command step.
