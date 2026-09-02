# Review-loop scope (quiet runs and incremental review)

Referenced from `skills/process-code-review/SKILL.md` *Review loop*. Extracted to keep the skill body under the skill-check token limit; the two subsections below are unchanged in content and remain the skill's own contract.

#### Quiet review runs (during the loop)

- During iterations 1…N–1 of the loop, invoke the review skill with the explicit instruction "do not publish; return findings as in-memory markdown for this loop iteration only". Both `code-review-github` and `code-review-jira` honour the suppression: no PR comment, no JIRA comment, no linked-issue summary is posted while the loop is still iterating.
- The very last iteration (the one whose findings satisfy the convergence gate of step 4) is the **only** iteration whose output is published — that publication is performed by the **PR update** + **Completion** steps below, not by the review skill itself.
- Loop iterations may write quality-gate output (composer scripts, build logs) to the local terminal — that is not "publishing" and is allowed.

#### Incremental review scope (iterations after the first)

The loop runs its iterations quiet, so nothing is published and the wrapper has no CR comment to resolve a baseline from. The caller is therefore the only source, and it must supply one:

- **Record the head SHA each iteration reviewed** (`git rev-parse HEAD`, read at the moment the wrapper is invoked). Pass it on the next invocation as `reviewedRevision = <SHA>`, so that iteration reviews `git diff <reviewedRevision>..HEAD` — the fixes committed in step 5 — instead of the whole PR again.
- **Pass the previous iteration's findings with their disposition** alongside it: `fixed` (step 5 applied the Suggested Fix), `rejected — <reason>` (the fix was not applied and the reason is recorded on the PR), or `open`. The wrapper carries every `open` finding into the new report at its original severity and re-verifies every `fixed` one against the code before dropping it.
A disposition this skill asserts is a claim, and `@rules/code-review/general.md` *Incremental Review Scope — Diff Since the Last Reviewed Revision* settles a finding on the reviewer's own re-read, never on a claim.
- **Iteration 1 passes neither** — with no baseline it is round 1 over the whole PR diff, which is what a first review is.
- **The final publishing run in Completion passes the last iteration's SHA too**, so the published comment carries the same `Reviewed revision:` / `Review scope:` header lines a standalone run would, and the next CR run days later resolves its baseline from them.
- **Narrowing the detection never narrows the convergence gate.** The gate still counts carried-over findings, so an iteration cannot converge by scoping an unresolved Critical — or an undeferred Moderate — out of view.
