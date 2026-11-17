# A `donatello` implementation blocked by the sandbox

Extracted from `agents/krang.md` so the orchestrator does not carry it on every run. **Loading trigger:** `donatello` returns `Blocked: sandbox denied file write` in step 5. A section, a step, or a bullet this file names *above* or *below* without a file is in `agents/krang.md`.

### `Blocked: sandbox denied file write`

If `donatello` returns `Blocked: sandbox denied file write` instead, **stop and escalate that blocker to the user with its remediation** (enable subagent file writes — see `docs/agents.md` *Troubleshooting — subagent file writes blocked*); never implement the change yourself in your own context to work around the denial (`@rules/compound-engineering/general.md` *Blocked delegation is a hard stop*). Run *Run cleanup* before that stop as well — the four scratch files (its `## PID` ownership gate is what clears them, and it passes here: this run wrote the brief and its own `mkdir` on the lock succeeded)
**and** the write-lock release (`rm -rf .claude/run/.krang-write.lock`), because this instance did acquire the lock earlier in this step, so it is the one that must give it back.
