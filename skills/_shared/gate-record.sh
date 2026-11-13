#!/usr/bin/env bash
# The globals this library sets are read by the script that sources it.
# shellcheck disable=SC2034
# gate-record.sh — shared library for the machine record of a quality-gate run.
#
# Sourced, not executed, by `run-gate.sh` (writes a record) and `verify-gate.sh`
# (accepts or rejects one). Both must agree on where a record lives, what makes
# it valid, and which commands run fresh, so that logic exists here once. The
# command checks and the executor come from project-commands.sh.
#
# TRUST MODEL — what a record proves, and what it does not
#   A record is a LOCAL CACHE on one machine and one account. It is keyed to the
#   git TREE the gate ran on, so a rebase, squash, or amend that keeps the bytes
#   keeps the record, and any other tree has none.
#   - A public commenter cannot write it. Against that threat it is stronger
#     than the textual `Quality gate:` line in a review comment.
#   - The code the gate runs (tests, Composer scripts from the branch) runs under
#     the same account and can write any file, a forged record included. It can
#     do the same today by making its own gate pass. The defence against hostile
#     branch code is code review, required CI, and a fresh `gate-fresh` run —
#     never this record.
#   - The SHA-256 of the log detects a damaged or swapped log. It does not prove
#     that the log is authentic.
#   - A record is valid only on the machine that wrote it. Elsewhere it is
#     missing, and the gate runs again.
#   - The copy of this library, project-commands.sh, run-gate.sh, and
#     verify-gate.sh that runs is the one the installer wrote from
#     vendor/pekral/ai-olympus. A diff over that installed copy is a merge-gate
#     change: Critical under rules/security/general.md *Code Review Application*.
#
# Where a record lives
#   <evidence>/<tree>.<tier>.json   the record
#   <evidence>/<tree>.<tier>.log    the full gate output
#   <machine dir>/ai-olympus-gate.lock/
#                                   the heavy-command lock (an atomic `mkdir`, never
#                                   flock). <machine dir> is `$AI_OLYMPUS_LOCK_DIR`, else
#                                   `${XDG_CACHE_HOME:-$HOME/.cache}/ai-olympus`. It sits
#                                   outside every repository, so every project and every
#                                   worktree of the account shares it and one heavy
#                                   command runs on the machine at a time. The holder
#                                   file names the PID, the process start, the wrapper,
#                                   the project, and the command; `heartbeat` records the
#                                   last progress of the holder. `gate-lock.sh` reads and
#                                   repairs it.
#   <evidence> is the manifest `gate-evidence`, default `.claude/run/gates`. It
#   must be relative, git-ignored, free of tracked files, and free of symlinks.
#   A file name is built only from the tree `git rev-parse` returned and the
#   tier; no value read from a record ever enters a path.
set -euo pipefail

: "${PROG:=${0##*/}}"

GATE_LIB_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=project-commands.sh
. "$GATE_LIB_DIR/project-commands.sh"

GATE_DEFAULT_EVIDENCE='.claude/run/gates'
GATE_REASON=''
GATE_EVIDENCE=''
GATE_TIER_COMMANDS=()
GATE_FRESH_COMMANDS=()
GATE_FRESH_RESULTS='[]'
GATE_FRESH_LOG=''
GATE_LOCK=''
GATE_LOCK_HELD=0
GATE_LOCK_TIMEOUT=3600
GATE_HEARTBEAT_PID=''

gate_tier_key() {
  case "$1" in
  full) printf 'gate' ;;
  pr) printf 'pr-gate' ;;
  *) return 1 ;;
  esac
}

gate_is_tree() {
  [[ "$1" =~ ^[0-9a-f]{40}([0-9a-f]{24})?$ ]]
}

gate_sha256() {
  local file="$1" sum
  if command -v sha256sum >/dev/null 2>&1; then
    sum="$(sha256sum -- "$file" | cut -d' ' -f1)"
  else
    sum="$(shasum -a 256 -- "$file" | cut -d' ' -f1)"
  fi
  [[ "$sum" =~ ^[0-9a-f]{64}$ ]] || return 1
  printf '%s' "$sum"
}

gate_now() {
  date -u +%Y-%m-%dT%H:%M:%SZ
}

