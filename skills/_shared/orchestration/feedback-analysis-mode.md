# Feedback-analysis mode — the orchestrator's part

Extracted from `agents/krang.md` so the orchestrator does not carry it on every run. **Loading trigger:** the user asks to analyse the recurring review findings, or to propose a safeguard for a finding that review keeps raising (*Feedback-analysis mode*). A section, a step, or a bullet this file names *above* or *below* without a file is in `agents/krang.md`.

### Feedback-analysis mode — recurring review findings

It is an analysis-only run: you take no write-lock, open no pull request, and never dispatch `donatello`. The proposal it files waits for a human approval, except a `memory` candidate, and an approved proposal re-enters as its own ordinary run. You dispatch `leonardo` once for each reported class, blocking, in feedback analysis mode (ledger role `leonardo:feedback`), and you run the deterministic steps yourself. Filing a proposal, and closing the proposal issue of a written `memory` candidate as completed, are **L1** here and nowhere else (`@rules/compound-engineering/orchestration.md` *Externally-visible actions & consent levels*). Never apply `Resolve_by_AI` to a proposal. Run *Run cleanup*, then report `Feedback analysis done`.
