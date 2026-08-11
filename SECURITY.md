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

### `--enforce-agent-bash-boundary`

**What it does.** Alongside this flag, the installer idempotently registers one `PreToolUse` hook in the project's `.claude/settings.local.json`:

```json
{
  "hooks": {
    "PreToolUse": [
      {
        "matcher": "Bash",
        "hooks": [{ "type": "command", "command": "<absolute path>/agent-skills bash-guard", "timeout": 10 }]
      }
    ]
  }
}
```

Claude Code then asks that validator about every Bash call before running it. The validator reads the payload's `agent_type` and answers `deny` for a command outside that agent's `## Bash boundary`, `ask` when it cannot read the command with confidence, and `defer` — fall through to the normal permission flow — for everything else. It **never** answers `allow`: an `allow` would override the project's own `permissions.deny`, including the entries `--deny-network-bash` writes. This is the mechanism that makes the boundary genuinely **per agent**, which no `permissions.*` rule can be. Full decision logic, the policy it reads, and its own residual gaps: `docs/agents.md` *Architecture constraint*.

**Scope — per agent, project-scoped, opt-in.** The hook is registered once, for the whole session, and the per-agent part comes from the payload rather than from where it is registered. Two consequences worth stating rather than discovering: the validator is asked about **every** Bash call in the project, including your own interactive ones, and the rules that apply to every agent regardless of its own list — no outbound network request, no `sudo`, no reading `~/.ssh/*` or `.env*` — apply to those too, so `curl` typed into your own Claude Code prompt in this project is refused just as an agent's would be. Only the *per-agent* half of the policy needs an `agent_type` to apply. It is deliberately **not** written into `agents/*.md` frontmatter, which would read more literally as "per agent": frontmatter hooks were reported not to fire for Task-dispatched subagents at all (anthropics/claude-code#18392, closed as a duplicate with no confirmed fix), they carry a workspace-trust gate of their own, and `agents/*.md` is copied into every consuming project **unconditionally** — a `hooks:` line there would ship this to projects that never opted in, which is exactly what *Installer security flags* rules out. It is written to `settings.local.json` rather than `~/.claude/settings.json` for the same reason `--deny-network-bash` is: hook and permission scopes merge, so a user-level entry would apply to every project on the machine and could not be relaxed by one that needs it off. Your ordinary terminal outside Claude Code is unaffected.

**What it does not do — it is a check the harness performs for you, not a sandbox.** Every item below is a way the layer **fails open** — the command simply runs, exactly as advisory as before the flag existed:

1. **Untrusted workspace.** Until you accept workspace trust for the project, hooks from project settings are not loaded and every Bash call proceeds unchecked. The installed hook therefore fails *open*, not closed, on a fresh checkout nobody has trusted yet. This is the vendor-documented behaviour; it has **not** been confirmed empirically by this project, and is stated as documented rather than measured.
2. **Hooks are a session-start snapshot.** A session already running when you install the flag stays unprotected until it restarts, and a later change to the entry needs re-approval in `/hooks`. That is why the installer's summary line tells you to restart.
3. **Self-disarming.** Every agent carries `Bash`, and `hefaistos` carries `Write` and `Edit` as well, so an agent can delete the entry from `.claude/settings.local.json` or overwrite the validator inside `vendor/`. This is a runtime layer under advisory documentation, not containment against an agent that wants out.
4. **A binary that cannot run, or runs too slowly.** `composer install --no-dev`, an emptied `vendor/`, no `php` on the hook's own PATH, or the 10-second timeout each produce a non-blocking error — and a non-blocking error means the command runs. The installer smoke-runs the binary once before writing the entry, which catches the broken-install case at install time but nothing at runtime.
5. **`--dangerously-skip-permissions` / `permissionMode: bypassPermissions`.** Whether a `deny` decision is still honoured in these modes is **unverified** — it needs a live session to establish and this project has not measured it. Assume it is not, until someone does.
6. **Only the command string, never the process tree.** Child processes of a permitted command (`gh`, `git`, `composer`, `npm`, `php -r`, `node -e`) are invisible to it. This package's own scripts are in that category by design and keep working.
7. **Obfuscation.** `c=curl; $c …`, `base64 -d | sh`, a shell function, or a script whose *contents* call `curl` are not visible in the command string before it runs.
8. **Only the `Bash` matcher.** `Write`, `Edit`, `WebFetch`, `WebSearch`, and MCP tools are not matched. Bash is the only tool whose input is a command string this validator can read.
9. **`agent_type` may be absent.** When the harness does not deliver it, the per-agent half degrades silently to the global rules that apply to every agent. A missing `agent_type` is never read as trustworthy.
10. **`defer` is honoured only in print mode.** Measured against Claude Code 2.1.221: an interactive session ignores a `defer` decision and prints a warning, as does any batch carrying more than one tool call. The *effect* still matches the intent — the call falls through to the normal permission flow — but expect the warning on ordinary, permitted commands.
11. **Some launch modes disable hooks outright.** Also measured on 2.1.221: `claude --bare` and `claude --safe-mode` run no hooks at all, and `claude -p` / `--print` silently ignores a settings file it cannot parse.

