# A `splinter` view that did not run (issue #379)

Extracted from `commands/_shared/krang-workflow.inc` so the orchestrator does not carry it on every run. **Loading trigger:** the `splinter` handoff of a *Parallel CR round* is `Blocked`, `splinter` is unregistered, or its report has no `Clean` field.

The merge section is `@rules/code-review/review-process.md` *Merging the `splinter` view into the one CR comment*.

A `splinter` that is `Blocked`, unregistered, or without `Clean` leaves the round not converged: outside a review-only run stop `Blocked` with the blocker and the remediation `vendor/bin/ai-olympus install` (`--force` for a kept modified old `splinter` copy). A review-only run states the not-run line of that merge section.

## Required review-only warning

Publish the exact warning below in the existing missing-view row of the review template. A missing current independent view still blocks convergence.

Independent security view not run — dispatch splinter  *(render this line only on a review-only run whose brief holds no `splinter` report for the head SHA, or a report without a `Clean` field — `@rules/code-review/review-process.md` *Merging the `splinter` view into the one CR comment*. Omit it on every other run, and on `Clean: no`.)*
