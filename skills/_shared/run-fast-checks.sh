#!/usr/bin/env bash
# run-fast-checks.sh — run the project's own fast checks on the files the branch changes, before a push.
#
# Why this exists
#   An implementer pushed a change and only then learned from the full CI run that a cheap check
#   failed: a static-analysis error, a coding-standard violation, a line that is too long, or a pin
#   test. Each such failure cost one more push and one more full CI wait. The project declares its
#   fast checks once, as the manifest key `fast-checks` (rules/general/general.md *Project manifest*),
#   and this script runs them on the changed files before every push
#   (skills/resolve-issue/references/quality-gates.md *Gate placement — deferred to the merge boundary*).
#   They never replace CI or the pre-merge quality gate.
#
# What it runs
#   `fast-checks` from the default-branch manifest, read through read-manifest.sh. A branch under
#   review proposes a manifest and never decides what runs here. Each entry is one of:
#     "<command>"                                  runs as written, once
#     {"command": "<command>", "files": "<ERE>"}   runs with every changed file whose path matches the
#                                                  ERE appended as one argument each; skipped when no
#                                                  changed file matches
#   Every command passes the allow-list of project-commands.sh (no shell, no metacharacter, an
#   allow-listed project-local executable) before any of them runs. One refused entry refuses the run.
#   Every entry runs, also after a failure, so one push round reports every failing check.
#
# The changed files
#   `git diff -z --relative --name-only --no-renames --diff-filter=ACMRT <merge base> HEAD`, run in the
#   project directory (the directory whose composer.json read-manifest.sh reads): the files the push
#   carries inside that directory, as paths relative to it, deleted files excluded and a renamed or
#   copied file listed under its new path. A `files` ERE is matched against that relative form. The
#   base ref is the default branch, or `--base <ref>` for a branch that starts from another branch. A file path is never part of a command string: it is
#   read NUL-separated and passed as its own argv element, so no character in a path is ever a shell
#   operator. A path that starts with `-` is passed as `./<path>`, so a tool never reads it as an option.
#   A path that is a symbolic link, that runs through a linked directory, or that has a `..` component
#   is never passed and is not counted: a branch could point it at a file outside the worktree, a
#   `.env*` file included, and the checker would read that file into the log. The check reuses
#   `gate_no_symlink` of gate-record.sh. A path is checked again just before it is passed; a path that
#   an earlier check turned into a link refuses the run. Every command runs through `run_command` of
#   project-commands.sh, the one executor of a project command.
#
# No silent pass
#   A run that cannot read what it needs never reports success: an unreadable default-branch
#   composer.json, a failed `git status`, and a failed `git diff` each refuse the run with exit 3.
#
# The machine lock
#   The checks are heavy commands, so they run under the machine-wide lock of run-gate.sh
#   (gate-record.sh), exactly as run-validation.sh runs a validation manifest. Nothing is locked when
#   no entry runs.
#
# The default branch
#   The manifest is read from the default branch only. Without a default-branch ref
#   (`refs/remotes/origin/HEAD`) the manifest would read as empty and declared checks would be skipped
#   silently, so the run is refused with exit 3 and the `git remote set-head origin --auto` hint.
#
# Usage
#   run-fast-checks.sh [--base <ref>]
#   run-fast-checks.sh --self-test
#
# Output (stdout): one line of JSON.
#   status   passed | failed | skipped | not-configured | refused | locked | unclean
#   base     the merge base the changed files were computed against (empty on an early stop)
#   head     the HEAD commit
#   changed  the number of changed files
#   checks   [{command, files, status, exit_code}] in manifest order; `files` is the number of
#            changed files the entry received, `status` is passed, failed, or skipped
#   log      the log of every check that ran, only on a failed run; a passed run deletes its log and
#            prints "". The caller deletes the log of a failed run once it read it.
#   reason   why the run stopped or failed
#
# Exit codes
#   0  every entry that ran passed, or no entry had a changed file to check (status `skipped`)
#   1  usage error, or a `--base` ref that is not a commit
#   2  missing required tool (git or jq)
#   3  refused: an entry or the manifest is not acceptable, no default-branch ref resolves, or the
#      merge base cannot be found
#   4  at least one check failed — read the log, fix, commit, and run again
#   5  the default-branch manifest sets no `fast-checks`: nothing ran
#   6  a live run held the machine lock past AI_OLYMPUS_GATE_LOCK_TIMEOUT: nothing ran
#   7  the working tree has uncommitted changes to tracked files: commit them first, so the checked
#      bytes are the pushed bytes
set -euo pipefail

