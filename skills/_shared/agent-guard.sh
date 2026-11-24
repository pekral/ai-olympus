#!/bin/bash
# agent-guard.sh — confine the shell commands of a read-only agent role with an operating-system sandbox.
#
# Why this exists
#   A read-only role (`leonardo`, `splinter`, `april`, `casey`, `shredder`, `krang`) carries `Bash`, and Bash can
#   write any file: a redirect, `sh -c`, an interpreter, `git commit`, a process left in the background. An
#   instruction that says "do not write" stops none of them. This script makes the limit hold while the agent runs:
#   a Claude Code `PreToolUse` hook rewrites the Bash command of a confined role so it runs under `sandbox-exec`
#   (macOS) or `bwrap` (Linux). The kernel then refuses every write outside a short list of named paths, from the
#   command and from every process it starts. The decision is made from the role the harness reports (`agent_type`),
#   never from the text of the command (rules/compound-engineering/orchestration.md *Bash capability boundary*).
#
# Usage
#   agent-guard.sh hook                     read the PreToolUse JSON on stdin, print the hook decision
#   agent-guard.sh exec <role> -- <command> run <command> with `/bin/bash -c` in the confinement of <role>
#   agent-guard.sh probe                    prove whether the current process can write into the project
#   agent-guard.sh verify                   check the installed copy and the hook handler against the install record
#   agent-guard.sh --self-test
#
# Tools
#   The script runs unconfined, so it never resolves a tool through the PATH it inherits: a directory on that PATH can
#   be writable by the user. It sets PATH to /usr/bin:/bin:/usr/sbin:/sbin before its first lookup, runs git with
#   `core.fsmonitor` and `core.hooksPath` switched off, and gives the inherited PATH back only to the confined command.
#
# Roles and profiles
#   The confined roles and their write allowances are literals of this script. It reads no file of the working tree
#   and nothing under `.claude/run/` to build them, so no branch and no agent can widen them. Writes are refused by
#   default. A confined role may write only:
#     every confined role   `.claude/run/**` and `.codex/run/**` of the project (no symbolic link is created there on
#                           macOS), the temporary directories (`/tmp`, `$TMPDIR`), the machine lock directory, and the
#                           terminal and null devices. Never the project, its git directories, the home directory, a
#                           directory on PATH, a shell startup file, or the git configuration.
#     leonardo, splinter    their own review worktree `.claude/worktrees/agent-cr-*-<role>` and its git directory
#     krang                 `.claude/worktrees/**` and `<git common dir>/worktrees/**` (it removes unit worktrees)
#     casey                 `storage/logs/**` (Laravel writes its log there while tinker runs)
#
# Strict mode
#   Only `extra.ai-olympus.security.strict-agent-guard: true` on the default branch switches it on, read through
#   read-manifest.sh beside this script. The manifest is read only when the confinement cannot be applied (or the
#   hook cannot decide), never on the path where it can. Strict: the command does not run and the reason is printed
#   on stderr (exec exit 5, hook exit 2). Not strict: the command runs and stderr carries exactly one line
#   `ai-olympus agent-guard: protection inactive (<reason>)`.
#
# Hook decision (Claude Code PreToolUse)
#   A role outside the confined set, an absent or empty `agent_type` (the main session), and a tool other than Bash:
#   exit 0, no output. A confined role: the original command is checked against the Bash `deny` and `ask` rules of
#   the settings files the hook can read, because the harness checks those rules against the rewritten command, not
#   the original one. A match with a deny rule is `deny`. A match with an ask rule, or a Bash rule of a shape this
#   check does not support, is `ask`. Otherwise it is `allow`. `ask` and `allow` carry `updatedInput` with every field
#   of the tool input kept and `command` replaced by `/bin/bash '<abs>/agent-guard.sh' exec <role> -- '<original command>'`.
#   Supported rule shapes are `Bash`, `Bash(x)`, `Bash(x *)`, and `Bash(x:*)`; a command is split at `&&`, `||`,
#   `;`, `|`, `|&`, `&`, and a newline, and leading `NAME=value` words and the wrappers `timeout time nice nohup
#   stdbuf command builtin noglob` are removed before a segment is matched.
#
# Probe
#   Creates `<project root>/.ai-olympus-guard-probe-<random>` and `<git common dir>/ai-olympus-guard-probe-<random>`
#   without overwriting anything, and removes what it created on every exit path. Prints `protection=active` only when
#   both writes were refused; otherwise `protection=inactive reason=<reason> strict=<true|false>`.
#
# Exit codes
#   exec    the exit code of the command; 5 strict mode refused to run it without confinement; 64 usage error
#   hook    0 decision printed (or none needed); 2 strict mode and the hook could not decide
#   probe   0 the result line was printed; 64 usage error
#   verify  0 verified; 3 refused (the line names the reason)
#   other   1 usage error; 2 missing required tool (jq)
set -uo pipefail

