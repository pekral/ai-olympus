#!/usr/bin/env bash
# plan-parallel.sh — group the units of one request into batches, so that two units
# run at the same time only when nothing can make them collide.
#
# Why this exists
#   `krang` used to process several sources strictly one after another. That is
#   always safe, and it leaves the machine idle between slow steps. Running two
#   units at once is safe only when their work cannot touch the same files, one
#   does not wait for the other, and neither is a risky change. Those are three
#   facts, so a script decides them. The orchestrator reads the batches and does
#   not judge by itself which units may overlap.
#
# Usage
#   plan-parallel.sh < units
#   plan-parallel.sh --step < evidence
#   plan-parallel.sh --self-test
#
#   One unit per line, in the order they must run (dependencies first):
#     <id>|<tier>|<depends-on>|<files>
#   <id>         a source slug, matching ^[A-Za-z0-9._-]{1,64}$
#   <tier>       the unit's `classify-risk.sh` verdict: FAST, STANDARD, or CRITICAL
#   <depends-on> comma-separated ids this unit waits for; empty for none. An id that
#                is not in this input is a prerequisite that already finished.
#   <files>      the files the unit is predicted to change, separated by spaces,
#                from the analysis or the blast radius. An entry ending in `/` is a
#                directory and covers every path below it; an entry without the
#                slash covers them too when the other entry lies below it. A
#                non-canonical spelling (`./x`, `a//b`, `a/./b`) is refused.
#                Empty means no evidence.
#   A line starting with `#` and a blank line are skipped.
#
# Rule
#   A unit joins the current batch only when all of these hold; otherwise it starts
#   the next batch. Batches run one after another, and a batch of two units runs
#   its two units at the same time.
#     1. The batch holds fewer than 2 units (the fixed concurrency limit).
#     2. Neither the unit nor any unit in the batch is CRITICAL.
#     3. The unit and every unit in the batch list files, and the two file sets
#        are disjoint. A unit without file evidence never runs beside another.
#     4. The unit does not depend on a unit in the batch.
#   A unit never moves ahead of an earlier unit, so the dependency order holds.
#
# Output (stdout, key=value)
#   max_concurrent=2
#   batches=<n>
#   batch.<k>=<id>[,<id>]        the units of batch k
#   mode.<k>=parallel|sequential parallel when batch k holds two units
#   reason.<id>=<why>            only for a unit that started a new batch because
#                                it could not join the previous one:
#                                critical:<id> | no-file-evidence:<id> |
#                                depends-on:<id> | files-overlap:<id>:<path> (the
#                                path is the later unit's own entry) |
#                                batch-full
#
# Step mode (--step)
#   The batch mode above sees only the units of one request. Step mode decides
#   whether ONE step may start now, beside the runs already in progress in the
#   repository, and beside what the project memory recorded about shared
#   resources. The orchestrator gathers the evidence and this script decides;
#   it never reads the repository, the run state, or the memory itself.
#
#   One step line, any number of live lines, any number of shared lines:
#     step|<id>|<kind>|<tree>|<branch>|<depends-on>|<files>
#     live|<id>|<kind>|<tree>|<branch>|<files>
#     shared|<path>|<memory-slug>
#   <kind>   write: changes files, a branch, or a ledger. heavy: a gate run, a
#            dependency install, or a full test suite. read: reads only.
#   <tree>   `shared` for the shared working tree, or the relative path of a
#            worktree. A live run whose tree is not known (a gate-lock holder,
#            a brief) uses `unknown`; that value is refused on the step.
#   <branch> the branch the run holds, or empty. Same spelling rules as git.
#   shared   a path that the project memory records as changed by every write
#            run. It is the memory evidence; a step needs no file list for it.
#   A live id may repeat (one run holds both a write tree and the gate lock).
#   The step id must not be a live id: the step's own run is not evidence.
#   A step is judged against each live run in input order; the first run that
#   blocks it is the blocker, and the step waits for it. Checks, in this order:
#     1. depends-on   the step waits for the result of a live run.
#     2. heavy-job    a heavy step never runs beside a live heavy run, whatever
#                     the tree: the machine runs one heavy job at a time.
#     3. tree-in-use  a write or heavy step never runs in a tree that a live
#                     write or heavy run uses; a live run in an unknown tree
#                     counts as using the shared tree.
#     4. branch-in-use a write or heavy step never takes the branch that a live
#                     write or heavy run holds.
#     5. a write step beside a live write run: shared-resource when the memory
#        names a shared path, then no-file-evidence when either side lists no
#        file, then files-overlap.
#   A read step waits only for a run it depends on, and a live read run blocks
#   nobody. A heavy step still takes the machine gate lock when it starts.
#
#   Output (stdout, key=value)
#     step=<id>
#     decision=parallel|wait
#     lock=gate                  only for a heavy step
#     blocker=<live id>          only when the decision is wait
#     reason=<why>               only when the decision is wait:
#       depends-on:<id> | heavy-job:<id> | tree-in-use:<id>:<tree> |
#       branch-in-use:<id>:<branch> | shared-resource:<id>:<path>:<slug> |
#       no-file-evidence:<id> | files-overlap:<id>:<path>
#
# Exit codes
#   0  a plan or a decision was produced
#   1  usage error or malformed input
set -euo pipefail
# A file entry is a word, never a pattern: no unquoted expansion may match a file of the working directory.
set -f

