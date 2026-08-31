# Installation

Operational reference for the `ai-olympus` installer. The two commands you need to get started are in the [Quickstart](../README.md#quickstart) — this page covers everything beyond them: how the installer finds your project, what it writes, how to automate it, and every CLI switch.

Everything on this page describes the **Composer** path. A project without Composer installs through the [plugin marketplace](#installing-without-composer-plugin-marketplace) instead, which is deliberately narrower.

## How the Installer Works

The installer discovers the project root by walking up from the current directory until it finds a `composer.json`. It then mirrors the `rules/` directory into `.claude/rules` and the `skills/` directory into `.claude/skills`, copying every file into the target project — or symlinking it when you pass `--symlink` and the operating system permits it.

When the package is required via Composer, sources are read from `vendor/pekral/ai-olympus/rules` and `vendor/pekral/ai-olympus/skills`.

## Automatic Installation via Composer Plugin

By default, the Composer plugin does **not** auto-install rules on `composer install` or `composer update`. To enable automatic installation, add the following to your project's `composer.json`:

```json
{
  "extra": {
    "ai-olympus": {
      "auto-install": true
    }
  }
}
```

| Option         | Description                                              | Default   |
|----------------|----------------------------------------------------------|-----------|
| `auto-install` | Enable automatic install on `composer install/update`.   | `false`   |

If you prefer manual control, simply call `vendor/bin/ai-olympus install` in your Composer `post-update-cmd` scripts with the desired flags.

## Available Commands

```bash
vendor/bin/ai-olympus help                                  # print help
vendor/bin/ai-olympus install                                # install for Claude Code
vendor/bin/ai-olympus install --force                        # overwrite existing files
vendor/bin/ai-olympus install --symlink                      # prefer symlinks (fallback to copy)
vendor/bin/ai-olympus install --prune                        # remove files in target that no longer exist in source
vendor/bin/ai-olympus install --global                       # also install skills to ~/.claude/skills (off by default)
vendor/bin/ai-olympus install --prune-global                 # remove this package's skills from ~/.claude/skills
vendor/bin/ai-olympus install --allow-bundled-scripts         # whitelist this package's bundled scripts in ~/.claude/settings.json
vendor/bin/ai-olympus install --allow-subagent-writes         # allow dispatched-subagent file writes (scoped Edit/Write) in .claude/settings.local.json
vendor/bin/ai-olympus install --deny-network-bash             # deny outbound-network Bash commands (curl, wget, ssh, ...) in .claude/settings.local.json
```

## Installer Flow

1. Determine the project root by walking up from the current directory until `composer.json` is found.
2. Resolve the rules source (local `rules/` or `vendor/pekral/ai-olympus/rules`).
3. Install rules into `.claude/rules`.
4. If present, resolve the skills source and install into `.claude/skills` (and additionally into `~/.claude/skills` when `--global` is passed and `HOME`/`USERPROFILE` is set).
5. Copy `agents/` to `.claude/agents` and `CLAUDE.md` to the project root (never overwrites existing).
6. Remove any leftover handler under `hooks` in `.claude/settings.local.json` that points at the removed `bash-guard` validator, so a project that once opted into the deleted `--enforce-agent-bash-boundary` flag stops seeing a `PreToolUse` hook error on every Bash call. Only that handler is removed; every other key in the file is preserved, and a project that has no such handler is not written to at all. The file is **read** on every `install` to make this check, so a `.claude/settings.local.json` file that is not valid JSON now ends the install with `Cannot parse Claude settings file <path>: Syntax error.` and exit `1` instead of being skipped. Restart the session afterwards — hooks are read once, at session start. See [`SECURITY.md`](../SECURITY.md#agent-capability-model--residual-risk).
7. Optionally overwrite existing files with `--force`; use `--symlink` to prefer symlinks (fallback to copy on Windows).
8. Surface explicit errors for missing directories, removal failures, and copy/symlink failures.

## CLI Switches

| Option            | Description                                                                 |
|-------------------|-----------------------------------------------------------------------------|
| `--force`                 | Overwrite files that already exist in the target directory.                                                                                                 |
| `--symlink`               | Create symlinks when the OS permits; automatically falls back to copy.                                                                                      |
| `--prune`                 | Remove files in target that no longer exist in source.                                                                                                       |
| `--global`                | Opt-in. Also install skills into `~/.claude/skills`. Off by default — see [Where skills are installed](#where-skills-are-installed). No effect when `HOME` / `USERPROFILE` is not set. |
| `--prune-global`          | Remove this package's skills from `~/.claude/skills` so the project copy is the one Claude Code loads. Matches by skill name; skills under other names are left untouched, and a symlinked install is removed as the link only. Irreversible — see the warning under [Where skills are installed](#where-skills-are-installed). Cannot be combined with `--global`. |
| `--allow-bundled-scripts` | Opt-in. Idempotently appends a narrow allow-list for this package's bundled scripts (`load-issue.sh` for GitHub and JIRA) to `~/.claude/settings.json`, so Claude Code stops prompting on every run. Other entries in `settings.json` are preserved. No effect when `HOME` / `USERPROFILE` is not set. |
| `--allow-subagent-writes` | Opt-in. Idempotently prepends scoped `Edit` / `Write` allow entries for the project working tree to `permissions.allow` in `.claude/settings.local.json`, so a dispatched subagent (e.g. `hephaestus`) can write files without interactive approval. Existing allow entries and unrelated keys are preserved. |
| `--deny-network-bash`     | Opt-in. Idempotently appends ten `permissions.deny` patterns (`curl`, `wget`, `nc`, `ncat`, `netcat`, `telnet`, `ssh`, `scp`, `sftp`, `openssl s_client`) to `.claude/settings.local.json`, so Claude Code refuses those literal Bash commands. The rule is **session-wide and project-scoped**: inside this project it applies to every agent *and* to your own interactive Bash, never per agent. Existing `allow` and foreign `deny` entries are preserved. It is **not** an egress control — see [`SECURITY.md`](../SECURITY.md#--deny-network-bash) for what it does not cover and how to undo it. |
| *(default)*               | Only copy missing files and keep existing content untouched.                                                                                                |

## Where skills are installed

Skills go to the project's `.claude/skills` and nowhere else unless you ask for more. That default follows from how Claude Code resolves a name collision — [its documentation](https://code.claude.com/docs/en/skills) states it plainly:

> When skills share the same name across levels, enterprise overrides personal, and personal overrides project.

So a copy in `~/.claude/skills` wins over the project's own copy, in **every** project on the machine. Install globally and each checkout silently runs whatever version the home directory happens to hold, rather than the version it has checked out — the two drift apart the moment one project upgrades the package and another does not, and nothing in the session surfaces which one won.

Keeping the install local ties each project to its own `composer.lock`. Pass `--global` when you genuinely want one shared set across projects that do not carry the package themselves; it installs to both locations, and the home copy then takes precedence.

Upgrading from a version that always installed globally (every release before this flag existed) leaves the old home copies behind, and those keep shadowing the project. Clear them once:

```bash
vendor/bin/ai-olympus install --prune-global
```

It removes only the skill directories this package ships and leaves everything else in `~/.claude/skills` alone.

> [!WARNING]
> The match is by skill **name**, and the removal is immediate and irreversible — there is no dry run and no backup. If you hand-edited a home skill that shares a name with one this package ships, `--prune-global` deletes your edited copy too, because a customised copy and a stale one are indistinguishable from the outside. Move such a skill to a name this package does not use before running the flag.

## Installing without Composer (plugin marketplace)

The package is a Claude Code plugin as well as a Composer plugin. Most of what it ships — the git, code-review, compound-engineering, writing, and security rules, and every skill that is not PHP-specific — needs no PHP at all, and a project without Composer had no way to reach any of it.

```text
/plugin marketplace add pekral/ai-olympus
/plugin install ai-olympus@ai-olympus
```

The plugin lives at the repository root (`.claude-plugin/marketplace.json` points at `./`), so there is no second copy of anything and no second version to keep in step: the plugin ships whatever the git checkout holds.

### What the plugin loads, and what it cannot

Claude Code reads `skills/` and `agents/` out of a plugin directory. It reads **neither `rules/` nor a `CLAUDE.md`** — there is no plugin mechanism for a project-scoped always-on instruction file. So the split is:

| | Loaded by the plugin |
|---|---|
| 53 skills (`skills/*/SKILL.md`) | ✅ automatically |
| 4 agents (`agents/*.md`) | ✅ automatically |
| Rules (`rules/**`) | ❌ — `/ai-olympus:install-rules` copies them |
| `CLAUDE.md` | ❌ — same command, and only when the project has none |
| `.claude/settings.local.json` switches (`--deny-network-bash`, …) | ❌ Composer only |
| `ai-olympus resolve-next` | ❌ Composer only |

```text
/ai-olympus:install-rules
```

The command copies `rules/` from the plugin directory into the project's `.claude/rules/`, overwriting what is there — they are package files, and a stale copy is exactly the drift the command prevents. It copies `CLAUDE.md` only when the project has none, the same guarantee the Composer installer carries. Rules are read at session start, so restart the session afterwards.

Re-run it after `/plugin update` to pick up rule changes; the skills and agents update on their own.

### Which path to choose

Take **Composer** on any PHP project. It installs the rules without a second step, it carries the opt-in security switches, and it pins the whole package to that project's `composer.lock` — so two checkouts cannot silently drift onto different versions.

Take the **plugin marketplace** when the project has no `composer.json` to install into, or when you want the skills and agents available without adding a dev dependency.
