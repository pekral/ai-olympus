---
description: Copy this package's PHP/Laravel rules and CLAUDE.md into the current project (marketplace install only)
allowed-tools: Bash(cp:*), Bash(mkdir:*), Bash(ls:*), Bash(test:*), Read
---

Install this plugin's rules into the current project.

A Claude Code plugin ships skills and agents natively, but it cannot ship `rules/` or a
`CLAUDE.md` — the harness loads neither from a plugin directory. Both files live in the plugin
tree, so this command copies them into the project once. Everything else the plugin provides is
already active and needs no copying.

Do exactly this, in order:

1. Create `.claude/rules/` in the project root when it does not exist.
2. Copy every file under `${CLAUDE_PLUGIN_ROOT}/rules/` into `.claude/rules/`, preserving the
   directory structure. Overwrite what is already there — these are package files, and a stale
   copy is the drift this command exists to prevent.
3. Copy `${CLAUDE_PLUGIN_ROOT}/CLAUDE.md` to the project root **only when the project has no
   `CLAUDE.md` of its own**. Never overwrite an existing one: it is the file a team customises,
   and the Composer installer carries the same guarantee.
4. Report what landed — the number of rule files copied, and whether `CLAUDE.md` was written or
   left alone because the project already had one.

The rules take effect in the next session; Claude Code reads `.claude/rules/` at session start.

This command writes no `.claude/settings.local.json` entry. The installer's opt-in security flags
(`--deny-network-bash`) stay bound to the Composer path — see `SECURITY.md`.