# Read the tier's commands and the fresh commands from the default-branch
# manifest (PROJECT_MANIFEST, loaded by load_project_manifest), and validate all
# of them before anything runs.
#   returns 5  the manifest sets no command for this tier, or none of the record
#              keys — the built-in path applies
#   returns 3  a key is malformed or a command is refused (GATE_REASON says which)
gate_load_commands() {
  local tier="$1" key command reason
  key="$(gate_tier_key "$tier")"
  GATE_TIER_COMMANDS=()
  GATE_FRESH_COMMANDS=()

  if ! printf '%s' "$PROJECT_MANIFEST" | jq -e --arg k "$key" '(.[$k] // []) | type == "array" and all(.[]; type == "string")' >/dev/null 2>&1; then
    GATE_REASON="project manifest: $key must be an array of strings"
    return 3
  fi
  if ! printf '%s' "$PROJECT_MANIFEST" | jq -e '(.["gate-fresh"] // []) | type == "array" and all(.[]; type == "string")' >/dev/null 2>&1; then
    GATE_REASON='project manifest: gate-fresh must be an array of strings'
    return 3
  fi

  while IFS= read -r command; do
    GATE_TIER_COMMANDS+=("$command")
  done < <(printf '%s' "$PROJECT_MANIFEST" | jq -r --arg k "$key" '(.[$k] // [])[]')

  if [[ "${#GATE_TIER_COMMANDS[@]}" -eq 0 ]]; then
    GATE_REASON="project manifest sets no $key — the built-in gate path applies"
    return 5
  fi

  # The machine record is opt-in: a project that sets none of the three record
  # keys keeps the built-in gate path exactly as before they existed, even when
  # it already sets `gate`.
  if ! printf '%s' "$PROJECT_MANIFEST" | jq -e 'has("pr-gate") or has("gate-fresh") or has("gate-evidence")' >/dev/null 2>&1; then
    GATE_REASON='project manifest sets none of pr-gate, gate-fresh, gate-evidence — the built-in gate path applies'
    return 5
  fi

  while IFS= read -r command; do
    GATE_FRESH_COMMANDS+=("$command")
  done < <(printf '%s' "$PROJECT_MANIFEST" | jq -r '(.["gate-fresh"] // [])[]')

  # A missing or empty `gate-fresh` is never "audit nothing": the dependency
  # advisory audit keeps running fresh, as it did before the key existed. With
  # no composer.lock there is no Composer dependency set to audit.
  if [[ "${#GATE_FRESH_COMMANDS[@]}" -eq 0 && -f composer.lock ]]; then
    GATE_FRESH_COMMANDS=('composer audit')
  fi

  for command in "${GATE_TIER_COMMANDS[@]}" ${GATE_FRESH_COMMANDS[@]+"${GATE_FRESH_COMMANDS[@]}"}; do
    if ! reason="$(validate_command "$command" 2>&1 >/dev/null)"; then
      GATE_REASON="refused command: $(safe_display "$reason") — $(safe_display "$command")"
      return 3
    fi
  done
  return 0
}

# Refuse a path when any of its components is a symlink. Checked before every
# write and every read, so a link planted in the tree cannot redirect either.
gate_no_symlink() {
  local path="$1" prefix="" part
  local IFS='/'
  for part in $path; do
    [[ -n "$part" ]] || continue
    prefix="${prefix:+$prefix/}$part"
    if [[ -L "$prefix" ]]; then
      GATE_REASON="refused: $(safe_display "$prefix") is a symlink"
      return 3
    fi
  done
  return 0
}

