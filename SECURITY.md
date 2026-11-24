# Security

## Plugin trust model

`pekral/ai-olympus` is a **Composer plugin** (`"type": "composer-plugin"`). Composer requires explicit opt-in before running any plugin — including this one — to guard against supply-chain attacks from unknown packages.

### `allow-plugins` in `composer.json`

When you `composer require pekral/ai-olympus:dev-master --dev`, Composer may ask:

```
Do you trust "pekral/ai-olympus" to execute code and wish to enable it now? (yes/no)
```

If you answer `yes`, Composer writes the following entry to your project's `composer.json`:

```json
{
  "config": {
    "allow-plugins": {
      "pekral/ai-olympus": true
    }
  }
}
```

This is the **standard Composer plugin-trust mechanism** (`allow-plugins`). It is project-scoped, version-controlled alongside your `composer.json`, and must be deliberately set to `true` by you. The package never modifies `allow-plugins` on its own behalf — you remain in full control of which plugins your project trusts.

If you prefer to give a non-interactive answer (e.g. in CI), you can pass the flag explicitly:

```bash
composer require pekral/ai-olympus:dev-master --dev --no-plugins   # skip the plugin during install
composer config allow-plugins.pekral/ai-olympus true     # then grant trust manually
```

### Auto-install hook

Granting `allow-plugins: true` also enables the package's Composer plugin to react to `post-install-cmd` and `post-update-cmd` events. By default the plugin does **nothing** on these events. The auto-install hook is activated only when you add the following opt-in to your project's `composer.json`:

```json
{
  "extra": {
    "ai-olympus": {
      "auto-install": true
    }
  }
}
```

When `auto-install` is `true`, every `composer install` or `composer update` automatically runs `Installer::run(['ai-olympus', 'install', '--force'])` — the same installer that you would call manually, with `--force` and without any opt-in flags (`--allow-bundled-scripts`, `--allow-subagent-writes`, `--deny-network-bash`). **Security implication:** any package that ships a `post-install-cmd` / `post-update-cmd` hook and is trusted via `allow-plugins` can trigger code execution during a routine `composer install`. Review the `extra.ai-olympus` block in your `composer.json` before enabling `auto-install`, and treat it the same way you treat other Composer script hooks.