ORIGINAL_PATH="${PATH:-/usr/bin:/bin}"
PATH=/usr/bin:/bin:/usr/sbin:/sbin
export PATH

PROG="${0##*/}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
SCRIPT_PATH="$SCRIPT_DIR/$PROG"

CONFINED_ROLES='leonardo splinter april casey shredder krang'
GUARD_PREFIX='ai-olympus agent-guard:'
NONE='/nonexistent-ai-olympus-guard'

usage() {
  cat >&2 <<'EOF'
Usage: agent-guard.sh hook
       agent-guard.sh exec <role> -- <command>
       agent-guard.sh probe
       agent-guard.sh verify
       agent-guard.sh --self-test
EOF
}

# git without the configuration that runs a program: no fsmonitor, no hooks.
git_safe() {
  git -c core.fsmonitor=false -c core.hooksPath=/dev/null "$@"
}

# A role the harness reports as `<plugin>:<role>` is the same role.
normalize_role() {
  printf '%s' "${1##*:}"
}

is_confined_role() {
  local role="$1" candidate
  for candidate in $CONFINED_ROLES; do
    [[ "$candidate" == "$role" ]] && return 0
  done
  return 1
}

physical_dir() {
  (cd "$1" 2>/dev/null && pwd -P)
}

# Sets TOP (git toplevel of the current directory), COMMON (git common directory), and ROOT (the main project root:
# the parent of a common directory named `.git`, otherwise TOP). Outside a git checkout every value is the current
# directory and COMMON is empty.
resolve_project() {
  local common
  TOP="$(git_safe rev-parse --show-toplevel 2>/dev/null)" || TOP=""
  if [[ -z "$TOP" ]]; then
    TOP="$(pwd -P)"
    COMMON=""
    ROOT="$TOP"
    return 1
  fi
  TOP="$(physical_dir "$TOP")"
  common="$(git_safe rev-parse --git-common-dir 2>/dev/null)" || common=""
  COMMON="$(physical_dir "$common")"
  if [[ -n "$COMMON" && "${COMMON##*/}" == ".git" ]]; then
    ROOT="${COMMON%/.git}"
  else
    ROOT="$TOP"
  fi
  return 0
}

# Prints true only when the default-branch manifest sets security.strict-agent-guard to the boolean true.
strict_mode() {
  local manifest=""
  if [[ -r "$SCRIPT_DIR/read-manifest.sh" ]]; then
    manifest="$(cd "$ROOT" 2>/dev/null && /bin/bash "$SCRIPT_DIR/read-manifest.sh" 2>/dev/null)" || true
  fi
  if [[ -n "$manifest" ]] && printf '%s' "$manifest" | jq -e '.security["strict-agent-guard"] == true' >/dev/null 2>&1; then
    printf 'true'
  else
    printf 'false'
  fi
}

# --- the hook ---------------------------------------------------------------------------------------------------

settings_files() {
  local project="$1"
  printf '%s\n' "$project/.claude/settings.json" "$project/.claude/settings.local.json"
  [[ -n "${HOME:-}" ]] && printf '%s\n' "$HOME/.claude/settings.json"
  printf '%s\n' '/Library/Application Support/ClaudeCode/managed-settings.json' '/etc/claude-code/managed-settings.json'
}

