#!/usr/bin/env bash
# gate-lock.sh — read and repair the machine-wide heavy-command lock.
#
# Why this exists
#   One lock, `ai-olympus-gate.lock` in the machine directory (gate-record.sh `gate_machine_dir`),
#   serialises every heavy command of the account: the gate, a dependency install, a full test
#   suite, and the targeted checks of run-validation.sh. A waiter reclaims the lock of a dead
#   holder by itself. A holder that is alive and stuck (a hung test, a process that waits on
#   input) keeps the lock until every waiter times out. This script finds that holder and repairs
#   the lock, and `krang` runs it (skills/_shared/orchestration/stale-lock-repair.md).
#
# Usage
#   gate-lock.sh status
#   gate-lock.sh repair --audit <abs .audit path> [--role <role>]
#   gate-lock.sh heartbeat <holder pid> <lock dir>        (internal: started by the lock holder)
#   gate-lock.sh --self-test
#
# The state of the lock (`status`, and the `state` field of `repair`)
#   free      there is no lock
#   held      a live holder that shows progress
#   dead      the holder PID is confirmed dead or was recycled: a waiter reclaims it as well
#   stuck     the holder is alive, a wrapper of this package started it, and its heartbeat records
#             no progress for longer than AI_OLYMPUS_GATE_STUCK_AFTER seconds (default 1800)
#   unproven  a lock directory with no readable holder; it counts as live and is never touched
#
# Heartbeat and progress
#   The holder runs `heartbeat` beside the command, every AI_OLYMPUS_GATE_HEARTBEAT_INTERVAL
#   seconds (default 30). A sample is the CPU the holder's process tree has used and the set of
#   processes in the tree. The `heartbeat` file in the lock directory names the last time the
#   sample changed, so a hung command stops refreshing it while the reporter itself stays alive.
#
# Repair — what it may stop
#   A dead holder: repair removes the lock and stops nothing.
#   A stuck holder: repair stops one process tree, and only after it proves all of these:
#     1. the holder file names a wrapper of this package (run-gate.sh or run-validation.sh);
#     2. the live process at the recorded PID runs that wrapper, by its command line;
#     3. its start time equals the recorded PROCESS_START (a recycled PID fails here);
#     4. two samples, AI_OLYMPUS_GATE_PROBE seconds apart (default 5), show no progress, and
#        neither differs from the last sample the heartbeat recorded.
#   The tree is the verified wrapper and its descendants, found by walking parent PIDs. Repair
#   sends SIGTERM, waits AI_OLYMPUS_GATE_REPAIR_GRACE seconds (default 10), then sends SIGKILL only
#   to a process whose start time still equals the one it recorded. It stops no other process.
#   A holder it cannot prove (an older holder file, another program at that PID) is left running
#   and reported as refused.
#   Repair needs an audit ledger (--audit, or AUDIT_TRAIL_FILE) and writes one line there through
#   audit-trail.sh: an `external-write` line for a lock it released, a `note` line for a refusal.
#
# Output (stdout): one JSON document.
#   status:  {state, lock, pid, project, command, wrapper, started, heartbeat_age_seconds,
#             stuck_after_seconds, wrapper_verified}
#   repair:  {action, state, reason, pid, project, command, stopped, released, audit}
#   action is one of none (free), none-live (a live holder shows progress), released-dead,
#   stopped-and-released, refused.
#
# Exit codes
#   0   status: free, held, or unproven. repair: nothing to repair, or repaired.
#   1   usage error, or repair without a usable audit ledger
#   2   a required tool is missing
#   3   repair refused: the stuck holder is not a wrapper this package started; nothing was stopped
#   4   repair could not stop every process of the tree
#   10  status: the holder is dead
#   11  status: the holder is stuck
set -euo pipefail

PROG="${0##*/}"
# shellcheck source=gate-record.sh
. "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/gate-record.sh"

usage() {
  cat >&2 <<'EOF'
Usage: gate-lock.sh status
       gate-lock.sh repair --audit <abs .audit path> [--role <role>]
       gate-lock.sh --self-test

status prints the state of the machine lock. repair releases a lock whose holder is dead, and
stops the process tree of a stuck holder only when a wrapper of this package started it.
EOF
}

