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
#       status is a `note` line whose target is the attempt and whose result carries the exit status
#       and says the remote state was not verified (a failed write may have changed the remote).
#   audit-trail.sh run [--target <text>] [--role R] [--file F] -- <write command...>
#       Run one write command, then `record` its own exit status. The command's stdout, stderr,
#       and exit status pass through unchanged. Allowed commands: `git push`,
#       `gh pr create|ready|edit|close|merge|update-branch|reopen`,
#       `gh issue create|edit|close|reopen`, `gh label create|edit|delete`. A comment goes through
#       `upsert-comment.sh` only: it adds the agent marker, and a comment without one reads as the
#       operator's own.
#   audit-trail.sh verify --file F [--branch B] [--pr N|URL] [--issue N|URL] [--remote R]
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
#   - with --pr, the pull request's URL appears in an `external-write` line;
#   - with --pr or --issue, every comment of the current GitHub account that carries an agent marker
#     (`<!-- <namespace>:actor=`) and was made since the first ledger line has an `external-write` line.
#     A comment without a marker is the operator's own and is skipped;
#   - with --pr or --issue, every label the account added (`+label <name>`) or removed (`-label <name>`)
#     since the first ledger line is named by an `external-write` line. A label event carries no
#     marker, so an unrecorded one is `not verified:`, never a problem;
#   - a later `note` line whose target is `comment <id>` or `label <name>` resolves that comment or label.
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
       audit-trail.sh verify --file F [--branch B] [--pr N|URL] [--issue N|URL] [--remote R]
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
    echo "$PROG: the audit file (--file or AUDIT_TRAIL_FILE) must be an *.audit file in an existing directory" >&2
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
    append_line "$FILE" "$ROLE" note "$target" "write failed (exit $status)${detail:+; $detail}; the remote state was not verified"
  fi
}

# The positional arguments of `git push <args>` (the remote, then the refspecs), one per line.
push_positionals() {
  local skip=0 end=0 arg
  for arg in "$@"; do
    if [[ "$skip" -eq 1 ]]; then
      skip=0
      continue
    fi
    if [[ "$end" -eq 0 ]]; then
      case "$arg" in
      --) end=1; continue ;;
      -o | --push-option | --repo) skip=1; continue ;;
      -*) continue ;;
      esac
    fi
    printf '%s\n' "$arg"
  done
}

# The abbreviated commit a `git push <args>` publishes; empty when it cannot be resolved.
pushed_head() {
  local -a rev=(git) args=("${@:2}") pos=()
  local arg src
  if [[ "${args[0]:-}" == "-C" ]]; then
    rev+=(-C "${args[1]:-.}")
    args=("${args[@]:2}")
  fi
  args=("${args[@]:1}")
  for arg in ${args[@]+"${args[@]}"}; do
    # Pushes that name no single source commit: nothing to resolve.
    case "$arg" in --all | --mirror | --tags | --delete | -d) return 0 ;; esac
  done
  while IFS= read -r arg; do pos+=("$arg"); done < <(push_positionals ${args[@]+"${args[@]}"})
  src="HEAD"
  if [[ "${#pos[@]}" -ge 2 ]]; then
    src="${pos[1]#+}"
    src="${src%%:*}"
  fi
  [[ -n "$src" && "$src" != -* ]] || return 0
  "${rev[@]}" rev-parse --short=12 --verify --quiet "${src}^{commit}" 2>/dev/null || true
}

