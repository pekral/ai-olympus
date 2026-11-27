#!/bin/bash
# agent-guard.sh — confine the shell commands of a read-only agent role with an operating-system sandbox.
#
# Why this exists
#   A read-only role (`leonardo`, `splinter`, `april`, `casey`, `shredder`, `krang`) carries `Bash`, and Bash can
#   write any file: a redirect, `sh -c`, an interpreter, `git commit`, a process left in the background. An
#   instruction that says "do not write" stops none of them. The Claude Code `PreToolUse` hook filters tools by
#   role and rewrites the Bash command of a confined role so it runs under `sandbox-exec`
#   (macOS) or `bwrap` (Linux). The kernel then refuses every write outside a short list of named paths, from the
#   command and from every process it starts. The decision is made from the role the harness reports (`agent_type`),
#   never from the text of the command (rules/compound-engineering/orchestration.md *Bash capability boundary*).
#
# Usage
#   agent-guard.sh hook                     read the PreToolUse JSON on stdin, print the hook decision
#   agent-guard.sh exec <role> [--project <dir>] -- <command>
#                                           run <command> with `/bin/bash -c` in the confinement of <role>
#   agent-guard.sh preflight <claude|codex> <role> [--project <dir>]
#                                          report the dispatch verdict before the role starts
#   agent-guard.sh probe [claude|codex]      report the runtime verdict without writing into the project
#   agent-guard.sh review-head <commit>     check that a commit may get a review worktree
#   agent-guard.sh verify                   check the installed guard, policy reader, and hook settings
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
#     every confined role   `.claude/run/**` and `.codex/run/**` of the project, a private temporary directory that
#                           `exec` creates for the one command, points `TMPDIR` at, and removes afterwards, the machine
#                           lock directory, and the terminal and null devices. On macOS also the `sh-thd*` file of a
#                           here-document directly in `/tmp`, because the system bash 3.2 ignores `TMPDIR` there. Never
#                           any other file in `/tmp`, the `TMPDIR` of the session, the project, its git directories,
#                           any worktree under `.claude/worktrees/` or `<git common dir>/worktrees/`, the home
#                           directory, a directory on PATH, a shell startup file, or the git configuration. On macOS a
#                           confined command creates no symbolic link anywhere.
#     casey                 `storage/logs/**` (Laravel writes its log there while tinker runs)
#
# Strict mode
#   The trusted manifest reader resolves and pins the origin default branch. It reads only the JSON boolean
#   `extra.ai-olympus.security.strict-agent-guard`. An absent key on that commit is off. A missing ref, reader, jq,
#   or malformed policy blocks the action. Strict mode requires evidence for the complete role, so both mixed
#   Claude and mixed Codex sessions are blocked. A non-strict Bash command can fall back only before it starts.
#
# Hook decision (Claude Code PreToolUse)
#   A role outside the confined set is unchanged unless it is dispatching a confined role. For a confined role the
#   hook permits the known read tools, gates delegation, denies other tool names, and wraps Bash. The harness
#   checks the permission rules against the rewritten Bash command, not
#   the original one, so the hook checks the original command against the Bash `deny`, `ask`, and `allow` rules of the
#   project `.claude/settings.json` and `.claude/settings.local.json`, the user `settings.json` (in `CLAUDE_CONFIG_DIR`
#   when it is set, otherwise in `~/.claude`), and the two `managed-settings.json` files. A deny match is `deny`. The
#   hook returns `allow` only when every segment of the command matches an allow rule, or when `permission_mode` is
#   `bypassPermissions`; every other case is `ask`: an ask match, an unreadable settings file, a user file that cannot
#   be located, a rule shape this check does not support, and a construct it cannot split (a subshell, a command
#   substitution, a backtick, a leading `!`, a shell keyword). `ask` and `allow` carry
#   `updatedInput` with every field of the tool input kept and `command` replaced by
#   `/bin/bash '<abs>/agent-guard.sh' exec <role> --project '<project>' -- '<original command>'`.
#   Supported rule shapes are `Bash`, `Bash(x)`, `Bash(x *)`, and `Bash(x:*)`. Every backslash-newline is removed
#   first, as the shell joins the two lines; then a command is split at `&&`, `||`,
#   `;`, `|`, `|&`, `&`, a newline, `(`, `)`, `{`, `}`, and a backtick. An allow rule is matched after only the wrappers
#   the session itself strips (`timeout time nice nohup stdbuf`), so a leading `NAME=value` word or another wrapper
#   never gets `allow` from a rule. A deny or ask rule is matched after every `NAME=value` word, shell keyword, and
#   the wrappers `timeout time nice nohup stdbuf xargs env exec command builtin noglob` are removed, on the command as
#   written and with its quotes and backslashes removed. In `bypassPermissions` a command whose name is a variable or
#   a glob asks when a deny rule exists.
#
# Review head
#   Run by the unconfined session before it creates or moves a review worktree. Exit 0 when the tree of <commit>
#   carries no symbolic link and no gitlink at `.claude` or `.codex` or below them (compared without case); otherwise
#   exit 3 with `review-head=refused reason=link-below-agent-config path=<path>` on stdout.
#
# Probe
#   Prints the platform and policy result without attempting to write to the real project. Bash sandbox tests use
#   a fixture and do not attest to the isolation of other tools. Neither shipped adapter attests to full isolation.
#
# Exit codes
#   exec    the exit code of the command; 5 strict mode refused to run it without confinement; 64 usage error; 70 no
#           private temporary directory could be created
#   hook    0 decision printed (or none needed); 2 fail closed when no decision can be issued
#   preflight, probe  0 dispatch can continue; 5 blocked; 64 usage error
#   review-head  0 the commit may get a review worktree; 3 refused; 64 usage error or not a commit
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
       agent-guard.sh preflight <claude|codex> <role> [--project <dir>]
       agent-guard.sh probe [claude|codex]
       agent-guard.sh review-head <commit>
       agent-guard.sh verify
       agent-guard.sh --self-test