PROG="${0##*/}"
MAX_CONCURRENT=2

usage() {
  cat >&2 <<'USAGE'
Usage: plan-parallel.sh < units     one "<id>|<tier>|<depends-on>|<files>" per line
       plan-parallel.sh --step < evidence   one step line, live lines, shared lines
       plan-parallel.sh --self-test
USAGE
}

die() {
  echo "$PROG: $1" >&2
  exit 1
}

IDS=()
TIERS=()
DEPS=()
FILES=()

# Two file entries collide when they are equal, or when one is a directory that
# contains the other. An entry names a directory with a trailing slash, and an
# entry without one counts as a directory too when the other entry lies below it
# (`src` against `src/A.php`), so the caller's spelling never decides.
paths_collide() {
  local a="${1%/}" b="${2%/}"
  [[ "$a" == "$b" ]] && return 0
  [[ "$b" == "$a"/* ]] && return 0
  [[ "$a" == "$b"/* ]] && return 0
  return 1
}

# Print the first colliding path between two units, or return 1.
first_collision() {
  local left="$1" right="$2" a b
  for a in $left; do
    for b in $right; do
      if paths_collide "$a" "$b"; then
        printf '%s' "$a"
        return 0
      fi
    done
  done
  return 1
}

# A file or worktree entry is a relative path in its one canonical spelling.
check_path_entry() {
  local owner="$1" file="$2"
  [[ "$file" =~ ^[A-Za-z0-9._@+/-]+$ && "$file" != /* && "$file" != *..* ]] || die "invalid file entry of $owner: $file"
  # A second spelling of one path must not decide the plan: refuse it.
  [[ "$file" != ./* && "$file" != */. && "$file" != . && "$file" != *//* && "$file" != */./* ]] || die "non-canonical file entry of $owner: $file"
}

has_dependency() {
  local unit_deps="$1" other="$2" dep
  local IFS=','
  for dep in $unit_deps; do
    [[ "$dep" == "$other" ]] && return 0
  done
  return 1
}

read_units() {
  local line id tier deps files index seen dep file extra
  while IFS= read -r line || [[ -n "$line" ]]; do
    [[ -z "${line//[[:space:]]/}" || "$line" == '#'* ]] && continue
    IFS='|' read -r id tier deps files extra <<<"$line"
    [[ -z "$extra" ]] || die "a unit has more than four fields: $line"
    [[ "$id" =~ ^[A-Za-z0-9._-]{1,64}$ ]] || die "invalid unit id: $id"
    case "$tier" in
    FAST | STANDARD | CRITICAL) ;;
    *) die "invalid tier for $id: $tier" ;;
    esac
    for seen in ${IDS[@]+"${IDS[@]}"}; do
      [[ "$seen" != "$id" ]] || die "duplicate unit id: $id"
    done
    deps="${deps//[[:space:]]/}"
    local IFS=','
    for dep in $deps; do
      [[ "$dep" =~ ^[A-Za-z0-9._-]{1,64}$ ]] || die "invalid dependency of $id: $dep"
    done
    unset IFS
    for file in $files; do
      check_path_entry "$id" "$file"
    done
    IDS+=("$id")
    TIERS+=("$tier")
    DEPS+=("$deps")
    FILES+=("$files")
  done

  [[ "${#IDS[@]}" -gt 0 ]] || die 'no unit given'

  # A dependency on a later unit means the input order is wrong.
  for index in "${!IDS[@]}"; do
    local later
    for ((later = index + 1; later < ${#IDS[@]}; later++)); do
      if has_dependency "${DEPS[$index]}" "${IDS[$later]}"; then
        die "${IDS[$index]} depends on ${IDS[$later]}, which comes later — list dependencies first"
      fi
    done
  done
}

plan() {
  local batches=0 index member reason collision
  local -a batch_members=() batch_reason=()
  local -a current=()

  for index in "${!IDS[@]}"; do
    reason=''
    if [[ "${#current[@]}" -gt 0 ]]; then
      if [[ "${#current[@]}" -ge "$MAX_CONCURRENT" ]]; then
        reason='batch-full'
      fi
      for member in ${current[@]+"${current[@]}"}; do
        [[ -z "$reason" ]] || break
        if [[ "${TIERS[$index]}" == CRITICAL ]]; then
          reason="critical:${IDS[$index]}"
        elif [[ "${TIERS[$member]}" == CRITICAL ]]; then
          reason="critical:${IDS[$member]}"
        elif [[ -z "${FILES[$index]}" ]]; then
          reason="no-file-evidence:${IDS[$index]}"
        elif [[ -z "${FILES[$member]}" ]]; then
          reason="no-file-evidence:${IDS[$member]}"
        elif has_dependency "${DEPS[$index]}" "${IDS[$member]}"; then
          reason="depends-on:${IDS[$member]}"
        elif collision="$(first_collision "${FILES[$index]}" "${FILES[$member]}")"; then
          reason="files-overlap:${IDS[$member]}:$collision"
        fi
      done
    fi

    if [[ "${#current[@]}" -gt 0 && -z "$reason" ]]; then
      current+=("$index")
    else
      if [[ "${#current[@]}" -gt 0 ]]; then
        batch_members+=("${current[*]}")
      fi
      [[ -z "$reason" ]] || batch_reason[${#batch_members[@]}]="${IDS[$index]}=$reason"
      current=("$index")
    fi
  done
  batch_members+=("${current[*]}")
  batches="${#batch_members[@]}"

  printf 'max_concurrent=%s\n' "$MAX_CONCURRENT"
  printf 'batches=%s\n' "$batches"
  local position members names mode
  for ((position = 0; position < batches; position++)); do
    names=''
    mode=sequential
    # shellcheck disable=SC2206
    members=(${batch_members[$position]})
    for member in "${members[@]}"; do
      names+="${names:+,}${IDS[$member]}"
    done
    [[ "${#members[@]}" -ge 2 ]] && mode=parallel
    printf 'batch.%s=%s\n' "$((position + 1))" "$names"
    printf 'mode.%s=%s\n' "$((position + 1))" "$mode"
    if [[ -n "${batch_reason[$position]:-}" ]]; then
      printf 'reason.%s\n' "${batch_reason[$position]}"
    fi
  done
}

LIVE_IDS=()
LIVE_KINDS=()
LIVE_TREES=()
LIVE_BRANCHES=()
LIVE_FILES=()
SHARED_PATHS=()
SHARED_SLUGS=()
STEP_ID=''
STEP_KIND=''
STEP_TREE=''
STEP_BRANCH=''
STEP_DEPS=''
STEP_FILES=''

check_id() {
  [[ "$2" =~ ^[A-Za-z0-9._-]{1,64}$ ]] || die "invalid $1: $2"
}

check_kind() {
  case "$2" in
  write | heavy | read) ;;
  *) die "invalid kind of $1: $2" ;;
  esac
}

# A tree is the shared working tree or the relative path of a worktree. The value
# `unknown` is the one honest spelling of a live run whose tree no file records
# (a gate-lock holder, a brief).
check_tree() {
  local owner="$1" tree="$2"
  case "$tree" in
  shared | unknown) return 0 ;;
  esac
  check_path_entry "$owner" "$tree"
  [[ "$tree" != */ ]] || die "non-canonical tree of $owner: $tree"
}

check_branch() {
  local owner="$1" branch="$2"
  [[ -z "$branch" ]] && return 0
  [[ "${#branch}" -le 100 && "$branch" =~ ^[A-Za-z0-9._@+-]+(/[A-Za-z0-9._@+-]+)*$ && "$branch" != *..* ]] || die "invalid branch of $owner: $branch"
}

check_files() {
  local owner="$1" files="$2" file
  for file in $files; do
    check_path_entry "$owner" "$file"
  done
}

read_evidence() {
  local line type f2 f3 f4 f5 f6 f7 extra deps seen dep
  while IFS= read -r line || [[ -n "$line" ]]; do
    [[ -z "${line//[[:space:]]/}" || "$line" == '#'* ]] && continue
    IFS='|' read -r type f2 f3 f4 f5 f6 f7 extra <<<"$line"
    case "$type" in
    step)
      [[ -z "$STEP_ID" ]] || die 'more than one step line'
      [[ -z "$extra" ]] || die "a step line has more than seven fields: $line"
      check_id 'step id' "$f2"
      check_kind "$f2" "$f3"
      [[ "$f4" != unknown ]] || die "the tree of step $f2 is not known"
      check_tree "$f2" "$f4"
      check_branch "$f2" "$f5"
      deps="${f6//[[:space:]]/}"
      local IFS=','
      for dep in $deps; do
        check_id "dependency of $f2" "$dep"
      done
      unset IFS
      check_files "$f2" "$f7"
      STEP_ID="$f2"
      STEP_KIND="$f3"
      STEP_TREE="$f4"
      STEP_BRANCH="$f5"
      STEP_DEPS="$deps"
      STEP_FILES="$f7"
      ;;
    live)
      [[ -z "$f7$extra" ]] || die "a live line has more than six fields: $line"
      check_id 'live id' "$f2"
      check_kind "$f2" "$f3"
      check_tree "$f2" "$f4"
      check_branch "$f2" "$f5"
      check_files "$f2" "$f6"
      LIVE_IDS+=("$f2")
      LIVE_KINDS+=("$f3")
      LIVE_TREES+=("$f4")
      LIVE_BRANCHES+=("$f5")
      LIVE_FILES+=("$f6")
      ;;
    shared)
      [[ -z "$f4$f5$f6$f7$extra" ]] || die "a shared line has more than three fields: $line"
      check_path_entry 'a shared line' "$f2"
      check_id 'memory slug' "$f3"
      SHARED_PATHS+=("$f2")
      SHARED_SLUGS+=("$f3")
      ;;
    *) die "unknown line type: $type" ;;
    esac
  done

  [[ -n "$STEP_ID" ]] || die 'no step line given'
  for seen in ${LIVE_IDS[@]+"${LIVE_IDS[@]}"}; do
    [[ "$seen" != "$STEP_ID" ]] || die "the step $STEP_ID is also a live run"
  done
}