# The label changes of a `gh pr|issue edit` command as " +label <name>" / " -label <name>", so the
# ledger line names them and `verify` can match a labeled / unlabeled event to it.
label_changes() {
  local out="" prev="" arg value label
  for arg in "$@"; do
    value=""
    case "$arg" in
    --add-label=*) value="+${arg#--add-label=}" ;;
    --remove-label=*) value="-${arg#--remove-label=}" ;;
    *)
      case "$prev" in
      --add-label) value="+$arg" ;;
      --remove-label) value="-$arg" ;;
      esac
      ;;
    esac
    prev="$arg"
    [[ -n "$value" ]] || continue
    while IFS= read -r label; do
      [[ -z "$label" ]] || out="$out ${value:0:1}label $label"
    done < <(tr ',' '\n' <<<"${value:1}")
  done
  printf '%s' "$out"
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
    local out="git push" count=0 name
    for arg in "${argv[@]:2}"; do
      if [[ "$arg" == --* ]]; then
        name="${arg%%=*}"
        # An option that makes git run a program is no write: refuse it, and every abbreviation git accepts.
        if [[ "${#name}" -gt 2 && ("--receive-pack" == "$name"* || "--exec" == "$name"* || "--upload-pack" == "$name"*) ]]; then
          return 1
        fi
      fi
      # A `ext::<command>` remote runs a program too.
      [[ "$arg" == *::* ]] && return 1
    done
    while IFS= read -r arg; do
      [[ "$arg" =~ ^[A-Za-z0-9._/:@+-]+$ && "$count" -lt 2 ]] || continue
      out="$out $arg"
      count=$((count + 1))
    done < <(push_positionals "${argv[@]:2}")
    printf '%s' "$out"
    ;;
  "gh pr")
    [[ " create ready edit close merge update-branch reopen " == *" $sub2 "* ]] || return 1
    arg="${argv[3]:-}"
    [[ "$arg" =~ ^[0-9]+$ || "$arg" =~ ^https://[^[:space:]]+$ ]] || arg=""
    printf 'gh pr %s%s%s' "$sub2" "${arg:+ $arg}" "$(label_changes "${argv[@]:3}")"
    ;;
  "gh issue")
    [[ " create edit close reopen " == *" $sub2 "* ]] || return 1
    arg="${argv[3]:-}"
    [[ "$arg" =~ ^[0-9]+$ || "$arg" =~ ^https://[^[:space:]]+$ ]] || arg=""
    printf 'gh issue %s%s%s' "$sub2" "${arg:+ $arg}" "$(label_changes "${argv[@]:3}")"
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
    head="$(pushed_head "$@")"
    [[ -z "$head" ]] || detail="head $head"
  fi
  [[ -z "$target" ]] && target="$described${url:+ -> $url}"
  [[ -n "$target" ]] || target="$described"
  cmd_record --target "$target" --status "$rc" --detail "$detail" --role "$role" --file "$file" || true
  return "$rc"
}

# The agent comments and the label changes the current GitHub account made on one pull request or
# issue since <since>, each one a line "kind<TAB>id-or-label<TAB>time". A comment counts only when its
# body carries an agent marker (`<!-- <namespace>:actor=`): the account is shared with the operator,
# and a comment without a marker is the operator's own (`rules/code-review/general.md` *Authorship
# trust*). Return 1 when a read fails.
observe_item_writes() {
  local base="$1" login="$2" since="$3" out
  out="$(gh api "$base/comments" --paginate --jq '.[] | select((.body // "") | test("<!-- [a-z][a-z0-9-]*:actor=")) | [.user.login, .created_at, (.id | tostring)] | @tsv' 2>/dev/null)" || return 1
  awk -F'\t' -v login="$login" -v since="$since" '$1 == login && $2 >= since { printf "comment\t%s\t%s\n", $3, $2 }' <<<"$out"
  out="$(gh api "$base/timeline" --paginate --jq '.[] | select(.event == "labeled" or .event == "unlabeled") | [.actor.login, .created_at, .event, .label.name] | @tsv' 2>/dev/null)" || return 1
  awk -F'\t' -v login="$login" -v since="$since" '$1 == login && $2 >= since { printf "%s\t%s\t%s\n", $3, $4, $2 }' <<<"$out"
}

# 0 when an `external-write` line of `$writes` names that label change: "+label <name>" for labeled and
# "-label <name>" for unlabeled, as a whole token (followed by " +label", " -label", " ->", or the field end).
label_recorded() {
  LABEL_SIGN="$1" LABEL_NAME="$2" awk -F'|' '
    BEGIN { needle = " " ENVIRON["LABEL_SIGN"] "label " ENVIRON["LABEL_NAME"] }
    {
      target = " " $4
      offset = 0
      while ((at = index(substr(target, offset + 1), needle)) > 0) {
        offset += at
        rest = substr(target, offset + length(needle))
        if (rest == "" || rest ~ /^ [+-]label / || rest ~ /^ ->/) { found = 1 }
      }
    }
    END { exit found ? 0 : 1 }
  ' <<<"$writes"
}

# 0 when a later `note` line names that write: its target is exactly the text given (`comment <id>`, `label <name>`).
note_names() {
  local named
  for named in ${note_target[@]+"${note_target[@]}"}; do
    [[ "$named" == "$1" ]] && return 0
  done
  return 1
}

# Compare those writes with the ledger: an agent comment with no ledger line is a problem; a label
# change with none is not verified, because a label event cannot be told from the operator's own.
# Reads and writes the caller's `problems`, `notes`, `writes`, `note_target`, `since`, and `login` variables.
observe_item() {
  local item="$1" base kind id when observed sign name
  if [[ "$item" =~ ^https://[^/]+/([^/]+)/([^/]+)/(pull|issues)/([0-9]+) ]]; then
    base="repos/${BASH_REMATCH[1]}/${BASH_REMATCH[2]}/issues/${BASH_REMATCH[4]}"
  elif [[ "$item" =~ ^[0-9]+$ ]]; then
    base="repos/{owner}/{repo}/issues/$item"
  else
    notes+=("the item $(clean_field "$item") is not a number or a GitHub URL; its comments and labels were not compared with the ledger")
    return 0
  fi
  if ! observed="$(observe_item_writes "$base" "$login" "$since")"; then
    notes+=("the comments or the timeline of $(clean_field "$item") could not be read; run 'gh api $base/comments' and 'gh api $base/timeline' and compare them with the ledger")
    return 0
  fi
  while IFS=$'\t' read -r kind id when; do
    [[ -n "$kind" ]] || continue
    case "$kind" in
    comment)
      grep -Fq -- "issuecomment-$id" <<<"$writes" || note_names "comment $id" || problems+=("comment $id of $login on $(clean_field "$item") (created $when) carries an agent marker and has no external-write line; append the line when this run wrote it, or a note line with the target 'comment $id' when it did not")
      ;;
    *)
      sign="+"
      [[ "$kind" == unlabeled ]] && sign="-"
      name="$(clean_field "$id")"
      label_recorded "$sign" "$name" || note_names "label $name" || notes+=("label '$name' $kind by $login on $(clean_field "$item") (at $when) has no external-write line, and a label event carries no agent marker, so it cannot be told from the operator's own; when this run made it, run the write through audit-trail.sh run, otherwise append a note line with the target 'label $name'")
      ;;
    esac
  done <<<"$observed"
}

cmd_verify() {
  local file="" branch="" pr="" issue="" remote="origin"
  while [[ $# -gt 0 ]]; do
    case "$1" in
    --file) [[ $# -ge 2 ]] || { usage; return 1; }; file="$2"; shift 2 ;;
    --issue) [[ $# -ge 2 ]] || { usage; return 1; }; issue="$2"; shift 2 ;;
    --branch) [[ $# -ge 2 ]] || { usage; return 1; }; branch="$2"; shift 2 ;;
    --pr) [[ $# -ge 2 ]] || { usage; return 1; }; pr="$2"; shift 2 ;;
    --remote) [[ $# -ge 2 ]] || { usage; return 1; }; remote="$2"; shift 2 ;;
    *) usage; return 1 ;;
    esac
  done
  file="${file:-${AUDIT_TRAIL_FILE:-}}"
  [[ -n "$file" ]] || { usage; return 1; }
  if [[ ! -r "$file" ]]; then
    echo "problem: the audit file (--file or AUDIT_TRAIL_FILE) is not readable"
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

  if [[ -n "$pr" || -n "$issue" ]]; then
    local since login
    since="$(awk -F'|' 'NF == 5 && $2 ~ /Z$/ { print $2; exit }' "$file")"
    login="$(gh api user --jq .login 2>/dev/null || true)"
    if [[ -z "$since" || -z "$login" ]]; then
      notes+=("the comments and the label changes of the account could not be compared with the ledger (no ledger time, or the account login could not be read)")
    else
      [[ -z "$pr" ]] || observe_item "$pr"
      [[ -z "$issue" ]] || observe_item "$issue"
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
  expect_eq 'the failed note names the attempt as its target' "$(field 4 "$l")" 'gh pr update-branch 7'
  if [[ "$(field 5 "$l")" == *'(exit 1)'*'merge conflict'* ]]; then ok 'the failed note carries the exit status and the detail'; else bad 'the failed note lacks the exit status'; fi
  if [[ "$(field 5 "$l")" == *'the remote state was not verified' && "$l" != *'no action performed'* ]]; then ok 'the failed note never claims that nothing happened'; else bad "the failed note claims or omits: $l"; fi

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
  local before out option
  before="$(line_count)"
  out="$(PATH="$tmp/bin:$PATH" "$script" run -- gh pr create --title x)"
  expect_eq 'run passes the command stdout through' "$out" 'https://example.test/o/r/pull/9'
  l="$(last_line)"
  expect_eq 'run records one line per write' "$(line_count)" "$((before + 1))"
  expect_eq 'run records a successful write as external-write' "$(field 3 "$l")" external-write
  if [[ "$(field 4 "$l")" == 'gh pr create -> https://example.test/o/r/pull/9' ]]; then ok 'run names the created pull request URL'; else bad "run target: $(field 4 "$l")"; fi

  PATH="$tmp/bin:$PATH" "$script" run -- gh pr edit 9 --add-label 'ready to merge' --remove-label=stale >/dev/null
  l="$(last_line)"
  expect_eq 'run names the label changes of an edit' "$(field 4 "$l")" 'gh pr edit 9 +label ready to merge -label stale -> https://example.test/o/r/pull/9'

  rc=0
  PATH="$tmp/bin:$PATH" FAKE_GH_EXIT=5 "$script" run -- gh pr ready 9 >/dev/null 2>&1 || rc=$?
  expect_eq 'run exits with the write command status, not a pipe status' "$rc" 5
  l="$(last_line)"
  expect_eq 'run records a failed write as a note line' "$(field 3 "$l")" note
  expect_eq 'the failed write names its own target' "$(field 4 "$l")" 'gh pr ready 9 -> https://example.test/o/r/pull/9'
  if [[ "$(field 5 "$l")" == 'write failed (exit 5);'* ]]; then ok 'the failed write names its own exit status'; else bad "failed note: $(field 5 "$l")"; fi

  before="$(line_count)"
  rc=0
  "$script" run -- curl https://example.test >/dev/null 2>&1 || rc=$?
  expect_eq 'run refuses a command that is not an allowed write' "$rc" 64
  expect_eq 'a refused command writes no line' "$(line_count)" "$before"
  local refused
  for refused in 'gh pr comment 5 --body x' 'gh pr review 5 --approve' 'gh issue comment 5 --body x'; do
    rc=0
    # shellcheck disable=SC2086
    PATH="$tmp/bin:$PATH" "$script" run -- $refused >/dev/null 2>&1 || rc=$?
    expect_eq "run refuses '$refused' with exit 64: a comment goes through upsert-comment.sh" "$rc" 64
    expect_eq "a refused '$refused' writes no line" "$(line_count)" "$before"
  done

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

  # --- run: a push that names a commit other than HEAD, and a push with no commit ----------------
  (
    cd "$work"
    git commit -q --allow-empty -m two
    "$script" run -- git push -q origin HEAD~1:refs/heads/older
  )
  l="$(last_line)"
  local older
  older="$(git -C "$work" rev-parse HEAD~1)"
  if [[ "$(field 5 "$l")" == *"head ${older:0:12}"* ]]; then ok 'a push line names the commit of the refspec source, not HEAD'; else bad "refspec push line: $l"; fi
  (
    cd "$work"
    "$script" run -- git push -q origin :refs/heads/older
  )
  l="$(last_line)"
  if [[ "$(field 5 "$l")" != *head* ]]; then ok 'a branch deletion names no head commit'; else bad "deletion push line: $l"; fi
  (
    cd "$work"
    git reset -q --hard HEAD~1
  )

  # --- run: a push that fails after it updated one ref never claims that nothing happened ---------
  local work2="$tmp/work2"
  git init -q "$work2"
  (
    cd "$work2"
    git config user.email t@example.test
    git config user.name t
    git checkout -q -b base
    git commit -q --allow-empty -m base
    git remote add origin "$remote"
    git push -q origin base:refs/heads/rej
    git checkout -q --orphan other
    git commit -q --allow-empty -m other
    git checkout -q -b okpart base
    git commit -q --allow-empty -m okpart
    rc=0
    "$script" run -- git push -q origin other:refs/heads/rej okpart:refs/heads/okpart >/dev/null 2>&1 || rc=$?
    exit "$rc"
  ) && rc=0 || rc=$?
  expect_eq 'a partial push keeps the exit status of git' "$rc" 1
  l="$(last_line)"
  expect_eq 'a partial push leaves a note line' "$(field 3 "$l")" note
  expect_eq 'a partial push names the push as its target' "$(field 4 "$l")" 'git push origin other:refs/heads/rej'
  if [[ "$l" != *'no action performed'* && "$(field 5 "$l")" == 'write failed (exit 1); the remote state was not verified' ]]; then
    ok 'a partial push does not claim that no action was performed'
  else
    bad "partial push line: $l"
  fi
  if [[ -n "$(git -C "$work2" ls-remote origin refs/heads/okpart)" ]]; then ok 'the partial push really updated a ref'; else bad 'the partial push updated no ref'; fi

  # --- run: an option that makes git run a program is refused ------------------------------------
  local pwn="$tmp/pwned"
  for option in "--receive-pack=touch $pwn" "--exec=touch $pwn" "--upload-pack=touch $pwn" "--receive=touch $pwn" '--rec'; do
    before="$(line_count)"
    rc=0
    (cd "$work" && "$script" run -- git push "$option" origin feature >/dev/null 2>&1) || rc=$?
    expect_eq "run refuses git push $option with exit 64" "$rc" 64
    expect_eq "a refused git push $option writes no line" "$(line_count)" "$before"
  done
  rc=0
  (cd "$work" && "$script" run -- git push "ext::touch $pwn" feature >/dev/null 2>&1) || rc=$?
  expect_eq 'run refuses an ext:: remote with exit 64' "$rc" 64
  if [[ ! -e "$pwn" ]]; then ok 'no refused push ran its program'; else bad 'a refused push ran its program'; fi

  # --- an environment value never reaches an output ----------------------------------------------
  local marker="${tmp}/MARKER-must-not-print"
  rc=0
  AUDIT_TRAIL_FILE="$marker" "$script" record --target t --status 0 >"$tmp/leak.out" 2>"$tmp/leak.err" || rc=$?
  expect_eq 'a bad AUDIT_TRAIL_FILE makes record exit 1' "$rc" 1
  AUDIT_TRAIL_FILE="$marker" "$script" verify >>"$tmp/leak.out" 2>>"$tmp/leak.err" || true
  AUDIT_TRAIL_FILE="${marker}.audit" "$script" append --class note --target t --result r >>"$tmp/leak.out" 2>>"$tmp/leak.err" || true
  AUDIT_TRAIL_FILE="${marker}.audit" "$script" verify >>"$tmp/leak.out" 2>>"$tmp/leak.err" || true
  if ! grep -q 'MARKER-must-not-print' "$tmp/leak.out" "$tmp/leak.err"; then ok 'no message prints the value of AUDIT_TRAIL_FILE'; else bad 'a message printed the value of AUDIT_TRAIL_FILE'; fi
  if grep -q 'must be an \*.audit file' "$tmp/leak.err"; then ok 'the bad-file message is fixed text'; else bad 'the bad-file message is missing'; fi

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

  # --- verify: the agent comments and the label changes of the account --------------------------
  if command -v jq >/dev/null 2>&1; then
    local obs="$tmp/obs.audit"
    cat >"$obs" <<'OBS'
donatello|2026-11-11T10:00:00Z|external-write|gh pr create -> https://example.test/o/r/pull/5|ok (exit 0)
donatello|2026-11-11T10:30:00Z|external-write|https://example.test/o/r/issues/5#issuecomment-101|created (exit 0)
donatello|2026-11-11T10:40:00Z|external-write|gh pr edit 5 +label ready to merge +label other-tag|ok (exit 0)
OBS
    mkdir -p "$tmp/obsbin"
    cat >"$tmp/obsbin/gh" <<'OBSGH'
#!/usr/bin/env bash
if [[ "${FAKE_GH_FAIL:-0}" == 1 && "$*" == *"issues/5/"* ]]; then exit 1; fi
expr=""
prev=""
for arg in "$@"; do
  [[ "$prev" == --jq ]] && expr="$arg"
  prev="$arg"
done
case "$*" in
"api user --jq .login") echo bot ;;
*"issues/5/comments"*)
  jq -r "$expr" <<'JSON'
[
  {"user":{"login":"bot"},"created_at":"2026-11-11T11:00:00Z","id":101,"body":"report\n<!-- cr-comment:actor=bot -->"},
  {"user":{"login":"bot"},"created_at":"2026-11-11T11:00:00Z","id":102,"body":"report\n<!-- cr-comment:actor=bot -->"},
  {"user":{"login":"other"},"created_at":"2026-11-11T11:00:00Z","id":103,"body":"report\n<!-- cr-comment:actor=other -->"},
  {"user":{"login":"bot"},"created_at":"2000-01-01T00:00:00Z","id":104,"body":"report\n<!-- cr-comment:actor=bot -->"},
  {"user":{"login":"bot"},"created_at":"2026-11-11T11:00:00Z","id":555,"body":"the operator wrote this by hand"},
  {"user":{"login":"bot"},"created_at":"2026-11-11T11:00:00Z","id":556,"body":null},
  {"user":{"login":"bot"},"created_at":"2026-11-11T11:00:00Z","id":557,"body":"agent report\n<!-- analysis-plan:actor=bot -->"}
]
JSON
  ;;
*"issues/5/timeline"*)
  jq -r "$expr" <<'JSON'
[
  {"actor":{"login":"bot"},"created_at":"2026-11-11T11:00:00Z","event":"labeled","label":{"name":"ready to merge"}},
  {"actor":{"login":"bot"},"created_at":"2026-11-11T11:00:00Z","event":"unlabeled","label":{"name":"ready to merge"}},
  {"actor":{"login":"bot"},"created_at":"2026-11-11T11:00:00Z","event":"labeled","label":{"name":"tag"}},
  {"actor":{"login":"bot"},"created_at":"2026-11-11T11:00:00Z","event":"labeled","label":{"name":"other-tag"}},
  {"actor":{"login":"bot"},"created_at":"2026-11-11T11:00:00Z","event":"labeled","label":{"name":"surprise"}},
  {"actor":{"login":"bot"},"created_at":"2026-11-11T11:00:00Z","event":"labeled","label":{"name":"debug"}},
  {"actor":{"login":"other"},"created_at":"2026-11-11T11:00:00Z","event":"labeled","label":{"name":"other-label"}},
  {"actor":{"login":"bot"},"created_at":"2000-01-01T00:00:00Z","event":"labeled","label":{"name":"old-label"}}
]
JSON
  ;;
*"pr view"*) echo https://example.test/o/r/pull/5 ;;
esac
OBSGH
    chmod +x "$tmp/obsbin/gh"
    rc=0
    PATH="$tmp/obsbin:$PATH" "$script" verify --file "$obs" --pr 5 >"$tmp/obs.out" || rc=$?
    expect_eq 'verify reports an agent comment with no ledger line' "$rc" 3
    if grep -q '^problem: comment 102 ' "$tmp/obs.out" && grep -q '^problem: comment 557 ' "$tmp/obs.out"; then ok 'verify names an agent comment of the account that has no line'; else bad "verify missed an unrecorded agent comment: $(cat "$tmp/obs.out")"; fi
    if ! grep -q 'comment 555\|comment 556' "$tmp/obs.out"; then ok 'verify skips a comment of the account that carries no agent marker'; else bad "verify named an operator comment: $(cat "$tmp/obs.out")"; fi
    if ! grep -q 'comment 101 \|comment 103 \|comment 104 \|other-label\|old-label' "$tmp/obs.out"; then
      ok 'verify skips a recorded comment, another actor, and a write before the run'
    else
      bad "verify named a write it should skip: $(cat "$tmp/obs.out")"
    fi
    if grep -q "^not verified: label 'ready to merge' unlabeled " "$tmp/obs.out"; then ok 'an unrecorded removal is not verified although the label was added by a line'; else bad "verify matched unlabeled to a +label line: $(cat "$tmp/obs.out")"; fi
    if grep -q "^not verified: label 'tag' labeled " "$tmp/obs.out"; then ok 'a label that is only a substring of a recorded label is not verified'; else bad "verify matched a label by substring: $(cat "$tmp/obs.out")"; fi
    if grep -q "^not verified: label 'surprise' labeled " "$tmp/obs.out"; then ok 'verify names a label change that has no line'; else bad "verify missed the unrecorded label change: $(cat "$tmp/obs.out")"; fi
    if ! grep -q "label 'ready to merge' labeled\|label 'other-tag' labeled\|^problem: label" "$tmp/obs.out"; then ok 'a recorded label is skipped and a label event is never a problem'; else bad "verify named a recorded label: $(cat "$tmp/obs.out")"; fi
    printf 'branch-name|2026-11-11T10:50:00Z|external-write|git push origin fix/debug-output|ok (exit 0)\n' >>"$obs"
    PATH="$tmp/obsbin:$PATH" "$script" verify --file "$obs" --pr 5 >"$tmp/obs.out" || true
    if grep -q "^not verified: label 'debug' labeled " "$tmp/obs.out"; then ok 'a label that is a substring of another write line is not verified'; else bad 'verify matched a label inside a push line'; fi

    cat >>"$obs" <<'OBS'
donatello|2026-11-11T12:00:00Z|note|comment 102|written by another agent, not by this run
donatello|2026-11-11T12:00:01Z|note|comment 557|written by another agent, not by this run
donatello|2026-11-11T12:00:02Z|note|label surprise|set by the operator, not by an agent
donatello|2026-11-11T12:00:03Z|note|label tag|set by the operator, not by an agent
donatello|2026-11-11T12:00:04Z|note|label ready to merge|removed by the operator, not by an agent
OBS
    rc=0
    PATH="$tmp/obsbin:$PATH" "$script" verify --file "$obs" --pr 5 >"$tmp/obs.out" || rc=$?
    expect_eq 'a later note that names the comment resolves the problem' "$rc" 0
    if ! grep -q "comment 102\|comment 557\|label 'surprise'\|label 'tag'\|label 'ready to merge'" "$tmp/obs.out"; then ok 'a later note that names the label resolves it'; else bad "a note did not resolve: $(cat "$tmp/obs.out")"; fi

    rc=0
    PATH="$tmp/obsbin:$PATH" FAKE_GH_FAIL=1 "$script" verify --file "$obs" --pr 5 >"$tmp/obs.out" || rc=$?
    expect_eq 'a failed read is no problem' "$rc" 0
    if grep -q '^not verified: the comments or the timeline' "$tmp/obs.out"; then ok 'a failed read prints not verified'; else bad "a failed read printed: $(cat "$tmp/obs.out")"; fi
  else
    ok 'skipped the comment and label checks of verify: jq is not installed'
  fi

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
