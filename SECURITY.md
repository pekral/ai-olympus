# Security

## Plugin trust model

`agentic-vibes/laravel-agent-skills` is a **Composer plugin** (`"type": "composer-plugin"`). Composer requires explicit opt-in before running any plugin — including this one — to guard against supply-chain attacks from unknown packages.

### `allow-plugins` in `composer.json`

When you `composer require agentic-vibes/laravel-agent-skills`, Composer may ask:

```
Do you trust "agentic-vibes/laravel-agent-skills" to execute code and wish to enable it now? (yes/no)
```

If you answer `yes`, Composer writes the following entry to your project's `composer.json`:

```json
{
  "config": {
    "allow-plugins": {
      "agentic-vibes/laravel-agent-skills": true
    }
  }
}
```

This is the **standard Composer plugin-trust mechanism** (`allow-plugins`). It is project-scoped, version-controlled alongside your `composer.json`, and must be deliberately set to `true` by you. The package never modifies `allow-plugins` on its own behalf — you remain in full control of which plugins your project trusts.

If you prefer to give a non-interactive answer (e.g. in CI), you can pass the flag explicitly:

```bash
composer require agentic-vibes/laravel-agent-skills --dev --no-plugins   # skip the plugin during install
composer config allow-plugins.agentic-vibes/laravel-agent-skills true     # then grant trust manually
```

### Auto-install hook

Granting `allow-plugins: true` also enables the package's Composer plugin to react to `post-install-cmd` and `post-update-cmd` events. By default the plugin does **nothing** on these events. The auto-install hook is activated only when you add the following opt-in to your project's `composer.json`:

```json
{
  "extra": {
    "agent-skills": {
      "auto-install": true
    }
  }
}
```

When `auto-install` is `true`, every `composer install` or `composer update` automatically runs `Installer::run(['agent-skills', 'install', '--force'])` — the same installer that you would call manually, with `--force` and without any opt-in flags (`--allow-bundled-scripts`, `--allow-subagent-writes`, `--deny-network-bash`). **Security implication:** any package that ships a `post-install-cmd` / `post-update-cmd` hook and is trusted via `allow-plugins` can trigger code execution during a routine `composer install`. Review the `extra.agent-skills` block in your `composer.json` before enabling `auto-install`, and treat it the same way you treat other Composer script hooks.