EOF
}

# Guard policy resolution must not inherit any repository, object, or Git config overrides from a tool call.
guard_environment() {
  /usr/bin/env -i PATH="$PATH" HOME=/dev/null GIT_CONFIG_NOSYSTEM=1 GIT_CONFIG_GLOBAL=/dev/null \
    GIT_NO_REPLACE_OBJECTS=1 GIT_CONFIG_COUNT=2 GIT_CONFIG_KEY_0=core.fsmonitor GIT_CONFIG_VALUE_0=false \
    GIT_CONFIG_KEY_1=core.hooksPath GIT_CONFIG_VALUE_1=/dev/null "$@"
}

git_safe() {
  guard_environment git "$@"
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
  if [[ -n "$project" ]]; then
    project="$(physical_dir "$project")" || return 1
    [[ "$(git_safe -C "$project" rev-parse --show-toplevel 2>/dev/null)" == "$project" ]] || return 1
    ROOT="$project"
    common="$(git_safe -C "$project" rev-parse --git-common-dir 2>/dev/null)" || common=""
    [[ -n "$common" && "$common" != /* ]] && common="$project/$common"
    [[ -n "$common" ]] && PCOMMON="$(physical_dir "$common")"
    [[ -z "$COMMON" && -n "$PCOMMON" ]] && COMMON="$PCOMMON"
    [[ -n "$PCOMMON" ]] || return 1
    status=0
  fi
  return "$status"
}

# Prints a known boolean only when the trusted manifest reader verified the default-branch policy.
strict_mode() {
  local policy loader="$SCRIPT_DIR/read-manifest.sh" install_path
  case "$SCRIPT_DIR" in
    */.claude/skills/_shared | */.agents/skills/_shared)
      install_path="${SCRIPT_DIR#"$ROOT/"}"
      if ! verify_copies "${PCOMMON:-$COMMON}" "$install_path"; then
        printf 'unknown'
        return 0
      fi
      ;;
  esac
  if [[ -f "$loader" && ! -L "$loader" ]] && have_jq && \
    policy="$(cd "$ROOT" && guard_environment /bin/bash "$loader" --guard-policy 2>/dev/null)"; then
    case "$policy" in true | false) printf '%s' "$policy"; return 0 ;; esac
  fi
  printf 'unknown'
}

# --- the hook ---------------------------------------------------------------------------------------------------

