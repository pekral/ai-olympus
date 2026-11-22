#!/usr/bin/env bash
# derive-effort.sh — derive the effort label of one assignment: `effort: small`, `effort: medium`, or
# `effort: large`. The label is a size, never a time.
#
# Why this exists
#   The triage labels every analysed issue with its size, so small tasks can be picked quickly and large
#   ones split before work starts. The size must be the same every time for the same text, so a script
#   derives it and an agent never guesses a threshold. The script publishes no estimate in hours or days
#   (@rules/general/general.md *Verified statements only*): it prints a count, the method that produced
#   the count, and the label that the thresholds below assign to it.
#
# Method
#   count  the `count=` line of `skills/_shared/count-assignment.sh --assignment <file>`: the distinct
#          files the assignment names plus its criteria. That script is the one owner of the method
#          `files-and-criteria`, which `@skills/do-it/SKILL.md` reads as the duration of an item.
#   tier   the `tier=` line of `commands/_shared/classify-risk.sh --assignment <file>`, or the value of
#          `--tier` (the caller already ran the classifier).
#
# Thresholds
#   SMALL_MAX and MEDIUM_MAX below are the only statement of them. A count up to SMALL_MAX is small, a
#   count up to MEDIUM_MAX is medium, and a larger count is large. A CRITICAL tier raises the size by
#   one step (small -> medium, medium -> large, large stays large). FAST and STANDARD never change it.
#   `large` marks the issue as a candidate for splitting.
#
# Usage
#   derive-effort.sh --assignment <file> [--tier FAST|STANDARD|CRITICAL]
#   derive-effort.sh --self-test
#
# Output (stdout, key=value, in this order)
#   label=effort: <size>
#   size=small|medium|large
#   method=files-and-criteria
#   count=<files + criteria>
#   files=<n>
#   criteria=<n>
#   criteria-source=sections|bullets|minimum
#   tier=FAST|STANDARD|CRITICAL
#   tier-source=classify-risk|argument
#   tier-raise=yes|no
#   split-candidate=yes|no
#   thresholds=small<=<SMALL_MAX> medium<=<MEDIUM_MAX> large>=<MEDIUM_MAX + 1>
#
# Exit codes
#   0  a label was derived
#   1  usage error, an unreadable assignment, or a classifier that printed no tier
set -euo pipefail
set -f

PROG="${0##*/}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
source "$HERE/../../_shared/resolve-command-helper.sh" || exit 3
CLASSIFIER="$(resolve_command_helper classify-risk.sh)" || exit 3
COUNTER="$HERE/../../_shared/count-assignment.sh"

# The thresholds. Every other place refers to these two names and prints their values from them.
SMALL_MAX=5
MEDIUM_MAX=10

die() {
  echo "$PROG: $1" >&2
  exit 1
}

usage() {
  cat >&2 <<'USAGE'
Usage: derive-effort.sh --assignment <file> [--tier FAST|STANDARD|CRITICAL]
       derive-effort.sh --self-test
USAGE
}

# size_of <count> <tier> — print `<size> <raised yes|no>`.
size_of() {
  local count="$1" tier="$2" size raised=no
  if ((count <= SMALL_MAX)); then
    size=small
  elif ((count <= MEDIUM_MAX)); then
    size=medium
  else
    size=large
  fi
  if [[ "$tier" == CRITICAL && "$size" != large ]]; then
    raised=yes
    if [[ "$size" == small ]]; then size=medium; else size=large; fi
  fi
  printf '%s %s' "$size" "$raised"
}

# counted <key> — the value of one key of the counter output held in $counted_output.
counted() {
  sed -n "s/^$1=//p" <<<"$counted_output"
}

