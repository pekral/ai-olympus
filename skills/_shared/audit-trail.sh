#!/usr/bin/env bash
# audit-trail.sh — write and check the run's audit trail (`.claude/run/<source-slug>.audit`).
#
# Why this exists
#   `rules/compound-engineering/orchestration.md` *Audit trail* asks every agent to append one
#   line per external write. An agent that writes the line by hand forgets it, writes it with an
#   extra leading field, takes the exit status from a `tail` pipe stage, or writes it long after
#   the action with a reconstructed time. Each of those costs a review round. This script moves
#   the line to the moment of the write: the package's write wrappers and `run` below append it
#   themselves, with the real clock time, the acting role, and the real exit status of the write.
#
# Usage
#   audit-trail.sh append --class <class> --target <text> --result <text> [--role R] [--file F]
#       Append one raw line. <class> is memory-read, outbound-request, external-write, or note.
#   audit-trail.sh record --target <text> --status <exit status> [--detail <text>] [--role R] [--file F]
#       Append the line for one write attempt: status 0 is an `external-write` line, any other
#       status is a `note` line that names the failed attempt and its exit status.
#   audit-trail.sh run [--target <text>] [--role R] [--file F] -- <write command...>
#       Run one write command, then `record` its own exit status. The command's stdout, stderr,
#       and exit status pass through unchanged. Allowed commands: `git push`,
#       `gh pr create|ready|edit|close|merge|update-branch|reopen|comment|review`,
#       `gh issue create|edit|close|reopen|comment`, `gh label create|edit|delete`.
#   audit-trail.sh verify --file F [--branch B] [--pr N|URL] [--remote R]
#       Check the file's line shape and compare the writes it can observe with the ledger.
#   audit-trail.sh --self-test
#
# Inputs
#   --file / AUDIT_TRAIL_FILE   the run's `.audit` file. Without it no line is written and a hint
#                               goes to stderr; the write itself is never blocked by it.
#   --role / AUDIT_TRAIL_ROLE   the acting agent, the FIRST field of the line (`donatello`).
#
# Line shape (one line per event, appended at the moment of the action)
#   <role>|<UTC ISO-8601>|<class>|<target>|<result>
#
# verify checks
#   - every line has exactly five fields, a role first, an ISO-8601 time second, and a class third;
#     a malformed line that a later `note` line names (`line <N>` or the line's own text) is resolved;
#   - with --branch, the remote head of the branch appears in an `external-write` line;
#   - with --pr, the pull request's URL appears in an `external-write` line.
#   A check that needs the network and cannot run prints `not verified: …` and never fails verify.
#
# Output (stdout of verify): `problem: …` and `not verified: …` lines, or `audit trail ok: …`.
#
# Exit codes
#   0   written / verified (for `run`: the write command's own exit status, 0 included)
#   1   usage error (not for `run`, which passes the command's status through)
#   3   verify found a problem
#   64  `run` refuses a command that is not an allowed write
#   Any other status of `run` is the status of the write command.
set -euo pipefail

PROG="${0##*/}"
CLASSES='memory-read outbound-request external-write note'
ISO_RE='^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(\.[0-9]+)?(Z|[+-][0-9]{2}:?[0-9]{2})$'
ROLE_RE='^[a-z][a-z0-9-]*$'

usage() {
  cat >&2 <<'EOF'
Usage: audit-trail.sh append --class <class> --target <text> --result <text> [--role R] [--file F]
       audit-trail.sh record --target <text> --status <n> [--detail <text>] [--role R] [--file F]
       audit-trail.sh run [--target <text>] [--role R] [--file F] -- <write command...>
       audit-trail.sh verify --file F [--branch B] [--pr N|URL] [--remote R]
       audit-trail.sh --self-test

AUDIT_TRAIL_FILE and AUDIT_TRAIL_ROLE supply --file and --role when they are not given.
EOF
}

now_utc() { date -u +%Y-%m-%dT%H:%M:%SZ; }

# One field: no separator, no line break, no credential in a URL, bounded length.
clean_field() {
  printf '%s' "$1" \
    | tr '\r\n\t' '   ' \
    | tr '|' '/' \
    | tr -d '\000-\010\013\014\016-\037\177' \
    | sed -E -e 's#://[^/@ ]*@#://#g' -e 's/^ +//' -e 's/ +$//' \
    | cut -c1-300
}

