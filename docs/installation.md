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
vendor/bin/agent-skills install --global                       # also install skills to ~/.claude/skills (off by default)
vendor/bin/agent-skills install --prune-global                 # remove this package's skills from ~/.claude/skills
vendor/bin/agent-skills install --allow-bundled-scripts         # whitelist this package's bundled scripts in ~/.claude/settings.json
vendor/bin/agent-skills install --allow-subagent-writes         # allow dispatched-subagent file writes (scoped Edit/Write) in .claude/settings.local.json
```

## Installer Flow

1. Determine the project root by walking up from the current directory until `composer.json` is found.
2. Resolve the rules source (local `rules/` or `vendor/agentic-vibes/laravel-agent-skills/rules`).
3. Install rules into `.claude/rules`.
4. If present, resolve the skills source and install into `.claude/skills` (and additionally into `~/.claude/skills` when `--global` is passed and `HOME`/`USERPROFILE` is set).
5. Copy `agents/` to `.claude/agents` and `CLAUDE.md` to the project root (never overwrites existing).
6. Optionally overwrite existing files with `--force`; use `--symlink` to prefer symlinks (fallback to copy on Windows).
7. Surface explicit errors for missing directories, removal failures, and copy/symlink failures.

## CLI Switches

| Option            | Description                                                                 |
|-------------------|-----------------------------------------------------------------------------|
| `--force`                 | Overwrite files that already exist in the target directory.                                                                                                 |
| `--symlink`               | Create symlinks when the OS permits; automatically falls back to copy.                                                                                      |
| `--prune`                 | Remove files in target that no longer exist in source.                                                                                                       |
| `--global`                | Opt-in. Also install skills into `~/.claude/skills`. Off by default — see [Where skills are installed](#where-skills-are-installed). No effect when `HOME` / `USERPROFILE` is not set. |
| `--prune-global`          | Remove this package's skills from `~/.claude/skills` so the project copy is the one Claude Code loads. Matches by skill name; skills under other names are left untouched, and a symlinked install is removed as the link only. Irreversible — see the warning under [Where skills are installed](#where-skills-are-installed). Cannot be combined with `--global`. |
| `--allow-bundled-scripts` | Opt-in. Idempotently appends a narrow allow-list for this package's bundled scripts (`load-issue.sh` for GitHub and JIRA) to `~/.claude/settings.json`, so Claude Code stops prompting on every run. Other entries in `settings.json` are preserved. No effect when `HOME` / `USERPROFILE` is not set. |
| `--allow-subagent-writes` | Opt-in. Idempotently prepends scoped `Edit` / `Write` allow entries for the project working tree to `permissions.allow` in `.claude/settings.local.json`, so a dispatched subagent (e.g. `talos`) can write files without interactive approval. Existing allow entries and unrelated keys are preserved. |
| *(default)*               | Only copy missing files and keep existing content untouched.                                                                                                |

## Where skills are installed

Skills go to the project's `.claude/skills` and nowhere else unless you ask for more. That default follows from how Claude Code resolves a name collision — [its documentation](https://code.claude.com/docs/en/skills) states it plainly:

> When skills share the same name across levels, enterprise overrides personal, and personal overrides project.

So a copy in `~/.claude/skills` wins over the project's own copy, in **every** project on the machine. Install globally and each checkout silently runs whatever version the home directory happens to hold, rather than the version it has checked out — the two drift apart the moment one project upgrades the package and another does not, and nothing in the session surfaces which one won.

Keeping the install local ties each project to its own `composer.lock`. Pass `--global` when you genuinely want one shared set across projects that do not carry the package themselves; it installs to both locations, and the home copy then takes precedence.

Upgrading from a version that always installed globally (every release before this flag existed) leaves the old home copies behind, and those keep shadowing the project. Clear them once:

```bash
vendor/bin/agent-skills install --prune-global
```

It removes only the skill directories this package ships and leaves everything else in `~/.claude/skills` alone.

> [!WARNING]
> The match is by skill **name**, and the removal is immediate and irreversible — there is no dry run and no backup. If you hand-edited a home skill that shares a name with one this package ships, `--prune-global` deletes your edited copy too, because a customised copy and a stale one are indistinguishable from the outside. Move such a skill to a name this package does not use before running the flag.