See also: [README — Automatic Installation via Composer Plugin](README.md#automatic-installation-via-composer-plugin).

## Installer security flags

All security-sensitive installer flags are **opt-in by design** — the package grants no additional permissions by default.

### `--allow-bundled-scripts`

**What it does.** Alongside this flag, the installer idempotently appends a narrow allow-list for this package's bundled scripts to `~/.claude/settings.json` (`permissions.allow`):

```
Bash(*skills/code-review-github/scripts/load-issue.sh:*)
Bash(*skills/code-review-jira/scripts/load-issue.sh:*)
```

These two patterns pre-approve the GitHub and JIRA `load-issue.sh` scripts that the `code-review-github` and `code-review-jira` skills invoke, so Claude Code stops prompting for confirmation on every run.

**What it does not do.** It grants access only to the two specific, version-controlled scripts shipped in this package. All other entries in `~/.claude/settings.json` are preserved untouched. The flag has no effect when neither `HOME` nor `USERPROFILE` is available.

**Implementation reference.** `src/InstallerClaudeSettings.php` — `applyIfRequested()` → `ensureBundledScriptPermissions()`.

See also: [README — CLI Switches](README.md#cli-switches).

### `--allow-subagent-writes`

**What it does.** Alongside this flag, the installer prepends two scoped permission entries to `permissions.allow` in the project's `.claude/settings.local.json`:

```
Edit(//<absolute-project-path>/**)
Write(//<absolute-project-path>/**)
```

These entries pre-allow dispatched subagents (e.g. `hefaistos`) to write files inside the project tree without requiring an interactive approval on each operation. A dispatched subagent runs non-interactively, so a write is denied at runtime unless the path is already in `permissions.allow`.

**Why `settings.local.json` and not `settings.json`.** The entries carry a machine-absolute path — they are personal and not portable. `settings.local.json` is git-ignored by Claude Code by default, so the absolute path never leaks into version control.

**Safety guarantees.** The flag is idempotent: it only adds missing entries and never removes or modifies existing ones. After writing, the installer reads the file back and validates that every required entry is present (`InstallerProjectSettings::validateSubagentWritePermissions()`), so a malformed file can never be produced. The package grants nothing by default — this flag is the explicit, human-owned opt-in.

**Implementation reference.** `src/InstallerProjectSettings.php` — `applySubagentWritesIfRequested()` → `ensureSubagentWritesEnabled()`.

See also: [docs/agents.md — Troubleshooting (subagent file writes blocked)](docs/agents.md#troubleshooting--subagent-file-writes-blocked) and [docs/plans/agent-sandbox-write-blocked.md](docs/plans/agent-sandbox-write-blocked.md).

### `--deny-network-bash`

**What it does.** Alongside this flag, the installer idempotently appends ten patterns to `permissions.deny` in the project's `.claude/settings.local.json`:

```
Bash(curl:*)        Bash(wget:*)     Bash(nc:*)      Bash(ncat:*)   Bash(netcat:*)
Bash(telnet:*)      Bash(ssh:*)      Bash(scp:*)     Bash(sftp:*)   Bash(openssl s_client:*)
```

Claude Code then **refuses** those commands before they run. This is the vendor's own recommended shape for the problem (deny the Bash network tools, keep `WebFetch(domain:…)` for the fetches you actually want), and its value is precise: permission rules are enforced by Claude Code, not by the model, so a prompt injection telling an agent to `curl attacker.example` is stopped by the harness rather than by the agent's good behaviour. `ncat` / `netcat` / `telnet` / `sftp` are listed separately because the `:*` suffix enforces a word boundary — `Bash(nc:*)` does not match `ncat`. `openssl` is denied only through its network subcommand, because a deny rule cannot carry allow-list exceptions and a bare `Bash(openssl:*)` would also block `openssl dgst`, the checksum verification this repository's own rules require.

**Scope — session-wide, project-scoped, never per agent.** A `permissions.deny` rule applies to the whole Claude Code session: inside this project it restricts **every agent and your own interactive Bash use identically**. That is the trade-off the flag exists to let you accept deliberately; it is why the flag is off by default and why it writes the project-local file rather than `~/.claude/settings.json` — permission rules merge across scopes and are evaluated deny → ask → allow with specificity ignored, so a deny in the user-level file would apply to every project on the machine and could not be relaxed by a project that genuinely needs `curl`. Your ordinary terminal outside Claude Code is unaffected. To make the policy team-wide, copy the same `permissions.deny` block into the committed `.claude/settings.json` yourself — the installer deliberately never writes a tracked file.

**What it does not do — this is not an egress control.** A permission rule matches the **command string Claude Code is asked to run**, never the process tree that command spawns. Concretely, the following all remain open:

1. **Child processes of any allowed command** — `gh`, `git clone` / `push` / `fetch`, `composer`, `npm`, `php -r`, `node -e`, `python3 -c "import socket"`. This package's own scripts are in that category by design and keep working with every pattern in place (`skills/code-review-bugsnag/scripts/_lib.sh`, `load-issue.sh`, `upsert-comment.sh`, `skills/_shared/attachments.sh`, `skills/_shared/assert-current-repo.sh` call `curl` / `ssh` directly).
2. **Wrappers that are not stripped** — `bash -c 'curl …'`, `sh -c`, `env curl`, `npx`, `docker exec`, `devbox run`, `mise exec`, `direnv exec`, `xargs -n1 curl` (only a bare `xargs` is stripped).
3. **Absolute and relative paths** — `Bash(curl:*)` matches the string `curl …`, not `/usr/bin/curl …` or `./curl`.
4. **Shell built-in networking** — `exec 3<>/dev/tcp/host/80` runs no binary, so there is nothing to match.
5. **Tools not on the list** — `socat`, `aria2c`, `httpie`, `rsync`, `ftp`, `openssl s_server` / `s_time`.
6. **`--dangerously-skip-permissions` / `bypassPermissions`**, which skip rule evaluation entirely.
7. **`WebFetch` / `WebSearch`** stay available wherever they are granted — deliberately, per the guidance above.

The tier that would actually close 1–4 is Claude Code **sandboxing**, an OS-level restriction on the Bash tool's filesystem and network access that also covers child processes. **This package does not configure sandboxing**, and this flag only approximates it for literal, first-order network commands. Treat the gain as *advisory instruction → harness-enforced refusal for the listed command strings*, not as containment against a determined agent.

**Safety guarantees.** The flag is idempotent and additive: it only appends missing patterns, and existing `permissions.allow` entries and unrelated keys are preserved untouched, as is every string entry already in `permissions.deny` (nothing is reordered). One precise exception, stated rather than glossed over: a **non-string** item inside `permissions.deny` — a number, `null`, an object — is dropped when that list is rewritten, because the installer sanitises the list to strings and such an item is not a rule Claude Code could enforce in the first place. After writing, the installer reads the file back and validates that every pattern is present (`InstallerProjectSettings::validateNetworkBashDenyPermissions()`) — so the installer never reports the restriction as applied when it was not actually written. Unlike `--allow-bundled-scripts`, it has no `HOME` precondition that could turn it into a silent no-op.

**How to undo it.** There is no inverse flag. Open `.claude/settings.local.json` in the project, delete the ten `Bash(...)` strings above from the `permissions.deny` array (leaving any entry you added yourself), and save. Removing the whole `deny` key is also safe if it holds nothing else.

**Implementation reference.** `src/InstallerProjectSettings.php` — `applyNetworkBashDenyIfRequested()` → `ensureNetworkBashDenyPermissions()`, patterns in `getNetworkBashDenyPermissions()`.

## Agent capability model & residual risk

The five shipped subagents (`agents/*.md`) each declare a `tools:` allow-list and, since issue #163, a `disallowedTools:` entry — the two layers the Claude Code harness actually enforces. Both are pinned by `tests/Installer/AgentsTest.php` so an agent cannot silently gain a tool it should not have. Full detail (per-agent Bash purpose lists, the harness research behind the numbers below) lives in `docs/agents.md` *Capability model* and `@rules/compound-engineering/general.md` *Bash capability boundary*; this section states only the facts a security reviewer of this package needs without opening either.

- **Enforced today:** the `tools:` allow-list itself, and the `disallowedTools:` entry every agent now carries (read-only agents lose `Write, Edit`; agents with no documentation-fetch need lose `WebSearch, WebFetch`).
- **Not enforced, advisory only:** every agent also carries `Bash`, which subsumes both write access and outbound network access regardless of what `tools:` / `disallowedTools:` say. There is no per-agent Bash command allow-list this package ships or can ship: the agent frontmatter `tools:` field has no syntax for a scoped command pattern (`Bash(gh:*)` is not expressible), and `permissions.allow` / `permissions.deny` patterns apply session-wide, never per agent, so scoping one agent's Bash would scope every agent's (and the human's) identically. The only genuinely per-agent mechanism is a `hooks: PreToolUse` validator — runtime code this instructions-only package does not ship. One was shipped behind an opt-in `--enforce-agent-bash-boundary` flag and removed again (issue #265), because it failed open in eleven separate cases and asked the user to confirm ordinary commands it could not read.
- **What the installer writes by default:** without an opt-in flag, the installer writes no Bash restriction of any kind — not a `permissions.allow` / `permissions.deny` entry, not a hook, nothing. `--allow-bundled-scripts` and `--allow-subagent-writes` do not restrict Bash either; they only pre-approve two specific scripts and pre-allow `Write`/`Edit` for a dispatched subagent, respectively.
- **One partial mechanism now exists, opt-in:** `--deny-network-bash` (see *Installer security flags* above) writes `permissions.deny` entries for ten literal outbound-network commands, moving exactly those command strings from advisory instruction to harness-enforced refusal. It narrows the gap; it does not close it. The restriction is **session-wide, not per agent** (it restricts the human's own interactive Bash in this project identically), and it matches command strings rather than process trees — child processes of allowed commands, unstripped wrappers, absolute paths, `/dev/tcp`, and unlisted tools all remain open, as enumerated under `--deny-network-bash`. Everything not on that list stays exactly as advisory as before.
- **No mechanism makes the boundary per-agent.** The per-agent half of the Bash boundary is advisory in full. The OS-level tier (Claude Code sandboxing) is not configured by this package, and it remains the only tier that would cover child processes.
- **If you ever installed the removed hook, remove its entry by hand.** The flag never had an inverse and its removal does not add one, so a project that opted in still carries the handler in `.claude/settings.local.json`. It now points at a subcommand the binary no longer has: `agent-skills bash-guard` falls through to the installer, prints `Unknown command: bash-guard`, and exits `1`. Claude Code treats a non-zero exit other than `2` as a non-blocking hook error, so the Bash call still runs — but the error is printed on **every** Bash call until the entry is gone. Delete the handler whose `command` ends in `agent-skills bash-guard` from the `Bash` group under `hooks.PreToolUse` (leave any handler you added yourself; removing the whole `hooks` key is safe when it holds nothing else), then restart the session — hooks are read once, at session start.

## Files this package writes

| Path | Created by | Condition |
|------|-----------|-----------|
| `~/.claude/settings.json` — sets `includeCoAuthoredBy: false` | `install` (unconditional) | `HOME`/`USERPROFILE` set; key absent — never overwrites an existing value |
| `~/.claude/settings.json` — adds `permissions.allow` bundled-script entries | `--allow-bundled-scripts` | `HOME`/`USERPROFILE` set |
| `.claude/settings.local.json` — prepends `permissions.allow` scoped `Edit`/`Write` entries | `--allow-subagent-writes` | always |
| `.claude/settings.local.json` — appends `permissions.deny` network-command entries | `--deny-network-bash` | always |
| `.claude/rules/` | `install` | always |
| `.claude/skills/` (and `~/.claude/skills/` when `HOME`/`USERPROFILE` is set) | `install` | always |
| `.claude/agents/` | `install` | always |
| `CLAUDE.md` | `install` | always; never overwrites an existing file |

The installer never writes outside the project directory and the user's home directory, and it never modifies `composer.json` or any project source file.

## Reporting a vulnerability

If you discover a security issue in this package, please report it privately so it can be addressed before public disclosure.

**Contact:** open a [GitHub Security Advisory](https://github.com/agentic-vibes/laravel-agent-skills/security/advisories/new) (preferred) or email `kral.petr.88@gmail.com`.

Please include a description of the issue, reproduction steps, and the potential impact. You will receive a response within a reasonable time. Public disclosure is coordinated after a fix is available.