# Prints the settings files the hook reads, one per line. The user file is `$CLAUDE_CONFIG_DIR/settings.json` when the
# variable is set, otherwise `~/.claude/settings.json`. An empty line stands for a user file that cannot be located.
settings_files() {
  local project="$1"
  printf '%s\n' "$project/.claude/settings.json" "$project/.claude/settings.local.json"
  if [[ -n "${CLAUDE_CONFIG_DIR:-}" ]]; then
    [[ "$CLAUDE_CONFIG_DIR" == /* ]] && printf '%s\n' "$CLAUDE_CONFIG_DIR/settings.json" || printf '\n'
  elif [[ -n "${HOME:-}" && "$HOME" == /* ]]; then
    printf '%s\n' "$HOME/.claude/settings.json"
  else
    printf '\n'
  fi
  printf '%s\n' '/Library/Application Support/ClaudeCode/managed-settings.json' '/etc/claude-code/managed-settings.json'
}

# Prints a JSON object {"deny": [...], "ask": [...], "allow": [...], "unreadable": bool} of the Bash rules of every
# settings file.
collect_rules() {
  local project="$1" file unreadable=false
  local -a docs=()
  while IFS= read -r file; do
    [[ -n "$file" ]] || { unreadable=true; continue; }
    [[ -e "$file" ]] || continue
    [[ -f "$file" && -r "$file" ]] || { unreadable=true; continue; }
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
.command |= gsub("\\\\\n"; "")
| (.command | segments(strip_deny)) as $plain
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
  echo "$GUARD_PREFIX Blocked: $1" >&2
  return 2
}

# The harness supplies the session and agent identities. The confined Bash process cannot write this Git metadata.
hook_state() {
  local input="$1" session agent parent="${PCOMMON:-$COMMON}/ai-olympus"
  BLOCK_SESSION=""
  BLOCK_STATE=""
  session="$(jq -r '.session_id // ""' <<<"$input")"
  agent="$(jq -r '.agent_id // .agent_type // ""' <<<"$input")"
  [[ -n "${PCOMMON:-$COMMON}" && ! -L "$parent" ]] || return 1
  [[ "$session" =~ ^[a-zA-Z0-9_-]{1,128}$ ]] || return 0
  BLOCK_SESSION="$parent/agent-guard-$session"
  [[ ! -L "$BLOCK_SESSION" ]] || return 1
  [[ "$agent" =~ ^[a-zA-Z0-9_:-]{1,128}$ ]] || return 0
  BLOCK_STATE="$BLOCK_SESSION/$agent"
  [[ ! -L "$BLOCK_STATE" ]]
}

hook_deny() {
  if [[ -n "${BLOCK_STATE:-}" ]]; then
    (umask 077; mkdir -p -- "$BLOCK_STATE") || { hook_fail 'cannot record the runtime denial'; return $?; }
  fi
  jq -cn --arg reason "$GUARD_PREFIX Blocked: $1" \
    '{hookSpecificOutput: {hookEventName: "PreToolUse", permissionDecision: "deny", permissionDecisionReason: $reason}}'
}

run_hook() {
  local input role tool target command project mode decision wrapped policy path
  input="$(cat)"

  if ! have_jq; then
    hook_fail "jq is not installed"
    return $?
  fi
  if ! jq -e 'type == "object"' >/dev/null 2>&1 <<<"$input"; then
    hook_fail "the hook input is not a JSON object"
    return $?
  fi

  if [[ "$(jq -r '.hook_event_name // ""' <<<"$input")" == 'SessionEnd' ]]; then
    resolve_project "${CLAUDE_PROJECT_DIR:-}" || return 0
    hook_state "$input" || return 0
    if [[ -n "$BLOCK_SESSION" && -d "$BLOCK_SESSION" ]]; then
      for path in "$BLOCK_SESSION"/*; do
        [[ -d "$path" && ! -L "$path" ]] && rmdir -- "$path"
      done
      rmdir -- "$BLOCK_SESSION"
    fi
    return 0
  fi

  tool="$(jq -r '.tool_name // ""' <<<"$input")"
  role="$(normalize_role "$(jq -r '.agent_type // "" | if type == "string" then . else "" end' <<<"$input")")"
  target=""
  case "$tool" in
    Agent | Task) target="$(normalize_role "$(jq -r '.tool_input.subagent_type // ""' <<<"$input")")" ;;
  esac
  if ! is_confined_role "$role" && ! is_confined_role "$target"; then
    return 0
  fi
  resolve_project "${CLAUDE_PROJECT_DIR:-}" || { hook_fail 'cannot resolve the project'; return $?; }
  hook_state "$input" || { hook_fail 'cannot resolve the runtime denial state'; return $?; }
  if [[ -n "$BLOCK_STATE" && -e "$BLOCK_STATE" ]]; then
    hook_deny 'the guard already denied an action in this role invocation'
    return $?
  fi
  policy="$(strict_mode)"
  if [[ "$policy" != false ]]; then
    hook_deny "$(inactive_reason claude "$policy")"
    return $?
  fi
  if ! is_confined_role "$role"; then
    return 0
  fi
  case "$tool" in
    Read | Glob | Grep | WebSearch | WebFetch) return 0 ;;
    Agent | Task) [[ "$role" != krang ]] || return 0 ;;
    Bash) ;;
    *) hook_deny 'the tool is unavailable to this read-only role'; return $? ;;
  esac
  if [[ "$tool" != Bash ]]; then
    hook_deny 'delegation is unavailable to this read-only role'
    return $?
  fi

  if ! jq -e '.tool_input.command | type == "string"' >/dev/null 2>&1 <<<"$input"; then
    hook_deny "the Bash input of $role carries no command"
    return $?
  fi

  command="$(printf '%s' "$input" | jq -r '.tool_input.command')"
  mode="$(printf '%s' "$input" | jq -r '.permission_mode // "" | if type == "string" then . else "" end')"
  project="$ROOT"
  decision="$(decide_command "$command" "$project" "$mode")" || decision="ask"

  if [[ "$decision" == "deny" ]]; then
    hook_deny "a Bash deny rule of the session matches this command of $role"
    return $?
  fi
  [[ "$decision" == "allow" ]] || decision="ask"

  wrapped="/bin/bash $(shell_quote "$SCRIPT_PATH") exec $role --project $(shell_quote "$project") -- $(shell_quote "$command")"
  printf '%s' "$input" | jq -c --arg wrapped "$wrapped" --arg decision "$decision" \
    --arg reason "$GUARD_PREFIX routes this command through the Bash guard for $role" \
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

within() {
  [[ "$1" == "$2" || "$1" == "$2/"* ]]
}

# Exceptions may not intersect Git metadata or any registered worktree. The three named project
# scratch directories may be inside ROOT itself, but cannot be checked-out content or symlink aliases.
safe_writable_dir() {
  local dir="$1" entry worktree listing
  [[ "$(physical_dir "$dir")" == "$dir" ]] || return 1
  for entry in "$COMMON" "$PCOMMON"; do
    [[ -n "$entry" ]] || continue
    if within "$dir" "$entry" || within "$entry" "$dir"; then return 1; fi
  done

  listing="$(git_safe -C "$ROOT" worktree list --porcelain)" || return 1
  while IFS= read -r entry; do
    [[ "$entry" == 'worktree '* ]] || continue
    worktree="${entry#worktree }"
    [[ "$worktree" == /* ]] || return 1
    if within "$worktree" "$dir" || { [[ "$worktree" != "$ROOT" ]] && within "$dir" "$worktree"; }; then
      return 1
    fi
  done <<<"$listing"
  case "$dir" in
    "$ROOT/.claude/run" | "$ROOT/.codex/run" | "$ROOT/storage/logs")
      listing="$(git_safe -C "$ROOT" ls-files -- "${dir#"$ROOT/"}")" || return 1
      if [[ "$dir" == "$ROOT/storage/logs" && "$listing" == 'storage/logs/.gitignore' ]]; then
        [[ -f "$dir/.gitignore" && ! -L "$dir/.gitignore" ]] || return 1
        LOGS_IGNORE="$dir/.gitignore"
      elif [[ -n "$listing" ]]; then
        return 1
      fi
      ;;
    *)
      for entry in "$ROOT" "$TOP" "$CWD_ROOT"; do
        if within "$dir" "$entry" || within "$entry" "$dir"; then return 1; fi
      done
      if [[ -n "$HOME_DIR" ]]; then
        for entry in "$HOME_DIR" "$HOME_DIR/.claude" "$HOME_DIR/.codex"; do
          within "$entry" "$dir" && return 1
        done
        within "$dir" "$HOME_DIR/.claude" && return 1
        within "$dir" "$HOME_DIR/.codex" && return 1
      fi
      ;;
  esac
  # Existing hard links could alias an inode across a read-only Linux bind mount.
  listing="$(find "$dir" -type f -links +1 -print -quit)" || return 1
  [[ -z "$listing" ]]
}

resolve_writable() {
  local role="$1" dir
  TMP_SYS="$(physical_dir /tmp)" || TMP_SYS=""
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
  if [[ -n "$LOCK_DIR" && ( -e "$LOCK_DIR" || -L "$LOCK_DIR" ) ]]; then
    safe_writable_dir "$LOCK_DIR" || return 1
  else
    LOCK_DIR=""
  fi
  RUN_CLAUDE="" RUN_CODEX="" LOGS="" LOGS_IGNORE=""
  for dir in "$ROOT/.claude/run" "$ROOT/.codex/run"; do
    [[ -e "$dir" || -L "$dir" ]] || continue
    safe_writable_dir "$dir" || return 1
    case "$dir" in */.claude/run) RUN_CLAUDE="$dir" ;; *) RUN_CODEX="$dir" ;; esac
  done
  if [[ "$role" == casey && ( -e "$ROOT/storage/logs" || -L "$ROOT/storage/logs" ) ]]; then
    safe_writable_dir "$ROOT/storage/logs" || return 1
    LOGS="$ROOT/storage/logs"
  fi
  return 0
}

