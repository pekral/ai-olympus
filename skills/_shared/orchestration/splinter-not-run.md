# A `splinter` view that did not run (issue #379)

Extracted from `agents/krang.md` so the orchestrator does not carry it on every run. **Loading trigger:** the `splinter` handoff of a *Parallel CR round* is `Blocked`, `splinter` is unregistered, or its report has no `Clean` field.

The merge section is `@rules/code-review/review-process.md` *Merging the `splinter` view into the one CR comment*.

The round converges only when the `leonardo` view and the `splinter` view are both clean under the convergence gate. Each Critical and Moderate `splinter` finding is a round finding, and a security finding is never deferred.

A `splinter` that is `Blocked`, unregistered, or without `Clean` leaves the round not converged: outside a review-only run stop `Blocked` with the blocker and the remediation `vendor/bin/ai-olympus install` (`--force` for a kept modified old `splinter` copy). A review-only run states the not-run line of that merge section.

Never pass a `leonardo` finding to `splinter`.
