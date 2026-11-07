#!/usr/bin/env bash
# run-shell-self-tests.sh — run every shell script's --self-test and print one aligned line per script.
#
# Why this exists
#   Each script prints its own self-test output in its own format: different
#   column widths, different PASS lines, and temporary paths. Read in a row,
#   the output is hard to scan. This runner captures each script's output and
#   prints one row per script with its status, check count, and duration.
#   The full output is printed, indented, only for a script that failed.
#   Every self-test builds its fixtures in its own mktemp directory, so the
#   scripts run in parallel, one job per CPU, and the rows keep the input order.
#
# Usage
#   run-shell-self-tests.sh <script.sh>...
#
# Exit codes
#   0  every script passed
#   1  at least one script failed, or no script was given

set -uo pipefail

if [[ $# -eq 0 ]]; then
  echo 'usage: run-shell-self-tests.sh <script.sh>...' >&2
  exit 1
fi

width=0
for script in "$@"; do
  [[ ${#script} -gt $width ]] && width=${#script}
done

results="$(mktemp -d)"
trap 'rm -rf "$results"' EXIT

jobs="$(getconf _NPROCESSORS_ONLN 2>/dev/null || echo 4)"
start_all=$SECONDS

# Each job writes <index>.log and <index>.rc ("<exit code> <seconds>").
# The job body is single-quoted on purpose: it expands inside each job, not here.
index=0
# shellcheck disable=SC2016
for script in "$@"; do
  printf '%s\0%s\0' "$index" "$script"
  index=$((index + 1))
done | xargs -0 -n 2 -P "$jobs" bash -c '
  results="$1"; index="$2"; script="$3"
  start=$SECONDS
  bash "$script" --self-test >"$results/$index.log" 2>&1
  echo "$? $((SECONDS - start))" >"$results/$index.rc"
' _ "$results"

failed=0
checks_total=0
index=0

for script in "$@"; do
  log="$results/$index.log"
  read -r rc elapsed <"$results/$index.rc"
  index=$((index + 1))

  # A script reports its checks either as one "ok    <label>" line each
  # or as a "(N checks)" summary.
  checks="$(grep -c '^ok ' "$log")"
  if [[ "$checks" -eq 0 ]]; then
    checks="$(sed -n 's/.*(\([0-9][0-9]*\) checks).*/\1/p' "$log" | tail -n 1)"
  fi
  if [[ -n "$checks" && "$checks" -gt 0 ]]; then
    checks_total=$((checks_total + checks))
    checks_label="${checks} checks"
  else
    checks_label='-'
  fi

  if [[ $rc -eq 0 ]]; then
    printf '  ✅ PASS  %-*s  %10s  %4ss\n' "$width" "$script" "$checks_label" "$elapsed"
  else
    failed=$((failed + 1))
    printf '  ❌ FAIL  %-*s  %10s  %4ss  exit %s\n' "$width" "$script" "$checks_label" "$elapsed" "$rc"
    sed 's/^/         │ /' "$log"
  fi
done

printf '\n  %s scripts, %s passed, %s failed, %s checks, %ss\n' \
  "$#" "$(($# - failed))" "$failed" "$checks_total" "$((SECONDS - start_all))"

[[ $failed -eq 0 ]]