HELD_LOCK=""
release_lock() {
  if [[ -n "$HELD_LOCK" ]]; then
    rmdir "$HELD_LOCK" 2>/dev/null || true
    HELD_LOCK=""
  fi
}

# The per-file append lock of `agents/krang.md` *Shared task brief*: mkdir, bounded wait, stale reclaim.
append_line() {
  local file="$1" role="$2" class="$3" target="$4" result="$5" line tries=0
  line="${role}|$(now_utc)|${class}|${target}|${result}"
  until mkdir "${file}.lock" 2>/dev/null; do
    sleep 0.2
    tries=$((tries + 1))
    [[ "$tries" -gt 50 ]] && rm -rf "${file}.lock"
  done
  HELD_LOCK="${file}.lock"
  trap release_lock EXIT
  printf '%s\n' "$line" >>"$file"
  release_lock
}

# Resolve --file and --role; return 1 (with a hint) when the ledger is not configured.
resolve_ledger() {
  local file="$1" role="$2" for_what="$3"
  FILE="${file:-${AUDIT_TRAIL_FILE:-}}"
  ROLE="${role:-${AUDIT_TRAIL_ROLE:-}}"
  if [[ -z "$FILE" || -z "$ROLE" ]]; then
    echo "$PROG: AUDIT_TRAIL_FILE and AUDIT_TRAIL_ROLE are not both set; no audit line written for: $for_what" >&2
    return 1
  fi
  if [[ ! "$ROLE" =~ $ROLE_RE ]]; then
    echo "$PROG: role must match $ROLE_RE" >&2
    return 1
  fi
  if [[ "$FILE" != *.audit || ! -d "$(dirname "$FILE")" ]]; then
    echo "$PROG: the audit file must be an existing directory's *.audit file: $(clean_field "$FILE")" >&2
    return 1
  fi
  return 0
}

class_is_valid() { [[ " $CLASSES " == *" $1 "* ]]; }

