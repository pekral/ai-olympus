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
#   agent-guard.sh exec <role> [--project <dir>] -- <command>
#                                           run <command> with `/bin/bash -c` in the confinement of <role>
#   agent-guard.sh probe                    prove whether the current process can write into the project
#   agent-guard.sh verify                   check the installed copy and the hook handler
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
#                           terminal and null devices. Never the project, its git directories, any
#                           worktree under `.claude/worktrees/` or `<git common dir>/worktrees/`, the home directory, a
#                           directory on PATH, a shell startup file, or the git configuration.
#     leonardo, splinter    the security-audit run directories below `.claude/run/security-audit/` (or `.codex/run/`)
#                           inside their own review worktree `.claude/worktrees/agent-cr-*-<role>`; the session that
#                           dispatches them creates the worktree and that directory, never the role
#     casey                 `storage/logs/**` (Laravel writes its log there while tinker runs)
#
# Strict mode
#   Only `extra.ai-olympus.security.strict-agent-guard: true` on the default branch switches it on, read through
#   read-manifest.sh beside this script. The manifest is read only when the confinement cannot be applied (or the
#   hook cannot decide), never on the path where it can. Without jq the value is read from the raw default-branch
#   composer.json, and any `"strict-agent-guard": true` in it counts. Strict: the command does not run and the reason
#   is printed on stderr (exec exit 5, hook exit 2). Not strict: the command runs and stderr carries exactly one line
#   `ai-olympus agent-guard: protection inactive (<reason>)`.
#
# Hook decision (Claude Code PreToolUse)
#   A role outside the confined set, an absent or empty `agent_type` (the main session), and a tool other than Bash:
#   exit 0, no output. A confined role: the harness checks the permission rules against the rewritten command, not
#   the original one, so the hook checks the original command against the Bash `deny`, `ask`, and `allow` rules of the
#   settings files it can read. A deny match is `deny`. The hook returns `allow` only when every segment of the
#   command matches an allow rule, or when `permission_mode` is `bypassPermissions`; every other case is `ask`: an ask
#   match, an unreadable settings file, a rule shape this check does not support, and a construct it cannot split (a
#   subshell, a command substitution, a backtick, a leading `!`, a shell keyword). `ask` and `allow` carry
#   `updatedInput` with every field of the tool input kept and `command` replaced by
#   `/bin/bash '<abs>/agent-guard.sh' exec <role> --project '<project>' -- '<original command>'`.
#   Supported rule shapes are `Bash`, `Bash(x)`, `Bash(x *)`, and `Bash(x:*)`; a command is split at `&&`, `||`,
#   `;`, `|`, `|&`, `&`, a newline, `(`, `)`, `{`, `}`, and a backtick. An allow rule is matched after only the wrappers
#   the session itself strips (`timeout time nice nohup stdbuf`), so a leading `NAME=value` word or another wrapper
#   never gets `allow` from a rule. A deny or ask rule is matched after every `NAME=value` word, shell keyword, and
#   the wrappers `timeout time nice nohup stdbuf xargs env exec command builtin noglob` are removed, on the command as
#   written and with its quotes and backslashes removed. In `bypassPermissions` a command whose name is a variable or
#   a glob asks when a deny rule exists.
#
# Probe
#   Creates `<project root>/.ai-olympus-guard-probe-<random>` and `<git common dir>/ai-olympus-guard-probe-<random>`
#   without overwriting anything, and removes what it created on every exit path. Prints `protection=active` only when
#   both writes were refused; otherwise `protection=inactive reason=<reason> strict=<true|false>`. Without jq it prints
#   `protection=inactive reason=jq-missing strict=<true|false>`.
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
       agent-guard.sh exec <role> [--project <dir>] -- <command>
       agent-guard.sh probe
       agent-guard.sh verify
       agent-guard.sh --self-test
EOF
}

# git without the configuration that runs a program: no fsmonitor, no hooks.
git_safe() {
  git -c core.fsmonitor=false -c core.hooksPath=/dev/null "$@"
}