derive() {
  local file="$1" tier="$2" tier_source=argument counted_output files criteria source count result size raised split=no
  [[ -r "$file" ]] || die "cannot read the assignment: $file"
  [[ -x "$COUNTER" ]] || die "counter not found: $COUNTER"

  if [[ -z "$tier" ]]; then
    [[ -x "$CLASSIFIER" ]] || die "classifier not found: $CLASSIFIER"
    tier="$("$CLASSIFIER" --assignment "$file" | sed -n 's/^tier=//p' | head -n 1)"
    tier_source=classify-risk
  fi
  case "$tier" in
  FAST | STANDARD | CRITICAL) ;;
  *) die "no valid tier: ${tier:-empty}" ;;
  esac

  counted_output="$("$COUNTER" --assignment "$file")" || die "the counter failed on: $file"
  files="$(counted files)"
  criteria="$(counted criteria)"
  source="$(counted criteria-source)"
  count="$(counted count)"
  [[ "$count" =~ ^[0-9]+$ && "$files" =~ ^[0-9]+$ && "$criteria" =~ ^[0-9]+$ ]] || die "the counter printed no count"

  result="$(size_of "$count" "$tier")"
  size="${result% *}"
  raised="${result#* }"
  [[ "$size" != large ]] || split=yes

  printf 'label=effort: %s\n' "$size"
  printf 'size=%s\n' "$size"
  printf 'method=%s\n' "$(counted method)"
  printf 'count=%d\n' "$count"
  printf 'files=%d\n' "$files"
  printf 'criteria=%d\n' "$criteria"
  printf 'criteria-source=%s\n' "$source"
  printf 'tier=%s\n' "$tier"
  printf 'tier-source=%s\n' "$tier_source"
  printf 'tier-raise=%s\n' "$raised"
  printf 'split-candidate=%s\n' "$split"
  printf 'thresholds=small<=%d medium<=%d large>=%d\n' "$SMALL_MAX" "$MEDIUM_MAX" "$((MEDIUM_MAX + 1))"
}

SELF_TEST_FAILURES=0
SELF_DIR=''
SELF_SCRIPT="$HERE/$PROG"

cleanup_self_test() {
  [[ -z "$SELF_DIR" ]] || rm -rf -- "$SELF_DIR"
}

# expect_value <label> <fixture file> <tier or empty> <key> <expected value>
expect_value() {
  local label="$1" file="$2" tier="$3" key="$4" expected="$5" actual
  if [[ -n "$tier" ]]; then
    actual="$("$SELF_SCRIPT" --assignment "$file" --tier "$tier" 2>&1 | sed -n "s/^${key}=//p")"
  else
    actual="$("$SELF_SCRIPT" --assignment "$file" 2>&1 | sed -n "s/^${key}=//p")"
  fi
  if [[ "$actual" == "$expected" ]]; then
    printf 'ok    %s\n' "$label"
  else
    printf 'FAIL  %s\n        expected: %s=%s\n        actual:   %s\n' "$label" "$key" "$expected" "$actual" >&2
    SELF_TEST_FAILURES=$((SELF_TEST_FAILURES + 1))
  fi
}

# expect_exit <label> <expected exit code> <command...>
expect_exit() {
  local label="$1" expected="$2" code=0
  shift 2
  "$@" >/dev/null 2>&1 || code=$?
  if [[ "$code" -eq "$expected" ]]; then
    printf 'ok    %s\n' "$label"
  else
    printf 'FAIL  %s (exit %s, expected %s)\n' "$label" "$code" "$expected" >&2
    SELF_TEST_FAILURES=$((SELF_TEST_FAILURES + 1))
  fi
}

# fixture <file> <criteria> — a body whose business sections hold <criteria> bullets in all.
fixture() {
  local file="$1" total="$2" i
  {
    printf 'Problem today.\n\n<details open>\n<summary><strong>The rules</strong></summary>\n\n'
    for ((i = 1; i <= total; i++)); do
      printf -- '- bullet %d\n' "$i"
    done
    printf '\n</details>\n'
  } >"$file"
}