# Prints a JSON object {"deny": [...], "ask": [...], "unreadable": bool} of the Bash rules of every settings file.
collect_rules() {
  local project="$1" file unreadable=false
  local -a docs=()
  while IFS= read -r file; do
    [[ -f "$file" ]] || continue
    if ! jq -e 'type == "object"' "$file" >/dev/null 2>&1; then
      unreadable=true
      continue
    fi
    docs+=("$file")
  done < <(settings_files "$project")
  if [[ "${#docs[@]}" -eq 0 ]]; then
    jq -cn --argjson u "$unreadable" '{deny: [], ask: [], unreadable: $u}'
    return 0
  fi
  jq -cs --argjson u "$unreadable" '
    def bash_rules($key): [ .[] | (.permissions[$key]? // []) | if type == "array" then .[] else empty end
      | select(type == "string") | select(. == "Bash" or startswith("Bash(")) ];
    {deny: bash_rules("deny"), ask: bash_rules("ask"), unreadable: $u}' "${docs[@]}"
}

# The jq program that decides deny / ask / allow for one command against the collected rules.
# shellcheck disable=SC2016
DECIDE_JQ='
def norm: [splits("[ \t]+")] | map(select(length > 0));
def drop_options: until(length == 0 or ((.[0] | startswith("-")) | not); if (.[0] == "-k" or .[0] == "-s" or .[0] == "-n") then .[2:] else .[1:] end);
def strip:
  if length == 0 then .
  elif (.[0] | test("^[A-Za-z_][A-Za-z0-9_]*=")) then .[1:] | strip
  elif .[0] == "timeout" then .[1:] | drop_options | .[1:] | strip
  elif (.[0] == "nice" or .[0] == "stdbuf") then .[1:] | drop_options | strip
  elif (.[0] as $t | ["time", "nohup", "command", "builtin", "noglob"] | index($t)) then .[1:] | drop_options | strip
  else . end;
def segments: [splits("\\|\\||&&|\\|&|[;|&\n]")] | map(norm | strip | join(" ")) | map(select(length > 0));
def parse_rule:
  if . == "Bash" or . == "Bash(*)" then {kind: "all"}
  elif (test("^Bash\\(.*\\)$") | not) then {kind: "unsupported"}
  else (.[5:-1]) as $x
    | if ($x | endswith(":*")) or ($x | endswith(" *")) then ($x[:-2] | norm | join(" ")) as $p
        | if ($p | contains("*")) or ($p == "") then {kind: "unsupported"} else {kind: "prefix", value: $p} end
      elif ($x | contains("*")) then {kind: "unsupported"}
      else {kind: "exact", value: ($x | norm | join(" "))} end
  end;
def matches($seg): if .kind == "all" then true
  elif .kind == "exact" then $seg == .value
  elif .kind == "prefix" then .value as $v | ($seg == $v or ($seg | startswith($v + " ")))
  else false end;
(.command | segments) as $segs
| (.rules.deny | map(parse_rule)) as $deny
| (.rules.ask | map(parse_rule)) as $ask
| if any($deny[]; . as $r | any($segs[]; . as $s | $r | matches($s))) then "deny"
  elif .rules.unreadable or any(($deny + $ask)[]; .kind == "unsupported") then "ask"
  elif any($ask[]; . as $r | any($segs[]; . as $s | $r | matches($s))) then "ask"
  else "allow" end
'

# The decision for one command: deny, ask, or allow.
decide_command() {
  local command="$1" project="$2" rules
  rules="$(collect_rules "$project")" || rules='{"deny":[],"ask":[],"unreadable":true}'
  jq -rn --arg command "$command" --argjson rules "$rules" "{command: \$command, rules: \$rules} | $DECIDE_JQ"
}

hook_fail() {
  local reason="$1"
  if [[ "$(strict_mode)" == "true" ]]; then
    echo "$GUARD_PREFIX strict mode stopped this command: $reason" >&2
    return 2
  fi
  echo "$GUARD_PREFIX protection inactive ($reason)" >&2
  return 0
}

run_hook() {
  local input role command project decision wrapped
  input="$(cat)"
  resolve_project >/dev/null 2>&1 || true

  if ! printf '%s' "$input" | jq -e 'type == "object"' >/dev/null 2>&1; then
    hook_fail "the hook input is not a JSON object"
    return $?
  fi

  [[ "$(printf '%s' "$input" | jq -r '.tool_name // ""')" == "Bash" ]] || return 0
  role="$(normalize_role "$(printf '%s' "$input" | jq -r '.agent_type // "" | if type == "string" then . else "" end')")"
  is_confined_role "$role" || return 0

  if ! printf '%s' "$input" | jq -e '.tool_input.command | type == "string"' >/dev/null 2>&1; then
    jq -cn --arg reason "$GUARD_PREFIX the Bash input of $role carries no command" \
      '{hookSpecificOutput: {hookEventName: "PreToolUse", permissionDecision: "deny", permissionDecisionReason: $reason}}'
    return 0
  fi

  command="$(printf '%s' "$input" | jq -r '.tool_input.command')"
  project="${CLAUDE_PROJECT_DIR:-}"
  [[ -n "$project" ]] || project="$(printf '%s' "$input" | jq -r '.cwd // "" | if type == "string" then . else "" end')"
  [[ -n "$project" ]] || project="$ROOT"
  decision="$(decide_command "$command" "$project")" || decision="ask"

  if [[ "$decision" == "deny" ]]; then
    jq -cn --arg reason "$GUARD_PREFIX a Bash deny rule of the session matches this command of $role" \
      '{hookSpecificOutput: {hookEventName: "PreToolUse", permissionDecision: "deny", permissionDecisionReason: $reason}}'
    return 0
  fi
  [[ "$decision" == "allow" ]] || decision="ask"

  wrapped="/bin/bash $(shell_quote "$SCRIPT_PATH") exec $role -- $(shell_quote "$command")"
  printf '%s' "$input" | jq -c --arg wrapped "$wrapped" --arg decision "$decision" \
    --arg reason "$GUARD_PREFIX runs this command in the read-only confinement of $role" \
    '{hookSpecificOutput: {hookEventName: "PreToolUse", permissionDecision: $decision, permissionDecisionReason: $reason,
      updatedInput: (.tool_input + {command: $wrapped})}}'
}

