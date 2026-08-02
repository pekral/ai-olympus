# Installation

Operational reference for the `agent-skills` installer. The two commands you need to get started are in the [Quickstart](../README.md#quickstart) — this page covers everything beyond them: how the installer finds your project, what it writes, how to automate it, and every CLI switch.

## How the Installer Works

The installer discovers the project root by walking up from the current directory until it finds a `composer.json`. It then mirrors the `rules/` directory into `.claude/rules` and the `skills/` directory into `.claude/skills`, copying every file into the target project — or symlinking it when you pass `--symlink` and the operating system permits it.

When the package is required via Composer, sources are read from `vendor/agentic-vibes/laravel-agent-skills/rules` and `vendor/agentic-vibes/laravel-agent-skills/skills`.

## Automatic Installation via Composer Plugin

By default, the Composer plugin does **not** auto-install rules on `composer install` or `composer update`. To enable automatic installation, add the following to your project's `composer.json`:

```json
{
  "extra": {
    "agent-skills": {
      "auto-install": true
    }
  }
}
```

| Option         | Description                                              | Default   |
|----------------|----------------------------------------------------------|-----------|
| `auto-install` | Enable automatic install on `composer install/update`.   | `false`   |

If you prefer manual control, simply call `vendor/bin/agent-skills install` in your Composer `post-update-cmd` scripts with the desired flags.

## Available Commands

```bash
vendor/bin/agent-skills help                                  # print help
vendor/bin/agent-skills install                                # install for Claude Code
vendor/bin/agent-skills install --force                        # overwrite existing files
vendor/bin/agent-skills install --symlink                      # prefer symlinks (fallback to copy)
vendor/bin/agent-skills install --prune                        # remove files in target that no longer exist in source
vendor/bin/agent-skills install --allow-bundled-scripts         # whitelist this package's bundled scripts in ~/.claude/settings.json
vendor/bin/agent-skills install --allow-subagent-writes         # allow dispatched-subagent file writes (scoped Edit/Write) in .claude/settings.local.json
```

## Installer Flow

1. Determine the project root by walking up from the current directory until `composer.json` is found.
2. Resolve the rules source (local `rules/` or `vendor/agentic-vibes/laravel-agent-skills/rules`).
3. Install rules into `.claude/rules`.
4. If present, resolve the skills source and install into `.claude/skills` (and `~/.claude/skills` when `HOME`/`USERPROFILE` is set).
5. Copy `agents/` to `.claude/agents` and `CLAUDE.md` to the project root (never overwrites existing).
6. Optionally overwrite existing files with `--force`; use `--symlink` to prefer symlinks (fallback to copy on Windows).
7. Surface explicit errors for missing directories, removal failures, and copy/symlink failures.

## CLI Switches

| Option            | Description                                                                 |
|-------------------|-----------------------------------------------------------------------------|
| `--force`                 | Overwrite files that already exist in the target directory.                                                                                                 |
| `--symlink`               | Create symlinks when the OS permits; automatically falls back to copy.                                                                                      |
| `--prune`                 | Remove files in target that no longer exist in source.                                                                                                       |
| `--allow-bundled-scripts` | Opt-in. Idempotently appends a narrow allow-list for this package's bundled scripts (`load-issue.sh` for GitHub and JIRA) to `~/.claude/settings.json`, so Claude Code stops prompting on every run. Other entries in `settings.json` are preserved. No effect when `HOME` / `USERPROFILE` is not set. |
| `--allow-subagent-writes` | Opt-in. Idempotently prepends scoped `Edit` / `Write` allow entries for the project working tree to `permissions.allow` in `.claude/settings.local.json`, so a dispatched subagent (e.g. `talos`) can write files without interactive approval. Existing allow entries and unrelated keys are preserved. |
| *(default)*               | Only copy missing files and keep existing content untouched.                                                                                                |
