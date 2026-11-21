# A declared HOTFIX — the planner flag and the dispatches

Extracted from `commands/_shared/krang-workflow.inc` so the orchestrator does not carry it on every run. **Loading trigger:** the caller's own instruction declares a production emergency in words (*Adaptive routing*). A section, a step, or a bullet this file names *above* or *below* without a file is in `commands/_shared/krang-workflow.inc`.

### A declared HOTFIX is passed to the planner

Add `--hotfix`: the plan then carries `"hotfix": true` and puts `leonardo` in `review_hotfix`, which narrows what the review reports and waives the coverage gate. It never changes the tier, so a sensitive-area force still buys every stage it would otherwise buy. Carry the mode and the declaring account into the `leonardo` dispatch and into the `donatello` dispatch, and record both in the routing ledger.