# POSIX single-quote escaping: the value becomes one shell word and no part of it is expanded.
shell_quote() {
  local value="$1" quote="'" escaped="'\\''"
  printf "'%s'" "${value//$quote/$escaped}"
}

# --- the confinement ----------------------------------------------------------------------------------------------

# Escapes a path for a regular expression of a sandbox profile.
regex_escape() {
  # shellcheck disable=SC2016
  printf '%s' "$1" | sed 's/[][\.*^$(){}+?|\\/]/\\&/g'
}

bwrap_base_args() {
  printf '%s\n' --die-with-parent --new-session
  if bwrap --help 2>&1 | grep -q -- '--disable-userns'; then
    printf '%s\n' --unshare-user --disable-userns
  fi
  printf '%s\n' --ro-bind / / --dev /dev
}

# Prints the reason the confinement cannot be applied on this host, or nothing when it can.
confinement_unavailable_reason() {
  local -a base=()
  case "$(uname -s 2>/dev/null)" in
  Darwin)
    [[ -x /usr/bin/sandbox-exec ]] || { printf 'sandbox-exec is not available'; return 0; }
    # A profile without a rule is accepted even inside a sandbox, so the trial carries one deny rule like the real one.
    /usr/bin/sandbox-exec -p "(version 1)(allow default)(deny file-write* (literal \"$NONE\"))" \
      /usr/bin/true >/dev/null 2>&1 \
      || printf 'sandbox-exec cannot apply a profile here (already inside a sandbox)'
    ;;
  Linux)
    command -v bwrap >/dev/null 2>&1 || { printf 'bwrap is not installed'; return 0; }
    mapfile -t base < <(bwrap_base_args)
    bwrap "${base[@]}" -- /bin/true >/dev/null 2>&1 \
      || printf 'bwrap cannot create a sandbox here (no unprivileged user namespaces, or already inside one)'
    ;;
  *) printf 'no supported sandbox on this operating system' ;;
  esac
}