# Print the reason that live run $1 blocks the step, or return 1 when it does not.
# The step's own checks run in the order the header lists them.
step_blocker_reason() {
  local index="$1" id="${LIVE_IDS[$1]}" kind="${LIVE_KINDS[$1]}" tree="${LIVE_TREES[$1]}"
  local branch="${LIVE_BRANCHES[$1]}" files="${LIVE_FILES[$1]}" collision

  if has_dependency "$STEP_DEPS" "$id"; then
    printf 'depends-on:%s' "$id"
    return 0
  fi
  [[ "$STEP_KIND" != read && "$kind" != read ]] || return 1
  if [[ "$STEP_KIND" == heavy && "$kind" == heavy ]]; then
    printf 'heavy-job:%s' "$id"
    return 0
  fi
  if [[ "$tree" == "$STEP_TREE" || ("$tree" == unknown && "$STEP_TREE" == shared) ]]; then
    printf 'tree-in-use:%s:%s' "$id" "$tree"
    return 0
  fi
  if [[ -n "$branch" && "$branch" == "$STEP_BRANCH" ]]; then
    printf 'branch-in-use:%s:%s' "$id" "$branch"
    return 0
  fi
  [[ "$STEP_KIND" == write && "$kind" == write ]] || return 1
  if [[ "${#SHARED_PATHS[@]}" -gt 0 ]]; then
    printf 'shared-resource:%s:%s:%s' "$id" "${SHARED_PATHS[0]}" "${SHARED_SLUGS[0]}"
  elif [[ -z "$STEP_FILES" ]]; then
    printf 'no-file-evidence:%s' "$STEP_ID"
  elif [[ -z "$files" ]]; then
    printf 'no-file-evidence:%s' "$id"
  elif collision="$(first_collision "$STEP_FILES" "$files")"; then
    printf 'files-overlap:%s:%s' "$id" "$collision"
  else
    return 1
  fi
}

