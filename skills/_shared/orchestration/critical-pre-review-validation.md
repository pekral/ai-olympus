# The pre-review validation of a `CRITICAL` run

Extracted from `agents/krang.md` so the orchestrator does not carry it on every run. **Loading trigger:** the re-classification of step 5 returns a final tier of `CRITICAL`. A section, a step, or a bullet this file names *above* or *below* without a file is in `agents/krang.md`.

### Pre-review validation

**Only on a final tier of `CRITICAL` run the pre-review validation** — and run it yourself, deterministically: `skills/_shared/run-validation.sh --manifest <the manifest donatello returned>`. It executes the commands the implementer said cover its own diff and returns a verdict with an explicit `escalate` field. **Dispatch `donatello` only when the runner escalates** (`status: failed` or `status: invalid`), with the runner's own output and the instruction *"run in fast scoped validation mode"* (`agents/donatello.md` *Fast scoped validation mode*) — a green run needs no session at all. On `FAST` and `STANDARD` the plan places this validation after the review instead. Record the dispatch in the ledger as `donatello:scoped`, never bare `donatello`.
If the scoped pass returns `Blocked`, **stop and escalate** with the details; the review-and-fix loop does not start.

**Helper agents forbidden.** When the project forbids helper agents, no step of this file starts an agent: the session performs each dispatch named here itself, in the role of that agent, or stops on a step it cannot perform, as `skills/_shared/orchestration/helper-agents.md` states.