# A relative, git-ignored, untracked path with no symlink in it. Every probe
# must be ignored, so a file the scripts write there never dirties the tree.
#   gate_scratch_path <name> <path> <probe>...
#   <name> is the setting or option that supplied <path>; the refusal names it.
#   returns 3 with GATE_REASON when the path is not acceptable
gate_scratch_path() {
  local name="$1" path="$2" probe
  shift 2
  if [[ ! "$path" =~ ^[A-Za-z0-9._/-]+$ || "$path" == /* || "$path" == '..' || "$path" == ../* || "$path" == */.. || "$path" == */../* ]]; then
    GATE_REASON="refused: $name must be a relative path inside the project — $(safe_display "$path")"
    return 3
  fi

  gate_no_symlink "$path" || return 3

  for probe in "$@"; do
    if ! git check-ignore -q -- "$probe" 2>/dev/null; then
      GATE_REASON="refused: $(safe_display "$path") is not git-ignored — add it to .gitignore"
      return 3
    fi
  done
  if [[ -n "$(git ls-files -- "$path" 2>/dev/null)" ]]; then
    GATE_REASON="refused: $(safe_display "$path") contains a tracked file"
    return 3
  fi
  return 0
}

# Resolve and check the evidence directory. With `write`, create it 0700.
#   returns 3 with GATE_REASON when the directory is not acceptable
gate_evidence_dir() {
  local mode="$1" dir
  if ! printf '%s' "$PROJECT_MANIFEST" | jq -e '(.["gate-evidence"] // "") | type == "string"' >/dev/null 2>&1; then
    GATE_REASON='project manifest: gate-evidence must be a string'
    return 3
  fi
  dir="$(printf '%s' "$PROJECT_MANIFEST" | jq -r '.["gate-evidence"] // ""')"
  [[ -n "$dir" ]] || dir="$GATE_DEFAULT_EVIDENCE"
  dir="${dir%/}"

  # Every path the scripts write must be ignored, so a record never dirties the
  # tree it describes and a branch can never commit one.
  gate_scratch_path gate-evidence "$dir" "$dir/0.full.json" "$dir/0.full.log" || return 3

  if [[ "$mode" == "write" ]]; then
    (umask 077 && mkdir -p -- "$dir")
    gate_no_symlink "$dir" || return 3
    # BSD chmod (macOS) reads a `--` after the mode as a file name. $dir is
    # validated relative above, and the `./` prefix keeps it from reading as an option.
    chmod 700 "./$dir"
  fi

  GATE_EVIDENCE="$dir"
  return 0
}

# The environment the gate's verdict depends on beyond the tree: the PHP
# version, the lockfiles in the working tree (some projects do not track them),
# and the manifest `env`. Hashes and a version string only — never a value of
# any other variable.
gate_fingerprint() {
  local php_version='none' file sum lockfiles='{}' env_sum='none'
  if command -v php >/dev/null 2>&1; then
    php_version="$(php -r 'echo PHP_VERSION;' 2>/dev/null || true)"
    [[ "$php_version" =~ ^[0-9A-Za-z.+-]{1,40}$ ]] || php_version='unknown'
  fi
  for file in composer.lock package-lock.json yarn.lock pnpm-lock.yaml; do
    [[ -f "$file" && ! -L "$file" ]] || continue
    sum="$(gate_sha256 "$file")" || sum='unreadable'
    lockfiles="$(printf '%s' "$lockfiles" | jq -c --arg f "$file" --arg s "$sum" '. + {($f): $s}')"
  done
  if [[ "${#PROJECT_ENV[@]}" -gt 0 ]]; then
    env_sum="$(printf '%s\n' "${PROJECT_ENV[@]}" | gate_sha256 -)" || env_sum='unreadable'
  fi
  jq -cnS --arg php "$php_version" --argjson lockfiles "$lockfiles" --arg env "$env_sum" \
    '{php: $php, lockfiles: $lockfiles, manifest_env: $env}'
}

# The one acceptance test for a record. `verify-gate.sh` calls it, and so does
# `run-gate.sh` before it takes a finished run's result over — a second test
# would be a second definition of "valid".
#   returns 0 valid, 10 missing, 11 failed, 12 stale or unusable; GATE_REASON says why
gate_check_record() {
  local tier="$1" tree="$2" record log expected_commands fingerprint field
  record="$GATE_EVIDENCE/$tree.$tier.json"
  log="$GATE_EVIDENCE/$tree.$tier.log"

  gate_no_symlink "$record" || return 12
  gate_no_symlink "$log" || return 12

  if [[ ! -e "$record" ]]; then
    GATE_REASON="missing: no $tier record for tree $tree"
    return 10
  fi

  # Fail closed: every field is read through jq by a fixed name and checked
  # against its shape. Nothing in a record is sourced, evaluated, or executed.
  for field in \
    '.schema == 1' \
    '.tier | type == "string"' \
    '.tree | type == "string" and test("^[0-9a-f]{40}([0-9a-f]{24})?$")' \
    '.head | type == "string" and test("^[0-9a-f]{40}([0-9a-f]{24})?$")' \
    '.merge_base | type == "string" and test("^([0-9a-f]{40}([0-9a-f]{24})?)?$")' \
    '.commands | type == "array" and length > 0 and all(.[]; (.command | type == "string") and (.exit_code | type == "number") and (.duration_seconds | type == "number"))' \
    '.environment | type == "object"' \
    '.actor | type == "string" and test("^[A-Za-z0-9._-]{1,64}$")' \
    '.started_at | type == "string" and test("^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$")' \
    '.finished_at | type == "string" and test("^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}Z$")' \
    '.duration_seconds | type == "number"' \
    '.exit_code | type == "number"' \
    '.log_sha256 | type == "string" and test("^[0-9a-f]{64}$")'; do
    if ! jq -e "$field" "$record" >/dev/null 2>&1; then
      GATE_REASON="stale: the record is unreadable or fails the check $field"
      return 12
    fi
  done

  if ! jq -e --arg tier "$tier" --arg tree "$tree" '.tier == $tier and .tree == $tree' "$record" >/dev/null 2>&1; then
    GATE_REASON="stale: the record does not describe the $tier gate on tree $tree"
    return 12
  fi

  # A failed run stops at the first failing command, so its record lists only a
  # prefix of the commands. That prefix is still this gate: the exit_code check
  # below then reports the record as failed, never as stale. A passing record
  # must list every command.
  expected_commands="$(printf '%s\n' "${GATE_TIER_COMMANDS[@]}" | jq -Rsc 'split("\n") | map(select(length > 0))')"
  if ! jq -e --argjson c "$expected_commands" \
    '[.commands[].command] as $r | $r == $c or (.exit_code != 0 and ($r | length) < ($c | length) and $r == $c[0:($r | length)])' \
    "$record" >/dev/null 2>&1; then
    GATE_REASON='stale: the gate commands differ from the default-branch manifest'
    return 12
  fi

  fingerprint="$(gate_fingerprint)"
  if ! jq -e --argjson f "$fingerprint" '.environment == $f' "$record" >/dev/null 2>&1; then
    GATE_REASON='stale: the environment fingerprint differs (PHP version, a lockfile, or the manifest env)'
    return 12
  fi

  if [[ ! -f "$log" ]] || ! jq -e --arg s "$(gate_sha256 "$log" || true)" '.log_sha256 == $s' "$record" >/dev/null 2>&1; then
    GATE_REASON='stale: the log is missing or does not match the hash in the record'
    return 12
  fi

  if ! jq -e '.exit_code == 0 and all(.commands[]; .exit_code == 0)' "$record" >/dev/null 2>&1; then
    GATE_REASON="failed: the recorded $tier gate on tree $tree did not pass"
    return 11
  fi

  GATE_REASON="valid: the $tier gate passed on tree $tree"
  return 0
}

# Run every `gate-fresh` command now. Their verdict depends on when they run
# (an advisory database changes), so it is never written to a record and never
# read from one.
#   returns 0 every fresh command passed, 11 one failed
gate_run_fresh() {
  local tier="$1" tree="$2" command status tmp
  GATE_FRESH_RESULTS='[]'
  GATE_FRESH_LOG=''
  [[ "${#GATE_FRESH_COMMANDS[@]}" -gt 0 ]] || return 0

  GATE_FRESH_LOG="$GATE_EVIDENCE/$tree.$tier.fresh.log"
  gate_no_symlink "$GATE_FRESH_LOG" || return 11
  tmp="$(umask 077 && mktemp "$GATE_EVIDENCE/.fresh.XXXXXX")"
  GATE_TEMP_FILES+=("$tmp")

  local failed=0
  for command in "${GATE_FRESH_COMMANDS[@]}"; do
    printf '### %s\n' "$command" >>"$tmp"
    status=0
    run_command "$command" "$tmp" || status=$?
    printf '### exit %s\n' "$status" >>"$tmp"
    GATE_FRESH_RESULTS="$(printf '%s' "$GATE_FRESH_RESULTS" | jq -c --arg c "$command" --argjson e "$status" '. + [{command: $c, exit_code: $e}]')"
    if [[ "$status" -ne 0 && "$failed" -eq 0 ]]; then
      failed=1
      GATE_REASON="failed: gate-fresh command exited $status — $(safe_display "$command")"
    fi
  done
  mv -f -- "$tmp" "$GATE_FRESH_LOG"

  [[ "$failed" -eq 0 ]] || return 11
  return 0
}

GATE_TEMP_FILES=()

gate_cleanup() {
  local file
  for file in ${GATE_TEMP_FILES[@]+"${GATE_TEMP_FILES[@]}"}; do
    rm -f -- "$file"
  done
  if [[ -n "$GATE_HEARTBEAT_PID" ]]; then
    kill "$GATE_HEARTBEAT_PID" 2>/dev/null || true
    wait "$GATE_HEARTBEAT_PID" 2>/dev/null || true
    GATE_HEARTBEAT_PID=''
  fi
  if [[ "$GATE_LOCK_HELD" -eq 1 && -n "$GATE_LOCK" ]]; then
    rm -rf -- "$GATE_LOCK"
    GATE_LOCK_HELD=0
  fi
  return 0
}

# `ps -o etime=` prints [[dd-]hh:]mm:ss.
gate_etime_seconds() {
  local etime="${1//[[:space:]]/}"
  [[ "$etime" =~ ^(([0-9]+)-)?(([0-9]+):)?([0-9]+):([0-9]+)$ ]] || return 1
  printf '%s' $((10#${BASH_REMATCH[2]:-0} * 86400 + 10#${BASH_REMATCH[4]:-0} * 3600 + 10#${BASH_REMATCH[5]} * 60 + 10#${BASH_REMATCH[6]}))
}

# The start of a process as an epoch, from `ps -o etime=`, which macOS and Linux both print.
#   returns 1 when ps cannot read the process
gate_process_start() {
  local etime elapsed
  etime="$(ps -o etime= -p "$1" 2>/dev/null || true)"
  elapsed="$(gate_etime_seconds "$etime")" || return 1
  printf '%s' $(($(date +%s) - elapsed))
}

# One tolerance for "this process started when the holder file says": `ps -o etime=` counts whole
# seconds, so two reads of one process can differ by a second or two.
gate_same_start() {
  [[ $(($1 - $2)) -le 5 && $(($2 - $1)) -le 5 ]]
}

# A zombie waits only for its parent to reap it; it holds no lock and runs no gate.
gate_pid_zombie() {
  [[ "$(ps -o stat= -p "$1" 2>/dev/null | tr -d ' ')" == Z* ]]
}

# The process exists and is not a zombie.
gate_pid_running() {
  kill -0 "$1" 2>/dev/null && ! gate_pid_zombie "$1"
}

# What the holder file says about the command: the wrapper sets GATE_HOLDER_COMMAND, and a
# library caller that sets none is named by its program.
gate_holder_command() {
  safe_display "${GATE_HOLDER_COMMAND:-$PROG}"
}

# The project a run belongs to, as the holder file records it: the toplevel of the git checkout,
# else the directory.
gate_project_path() {
  safe_display "$(git rev-parse --show-toplevel 2>/dev/null || pwd -P)"
}

# The holder file of a lock directory: this script's own PID and start time, the process
# start that lets a repair prove the PID is still this wrapper, the wrapper's name, the
# project it runs for, and the command it runs. Every value is one printable line.
gate_holder_text() {
  local start project
  start="$(gate_process_start "$$" 2>/dev/null || true)"
  project="$(gate_project_path)"
  printf 'PID=%s\nSTARTED=%s\nSTARTED_EPOCH=%s\n' "$$" "$(gate_now)" "$(date +%s)"
  [[ -z "$start" ]] || printf 'PROCESS_START=%s\n' "$start"
  printf 'WRAPPER=%s\nPROJECT=%s\nCOMMAND=%s\n' "$(safe_display "$PROG")" "$project" "$(gate_holder_command)"
}

# One field of a holder or heartbeat file, read once and silently. Prints nothing when the file
# or the field is missing, so a caller treats the value as unknown.
gate_file_field() {
  local file="$1" key="$2"
  [[ -f "$file" && ! -L "$file" ]] || return 0
  sed -n "/^$key=/{s/^$key=//p;q;}" "$file" 2>/dev/null || true
}

# The holder of a lock directory (the gate lock unless the caller names another)
# is gone when its PID is confirmed dead, or when the PID now belongs to a
# process that started after the lock was written (recycled). Anything
# inconclusive counts as a live holder: reclaiming a live run's lock would let
# two gates corrupt one test database. The holder is read once and silently: a
# holder that its run removed after the -f test, or that cannot be read, is
# inconclusive, and the probe writes nothing to the caller's output.
gate_lock_is_stale() {
  local dir="${1:-$GATE_LOCK}" holder text pid started probe start
  holder="$dir/holder"
  [[ -f "$holder" && ! -L "$holder" ]] || return 1
  text="$(cat -- "$holder" 2>/dev/null)" || return 1
  pid="$(sed -n '/^PID=[0-9]\{1,7\}$/{s/^PID=//p;q;}' <<<"$text")"
  started="$(sed -n '/^STARTED_EPOCH=[0-9]\{1,12\}$/{s/^STARTED_EPOCH=//p;q;}' <<<"$text")"
  [[ -n "$pid" ]] || return 1

  if ! probe="$(LC_ALL=C kill -0 "$pid" 2>&1)"; then
    [[ "$probe" == *'not permitted'* ]] && return 1
    return 0
  fi

  # A zombie holds no lock and runs no gate.
  ! gate_pid_zombie "$pid" || return 0

  [[ -n "$started" ]] || return 1
  start="$(gate_process_start "$pid")" || return 1
  # A process cannot have written a timestamp before it existed; 60 s absorbs skew.
  [[ "$start" -gt $((started + 60)) ]]
}

# The reclaim directory of a waiter is stale when its holder is confirmed dead,
# never because of its age: a waiter stopped inside the reclaim (SIGSTOP, a
# sleeping laptop) is alive, and clearing its directory would let a second waiter
# reclaim the lock beside it. The one window left is between the `mkdir` and the
# holder file: a directory with no readable PID counts as live for one minute
# (its mtime), then belongs to a waiter killed in that window and is stale. The
# main lock has no such bound, because its holder is written right after the
# `mkdir` of a lock a run holds for minutes; a reclaim directory must not block
# every later reclaim for ever.
gate_reclaim_is_stale() {
  local dir="$GATE_LOCK.reclaim"
  [[ -d "$dir" && ! -L "$dir" ]] || return 1
  if [[ -f "$dir/holder" && ! -L "$dir/holder" ]] && grep -q '^PID=[0-9]\{1,7\}$' "$dir/holder" 2>/dev/null; then
    gate_lock_is_stale "$dir"
    return
  fi
  gate_lock_headless_aged "$dir"
}

# The directory of the machine-wide lock: outside every repository, so every project and every
# worktree of the account contends on the one lock. `AI_OLYMPUS_LOCK_DIR` (an absolute path) moves
# it, which is how a self-test keeps its fixtures off the real lock. The directory is created
# 0700, must be owned by the caller, and is never a symlink. An existing directory loses every
# group and other permission: the holder file in it decides which process a repair stops, so no
# other account may write there. The owner bits stay as they are.
#   returns 1 when no usable directory exists
gate_machine_dir() {
  local base
  if [[ -n "${AI_OLYMPUS_LOCK_DIR:-}" ]]; then
    base="$AI_OLYMPUS_LOCK_DIR"
  elif [[ -n "${XDG_CACHE_HOME:-}" && "$XDG_CACHE_HOME" == /* ]]; then
    base="$XDG_CACHE_HOME/ai-olympus"
  elif [[ -n "${HOME:-}" && "$HOME" == /* ]]; then
    base="$HOME/.cache/ai-olympus"
  else
    return 1
  fi
  [[ "$base" == /* && ! -L "$base" ]] || return 1
  (umask 077 && mkdir -p -- "$base") 2>/dev/null || return 1
  base="$(cd "$base" 2>/dev/null && pwd -P)" || return 1
  [[ -O "$base" ]] || return 1
  # $base is absolute, so BSD chmod never reads it as an option.
  chmod go-rwx "$base" 2>/dev/null || return 1
  printf '%s' "$base"
}

# The one heavy-command lock of the machine: `ai-olympus-gate.lock` in the machine directory.
#   returns 1 when the machine directory is not usable
gate_lock_path() {
  local base
  base="$(gate_machine_dir)" || return 1
  printf '%s/ai-olympus-gate.lock' "$base"
}

# Whole seconds from a named environment value; the default when it is unset or malformed.
gate_seconds_setting() {
  local value="${!1:-}"
  if [[ "$value" =~ ^[0-9]{1,6}$ && "$((10#$value))" -ge 1 ]]; then
    printf '%s' "$((10#$value))"
  else
    printf '%s' "$2"
  fi
}

# How long a holder may show no progress before it counts as stuck, and how often it reports.
gate_stuck_after() { gate_seconds_setting AI_OLYMPUS_GATE_STUCK_AFTER 1800; }
gate_heartbeat_interval() { gate_seconds_setting AI_OLYMPUS_GATE_HEARTBEAT_INTERVAL 30; }

# The descendants of a process, one `<pid> <cpu centiseconds> <elapsed seconds>` line each. The
# elapsed time comes from the same `ps` listing as the PID, so a caller that derives a start time
# from it never pairs a PID with the start of a process that took the PID after the listing.
# The process itself and the subtree of <exclude> are left out, so the heartbeat never counts its
# own work as progress. `ps -A -o pid=,ppid=,time=,etime=` prints the same columns on macOS and
# Linux; `time` is [[dd-]hh:]mm:ss[.cc] and `etime` is [[dd-]hh:]mm:ss.
gate_descendants() {
  ps -A -o pid=,ppid=,time=,etime= 2>/dev/null | awk -v root="$1" -v ex="${2:-0}" '
    function centis(t,   d, n, a, i, v, p) {
      d = 0
      if (index(t, "-")) { split(t, p, "-"); d = p[1]; t = p[2] }
      n = split(t, a, ":"); v = 0
      for (i = 1; i <= n; i++) v = v * 60 + a[i]
      return (d * 86400 + v) * 100
    }
    { pid[NR] = $1; par[NR] = $2; cpu[NR] = centis($3); ela[NR] = centis($4) / 100; n = NR }
    END {
      inset[root] = 1
      if (ex > 0) excluded[ex] = 1
      do {
        changed = 0
        for (i = 1; i <= n; i++) {
          if ((par[i] in excluded) && !(pid[i] in excluded)) { excluded[pid[i]] = 1; changed = 1 }
          if ((par[i] in inset) && !(pid[i] in inset) && !(pid[i] in excluded)) { inset[pid[i]] = 1; changed = 1 }
        }
      } while (changed)
      for (i = 1; i <= n; i++) if ((pid[i] in inset) && pid[i] != root) printf "%s %.0f %.0f\n", pid[i], cpu[i], ela[i]
    }'
}

# What "making progress" means for a holder: the CPU its process tree has used, and the set of
# processes in the tree. A hung test, or a process waiting on input, changes neither.
# Prints `<cpu> <signature>`.
gate_progress_sample() {
  local tree cpu sig
  tree="$(gate_descendants "$1" "${2:-0}")"
  cpu="$(printf '%s\n' "$tree" | awk 'NF { s += $2 } END { printf "%.0f", s + 0 }')"
  sig="$(printf '%s\n' "$tree" | awk 'NF { print $1 }' | sort -n | cksum | cut -d' ' -f1)"
  printf '%s %s' "$cpu" "$sig"
}

# Seconds since the holder last made progress; the heartbeat file records it, and a holder that
# has not reported yet counts from its own start. Returns 1 when neither is readable.
gate_heartbeat_age() {
  local dir="${1:-$GATE_LOCK}" beat
  beat="$(gate_file_field "$dir/heartbeat" HEARTBEAT_EPOCH)"
  [[ "$beat" =~ ^[0-9]{1,12}$ ]] || beat="$(gate_file_field "$dir/holder" STARTED_EPOCH)"
  [[ "$beat" =~ ^[0-9]{1,12}$ ]] || return 1
  printf '%s' $(($(date +%s) - beat))
}

# A lock directory with no readable holder for more than one minute: its run was killed between
# the `mkdir` and the holder file. The one-minute rule is the one gate_reclaim_is_stale applies to
# a reclaim directory. A waiter never acts on it (the main lock stays live for a waiter, as before);
# only `gate-lock.sh repair` does.
gate_lock_headless_aged() {
  local dir="${1:-$GATE_LOCK}"
  [[ -d "$dir" && ! -L "$dir" ]] || return 1
  [[ ! "$(gate_file_field "$dir/holder" PID)" =~ ^[0-9]{1,7}$ ]] || return 1
  [[ -n "$(find "$dir" -maxdepth 0 -type d -mmin +1 2>/dev/null)" ]]
}

# The state of a lock directory:
#   free      there is no lock
#   dead      the holder is confirmed dead or its PID was recycled (gate_lock_is_stale), or the lock
#             has had no readable holder for more than a minute (gate_lock_headless_aged)
#   stuck     the holder is alive, started by a wrapper of this package, and has shown no progress
#             for longer than gate_stuck_after
#   held      a live holder; a holder file of an older format has no heartbeat and never counts as stuck
#   unproven  a lock directory without a readable holder for less than a minute: a run is between
#             its mkdir and its holder file, or the lock is unreadable. It counts as live.
gate_lock_state() {
  local dir="${1:-$GATE_LOCK}" age
  [[ -d "$dir" ]] || { printf 'free'; return 0; }
  if [[ ! -f "$dir/holder" || -L "$dir/holder" || ! "$(gate_file_field "$dir/holder" PID)" =~ ^[0-9]{1,7}$ ]]; then
    if gate_lock_headless_aged "$dir"; then printf 'dead'; else printf 'unproven'; fi
    return 0
  fi
  if gate_lock_is_stale "$dir"; then
    printf 'dead'
    return 0
  fi
  if [[ "$(gate_file_field "$dir/holder" PROCESS_START)" =~ ^[0-9]{9,12}$ ]] && age="$(gate_heartbeat_age "$dir")" && [[ "$age" -gt "$(gate_stuck_after)" ]]; then
    printf 'stuck'
    return 0
  fi
  printf 'held'
}

# One line naming the holder of a lock directory, for the run that waits for it.
gate_lock_describe() {
  local dir="${1:-$GATE_LOCK}" holder_file age=''
  holder_file="$dir/holder"
  age="$(gate_heartbeat_age "$dir" 2>/dev/null || true)"
  printf 'PID %s, project %s, command %s, started %s' \
    "$(gate_file_field "$holder_file" PID)" "$(gate_file_field "$holder_file" PROJECT)" \
    "$(gate_file_field "$holder_file" COMMAND)" "$(gate_file_field "$holder_file" STARTED)"
  [[ -z "$age" ]] || printf ', last progress %ss ago' "$age"
}

gate_lock_reclaimable() {
  gate_lock_is_stale || { [[ "${1:-}" == headless ]] && gate_lock_headless_aged; }
}

# Remove a stale lock directory when this call may. Only one waiter reclaims, and it checks again
# under the reclaim lock: a waiter that read the dead holder earlier then does not remove the live
# lock that another waiter has taken in the meantime. The reclaim lock carries its own holder file
# and is cleared only when that holder is confirmed dead (gate_reclaim_is_stale).
# One race stays open: mkdir has no compare-and-delete and flock(1) does not exist
# on macOS. A waiter that probed a dead reclaim directory and stalls before it
# removes it can remove a newer waiter's live one. Two gates then run at once only
# when that newer waiter also stalls between its second probe and its removal of
# the gate lock: the race needs a dead gate holder, a dead reclaimer, and two
# stalled waiters.
# `headless` as the first argument also reclaims a lock with no holder for over a minute; repair
# passes it, a waiter never does.
#   returns 0 when this call took the reclaim lock (the lock may now be gone), 1 otherwise
gate_lock_reclaim_stale() {
  local scope="${1:-}"
  if gate_reclaim_is_stale; then
    rm -rf -- "$GATE_LOCK.reclaim"
  fi
  if gate_lock_reclaimable "$scope" && mkdir -- "$GATE_LOCK.reclaim" 2>/dev/null; then
    # No-clobber: when a stall let another waiter clear this directory and take
    # its own, only the first holder file stands and the other waiter backs off.
    if (set -C && gate_holder_text >"$GATE_LOCK.reclaim/holder") 2>/dev/null; then
      if gate_lock_reclaimable "$scope"; then
        rm -rf -- "$GATE_LOCK"
      fi
      rm -rf -- "$GATE_LOCK.reclaim"
    fi
    return 0
  fi
  return 1
}

# Take the machine lock: an atomic `mkdir`, a holder file, a heartbeat, and a stale reclaim on
# confirmed death only (@rules/compound-engineering/concurrency.md). `flock(1)` does not exist on
# macOS, so it is not used. The lock is shared by every project and worktree of the account and by
# every heavy command, and a caller waits for a live holder up to <timeout> seconds. While it
# waits, it says so and names the holder. A holder that shows no progress is reported and never
# reclaimed here: its process is alive, so only `gate-lock.sh repair` may stop it.
# A command that a holder started inherits AI_OLYMPUS_LOCK_HELD_BY and runs under that lock
# instead of waiting for it, so a heavy command that starts another never deadlocks.
#   returns 6 when a live holder keeps the lock past the timeout
gate_lock_acquire() {
  local timeout="$1" waited=0 held_by
  GATE_LOCK="$(gate_lock_path)" || { GATE_REASON='refused: no usable machine lock directory (AI_OLYMPUS_LOCK_DIR, XDG_CACHE_HOME, or HOME)'; return 3; }
  if [[ -L "$GATE_LOCK" ]]; then
    GATE_REASON="refused: $(safe_display "$GATE_LOCK") is a symlink"
    return 3
  fi

  held_by="${AI_OLYMPUS_LOCK_HELD_BY:-}"
  if [[ "$held_by" =~ ^[0-9]{1,7}$ && "$(gate_file_field "$GATE_LOCK/holder" PID)" == "$held_by" ]] && kill -0 "$held_by" 2>/dev/null; then
    return 0
  fi

  GATE_HOLDER_COMMAND="${GATE_HOLDER_COMMAND:-$PROG}"
  until (umask 077 && mkdir -- "$GATE_LOCK") 2>/dev/null; do
    if gate_lock_reclaim_stale; then
      continue
    fi
    if [[ "$waited" -ge "$timeout" ]]; then
      GATE_REASON="locked: the machine lock is held by $(gate_lock_describe "$GATE_LOCK") — it was not reclaimed because its holder is alive or unproven dead"
      [[ "$(gate_lock_state "$GATE_LOCK")" != stuck ]] || GATE_REASON="$GATE_REASON; the holder shows no progress — krang repairs it with gate-lock.sh repair"
      return 6
    fi
    if [[ $((waited % 60)) -eq 0 ]]; then
      echo "$PROG: waiting for the machine lock, held by $(gate_lock_describe "$GATE_LOCK")" >&2
    fi
    sleep 1
    waited=$((waited + 1))
  done

  GATE_LOCK_HELD=1
  # This script's own PID: the script, not a per-call subshell, holds the lock
  # for exactly as long as the run lasts.
  # A temporary file and a rename: a reader sees the whole holder file or none, so a lock without
  # a holder past a minute means the run died between the `mkdir` and this line.
  gate_holder_text >"$GATE_LOCK/.holder.$$"
  mv -f -- "$GATE_LOCK/.holder.$$" "$GATE_LOCK/holder"
  export AI_OLYMPUS_LOCK_HELD_BY="$$"
  gate_heartbeat_start
  return 0
}

# The wait limit of every caller of the machine lock: AI_OLYMPUS_GATE_LOCK_TIMEOUT, default 3600,
# in GATE_LOCK_TIMEOUT.
#   returns 1 with GATE_REASON when the value is not whole seconds
gate_lock_timeout() {
  GATE_LOCK_TIMEOUT="${AI_OLYMPUS_GATE_LOCK_TIMEOUT:-3600}"
  [[ "$GATE_LOCK_TIMEOUT" =~ ^[0-9]{1,6}$ ]] && return 0
  GATE_REASON='AI_OLYMPUS_GATE_LOCK_TIMEOUT must be whole seconds'
  return 1
}

# The one entry into the machine lock of run-gate.sh and run-validation.sh: the cleanup traps
# that release the lock however the script ends, then the wait for the lock.
#   returns what gate_lock_acquire returns
gate_lock_enter() {
  trap gate_cleanup EXIT
  trap 'exit 130' INT
  trap 'exit 143' TERM
  gate_lock_acquire "$1"
}

# Start the progress reporter of the holder. It stays silent, holds none of the caller's
# descriptors, and ends when the holder does.
gate_heartbeat_start() {
  bash "$GATE_LIB_DIR/gate-lock.sh" heartbeat "$$" "$GATE_LOCK" </dev/null >/dev/null 2>&1 &
  GATE_HEARTBEAT_PID=$!
}

gate_tree_of() {
  local tree
  tree="$(git rev-parse --verify --quiet "$1^{tree}" 2>/dev/null)" || return 1
  gate_is_tree "$tree" || return 1
  printf '%s' "$tree"
}

# --- self-test fixtures, shared by both scripts' --self-test ------------------

# A throwaway project: a git repository whose default branch carries the given
# manifest, with stub tools in vendor/bin that append their name to
# $GATE_SELFTEST_CALLS so a test can count what ran.
gate_selftest_project() {
  local dir="$1" manifest="$2"
  mkdir -p "$dir/vendor/bin"
  cat >"$dir/vendor/bin/gate-ok" <<'STUB'
#!/usr/bin/env bash
echo "gate-ok ${*:-}" >>"$GATE_SELFTEST_CALLS"
[[ -n "${GATE_SELFTEST_SLEEP:-}" ]] && sleep "$GATE_SELFTEST_SLEEP"
echo "gate output"
exit 0
STUB
  cat >"$dir/vendor/bin/gate-span" <<'STUB'
#!/usr/bin/env bash
echo "span-start" >>"$GATE_SELFTEST_CALLS"
sleep "${GATE_SELFTEST_SLEEP:-1}"
echo "span-end" >>"$GATE_SELFTEST_CALLS"
STUB
  cat >"$dir/vendor/bin/gate-hang" <<'STUB'
#!/usr/bin/env bash
echo "gate-hang" >>"$GATE_SELFTEST_CALLS"
sleep 300
STUB
  cat >"$dir/vendor/bin/gate-busy" <<'STUB'
#!/usr/bin/env bash
echo "gate-busy" >>"$GATE_SELFTEST_CALLS"
end=$((SECONDS + ${GATE_SELFTEST_SLEEP:-5}))
while [[ "$SECONDS" -lt "$end" ]]; do :; done
STUB
  cat >"$dir/vendor/bin/gate-fail" <<'STUB'
#!/usr/bin/env bash
echo "gate-fail" >>"$GATE_SELFTEST_CALLS"
exit 3
STUB
  cat >"$dir/vendor/bin/gate-dirty" <<'STUB'
#!/usr/bin/env bash
echo "gate-dirty" >>"$GATE_SELFTEST_CALLS"
echo "changed" >>tracked.txt
STUB
  cat >"$dir/vendor/bin/gate-kill" <<'STUB'
#!/usr/bin/env bash
echo "gate-kill" >>"$GATE_SELFTEST_CALLS"
kill -TERM "$PPID"
STUB
  cat >"$dir/vendor/bin/fresh-ok" <<'STUB'
#!/usr/bin/env bash
echo "fresh-ok" >>"$GATE_SELFTEST_CALLS"
STUB
  cat >"$dir/vendor/bin/fresh-fail" <<'STUB'
#!/usr/bin/env bash
echo "fresh-fail" >>"$GATE_SELFTEST_CALLS"
exit 1
STUB
  cat >"$dir/vendor/bin/pwn" <<'STUB'
#!/usr/bin/env bash
touch pwned
STUB
  chmod +x "$dir"/vendor/bin/*
  printf '/.claude/run/\n/elsewhere/\n/composer.lock\n' >"$dir/.gitignore"
  printf 'tracked\n' >"$dir/tracked.txt"
  printf '%s\n' "$manifest" >"$dir/composer.json"
  git -C "$dir" init -q
  git -C "$dir" add -A
  git -C "$dir" -c user.name=t -c user.email=t@t commit -q -m init
  git -C "$dir" update-ref refs/remotes/origin/master HEAD
}

# How many stub calls in $GATE_SELFTEST_CALLS start with $1; 0 when no stub ran.
gate_selftest_calls() {
  if [[ -f "$GATE_SELFTEST_CALLS" ]]; then grep -c "^$1" "$GATE_SELFTEST_CALLS" || true; else printf '0'; fi
}

# Replace the default-branch manifest without touching the working tree.
gate_selftest_manifest() {
  local dir="$1" manifest="$2" blob tree commit index
  index="$dir/.git/selftest-index"
  blob="$(printf '%s\n' "$manifest" | git -C "$dir" hash-object -w --stdin)"
  GIT_INDEX_FILE="$index" git -C "$dir" read-tree origin/master
  GIT_INDEX_FILE="$index" git -C "$dir" update-index --cacheinfo "100644,$blob,composer.json"
  tree="$(GIT_INDEX_FILE="$index" git -C "$dir" write-tree)"
  rm -f "$index"
  commit="$(git -C "$dir" -c user.name=t -c user.email=t@t commit-tree "$tree" -p origin/master -m manifest)"
  git -C "$dir" update-ref refs/remotes/origin/master "$commit"
}

# GNU first: GNU `stat -f` reports the file system and succeeds, so it would
# hide the fallback. BSD `stat -c` fails, so the BSD form then runs.
gate_selftest_mode() {
  stat -c %a "$1" 2>/dev/null || stat -f %Lp "$1"
}