# Progress reporter of a lock holder. Writes `heartbeat` in the lock directory until the holder
# or the lock is gone.
cmd_heartbeat() {
  local parent="${1:-}" dir="${2:-}" interval sleeper='' last='' progress now sample tmp
  [[ "$parent" =~ ^[0-9]{1,7}$ && "$dir" == /* ]] || return 1
  interval="$(gate_heartbeat_interval)"
  trap 'kill "$sleeper" 2>/dev/null; exit 0' TERM
  progress="$(date +%s)"
  while kill -0 "$parent" 2>/dev/null && [[ "$(gate_file_field "$dir/holder" PID)" == "$parent" ]]; do
    sample="$(gate_progress_sample "$parent" "$$")"
    now="$(date +%s)"
    if [[ "$sample" != "$last" ]]; then
      last="$sample"
      progress="$now"
    fi
    tmp="$dir/.heartbeat.$$"
    if printf 'HEARTBEAT_EPOCH=%s\nSAMPLED_EPOCH=%s\nPROGRESS_CPU=%s\nPROGRESS_SIG=%s\nHEARTBEAT_PID=%s\n' \
      "$progress" "$now" "${sample%% *}" "${sample##* }" "$$" >"$tmp" 2>/dev/null; then
      mv -f -- "$tmp" "$dir/heartbeat" 2>/dev/null || rm -f -- "$tmp"
    fi
    sleep "$interval" &
    sleeper=$!
    wait "$sleeper" || true
  done
}

# The holder is a process a wrapper of this package started: the holder file names the wrapper,
# the live process at the recorded PID runs it, and the process start equals the recorded one.
gate_holder_verified() {
  local dir="${1:-$GATE_LOCK}" pid wrapper recorded command actual
  pid="$(gate_file_field "$dir/holder" PID)"
  wrapper="$(gate_file_field "$dir/holder" WRAPPER)"
  recorded="$(gate_file_field "$dir/holder" PROCESS_START)"
  [[ "$pid" =~ ^[0-9]{1,7}$ && "$recorded" =~ ^[0-9]{9,12}$ ]] || return 1
  [[ "$wrapper" == run-gate.sh || "$wrapper" == run-validation.sh ]] || return 1
  command="$(ps -o command= -p "$pid" 2>/dev/null || true)"
  [[ "$command" == *"$wrapper"* ]] || return 1
  actual="$(gate_process_start "$pid")" || return 1
  [[ $((actual - recorded)) -le 5 && $((recorded - actual)) -le 5 ]]
}

emit_status() {
  local state="$1" dir="$2" verified=false age
  if [[ "$state" == stuck || "$state" == held ]] && gate_holder_verified "$dir"; then
    verified=true
  fi
  age="$(gate_heartbeat_age "$dir" 2>/dev/null || true)"
  jq -n --arg state "$state" --arg lock "$dir" --arg pid "$(gate_file_field "$dir/holder" PID)" \
    --arg project "$(gate_file_field "$dir/holder" PROJECT)" --arg command "$(gate_file_field "$dir/holder" COMMAND)" \
    --arg wrapper "$(gate_file_field "$dir/holder" WRAPPER)" --arg started "$(gate_file_field "$dir/holder" STARTED)" \
    --arg age "$age" --argjson limit "$(gate_stuck_after)" --argjson verified "$verified" \
    '{state: $state, lock: $lock, pid: $pid, project: $project, command: $command, wrapper: $wrapper,
      started: $started, heartbeat_age_seconds: ($age | tonumber? // null), stuck_after_seconds: $limit,
      wrapper_verified: $verified}'
}

cmd_status() {
  local state
  GATE_LOCK="$(gate_lock_path)" || { echo "$PROG: no usable machine lock directory" >&2; return 1; }
  state="$(gate_lock_state "$GATE_LOCK")"
  emit_status "$state" "$GATE_LOCK"
  case "$state" in
  dead) return 10 ;;
  stuck) return 11 ;;
  esac
  return 0
}

AUDIT_FILE=''
AUDIT_ROLE='krang'
AUDIT_RESULT='not written'

# One audit line through the one owner of the line shape. A failed write is reported in the
# result document and never changes what the repair already did.
audit_line() {
  local class="$1" target="$2" result="$3"
  if "$GATE_LIB_DIR/audit-trail.sh" append --class "$class" --target "$target" --result "$result" \
    --role "$AUDIT_ROLE" --file "$AUDIT_FILE" >/dev/null 2>&1; then
    AUDIT_RESULT='written'
  fi
}

emit_repair() {
  local action="$1" state="$2" reason="$3" code="$4" pid="$5" project="$6" command="$7" stopped="$8" released="$9"
  jq -n --arg action "$action" --arg state "$state" --arg reason "$reason" --arg pid "$pid" \
    --arg project "$project" --arg command "$command" --argjson stopped "$stopped" \
    --argjson released "$released" --arg audit "$AUDIT_RESULT" \
    '{action: $action, state: $state, reason: $reason, pid: $pid, project: $project, command: $command,
      stopped: $stopped, released: $released, audit: $audit}'
  return "$code"
}

# A process counts as running until it is gone. A zombie waits only for its parent to reap it and
# holds nothing, so it counts as gone.
proc_alive() {
  kill -0 "$1" 2>/dev/null && [[ "$(ps -o stat= -p "$1" 2>/dev/null | tr -d ' ')" != Z* ]]
}

# True when every pid of the list is gone.
all_gone() {
  local pid
  for pid in "$@"; do
    ! proc_alive "$pid" || return 1
  done
}

# Stop the process tree of the verified holder. $1 is the wrapper PID. Prints the pids it stopped
# as a JSON array; returns 1 when a process survived SIGKILL.
stop_holder_tree() {
  local root="$1" pid start grace waited=0 survivors=0
  local -a pids=("$root") starts=("$(gate_process_start "$root")") live=()
  while read -r pid _; do
    [[ -n "$pid" ]] || continue
    pids+=("$pid")
    starts+=("$(gate_process_start "$pid" 2>/dev/null || true)")
  done < <(gate_descendants "$root")

  for pid in "${pids[@]}"; do
    kill -TERM "$pid" 2>/dev/null || true
  done
  grace="$(gate_seconds_setting AI_OLYMPUS_GATE_REPAIR_GRACE 10)"
  while ! all_gone "${pids[@]}" && [[ "$waited" -lt "$grace" ]]; do
    sleep 1
    waited=$((waited + 1))
  done

  local index
  for index in "${!pids[@]}"; do
    pid="${pids[$index]}"
    proc_alive "$pid" || continue
    start="$(gate_process_start "$pid" 2>/dev/null || true)"
    # The same process, not a new one that took the PID: its start time equals the recorded one.
    if [[ -n "$start" && -n "${starts[$index]}" && $((start - starts[index])) -le 2 && $((starts[index] - start)) -le 2 ]]; then
      kill -KILL "$pid" 2>/dev/null || true
    fi
  done
  sleep 1
  for index in "${!pids[@]}"; do
    pid="${pids[$index]}"
    if proc_alive "$pid"; then
      survivors=$((survivors + 1))
    else
      live+=("$pid")
    fi
  done
  printf '%s\n' ${live[@]+"${live[@]}"} | jq -R 'select(length > 0) | tonumber' | jq -s -c .
  [[ "$survivors" -eq 0 ]]
}

cmd_repair() {
  local state pid project command wrapper age probe recorded_cpu recorded_sig exclude s1 s2 stopped='[]' tries
  while [[ $# -gt 0 ]]; do
    case "$1" in
    --audit) [[ $# -ge 2 ]] || { usage; return 1; }; AUDIT_FILE="$2"; shift 2 ;;
    --role) [[ $# -ge 2 ]] || { usage; return 1; }; AUDIT_ROLE="$2"; shift 2 ;;
    *) usage; return 1 ;;
    esac
  done
  AUDIT_FILE="${AUDIT_FILE:-${AUDIT_TRAIL_FILE:-}}"
  if [[ "$AUDIT_FILE" != /*.audit || ! -d "$(dirname "$AUDIT_FILE")" || ! "$AUDIT_ROLE" =~ ^[a-z][a-z0-9-]*$ ]]; then
    echo "$PROG: repair needs an audit ledger: --audit <abs .audit path> (or AUDIT_TRAIL_FILE) and a role" >&2
    return 1
  fi

  GATE_LOCK="$(gate_lock_path)" || { echo "$PROG: no usable machine lock directory" >&2; return 1; }
  state="$(gate_lock_state "$GATE_LOCK")"
  pid="$(gate_file_field "$GATE_LOCK/holder" PID)"
  project="$(gate_file_field "$GATE_LOCK/holder" PROJECT)"
  command="$(gate_file_field "$GATE_LOCK/holder" COMMAND)"
  wrapper="$(gate_file_field "$GATE_LOCK/holder" WRAPPER)"

  case "$state" in
  free)
    emit_repair none free 'there is no machine lock' 0 '' '' '' '[]' false
    return
    ;;
  held | unproven)
    emit_repair none-live "$state" 'the holder is alive and the lock is not stale; nothing was changed' 0 "$pid" "$project" "$command" '[]' false
    return
    ;;
  dead)
    tries=0
    while [[ -d "$GATE_LOCK" && "$tries" -lt 5 ]] && gate_lock_is_stale "$GATE_LOCK"; do
      gate_lock_reclaim_stale || true
      tries=$((tries + 1))
      [[ ! -d "$GATE_LOCK" ]] || sleep 1
    done
    audit_line external-write "release machine lock of dead holder pid $pid ($wrapper, project $project)" "removed the lock; the holder was confirmed dead; command: $command"
    emit_repair released-dead dead 'the holder is dead; the lock was removed and no process was stopped' 0 "$pid" "$project" "$command" '[]' true
    return
    ;;
  esac

  # stuck
  age="$(gate_heartbeat_age "$GATE_LOCK" 2>/dev/null || true)"
  if ! gate_holder_verified "$GATE_LOCK"; then
    audit_line note "refused to stop pid $pid, holder of the machine lock" "the wrapper did not start it, so nothing was stopped; project $project; command: $command"
    emit_repair refused stuck 'the holder is not a process a wrapper of this package started (command line or start time differs); nothing was stopped' 3 "$pid" "$project" "$command" '[]' false
    return
  fi

  recorded_cpu="$(gate_file_field "$GATE_LOCK/heartbeat" PROGRESS_CPU)"
  recorded_sig="$(gate_file_field "$GATE_LOCK/heartbeat" PROGRESS_SIG)"
  exclude="$(gate_file_field "$GATE_LOCK/heartbeat" HEARTBEAT_PID)"
  [[ "$exclude" =~ ^[0-9]{1,7}$ ]] || exclude=0
  s1="$(gate_progress_sample "$pid" "$exclude")"
  if [[ -n "$recorded_cpu" && -n "$recorded_sig" && "$s1" != "$recorded_cpu $recorded_sig" ]]; then
    emit_repair none-live held 'the holder made progress since its last heartbeat; nothing was changed' 0 "$pid" "$project" "$command" '[]' false
    return
  fi
  probe="$(gate_seconds_setting AI_OLYMPUS_GATE_PROBE 5)"
  sleep "$probe"
  s2="$(gate_progress_sample "$pid" "$exclude")"
  if [[ "$s1" != "$s2" ]]; then
    emit_repair none-live held 'the holder made progress while repair probed it; nothing was changed' 0 "$pid" "$project" "$command" '[]' false
    return
  fi

  gate_holder_verified "$GATE_LOCK" || {
    emit_repair none-live held 'the holder changed while repair probed it; nothing was changed' 0 "$pid" "$project" "$command" '[]' false
    return
  }
  if ! stopped="$(stop_holder_tree "$pid")"; then
    audit_line note "stop of stuck holder pid $pid of the machine lock failed" "a process of the tree survived SIGKILL: $stopped stopped; project $project; command: $command"
    emit_repair refused stuck 'a process of the holder tree survived SIGKILL; the lock was kept' 4 "$pid" "$project" "$command" "$stopped" false
    return
  fi
  tries=0
  while [[ -d "$GATE_LOCK" && "$(gate_file_field "$GATE_LOCK/holder" PID)" == "$pid" && "$tries" -lt 5 ]]; do
    gate_lock_reclaim_stale || true
    tries=$((tries + 1))
    [[ ! -d "$GATE_LOCK" ]] || sleep 1
  done
  audit_line external-write "stop process tree of stuck machine-lock holder pid $pid ($wrapper, project $project)" "stopped pids $stopped after ${age:-unknown}s without progress (limit $(gate_stuck_after)s) and released the lock; command: $command"
  emit_repair stopped-and-released stuck "the holder showed no progress for ${age:-unknown}s; its process tree was stopped and the lock was released" 0 "$pid" "$project" "$command" "$stopped" true
}

SELF_TEST_TMP=""
# Reached only through `trap cleanup_self_test EXIT`; ShellCheck does not credit a trap as an
# invocation (SC2317, and SC2329 in 0.11), so both are suppressed.
# shellcheck disable=SC2317,SC2329
cleanup_self_test() {
  local pid
  for pid in ${SELF_TEST_PIDS[@]+"${SELF_TEST_PIDS[@]}"}; do
    { kill "$pid" && wait "$pid"; } 2>/dev/null || true
  done
  [[ -n "$SELF_TEST_TMP" ]] && rm -rf "$SELF_TEST_TMP"
  return 0
}

SELF_TEST_PIDS=()

self_test() {
  local failures=0 script
  script="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/run-gate.sh"
  SELF_TEST_TMP="$(mktemp -d)"
  trap cleanup_self_test EXIT
  local tmp="$SELF_TEST_TMP" self
  self="$GATE_LIB_DIR/gate-lock.sh"
  export GATE_SELFTEST_CALLS="$tmp/calls"
  export AI_OLYMPUS_LOCK_DIR="$tmp/machine"
  export AI_OLYMPUS_GATE_LOCK_TIMEOUT=2
  export AI_OLYMPUS_GATE_HEARTBEAT_INTERVAL=1
  export AI_OLYMPUS_GATE_STUCK_AFTER=4
  export AI_OLYMPUS_GATE_PROBE=1
  export AI_OLYMPUS_GATE_REPAIR_GRACE=3
  unset AI_OLYMPUS_LOCK_HELD_BY
  local lock="$AI_OLYMPUS_LOCK_DIR/ai-olympus-gate.lock" audit="$tmp/run.audit"
  : >"$audit"

  check() {
    local label="$1" verdict="$2"
    if [[ "$verdict" == pass ]]; then
      printf 'ok    %s\n' "$label"
    else
      printf 'FAIL  %s\n' "$label" >&2
      failures=$((failures + 1))
    fi
  }

  # Run the status command: the JSON goes to OUT, the exit status is returned.
  OUT=''
  status_of() {
    local code=0
    OUT="$("$self" status 2>/dev/null)" || code=$?
    return "$code"
  }

  # Wait up to ten seconds until a file exists.
  wait_for_file() {
    local file="$1" tries=0
    while [[ ! -f "$file" && "$tries" -lt 50 ]]; do
      sleep 0.2
      tries=$((tries + 1))
    done
    [[ -f "$file" ]]
  }

  local manifest='{ "extra": { "ai-olympus": { "validation": { "executables": ["vendor/bin/gate-ok", "vendor/bin/gate-hang", "vendor/bin/gate-busy", "vendor/bin/gate-span"] } } } }'
  local project="$tmp/project" other="$tmp/other" code wrapper_pid; local -a tree_before=()
  gate_selftest_project "$project" "$manifest"
  gate_selftest_project "$other" "$manifest"

  # --- No lock ------------------------------------------------------------------
  code=0; status_of || code=$?
  check 'status reports no lock as free' "$([[ "$code" -eq 0 && "$(jq -r .state <<<"$OUT")" == free ]] && echo pass)"
  code=0; "$self" repair --audit "$audit" >/dev/null 2>&1 || code=$?
  check 'repair of a free lock changes nothing and exits 0' "$([[ "$code" -eq 0 && ! -s "$audit" ]] && echo pass)"

  # --- A holder that makes progress is held --------------------------------------
  ( cd "$project" && GATE_SELFTEST_SLEEP=9 exec "$script" --exclusive --command 'vendor/bin/gate-busy' >/dev/null 2>&1 ) &
  wrapper_pid=$!
  SELF_TEST_PIDS+=("$wrapper_pid")
  wait_for_file "$lock/heartbeat" || true
  code=0; status_of || code=$?
  check 'status names the holder: PID, project, command, start' \
    "$([[ "$code" -eq 0 && "$(jq -r .state <<<"$OUT")" == held && "$(jq -r .pid <<<"$OUT")" == "$wrapper_pid" && "$(jq -r .project <<<"$OUT")" == *project && "$(jq -r .command <<<"$OUT")" == *gate-busy* && "$(jq -r .started <<<"$OUT")" == 20* && "$(jq -r .wrapper_verified <<<"$OUT")" == true ]] && echo pass)"
  sleep 6
  code=0; status_of || code=$?
  check 'a holder that keeps using CPU stays held past the stuck limit' \
    "$([[ "$code" -eq 0 && "$(jq -r .state <<<"$OUT")" == held ]] && echo pass)"
  code=0; "$self" repair --audit "$audit" >/dev/null 2>&1 || code=$?
  check 'repair leaves a holder that makes progress running' \
    "$([[ "$code" -eq 0 ]] && kill -0 "$wrapper_pid" 2>/dev/null && [[ ! -s "$audit" ]] && echo pass)"
  wait "$wrapper_pid" || true
  check 'the lock and the heartbeat reporter are gone after a normal run' "$([[ ! -e "$lock" ]] && echo pass)"

  # --- A stuck holder: stale heartbeat, process alive ------------------------------
  ( cd "$project" && exec "$script" --exclusive --command 'vendor/bin/gate-hang' >/dev/null 2>&1 ) &
  wrapper_pid=$!
  SELF_TEST_PIDS+=("$wrapper_pid")
  wait_for_file "$lock/heartbeat" || true
  sleep 1
  read -r -a tree_before <<<"$(gate_descendants "$wrapper_pid" | awk '{ print $1 }' | tr '\n' ' ')"
  code=0; status_of || code=$?
  check 'a quiet holder is held until the stuck limit passes' \
    "$([[ "$code" -eq 0 && "$(jq -r .state <<<"$OUT")" == held ]] && echo pass)"
  sleep 5
  code=0; status_of || code=$?
  check 'a holder with a stale heartbeat is stuck and status exits 11' \
    "$([[ "$code" -eq 11 && "$(jq -r .state <<<"$OUT")" == stuck && "$(jq -r .wrapper_verified <<<"$OUT")" == true ]] && echo pass)"

  : >"$tmp/waiter.err"
  code=0; (cd "$other" && "$script" --exclusive --command 'vendor/bin/gate-ok' 2>"$tmp/waiter.err" >"$tmp/waiter.json") || code=$?
  check 'a run of another project waits, names the holder, and exits 6 without running' \
    "$([[ "$code" -eq 6 && "$(calls_in gate-ok)" -eq 0 && "$(jq -r .reason "$tmp/waiter.json")" == *"PID $wrapper_pid, project "*"/project, command "*"vendor/bin/gate-hang"*"no progress"* && "$(cat "$tmp/waiter.err")" == *"waiting for the machine lock"*"PID $wrapper_pid"* ]] && echo pass)"

  code=0; "$self" repair >/dev/null 2>&1 || code=$?
  check 'repair without an audit ledger refuses and stops nothing' \
    "$([[ "$code" -eq 1 ]] && kill -0 "$wrapper_pid" 2>/dev/null && echo pass)"

  code=0; OUT="$("$self" repair --audit "$audit" --role krang 2>/dev/null)" || code=$?
  check 'repair stops the process tree of a stuck holder and releases the lock' \
    "$([[ "$code" -eq 0 && "$(jq -r .action <<<"$OUT")" == stopped-and-released && "$(jq -r .released <<<"$OUT")" == true && ! -e "$lock" ]] && all_gone "$wrapper_pid" ${tree_before[@]+"${tree_before[@]}"} && [[ "${#tree_before[@]}" -gt 0 ]] && echo pass)"
  check 'repair records the stop in the audit ledger' \
    "$([[ "$(wc -l <"$audit" | tr -d ' ')" -eq 1 && "$(cat "$audit")" == krang\|20*\|external-write\|*"stuck machine-lock holder pid $wrapper_pid"*"released the lock"* ]] && echo pass)"
  code=0; (cd "$other" && "$script" --exclusive --command 'vendor/bin/gate-ok' >/dev/null 2>&1) || code=$?
  check 'the next run takes the released lock' "$([[ "$code" -eq 0 && "$(calls_in gate-ok)" -eq 1 ]] && echo pass)"

  # --- A dead holder ----------------------------------------------------------------
  local dead
  sh -c 'exit 0' &
  dead=$!
  wait "$dead" || true
  mkdir -p "$lock"
  printf 'PID=%s\nSTARTED=%s\nSTARTED_EPOCH=%s\nWRAPPER=run-gate.sh\nPROJECT=%s\nCOMMAND=x\n' "$dead" "$(gate_now)" "$(date +%s)" "$project" >"$lock/holder"
  code=0; status_of || code=$?
  check 'status reports a dead holder and exits 10' "$([[ "$code" -eq 10 && "$(jq -r .state <<<"$OUT")" == dead ]] && echo pass)"
  : >"$audit"
  code=0; OUT="$("$self" repair --audit "$audit" 2>/dev/null)" || code=$?
  check 'a dead holder is reclaimed, nothing is stopped, and the ledger names it' \
    "$([[ "$code" -eq 0 && "$(jq -r .action <<<"$OUT")" == released-dead && "$(jq -r .stopped <<<"$OUT")" == '[]' && ! -e "$lock" && "$(cat "$audit")" == *"dead holder pid $dead"* ]] && echo pass)"

  # A holder that exited and was never reaped by its parent is a zombie: it holds nothing.
  local zombie
  sh -c 'sh -c "echo \$\$ >\"$1\"; exit 0" & exec sleep 4' _ "$tmp/zombie.pid" &
  SELF_TEST_PIDS+=("$!")
  wait_for_file "$tmp/zombie.pid" || true
  sleep 0.5
  zombie="$(cat "$tmp/zombie.pid")"
  mkdir -p "$lock"
  printf 'PID=%s\nSTARTED=%s\nSTARTED_EPOCH=%s\nWRAPPER=run-gate.sh\nPROJECT=%s\nCOMMAND=x\n' "$zombie" "$(gate_now)" "$(date +%s)" "$project" >"$lock/holder"
  code=0; status_of || code=$?
  check 'a holder that exited and was never reaped reads as dead' \
    "$([[ "$code" -eq 10 && "$(jq -r .state <<<"$OUT")" == dead ]] && echo pass)"
  rm -rf "$lock"

  # --- A process the wrapper did not start is never stopped -------------------------
  local stranger now_epoch start
  sleep 300 &
  stranger=$!
  SELF_TEST_PIDS+=("$stranger")
  now_epoch="$(date +%s)"
  start="$(gate_process_start "$stranger")"
  mkdir -p "$lock"
  printf 'PID=%s\nSTARTED=%s\nSTARTED_EPOCH=%s\nPROCESS_START=%s\nWRAPPER=run-gate.sh\nPROJECT=%s\nCOMMAND=%s\n' \
    "$stranger" "$(gate_now)" "$now_epoch" "$start" "$project" 'vendor/bin/gate-hang' >"$lock/holder"
  printf 'HEARTBEAT_EPOCH=%s\nSAMPLED_EPOCH=%s\nPROGRESS_CPU=0\nPROGRESS_SIG=0\n' "$((now_epoch - 100))" "$((now_epoch - 100))" >"$lock/heartbeat"
  : >"$audit"
  code=0; status_of || code=$?
  check 'a stale heartbeat on a foreign PID reads as stuck but not as a wrapper' \
    "$([[ "$code" -eq 11 && "$(jq -r .wrapper_verified <<<"$OUT")" == false ]] && echo pass)"
  code=0; OUT="$("$self" repair --audit "$audit" 2>/dev/null)" || code=$?
  check 'repair refuses to stop a process the wrapper did not start and keeps the lock' \
    "$([[ "$code" -eq 3 && "$(jq -r .action <<<"$OUT")" == refused && "$(jq -r .released <<<"$OUT")" == false && -d "$lock" ]] && kill -0 "$stranger" 2>/dev/null && echo pass)"
  check 'the refusal is in the audit ledger as a note' "$([[ "$(cat "$audit")" == krang\|20*\|note\|*"pid $stranger"*"nothing was stopped"* ]] && echo pass)"

  # A wrapper name in the holder file is not proof: the command line and the start time must match.
  printf 'PID=%s\nSTARTED=%s\nSTARTED_EPOCH=%s\nPROCESS_START=%s\nWRAPPER=run-gate.sh\nPROJECT=%s\nCOMMAND=%s\n' \
    "$stranger" "$(gate_now)" "$now_epoch" "$((start - 3600))" "$project" 'vendor/bin/gate-hang' >"$lock/holder"
  code=0; "$self" repair --audit "$audit" >/dev/null 2>&1 || code=$?
  check 'a recorded start time that differs from the live process is refused' \
    "$([[ "$code" -eq 3 ]] && kill -0 "$stranger" 2>/dev/null && echo pass)"

  # A holder file of an older format carries no heartbeat and is never stuck.
  printf 'PID=%s\nSTARTED=%s\nSTARTED_EPOCH=%s\n' "$stranger" "$(gate_now)" "$now_epoch" >"$lock/holder"
  rm -f "$lock/heartbeat"
  code=0; status_of || code=$?
  check 'a holder file without a process start is held and never stuck' "$([[ "$code" -eq 0 && "$(jq -r .state <<<"$OUT")" == held ]] && echo pass)"
  code=0; "$self" repair --audit "$audit" >/dev/null 2>&1 || code=$?
  check 'repair leaves a holder of an older format alone' "$([[ "$code" -eq 0 ]] && kill -0 "$stranger" 2>/dev/null && [[ -d "$lock" ]] && echo pass)"
  rm -rf "$lock"
  { kill "$stranger" && wait "$stranger"; } 2>/dev/null || true

  # --- A command that a holder started runs under its lock ---------------------------
  mkdir -p "$lock"
  printf 'PID=%s\nSTARTED=%s\nSTARTED_EPOCH=%s\n' "$$" "$(gate_now)" "$(date +%s)" >"$lock/holder"
  : >"$GATE_SELFTEST_CALLS"
  code=0; (cd "$project" && AI_OLYMPUS_LOCK_HELD_BY="$$" "$script" --exclusive --command 'vendor/bin/gate-ok' >/dev/null 2>&1) || code=$?
  check 'a heavy command started by the holder runs under its lock and does not wait for it' \
    "$([[ "$code" -eq 0 && "$(calls_in gate-ok)" -eq 1 && -d "$lock" ]] && echo pass)"
  code=0; (cd "$project" && AI_OLYMPUS_LOCK_HELD_BY=1 "$script" --exclusive --command 'vendor/bin/gate-ok' >/dev/null 2>&1) || code=$?
  check 'a held-by claim that names another process still waits for the lock' "$([[ "$code" -eq 6 ]] && echo pass)"
  rm -rf "$lock"

  # --- Usage --------------------------------------------------------------------------
  code=0; "$self" nonsense >/dev/null 2>&1 || code=$?
  check 'an unknown command is a usage error' "$([[ "$code" -eq 1 ]] && echo pass)"

  if [[ "$failures" -gt 0 ]]; then
    echo "gate-lock self-test: $failures failure(s)" >&2
    return 4
  fi
  echo 'gate-lock self-test: PASS'
  return 0
}

# Count the stub calls that start with $1.
calls_in() {
  if [[ -f "$GATE_SELFTEST_CALLS" ]]; then grep -c "^$1" "$GATE_SELFTEST_CALLS" || true; else printf '0'; fi
}

for tool in git jq; do
  if ! command -v "$tool" >/dev/null 2>&1; then
    echo "$PROG: required tool not found: $tool" >&2
    exit 2
  fi
done

case "${1:-}" in
--self-test)
  self_test
  exit $?
  ;;
status)
  cmd_status
  exit $?
  ;;
repair)
  shift
  cmd_repair "$@"
  exit $?
  ;;
heartbeat)
  shift
  cmd_heartbeat "$@"
  exit $?
  ;;
*)
  usage
  exit 1
  ;;
esac