decide_step() {
  local index reason=''
  for index in ${LIVE_IDS[@]+"${!LIVE_IDS[@]}"}; do
    if reason="$(step_blocker_reason "$index")"; then
      break
    fi
    reason=''
  done

  printf 'step=%s\n' "$STEP_ID"
  if [[ -n "$reason" ]]; then
    printf 'decision=wait\n'
  else
    printf 'decision=parallel\n'
  fi
  [[ "$STEP_KIND" != heavy ]] || printf 'lock=gate\n'
  if [[ -n "$reason" ]]; then
    printf 'blocker=%s\n' "${LIVE_IDS[$index]}"
    printf 'reason=%s\n' "$reason"
  fi
}

SELF_TEST_FAILURES=0

expect_plan() {
  local label="$1" input="$2" expected="$3" actual
  actual="$(printf '%s\n' "$input" | "$0" 2>&1 | tr '\n' ' ')" || true
  if [[ "$actual" == "$expected" ]]; then
    printf 'ok    %s\n' "$label"
  else
    printf 'FAIL  %s\n        expected: %s\n        actual:   %s\n' "$label" "$expected" "$actual" >&2
    SELF_TEST_FAILURES=$((SELF_TEST_FAILURES + 1))
  fi
}

expect_step() {
  local label="$1" input="$2" expected="$3" actual
  actual="$(printf '%s\n' "$input" | "$0" --step 2>&1 | tr '\n' ' ')" || true
  if [[ "$actual" == "$expected" ]]; then
    printf 'ok    %s\n' "$label"
  else
    printf 'FAIL  %s\n        expected: %s\n        actual:   %s\n' "$label" "$expected" "$actual" >&2
    SELF_TEST_FAILURES=$((SELF_TEST_FAILURES + 1))
  fi
}