macos_profile() {
  local role="$1" profile
  profile='(version 1)(allow default)(deny file-write*)'
  profile+='(allow file-write* (literal "/dev/null") (literal "/dev/zero") (literal "/dev/tty") (literal "/dev/dtracehelper")'
  profile+=' (regex "^/dev/ttys[0-9]+$") (regex "^/dev/fd/[0-9]+$"))'
  profile+='(allow file-write* (subpath (param "PRIVATE_TMP")) (subpath (param "LOCK_DIR")))'
  profile+='(allow file-write-data (literal (param "TMP_SYS")))(allow file-write-create file-write-unlink (regex (param "HEREDOC_RE")))'
  profile+='(deny file-write* (subpath (param "ROOT")) (subpath (param "TOP")) (subpath (param "CWD_ROOT"))'
  profile+=' (subpath (param "COMMON")) (subpath (param "PCOMMON")))'
  profile+='(deny file-write* (subpath (param "HOME_CLAUDE")) (subpath (param "HOME_CODEX")))'
  profile+='(allow file-write* (subpath (param "RUN_CLAUDE")) (subpath (param "RUN_CODEX")))'
  [[ "$role" == "casey" ]] && profile+='(allow file-write* (subpath (param "LOGS")))'
  profile+='(deny file-write* (literal (param "LOGS_IGNORE")))'
  profile+='(deny file-write-create (vnode-type SYMLINK))'
  printf '%s' "$profile"
}