cmd_append() {
  local class="" target="" result="" role="" file=""
  while [[ $# -gt 0 ]]; do
    case "$1" in
    --class) [[ $# -ge 2 ]] || { usage; return 1; }; class="$2"; shift 2 ;;
    --target) [[ $# -ge 2 ]] || { usage; return 1; }; target="$2"; shift 2 ;;
    --result) [[ $# -ge 2 ]] || { usage; return 1; }; result="$2"; shift 2 ;;
    --role) [[ $# -ge 2 ]] || { usage; return 1; }; role="$2"; shift 2 ;;
    --file) [[ $# -ge 2 ]] || { usage; return 1; }; file="$2"; shift 2 ;;
    *) usage; return 1 ;;
    esac
  done
  class_is_valid "$class" || { echo "$PROG: unknown class: $(clean_field "$class")" >&2; return 1; }
  target="$(clean_field "$target")"
  result="$(clean_field "$result")"
  [[ -n "$target" && -n "$result" ]] || { echo "$PROG: --target and --result must not be empty" >&2; return 1; }
  resolve_ledger "$file" "$role" "$class $target" || return 1
  append_line "$FILE" "$ROLE" "$class" "$target" "$result"
}

cmd_record() {
  local target="" status="" detail="" role="" file=""
  while [[ $# -gt 0 ]]; do
    case "$1" in
    --target) [[ $# -ge 2 ]] || { usage; return 1; }; target="$2"; shift 2 ;;
    --status) [[ $# -ge 2 ]] || { usage; return 1; }; status="$2"; shift 2 ;;
    --detail) [[ $# -ge 2 ]] || { usage; return 1; }; detail="$2"; shift 2 ;;
    --role) [[ $# -ge 2 ]] || { usage; return 1; }; role="$2"; shift 2 ;;
    --file) [[ $# -ge 2 ]] || { usage; return 1; }; file="$2"; shift 2 ;;
    *) usage; return 1 ;;
    esac
  done
  [[ "$status" =~ ^[0-9]+$ ]] || { echo "$PROG: --status must be a number" >&2; return 1; }
  target="$(clean_field "$target")"
  detail="$(clean_field "$detail")"
  [[ -n "$target" ]] || { echo "$PROG: --target must not be empty" >&2; return 1; }
  resolve_ledger "$file" "$role" "$target" || return 1
  if [[ "$status" -eq 0 ]]; then
    append_line "$FILE" "$ROLE" external-write "$target" "${detail:-ok} (exit 0)"
  else
    append_line "$FILE" "$ROLE" note "no action performed" "$target failed (exit $status)${detail:+; $detail}"
  fi
}

# Describe an allowed write command in a bounded, secret-free target; return 1 when it is not allowed.
describe_command() {
  local -a argv=("$@")
  local tool="${argv[0]:-}" sub1 sub2 arg
  if [[ "$tool" == git && "${argv[1]:-}" == "-C" ]]; then
    argv=("git" "${argv[@]:3}")
  fi
  sub1="${argv[1]:-}"
  sub2="${argv[2]:-}"
  case "$tool $sub1" in
  "git push")
    local out="git push" count=0
    for arg in "${argv[@]:2}"; do
      [[ "$arg" == -* || ! "$arg" =~ ^[A-Za-z0-9._/:@+-]+$ || "$count" -ge 2 ]] && continue
      out="$out $arg"
      count=$((count + 1))
    done
    printf '%s' "$out"
    ;;
  "gh pr")
    [[ " create ready edit close merge update-branch reopen comment review " == *" $sub2 "* ]] || return 1
    arg="${argv[3]:-}"
    [[ "$arg" =~ ^[0-9]+$ || "$arg" =~ ^https://[^[:space:]]+$ ]] || arg=""
    printf 'gh pr %s%s' "$sub2" "${arg:+ $arg}"
    ;;
  "gh issue")
    [[ " create edit close reopen comment " == *" $sub2 "* ]] || return 1
    arg="${argv[3]:-}"
    [[ "$arg" =~ ^[0-9]+$ || "$arg" =~ ^https://[^[:space:]]+$ ]] || arg=""
    printf 'gh issue %s%s' "$sub2" "${arg:+ $arg}"
    ;;
  "gh label")
    [[ " create edit delete " == *" $sub2 "* ]] || return 1
    printf 'gh label %s' "$sub2"
    ;;
  *) return 1 ;;
  esac
}

cmd_run() {
  local target="" role="" file=""
  while [[ $# -gt 0 ]]; do
    case "$1" in
    --target) [[ $# -ge 2 ]] || { usage; return 1; }; target="$2"; shift 2 ;;
    --role) [[ $# -ge 2 ]] || { usage; return 1; }; role="$2"; shift 2 ;;
    --file) [[ $# -ge 2 ]] || { usage; return 1; }; file="$2"; shift 2 ;;
    --) shift; break ;;
    *) usage; return 1 ;;
    esac
  done
  [[ $# -gt 0 ]] || { usage; return 1; }

  local described
  if ! described="$(describe_command "$@")"; then
    echo "$PROG: refusing a command that is not an allowed write: $(clean_field "$1 ${2:-} ${3:-}")" >&2
    return 64
  fi

  local out rc=0
  out="$(mktemp)"
  # The command's own exit status: no pipe stage sits between it and `$?`.
  "$@" >"$out" || rc=$?
  cat -- "$out"

  local url head="" detail=""
  url="$(awk 'match($0, /https:\/\/[^ \t]+/) { print substr($0, RSTART, RLENGTH); exit }' "$out")"
  rm -f "$out"
  if [[ "$rc" -eq 0 && "$described" == "git push"* ]]; then
    if [[ "$1" == git && "${2:-}" == "-C" ]]; then
      head="$(git -C "$3" rev-parse --short=12 HEAD 2>/dev/null || true)"
    else
      head="$(git rev-parse --short=12 HEAD 2>/dev/null || true)"
    fi
    [[ -z "$head" ]] || detail="head $head"
  fi
  [[ -z "$target" ]] && target="$described${url:+ -> $url}"
  [[ -n "$target" ]] || target="$described"
  cmd_record --target "$target" --status "$rc" --detail "$detail" --role "$role" --file "$file" || true
  return "$rc"
}

cmd_verify() {
  local file="" branch="" pr="" remote="origin"
  while [[ $# -gt 0 ]]; do
    case "$1" in
    --file) [[ $# -ge 2 ]] || { usage; return 1; }; file="$2"; shift 2 ;;
    --branch) [[ $# -ge 2 ]] || { usage; return 1; }; branch="$2"; shift 2 ;;
    --pr) [[ $# -ge 2 ]] || { usage; return 1; }; pr="$2"; shift 2 ;;
    --remote) [[ $# -ge 2 ]] || { usage; return 1; }; remote="$2"; shift 2 ;;
    *) usage; return 1 ;;
    esac
  done
  file="${file:-${AUDIT_TRAIL_FILE:-}}"
  [[ -n "$file" ]] || { usage; return 1; }
  if [[ ! -r "$file" ]]; then
    echo "problem: the audit file is not readable: $(clean_field "$file")"
    return 3
  fi

  local -a problems=() notes=() bad_no=() bad_msg=() bad_raw=() note_no=() note_target=()
  local line lineno=0 nf f1 f2 f3 f4 f5 lines=0 msg
  while IFS= read -r line || [[ -n "$line" ]]; do
    lineno=$((lineno + 1))
    [[ -n "${line//[[:space:]]/}" ]] || continue
    lines=$((lines + 1))
    nf="$(awk -F'|' '{ print NF }' <<<"$line")"
    IFS='|' read -r f1 f2 f3 f4 f5 <<<"$line"
    msg=""
    if [[ "$f1" =~ $ISO_RE ]]; then
      msg="starts with a time, so the role field is missing or a field was added before it (shape: role|time|class|target|result)"
    elif [[ "$nf" -ne 5 ]]; then
      msg="has $nf fields, expected 5 (shape: role|time|class|target|result)"
    elif [[ ! "$f1" =~ $ROLE_RE ]]; then
      msg="first field is not a role: $(clean_field "$f1")"
    elif [[ ! "$f2" =~ $ISO_RE ]]; then
      msg="second field is not an ISO-8601 time: $(clean_field "$f2")"
    elif ! class_is_valid "$f3"; then
      msg="third field is not one of memory-read / outbound-request / external-write / note: $(clean_field "$f3")"
    elif [[ -z "${f4// }" || -z "${f5// }" ]]; then
      msg="target or result field is empty"
    elif [[ "$f3" == note ]]; then
      note_no+=("$lineno")
      note_target+=("$f4")
    fi
    if [[ -n "$msg" ]]; then
      bad_no+=("$lineno")
      bad_msg+=("$msg")
      bad_raw+=("$(clean_field "$line")")
    fi
  done <"$file"

  local i j resolved
  for ((i = 0; i < ${#bad_no[@]}; i++)); do
    resolved=0
    for ((j = 0; j < ${#note_no[@]}; j++)); do
      [[ "${note_no[$j]}" -gt "${bad_no[$i]}" ]] || continue
      if [[ "${note_target[$j]}" =~ line[[:space:]]+${bad_no[$i]}([^0-9]|$) || "${note_target[$j]}" == *"${bad_raw[$i]}"* ]]; then
        resolved=1
        break
      fi
    done
    [[ "$resolved" -eq 1 ]] || problems+=("line ${bad_no[$i]} ${bad_msg[$i]}")
  done

  local writes
  writes="$(awk -F'|' 'NF == 5 && $3 == "external-write"' "$file")"

  if [[ -n "$branch" ]]; then
    local head
    head="$(git ls-remote "$remote" "refs/heads/$branch" 2>/dev/null | awk '{ print $1; exit }' || true)"
    if [[ -z "$head" ]]; then
      notes+=("the remote head of $remote/$branch could not be read; run 'git ls-remote $remote refs/heads/$branch' and compare it with the ledger")
    elif ! grep -Fq -- "${head:0:7}" <<<"$writes"; then
      problems+=("remote branch $branch is at ${head:0:12}, and no external-write line names that commit; append it, or run the push through audit-trail.sh run")
    fi
  fi

  if [[ -n "$pr" ]]; then
    local pr_url
    pr_url="$(gh pr view "$pr" --json url --jq .url 2>/dev/null || true)"
    if [[ ! "$pr_url" =~ /pull/([0-9]+)$ ]]; then
      notes+=("the pull request $(clean_field "$pr") could not be read; run 'gh pr view' and compare its URL with the ledger")
    elif ! grep -Fq -- "/pull/${BASH_REMATCH[1]}" <<<"$writes"; then
      problems+=("pull request $pr_url has no external-write line; append it, or run the write through audit-trail.sh run")
    fi
  fi

  local item
  for item in ${notes[@]+"${notes[@]}"}; do
    printf 'not verified: %s\n' "$item"
  done
  if [[ "${#problems[@]}" -gt 0 ]]; then
    for item in "${problems[@]}"; do
      printf 'problem: %s\n' "$item"
    done
    return 3
  fi
  printf 'audit trail ok: %s line(s)\n' "$lines"
}

self_test() {
  local failures=0 script tmp
  script="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/$PROG"
  tmp="$(mktemp -d)"
  # shellcheck disable=SC2064
  trap "rm -rf '$tmp'" EXIT
  local ledger="$tmp/run.audit"
  : >"$ledger"

  ok() { printf 'ok    %s\n' "$1"; }
  bad() { printf 'FAIL  %s\n' "$1" >&2; failures=$((failures + 1)); }
  expect_eq() { if [[ "$2" == "$3" ]]; then ok "$1"; else bad "$1 (expected '$3', got '$2')"; fi; }
  last_line() { tail -n 1 "$ledger"; }
  line_count() { wc -l <"$ledger" | tr -d ' '; }
  field() { awk -F'|' -v n="$1" '{ print $n }' <<<"$2"; }

  export AUDIT_TRAIL_FILE="$ledger" AUDIT_TRAIL_ROLE=donatello

  # --- append: shape, role first, real clock time -------------------------------------------
  "$script" append --class external-write --target 'PR https://example.test/o/r/pull/1' --result 'created'
  local l
  l="$(last_line)"
  expect_eq 'append writes five fields' "$(awk -F'|' '{ print NF }' <<<"$l")" 5
  expect_eq 'the role is the FIRST field' "$(field 1 "$l")" donatello
  if [[ "$(field 2 "$l")" =~ $ISO_RE ]]; then ok 'the second field is a UTC ISO-8601 time'; else bad 'the second field is not a time'; fi
  expect_eq 'the third field is the class' "$(field 3 "$l")" external-write

  "$script" append --class external-write --target $'a|b\nc' --result 'x|y'
  l="$(last_line)"
  expect_eq 'a separator or newline in a field never adds a field' "$(awk -F'|' '{ print NF }' <<<"$l")" 5
  expect_eq 'a newline in a field never adds a line' "$(line_count)" 2

  local rc=0
  "$script" append --class git-push --target t --result r 2>/dev/null || rc=$?
  expect_eq 'an unknown class is a usage error' "$rc" 1
  expect_eq 'an unknown class writes no line' "$(line_count)" 2

  # --- record: the real exit status ---------------------------------------------------------
  "$script" record --target 'gh pr update-branch 7' --status 0
  l="$(last_line)"
  expect_eq 'record status 0 is an external-write line' "$(field 3 "$l")" external-write
  "$script" record --target 'gh pr update-branch 7' --status 1 --detail 'merge conflict'
  l="$(last_line)"
  expect_eq 'record with a failed status is a note line' "$(field 3 "$l")" note
  expect_eq 'the failed note says no action was performed' "$(field 4 "$l")" 'no action performed'
  if [[ "$(field 5 "$l")" == *'(exit 1)'* ]]; then ok 'the failed note carries the exit status'; else bad 'the failed note lacks the exit status'; fi

  # --- unset ledger: hint, no file, no block --------------------------------------------------
  rc=0
  (unset AUDIT_TRAIL_FILE; "$script" record --target t --status 0 2>"$tmp/hint") || rc=$?
  expect_eq 'an unset ledger makes record exit 1 with a hint' "$rc" 1
  if grep -q 'no audit line written' "$tmp/hint"; then ok 'an unset ledger prints a hint'; else bad 'an unset ledger printed no hint'; fi

  # --- run: fake gh, real git ---------------------------------------------------------------
  mkdir "$tmp/bin"
  cat >"$tmp/bin/gh" <<'EOF'
#!/usr/bin/env bash
echo "https://example.test/o/r/pull/9"
exit "${FAKE_GH_EXIT:-0}"
EOF
  chmod +x "$tmp/bin/gh"
  local before out
  before="$(line_count)"
  out="$(PATH="$tmp/bin:$PATH" "$script" run -- gh pr create --title x)"
  expect_eq 'run passes the command stdout through' "$out" 'https://example.test/o/r/pull/9'
  l="$(last_line)"
  expect_eq 'run records one line per write' "$(line_count)" "$((before + 1))"
  expect_eq 'run records a successful write as external-write' "$(field 3 "$l")" external-write
  if [[ "$(field 4 "$l")" == 'gh pr create -> https://example.test/o/r/pull/9' ]]; then ok 'run names the created pull request URL'; else bad "run target: $(field 4 "$l")"; fi

  rc=0
  PATH="$tmp/bin:$PATH" FAKE_GH_EXIT=5 "$script" run -- gh pr ready 9 >/dev/null 2>&1 || rc=$?
  expect_eq 'run exits with the write command status, not a pipe status' "$rc" 5
  l="$(last_line)"
  expect_eq 'run records a failed write as a note line' "$(field 3 "$l")" note
  if [[ "$(field 5 "$l")" == *'gh pr ready 9'*'failed (exit 5)'* ]]; then ok 'the failed write names its own exit status'; else bad "failed note: $(field 5 "$l")"; fi

  before="$(line_count)"
  rc=0
  "$script" run -- curl https://example.test >/dev/null 2>&1 || rc=$?
  expect_eq 'run refuses a command that is not an allowed write' "$rc" 64
  expect_eq 'a refused command writes no line' "$(line_count)" "$before"

  rc=0
  (unset AUDIT_TRAIL_FILE; PATH="$tmp/bin:$PATH" FAKE_GH_EXIT=3 "$script" run -- gh pr merge 9 >/dev/null 2>&1) || rc=$?
  expect_eq 'run never blocks or masks a write when the ledger is unset' "$rc" 3

  # --- run: git push on a real bare remote ---------------------------------------------------
  local remote="$tmp/remote.git" work="$tmp/work"
  git init -q --bare "$remote"
  git init -q "$work"
  (
    cd "$work"
    git config user.email t@example.test
    git config user.name t
    git checkout -q -b feature
    git commit -q --allow-empty -m one
    git remote add origin "$remote"
    "$script" run -- git push -q origin feature
  )
  l="$(last_line)"
  local head
  head="$(git -C "$work" rev-parse HEAD)"
  if [[ "$(field 5 "$l")" == *"head ${head:0:12}"* ]]; then ok 'a push line names the pushed head commit'; else bad "push line: $l"; fi
  expect_eq 'a push line names the push, never a branch-less target' "$(field 4 "$l")" 'git push origin feature'

  # --- verify -----------------------------------------------------------------------------
  rc=0
  "$script" verify --file "$ledger" >/dev/null || rc=$?
  expect_eq 'verify accepts the lines the script wrote' "$rc" 0

  if (cd "$work" && "$script" verify --file "$ledger" --branch feature --remote "$remote" >/dev/null); then
    ok 'verify accepts a remote head that the ledger names'
  else
    bad 'verify rejected a named remote head'
  fi

  if (
    cd "$work"
    git commit -q --allow-empty -m two
    git push -q origin feature
    rc=0
    "$script" verify --file "$ledger" --branch feature --remote "$remote" >"$tmp/verify.out" || rc=$?
    [[ "$rc" -eq 3 ]] && grep -q 'no external-write line names that commit' "$tmp/verify.out"
  ); then
    ok 'verify reports a push the ledger does not name'
  else
    bad 'verify missed an unrecorded push'
  fi

  local bad_ledger="$tmp/bad.audit"
  cat >"$bad_ledger" <<'EOF'
donatello|2026-11-11T10:00:00Z|external-write|git push origin feature|ok
2026-11-11T10:05:00Z|donatello|external-write|git push origin feature|ok
donatello|2026-11-11T10:06:00Z|git-push|origin feature|ok
krang|-|gather|delivered|2026-11-11T10:07:00Z
donatello|2026-11-11T10:08:00Z|external-write|gh pr create|ok|extra
EOF
  rc=0
  "$script" verify --file "$bad_ledger" >"$tmp/bad.out" || rc=$?
  expect_eq 'verify rejects malformed lines' "$rc" 3
  if grep -q 'line 2 starts with a time' "$tmp/bad.out"; then ok 'verify names a leading extra field'; else bad 'verify missed the extra leading field'; fi
  if grep -q 'line 3 third field is not one of' "$tmp/bad.out"; then ok 'verify names a malformed class such as git-push'; else bad 'verify missed the malformed class'; fi
  if grep -q 'line 4 ' "$tmp/bad.out"; then ok 'verify names a dispatch-ledger line'; else bad 'verify missed the dispatch-ledger line'; fi
  if grep -q 'line 5 has 6 fields' "$tmp/bad.out"; then ok 'verify names a line with extra fields'; else bad 'verify missed the six-field line'; fi

  printf 'donatello|2026-11-11T10:09:00Z|note|line 2 and line 3 and line 4 and line 5|corrected by the lines below\n' >>"$bad_ledger"
  rc=0
  "$script" verify --file "$bad_ledger" >"$tmp/bad.out" || rc=$?
  expect_eq 'a later note that names a malformed line resolves it' "$rc" 0

  rc=0
  "$script" verify --file "$tmp/missing.audit" >/dev/null || rc=$?
  expect_eq 'verify reports an unreadable file' "$rc" 3

  # --- a package wrapper records its own write: the GitHub comment publisher --------------------
  if command -v php >/dev/null 2>&1 && command -v jq >/dev/null 2>&1; then
    cat >"$tmp/bin/gh" <<'EOF'
#!/usr/bin/env bash
case "$*" in
"api user --jq .login") echo bot ;;
*"/comments --paginate"*) echo '[]' ;;
*"-X POST"*)
  cat >/dev/null
  if [[ "${FAKE_POST_RC:-0}" -ne 0 ]]; then echo '{"message":"boom"}'; exit "$FAKE_POST_RC"; fi
  echo '{"html_url":"https://github.com/o/r/issues/5#issuecomment-77","id":77}'
  ;;
esac
EOF
    chmod +x "$tmp/bin/gh"
    printf 'body\n' >"$tmp/body.md"
    local upsert
    upsert="$(cd "$(dirname "$script")/../code-review-github/scripts" && pwd)/upsert-comment.sh"
    before="$(line_count)"
    out="$(PATH="$tmp/bin:$PATH" "$upsert" https://github.com/o/r/issues/5 "$tmp/body.md" 2>/dev/null)"
    expect_eq 'the comment publisher keeps its stdout' "$out" 'https://github.com/o/r/issues/5#issuecomment-77'
    l="$(last_line)"
    expect_eq 'the comment publisher leaves exactly one line' "$(line_count)" "$((before + 1))"
    expect_eq 'the comment publisher line has the role first' "$(field 1 "$l")" donatello
    expect_eq 'the comment publisher line is an external-write' "$(field 3 "$l")" external-write
    expect_eq 'the comment publisher line names the comment URL' "$(field 4 "$l")" 'https://github.com/o/r/issues/5#issuecomment-77'

    rc=0
    PATH="$tmp/bin:$PATH" FAKE_POST_RC=1 "$upsert" https://github.com/o/r/issues/5 "$tmp/body.md" >/dev/null 2>&1 || rc=$?
    expect_eq 'a failed comment write keeps the publisher exit code 3' "$rc" 3
    l="$(last_line)"
    expect_eq 'a failed comment write leaves a note line' "$(field 3 "$l")" note
    if [[ "$(field 5 "$l")" == *'(exit 1)'* ]]; then ok 'the failed comment write names the request exit status'; else bad "failed comment note: $(field 5 "$l")"; fi

    rc=0
    out="$(unset AUDIT_TRAIL_FILE; PATH="$tmp/bin:$PATH" "$upsert" https://github.com/o/r/issues/5 "$tmp/body.md" 2>/dev/null)" || rc=$?
    expect_eq 'the comment publisher works without a ledger' "$rc" 0
    expect_eq 'the comment publisher stdout is the same without a ledger' "$out" 'https://github.com/o/r/issues/5#issuecomment-77'
  fi

  # --- concurrent appends never interleave -------------------------------------------------------
  local par="$tmp/par.audit" n
  : >"$par"
  for n in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 19 20; do
    "$script" append --file "$par" --class external-write --target "t$n" --result ok &
  done
  wait
  expect_eq 'twenty concurrent appends leave twenty lines' "$(wc -l <"$par" | tr -d ' ')" 20
  rc=0
  "$script" verify --file "$par" >/dev/null || rc=$?
  expect_eq 'twenty concurrent appends leave twenty well-formed lines' "$rc" 0

  if [[ "$failures" -gt 0 ]]; then
    echo "audit-trail self-test: $failures failure(s)" >&2
    return 4
  fi
  echo 'audit-trail self-test: PASS'
}

main() {
  case "${1:-}" in
  --self-test) self_test ;;
  append) shift; cmd_append "$@" ;;
  record) shift; cmd_record "$@" ;;
  run) shift; cmd_run "$@" ;;
  verify) shift; cmd_verify "$@" ;;
  *) usage; return 1 ;;
  esac
}

main "$@"