The tier that would actually close 3, 6, and 7 is Claude Code **sandboxing**, an OS-level restriction on the Bash tool that also covers child processes. **This package does not configure sandboxing.** As with `--deny-network-bash`: it narrows the gap; it does not close it.

**Safety guarantees.** The flag is idempotent and additive: it adds one handler and only when an identical one is absent. Foreign `PreToolUse` matcher groups, foreign handlers inside the `Bash` group, the `permissions` block, and every unrelated key are preserved in place and in order — nothing is overwritten or reordered. One precise exception, stated rather than glossed over: an item inside `hooks.PreToolUse`, or inside a group's own `hooks` list, that is **not a JSON object** is dropped when that list is rewritten, because it is not a handler Claude Code could run in the first place. Before writing anything the installer resolves the `agent-skills` binary and runs it once (`bash-guard --self-test`), requiring a valid `deny` answer and exit 0; if the binary cannot be found, cannot be expressed as a hook command, or answers anything else, the install **fails loudly and writes no hook**. After writing, the file is read back and the entry — command *and* explicit timeout — is validated (`InstallerHookSettings::validateAgentBashBoundaryHook()`), so the installer never reports the protection as applied when it was not actually written. The validator itself writes no file, makes no network call, and keeps no log: a Bash command may carry a token, and logging one would create a secrets-at-rest surface that does not exist today.

**How to undo it.** There is no inverse flag. Open `.claude/settings.local.json` in the project, delete the handler whose `command` ends in `agent-skills bash-guard` from the `Bash` group under `hooks.PreToolUse` (leaving any handler you added yourself), and save. Removing the whole `hooks` key is also safe if it holds nothing else. Restart the session afterwards, for the same reason as installing it.

**Implementation reference.** `src/InstallerHookSettings.php` — `applyAgentBashBoundaryIfRequested()` → `ensureAgentBashBoundaryHook()` → `validateAgentBashBoundaryHook()`; binary resolution and the install-time smoke run in `src/InstallerBashGuard.php`; the decision logic in `src/AgentBashBoundaryPolicy.php` and `src/AgentBashBoundaryGuard.php`.

## Agent capability model & residual risk

The five shipped subagents (`agents/*.md`) each declare a `tools:` allow-list and, since issue #163, a `disallowedTools:` entry — the two layers the Claude Code harness actually enforces. Both are pinned by `tests/Installer/AgentsTest.php` so an agent cannot silently gain a tool it should not have. Full detail (per-agent Bash purpose lists, the harness research behind the numbers below) lives in `docs/agents.md` *Capability model* and `@rules/compound-engineering/general.md` *Bash capability boundary*; this section states only the facts a security reviewer of this package needs without opening either.