run_macos() {
  local role="$1" command="$2"
  /usr/bin/sandbox-exec \
    -D ROOT="$ROOT" -D TOP="$TOP" -D CWD_ROOT="$CWD_ROOT" \
    -D COMMON="${COMMON:-$NONE}" -D PCOMMON="${PCOMMON:-$NONE}" \
    -D PRIVATE_TMP="$PRIVATE_TMP" -D LOCK_DIR="${LOCK_DIR:-$NONE}" -D TMP_SYS="${TMP_SYS:-$NONE}" \
    -D HEREDOC_RE="^$(regex_escape "${TMP_SYS:-$NONE}")/sh-thd[^/]*\$" \
    -D HOME_CLAUDE="${HOME_DIR:-$NONE}/.claude" -D HOME_CODEX="${HOME_DIR:-$NONE}/.codex" \
    -D RUN_CLAUDE="${RUN_CLAUDE:-$NONE}" -D RUN_CODEX="${RUN_CODEX:-$NONE}" \
    -D LOGS="${LOGS:-$NONE}" -D LOGS_IGNORE="${LOGS_IGNORE:-$NONE}" \
    -p "$(macos_profile "$role")" /usr/bin/env PATH="$ORIGINAL_PATH" TMPDIR="$PRIVATE_TMP" /bin/bash -c "$command"
}

