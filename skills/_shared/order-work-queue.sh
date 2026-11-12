#!/usr/bin/env bash
# order-work-queue.sh — order the items of one work queue, and say which of them need a new tracker
# issue and which reuse an issue that exists.
#
# Why this exists
#   `/do-it` turns free-form instructions into a queue of items. The order of the queue and the
#   decision to create an issue are two facts that a script decides, so the same input gives the same
#   queue every time and the orchestrator never orders by feeling. The criteria are fixed:
#     1. priority    critical, then high, then medium, then low (the `priority:` labels of
#                    `@skills/github-issue-triage/SKILL.md`)
#     2. difficulty  FAST, then STANDARD, then CRITICAL (the `classify-risk.sh` verdict of the item)
#     3. duration    the smaller number first
#     4. input order, when the three keys tie
#   A dependency overrides the keys: an item never comes before an item it depends on. Among the items
#   whose dependencies are done, the best key goes first. An item inherits the priority of its most
#   urgent dependent, directly or through a chain, so a critical item never waits behind work that
#   only a low-priority blocker holds up. Only the priority is inherited: difficulty and duration
#   stay the item's own.
#
#   The script publishes no estimate of its own. The duration is a number the caller derived, and the
#   caller names the method that produced it. An item with a duration and no method is refused, so a
#   number never travels without its derivation (@rules/general/general.md *Verified statements only*).
#
# Usage
#   order-work-queue.sh < items
#   order-work-queue.sh --self-test
#
#   One item per line, in the order the instructions gave them:
#     <id>|<priority>|<difficulty>|<duration>|<method>|<depends-on>|<issue>|<evidence>
#   <id>         an item slug, matching ^[A-Za-z0-9._-]{1,64}$
#   <priority>   critical | high | medium | low
#   <difficulty> FAST | STANDARD | CRITICAL
#   <duration>   a non-negative integer of at most 9 digits, in the unit the method defines
#   <method>     the name of the method that derived the duration, matching ^[a-z][a-z0-9-]{0,39}$
#   <depends-on> comma-separated ids this item waits for; empty for none. An id that is not in this
#                input is a prerequisite that already finished.
#   <issue>      `new` when no issue exists for the item, or `existing:<number>` when one does
#   <evidence>   why the item has that issue state: the instruction line that names the issue, or the
#                search that found the match or found none. It is never empty and never carries `|`.
#   A line starting with `#` and a blank line are skipped.
#
# Output (stdout, key=value)
#   order=<id>,<id>,...        the queue, first to run first
#   create=<id>,... | none     the items that need a new issue
#   reuse=<id>,... | none      the items that reuse an issue
#   item.<n>=<id> priority=<p> difficulty=<d> duration=<n> method=<m> depends=<ids|none> issue=<new|existing:#N>
#   evidence.<id>=<text>       the evidence of the item, printable characters only, cut to 160
#   inherits.<id>=<priority>:<id>  only for an item that inherited a better priority, and the dependent it came from
#   held.<id>=depends-on:<id>  only for an item that its keys rank earlier, which waits for a dependency
#
# Exit codes
#   0  a queue was produced
#   1  usage error, malformed input, or a dependency cycle (the message names the items of the cycle)
set -euo pipefail
set -f

PROG="${0##*/}"

usage() {
  cat >&2 <<'USAGE'
Usage: order-work-queue.sh < items
       order-work-queue.sh --self-test
USAGE
}

die() {
  echo "$PROG: $1" >&2
  exit 1
}

IDS=()
PRIORITIES=()
DIFFICULTIES=()
DURATIONS=()
METHODS=()
DEPS=()
ISSUES=()
EVIDENCE=()
KEYS=()
PRANKS=()
DRANKS=()
INHERITED=()

has_id() {
  local needle="$1" id
  for id in ${IDS[@]+"${IDS[@]}"}; do
    [[ "$id" != "$needle" ]] || return 0
  done
  return 1
}

in_list() {
  local list="$1" needle="$2" entry
  local -a entries
  IFS=, read -r -a entries <<<"$list"
  for entry in ${entries[@]+"${entries[@]}"}; do
    [[ "$entry" != "$needle" ]] || return 0
  done
  return 1
}