PROG="${0##*/}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# The command checks, the executor, and the manifest reader live in project-commands.sh; the machine
# lock lives in gate-record.sh, which sources project-commands.sh itself.
# shellcheck source=gate-record.sh
. "$SCRIPT_DIR/gate-record.sh"

usage() {
  cat >&2 <<'EOF'
Usage: run-fast-checks.sh [--base <ref>]
       run-fast-checks.sh --self-test

Runs the default-branch manifest `fast-checks` on the files the branch changes, under the machine
lock, and prints a JSON result. Exit 0 passed or nothing to check, 3 refused, 4 failed,
5 not configured, 6 locked, 7 uncommitted changes.
EOF
}

BASE=''
HEAD_SHA=''
CHANGED_COUNT=0
LOG=''
CHECKS='[]'

emit() {
  local status="$1" reason="$2"
  jq -nc --arg status "$status" --arg base "$BASE" --arg head "$HEAD_SHA" --argjson changed "$CHANGED_COUNT" \
    --argjson checks "$CHECKS" --arg log "$LOG" --arg reason "$reason" \
    '{status: $status, base: $base, head: $head, changed: $changed, checks: $checks, log: $log, reason: $reason}'
}

stop() {
  local status="$1" code="$2" reason="$3"
  if [[ -n "$LOG" ]]; then
    rm -f -- "$LOG"
    LOG=''
  fi
  emit "$status" "$(safe_display "$reason")"
  exit "$code"
}

add_check() {
  CHECKS="$(jq -c --arg command "$1" --argjson files "$2" --arg status "$3" --argjson code "$4" \
    '. + [{command: $command, files: $files, status: $status, exit_code: $code}]' <<<"$CHECKS")"
}