run_linux() {
  local command="$1" dir
  local -a args=()
  mapfile -t args < <(bwrap_base_args)
  args+=(--bind "$PRIVATE_TMP" "$PRIVATE_TMP")
  [[ -n "$LOCK_DIR" && -d "$LOCK_DIR" ]] && args+=(--bind "$LOCK_DIR" "$LOCK_DIR")
  for dir in "$ROOT" "$TOP" "$CWD_ROOT" "$COMMON" "$PCOMMON"; do
    [[ -n "$dir" && -d "$dir" ]] && args+=(--ro-bind "$dir" "$dir")
  done
  for dir in "$RUN_CLAUDE" "$RUN_CODEX" "$LOGS"; do
    [[ -z "$dir" ]] || args+=(--bind "$dir" "$dir")
  done
  [[ -z "$LOGS_IGNORE" ]] || args+=(--ro-bind "$LOGS_IGNORE" "$LOGS_IGNORE")
  bwrap "${args[@]}" -- /usr/bin/env PATH="$ORIGINAL_PATH" TMPDIR="$PRIVATE_TMP" /bin/bash -c "$command"
}

run_exec() {
  local role="${1:-}" project="" command reason status policy
  shift || true
  if [[ "${1:-}" == "--project" ]]; then
    project="${2:-}"
    shift 2 || { usage; return 64; }
  fi
  [[ $# -eq 2 && "$1" == "--" ]] || { usage; return 64; }
  command="$2"
  role="$(normalize_role "$role")"
  is_confined_role "$role" || { echo "$PROG: not a confined role: $role" >&2; return 64; }
  resolve_project "$project" || { echo "$GUARD_PREFIX Blocked: cannot resolve the project" >&2; return 5; }
  policy="$(strict_mode)"
  if [[ "$policy" != false ]]; then
    echo "$GUARD_PREFIX Blocked: $(inactive_reason claude "$policy")" >&2
    return 5
  fi

  resolve_writable "$role" || { echo "$GUARD_PREFIX Blocked: unsafe writable directory" >&2; return 5; }
  reason="$(confinement_unavailable_reason)"
  if [[ -n "$reason" ]]; then
    echo "$GUARD_PREFIX protection inactive ($reason)" >&2
    /usr/bin/env PATH="$ORIGINAL_PATH" /bin/bash -c "$command"
    return $?
  fi

  PRIVATE_TMP="$(mktemp -d "${TMP_SYS:-/tmp}/ai-olympus-guard-$role.XXXXXXXX" 2>/dev/null)" && PRIVATE_TMP="$(physical_dir "$PRIVATE_TMP")"
  if [[ -z "$PRIVATE_TMP" ]]; then
    echo "$GUARD_PREFIX cannot create the private temporary directory of $role" >&2
    return 70
  fi
  # shellcheck disable=SC2064
  trap 'rm -rf -- "$PRIVATE_TMP"' EXIT
  safe_writable_dir "$PRIVATE_TMP" || { echo "$GUARD_PREFIX Blocked: unsafe private temporary directory" >&2; return 5; }
  if [[ "$(uname -s)" == "Darwin" ]]; then
    run_macos "$role" "$command"
  else
    run_linux "$command"
  fi
  status=$?
  if [[ "$status" -ne 0 ]]; then
    echo "$GUARD_PREFIX confined Bash command exited with status $status" >&2
  fi
  return "$status"
}

# --- review head ------------------------------------------------------------------------------------------------

check_review_entries() {
  local entry mode path lower tab=$'\t'
  while IFS= read -r -d '' entry; do
    mode="${entry%% *}"
    [[ "$mode" == "120000" || "$mode" == "160000" ]] || continue
    path="${entry#*"$tab"}"
    lower="$(printf '%s' "$path" | tr '[:upper:]' '[:lower:]')"
    if [[ "$lower" == ".claude" || "$lower" == ".codex" || "$lower" == .claude/* || "$lower" == .codex/* ]]; then
      printf 'review-head=refused reason=link-below-agent-config path=%q\n' "$path"
      return 3
    fi
  done
}

run_review_head() {
  local commit="${1:-}" status
  [[ $# -eq 1 && "$commit" =~ ^[0-9a-f]{7,64}$ ]] || { usage; return 64; }
  git_safe rev-parse --verify --quiet "$commit^{commit}" >/dev/null || { echo "$PROG: not a commit: $commit" >&2; return 64; }
  git_safe ls-tree -r -z --full-tree "$commit" | check_review_entries
  status=$?
  case "$status" in
    0) ;;
    3) return 3 ;;
    *) echo 'review-head=refused reason=unreadable-tree'; return 3 ;;
  esac
  echo 'review-head=ok'
  return 0
}

# --- probe and verify ---------------------------------------------------------------------------------------------

inactive_reason() {
  if [[ "$2" == unknown ]]; then
    printf 'trusted-policy-unavailable'
    return 0
  fi
  case "$1" in
    claude) printf 'claude-full-tool-isolation-unverified' ;;
    codex) printf 'codex-session-isolation-unverified' ;;
    *) printf 'host-isolation-unverified' ;;
  esac
}

report_inactive() {
  local platform="$1" project="${2:-}" policy=unknown reason blocked=false
  if resolve_project "$project"; then
    policy="$(strict_mode)"
  fi
  reason="$(inactive_reason "$platform" "$policy")"
  [[ "$policy" == false ]] || blocked=true
  printf 'protection=inactive reason=%s strict=%s blocked=%s\n' "$reason" "$policy" "$blocked"
  [[ "$blocked" == false ]] || return 5
}

run_preflight() {
  local platform="${1:-}" role="${2:-}" project=""
  case "$platform" in claude | codex) ;; *) usage; return 64 ;; esac
  case "$#" in
    2) ;;
    4) [[ "$3" == --project && -n "$4" ]] || { usage; return 64; }; project="$4" ;;
    *) usage; return 64 ;;
  esac
  is_confined_role "$(normalize_role "$role")" || return 0
  report_inactive "$platform" "$project"
}

sha256_of() {
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum "$1" | cut -d' ' -f1
  else
    shasum -a 256 "$1" | cut -d' ' -f1
  fi
}

verify_copies() {
  local common="$1" directory="$2" installed reference recorded actual
  case "$directory" in .claude/skills/_shared | .agents/skills/_shared) ;; *) return 1 ;; esac
  reference="$common/ai-olympus/installed-copies.sha256"
  [[ -n "$common" && -f "$reference" && ! -L "$reference" && ! -L "${reference%/*}" ]] || return 1
  [[ "$(physical_dir "$ROOT/$directory")" == "$ROOT/$directory" ]] || return 1
  for installed in "$directory/agent-guard.sh" "$directory/read-manifest.sh"; do
    [[ -f "$ROOT/$installed" && ! -L "$ROOT/$installed" ]] || return 1
    recorded="$(awk -v path="$installed" 'substr($0, 67) == path && substr($0, 65, 2) == "  " {
      count++; hash = substr($0, 1, 64)
    } END { if (count == 1) print hash }' "$reference")"
    actual="$(sha256_of "$ROOT/$installed")" || return 1
    [[ -n "$recorded" && "$recorded" == "$actual" ]] || return 1
  done
}

run_verify() {
  local settings
  resolve_project || { echo 'agent-guard=refused reason=not-a-git-checkout'; return 3; }
  if ! verify_copies "$COMMON" '.claude/skills/_shared'; then
    echo 'agent-guard=refused reason=unverified-installed-copies'
    return 3
  fi
  settings="$ROOT/.claude/settings.local.json"
  if [[ -L "$settings" || ! -f "$settings" ]] || ! jq -e \
    --arg command '"$CLAUDE_PROJECT_DIR"/.claude/skills/_shared/agent-guard.sh hook' '
    .disableAllHooks != true and (
      [.hooks | to_entries[] | .key as $event
        | .value[] as $group | $group.hooks[]
        | select(.command? | strings | endswith("/.claude/skills/_shared/agent-guard.sh hook"))
        | {event: $event, matcher: $group.matcher, handler: .}]
      | sort_by(.event) == (["PreToolUse", "SessionEnd"] | map({event: ., matcher: "*", handler: {
          type: "command", command: $command, timeout: 10, onFailure: "block"
        }}))
    )' "$settings" >/dev/null 2>&1; then
    echo 'agent-guard=refused reason=unverified-hook-settings'
    return 3
  fi
  echo 'agent-guard=verified'
}

# --- self-test ----------------------------------------------------------------------------------------------------

self_test() {
  local failures=0 tmp repo out
  tmp="$(mktemp -d)" || return 4
  tmp="$(physical_dir "$tmp")" || return 4
  # shellcheck disable=SC2064
  trap "rm -rf -- $(shell_quote "$tmp")" EXIT
  repo="$tmp/repo"
  mkdir -p "$repo/.claude/run" "$tmp/trusted" || return 4
  cp -- "$SCRIPT_PATH" "$SCRIPT_DIR/read-manifest.sh" "$tmp/trusted/" || return 4
  SCRIPT_DIR="$tmp/trusted"
  SCRIPT_PATH="$SCRIPT_DIR/$PROG"
  git_safe -C "$repo" init -q || return 4
  git_safe -C "$repo" -c user.name='Guard Test' -c user.email=guard@example.test commit -qm fixture --allow-empty || return 4
  git_safe -C "$repo" update-ref refs/remotes/origin/main HEAD || return 4
  git_safe -C "$repo" symbolic-ref refs/remotes/origin/HEAD refs/remotes/origin/main || return 4

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

  out="$(hook_input leonardo "echo it's" | (CLAUDE_PROJECT_DIR="$repo" HOME="$tmp" run_hook))"
  check 'a confined role gets the command wrapped in exec' \
    test "$(printf '%s' "$out" | jq -r '.hookSpecificOutput.updatedInput.command')" \
    == "/bin/bash '$SCRIPT_PATH' exec leonardo --project '$repo' -- 'echo it'\\''s'"
  check 'a command no allow rule matches asks' test "$(printf '%s' "$out" | jq -r '.hookSpecificOutput.permissionDecision')" == ask
  check 'the wrapped input keeps every other field' \
    test "$(printf '%s' "$out" | jq -c '.hookSpecificOutput.updatedInput | del(.command)')" == '{"description":"d","timeout":5000}'
  check 'a plugin-qualified role is confined too' \
    test -n "$(hook_input 'pkg:splinter' 'ls' | (CLAUDE_PROJECT_DIR="$repo" HOME="$tmp" run_hook))"
  check 'a writing role is not wrapped' \
    test -z "$(hook_input donatello 'ls' | (CLAUDE_PROJECT_DIR="$repo" HOME="$tmp" run_hook))"
  check 'the main session is not wrapped' \
    test -z "$(hook_input '' 'ls' | (CLAUDE_PROJECT_DIR="$repo" HOME="$tmp" run_hook))"

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
  check 'a deny rule matches across a line continuation' test "$(decide_command $'cu\\\nrl x' "$tmp" bypassPermissions)" == deny
  printf '{"permissions":{"deny":["Bash(git * --force)"]}}' >"$tmp/.claude/settings.local.json"
  check 'an unsupported rule shape asks' test "$(decide_command 'ls' "$tmp")" == ask

  if [[ -z "$(confinement_unavailable_reason)" ]]; then
    (cd "$repo" && HOME="$tmp/home" "$SCRIPT_PATH" exec leonardo -- "echo x > '$repo/written'" >/dev/null 2>&1)
    check 'the confinement refuses a write into the project' test ! -e "$repo/written"
    (cd "$repo" && HOME="$tmp/home" "$SCRIPT_PATH" exec leonardo -- "echo x > '$repo/.claude/run/brief'" >/dev/null 2>&1)
    check 'the confinement allows a write into .claude/run' test -e "$repo/.claude/run/brief"
    mkdir -p "$tmp/home"
    (cd "$repo" && HOME="$tmp/home" "$SCRIPT_PATH" exec leonardo -- "echo x > '$tmp/home/.gitconfig'" >/dev/null 2>&1)
    check 'the confinement refuses a write into the home directory' test ! -e "$tmp/home/.gitconfig"
    (cd "$repo" && HOME="$tmp/home" "$SCRIPT_PATH" exec leonardo -- "echo x > '$tmp/shared'" >/dev/null 2>&1)
    check 'the confinement refuses a write into the shared temporary directory' test ! -e "$tmp/shared"
    # shellcheck disable=SC2016
    check 'the confinement gives the command a private temporary directory' \
      env HOME="$tmp/home" "$SCRIPT_PATH" exec leonardo --project "$repo" -- 'echo x > "$TMPDIR/own" && test -s "$TMPDIR/own"'
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
    [[ $# -le 2 ]] || { usage; return 64; }
    report_inactive "${2:-unknown}"
    return $?
    ;;
  preflight)
    shift
    run_preflight "$@"
    return $?
    ;;
  review-head)
    shift
    run_review_head "$@"
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