printable() {
  printf '%s' "$1" | LC_ALL=C tr -cd '[:print:]' | cut -c1-160
}

read_items() {
  local line id priority difficulty duration method deps issue evidence extra dep prank drank
  local -a deps_list

  while IFS= read -r line || [[ -n "$line" ]]; do
    [[ -n "$line" && "$line" != '#'* ]] || continue
    # `extra` only absorbs a ninth field; the pipe count below refuses that line.
    # shellcheck disable=SC2034
    IFS='|' read -r id priority difficulty duration method deps issue evidence extra <<<"$line"
    [[ "$(printf '%s' "$line" | tr -cd '|' | wc -c | tr -d ' ')" -eq 7 ]] || die "an item needs exactly 8 fields: $(printable "$line")"

    [[ "$id" =~ ^[A-Za-z0-9._-]{1,64}$ ]] || die "invalid id: $(printable "$id")"
    ! has_id "$id" || die "duplicate id: $id"

    case "$priority" in
    critical) prank=0 ;;
    high) prank=1 ;;
    medium) prank=2 ;;
    low) prank=3 ;;
    *) die "invalid priority of $id: $(printable "$priority")" ;;
    esac
    case "$difficulty" in
    FAST) drank=0 ;;
    STANDARD) drank=1 ;;
    CRITICAL) drank=2 ;;
    *) die "invalid difficulty of $id: $(printable "$difficulty")" ;;
    esac
    [[ "$duration" =~ ^[0-9]{1,9}$ ]] || die "invalid duration of $id: $(printable "$duration")"
    [[ -n "$method" ]] || die "the duration of $id has no method; a number needs the method that derived it"
    [[ "$method" =~ ^[a-z][a-z0-9-]{0,39}$ ]] || die "invalid method of $id: $(printable "$method")"

    if [[ -n "$deps" ]]; then
      IFS=, read -r -a deps_list <<<"$deps"
      for dep in "${deps_list[@]}"; do
        [[ "$dep" =~ ^[A-Za-z0-9._-]{1,64}$ ]] || die "invalid dependency of $id: $(printable "$dep")"
      done
    fi

    case "$issue" in
    new) ;;
    existing:[1-9]*) [[ "${issue#existing:}" =~ ^[1-9][0-9]{0,9}$ ]] || die "invalid issue of $id: $(printable "$issue")" ;;
    *) die "invalid issue of $id: $(printable "$issue"); use new or existing:<number>" ;;
    esac
    [[ -n "$evidence" ]] || die "the issue state of $id has no evidence"

    IDS+=("$id")
    PRIORITIES+=("$priority")
    DIFFICULTIES+=("$difficulty")
    DURATIONS+=("$duration")
    METHODS+=("$method")
    DEPS+=("$deps")
    ISSUES+=("$issue")
    EVIDENCE+=("$evidence")
    PRANKS+=("$prank")
    DRANKS+=("$drank")
  done

  [[ "${#IDS[@]}" -gt 0 ]] || die 'no item given'
  inherit_priority
  build_keys
}

PRIORITY_NAMES=(critical high medium low)

index_of() {
  local needle="$1" index
  for index in "${!IDS[@]}"; do
    if [[ "${IDS[$index]}" == "$needle" ]]; then
      printf '%s' "$index"
      return 0
    fi
  done
  return 1
}

# inherit_priority — raise the priority rank of every item to the best rank of its dependents, until nothing
# changes. A rank only falls and never below 0, so the loop ends, a cycle included.
inherit_priority() {
  local changed=1 index dep target
  local -a deps_list
  while [[ "$changed" -eq 1 ]]; do
    changed=0
    for index in "${!IDS[@]}"; do
      [[ -n "${DEPS[$index]}" ]] || continue
      IFS=, read -r -a deps_list <<<"${DEPS[$index]}"
      for dep in "${deps_list[@]}"; do
        target="$(index_of "$dep")" || continue
        if [[ "${PRANKS[$index]}" -lt "${PRANKS[$target]}" ]]; then
          PRANKS[target]="${PRANKS[$index]}"
          INHERITED[target]="${PRIORITY_NAMES[${PRANKS[$index]}]}:${IDS[$index]}"
          changed=1
        fi
      done
    done
  done
}

