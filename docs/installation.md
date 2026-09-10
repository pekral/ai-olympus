# Installation

Operational reference for the `ai-olympus` installer. The two commands you need to get started are in the [Quickstart](../README.md#quickstart) — this page covers everything beyond them: how the installer finds your project, what it writes, how to automate it, and every CLI switch.

Everything on this page describes the **Composer** path. A project without Composer installs through the [plugin marketplace](#installing-without-composer-plugin-marketplace) instead, which is deliberately narrower.

## Versions and upgrades

The Composer installer requires PHP `^8.3` and Composer 2. Its distribution install is checked in CI on PHP 8.3, 8.4, and 8.5. Development dependencies and the full test suite require PHP 8.5.

Release `0.1.1` uses the exact Git tag `0.1.1`. Install this version from Packagist with:

```bash
composer require pekral/ai-olympus:0.1.1 --dev
vendor/bin/ai-olympus install --force --prune
```

Commit the consuming project's `composer.lock` to keep installations reproducible. The `0.1.1` constraint pins this version; `^0.1` permits subsequent `0.1.x` patches. During `0.x`, a new minor version may change workflow behavior or installer options. Review the [changelog](../CHANGELOG.md) before changing the constraint, save local customizations, and refresh installed files with `--force --prune` after an update.

## Global settings and attribution

A default install does not read or write `~/.claude/settings.json`. To opt into the package's AI co-author preference, run:

```bash
vendor/bin/ai-olympus install --disable-co-author-attribution
```

This sets `includeCoAuthoredBy: false` only when the key is absent and preserves all existing values and unrelated settings. It affects Claude Code across projects. Existing preferences written by older installations are left unchanged; remove the key manually to return to Claude Code's default. Without `HOME` or `USERPROFILE`, the flag has no effect. Automatic installation never enables this flag.

## How the Installer Works

The installer discovers the project root by walking up from the current directory until it finds a `composer.json`. It mirrors the same source artifacts into both supported harnesses: rules into `.claude/rules` and `.codex/rules`, skills into `.claude/skills` and Codex's native `.agents/skills`, and the five roles into each harness's agent format. Files are copied by default or symlinked when you pass `--symlink` and the operating system permits it.

When the package is required via Composer, sources are read from `vendor/pekral/ai-olympus/rules` and `vendor/pekral/ai-olympus/skills`. The installed `CLAUDE.md` comes from `templates/CLAUDE.md`; this repository's root `CLAUDE.md` contains additional maintenance instructions that are not copied to consuming projects.

### Distribution contents

GitHub distribution archives and `composer archive` omit `assets/` and `docs/`. The installer reads `src/`, `rules/`, `skills/`, `agents/`, `commands/`, `codex/agents/`, `templates/CLAUDE.md`, and `AGENTS.md`; it does not require the artwork or this repository's documentation. A workflow's `docs/memory/PROJECT_MEMORY.md` refers to the consuming project's own memory, not the package's development history.

Composer uses distribution archives by default; `--prefer-dist` selects them explicitly. `--prefer-source` clones the Git repository and therefore includes its artwork and documentation. The originals and documentation remain available on [GitHub](https://github.com/pekral/ai-olympus). Existing locked revisions retain their original archive contents until the dependency is updated.

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
vendor/bin/ai-olympus install                                # install for Claude Code and Codex
vendor/bin/ai-olympus install --force                        # overwrite existing files
vendor/bin/ai-olympus install --symlink                      # prefer symlinks (fallback to copy)
vendor/bin/ai-olympus install --prune                        # remove files in target that no longer exist in source
vendor/bin/ai-olympus install --global                       # also install skills to ~/.claude/skills and ~/.agents/skills
vendor/bin/ai-olympus install --prune-global                 # remove this package's skills from both home locations
vendor/bin/ai-olympus install --disable-co-author-attribution # opt into the global Claude co-author preference
vendor/bin/ai-olympus install --allow-bundled-scripts         # whitelist this package's bundled scripts in ~/.claude/settings.json
vendor/bin/ai-olympus install --allow-subagent-writes         # allow dispatched-subagent file writes (scoped Edit/Write) in .claude/settings.local.json
vendor/bin/ai-olympus install --deny-network-bash             # deny outbound-network Bash commands (curl, wget, ssh, ...) in .claude/settings.local.json
```

## Installer Flow

1. Determine the project root by walking up from the current directory until `composer.json` is found.
2. Resolve the rules source (local `rules/` or `vendor/pekral/ai-olympus/rules`).
3. Install rules into `.claude/rules` and `.codex/rules`.
4. Install skills into `.claude/skills` and `.agents/skills` (and additionally into `~/.claude/skills` and `~/.agents/skills` when `--global` is passed and `HOME`/`USERPROFILE` is set).
5. Copy `agents/` to `.claude/agents` and `.codex/agent-instructions`, install the Codex TOML adapters into `.codex/agents`, and copy `CLAUDE.md` / `AGENTS.md` to the project root. Neither root instruction file is overwritten once it exists.
6. Remove any leftover handler under `hooks` in `.claude/settings.local.json` that points at the removed `bash-guard` validator, so a project that once opted into the deleted `--enforce-agent-bash-boundary` flag stops seeing a `PreToolUse` hook error on every Bash call. Only that handler is removed; every other key in the file is preserved, and a project that has no such handler is not written to at all. The file is **read** on every `install` to make this check, so a `.claude/settings.local.json` file that is not valid JSON now ends the install with `Cannot parse Claude settings file <path>: Syntax error.` and exit `1` instead of being skipped. Restart the session afterwards — hooks are read once, at session start. See [`SECURITY.md`](../SECURITY.md#agent-capability-model--residual-risk).
7. Optionally overwrite existing files with `--force`; use `--symlink` to prefer symlinks (fallback to copy on Windows).
8. Surface explicit errors for missing directories, removal failures, and copy/symlink failures.

## CLI Switches

| Option            | Description                                                                 |
|-------------------|-----------------------------------------------------------------------------|
| `--force`                 | Overwrite files that already exist in the target directory.                                                                                                 |
| `--symlink`               | Create symlinks when the OS permits; automatically falls back to copy.                                                                                      |
| `--prune`                 | Remove files in target that no longer exist in source.                                                                                                       |
| `--global`                | Opt-in. Also install skills into `~/.claude/skills` and `~/.agents/skills`. Off by default — see [Where skills are installed](#where-skills-are-installed). No effect when `HOME` / `USERPROFILE` is not set. |
| `--prune-global`          | Remove this package's skills from both home locations so project copies load. Matches by skill name; skills under other names are left untouched, and a symlinked install is removed as the link only. Irreversible — see the warning under [Where skills are installed](#where-skills-are-installed). Cannot be combined with `--global`. |
| `--disable-co-author-attribution` | Opt-in. Sets `includeCoAuthoredBy: false` in `~/.claude/settings.json` only when absent. Preserves existing values. See [Global settings and attribution](#global-settings-and-attribution). |
| `--allow-bundled-scripts` | Opt-in. Idempotently appends a narrow allow-list for this package's bundled scripts (`load-issue.sh` for GitHub and JIRA) to `~/.claude/settings.json`, so Claude Code stops prompting on every run. Other entries in `settings.json` are preserved. No effect when `HOME` / `USERPROFILE` is not set. |
| `--allow-subagent-writes` | Opt-in. Idempotently prepends scoped `Edit` / `Write` allow entries for the project working tree to `permissions.allow` in `.claude/settings.local.json`, so a dispatched subagent (e.g. `hephaestus`) can write files without interactive approval. Existing allow entries and unrelated keys are preserved. |
| `--deny-network-bash`     | Opt-in. Idempotently appends ten `permissions.deny` patterns (`curl`, `wget`, `nc`, `ncat`, `netcat`, `telnet`, `ssh`, `scp`, `sftp`, `openssl s_client`) to `.claude/settings.local.json`, so Claude Code refuses those literal Bash commands. The rule is **session-wide and project-scoped**: inside this project it applies to every agent *and* to your own interactive Bash, never per agent. Existing `allow` and foreign `deny` entries are preserved. It is **not** an egress control — see [`SECURITY.md`](../SECURITY.md#--deny-network-bash) for what it does not cover and how to undo it. |
| *(default)*               | Copy missing files; refresh security rules and remove obsolete `bash-guard` hooks. Preserve root instruction files and global Claude settings.                                                                                                |

## Where skills are installed

Skills go to the project's `.claude/skills` and `.agents/skills` and nowhere else unless you ask for more. Codex's [official skill documentation](https://developers.openai.com/codex/skills/) defines `.agents/skills` as its repository location. The project-first default also follows from how Claude Code resolves a name collision — [its documentation](https://code.claude.com/docs/en/skills) states it plainly:

> When skills share the same name across levels, enterprise overrides personal, and personal overrides project.

So a copy in `~/.claude/skills` wins over the project's own copy, in **every** project on the machine. Install globally and each checkout silently runs whatever version the home directory happens to hold, rather than the version it has checked out — the two drift apart the moment one project upgrades the package and another does not, and nothing in the session surfaces which one won.

Keeping the install local ties each project to its own `composer.lock`. Pass `--global` when you genuinely want one shared set across projects that do not carry the package themselves; it installs to both locations, and the home copy then takes precedence.

Upgrading from a version that always installed globally (every release before this flag existed) leaves the old home copies behind, and those keep shadowing the project. Clear them once:

```bash
vendor/bin/ai-olympus install --prune-global
```

It removes only the skill directories this package ships and leaves everything else in `~/.claude/skills` and `~/.agents/skills` alone.

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
| 55 skills (`skills/*/SKILL.md`) | ✅ automatically |
| 5 agents (`agents/*.md`) | ✅ automatically |
| `/prepare-issue-for-merge` (`commands/*.md`) | ✅ automatically |
| Rules (`rules/**`) | ❌ Composer only |
| `CLAUDE.md` | ❌ Composer only |
| `.claude/settings.local.json` switches (`--deny-network-bash`, …) | ❌ Composer only |

The package used to ship a `/ai-olympus:install-rules` command that copied `rules/` and `CLAUDE.md` out of the plugin directory. It no longer does: `commands/` now carries `prepare-issue-for-merge.md` alone. On this channel the rules and `CLAUDE.md` therefore do not arrive at all — install through Composer when you want them.

Skills, agents, and the command update on their own after `/plugin update`.

### Which path to choose

Take **Composer** on any PHP project. It installs the rules without a second step, it carries the opt-in security switches, and it pins the whole package to that project's `composer.lock` — so two checkouts cannot silently drift onto different versions.

Take the **plugin marketplace** when the project has no `composer.json` to install into, or when you want the skills and agents available without adding a dev dependency.
