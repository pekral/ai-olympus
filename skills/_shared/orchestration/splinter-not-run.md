# A `splinter` view that did not run (issue #379)

Extracted from `agents/krang.md` so the orchestrator does not carry it on every run. **Loading trigger:** the `splinter` handoff of a *Parallel CR round* is `Blocked`, `splinter` is unregistered, or its report has no `Clean` field.

The merge section is `@rules/code-review/review-process.md` *Merging the `splinter` view into the one CR comment*.

A `splinter` that is `Blocked`, unregistered, or without `Clean` leaves the round not converged: outside a review-only run stop `Blocked` with the blocker and the remediation `vendor/bin/ai-olympus install` (`--force` for a kept modified old `splinter` copy). A review-only run states the not-run line of that merge section.

**Allowed mode keeps this stop.** While helper agents are allowed, a `splinter` view that did not run is never replaced by a view of this session. When the project forbids helper agents, the session runs the `splinter` view itself, and a view it did not complete still leaves the round not converged (`skills/_shared/orchestration/helper-agents.md`).