# build_keys — the key sorts as a string: priority, difficulty, zero-padded duration, then input position.
build_keys() {
  local index
  for index in "${!IDS[@]}"; do
    KEYS+=("$(printf '%d%d%09d%06d' "${PRANKS[$index]}" "${DRANKS[$index]}" "$((10#${DURATIONS[$index]}))" "$((index + 1))")")
  done
}

# unmet_dependency <index> — print the first dependency of the item that is in the input and not emitted yet.
unmet_dependency() {
  local index="$1" dep
  local -a deps_list
  [[ -n "${DEPS[$index]}" ]] || return 1
  IFS=, read -r -a deps_list <<<"${DEPS[$index]}"
  for dep in "${deps_list[@]}"; do
    if has_id "$dep" && ! in_list "$EMITTED" "$dep"; then
      printf '%s' "$dep"
      return 0
    fi
  done
  return 1
}

EMITTED=''

order() {
  local -a sequence=() held=()
  local remaining="${#IDS[@]}" index best unmet blocked_key cycle
  local -a done_flag=()

  for index in "${!IDS[@]}"; do
    done_flag[index]=0
  done

  while [[ "$remaining" -gt 0 ]]; do
    best=''
    for index in "${!IDS[@]}"; do
      [[ "${done_flag[$index]}" -eq 0 ]] || continue
      if unmet_dependency "$index" >/dev/null; then
        continue
      fi
      if [[ -z "$best" || "${KEYS[$index]}" < "${KEYS[$best]}" ]]; then
        best="$index"
      fi
    done

    if [[ -z "$best" ]]; then
      cycle=''
      for index in "${!IDS[@]}"; do
        [[ "${done_flag[$index]}" -ne 0 ]] || cycle+="${cycle:+,}${IDS[$index]}"
      done
      die "dependency cycle among: $cycle"
    fi

    # An item that its keys rank before the chosen one and that still waits for a dependency is held back.
    for index in "${!IDS[@]}"; do
      [[ "${done_flag[$index]}" -eq 0 && "$index" != "$best" ]] || continue
      blocked_key="${KEYS[$index]}"
      if [[ "$blocked_key" < "${KEYS[$best]}" ]] && unmet="$(unmet_dependency "$index")"; then
        [[ -n "${held[$index]:-}" ]] || held[index]="$unmet"
      fi
    done

    done_flag[best]=1
    remaining=$((remaining - 1))
    sequence+=("$best")
    EMITTED+="${EMITTED:+,}${IDS[$best]}"
  done

  local ids='' create='' reuse='' position=0 deps
  for index in "${sequence[@]}"; do
    position=$((position + 1))
    ids+="${ids:+,}${IDS[$index]}"
    if [[ "${ISSUES[$index]}" == new ]]; then
      create+="${create:+,}${IDS[$index]}"
    else
      reuse+="${reuse:+,}${IDS[$index]}"
    fi
  done

  printf 'order=%s\n' "$ids"
  printf 'create=%s\n' "${create:-none}"
  printf 'reuse=%s\n' "${reuse:-none}"
  position=0
  for index in "${sequence[@]}"; do
    position=$((position + 1))
    deps="${DEPS[$index]:-none}"
    printf 'item.%s=%s priority=%s difficulty=%s duration=%s method=%s depends=%s issue=%s\n' \
      "$position" "${IDS[$index]}" "${PRIORITIES[$index]}" "${DIFFICULTIES[$index]}" "${DURATIONS[$index]}" \
      "${METHODS[$index]}" "$deps" "${ISSUES[$index]/existing:/existing:#}"
  done
  for index in "${sequence[@]}"; do
    printf 'evidence.%s=%s\n' "${IDS[$index]}" "$(printable "${EVIDENCE[$index]}")"
  done
  for index in "${sequence[@]}"; do
    [[ -z "${INHERITED[$index]:-}" ]] || printf 'inherits.%s=%s\n' "${IDS[$index]}" "${INHERITED[$index]}"
  done
  for index in "${sequence[@]}"; do
    [[ -z "${held[$index]:-}" ]] || printf 'held.%s=depends-on:%s\n' "${IDS[$index]}" "${held[$index]}"
  done
}

SELF_TEST_FAILURES=0
SELF_SCRIPT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/$PROG"

# expect_order <label> <items> <expected order=...> — compare the `order=` line only.
expect_order() {
  local label="$1" input="$2" expected="$3" actual
  actual="$(printf '%s\n' "$input" | "$SELF_SCRIPT" 2>&1 | grep '^order=' || true)"
  if [[ "$actual" == "order=$expected" ]]; then
    printf 'ok    %s\n' "$label"
  else
    printf 'FAIL  %s\n        expected: order=%s\n        actual:   %s\n' "$label" "$expected" "$actual" >&2
    SELF_TEST_FAILURES=$((SELF_TEST_FAILURES + 1))
  fi
}

# expect_output <label> <items> <expected whole output, lines joined by a space>
expect_output() {
  local label="$1" input="$2" expected="$3" actual
  actual="$(printf '%s\n' "$input" | "$SELF_SCRIPT" 2>&1 | tr '\n' ' ')" || true
  if [[ "$actual" == "$expected" ]]; then
    printf 'ok    %s\n' "$label"
  else
    printf 'FAIL  %s\n        expected: %s\n        actual:   %s\n' "$label" "$expected" "$actual" >&2
    SELF_TEST_FAILURES=$((SELF_TEST_FAILURES + 1))
  fi
}

# expect_refusal <label> <items> <part of the message> — exit 1 and the message carries the part.
expect_refusal() {
  local label="$1" input="$2" part="$3" code=0 message
  message="$(printf '%s\n' "$input" | "$SELF_SCRIPT" 2>&1 >/dev/null)" || code=$?
  if [[ "$code" -eq 1 && "$message" == *"$part"* ]]; then
    printf 'ok    %s\n' "$label"
  else
    printf 'FAIL  %s (exit %s, message: %s)\n' "$label" "$code" "$message" >&2
    SELF_TEST_FAILURES=$((SELF_TEST_FAILURES + 1))
  fi
}

# item <id> <priority> <difficulty> <duration> [depends] [issue] — one valid line with fixed method and evidence.
item() {
  printf '%s|%s|%s|%s|files-and-criteria|%s|%s|%s' "$1" "$2" "$3" "$4" "${5:-}" "${6:-new}" 'search found no open issue'
}

self_test() {
  local nl=$'\n'

  expect_order 'one item is a queue of one' "$(item a medium FAST 3)" 'a'

  expect_order 'priority comes first: critical, high, medium, low' \
    "$(item l low FAST 1)${nl}$(item m medium FAST 1)${nl}$(item h high FAST 1)${nl}$(item c critical FAST 1)" 'c,h,m,l'

  expect_order 'priority beats difficulty and duration' \
    "$(item easy low FAST 1)${nl}$(item hard high CRITICAL 99)" 'hard,easy'

  expect_order 'difficulty comes second: FAST, STANDARD, CRITICAL' \
    "$(item c medium CRITICAL 1)${nl}$(item s medium STANDARD 1)${nl}$(item f medium FAST 1)" 'f,s,c'

  expect_order 'difficulty beats duration inside one priority' \
    "$(item slow-easy medium FAST 50)${nl}$(item quick-hard medium STANDARD 1)" 'slow-easy,quick-hard'

  expect_order 'duration comes third: the shortest first' \
    "$(item a medium STANDARD 30)${nl}$(item b medium STANDARD 5)${nl}$(item c medium STANDARD 12)" 'b,c,a'

  expect_order 'a duration of 0 sorts before 5 and 10 sorts after 9' \
    "$(item a medium FAST 10)${nl}$(item b medium FAST 9)${nl}$(item c medium FAST 0)" 'c,b,a'

  expect_order 'a full tie keeps the order of the instructions' \
    "$(item a medium FAST 3)${nl}$(item b medium FAST 3)${nl}$(item c medium FAST 3)" 'a,b,c'

  expect_order 'a dependency runs before its dependent even when the dependent ranks first' \
    "$(item dependent critical FAST 1 blocker)${nl}$(item blocker low CRITICAL 99)" 'blocker,dependent'

  expect_order 'a dependency on a later item waits for it, and a free item with a better key goes first' \
    "$(item a high FAST 1 c)${nl}$(item b high FAST 2)${nl}$(item c low FAST 3)" 'b,c,a'

  expect_order 'a dependency inherits the priority of its most urgent dependent' \
    "$(item a critical CRITICAL 9 b)${nl}$(item b low FAST 1)${nl}$(item c high FAST 1)" 'b,a,c'

  expect_order 'a dependency inherits the priority through a chain' \
    "$(item d high FAST 1)${nl}$(item a critical CRITICAL 9 b)${nl}$(item b low FAST 1 c)${nl}$(item c low FAST 1)" 'c,b,a,d'

  expect_order 'an inherited priority never changes difficulty or duration' \
    "$(item a critical FAST 1 b)${nl}$(item b low CRITICAL 50)${nl}$(item c critical FAST 2)" 'c,b,a'

  expect_order 'a chain keeps its order whatever the keys say' \
    "$(item a low CRITICAL 9 b)${nl}$(item b medium STANDARD 5 c)${nl}$(item c high FAST 1)" 'c,b,a'

  expect_order 'after a dependency is done the best key goes next' \
    "$(item a low FAST 1)${nl}$(item base medium FAST 5)${nl}$(item top high FAST 9 base)${nl}$(item b medium FAST 6)" 'base,top,b,a'

  expect_order 'an item that depends on two items waits for both' \
    "$(item join critical FAST 1 x,y)${nl}$(item x low FAST 1)${nl}$(item y low FAST 2)" 'x,y,join'

  expect_order 'a dependency outside the queue is already done' \
    "$(item a medium FAST 3 old-issue)${nl}$(item b high FAST 3)" 'b,a'

  expect_order 'comments and blank lines are skipped' \
    "# a comment${nl}${nl}$(item a medium FAST 3)${nl}$(item b high FAST 3)" 'b,a'

  expect_output 'the output lists the order, the issue decisions, every item, the evidence, and the held items' \
    "$(item a low FAST 1)${nl}$(item base medium FAST 5 '' existing:12)${nl}$(item top high FAST 9 base)" \
    'order=base,top,a create=top,a reuse=base item.1=base priority=medium difficulty=FAST duration=5 method=files-and-criteria depends=none issue=existing:#12 item.2=top priority=high difficulty=FAST duration=9 method=files-and-criteria depends=base issue=new item.3=a priority=low difficulty=FAST duration=1 method=files-and-criteria depends=none issue=new evidence.base=search found no open issue evidence.top=search found no open issue evidence.a=search found no open issue inherits.base=high:top '

  expect_output 'a dependent that ranks first is held back and the dependency names its source of priority' \
    "$(item a critical FAST 1 b)${nl}$(item b low CRITICAL 50)" \
    'order=b,a create=b,a reuse=none item.1=b priority=low difficulty=CRITICAL duration=50 method=files-and-criteria depends=none issue=new item.2=a priority=critical difficulty=FAST duration=1 method=files-and-criteria depends=b issue=new evidence.b=search found no open issue evidence.a=search found no open issue inherits.b=critical:a held.a=depends-on:b '

  expect_output 'an item that reuses an issue is never in the create list' \
    "$(item a medium FAST 1 '' existing:7)" \
    'order=a create=none reuse=a item.1=a priority=medium difficulty=FAST duration=1 method=files-and-criteria depends=none issue=existing:#7 evidence.a=search found no open issue '

  expect_output 'every item that needs an issue is in the create list' \
    "$(item a medium FAST 1)${nl}$(item b medium FAST 2)" \
    'order=a,b create=a,b reuse=none item.1=a priority=medium difficulty=FAST duration=1 method=files-and-criteria depends=none issue=new item.2=b priority=medium difficulty=FAST duration=2 method=files-and-criteria depends=none issue=new evidence.a=search found no open issue evidence.b=search found no open issue '

  expect_output 'a mixed queue splits into create and reuse' \
    "$(item a medium FAST 1)${nl}$(item b medium FAST 2 '' existing:31)${nl}$(item c medium FAST 3)" \
    'order=a,b,c create=a,c reuse=b item.1=a priority=medium difficulty=FAST duration=1 method=files-and-criteria depends=none issue=new item.2=b priority=medium difficulty=FAST duration=2 method=files-and-criteria depends=none issue=existing:#31 item.3=c priority=medium difficulty=FAST duration=3 method=files-and-criteria depends=none issue=new evidence.a=search found no open issue evidence.b=search found no open issue evidence.c=search found no open issue '

  expect_output 'the evidence carries printable characters only' \
    $'a|medium|FAST|1|files-and-criteria||existing:5|the line "fix #5"\x01\x1b[31m names it' \
    'order=a create=none reuse=a item.1=a priority=medium difficulty=FAST duration=1 method=files-and-criteria depends=none issue=existing:#5 evidence.a=the line "fix #5"[31m names it '

  expect_refusal 'a dependency cycle is refused and names its items' \
    "$(item a medium FAST 1 b)${nl}$(item b medium FAST 1 a)${nl}$(item c medium FAST 1)" 'dependency cycle among: a,b'
  expect_refusal 'a longer cycle is refused' \
    "$(item a medium FAST 1 c)${nl}$(item b medium FAST 1 a)${nl}$(item c medium FAST 1 b)" 'dependency cycle among: a,b,c'
  expect_refusal 'an item that depends on itself is refused' "$(item a medium FAST 1 a)" 'dependency cycle among: a'
  expect_refusal 'a duration without a method is refused' 'a|medium|FAST|3||||search found no open issue' 'has no method'
  expect_refusal 'a method that is not a name is refused' 'a|medium|FAST|3|Total Guess||new|x' 'invalid method'
  expect_refusal 'an item that reuses an issue without evidence is refused' 'a|medium|FAST|3|files-and-criteria||existing:7|' 'no evidence'
  expect_refusal 'an item that needs a new issue without evidence is refused' 'a|medium|FAST|3|files-and-criteria||new|' 'no evidence'
  expect_refusal 'an issue number of 0 is refused' 'a|medium|FAST|3|files-and-criteria||existing:0|x' 'invalid issue'
  expect_refusal 'an issue state that is not new or existing is refused' 'a|medium|FAST|3|files-and-criteria||duplicate:7|x' 'invalid issue'
  expect_refusal 'an unknown priority is refused' 'a|urgent|FAST|3|files-and-criteria||new|x' 'invalid priority'
  expect_refusal 'an unknown difficulty is refused' 'a|medium|HARD|3|files-and-criteria||new|x' 'invalid difficulty'
  expect_refusal 'a negative duration is refused' 'a|medium|FAST|-1|files-and-criteria||new|x' 'invalid duration'
  expect_refusal 'a fractional duration is refused' 'a|medium|FAST|1.5|files-and-criteria||new|x' 'invalid duration'
  expect_refusal 'an id with a path separator is refused' '../a|medium|FAST|3|files-and-criteria||new|x' 'invalid id'
  expect_refusal 'a duplicate id is refused' "$(item a medium FAST 1)${nl}$(item a medium FAST 2)" 'duplicate id'
  expect_refusal 'a dependency with a path separator is refused' 'a|medium|FAST|3|files-and-criteria|../b|new|x' 'invalid dependency'
  expect_refusal 'an item with seven fields is refused' 'a|medium|FAST|3|files-and-criteria||new' 'exactly 8 fields'
  expect_refusal 'an item with nine fields is refused' 'a|medium|FAST|3|files-and-criteria||new|x|y' 'exactly 8 fields'
  expect_refusal 'empty input is refused' '' 'no item given'

  if [[ "$SELF_TEST_FAILURES" -gt 0 ]]; then
    echo "order-work-queue self-test: $SELF_TEST_FAILURES failure(s)" >&2
    return 1
  fi
  echo 'order-work-queue self-test: PASS'
}

if [[ "${1:-}" == '--self-test' ]]; then
  self_test
  exit $?
fi

if [[ $# -gt 0 ]]; then
  usage
  exit 1
fi

read_items
order
