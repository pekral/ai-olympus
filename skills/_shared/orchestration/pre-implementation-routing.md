# Pre-implementation routing — the security analysis and the page redesign of step 4

Extracted from `agents/krang.md` so the orchestrator does not carry it on every run. **Loading trigger:** step 4 classifies the task as security-focused or as a page redesign. A section, a step, or a bullet this file names *above* or *below* without a file is in `agents/krang.md`.

### Security-focused task

**Dispatch `leonardo` through the Task tool** in its **security analysis mode** with the resolved source **and the shared brief path**. `leonardo` scopes the security risk and publishes a remediation plan artifact (`Security analysis done`), which you feed to `donatello` for implementation in step 5; `leonardo` then runs again as the security CR after `donatello` (step 6). Wait for the `Security analysis done` handoff to land in the brief before dispatching `donatello`, which then reads the complete brief and implements on that plan.
`leonardo`'s analysis mode is dispatchable only when she is registered (see the registration note in step 6); when she is not registered, note *"leonardo is not registered — no pre-implementation security analysis runs"* and proceed to step 5. **Do not route the analysis into `donatello` instead:** its pre-PR self-check is a deterministic checklist and runs no review skill at all (`@skills/resolve-issue/SKILL.md` *Pre-PR self-check*), so naming it as the fallback would report a security pass that nobody performed. The security review still reaches the PR through the CR fallback in step 6, where `code-review-github` runs the review inline. Do not duplicate `@skills/analyze-problem` or the security skills — `leonardo` owns them.

### Page-redesign task

Pass `--redesign` to the planner and **dispatch `michelangelo` through the Task tool** with the resolved source **and the shared brief path**. It returns `Redesign done` with the proposal, the mockups, and one rendered preview per state; that handoff is the specification `donatello` implements in step 5, exactly as `leonardo`'s remediation plan is. Wait for it to land in the brief before dispatching `donatello`.

**This branch is not gated on the tier** — a page needs a redesign because of what the task asks for, never because of how risky the change is — and it composes with the security branch above rather than replacing it: a `CRITICAL` redesign gets the security analysis first, then the redesign, then the implementation. When `michelangelo` is not registered, note *"michelangelo is not registered — no redesign specification is produced"* and stop with the remediation rather than letting `donatello` invent a layout: the whole point of the stage is that somebody designed the page before it was built.

**An approved redesign is never replayed.** When the task carries a redesign proposal the user already approved (`@skills/deliver-page-redesign/SKILL.md` *Get the design approved*), do not pass `--redesign` and do not dispatch `michelangelo`: hand the approved proposal to `donatello` as the specification (`@rules/compound-engineering/orchestration.md` *An approved redesign is never replayed*).

**A redesign the user asked for without any implementation ask is analysis-only** and was already routed in step 3 — `michelangelo` is that branch's second specialist, beside `leonardo`. Its deliverable is the proposal itself, and the run stops there.