expect_step_refusal() {
  local label="$1" input="$2" code=0
  printf '%s\n' "$input" | "$0" --step >/dev/null 2>&1 || code=$?
  if [[ "$code" -eq 1 ]]; then
    printf 'ok    %s\n' "$label"
  else
    printf 'FAIL  %s (exit %s)\n' "$label" "$code" >&2
    SELF_TEST_FAILURES=$((SELF_TEST_FAILURES + 1))
  fi
}

expect_refusal() {
  local label="$1" input="$2" code=0
  printf '%s\n' "$input" | "$0" >/dev/null 2>&1 || code=$?
  if [[ "$code" -eq 1 ]]; then
    printf 'ok    %s\n' "$label"
  else
    printf 'FAIL  %s (exit %s)\n' "$label" "$code" >&2
    SELF_TEST_FAILURES=$((SELF_TEST_FAILURES + 1))
  fi
}

# Run the planner from a directory where a glob matches a file, so an unquoted
# expansion would turn the pattern into a valid path. Exit 1 is the refusal.
expect_glob_refusal() {
  local label="$1" plan="$2" step="$3" dir script code=0
  dir="$(mktemp -d)"
  : >"$dir/A.php"
  script="$(cd "$(dirname "$0")" && pwd)/${0##*/}"
  if [[ -n "$step" ]]; then
    (cd "$dir" && printf '%s\n' "$step" | "$script" --step >/dev/null 2>&1) || code=$?
  else
    (cd "$dir" && printf '%s\n' "$plan" | "$script" >/dev/null 2>&1) || code=$?
  fi
  rm -rf "$dir"
  if [[ "$code" -eq 1 ]]; then
    printf 'ok    %s\n' "$label"
  else
    printf 'FAIL  %s (exit %s)\n' "$label" "$code" >&2
    SELF_TEST_FAILURES=$((SELF_TEST_FAILURES + 1))
  fi
}