self_test() {
  trap cleanup_self_test EXIT
  SELF_DIR="$(mktemp -d)"
  local d="$SELF_DIR" small_max=$SMALL_MAX medium_max=$MEDIUM_MAX

  # Every case below is built from the constants, so a change of a threshold changes no expectation.
  fixture "$d/at-small.md" "$small_max"
  fixture "$d/over-small.md" "$((small_max + 1))"
  fixture "$d/at-medium.md" "$medium_max"
  fixture "$d/over-medium.md" "$((medium_max + 1))"
  fixture "$d/one.md" 1

  expect_value 'a count at the small limit is small' "$d/at-small.md" STANDARD label 'effort: small'
  expect_value 'a count over the small limit is medium' "$d/over-small.md" STANDARD label 'effort: medium'
  expect_value 'a count at the medium limit is medium' "$d/at-medium.md" STANDARD label 'effort: medium'
  expect_value 'a count over the medium limit is large' "$d/over-medium.md" STANDARD label 'effort: large'
  expect_value 'a medium issue is no split candidate' "$d/at-medium.md" STANDARD split-candidate no
  expect_value 'a large issue is a split candidate' "$d/over-medium.md" STANDARD split-candidate yes
  expect_value 'a STANDARD tier does not raise the size' "$d/over-small.md" STANDARD tier-raise no
  expect_value 'a FAST tier does not change the size' "$d/over-medium.md" FAST label 'effort: large'
  expect_value 'a FAST tier keeps a small size' "$d/at-small.md" FAST label 'effort: small'
  expect_value 'a CRITICAL tier raises small to medium' "$d/at-small.md" CRITICAL label 'effort: medium'
  expect_value 'a CRITICAL tier raises medium to large' "$d/over-small.md" CRITICAL label 'effort: large'
  expect_value 'a CRITICAL tier keeps large' "$d/over-medium.md" CRITICAL label 'effort: large'
  expect_value 'a raised size says so' "$d/over-small.md" CRITICAL tier-raise yes
  expect_value 'a small issue under a CRITICAL tier is medium and no split candidate' "$d/one.md" CRITICAL split-candidate no

  expect_value 'the count is the counter count' "$d/at-small.md" STANDARD count "$small_max"
  expect_value 'the method is the one the counter names' "$d/one.md" FAST method files-and-criteria
  expect_value 'the criteria source of the counter is passed on' "$d/one.md" FAST criteria-source sections
  expect_value 'the tier given by the caller is named' "$d/one.md" FAST tier-source argument
  expect_value 'the thresholds are printed from the constants' "$d/one.md" FAST thresholds "small<=$small_max medium<=$medium_max large>=$((medium_max + 1))"
  expect_value 'the same text always gives the same label (first run)' "$d/over-medium.md" STANDARD label 'effort: large'
  expect_value 'the same text always gives the same label (second run)' "$d/over-medium.md" STANDARD label 'effort: large'
  expect_value 'the tier comes from the classifier when none is given' "$d/one.md" '' tier-source classify-risk

  expect_exit 'a missing assignment is refused' 1 "$SELF_SCRIPT" --assignment "$d/missing.md" --tier FAST
  expect_exit 'an unknown tier is refused' 1 "$SELF_SCRIPT" --assignment "$d/one.md" --tier HUGE
  expect_exit 'a call with no argument is refused' 1 "$SELF_SCRIPT"
  expect_exit 'an unknown option is refused' 1 "$SELF_SCRIPT" --hours 3

  if [[ "$SELF_TEST_FAILURES" -gt 0 ]]; then
    echo "derive-effort self-test: $SELF_TEST_FAILURES failure(s)" >&2
    return 1
  fi
  echo 'derive-effort self-test: PASS'
}

assignment='' tier=''
if [[ "${1:-}" == '--self-test' ]]; then
  self_test
  exit $?
fi

while [[ $# -gt 0 ]]; do
  case "$1" in
  --assignment)
    [[ $# -ge 2 ]] || { usage; exit 1; }
    assignment="$2"
    shift 2
    ;;
  --tier)
    [[ $# -ge 2 ]] || { usage; exit 1; }
    tier="$2"
    shift 2
    ;;
  *)
    usage
    exit 1
    ;;
  esac
done

[[ -n "$assignment" ]] || { usage; exit 1; }
derive "$assignment" "$tier"