# A changed path that stays inside the worktree: a regular file, no `..` component, and no component
# of it is a symbolic link (gate_no_symlink of gate-record.sh). The executor checks it again just
# before it passes the path, because an earlier check of the same run executes branch code that could
# have replaced the file with a link.
inside_worktree() {
  [[ -f "$1" && "/$1/" != */../* ]] || return 1
  gate_no_symlink "$1" >/dev/null 2>&1
}

# Matches the empty text against an ERE. The status is the point: 2 means the pattern is not a valid
# extended regex, which `[[ =~ ]]` reports instead of a match or a mismatch.
match_empty() {
  [[ '' =~ $1 ]]
}

require_tools() {
  local tool
  for tool in git jq; do
    command -v "$tool" >/dev/null 2>&1 || { echo "$PROG: $tool is required" >&2; exit 2; }
  done
}

main() {
  local base_ref='' default_ref

  while [[ $# -gt 0 ]]; do
    case "$1" in
    --self-test)
      require_tools
      self_test
      return $?
      ;;
    --base)
      [[ $# -ge 2 ]] || { usage; return 1; }
      base_ref="$2"
      shift 2
      ;;
    -h | --help)
      usage
      return 0
      ;;
    *)
      usage
      return 1
      ;;
    esac
  done

  require_tools
  HEAD_SHA="$(git rev-parse --verify --quiet HEAD 2>/dev/null)" || stop refused 3 'no HEAD commit in the current directory'

  # Without a default-branch ref the manifest reads as empty, so the declared checks would be skipped.
  default_ref="$(bash "$SCRIPT_DIR/read-manifest.sh" --default-ref 2>/dev/null)" \
    || stop refused 3 'no default-branch ref; run: git remote set-head origin --auto'

  bash "$SCRIPT_DIR/read-manifest.sh" >/dev/null 2>&1 \
    || stop refused 3 'the default-branch composer.json cannot be read as a manifest; the declared fast checks are unknown'
  load_project_manifest || stop refused 3 "$PROJECT_REFUSAL"
  printf '%s' "$PROJECT_MANIFEST" | jq -e 'has("fast-checks")' >/dev/null 2>&1 \
    || stop not-configured 5 'the default-branch manifest sets no fast-checks; no fast check runs before a push'

  # --- Validate every entry before any of them runs ------------------------------------------------
  local entries
  entries="$(printf '%s' "$PROJECT_MANIFEST" | jq -c '.["fast-checks"]')"
  printf '%s' "$entries" | jq -e '
      type == "array" and all(.[];
        type == "string"
        or (type == "object" and (keys - ["command", "files"]) == [] and (.command | type) == "string"
            and (.files | type) == "string"))' >/dev/null 2>&1 \
    || stop refused 3 'project manifest: fast-checks must be an array of command strings and {"command", "files"} objects'

  local count index command pattern reason match_status
  local -a commands=() patterns=()
  count="$(printf '%s' "$entries" | jq 'length')"
  for ((index = 0; index < count; index++)); do
    command="$(printf '%s' "$entries" | jq -r --argjson i "$index" '.[$i] | if type == "string" then . else .command end')"
    pattern="$(printf '%s' "$entries" | jq -r --argjson i "$index" '.[$i] | if type == "string" then "" else .files end')"
    if ! reason="$(validate_command "$command" 2>&1 >/dev/null)"; then
      stop refused 3 "fast-checks: $reason: $command"
    fi
    if printf '%s' "$entries" | jq -e --argjson i "$index" '.[$i] | type == "object"' >/dev/null; then
      [[ -n "$pattern" ]] || stop refused 3 "fast-checks: an empty files pattern: $command"
      match_status=0
      match_empty "$pattern" || match_status=$?
      [[ "$match_status" -ne 2 ]] || stop refused 3 "fast-checks: the files pattern is not a valid extended regex: $command"
    fi
    commands+=("$command")
    patterns+=("$pattern")
  done

  local tree_status
  tree_status="$(git status --porcelain --untracked-files=no 2>/dev/null)" \
    || stop refused 3 'git status failed; the working tree cannot be read'
  [[ -z "$tree_status" ]] || stop unclean 7 'the working tree has uncommitted changes; commit them, then run the fast checks'

  # --- The changed files ---------------------------------------------------------------------------
  if [[ -z "$base_ref" ]]; then
    base_ref="$default_ref"
  else
    valid_ref "$base_ref" || { echo "$PROG: not a valid commit ref: $(safe_display "$base_ref")" >&2; return 1; }
  fi
  BASE="$(git merge-base "$base_ref" HEAD 2>/dev/null)" || stop refused 3 "no merge base of HEAD and $base_ref"

  local path list tmp_dir="${TMPDIR:-/tmp}"
  local -a changed=()
  list="$(mktemp "${tmp_dir%/}/run-fast-checks-files.XXXXXX")" || stop refused 3 'cannot create the file list'
  if ! git diff -z --relative --name-only --no-renames --diff-filter=ACMRT "$BASE" HEAD >"$list" 2>/dev/null; then
    rm -f -- "$list"
    stop refused 3 "git diff failed; the changed files of HEAD against $base_ref cannot be listed"
  fi
  while IFS= read -r -d '' path; do
    inside_worktree "$path" || continue
    changed+=("$path")
  done <"$list"
  rm -f -- "$list"
  CHANGED_COUNT="${#changed[@]}"

  # --- The plan: which entry runs, with which files ------------------------------------------------
  local -a runs=()
  local planned=0
  for ((index = 0; index < count; index++)); do
    if [[ -z "${patterns[index]}" ]]; then
      if [[ "$CHANGED_COUNT" -gt 0 ]]; then runs+=(run); else runs+=(skip); fi
    else
      runs+=(skip)
      for path in ${changed[@]+"${changed[@]}"}; do
        if [[ "$path" =~ ${patterns[index]} ]]; then
          runs[index]=run
          break
        fi
      done
    fi
    [[ "${runs[index]}" != run ]] || planned=$((planned + 1))
  done

  if [[ "$planned" -eq 0 ]]; then
    for ((index = 0; index < count; index++)); do
      add_check "${commands[index]}" 0 skipped 0
    done
    emit skipped 'no fast check has a changed file to check'
    return 0
  fi

  # --- The machine lock ----------------------------------------------------------------------------
  local lock_status=0
  gate_lock_timeout || stop refused 3 "$GATE_REASON"
  GATE_HOLDER_COMMAND="fast checks of ${HEAD_SHA:0:12}: $planned command(s)"
  gate_lock_enter "$GATE_LOCK_TIMEOUT" || lock_status=$?
  case "$lock_status" in
  0) ;;
  6) stop locked 6 "$GATE_REASON" ;;
  *) stop refused 3 "$GATE_REASON" ;;
  esac

  LOG="$(mktemp "${tmp_dir%/}/run-fast-checks.XXXXXX")" || stop refused 3 'cannot create the log file'

  # --- Execute -------------------------------------------------------------------------------------
  local status failed=0
  local -a files=()
  for ((index = 0; index < count; index++)); do
    if [[ "${runs[index]}" != run ]]; then
      add_check "${commands[index]}" 0 skipped 0
      continue
    fi
    files=()
    if [[ -n "${patterns[index]}" ]]; then
      for path in "${changed[@]}"; do
        [[ "$path" =~ ${patterns[index]} ]] || continue
        inside_worktree "$path" || stop refused 3 'a changed path became a link or disappeared during the run; an earlier check changed the tree'
        [[ "$path" != -* ]] || path="./$path"
        files+=("$path")
      done
    fi

    printf '== %s (%d file(s))\n' "${commands[index]}" "${#files[@]}" >>"$LOG"
    status=0
    run_command "${commands[index]}" "$LOG" ${files[@]+"${files[@]}"} || status=$?
    if [[ "$status" -eq 0 ]]; then
      add_check "${commands[index]}" "${#files[@]}" passed 0
    else
      failed=1
      add_check "${commands[index]}" "${#files[@]}" failed "$status"
    fi
  done

  if [[ "$failed" -eq 1 ]]; then
    emit failed 'a fast check failed; read the log, fix what it reports, commit, delete the log, and run the fast checks again'
    return 4
  fi
  rm -f -- "$LOG"
  LOG=''
  emit passed ''
  return 0
}

SELF_TEST_TMP=""
# Reached only through `trap cleanup_self_test EXIT`; ShellCheck does not credit a trap as an
# invocation (SC2317, and SC2329 in 0.11), so both are suppressed.
# shellcheck disable=SC2317,SC2329
cleanup_self_test() {
  [[ -n "$SELF_TEST_TMP" ]] && rm -rf "$SELF_TEST_TMP"
  return 0
}

self_test() {
  local failures=0 script tmp repo out code
  script="$SCRIPT_DIR/$PROG"
  SELF_TEST_TMP="$(mktemp -d)"
  trap cleanup_self_test EXIT
  tmp="$SELF_TEST_TMP"
  repo="$tmp/repo"
  # The checks take the machine lock. The self-test gets its own, so it never waits for a real gate,
  # and a self-test that a gate started never waits for the gate's lock.
  export AI_OLYMPUS_LOCK_DIR="$tmp/machine"
  export AI_OLYMPUS_GATE_LOCK_TIMEOUT=2
  unset AI_OLYMPUS_LOCK_HELD_BY
  export FAST_CHECKS_SELFTEST_CALLS="$tmp/calls"
  # The log of every run below lands in the self-test directory, so the cleanup removes it.
  export TMPDIR="$tmp"

  check() {
    local label="$1" verdict="$2"
    if [[ "$verdict" == pass ]]; then
      printf 'ok    %s\n' "$label"
    else
      printf 'FAIL  %s\n' "$label" >&2
      printf '      exit %s, output: %s\n' "$code" "$out" >&2
      failures=$((failures + 1))
    fi
  }

  run() {
    rm -f "$FAST_CHECKS_SELFTEST_CALLS"
    set +e
    out="$(cd "${RUN_DIR:-$repo}" && "$script" "$@" 2>/dev/null)"
    code=$?
    set -e
  }

  # The argv each stub received, one argument per line, so a test compares the exact arguments.
  calls() {
    if [[ -f "$FAST_CHECKS_SELFTEST_CALLS" ]]; then cat "$FAST_CHECKS_SELFTEST_CALLS"; fi
  }

  json() {
    printf '%s' "$out" | jq -c "$1" 2>/dev/null
  }

  # A lock held by this live shell, so a run that waits for it exits 6.
  live_lock() {
    mkdir -p "$tmp/machine/ai-olympus-gate.lock"
    printf 'PID=%s\nSTARTED=%s\nSTARTED_EPOCH=%s\n' "$$" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$(date +%s)" >"$tmp/machine/ai-olympus-gate.lock/holder"
  }

  # Replaces the default-branch manifest; the feature branch keeps its own composer.json.
  manifest() {
    gate_selftest_manifest "$repo" "{ \"extra\": { \"ai-olympus\": { \"validation\": { \"executables\": [\"vendor/bin/argv-ok\", \"vendor/bin/argv-fail\", \"vendor/bin/pwn\"] }$1 } } }"
  }

  gate_selftest_project "$repo" '{ "extra": { "ai-olympus": {} } }'
  cat >"$repo/vendor/bin/argv-ok" <<'STUB'
#!/usr/bin/env bash
{ echo "argv-ok $#"; [[ $# -eq 0 ]] || printf '%s\n' "$@"; } >>"$FAST_CHECKS_SELFTEST_CALLS"
STUB
  cat >"$repo/vendor/bin/argv-fail" <<'STUB'
#!/usr/bin/env bash
{ echo "argv-fail $#"; [[ $# -eq 0 ]] || printf '%s\n' "$@"; } >>"$FAST_CHECKS_SELFTEST_CALLS"
exit 2
STUB
  chmod +x "$repo/vendor/bin/argv-ok" "$repo/vendor/bin/argv-fail"
  printf 'vendor/\n' >>"$repo/.gitignore"
  printf 'gone\n' >"$repo/removed.php"
  git -C "$repo" add -A
  git -C "$repo" -c user.name=t -c user.email=t@t commit -q -m 'base files'
  git -C "$repo" update-ref refs/remotes/origin/master HEAD
  git -C "$repo" switch -q -c feature

  run
  check 'a manifest without fast-checks runs nothing and exits 5' "$([[ "$code" -eq 5 && "$(json .status)" == '"not-configured"' && -z "$(calls)" ]] && echo pass)"

  # The paths a hostile branch can create: a space, a shell operator, a command substitution, and a
  # leading dash. Each must arrive as one literal argument, and nothing in it may run.
  mkdir -p "$repo/src"
  printf 'a\n' >"$repo/src/a b.php"
  printf 'b\n' >"$repo/src/x;touch pwned-semicolon.php"
  # The `$(...)` is literal text in a file name, which is what the check needs.
  # shellcheck disable=SC2016
  printf 'c\n' >"$repo/src/\$(touch pwned-substitution).php"
  printf 'd\n' >"$repo/-n.php"
  printf 'e\n' >"$repo/notes.md"
  git -C "$repo" rm -q removed.php
  git -C "$repo" add -A
  git -C "$repo" -c user.name=t -c user.email=t@t commit -q -m 'feature files'

  manifest ', "fast-checks": [{ "command": "vendor/bin/argv-ok --level=max", "files": "\\.php$" }, "vendor/bin/argv-ok", { "command": "vendor/bin/argv-ok", "files": "\\.css$" }]'
  run
  local expected
  # The literal paths are the expected arguments of the stub; nothing here is meant to expand.
  # shellcheck disable=SC2016
  expected="$(printf '%s\n' 'argv-ok 5' '--level=max' './-n.php' 'src/$(touch pwned-substitution).php' 'src/a b.php' 'src/x;touch pwned-semicolon.php' 'argv-ok 0')"
  check 'a files entry receives each matching changed file as one literal argument, after its own arguments' "$([[ "$code" -eq 0 && "$(calls)" == "$expected" ]] && echo pass)"
  check 'no character in a changed path runs as a shell operator' "$([[ ! -e "$repo/pwned-semicolon.php" && ! -e "$repo/pwned-substitution" && ! -e "$repo/src/pwned-semicolon.php" ]] && echo pass)"
  check 'a path that starts with a dash is passed as ./<path>' "$(calls | grep -qx -- './-n.php' && ! calls | grep -qx -- '-n.php' && echo pass)"
  check 'a deleted file is never passed' "$(calls | grep -q 'removed.php' || echo pass)"
  check 'a command string runs once as written, without the changed files' "$([[ "$(calls | tail -n 1)" == 'argv-ok 0' ]] && echo pass)"
  check 'an entry whose pattern matches no changed file is skipped' "$([[ "$(json '[.status, [.checks[] | [.status, .files]]]')" == '["passed",[["passed",4],["passed",0],["skipped",0]]]' && "$(json .changed)" == 5 ]] && echo pass)"
  check 'a passed run deletes its log and its file list and reports none' "$([[ "$(json .log)" == '""' && -z "$(find "$tmp" -maxdepth 1 -name 'run-fast-checks*')" ]] && echo pass)"
  check 'the machine lock is released after the run' "$([[ ! -e "$tmp/machine/ai-olympus-gate.lock" ]] && echo pass)"

  manifest ', "fast-checks": [{ "command": "vendor/bin/argv-fail", "files": "\\.md$" }, { "command": "vendor/bin/argv-ok", "files": "\\.md$" }]'
  run
  check 'a failing check exits 4, and every later check still runs' "$([[ "$code" -eq 4 && "$(json '[.status, [.checks[] | [.status, .exit_code]]]')" == '["failed",[["failed",2],["passed",0]]]' && "$(calls | grep -c '^argv-')" == 2 ]] && echo pass)"
  local failed_log
  failed_log="$(printf '%s' "$out" | jq -r .log)"
  check 'a failed run keeps the log, and the log names every check that ran' "$([[ -f "$failed_log" && "$(grep -c '^== ' "$failed_log")" == 2 ]] && echo pass)"
  rm -f "$failed_log"

  # A live holder keeps the lock during this run: a run that waited for it would exit 6.
  manifest ', "fast-checks": [{ "command": "vendor/bin/argv-ok", "files": "\\.css$" }]'
  live_lock
  run
  check 'no changed file to check exits 0 with status skipped and takes no lock' "$([[ "$code" -eq 0 && "$(json .status)" == '"skipped"' && -z "$(calls)" && -d "$tmp/machine/ai-olympus-gate.lock" ]] && echo pass)"
  rm -rf "$tmp/machine/ai-olympus-gate.lock"

  # Without origin/HEAD the manifest would read as empty: the declared checks must not be skipped.
  manifest ', "fast-checks": [{ "command": "vendor/bin/argv-ok", "files": "\\.php$" }]'
  git -C "$repo" symbolic-ref --delete refs/remotes/origin/HEAD
  run
  check 'no default-branch ref refuses the run with the set-head hint instead of reading no checks' "$([[ "$code" -eq 3 && "$(json .status)" == '"refused"' && "$(json .reason)" == *'git remote set-head origin --auto'* && -z "$(calls)" ]] && echo pass)"
  git -C "$repo" symbolic-ref refs/remotes/origin/HEAD refs/remotes/origin/master

  # Each refusal is checked before any entry runs, so the first, valid entry must not run either.
  local refusal
  # The payloads must reach the manifest as literal text, so single quotes are the point.
  # shellcheck disable=SC2016
  for refusal in \
    '"vendor/bin/argv-ok; touch pwned"' \
    '"vendor/bin/argv-ok $(touch pwned)"' \
    '"curl http://example.invalid"' \
    '{ "command": "vendor/bin/argv-ok", "files": "(" }' \
    '{ "command": "vendor/bin/argv-ok", "files": "" }' \
    '{ "command": "vendor/bin/argv-ok" }' \
    '{ "command": "vendor/bin/argv-ok", "files": "\\.php$", "shell": true }' \
    '42'; do
    manifest ", \"fast-checks\": [\"vendor/bin/argv-ok\", $refusal]"
    run
    check "an unacceptable entry refuses the whole run before anything runs: $refusal" "$([[ "$code" -eq 3 && "$(json .status)" == '"refused"' && -z "$(calls)" && ! -e "$repo/pwned" ]] && echo pass)"
  done
  manifest ', "fast-checks": "vendor/bin/argv-ok"'
  run
  check 'fast-checks that is not an array is refused' "$([[ "$code" -eq 3 && -z "$(calls)" ]] && echo pass)"

  # The branch proposes its own manifest; only the default branch decides what runs.
  manifest ''
  printf '{ "extra": { "ai-olympus": { "validation": { "executables": ["vendor/bin/pwn"] }, "fast-checks": ["vendor/bin/pwn"] } } }\n' >"$repo/composer.json"
  git -C "$repo" add composer.json
  git -C "$repo" -c user.name=t -c user.email=t@t commit -q -m 'propose fast checks'
  run
  check 'fast-checks set only on the branch under review never run' "$([[ "$code" -eq 5 && ! -e "$repo/pwned" ]] && echo pass)"

  manifest ', "fast-checks": ["vendor/bin/argv-ok"]'
  printf 'dirty\n' >>"$repo/notes.md"
  run
  check 'uncommitted changes to tracked files exit 7 and run nothing' "$([[ "$code" -eq 7 && "$(json .status)" == '"unclean"' && -z "$(calls)" ]] && echo pass)"
  git -C "$repo" checkout -q -- notes.md

  live_lock
  run
  check 'a live lock holder makes the run wait, then exit 6 without running a check' "$([[ "$code" -eq 6 && "$(json .status)" == '"locked"' && -z "$(calls)" ]] && echo pass)"
  rm -rf "$tmp/machine/ai-olympus-gate.lock"

  git -C "$repo" switch -q -c stacked
  printf 'f\n' >"$repo/stacked.php"
  git -C "$repo" add -A
  git -C "$repo" -c user.name=t -c user.email=t@t commit -q -m 'stacked file'
  manifest ', "fast-checks": [{ "command": "vendor/bin/argv-ok", "files": "\\.php$" }]'
  run --base feature
  check '--base computes the changed files against the named branch' "$([[ "$code" -eq 0 && "$(calls)" == "$(printf '%s\n' 'argv-ok 1' 'stacked.php')" ]] && echo pass)"
  run --base '-p'
  check 'a --base that looks like an option is refused' "$([[ "$code" -eq 1 && -z "$(calls)" ]] && echo pass)"
  run --base 'no-such-ref'
  check 'an unknown --base is refused' "$([[ "$code" -eq 1 ]] && echo pass)"
  run --unknown
  check 'an unknown option is a usage error' "$([[ "$code" -eq 1 ]] && echo pass)"
  run --log "$tmp/x.log"
  check 'the removed --log option is a usage error' "$([[ "$code" -eq 1 ]] && echo pass)"

  # --- Links: a branch must not point a checker at a file outside the worktree ---------------------
  # Each form below names a .php path, so the files pattern matches it; none may reach the argv.
  local outside="$tmp/outside"
  mkdir -p "$outside/dir"
  printf 'FAKE_OUTSIDE_SECRET\n' >"$outside/.env"
  printf 'FAKE_OUTSIDE_DIR_SECRET\n' >"$outside/dir/inner.php"
  export FAST_CHECKS_SELFTEST_OUTSIDE="$outside/.env"
  export FAST_CHECKS_SELFTEST_READS="$tmp/reads"
  # A checker that reads what it receives, as phpcs does: a file is read, a directory is walked.
  cat >"$repo/vendor/bin/argv-cat" <<'STUB'
#!/usr/bin/env bash
{ echo "argv-cat $#"; [[ $# -eq 0 ]] || printf '%s\n' "$@"; } >>"$FAST_CHECKS_SELFTEST_CALLS"
for file in "$@"; do
  if [[ -d "$file" ]]; then find "$file/" -type f -exec cat {} + >>"$FAST_CHECKS_SELFTEST_READS"; else cat -- "$file" >>"$FAST_CHECKS_SELFTEST_READS"; fi
done
STUB
  chmod +x "$repo/vendor/bin/argv-cat"
  cat >"$repo/vendor/bin/argv-swap" <<'STUB'
#!/usr/bin/env bash
echo "argv-swap $#" >>"$FAST_CHECKS_SELFTEST_CALLS"
rm -f real.php && ln -s "$FAST_CHECKS_SELFTEST_OUTSIDE" real.php
STUB
  chmod +x "$repo/vendor/bin/argv-swap"
  git -C "$repo" switch -q -c links
  ln -s "$outside/.env" "$repo/link-file.php"
  ln -s "$outside/dir" "$repo/link-dir.php"
  ln -s ab.php "$repo/inside-link.php"
  printf 'ab\n' >"$repo/ab.php"
  ln -s "$outside/.env" "$repo/[a]b.php"
  rm "$repo/stacked.php" && ln -s "$outside/.env" "$repo/stacked.php"
  printf 'real\n' >"$repo/real.php"
  git -C "$repo" add -A
  git -C "$repo" -c user.name=t -c user.email=t@t commit -q -m 'links'
  gate_selftest_manifest "$repo" '{ "extra": { "ai-olympus": { "validation": { "executables": ["vendor/bin/argv-cat", "vendor/bin/argv-ok", "vendor/bin/argv-swap"] }, "fast-checks": [{ "command": "vendor/bin/argv-cat", "files": "\\.php$" }] } } }'
  rm -f "$FAST_CHECKS_SELFTEST_READS"
  run --base stacked
  check 'a link to an outside file or directory, an inside link, a glob-shaped link name, and a file turned into a link never reach a checker' "$([[ "$code" -eq 0 && "$(calls)" == "$(printf '%s\n' 'argv-cat 2' 'ab.php' 'real.php')" && "$(json .changed)" == 2 ]] && echo pass)"
  check 'a checker that reads its files reads only files inside the worktree, never the outside secret' "$([[ "$(cat "$FAST_CHECKS_SELFTEST_READS" 2>/dev/null)" == "$(printf '%s\n' 'ab' 'real')" ]] && ! grep -qs FAKE_OUTSIDE "$FAST_CHECKS_SELFTEST_READS" && echo pass)"

  # An earlier check runs branch code: a path it turns into a link is checked again before it is passed.
  gate_selftest_manifest "$repo" '{ "extra": { "ai-olympus": { "validation": { "executables": ["vendor/bin/argv-ok", "vendor/bin/argv-swap"] }, "fast-checks": ["vendor/bin/argv-swap", { "command": "vendor/bin/argv-ok", "files": "^real\\.php$" }] } } }'
  run --base stacked
  check 'a path an earlier check replaced with a link is never passed, and the run is refused instead of passing' "$([[ "$code" -eq 3 && "$(json .status)" == '"refused"' && "$(calls)" == "$(printf '%s\n' 'argv-swap 0')" && "$(json .log)" == '""' && ! -e "$tmp/machine/ai-olympus-gate.lock" ]] && echo pass)"
  git -C "$repo" checkout -q -- real.php

  check 'a path with a .. component is outside the worktree' "$( (cd "$repo" && ! inside_worktree 'src/../ab.php' && inside_worktree 'ab.php') && echo pass)"

  # --- No silent pass: every input the run cannot read refuses it -----------------------------------
  gate_selftest_manifest "$repo" '{ "extra": { "ai-olympus": '
  run --base stacked
  check 'a default-branch composer.json that is not JSON refuses the run instead of reading no checks' "$([[ "$code" -eq 3 && "$(json .status)" == '"refused"' && -z "$(calls)" ]] && echo pass)"
  gate_selftest_manifest "$repo" '{ "extra": { "ai-olympus": { "validation": { "executables": ["vendor/bin/argv-ok"] }, "fast-checks": [{ "command": "vendor/bin/argv-ok", "files": "\\.php$" }] } } }'

  # A missing tree object of HEAD fails `git status`; a missing tree object of the merge base fails
  # only `git diff`. Each runs in a copy, so the fixture keeps every object.
  local broken object
  broken="$tmp/broken-status"
  cp -R "$repo" "$broken"
  object="$(git -C "$broken" rev-parse "HEAD^{tree}")"
  rm -f "$broken/.git/objects/${object:0:2}/${object:2}"
  RUN_DIR="$broken" run --base stacked
  check 'a git status that fails refuses the run instead of reading a clean tree' "$([[ "$code" -eq 3 && "$(json .reason)" == *'git status failed'* && -z "$(calls)" ]] && echo pass)"
  broken="$tmp/broken-diff"
  cp -R "$repo" "$broken"
  object="$(git -C "$broken" rev-parse "$(git -C "$broken" merge-base stacked HEAD)^{tree}")"
  rm -f "$broken/.git/objects/${object:0:2}/${object:2}"
  RUN_DIR="$broken" run --base stacked
  check 'a git diff that fails refuses the run instead of reading no changed file' "$([[ "$code" -eq 3 && "$(json .reason)" == *'git diff failed'* && -z "$(calls)" && -z "$(find "$tmp" -maxdepth 1 -name 'run-fast-checks*')" ]] && echo pass)"

  # --- A project in a subdirectory: paths are relative to the project directory ---------------------
  # Its manifest is app/composer.json, so the script runs in app/. A change outside app/ is not the
  # project's; a renamed file, and a name with a newline and a non-ASCII letter, are checked.
  local mono="$tmp/mono"
  mkdir -p "$mono/app/vendor/bin" "$mono/app/src"
  cp "$repo/vendor/bin/argv-ok" "$mono/app/vendor/bin/argv-ok"
  printf '%s\n' '{ "extra": { "ai-olympus": { "validation": { "executables": ["vendor/bin/argv-ok"] }, "fast-checks": [{ "command": "vendor/bin/argv-ok", "files": "^src/.*\\.php$" }] } } }' >"$mono/app/composer.json"
  printf 'old\n' >"$mono/app/src/old.php"
  git -C "$mono" init -q
  git -C "$mono" add -A
  git -C "$mono" -c user.name=t -c user.email=t@t commit -q -m init
  git -C "$mono" update-ref refs/remotes/origin/master HEAD
  git -C "$mono" symbolic-ref refs/remotes/origin/HEAD refs/remotes/origin/master
  git -C "$mono" switch -q -c feature
  git -C "$mono" mv app/src/old.php app/src/renamed.php
  printf 'a\n' >"$mono/app/src/a.php"
  printf 'n\n' >"$mono/app/src/new"$'\n'"line-ž.php"
  printf 'r\n' >"$mono/root.php"
  git -C "$mono" add -A
  git -C "$mono" -c user.name=t -c user.email=t@t commit -q -m 'subdirectory change'
  RUN_DIR="$mono/app" run
  check 'a project in a subdirectory checks its changed files relative to its directory, renamed and unusual names included' "$([[ "$code" -eq 0 && "$(json .status)" == '"passed"' && "$(calls)" == "$(printf '%s\n' 'argv-ok 3' 'src/a.php' "src/new"$'\n'"line-ž.php" 'src/renamed.php')" && "$(json .changed)" == 3 ]] && echo pass)"

  if [[ "$failures" -gt 0 ]]; then
    printf '%s self-test: %d failure(s)\n' "$PROG" "$failures" >&2
    return 1
  fi
  printf '%s self-test: all checks passed\n' "$PROG"
}

main "$@"