self_test() {
  local head='max_concurrent=2 '

  expect_plan 'one unit is one sequential batch' \
    'a|STANDARD||src/A.php' \
    "${head}batches=1 batch.1=a mode.1=sequential "

  expect_plan 'two units with disjoint files run in parallel' \
    $'a|STANDARD||src/A.php\nb|FAST||src/B.php docs/b.md' \
    "${head}batches=1 batch.1=a,b mode.1=parallel "

  expect_plan 'two units that change the same file run one after the other' \
    $'a|STANDARD||src/A.php CHANGELOG.md\nb|STANDARD||src/B.php CHANGELOG.md' \
    "${head}batches=2 batch.1=a mode.1=sequential batch.2=b mode.2=sequential reason.b=files-overlap:a:CHANGELOG.md "

  expect_plan 'a directory entry covers every file below it' \
    $'a|STANDARD||src/Actions/\nb|STANDARD||src/Actions/Make.php' \
    "${head}batches=2 batch.1=a mode.1=sequential batch.2=b mode.2=sequential reason.b=files-overlap:a:src/Actions/Make.php "

  expect_plan 'a directory entry without the trailing slash covers every file below it' \
    $'a|STANDARD||src\nb|STANDARD||src/A.php' \
    "${head}batches=2 batch.1=a mode.1=sequential batch.2=b mode.2=sequential reason.b=files-overlap:a:src/A.php "

  expect_plan 'a file below a directory entry without the trailing slash is a collision from the other side' \
    $'a|STANDARD||src/A.php\nb|STANDARD||src' \
    "${head}batches=2 batch.1=a mode.1=sequential batch.2=b mode.2=sequential reason.b=files-overlap:a:src "

  expect_plan 'a sibling path with a common prefix is not a collision' \
    $'a|STANDARD||src/Action/\nb|STANDARD||src/ActionBar.php' \
    "${head}batches=1 batch.1=a,b mode.1=parallel "

  expect_plan 'a dependency keeps the dependent in a later batch' \
    $'a|STANDARD||src/A.php\nb|STANDARD|a|src/B.php' \
    "${head}batches=2 batch.1=a mode.1=sequential batch.2=b mode.2=sequential reason.b=depends-on:a "

  expect_plan 'a dependency on a unit outside the request is already done' \
    $'a|STANDARD|old-issue|src/A.php\nb|STANDARD||src/B.php' \
    "${head}batches=1 batch.1=a,b mode.1=parallel "

  expect_plan 'a CRITICAL unit never runs beside another unit' \
    $'a|CRITICAL||src/A.php\nb|FAST||docs/b.md' \
    "${head}batches=2 batch.1=a mode.1=sequential batch.2=b mode.2=sequential reason.b=critical:a "

  expect_plan 'a CRITICAL unit that follows a free unit starts its own batch' \
    $'a|FAST||docs/a.md\nb|CRITICAL||src/B.php' \
    "${head}batches=2 batch.1=a mode.1=sequential batch.2=b mode.2=sequential reason.b=critical:b "

  expect_plan 'a unit without file evidence never runs beside another unit' \
    $'a|STANDARD||src/A.php\nb|STANDARD||' \
    "${head}batches=2 batch.1=a mode.1=sequential batch.2=b mode.2=sequential reason.b=no-file-evidence:b "

  expect_plan 'at most two units run at the same time' \
    $'a|FAST||a.md\nb|FAST||b.md\nc|FAST||c.md\nd|FAST||d.md\ne|FAST||e.md' \
    "${head}batches=3 batch.1=a,b mode.1=parallel batch.2=c,d mode.2=parallel reason.c=batch-full batch.3=e mode.3=sequential reason.e=batch-full "

  expect_plan 'a unit never moves ahead of an earlier unit' \
    $'a|STANDARD||src/A.php\nb|STANDARD||src/A.php\nc|STANDARD||src/C.php' \
    "${head}batches=2 batch.1=a mode.1=sequential batch.2=b,c mode.2=parallel reason.b=files-overlap:a:src/A.php "

  expect_plan 'comments and blank lines are skipped' \
    $'# a comment\n\na|FAST||a.md\nb|FAST||b.md' \
    "${head}batches=1 batch.1=a,b mode.1=parallel "

  expect_refusal 'an unknown tier is refused' 'a|HUGE||a.md'
  expect_refusal 'an id with a path separator is refused' '../a|FAST||a.md'
  expect_refusal 'a duplicate id is refused' $'a|FAST||a.md\na|FAST||b.md'
  expect_refusal 'a dependency on a later unit is refused' $'a|FAST|b|a.md\nb|FAST||b.md'
  expect_refusal 'a file entry that climbs out of the project is refused' 'a|FAST||../x.md'
  expect_refusal 'an absolute file entry is refused' 'a|FAST||/etc/passwd'
  expect_refusal 'a file entry with a leading dot segment is refused' 'a|FAST||./src/A.php'
  expect_refusal 'a file entry with a doubled slash is refused' 'a|FAST||src//A.php'
  expect_refusal 'a file entry with an inner dot segment is refused' 'a|FAST||src/./A.php'
  expect_refusal 'a unit with five fields is refused' 'a|FAST||a.md|extra'
  expect_refusal 'empty input is refused' ''

  # Step mode: one step against the runs already in progress and the memory.
  local wt_a='.claude/worktrees/run-a' wt_b='.claude/worktrees/run-b'

  expect_step 'a step with no live run starts' \
    'step|a|write|shared|feat/a||src/A.php' \
    'step=a decision=parallel '

  expect_step 'a gate in its own worktree starts beside a writer on the shared tree and in another worktree' \
    $'step|g|heavy|'"$wt_a"$'|feat/g||\nlive|w1|write|shared|feat/w1|src/A.php\nlive|w2|write|'"$wt_b"$'|feat/w2|src/B.php' \
    'step=g decision=parallel lock=gate '

  expect_step 'a gate never runs beside a live heavy run, whatever its tree' \
    $'step|g|heavy|'"$wt_a"$'|feat/g||\nlive|h1|heavy|'"$wt_b"$'||' \
    'step=g decision=wait lock=gate blocker=h1 reason=heavy-job:h1 '

  expect_step 'a gate never runs in a tree that a live writer uses' \
    $'step|g|heavy|shared|feat/g||\nlive|w1|write|shared|feat/w1|src/A.php' \
    'step=g decision=wait lock=gate blocker=w1 reason=tree-in-use:w1:shared '

  expect_step 'a write step never takes the tree of a live gate run' \
    $'step|a|write|'"$wt_a"$'|feat/a||src/A.php\nlive|g1|heavy|'"$wt_a"$'||' \
    'step=a decision=wait blocker=g1 reason=tree-in-use:g1:'"$wt_a"' '

  expect_step 'a write step on the shared tree never runs beside a live writer on it' \
    $'step|a|write|shared|feat/a||src/A.php\nlive|w1|write|shared|feat/w1|src/B.php' \
    'step=a decision=wait blocker=w1 reason=tree-in-use:w1:shared '

  expect_step 'a gate holder in an unknown tree counts as using the shared tree' \
    $'step|a|write|shared|feat/a||src/A.php\nlive|g1|heavy|unknown||' \
    'step=a decision=wait blocker=g1 reason=tree-in-use:g1:unknown '

  expect_step 'a brief in an unknown tree counts as using the shared tree' \
    $'step|a|write|shared|feat/a||src/A.php\nlive|b|write|unknown|feat/b|src/B.php' \
    'step=a decision=wait blocker=b reason=tree-in-use:b:unknown '

  expect_step 'a gate holder in an unknown tree does not block a write step in a worktree' \
    $'step|a|write|'"$wt_a"$'|feat/a||src/A.php\nlive|g1|heavy|unknown||' \
    'step=a decision=parallel '

  expect_step 'a write step beside a live writer in another worktree with disjoint files starts' \
    $'step|a|write|'"$wt_a"$'|feat/a||src/A.php\nlive|w1|write|'"$wt_b"$'|feat/w1|src/B.php' \
    'step=a decision=parallel '

  expect_step 'a write step never changes a file that a live writer changes' \
    $'step|a|write|'"$wt_a"$'|feat/a||src/A.php CHANGELOG.md\nlive|w1|write|'"$wt_b"$'|feat/w1|CHANGELOG.md' \
    'step=a decision=wait blocker=w1 reason=files-overlap:w1:CHANGELOG.md '

  expect_step 'a write step never takes the branch of a live run' \
    $'step|a|write|'"$wt_a"$'|feat/a||src/A.php\nlive|w1|write|'"$wt_b"$'|feat/a|src/B.php' \
    'step=a decision=wait blocker=w1 reason=branch-in-use:w1:feat/a '

  expect_step 'a step waits for the live run it depends on' \
    $'step|b|write|'"$wt_a"$'|feat/b|w1|src/B.php\nlive|w1|read|'"$wt_b"$'||' \
    'step=b decision=wait blocker=w1 reason=depends-on:w1 '

  expect_step 'a read step starts beside a live writer on the same tree' \
    $'step|r|read|shared|||\nlive|w1|write|shared|feat/w1|src/A.php' \
    'step=r decision=parallel '

  expect_step 'a live read run blocks no write step' \
    $'step|a|write|shared|feat/a||src/A.php\nlive|r1|read|shared||' \
    'step=a decision=parallel '

  expect_step 'a write step without file evidence never runs beside a live writer' \
    $'step|a|write|'"$wt_a"$'|feat/a||\nlive|w1|write|'"$wt_b"$'|feat/w1|src/B.php' \
    'step=a decision=wait blocker=w1 reason=no-file-evidence:a '

  expect_step 'a live writer without file evidence blocks a write step' \
    $'step|a|write|'"$wt_a"$'|feat/a||src/A.php\nlive|w1|write|'"$wt_b"$'|feat/w1|' \
    'step=a decision=wait blocker=w1 reason=no-file-evidence:w1 '

  expect_step 'a shared resource that the memory records serialises two write steps' \
    $'step|a|write|'"$wt_a"$'|feat/a||src/A.php\nlive|w1|write|'"$wt_b"$'|feat/w1|src/B.php\nshared|CHANGELOG.md|every-pr-edits-the-changelog' \
    'step=a decision=wait blocker=w1 reason=shared-resource:w1:CHANGELOG.md:every-pr-edits-the-changelog '

  expect_step 'a shared resource that the memory records does not block a gate in its own worktree' \
    $'step|g|heavy|'"$wt_a"$'|feat/g||\nlive|w1|write|'"$wt_b"$'|feat/w1|src/B.php\nshared|CHANGELOG.md|every-pr-edits-the-changelog' \
    'step=g decision=parallel lock=gate '

  expect_step 'the first live run that blocks the step is the blocker' \
    $'step|a|write|'"$wt_a"$'|feat/a||src/A.php\nlive|w1|write|'"$wt_b"$'|feat/w1|src/B.php\nlive|w2|write|shared|feat/w2|src/A.php' \
    'step=a decision=wait blocker=w2 reason=files-overlap:w2:src/A.php '

  expect_step 'one run that holds a tree and the gate lock is two live lines' \
    $'step|g|heavy|'"$wt_a"$'|feat/g||\nlive|w1|write|'"$wt_b"$'|feat/w1|src/B.php\nlive|w1|heavy|'"$wt_b"$'||' \
    'step=g decision=wait lock=gate blocker=w1 reason=heavy-job:w1 '

  expect_step_refusal 'a step input without a step line is refused' 'live|w1|write|shared||src/A.php'
  expect_step_refusal 'two step lines are refused' $'step|a|write|shared|||src/A.php\nstep|b|write|shared|||src/B.php'
  expect_step_refusal 'a step that is also a live run is refused' $'step|a|write|shared|||src/A.php\nlive|a|write|'"$wt_b"'|feat/a|src/B.php'
  expect_step_refusal 'a step in an unknown tree is refused' 'step|a|write|unknown|||src/A.php'
  expect_step_refusal 'an unknown kind is refused' 'step|a|build|shared|||src/A.php'
  expect_step_refusal 'an absolute tree is refused' 'step|a|write|/tmp/x|||src/A.php'
  expect_step_refusal 'a tree with a trailing slash is refused' 'step|a|write|.claude/worktrees/run-a/|||src/A.php'
  expect_step_refusal 'a branch that climbs out is refused' 'step|a|write|shared|feat/../x||src/A.php'
  expect_step_refusal 'a branch with a space is refused' $'step|a|write|shared|feat x||src/A.php'
  expect_step_refusal 'a step line with eight fields is refused' 'step|a|write|shared|feat/a||src/A.php|extra'
  expect_step_refusal 'a live line with seven fields is refused' $'step|a|write|shared|||src/A.php\nlive|w1|write|shared||src/B.php|extra'
  expect_step_refusal 'an unknown line type is refused' $'step|a|write|shared|||src/A.php\nnote|x'
  expect_step_refusal 'a shared line with a bad slug is refused' $'step|a|write|shared|||src/A.php\nshared|CHANGELOG.md|../x'
  expect_step_refusal 'a shared line with an absolute path is refused' $'step|a|write|shared|||src/A.php\nshared|/etc/passwd|slug'
  expect_step_refusal 'empty step input is refused' ''

  expect_glob_refusal 'a glob in a unit file list is refused, never expanded' 'a|STANDARD||*' ''
  expect_glob_refusal 'a glob in a step file list is refused, never expanded' '' 'step|a|write|shared|||*'
  expect_glob_refusal 'a glob in a live file list is refused, never expanded' '' $'step|a|write|shared|||src/A.php\nlive|w1|write|shared||*'

  if [[ "$SELF_TEST_FAILURES" -gt 0 ]]; then
    echo "plan-parallel self-test: $SELF_TEST_FAILURES failure(s)" >&2
    return 1
  fi
  echo 'plan-parallel self-test: PASS'
}

if [[ "${1:-}" == '--self-test' ]]; then
  self_test
  exit $?
fi

if [[ "${1:-}" == '--step' && $# -eq 1 ]]; then
  read_evidence
  decide_step
  exit 0
fi

if [[ $# -gt 0 ]]; then
  usage
  exit 1
fi

read_units
plan