# jq counts only as an executable regular file: `command -v` also names a file that exists but cannot run.
have_jq() {
  local path
  path="$(command -v jq 2>/dev/null)" || return 1
  [[ -f "$path" && -x "$path" ]]
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

# Sets TOP (git toplevel of the current directory), COMMON (git common directory), CWD_ROOT (the main project root of
# the current directory: the parent of a common directory named `.git`, otherwise TOP), PCOMMON (the git common
# directory of the project given with --project), and ROOT (the project given with --project, otherwise CWD_ROOT).
# Outside a git checkout TOP and CWD_ROOT are the current directory and COMMON is empty.
resolve_project() {
  local project="${1:-}" common status=0
  TOP="$(git_safe rev-parse --show-toplevel 2>/dev/null)" || TOP=""
  if [[ -z "$TOP" ]]; then
    TOP="$(pwd -P)"
    COMMON=""
    CWD_ROOT="$TOP"
    status=1
  else
    TOP="$(physical_dir "$TOP")"
    common="$(git_safe rev-parse --git-common-dir 2>/dev/null)" || common=""
    COMMON="$(physical_dir "$common")"
    if [[ -n "$COMMON" && "${COMMON##*/}" == ".git" ]]; then
      CWD_ROOT="${COMMON%/.git}"
    else
      CWD_ROOT="$TOP"
    fi
  fi
  ROOT="$CWD_ROOT"
  PCOMMON=""
  if [[ -n "$project" ]] && project="$(physical_dir "$project")" && [[ -n "$project" ]]; then
    ROOT="$project"
    common="$(git_safe -C "$project" rev-parse --git-common-dir 2>/dev/null)" || common=""
    [[ -n "$common" && "$common" != /* ]] && common="$project/$common"
    [[ -n "$common" ]] && PCOMMON="$(physical_dir "$common")"
    [[ -z "$COMMON" && -n "$PCOMMON" ]] && COMMON="$PCOMMON"
  fi
  return "$status"
}

# Prints true only when the default-branch manifest sets security.strict-agent-guard to the boolean true. Without jq
# the raw default-branch composer.json is searched, so any `"strict-agent-guard": true` in it switches strict mode on.
strict_mode() {
  local manifest=""
  [[ -r "$SCRIPT_DIR/read-manifest.sh" ]] || { printf 'false'; return 0; }
  if have_jq; then
    manifest="$(cd "$ROOT" 2>/dev/null && /bin/bash "$SCRIPT_DIR/read-manifest.sh" 2>/dev/null)" || true
    if [[ -n "$manifest" ]] && printf '%s' "$manifest" | jq -e '.security["strict-agent-guard"] == true' >/dev/null 2>&1; then
      printf 'true'
      return 0
    fi
  else
    manifest="$(cd "$ROOT" 2>/dev/null && /bin/bash "$SCRIPT_DIR/read-manifest.sh" --raw 2>/dev/null)" || true
    if printf '%s' "$manifest" | tr -d '[:space:]' | grep -q '"strict-agent-guard":true'; then
      printf 'true'
      return 0
    fi
  fi
  printf 'false'
}

# --- the hook ---------------------------------------------------------------------------------------------------

settings_files() {
  local project="$1"
  printf '%s\n' "$project/.claude/settings.json" "$project/.claude/settings.local.json"
  [[ -n "${HOME:-}" ]] && printf '%s\n' "$HOME/.claude/settings.json"
  printf '%s\n' '/Library/Application Support/ClaudeCode/managed-settings.json' '/etc/claude-code/managed-settings.json'
}

# Prints a JSON object {"deny": [...], "ask": [...], "allow": [...], "unreadable": bool} of the Bash rules of every
# settings file.
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
    jq -cn --argjson u "$unreadable" '{deny: [], ask: [], allow: [], unreadable: $u}'
    return 0
  fi
  jq -cs --argjson u "$unreadable" '
    def bash_rules($key): [ .[] | (.permissions[$key]? // []) | if type == "array" then .[] else empty end
      | select(type == "string") | select(. == "Bash" or startswith("Bash(")) ];
    {deny: bash_rules("deny"), ask: bash_rules("ask"), allow: bash_rules("allow"), unreadable: $u}' "${docs[@]}"
}

# The jq program that decides deny / ask / allow for one command against the collected rules.
# shellcheck disable=SC2016
DECIDE_JQ='
def keywords: ["if", "then", "elif", "else", "fi", "do", "done", "while", "until", "!"];
def unsplittable: ["for", "case", "esac", "select", "function", "in", "coproc", "[[", "]]"];
def norm: [splits("[ \t]+")] | map(select(length > 0));
def drop_options($two): until(length == 0 or ((.[0] | startswith("-")) | not);
  if (.[0] as $o | $two | index($o)) then .[2:] else .[1:] end);
def wrapper($w): .[0] == $w;
def strip_harness:
  if length == 0 then .
  elif wrapper("timeout") then .[1:] | drop_options(["-k", "-s", "--kill-after", "--signal"]) | .[1:] | strip_harness
  elif wrapper("nice") then .[1:] | drop_options(["-n"]) | strip_harness
  elif wrapper("stdbuf") then .[1:] | drop_options(["-i", "-o", "-e"]) | strip_harness
  elif wrapper("time") then .[1:] | drop_options(["-f", "-o"]) | strip_harness
  elif wrapper("nohup") then .[1:] | strip_harness
  else . end;
def strip_deny:
  if length == 0 then .
  elif (.[0] | test("^[A-Za-z_][A-Za-z0-9_]*=")) then .[1:] | strip_deny
  elif (.[0] as $t | keywords | index($t)) then .[1:] | strip_deny
  elif (.[0] as $t | ["timeout", "nice", "stdbuf", "time", "nohup"] | index($t)) then strip_harness | strip_deny
  elif wrapper("xargs") then .[1:] | drop_options(["-n", "-I", "-L", "-P", "-d", "-E", "-s", "-a"]) | strip_deny
  elif wrapper("env") then .[1:] | drop_options(["-u", "-C"]) | strip_deny
  elif wrapper("exec") then .[1:] | drop_options(["-a"]) | strip_deny
  elif (.[0] as $t | ["command", "builtin", "noglob"] | index($t)) then .[1:] | drop_options([]) | strip_deny
  else . end;
def separators: "\\|\\||&&|\\|&|[;|&\n(){}`]";
def segments(f): [splits(separators)] | map(norm | f | join(" ")) | map(select(length > 0));
def dequote: gsub("[\"\\\\\u0027]"; "");
def opaque_name: split(" ")[0] | test("[$*?\\[]");
def unsupported_construct: test("[(){}`]") or test("(^|[;&|\n][ \t]*)!") or
  ([splits("\\|\\||&&|\\|&|[;|&\n]")] | map(norm | .[0] // "") | any(. as $w | (keywords + unsplittable) | index($w)));
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
(.command | segments(strip_deny)) as $plain
| ($plain + (.command | dequote | segments(strip_deny))) as $dsegs
| (.command | segments(strip_harness)) as $asegs
| (.rules.deny | map(parse_rule)) as $deny
| (.rules.ask | map(parse_rule)) as $ask
| (.rules.allow | map(parse_rule)) as $allow
| (.command | unsupported_construct) as $construct
| if any($deny[]; . as $r | any($dsegs[]; . as $s | $r | matches($s))) then "deny"
  elif .rules.unreadable or any(($deny + $ask)[]; .kind == "unsupported") then "ask"
  elif any($ask[]; . as $r | any($dsegs[]; . as $s | $r | matches($s))) then "ask"
  elif .mode == "bypassPermissions" and (($deny | length) == 0 or (($construct | not) and (any($plain[]; opaque_name) | not))) then "allow"
  elif $construct then "ask"
  elif ($asegs | length) > 0 and all($asegs[]; . as $s | any($allow[]; matches($s))) then "allow"
  else "ask" end
'

# The decision for one command: deny, ask, or allow.
decide_command() {
  local command="$1" project="$2" mode="${3:-}" rules
  rules="$(collect_rules "$project")" || rules='{"deny":[],"ask":[],"allow":[],"unreadable":true}'
  jq -rn --arg command "$command" --arg mode "$mode" --argjson rules "$rules" \
    "{command: \$command, mode: \$mode, rules: \$rules} | $DECIDE_JQ"
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
  local input role command project mode decision wrapped
  input="$(cat)"
  resolve_project "${CLAUDE_PROJECT_DIR:-}" >/dev/null 2>&1 || true

  if ! have_jq; then
    hook_fail "jq is not installed"
    return $?
  fi
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
  mode="$(printf '%s' "$input" | jq -r '.permission_mode // "" | if type == "string" then . else "" end')"
  project="${CLAUDE_PROJECT_DIR:-}"
  [[ -n "$project" ]] || project="$(printf '%s' "$input" | jq -r '.cwd // "" | if type == "string" then . else "" end')"
  [[ -n "$project" ]] || project="$ROOT"
  decision="$(decide_command "$command" "$project" "$mode")" || decision="ask"

  if [[ "$decision" == "deny" ]]; then
    jq -cn --arg reason "$GUARD_PREFIX a Bash deny rule of the session matches this command of $role" \
      '{hookSpecificOutput: {hookEventName: "PreToolUse", permissionDecision: "deny", permissionDecisionReason: $reason}}'
    return 0
  fi
  [[ "$decision" == "allow" ]] || decision="ask"

  wrapped="/bin/bash $(shell_quote "$SCRIPT_PATH") exec $role --project $(shell_quote "$project") -- $(shell_quote "$command")"
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
  printf '%s\n' --die-with-parent --new-session --unshare-pid
  if bwrap --help 2>&1 | grep -q -- '--disable-userns'; then
    printf '%s\n' --unshare-user --disable-userns
  fi
  printf '%s\n' --ro-bind / / --dev /dev --proc /proc
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

is_review_role() {
  [[ "$1" == "leonardo" || "$1" == "splinter" ]]
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
  profile+='(deny file-write* (subpath (param "ROOT")) (subpath (param "TOP")) (subpath (param "CWD_ROOT"))'
  profile+=' (subpath (param "COMMON")) (subpath (param "PCOMMON")))'
  profile+='(deny file-write* (subpath (param "HOME_CLAUDE")) (subpath (param "HOME_CODEX")))'
  profile+='(allow file-write* (subpath (param "RUN_CLAUDE")) (subpath (param "RUN_CODEX")))'
  case "$role" in
  leonardo | splinter) profile+='(allow file-write* (regex (param "AUDIT_RE")))' ;;
  casey) profile+='(allow file-write* (subpath (param "LOGS")))' ;;
  esac
  profile+='(deny file-write-create (require-all (subpath (param "RUN_CLAUDE")) (vnode-type SYMLINK)))'
  profile+='(deny file-write-create (require-all (subpath (param "RUN_CODEX")) (vnode-type SYMLINK)))'
  profile+='(deny file-write-create (require-all (regex (param "AUDIT_RE")) (vnode-type SYMLINK)))'
  printf '%s' "$profile"
}

run_macos() {
  local role="$1" command="$2" root_re
  root_re="$(regex_escape "$ROOT")"
  /usr/bin/sandbox-exec \
    -D ROOT="$ROOT" -D TOP="$TOP" -D CWD_ROOT="$CWD_ROOT" \
    -D COMMON="${COMMON:-$NONE}" -D PCOMMON="${PCOMMON:-$NONE}" \
    -D TMP_SYS="${TMP_SYS:-$NONE}" -D TMP_USER="${TMP_USER:-$NONE}" \
    -D HOME_DIR="${HOME_DIR:-$NONE}" -D LOCK_DIR="${LOCK_DIR:-$NONE}" \
    -D HOME_CLAUDE="${HOME_DIR:-$NONE}/.claude" -D HOME_CODEX="${HOME_DIR:-$NONE}/.codex" \
    -D RUN_CLAUDE="$ROOT/.claude/run" -D RUN_CODEX="$ROOT/.codex/run" \
    -D AUDIT_RE="^$root_re/\\.claude/worktrees/agent-cr-[^/]+-${role}[0-9]*/\\.(claude|codex)/run/security-audit/.+" \
    -D LOGS="$ROOT/storage/logs" \
    -p "$(macos_profile "$role")" /usr/bin/env PATH="$ORIGINAL_PATH" /bin/bash -c "$command"
}

# Prints the security-audit run directory of every review worktree of <role> that the dispatcher prepared: a real
# directory below the physical project root, with no symbolic link on its path.
review_audit_dirs() {
  local role="$1" entry dir sub
  for entry in "$ROOT/.claude/worktrees"/agent-cr-*; do
    [[ "${entry##*/}" =~ ^agent-cr-.+-${role}[0-9]*$ ]] || continue
    for sub in .claude .codex; do
      dir="$entry/$sub/run/security-audit"
      [[ -d "$dir" && "$(physical_dir "$dir")" == "$dir" ]] && printf '%s\n' "$dir"
    done
  done
  return 0
}

run_linux() {
  local role="$1" command="$2" dir
  local -a args=()
  mapfile -t args < <(bwrap_base_args)
  [[ -n "$TMP_SYS" && -d "$TMP_SYS" ]] && args+=(--bind "$TMP_SYS" "$TMP_SYS")
  [[ -n "$TMP_USER" && -d "$TMP_USER" ]] && args+=(--bind "$TMP_USER" "$TMP_USER")
  [[ -n "$HOME_DIR" && -d "$HOME_DIR" ]] && args+=(--ro-bind "$HOME_DIR" "$HOME_DIR")
  if [[ -n "$TMP_USER" && -n "$HOME_DIR" && "$TMP_USER" == "$HOME_DIR"/* && -d "$TMP_USER" ]]; then
    args+=(--bind "$TMP_USER" "$TMP_USER")
  fi
  [[ -n "$LOCK_DIR" && -d "$LOCK_DIR" ]] && args+=(--bind "$LOCK_DIR" "$LOCK_DIR")
  for dir in "$ROOT" "$TOP" "$CWD_ROOT" "$COMMON" "$PCOMMON"; do
    [[ -n "$dir" && -d "$dir" ]] && args+=(--ro-bind "$dir" "$dir")
  done
  mkdir -p "$ROOT/.claude/run" 2>/dev/null || true
  args+=(--bind "$ROOT/.claude/run" "$ROOT/.claude/run")
  [[ -d "$ROOT/.codex/run" ]] && args+=(--bind "$ROOT/.codex/run" "$ROOT/.codex/run")
  if is_review_role "$role"; then
    while IFS= read -r dir; do
      args+=(--bind "$dir" "$dir")
    done < <(review_audit_dirs "$role")
  fi
  [[ "$role" == "casey" && -d "$ROOT/storage/logs" ]] && args+=(--bind "$ROOT/storage/logs" "$ROOT/storage/logs")
  bwrap "${args[@]}" -- /usr/bin/env PATH="$ORIGINAL_PATH" /bin/bash -c "$command"
}

run_exec() {
  local role="${1:-}" project="" command reason status
  shift || true
  if [[ "${1:-}" == "--project" ]]; then
    project="${2:-}"
    shift 2 || { usage; return 64; }
  fi
  [[ $# -eq 2 && "$1" == "--" ]] || { usage; return 64; }
  command="$2"
  role="$(normalize_role "$role")"
  is_confined_role "$role" || { echo "$PROG: not a confined role: $role" >&2; return 64; }
  resolve_project "$project" || true

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
  if ! have_jq; then
    printf 'protection=inactive reason=jq-missing strict=%s\n' "$(strict_mode)"
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
    jq -cn --arg agent "$1" --arg command "$2" --arg mode "${3:-default}" \
      '{tool_name: "Bash", agent_type: $agent, permission_mode: $mode, tool_input: {command: $command, description: "d", timeout: 5000}}'
  }

  out="$(hook_input leonardo "echo it's" | (CLAUDE_PROJECT_DIR="$tmp" HOME="$tmp" run_hook))"
  check 'a confined role gets the command wrapped in exec' \
    test "$(printf '%s' "$out" | jq -r '.hookSpecificOutput.updatedInput.command')" \
    == "/bin/bash '$SCRIPT_PATH' exec leonardo --project '$tmp' -- 'echo it'\\''s'"
  check 'a command no allow rule matches asks' test "$(printf '%s' "$out" | jq -r '.hookSpecificOutput.permissionDecision')" == ask
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
  printf '{"permissions":{"deny":["Bash(curl:*)"],"ask":["Bash(gh pr merge *)"],"allow":["Bash(git status:*)","Bash(ls)"]}}' \
    >"$tmp/.claude/settings.json"
  check 'a deny rule denies the original command' \
    test "$(decide_command 'cd x && timeout 5 curl https://example.test' "$tmp")" == deny
  # shellcheck disable=SC2016
  check 'a deny rule denies inside a subshell' test "$(decide_command 'echo $(curl https://example.test)' "$tmp")" == deny
  check 'an ask rule never allows' test "$(decide_command 'gh pr merge 1' "$tmp")" == ask
  check 'a command no rule matches asks' test "$(decide_command 'curlish --version' "$tmp")" == ask
  check 'a command every segment of which an allow rule matches is allowed' \
    test "$(decide_command 'git status --short && ls' "$tmp")" == allow
  check 'one unmatched segment asks' test "$(decide_command 'git status; rm -rf x' "$tmp")" == ask
  check 'a subshell asks even when an allow rule matches' test "$(decide_command '(git status)' "$tmp")" == ask
  check 'bypassPermissions allows' test "$(decide_command 'curlish x' "$tmp" bypassPermissions)" == allow
  check 'bypassPermissions keeps the deny rule' test "$(decide_command 'curl x' "$tmp" bypassPermissions)" == deny
  check 'a leading assignment never gets allow from a rule' test "$(decide_command 'GIT_PAGER=x git status' "$tmp")" == ask
  check 'a deny rule matches behind a bare xargs' test "$(decide_command 'echo u | xargs curl' "$tmp" bypassPermissions)" == deny
  check 'a deny rule matches a quoted command name' test "$(decide_command '"curl" x' "$tmp" bypassPermissions)" == deny
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
  case "${1:-}" in
  hook)
    run_hook
    return $?
    ;;
  exec)
    shift
    run_exec "$@"
    return $?
    ;;
  probe)
    run_probe
    return $?
    ;;
  esac
  if ! have_jq; then
    echo "$PROG: required tool not found: jq" >&2
    return 2
  fi
  case "${1:-}" in
  verify) run_verify ;;
  --self-test) self_test ;;
  *)
    usage
    return 1
    ;;
  esac
}

main "$@"