See also: [Installation — Automatic Installation via Composer Plugin](docs/installation.md#automatic-installation-via-composer-plugin).

## Installer security flags

All installer flags that grant a permission are **opt-in by design** — the package grants no additional permissions by default. The one restriction the installer writes by default is the runtime agent guard (see *Runtime agent guard* below), and `--no-agent-guard` removes it.

### `--allow-bundled-scripts`

**What it does.** Alongside this flag, the installer idempotently appends a narrow allow-list for this package's bundled scripts to `~/.claude/settings.json` (`permissions.allow`):

```
Bash(*skills/code-review-github/scripts/load-issue.sh *)
Bash(*skills/code-review-jira/scripts/load-issue.sh *)
```

These two patterns pre-approve the GitHub and JIRA `load-issue.sh` scripts that the `code-review-github` and `code-review-jira` skills invoke, so Claude Code stops prompting for confirmation on every run.

**What it does not do.** It grants access only to the two specific, version-controlled scripts shipped in this package. All other entries in `~/.claude/settings.json` are preserved untouched. The flag has no effect when neither `HOME` nor `USERPROFILE` is available.

**Implementation reference.** `src/InstallerClaudeSettings.php` — `applyIfRequested()` → `ensureBundledScriptPermissions()`.

See also: [Installation — CLI Switches](docs/installation.md#cli-switches).

### `--allow-subagent-writes`

**What it does.** Alongside this flag, the installer prepends two scoped permission entries to `permissions.allow` in the project's `.claude/settings.local.json`:

```
Edit(//<absolute-project-path>/**)
Write(//<absolute-project-path>/**)
```

These entries pre-allow dispatched subagents (e.g. `donatello`) to write files inside the project tree without requiring an interactive approval on each operation. A dispatched subagent runs non-interactively, so a write is denied at runtime unless the path is already in `permissions.allow`.

**Why `settings.local.json` and not `settings.json`.** The entries carry a machine-absolute path — they are personal and not portable. `settings.local.json` is git-ignored by Claude Code by default, so the absolute path never leaks into version control.

**Safety guarantees.** The flag is idempotent: it only adds missing entries and never removes or modifies existing ones. After writing, the installer reads the file back and validates that every required entry is present (`InstallerProjectSettings::validateSubagentWritePermissions()`), so a malformed file can never be produced. The package grants nothing by default — this flag is the explicit, human-owned opt-in.

**Implementation reference.** `src/InstallerProjectSettings.php` — `applySubagentWritesIfRequested()` → `ensureSubagentWritesEnabled()`.

See also: [docs/agents.md — Troubleshooting (subagent file writes blocked)](docs/agents.md#troubleshooting--subagent-file-writes-blocked).

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

## Runtime agent guard

A read-only agent role carries `Bash`, and Bash can write any file. The runtime agent guard makes the read-only limit hold while the agent runs, where the platform lets it. The installer registers it on every `install`; `--no-agent-guard` removes it again. Strict mode is optional and off by default.

### Claude Code — what is enforced

- **The hook.** `install` runs `.claude/skills/_shared/agent-guard.sh --self-test` and, when it passes, writes one `PreToolUse` handler (matcher `Bash`, `timeout: 10`, `onFailure: "block"`) into `hooks` of `.claude/settings.local.json`. Every other key and handler of the file is kept, a second `install` changes nothing, and a failed self-test writes nothing and says so in the install summary.
- **The decision is made by role, never by the text of the command.** For `agent_type` `leonardo`, `splinter`, `april`, `casey`, `shredder`, or `krang` (also as `<plugin>:<role>`), the hook rewrites the command into `/bin/bash '<abs>/agent-guard.sh' exec <role> --project '<project>' -- '<the original command>'` and keeps every other field of the tool input. `donatello`, `michelangelo`, `raphael`, and the main session without `--agent` are not touched.
- **The guard trusts no tool on the inherited PATH.** It runs unconfined, so it sets `PATH` to `/usr/bin:/bin:/usr/sbin:/sbin` before its first lookup and runs git with `core.fsmonitor` and `core.hooksPath` switched off. Only the confined command gets the inherited `PATH` back.
- **The confinement is the operating system's, and it refuses writes by default.** `exec` runs the command under `sandbox-exec` on macOS and `bwrap` on Linux / WSL2 (with its own PID namespace and its own `/proc`). Every write outside the paths below is refused for the command and for every process it starts: the project given with `--project`, the git toplevel and common directory of the command, the home directory with its shell startup files and `~/.gitconfig`, `~/.claude`, `~/.codex`, and every directory on PATH. That covers a redirect, `tee`, `sh -c`, an interpreter, `git commit` or `git config`, a process left in the background, a hard link to a project file, and a nested sandbox with a looser profile. On macOS a symbolic link cannot be created inside `.claude/run/` either.
- **What stays writable.** `.claude/run/**` and `.codex/run/**` of the project, the temporary directories (`/tmp`, `$TMPDIR`), the machine lock directory of `skills/_shared/gate-record.sh`, and the terminal and null devices. `leonardo` and `splinter` also write their own review worktree `.claude/worktrees/agent-cr-*-<role>`; its metadata (`.git`, `commondir`, `gitdir`, `config`, `config.worktree`) only once, when it is created, on macOS, and never once it exists, on Linux. `krang` may only remove entries under `.claude/worktrees/**` and the git `worktrees/` directory on macOS. `casey` also writes `storage/logs/**`. The profiles are literals of the script; it reads no file of the working tree or of `.claude/run/` to build them.
- **The git object store is not writable.** A confined role fetches a commit that is not in the store into a scratch object directory below `.claude/run/` (`GIT_OBJECT_DIRECTORY` with the project store as `GIT_ALTERNATE_OBJECT_DIRECTORIES`), the way `skills/_shared/review-carry-forward.sh decide` does. A review worktree needs its head commit in the store, so that commit is fetched by a session the guard does not confine.
- **Worktree metadata is checked after every command.** After a confined command of `leonardo`, `splinter`, or `krang` ends, `exec` restores a `commondir` that is not `../..`, removes a new `config.worktree`, moves a `.git` entry under `.claude/worktrees/` that does not name an entry of the git `worktrees/` directory aside, and exits `6`. `agent-guard.sh verify` runs the same check without changing anything and refuses with `reason=worktree-metadata`.
- **The hook never allows a command only because no deny rule matched it.** The harness checks permission rules against the rewritten command, so the hook checks the original command against the Bash `deny`, `ask`, and `allow` rules of the settings files it can read. A deny match is denied. The hook allows a command into the confinement only when an allow rule matches every segment of it, or when the session runs in `bypassPermissions`. Every other command asks: an ask match, an unreadable settings file, a rule shape the hook does not support, and a construct it cannot split (a subshell, a command substitution, a backtick, a leading `!`, a shell keyword). `--deny-network-bash` therefore keeps its effect for a confined role. **The cost:** a confined role asks the user for every command that no allow rule of the settings files covers, as the same command would without the guard.
- **The probe is the evidence.** Every confined role runs `agent-guard.sh probe` first and reports `**Protection:** active` only when a write into the project and into the git directory were both refused. `krang` runs `agent-guard.sh verify` at the start and the end of a run: it compares the installed script with the hash the installer recorded in the git directory and checks that the handler exists.

### Codex — what is not enforced

Codex gives a custom agent no sandbox of its own: the sandbox and the approval policy are inherited from the session, and a `PreToolUse` hook carries no identity of the agent. The guard therefore confines no Codex role, and the installer writes nothing for Codex. A whole Codex session started with `--sandbox read-only` is confined by Codex itself; `agent-guard.sh probe` reports that session as `active` and every `workspace-write` session as `inactive`. On Codex the read-only limit of a role inside a writing session stays **advisory**.

### Strict mode

`extra.ai-olympus.security.strict-agent-guard: true` in `composer.json` switches it on, and only the copy on the default branch counts: the guard reads it through `skills/_shared/read-manifest.sh`, so a pull request that proposes it changes nothing until it is merged. With strict mode, a command of a confined role does not run when the confinement cannot be applied, the reason is printed, and a role whose probe reports `inactive` stops with `Blocked: runtime protection inactive — <reason>`. Without it the command runs and stderr carries exactly one line `ai-olympus agent-guard: protection inactive (<reason>)`.

### What stays outside the guard

1. **Codex**, per agent, as stated above.
2. **The main session without `--agent`**, a top-level session in the `krang` role included: it reports no `agent_type`.
3. **A hook that does not run:** a workspace whose trust was not accepted in an interactive session, `--bare`, `disableAllHooks`, `allowManagedHooksOnly`, and an install as a Claude Code plugin (the package ships no `hooks/hooks.json`). Before Claude Code v2.1.295, which added `onFailure: "block"`, a timeout or a crash of the hook lets the call through too. Only `probe` detects these cases.
4. **A host without confinement:** native Windows, and Linux without `bwrap` or without unprivileged user namespaces (many containers). The guard then reports `inactive`, and strict mode stops.
5. **A nested sandbox:** macOS refuses `sandbox-exec` inside another sandbox, so inside the Claude Code sandbox the guard reports `inactive`.
6. **The network.** A confined role can still reach the network (`gh` needs it). Only `--deny-network-bash` and the deny-rule check above restrict it. The hook reads no permission rule given on the command line (`--settings`, `--allowedTools`, `--disallowedTools`); with `bypassPermissions`, or when a settings-file allow rule matches, a command-line deny rule therefore does not reach a confined command.
7. **Inter-process channels to unconfined processes:** the docker daemon, a tmux or screen server, `open` / `osascript` / launchd on macOS, a local application with a writing endpoint, and an MCP server.
8. **Linux only:** `bwrap` binds by directory, never by name. It cannot refuse a symbolic link inside `.claude/run/`, and it cannot refuse a new directory of another name inside `.claude/worktrees/` or the git `worktrees/` directory for `leonardo`, `splinter`, and `krang`. It keeps every existing worktree of another role read-only, but not one created while the command runs. `krang` can write, not only remove, under both directories; the metadata check after the command still runs.
11. **macOS only: a process left running after the command.** The metadata check runs when the command ends. A background process of a review role can still create the metadata of a new review worktree after that; `agent-guard.sh verify` detects it, and `krang` removes no worktree after such a refusal.
12. **`.claude/run/**` stays writable** for every confined role, as the role definitions require. The guard does not protect what other steps later read from it.
13. **A confined `krang` does not finish the local steps after a merge.** Started as `claude --agent krang` or as a subagent, it cannot switch, create, or delete a local branch, pull, or create a worktree. The top-level session without `--agent`, which the guard does not confine, runs those steps and the branch cleanup; a confined `krang` hands them over in its report.
9. **A writing role or the main session can change the guard.** They may edit `.claude/settings*.json` and the installed script; Claude Code asks before such a write unless the session runs with `bypassPermissions`. `agent-guard.sh verify` detects the change; it does not prevent it.
10. **A changed `composer.lock`** on a branch with `auto-install` installs another version of the script: a deliberate change of configuration, which this protection does not cover.

**How to undo it.** Run `vendor/bin/ai-olympus install --no-agent-guard`. It removes only the package's handler from `.claude/settings.local.json` and keeps every handler you wrote. Restart the session: hooks are read at session start.

**Implementation reference.** `skills/_shared/agent-guard.sh` (`hook`, `exec`, `probe`, `verify`, `--self-test`) and `src/InstallerAgentGuard.php`.

## Agent capability model & residual risk

The five shipped subagents (`agents/*.md`) each declare a `tools:` allow-list and, since issue #163, a `disallowedTools:` entry — the two layers the Claude Code harness actually enforces. Both are pinned by `tests/Installer/AgentsTest.php` so an agent cannot silently gain a tool it should not have. Full detail (per-agent Bash purpose lists, the harness research behind the numbers below) lives in `docs/agents.md` *Capability model* and `@rules/compound-engineering/orchestration.md` *Bash capability boundary*; this section states only the facts a security reviewer of this package needs without opening either.

- **Enforced today:** the `tools:` allow-list itself, the `disallowedTools:` entry every agent now carries (read-only agents lose `Write, Edit`; agents with no documentation-fetch need lose `WebSearch, WebFetch`), and on Claude Code the runtime agent guard, which confines the Bash commands of the read-only roles (see *Runtime agent guard* above).
- **Not enforced, advisory only:** every agent also carries `Bash`, which subsumes both write access and outbound network access regardless of what `tools:` / `disallowedTools:` say. There is no per-agent Bash command allow-list this package ships or can ship: the agent frontmatter `tools:` field has no syntax for a scoped command pattern (`Bash(gh:*)` is not expressible), and `permissions.allow` / `permissions.deny` patterns apply session-wide, never per agent. The one per-agent mechanism is a `PreToolUse` hook. An earlier one parsed the text of each command behind an opt-in `--enforce-agent-bash-boundary` flag and was removed again (issue #265), because it failed open in eleven separate cases and asked the user to confirm ordinary commands it could not read. The runtime agent guard does not read the command: it decides by role and lets the operating system refuse the write. Everything it does not cover, listed under *What stays outside the guard*, stays advisory.
- **What the installer writes by default:** without an opt-in flag, the installer writes one Bash restriction, the runtime agent guard hook, and no `permissions.allow` / `permissions.deny` entry. `--allow-bundled-scripts` and `--allow-subagent-writes` do not restrict Bash either; they only pre-approve two specific scripts and pre-allow `Write`/`Edit` for a dispatched subagent, respectively.
- **One partial mechanism now exists, opt-in:** `--deny-network-bash` (see *Installer security flags* above) writes `permissions.deny` entries for ten literal outbound-network commands, moving exactly those command strings from advisory instruction to harness-enforced refusal. It narrows the gap; it does not close it. The restriction is **session-wide, not per agent** (it restricts the human's own interactive Bash in this project identically), and it matches command strings rather than process trees — child processes of allowed commands, unstripped wrappers, absolute paths, `/dev/tcp`, and unlisted tools all remain open, as enumerated under `--deny-network-bash`. Everything not on that list stays exactly as advisory as before.
- **The runtime agent guard is the per-agent mechanism, on Claude Code only.** It covers child processes because the operating system confines them. The Claude Code sandbox, which confines a whole session, is not configured by this package; on Codex a session-wide `--sandbox read-only` is the only tier that would cover child processes of a confined role.
- **If you ever installed the removed hook, `install` now removes its entry for you.** The flag never had an inverse, so a project that opted in still carries the handler in `.claude/settings.local.json`. It points at a subcommand the binary no longer has: `ai-olympus bash-guard` falls through to the installer, prints `Unknown command: bash-guard`, and exits `1`. Claude Code treats a non-zero exit other than `2` as a non-blocking hook error, so the Bash call still runs — but the error is printed on **every** Bash call until the entry is gone. Every `install` run now deletes it (issue #6): each hook handler whose `command` ends in one of this package's own binaries followed by the `bash-guard` subcommand, and only those. Both binary names count, and the closing quote the writer put around a path holding a space is tolerated — the flag itself wrote `agent-skills bash-guard` (or `…/agent-skills' bash-guard`), because `bash-guard` was deleted five days before the rebrand renamed the binary to `ai-olympus`, and a hand-edited file may carry either name. A handler you added yourself is left in place, and a matcher group, a hook event, and the `hooks` key are pruned only once that deletion empties them. `install` reads `.claude/settings.local.json` on every run to make this check, whether or not the file holds such a handler — so a file that is not valid JSON now ends the install with `Cannot parse Claude settings file <path>: Syntax error.` and exit `1`, where the same install used to read that file only behind `--allow-subagent-writes` / `--deny-network-bash` and succeeded without it. Nothing is written unless a handler was actually removed. The removal is reported in the install summary, which also tells you to restart the session — hooks are read once, at session start, so the error keeps printing in the session that was already running. To remove it by hand instead, delete the same handler from the `Bash` group under `hooks.PreToolUse`.

## Files this package writes

Default installation does not read or write global `~/.claude/settings.json`. The `--disable-co-author-attribution` flag opts into setting `includeCoAuthoredBy: false` when absent; it preserves an existing value, including `true`, and all unrelated settings. The preference applies across Claude Code projects. To undo an earlier installation's preference, remove the key manually; upgrades do not infer whether an existing value was chosen by the user. Composer auto-install does not enable this flag.

| Path | Created by | Condition |
|------|-----------|-----------|
| `~/.claude/settings.json` — sets `includeCoAuthoredBy: false` | `--disable-co-author-attribution` (opt-in) | `HOME`/`USERPROFILE` set; key absent — never overwrites an existing value |
| `~/.claude/settings.json` — adds `permissions.allow` bundled-script entries | `--allow-bundled-scripts` | `HOME`/`USERPROFILE` set |
| `.claude/settings.local.json` — prepends `permissions.allow` scoped `Edit`/`Write` entries | `--allow-subagent-writes` | always |
| `.claude/settings.local.json` — appends `permissions.deny` network-command entries | `--deny-network-bash` | always |
| `.claude/settings.local.json` — adds one `PreToolUse` handler for `agent-guard.sh hook` | `install` (default; `--no-agent-guard` removes only that handler) | only after the self-test of the installed script passed; rewritten only when the handler is missing or outdated |
| `.claude/settings.local.json` — deletes hook handlers pointing at the removed `bash-guard` | `install` (unconditional) | read on every run (invalid JSON fails the install); rewritten only when a matching handler is present |
| `.claude/rules/` | `install` | always |
| `.claude/skills/` (and `~/.claude/skills/` with `--global`) | `install` | always |
| `.claude/agents/` | `install` | always |
| `.codex/rules/`, `.codex/agent-instructions/`, `.codex/agents/` | `install` | always |
| `.agents/skills/` (and `~/.agents/skills/` with `--global`) | `install` | always |
| `AGENTS.md` | `install` | only when the project has none; never overwritten |
| `.claude/run/code-review-memory.sqlite` (`.codex/run/` under Codex) | `ai-olympus memory migrate` and `memory add`, never `install` | git ignores the path and does not track it; otherwise the command exits `2` and writes nothing. `install --prune` never deletes it. |

The installer never writes outside the project directory and the user's home directory, and it never modifies `composer.json` or any project source file.

## Skills are dependencies

A skill is executable instruction: an agent follows its `SKILL.md` and runs its scripts with the permissions of your session, including `Bash`. Treat skills the way you treat Composer packages.

- **Install them only from this package** — `composer require pekral/ai-olympus` from Packagist, or `/plugin marketplace add pekral/ai-olympus` in Claude Code. A `SKILL.md` copied from anywhere else is untrusted code, even when it looks identical to one shipped here.
- **Pin the version and commit `composer.lock`**, so every machine installs the same release you reviewed (the [Installation](README.md#installation) command pins a release).
- **Read the changes before you upgrade.** `install --force` replaces the installed rules, skills, and agents; check [`CHANGELOG.md`](CHANGELOG.md) for the release, and when your project commits `.claude/` and `.agents/`, review their diff before committing it.
- **Report a modified copy** that claims to come from this package through the private process below.

## Reporting a vulnerability

If you discover a security issue in this package, please report it privately so it can be addressed before public disclosure.

**Contact:** open a [GitHub Security Advisory](https://github.com/pekral/ai-olympus/security/advisories/new) (preferred) or email `kral.petr.88@gmail.com`.

Please include a description of the issue, reproduction steps, and the potential impact. You will receive a response within a reasonable time. Public disclosure is coordinated after a fix is available.
