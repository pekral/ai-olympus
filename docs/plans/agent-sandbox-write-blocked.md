# Plan — the sandbox blocks file writes for write-agents (hephaestus)

## Goal
When a dispatched write-agent (`hephaestus`) is blocked by the harness sandbox / permission mode while writing files, the run **stops and reports a clear blocker with its remediation**, instead of the main thread quietly finishing the implementation outside the delegated, reviewed pipeline. The documentation states the environment prerequisite (allow subagents to Edit/Write) and how to satisfy it.

## Architecture
The cause sits in the Claude Code harness (sandbox / permission mode for non-interactive subagents), **not** in the agent definitions — `hephaestus` declares `Write` / `Edit` in `tools`. The repository therefore cannot "grant" the write, but it owns two things that do resolve this case:

- **`rules/compound-engineering/general.md`** (or a new `rules/agents/general.md` with no `paths:` key) — the behaviour of the main thread and the orchestrator: a write-blocked subagent is a hard blocker → stop and report, never a silent takeover of the work into the main thread.
- **`agents/hephaestus.md` + `agents/daedalus.md`** — the handoff contract: on a refused write `hephaestus` returns `Blocked: sandbox denied file write` with its remediation, and `daedalus` escalates it to the user.
- **`docs/agents.md` (Troubleshooting) + `README.md`** — the environment prerequisite: the session must allow subagents to Edit/Write, and how to enable it. **Finding (verified against the official documentation):** `defaultMode: acceptEdits` plus `permissions.allow: ["Edit","Write"]` are *necessary but not sufficient* — a dispatched subagent still meets two boundaries the main thread does not: (1) a **background** subagent auto-denies every write that would otherwise raise a prompt, and (2) the OS-level **filesystem sandbox** allows writes only into the cwd and `$TMPDIR` by default. The real remediation is therefore the `sandbox` layer (`"sandbox": { "enabled": true, "filesystem": { "allowWrite": ["."] } }`) and/or re-dispatching the agent in the *foreground*, not the permission mode. Sources: https://code.claude.com/docs/en/sandboxing , https://code.claude.com/docs/en/sub-agents .

~~The **installer is deliberately not changed** to flip security settings automatically (sandbox off, allow every edit) — that would be far too broad and would contradict both the "only on request" principle and the security rules.~~

**Update (at the user's request):** the installer **may** write that setting, but **only behind the opt-in flag** `--allow-subagent-writes` (following the `--allow-bundled-scripts` pattern), never automatically.

**Correction (verified in practice on a real project):** the `sandbox` block did **not** unblock subagent writes. The working "option A" is to prepend the scoped permission entries `Edit(//<project>/**)` and `Write(//<project>/**)` to the `permissions.allow` array in the **project's `.claude/settings.local.json`** (not `settings.json`, and not the `sandbox` block). The subagent (`hephaestus`) then writes without interactive approval. The installer's `--allow-subagent-writes` therefore generates those two scoped entries (idempotently, prepended, leaving existing entries untouched) in `settings.local.json` and validates the result (`InstallerClaudeSettings::validateSubagentWritePermissions`). `settings.local.json` is the right home because the entries carry an absolute path bound to one machine. "Only on request" still holds — the default behaviour adds nothing.

## Implementation steps
1. Add the behavioural rule (always applied): a sandbox-write-blocked write-agent is a hard blocker → stop, report, remediate; silently finishing the work in the main thread is forbidden.
2. Extend the handoff contract in `agents/hephaestus.md` with the terminal state `Blocked: sandbox denied file write` (plus remediation), and `agents/daedalus.md` with its escalation.
3. Add a Troubleshooting section to `docs/agents.md` and a short note to `README.md` with the concrete procedure for allowing subagent writes.
4. `composer build` (sync `.claude/`, fixers, checks, skill-check, tests) must be green.

## Sources
- `agents/hephaestus.md` (`tools: Read, Write, Edit, Glob, Grep, Bash`) — the write is permitted at the agent level.
- `agents/daedalus.md` — delegation model, one-level nesting, handoff contract.
- `src/InstallerClaudeSettings.php` — the installer manages only `permissions.allow` (bundled scripts) and `includeCoAuthoredBy`; no sandbox key.
- `.claude/settings.local.json` — `permissions.allow` only; no `sandbox` / `defaultMode`.
- `docs/agents.md` — "Subagents of an agent" (one-level nesting), "Distribution".

## Success criteria
- On a blocked write the subagent returns an unambiguous blocker and the main thread does NOT continue with a silent implementation.
- The documentation states the environment prerequisite and the procedure for allowing subagent writes.
- `composer build` green (0 errors), `composer skill-check` 0 errors.
- No automatic flipping of security settings in the installer.
