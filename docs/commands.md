# Command authoring contract

Commands are the only entrypoints that select people or coordinate their work. Every
new command starts with the root session holding the `krang` role and follows
`commands/_shared/krang-workflow.inc`.

Claude Code receives only the five top-level Markdown files in `.claude/commands`.
Its shared command support is installed below `.claude/command-support/`, outside
recursive command discovery. Codex receives the complete command tree below
`.codex/commands` as data for its root instructions, not as native slash commands.

Skills describe one bounded piece of work: their inputs, constraints, deterministic
steps, outputs, and done condition. They do not select a role, dispatch work, own a
review loop, or decide a merge. A command may invoke a skill directly when that
bounded work is all the request needs.

Codex has no custom slash-command discovery. The installed root instructions load the
matching contract from `.codex/commands/` before direct use of a user-facing skill.
This keeps the same lifecycle on both platforms without making a skill coordinate it.

Use `commands/do-it.md` as the prompt and formatting model. Preserve the argument
shape of an existing command. Put any new shared routing instruction below
`commands/_shared/`, never below `skills/_shared/`.

## Responsibility map

| Concern | Before | Now | Preserved contract |
| --- | --- | --- | --- |
| Public entry | Five `commands/*.md` prompts delegated into skills | The same five prompts start the command-owned root workflow | Arguments, command names, templates, and explicit merge opt-in stay unchanged. |
| Role selection and dispatch | Mixed between role-aware skills and shared skill orchestration | `commands/_shared/krang-workflow.inc` and `commands/_shared/orchestration/*.inc` | Routing modes, independent review/security views, convergence caps, and blocked stops stay command-owned. |
| Bounded work | Skills also named the people who ran them | `skills/**` defines inputs, constraints, deterministic steps, outputs, and done conditions only | Direct primitive-skill use remains available without starting another root workflow. |
| Deterministic coordination | Role-aware helpers lived below `skills/_shared/` | Command helpers live below `commands/_shared/`; neutral validation helpers remain below `skills/_shared/` | Script inputs, JSON/text output, gate locks, audit records, and trusted-copy checks are unchanged. |
| Claude installation | A recursive commands tree could expose support Markdown as slash commands | Five `.md` prompts go to `.claude/commands`; `.inc` support goes to `.claude/command-support` | Exactly five public commands remain visible. |
| Codex installation | Commands had no installed command-contract tree | The full tree goes to `.codex/commands` and is loaded by root instructions | Codex continues to use the matching workflow before a user-facing skill. |
| Gate and merge semantics | Skills and roles jointly described the lifecycle | Commands coordinate the unchanged gate, CI, review, reporting, and explicit-merge contracts | No full gate moves earlier; a merge still requires its exact-head evidence. |

### Workflow responsibilities

| Existing operation | Command protocol in `commands/_shared/` | Input and result at the skill boundary |
| --- | --- | --- |
| Queue a set of changes, run dependency batches and report progress | `orchestration/do-it.inc`, `orchestration/batch-preparation.inc` | `do-it` returns trusted issue matches, ordering evidence and blocked items. |
| Test a task in report or fix mode | `orchestration/test-assignment.inc` | `test-assignment` returns the input map, test matrix, observed results and decisions. |
| Approve a design, implement it and walk through the resulting page | `orchestration/deliver-page-redesign.inc` | `deliver-page-redesign` records the route, local captures and design criteria. Delivery requires the explicit approval record. |
| Run independent review, fix, converge and finalize | `krang-workflow.inc`, `orchestration/process-code-review.inc` | `process-code-review` returns per-finding fixes, test evidence, revision and remaining blockers. |
| Assess security controls and validate coverage | `orchestration/security-audit.inc` | `security-audit` returns source evidence and a verdict for the assigned unit. |
| Inspect a subsystem for simplifications | `orchestration/simplification-audit.inc` | `simplification-audit` returns at most two recommendations or a documented skip. |
| Prepare one or several pull requests for a merge decision | `orchestration/verify-merge-readiness.inc` | `verify-merge-readiness` returns exact-head evidence and unanswered decisions. |
| Process the interactive testing backlog | `orchestration/auto-interactive-testing.inc` | `auto-interactive-testing` returns local scenario results, evidence and cleanup status. |
| Verify delegated security in a merge | `security-delivery.inc` | The merge uses trusted review identity, the linked security comment and its timestamp. |
