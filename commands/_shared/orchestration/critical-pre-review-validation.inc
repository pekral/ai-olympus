# The pre-review validation of a `CRITICAL` run

Extracted from `commands/_shared/krang-workflow.inc` so the orchestrator does not carry it on every run. **Loading trigger:** the re-classification of step 5 returns a final tier of `CRITICAL`. A section, a step, or a bullet this file names *above* or *below* without a file is in `commands/_shared/krang-workflow.inc`.

### Pre-review validation

**Only on a final tier of `CRITICAL` run the pre-review validation** — and run it yourself, deterministically: `commands/_shared/run-validation.sh --manifest <the manifest donatello returned>`. It executes the commands the implementer said cover its own diff and returns a verdict with an explicit `escalate` field. **Dispatch `donatello` only when the runner escalates** (`status: failed` or `status: invalid`), with the runner's own output and the instruction *"run in fast scoped validation mode"* (`agents/donatello.md` *Fast scoped validation mode*) — a green run needs no session at all. On `FAST` and `STANDARD` the plan places this validation after the review instead. Record the dispatch in the ledger as `donatello:scoped`, never bare `donatello`.
If the scoped pass returns `Blocked`, **stop and escalate** with the details; the review-and-fix loop does not start.