# Sets TMP_SYS, TMP_USER, HOME_DIR, and LOCK_DIR: the temporary directories, the home directory, and the machine lock
# directory of skills/_shared/gate-record.sh (gate_machine_dir).
resolve_writable() {
  TMP_SYS="$(physical_dir /tmp)" || TMP_SYS=""
  TMP_USER=""
  [[ -n "${TMPDIR:-}" && "$TMPDIR" == /* ]] && TMP_USER="$(physical_dir "$TMPDIR")"
  HOME_DIR=""
  [[ -n "${HOME:-}" && "$HOME" == /* && "$HOME" != "/" ]] && HOME_DIR="$(physical_dir "$HOME")"
  LOCK_DIR=""
  if [[ -n "${AI_OLYMPUS_LOCK_DIR:-}" && "$AI_OLYMPUS_LOCK_DIR" == /* ]]; then
    LOCK_DIR="$AI_OLYMPUS_LOCK_DIR"
  elif [[ -n "${XDG_CACHE_HOME:-}" && "$XDG_CACHE_HOME" == /* ]]; then
    LOCK_DIR="$XDG_CACHE_HOME/ai-olympus"
  elif [[ -n "$HOME_DIR" ]]; then
    LOCK_DIR="$HOME_DIR/.cache/ai-olympus"
  fi
  [[ -n "$LOCK_DIR" && -d "$LOCK_DIR" ]] && LOCK_DIR="$(physical_dir "$LOCK_DIR")"
  return 0
}

role_allows_worktrees() {
  [[ "$1" == "leonardo" || "$1" == "splinter" || "$1" == "krang" ]]
}

macos_profile() {
  local role="$1" profile
  profile='(version 1)(allow default)(deny file-write*)'
  profile+='(allow file-write* (literal "/dev/null") (literal "/dev/zero") (literal "/dev/tty") (literal "/dev/dtracehelper")'
  profile+=' (regex "^/dev/ttys[0-9]+$") (regex "^/dev/fd/[0-9]+$"))'
  profile+='(allow file-write* (subpath (param "TMP_SYS")) (subpath (param "TMP_USER")))'
  profile+='(deny file-write* (regex "/xcrun_db"))'
  profile+='(deny file-write* (subpath (param "HOME_DIR")))'
  if [[ -n "$TMP_USER" && -n "$HOME_DIR" && "$TMP_USER" == "$HOME_DIR"/* ]]; then
    profile+='(allow file-write* (subpath (param "TMP_USER")))(deny file-write* (regex "/xcrun_db"))'
  fi
  profile+='(allow file-write* (subpath (param "LOCK_DIR")))'
  profile+='(deny file-write* (subpath (param "ROOT")) (subpath (param "TOP")) (subpath (param "COMMON")))'
  profile+='(deny file-write* (subpath (param "HOME_CLAUDE")) (subpath (param "HOME_CODEX")))'
  profile+='(allow file-write* (subpath (param "RUN_CLAUDE")) (subpath (param "RUN_CODEX")))'
  case "$role" in
  leonardo | splinter)
    profile+='(allow file-write* (literal (param "WT_DIR")) (regex (param "WT_RE")))'
    profile+='(allow file-write* (literal (param "GIT_WT_DIR")) (regex (param "GIT_WT_RE")))'
    ;;
  krang)
    profile+='(allow file-write* (subpath (param "WT_DIR")) (subpath (param "GIT_WT_DIR")))'
    ;;
  casey) profile+='(allow file-write* (subpath (param "LOGS")))' ;;
  esac
  profile+='(deny file-write-create (require-all (subpath (param "RUN_CLAUDE")) (vnode-type SYMLINK)))'
  profile+='(deny file-write-create (require-all (subpath (param "RUN_CODEX")) (vnode-type SYMLINK)))'
  printf '%s' "$profile"
}

run_macos() {
  local role="$1" command="$2" root_re common_re
  root_re="$(regex_escape "$ROOT")"
  common_re="$(regex_escape "${COMMON:-$NONE}")"
  /usr/bin/sandbox-exec \
    -D ROOT="$ROOT" -D TOP="$TOP" -D COMMON="${COMMON:-$NONE}" \
    -D TMP_SYS="${TMP_SYS:-$NONE}" -D TMP_USER="${TMP_USER:-$NONE}" \
    -D HOME_DIR="${HOME_DIR:-$NONE}" -D LOCK_DIR="${LOCK_DIR:-$NONE}" \
    -D HOME_CLAUDE="${HOME_DIR:-$NONE}/.claude" -D HOME_CODEX="${HOME_DIR:-$NONE}/.codex" \
    -D RUN_CLAUDE="$ROOT/.claude/run" -D RUN_CODEX="$ROOT/.codex/run" \
    -D WT_DIR="$ROOT/.claude/worktrees" -D GIT_WT_DIR="${COMMON:-$NONE}/worktrees" \
    -D WT_RE="^$root_re/\\.claude/worktrees/agent-cr-[^/]+-${role}[0-9]*(/|\$)" \
    -D GIT_WT_RE="^$common_re/worktrees/agent-cr-[^/]+-${role}[0-9]*(/|\$)" \
    -D LOGS="$ROOT/storage/logs" \
    -p "$(macos_profile "$role")" /usr/bin/env PATH="$ORIGINAL_PATH" /bin/bash -c "$command"
}

run_linux() {
  local role="$1" command="$2" entry name dir
  local -a args=()
  mapfile -t args < <(bwrap_base_args)
  [[ -n "$TMP_SYS" && -d "$TMP_SYS" ]] && args+=(--bind "$TMP_SYS" "$TMP_SYS")
  [[ -n "$TMP_USER" && -d "$TMP_USER" ]] && args+=(--bind "$TMP_USER" "$TMP_USER")
  [[ -n "$HOME_DIR" && -d "$HOME_DIR" ]] && args+=(--ro-bind "$HOME_DIR" "$HOME_DIR")
  if [[ -n "$TMP_USER" && -n "$HOME_DIR" && "$TMP_USER" == "$HOME_DIR"/* && -d "$TMP_USER" ]]; then
    args+=(--bind "$TMP_USER" "$TMP_USER")
  fi
  [[ -n "$LOCK_DIR" && -d "$LOCK_DIR" ]] && args+=(--bind "$LOCK_DIR" "$LOCK_DIR")
  for dir in "$ROOT" "$TOP" "$COMMON"; do
    [[ -n "$dir" && -d "$dir" ]] && args+=(--ro-bind "$dir" "$dir")
  done
  mkdir -p "$ROOT/.claude/run" 2>/dev/null || true
  args+=(--bind "$ROOT/.claude/run" "$ROOT/.claude/run")
  [[ -d "$ROOT/.codex/run" ]] && args+=(--bind "$ROOT/.codex/run" "$ROOT/.codex/run")
  if role_allows_worktrees "$role"; then
    mkdir -p "$ROOT/.claude/worktrees" 2>/dev/null || true
    args+=(--bind "$ROOT/.claude/worktrees" "$ROOT/.claude/worktrees")
    if [[ -n "$COMMON" ]]; then
      mkdir -p "$COMMON/worktrees" 2>/dev/null || true
      args+=(--bind "$COMMON/worktrees" "$COMMON/worktrees")
    fi
    if [[ "$role" != "krang" ]]; then
      for entry in "$ROOT/.claude/worktrees"/* ${COMMON:+"$COMMON/worktrees"/*}; do
        [[ -e "$entry" ]] || continue
        name="${entry##*/}"
        [[ "$name" =~ ^agent-cr-.+-${role}[0-9]*$ ]] || args+=(--ro-bind "$entry" "$entry")
      done
    fi
  fi
  [[ "$role" == "casey" && -d "$ROOT/storage/logs" ]] && args+=(--bind "$ROOT/storage/logs" "$ROOT/storage/logs")
  bwrap "${args[@]}" -- /usr/bin/env PATH="$ORIGINAL_PATH" /bin/bash -c "$command"
}

run_exec() {
  [[ $# -ge 3 && "$2" == "--" ]] || { usage; return 64; }
  local role="$1" command="$3" reason status
  role="$(normalize_role "$role")"
  is_confined_role "$role" || { echo "$PROG: not a confined role: $role" >&2; return 64; }
  resolve_project || true

  reason="$(confinement_unavailable_reason)"
  if [[ -n "$reason" ]]; then
    if [[ "$(strict_mode)" == "true" ]]; then
      echo "$GUARD_PREFIX strict mode refused to run the command of $role without confinement: $reason" >&2
      return 5
    fi
    echo "$GUARD_PREFIX protection inactive ($reason)" >&2
    /usr/bin/env PATH="$ORIGINAL_PATH" /bin/bash -c "$command"
    return $?
  fi

  resolve_writable
  if [[ "$(uname -s)" == "Darwin" ]]; then
    run_macos "$role" "$command"
  else
    run_linux "$role" "$command"
  fi
  status=$?
  if [[ "$status" -ne 0 ]]; then
    echo "$GUARD_PREFIX $role ran this command in its read-only confinement (exit $status); a refused write is a Blocked stop, never a retry another way" >&2
  fi
  return "$status"
}

# --- probe and verify ---------------------------------------------------------------------------------------------

PROBE_CREATED=()

probe_cleanup() {
  local file
  for file in "${PROBE_CREATED[@]+"${PROBE_CREATED[@]}"}"; do
    [[ -f "$file" && ! -L "$file" ]] && unlink "$file" 2>/dev/null
  done
  PROBE_CREATED=()
}

# Creates the file without overwriting anything. Succeeds only when this call created it.
probe_write() {
  local file="$1"
  if (set -C; : >"$file") 2>/dev/null; then
    PROBE_CREATED+=("$file")
    return 0
  fi
  return 1
}

run_probe() {
  local suffix tree_written=false git_written=false reason
  trap probe_cleanup EXIT
  trap 'probe_cleanup; exit 130' INT
  trap 'probe_cleanup; exit 143' TERM
  if ! resolve_project; then
    printf 'protection=inactive reason=not-a-git-checkout strict=%s\n' "$(strict_mode)"
    return 0
  fi
  suffix="$RANDOM$RANDOM$$"
  probe_write "$ROOT/.ai-olympus-guard-probe-$suffix" && tree_written=true
  probe_write "$COMMON/ai-olympus-guard-probe-$suffix" && git_written=true
  probe_cleanup

  if [[ "$tree_written" == "false" && "$git_written" == "false" ]]; then
    printf 'protection=active\n'
    return 0
  fi
  if [[ "$tree_written" == "true" ]]; then
    reason='working-tree-writable'
  else
    reason='git-dir-writable'
  fi
  printf 'protection=inactive reason=%s strict=%s\n' "$reason" "$(strict_mode)"
  return 0
}

sha256_of() {
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$1" | cut -d' ' -f1
  else
    shasum -a 256 "$1" | cut -d' ' -f1
  fi
}

run_verify() {
  local installed=".claude/skills/_shared/agent-guard.sh" reference recorded actual settings
  resolve_project || { echo 'agent-guard=refused reason=not-a-git-checkout'; return 3; }
  reference="$COMMON/ai-olympus/installed-copies.sha256"
  [[ -f "$ROOT/$installed" && ! -L "$ROOT/$installed" ]] || { echo 'agent-guard=refused reason=no-installed-copy'; return 3; }
  [[ -f "$reference" ]] || { echo 'agent-guard=refused reason=no-install-record'; return 3; }
  recorded="$(awk -v path="$installed" '$2 == path { print $1 }' "$reference")"
  actual="$(sha256_of "$ROOT/$installed")"
  [[ -n "$recorded" && "$recorded" == "$actual" ]] || { echo 'agent-guard=refused reason=hash-mismatch'; return 3; }
  settings="$ROOT/.claude/settings.local.json"
  if ! jq -e '[.hooks.PreToolUse[]?.hooks[]? | .command? // "" | select(type == "string")
      | select(test("/\\.claude/skills/_shared/agent-guard\\.sh hook$"))] | length == 1' "$settings" >/dev/null 2>&1; then
    echo 'agent-guard=refused reason=no-hook-handler'
    return 3
  fi
  echo 'agent-guard=verified'
  return 0
}

# --- self-test ----------------------------------------------------------------------------------------------------

self_test() {
  local failures=0 tmp repo out
  tmp="$(mktemp -d)"
  tmp="$(physical_dir "$tmp")"
  # shellcheck disable=SC2064
  trap "rm -rf '$tmp'" EXIT

  check() {
    local label="$1"
    shift
    if "$@"; then
      printf 'ok    %s\n' "$label"
    else
      printf 'FAIL  %s\n' "$label" >&2
      failures=$((failures + 1))
    fi
  }

  hook_input() {
    jq -cn --arg agent "$1" --arg command "$2" \
      '{tool_name: "Bash", agent_type: $agent, tool_input: {command: $command, description: "d", timeout: 5000}}'
  }

  out="$(hook_input leonardo "echo it's" | (CLAUDE_PROJECT_DIR="$tmp" HOME="$tmp" run_hook))"
  check 'a confined role gets the command wrapped in exec' \
    test "$(printf '%s' "$out" | jq -r '.hookSpecificOutput.updatedInput.command')" \
    == "/bin/bash '$SCRIPT_PATH' exec leonardo -- 'echo it'\\''s'"
  check 'the wrapped input keeps every other field' \
    test "$(printf '%s' "$out" | jq -c '.hookSpecificOutput.updatedInput | del(.command)')" == '{"description":"d","timeout":5000}'
  check 'a plugin-qualified role is confined too' \
    test -n "$(hook_input 'pkg:splinter' 'ls' | (CLAUDE_PROJECT_DIR="$tmp" HOME="$tmp" run_hook))"
  check 'a writing role is not wrapped' \
    test -z "$(hook_input donatello 'ls' | (CLAUDE_PROJECT_DIR="$tmp" HOME="$tmp" run_hook))"
  check 'the main session is not wrapped' \
    test -z "$(hook_input '' 'ls' | (CLAUDE_PROJECT_DIR="$tmp" HOME="$tmp" run_hook))"

  check 'the quoted command is the original command' \
    test "$(sh -c "printf '%s' $(shell_quote "a'b \$HOME \"c\"")")" == "a'b \$HOME \"c\""

  mkdir -p "$tmp/.claude"
  printf '{"permissions":{"deny":["Bash(curl:*)"],"ask":["Bash(gh pr merge *)"]}}' >"$tmp/.claude/settings.json"
  check 'a deny rule denies the original command' \
    test "$(decide_command 'cd x && timeout 5 curl https://example.test' "$tmp")" == deny
  check 'an ask rule never allows' test "$(decide_command 'gh pr merge 1' "$tmp")" == ask
  check 'a command no rule matches is allowed' test "$(decide_command 'curlish --version' "$tmp")" == allow
  printf '{"permissions":{"deny":["Bash(git * --force)"]}}' >"$tmp/.claude/settings.local.json"
  check 'an unsupported rule shape asks' test "$(decide_command 'ls' "$tmp")" == ask

  if [[ -z "$(confinement_unavailable_reason)" ]]; then
    repo="$tmp/repo"
    mkdir -p "$repo"
    git_safe -C "$repo" init -q
    mkdir -p "$repo/.claude/run"
    (cd "$repo" && HOME="$tmp/home" "$SCRIPT_PATH" exec leonardo -- "echo x > '$repo/written'" >/dev/null 2>&1)
    check 'the confinement refuses a write into the project' test ! -e "$repo/written"
    (cd "$repo" && HOME="$tmp/home" "$SCRIPT_PATH" exec leonardo -- "echo x > '$repo/.claude/run/brief'" >/dev/null 2>&1)
    check 'the confinement allows a write into .claude/run' test -e "$repo/.claude/run/brief"
    mkdir -p "$tmp/home"
    (cd "$repo" && HOME="$tmp/home" "$SCRIPT_PATH" exec leonardo -- "echo x > '$tmp/home/.gitconfig'" >/dev/null 2>&1)
    check 'the confinement refuses a write into the home directory' test ! -e "$tmp/home/.gitconfig"
  else
    printf 'ok    confinement checks skipped: %s (the fallback is covered by the Pest suite)\n' "$(confinement_unavailable_reason)"
  fi

  if [[ "$failures" -gt 0 ]]; then
    echo "agent-guard self-test: $failures failure(s)" >&2
    return 4
  fi
  echo 'agent-guard self-test: PASS'
  return 0
}

main() {
  if ! command -v jq >/dev/null 2>&1; then
    if [[ "${1:-}" == "hook" ]]; then
      echo "$GUARD_PREFIX protection inactive (jq is not installed)" >&2
      return 0
    fi
    echo "$PROG: required tool not found: jq" >&2
    return 2
  fi

  case "${1:-}" in
  hook) run_hook ;;
  exec)
    shift
    run_exec "$@"
    ;;
  probe) run_probe ;;
  verify) run_verify ;;
  --self-test) self_test ;;
  *)
    usage
    return 1
    ;;
  esac
}

main "$@"