- **Enforced today:** the `tools:` allow-list itself, and the `disallowedTools:` entry every agent now carries (read-only agents lose `Write, Edit`; agents with no documentation-fetch need lose `WebSearch, WebFetch`).
- **Not enforced, advisory only:** every agent also carries `Bash`, which subsumes both write access and outbound network access regardless of what `tools:` / `disallowedTools:` say. There is no per-agent Bash command allow-list this package ships or can ship: the agent frontmatter `tools:` field has no syntax for a scoped command pattern (`Bash(gh:*)` is not expressible), and `permissions.allow` / `permissions.deny` patterns apply session-wide, never per agent, so scoping one agent's Bash would scope every agent's (and the human's) identically. The only genuinely per-agent mechanism is a `hooks: PreToolUse` validator, which this package now ships behind the opt-in `--enforce-agent-bash-boundary` — off by default, and fail-open in every case enumerated under that flag.
- **What the installer writes by default:** without an opt-in flag, the installer writes no Bash restriction of any kind — not a `permissions.allow` / `permissions.deny` entry, not a hook, nothing. `--allow-bundled-scripts` and `--allow-subagent-writes` do not restrict Bash either; they only pre-approve two specific scripts and pre-allow `Write`/`Edit` for a dispatched subagent, respectively.
- **One partial mechanism now exists, opt-in:** `--deny-network-bash` (see *Installer security flags* above) writes `permissions.deny` entries for ten literal outbound-network commands, moving exactly those command strings from advisory instruction to harness-enforced refusal. It narrows the gap; it does not close it. The restriction is **session-wide, not per agent** (it restricts the human's own interactive Bash in this project identically), and it matches command strings rather than process trees — child processes of allowed commands, unstripped wrappers, absolute paths, `/dev/tcp`, and unlisted tools all remain open, as enumerated under `--deny-network-bash`. Everything not on that list stays exactly as advisory as before.
- **The mechanism that makes the boundary genuinely per-agent now exists too, also opt-in:** `--enforce-agent-bash-boundary` registers a `PreToolUse` hook that asks this package's own validator about every Bash call and can refuse it per agent. It is off by default, and it is a **check, not a sandbox**: it fails open on an untrusted workspace, on any error or timeout of the validator, and against an agent that simply deletes the hook entry — the full enumeration is under that flag. It narrows the gap; it does not close it. The OS-level tier (Claude Code sandboxing) is still not configured by this package, and it remains the only tier that would cover child processes.

## Files this package writes

| Path | Created by | Condition |
|------|-----------|-----------|
| `~/.claude/settings.json` — sets `includeCoAuthoredBy: false` | `install` (unconditional) | `HOME`/`USERPROFILE` set; key absent — never overwrites an existing value |
| `~/.claude/settings.json` — adds `permissions.allow` bundled-script entries | `--allow-bundled-scripts` | `HOME`/`USERPROFILE` set |
| `.claude/settings.local.json` — prepends `permissions.allow` scoped `Edit`/`Write` entries | `--allow-subagent-writes` | always |
| `.claude/settings.local.json` — appends `permissions.deny` network-command entries | `--deny-network-bash` | always |
| `.claude/settings.local.json` — registers one `hooks.PreToolUse` `Bash` handler | `--enforce-agent-bash-boundary` | the `agent-skills` binary resolves and passes its install-time smoke run; otherwise the install fails and nothing is written |
| `.claude/rules/` | `install` | always |
| `.claude/skills/` (and `~/.claude/skills/` when `HOME`/`USERPROFILE` is set) | `install` | always |
| `.claude/agents/` | `install` | always |
| `CLAUDE.md` | `install` | always; never overwrites an existing file |

The installer never writes outside the project directory and the user's home directory, and it never modifies `composer.json` or any project source file.

## Reporting a vulnerability

If you discover a security issue in this package, please report it privately so it can be addressed before public disclosure.

**Contact:** open a [GitHub Security Advisory](https://github.com/agentic-vibes/laravel-agent-skills/security/advisories/new) (preferred) or email `kral.petr.88@gmail.com`.

Please include a description of the issue, reproduction steps, and the potential impact. You will receive a response within a reasonable time. Public disclosure is coordinated after a fix is available.
