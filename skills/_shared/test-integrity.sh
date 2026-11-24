#!/usr/bin/env bash
# test-integrity.sh — the test-integrity check of a change: prove that each regression test fails on the code
# before the fix and passes after it, and list the test and CI changes that can lower the quality of testing.
#
# Why this exists
#   A fix is trusted because its tests pass at the end. A regression test that also passes on the code before
#   the fix proves nothing, and a test that is removed, weakened, or skipped, or a CI check that is switched off,
#   makes a change look tested when it is not. `skills/test-audit/references/test-integrity.md` owns when each
#   subcommand runs and how a reviewer reads its output; this script only produces the evidence.
#
# Usage
#   test-integrity.sh prove --command "<runner> [<option>...]" --test "<file>::<test name>" [--test ...]
#                           [--actor <name>]
#   test-integrity.sh verify --record <absolute path> --base <commit id> --head <commit id>
#   test-integrity.sh scan --base <commit id> --head <commit id>
#   test-integrity.sh --self-test
#
# prove — the implementer runs it in its own worktree, after it committed the fix and the test
#   1. Refuses (exit 3, the tree untouched) a working tree with any change or untracked file, a command that
#      `validate_command` of project-commands.sh refuses or that sets `--log-junit` itself, and a test that is not
#      a regular test-side file the branch changed since its merge base with the default branch. It also refuses
#      an ignored file at a production path the branch deleted, or at a parent of that path, because the swap
#      would write the base file over it and the restore would delete it.
#   2. Ends unprovable (exit 4, no swap) when the runner is not `vendor/bin/pest` or `vendor/bin/phpunit`, when the
#      branch changes a dependency manifest or lockfile, or when it changes test-side paths only.
#   3. Holds the machine lock (gate_lock_acquire) for the swap and both runs. It never starts another wrapper of
#      the lock, and a command it runs inherits the lock instead of waiting for it.
#   4. Puts every production path the branch changed back to the merge base (`git restore --source=<base>
#      --worktree`, the index untouched), runs the named tests with `--log-junit`, restores HEAD, and runs them
#      again. A trap restores HEAD on every exit, a signal included; a tree that does not return to HEAD exits 5.
#      Residual risk: SIGKILL cannot be trapped. A prover killed during the swap leaves the production paths at
#      the base with no message. Every change is committed, so `git status` lists them, the next prove refuses
#      the dirty tree, and `git restore --source=HEAD --worktree -- :/` plus removing the listed untracked files
#      brings the tree back.
#   5. A test is proven when it failed or errored on the base and passed on HEAD. One base pass makes the
#      record refuted; a missing result, a skip, or a HEAD failure makes it incomplete.
#   6. Writes `<gate-evidence>/regression/<key>.json`, `<key>.base.xml`, `<key>.head.xml`, and `<key>.log`. The key
#      is the SHA-256 of the sorted `<path> TAB <name> TAB <blob>` lines of the named tests, so a rebase or a later
#      production commit keeps the record and a changed test gets a new one. No value of a record enters a path.
#
# verify — the reviewer runs it from the trusted copy; it executes nothing from the record or the branch
#   Refuses (exit 3) a record path that is not absolute, carries a control character, has a symbolic link in a
#   component, is not a regular file, or is not `.../regression/<64 hex>.json`. Reports malformed (exit 13) a record whose fields, file name,
#   sibling hashes, or statuses do not match: both JUnit reports are parsed again. Reports stale (exit 12) a test
#   the pull request no longer changes, a test whose blob at --head differs from the record, and a recorded base
#   that is not an ancestor of the current merge base. Prints the base failure message of each test, so the
#   reviewer judges whether the test failed for the right reason.
#
# scan — the implementer runs it before the pull request, the reviewer from the trusted copy on every review
#   Reads only `git diff` from the merge base of --base and --head to --head, executes nothing, and lists the
#   places a reviewer reads: a removed or moved-out test file, a removed test declaration, an added skip, a
#   removed assertion line of a test the base already had, a changed test configuration, a removed or loosened
#   CI step, a removed Composer script, a lowered `--min=`, and a lowered PHPStan level. A test the change adds
#   is never a candidate, and neither is a test moved inside the test side unchanged. A binary diff on a watched
#   path, a path with a control character, and a git or jq error end the scan incomplete. The list is candidates,
#   never a proof that nothing else was weakened.
#
# Test-side paths
#   One pattern, taken from the trigger of the test-value lens in
#   `skills/code-review/references/specialized-reviews.md`: a path under `tests/` at any depth, a `*Test.php`
#   file, a `*.spec.ts` file, and a `*.test.ts` file (TI_TEST_SIDE_RE). Every subcommand classifies a path with it.
#
# TRUST MODEL — what a record proves, and what it does not
#   A record is a local cache of one account, like the gate record (gate-record.sh). The tests it ran are the
#   code of the branch, run with the vendor of the branch, so that code can forge a record. The defence is
#   the review, which reads the base failure message, the re-parse of both JUnit reports, and CI. The hashes
#   detect a damaged or swapped file; they do not prove that a file is authentic.
#
# SECURITY
#   - A command runs only through `run_command` of project-commands.sh: an allow-listed executable, split into
#     an argv, never a shell. A command never comes from a record, a pull request, or a tracker text.
#   - A path, a test name, and a message from a JUnit report are data. They are compared, passed as argv
#     elements of their own, and printed only after control characters are removed; nothing reaches a shell.
#   - A path that carries a control character is refused, so no output line can be split or carry a terminal
#     escape sequence.
#
# Output
#   prove   `regression-proof=<proven|refuted|incomplete|unprovable|refused|blocked|locked>`, `reason=<text>` when
#           it stopped, `record=<absolute path>` when it wrote one, and one
#           `test TAB <verdict> TAB base=<status> TAB head=<status> TAB <file>::<name>` line per test
#   verify  one test line per test, then `base-message TAB <file>::<name> TAB <message>`, and the last line
#           `verify-verdict=<proven|refuted|incomplete|stale|malformed|missing|refused>` with a `reason=` line before it
#   scan    one `candidate TAB <kind> TAB <path> TAB <line or ->` line per place, where a removed line carries its
#           base line number and an added line its head line number, then the last line `scan-verdict=complete` or
#           `scan-verdict=incomplete reason=<reason>`. It never prints the text of a diff line.
#
# Exit codes
#   prove   0 proven, 3 refused, 4 unprovable or incomplete, 5 blocked (the tree did not return to HEAD),
#           6 the machine lock timed out, 11 refuted, 129/130/143 stopped by a signal (the tree restored)
#   verify  0 proven, 3 refused, 10 missing, 11 refuted or incomplete, 12 stale, 13 malformed
#   scan    0 complete with no candidate, 5 complete with candidates, 7 incomplete
#   --self-test  0 passed, 1 a check failed; every subcommand exits 1 on a usage error

set -uo pipefail

TI_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TI_SELF="$TI_DIR/test-integrity.sh"
PROG='test-integrity.sh'
# shellcheck source=gate-record.sh
. "$TI_DIR/gate-record.sh"

TI_TEST_SIDE_RE='(^|/)tests/|Test\.php$|\.spec\.ts$|\.test\.ts$'
TI_DEPENDENCY_RE='(^|/)(composer\.json|composer\.lock|package\.json|package-lock\.json|npm-shrinkwrap\.json|yarn\.lock|pnpm-lock\.yaml)$'
TI_OID_RE='^[0-9a-f]{40}([0-9a-f]{24})?$'
TI_NAME_LIMIT=500

TI_TMP=''
TI_SWAP_ACTIVE=0
TI_SWAP_CREATED=()

ti_usage() {
  cat >&2 <<'USAGE'
Usage: test-integrity.sh prove --command "<runner> [<option>...]" --test "<file>::<test name>" [--test ...] [--actor <name>]
       test-integrity.sh verify --record <absolute path> --base <commit id> --head <commit id>
       test-integrity.sh scan --base <commit id> --head <commit id>
       test-integrity.sh --self-test
USAGE
  exit 1
}

# A path the test-value lens treats as a test.
ti_is_test_side() {
  [[ "$1" =~ $TI_TEST_SIDE_RE ]]
}

# The value carries a control character: a byte below 0x20, or 0x7f.
ti_has_control() {
  local LC_ALL=C
  [[ "$1" == *[[:cntrl:]]* ]]
}

# A path relative to the project that neither climbs out of it nor carries a control character.
ti_safe_relative_path() {
  [[ -n "$1" && "$1" != /* ]] || return 1
  [[ ! "$1" =~ (^|/)\.\.(/|$) ]] || return 1
  ! ti_has_control "$1"
}

# The key of a record: the SHA-256 of the sorted `<path> TAB <name> TAB <blob>` lines.
ti_key() {
  printf '%s\n' "$@" | LC_ALL=C sort | gate_sha256 -
}

ti_in_list() {
  local needle="$1" item
  shift
  for item in "$@"; do
    [[ "$item" != "$needle" ]] || return 0
  done
  return 1
}

# The status of one named test in the TSV rows of junit-results.php: its status, `missing`, or `ambiguous`.
ti_status_of() {
  TI_FILE="$2" TI_NAME="$3" awk -F'\t' '
    $2 == ENVIRON["TI_FILE"] && $3 == ENVIRON["TI_NAME"] { status = $1; count++ }
    END { if (count == 0) print "missing"; else if (count > 1) print "ambiguous"; else print status }' <<<"$1"
}

ti_message_of() {
  TI_FILE="$2" TI_NAME="$3" awk -F'\t' '$2 == ENVIRON["TI_FILE"] && $3 == ENVIRON["TI_NAME"] { print $4; exit }' <<<"$1"
}

# The verdict of one test from its two statuses.
ti_test_verdict() {
  if [[ ("$1" == failed || "$1" == errored) && "$2" == passed ]]; then
    printf 'proven'
  elif [[ "$1" == passed ]]; then
    printf 'refuted'
  else
    printf 'incomplete'
  fi
}

# The rows of a JUnit report, or nothing when the report is missing or unreadable.
ti_junit_rows() {
  php "$TI_DIR/junit-results.php" --root "$1" "$2" 2>/dev/null || true
}

ti_temp_dir() {
  TI_TMP="$(mktemp -d "${TMPDIR:-/tmp}/test-integrity.XXXXXX")" || return 1
}

# --- prove ---------------------------------------------------------------------------------------------------

ti_prove_stop() {
  printf 'regression-proof=%s\nreason=%s\n' "$2" "$(safe_display "$3")"
  exit "$1"
}

# Bring the working tree back to HEAD after a swap: every tracked path from HEAD, every path the base created and
# HEAD does not have removed, then a clean status. Returns 1 when the tree did not return to HEAD.
ti_restore() {
  [[ "$TI_SWAP_ACTIVE" -eq 1 ]] || return 0
  TI_SWAP_ACTIVE=0
  local path failed=0 dirty
  git restore --source=HEAD --worktree -- :/ >/dev/null 2>&1 || failed=1
  for path in ${TI_SWAP_CREATED[@]+"${TI_SWAP_CREATED[@]}"}; do
    if gate_no_symlink "$(dirname -- "$path")" 2>/dev/null; then
      rm -f -- "$path" || failed=1
    else
      failed=1
    fi
  done
  dirty="$(git status --porcelain=v1 --untracked-files=all 2>/dev/null)" || failed=1
  [[ -z "$dirty" ]] || failed=1
  return "$failed"
}

# The swap may create the path: nothing sits at it, and each of its parents is a directory or absent. A clean
# status does not show an ignored file, and the swap would write over it and the restore would delete it.
ti_swap_target_free() {
  local dir
  [[ ! -e "$1" && ! -L "$1" ]] || return 1
  dir="$(dirname -- "$1")"
  while [[ "$dir" != . && "$dir" != / ]]; do
    [[ ! -e "$dir" || -d "$dir" ]] || return 1
    dir="$(dirname -- "$dir")"
  done
  return 0
}

ti_blocked_message() {
  printf 'regression-proof=blocked\nreason=the working tree did not return to HEAD; every change is committed, so run: git restore --source=HEAD --worktree -- :/ and remove the untracked files git status --untracked-files=all lists\n'
}

ti_prove_exit() {
  local status=$?
  trap - EXIT
  if ! ti_restore; then
    ti_blocked_message
    status=5
  fi
  gate_cleanup
  [[ -z "$TI_TMP" ]] || rm -rf -- "$TI_TMP"
  exit "$status"
}

ti_prove() {
  local command='' actor='donatello' spec path name mode blob top reason runner ref head base
  local -a specs=() files=() key_lines=() changed=() production=() names=() paths=() blobs=()
  while [[ $# -gt 0 ]]; do
    case "$1" in
    --command) [[ $# -ge 2 ]] || ti_usage; command="$2"; shift 2 ;;
    --test) [[ $# -ge 2 ]] || ti_usage; specs+=("$2"); shift 2 ;;
    --actor) [[ $# -ge 2 ]] || ti_usage; actor="$2"; shift 2 ;;
    *) ti_usage ;;
    esac
  done
  [[ -n "$command" && "${#specs[@]}" -gt 0 ]] || ti_usage
  [[ "$actor" =~ ^[A-Za-z0-9._-]{1,64}$ ]] || ti_prove_stop 3 refused '--actor must be a short name'

  top="$(git rev-parse --show-toplevel 2>/dev/null)" || ti_prove_stop 3 refused 'not inside a git working tree'
  cd -- "$top" || ti_prove_stop 3 refused 'the working tree is not readable'
  reason="$(git status --porcelain=v1 --untracked-files=all 2>/dev/null)" || ti_prove_stop 3 refused 'git status failed'
  [[ -z "$reason" ]] || ti_prove_stop 3 refused 'the working tree has a change or an untracked file; commit the fix and the test first'

  load_project_manifest || ti_prove_stop 3 refused "$PROJECT_REFUSAL"
  if ! reason="$(validate_command "$command" 2>&1 >/dev/null)"; then
    ti_prove_stop 3 refused "refused command: $reason"
  fi
  case " $command" in
  *' --log-junit'*) ti_prove_stop 3 refused 'the command sets --log-junit; the prover sets it' ;;
  esac
  read -r runner _ <<<"$command"
  runner="${runner#./}"
  if [[ "$runner" != vendor/bin/pest && "$runner" != vendor/bin/phpunit ]]; then
    ti_prove_stop 4 unprovable 'unsupported runner: only vendor/bin/pest and vendor/bin/phpunit report one result per test'
  fi

  ref="$(bash "$TI_DIR/read-manifest.sh" --default-ref 2>/dev/null)" || ti_prove_stop 3 refused 'the default branch does not resolve; run: git remote set-head origin --auto'
  head="$(git rev-parse --verify --quiet 'HEAD^{commit}')" || ti_prove_stop 3 refused 'HEAD does not resolve'
  base="$(git merge-base "$ref" "$head" 2>/dev/null)" || ti_prove_stop 3 refused 'HEAD has no merge base with the default branch'
  [[ "$base" != "$head" ]] || ti_prove_stop 3 refused 'HEAD carries no commit on top of the default branch'

  ti_temp_dir || ti_prove_stop 3 refused 'no temporary directory'
  trap ti_prove_exit EXIT
  trap 'exit 130' INT
  trap 'exit 143' TERM
  trap 'exit 129' HUP

  git diff --name-only -z --no-renames "$base" "$head" >"$TI_TMP/changed" 2>/dev/null || ti_prove_stop 3 refused 'git diff failed'
  while IFS= read -r -d '' path; do
    changed+=("$path")
  done <"$TI_TMP/changed"

  for spec in "${specs[@]}"; do
    [[ "$spec" == *'::'* ]] || ti_prove_stop 3 refused 'a --test is <file>::<test name>'
    path="${spec%%::*}"
    name="${spec#*::}"
    [[ -n "$name" && "${#name}" -le "$TI_NAME_LIMIT" ]] || ti_prove_stop 3 refused 'a test name is empty or too long'
    ! ti_has_control "$name" || ti_prove_stop 3 refused 'a test name carries a control character'
    ti_safe_relative_path "$path" || ti_prove_stop 3 refused "an unsafe test path: $path"
    ti_is_test_side "$path" || ti_prove_stop 3 refused "not a test-side path: $path"
    ti_in_list "$path" "${changed[@]}" || ti_prove_stop 3 refused "the branch does not change the test: $path"
    mode="$(GIT_LITERAL_PATHSPECS=1 git ls-tree "$head" -- "$path" 2>/dev/null)" || mode=''
    [[ "$mode" =~ ^100(644|755)\ blob\ ([0-9a-f]{40}([0-9a-f]{24})?)$'\t' ]] || ti_prove_stop 3 refused "not a regular file at HEAD: $path"
    blob="${BASH_REMATCH[2]}"
    gate_no_symlink "$path" || ti_prove_stop 3 refused "$GATE_REASON"
    [[ -f "$path" ]] || ti_prove_stop 3 refused "not a regular file: $path"
    key_lines+=("$path"$'\t'"$name"$'\t'"$blob")
    paths+=("$path")
    names+=("$name")
    blobs+=("$blob")
    ti_in_list "$path" ${files[@]+"${files[@]}"} || files+=("$path")
  done

  for path in "${changed[@]}"; do
    if [[ "$path" =~ $TI_DEPENDENCY_RE ]]; then
      ti_prove_stop 4 unprovable "dependency change: the base cannot run with the dependencies of HEAD ($path)"
    fi
  done
  for path in "${changed[@]}"; do
    ti_is_test_side "$path" && continue
    production+=("$path")
    if ! git cat-file -e "$head:$path" 2>/dev/null; then
      TI_SWAP_CREATED+=("$path")
    fi
  done
  [[ "${#production[@]}" -gt 0 ]] || ti_prove_stop 4 unprovable 'no production change: the branch changes test-side paths only'
  for path in ${TI_SWAP_CREATED[@]+"${TI_SWAP_CREATED[@]}"}; do
    gate_no_symlink "$path" || ti_prove_stop 3 refused "$GATE_REASON"
    ti_swap_target_free "$path" || ti_prove_stop 3 refused "an ignored file sits at a path the branch deleted, or at a parent of it: $path"
  done

  gate_evidence_dir write || ti_prove_stop 3 refused "$GATE_REASON"
  local dir="$GATE_EVIDENCE/regression" key record base_xml head_xml log file
  (umask 077 && mkdir -p -- "$dir") || ti_prove_stop 3 refused 'the evidence directory cannot be created'
  gate_no_symlink "$dir" || ti_prove_stop 3 refused "$GATE_REASON"
  key="$(ti_key "${key_lines[@]}")" || ti_prove_stop 3 refused 'the key cannot be computed'
  record="$dir/$key.json"
  base_xml="$dir/$key.base.xml"
  head_xml="$dir/$key.head.xml"
  log="$dir/$key.log"
  for file in "$record" "$base_xml" "$head_xml" "$log"; do
    gate_no_symlink "$file" || ti_prove_stop 3 refused "$GATE_REASON"
    rm -f -- "$file"
  done
  : >"$log"

  local status=0 started_at
  gate_lock_timeout || ti_prove_stop 3 refused "$GATE_REASON"
  GATE_HOLDER_COMMAND='test-integrity.sh prove'
  gate_lock_acquire "$GATE_LOCK_TIMEOUT" || status=$?
  if [[ "$status" -eq 6 ]]; then
    ti_prove_stop 6 locked "$GATE_REASON"
  elif [[ "$status" -ne 0 ]]; then
    ti_prove_stop 3 refused "$GATE_REASON"
  fi
  started_at="$(gate_now)"

  TI_SWAP_ACTIVE=1
  if ! GIT_LITERAL_PATHSPECS=1 git restore --source="$base" --worktree -- "${production[@]}" >>"$log" 2>&1; then
    ti_restore || { ti_blocked_message; exit 5; }
    ti_prove_stop 4 unprovable 'the base state of the production paths could not be checked out'
  fi
  printf '### base %s\n' "$base" >>"$log"
  run_command "$command" "$log" --log-junit "$base_xml" "${files[@]}" || true
  if ! ti_restore; then
    ti_blocked_message
    exit 5
  fi
  printf '### head %s\n' "$head" >>"$log"
  run_command "$command" "$log" --log-junit "$head_xml" "${files[@]}" || true
  reason="$(git status --porcelain=v1 --untracked-files=all 2>/dev/null)" || reason='unreadable'
  if [[ -n "$reason" ]]; then
    ti_blocked_message
    exit 5
  fi
  [[ -f "$base_xml" && ! -L "$base_xml" ]] || : >"$base_xml"
  [[ -f "$head_xml" && ! -L "$head_xml" ]] || : >"$head_xml"

  local base_rows head_rows index bs hs tv verdict=proven tests='[]' message lines=()
  base_rows="$(ti_junit_rows "$top" "$base_xml")"
  head_rows="$(ti_junit_rows "$top" "$head_xml")"
  for index in "${!paths[@]}"; do
    bs="$(ti_status_of "$base_rows" "${paths[index]}" "${names[index]}")"
    hs="$(ti_status_of "$head_rows" "${paths[index]}" "${names[index]}")"
    message="$(ti_message_of "$base_rows" "${paths[index]}" "${names[index]}")"
    tv="$(ti_test_verdict "$bs" "$hs")"
    if [[ "$tv" == refuted ]]; then
      verdict=refuted
    elif [[ "$tv" == incomplete && "$verdict" == proven ]]; then
      verdict=incomplete
    fi
    tests="$(jq -c --arg p "${paths[index]}" --arg n "${names[index]}" --arg b "${blobs[index]}" --arg bs "$bs" \
      --arg hs "$hs" --arg m "$message" --arg v "$tv" \
      '. + [{path: $p, name: $n, blob: $b, base_status: $bs, head_status: $hs, base_message: $m, verdict: $v}]' <<<"$tests")"
    lines+=("$(printf 'test\t%s\tbase=%s\thead=%s\t%s::%s' "$tv" "$bs" "$hs" "$(safe_display "${paths[index]}")" "$(safe_display "${names[index]}")")")
  done

  jq -n --arg verdict "$verdict" --arg base "$base" --arg head "$head" --arg command "$command" --arg actor "$actor" \
    --arg root "$top" --arg started "$started_at" --arg finished "$(gate_now)" --argjson tests "$tests" \
    --arg bx "$(gate_sha256 "$base_xml")" --arg hx "$(gate_sha256 "$head_xml")" --arg lg "$(gate_sha256 "$log")" \
    '{schema: 1, kind: "regression-proof", verdict: $verdict, base: $base, head: $head, command: $command,
      actor: $actor, root: $root, started_at: $started, finished_at: $finished, tests: $tests,
      base_junit_sha256: $bx, head_junit_sha256: $hx, log_sha256: $lg}' >"$TI_TMP/record.json" \
    || ti_prove_stop 3 refused 'the record cannot be written'
  mv -f -- "$TI_TMP/record.json" "$record"

  printf 'regression-proof=%s\nrecord=%s/%s\n' "$verdict" "$(pwd -P)" "$record"
  printf '%s\n' "${lines[@]}"
  case "$verdict" in
  proven) exit 0 ;;
  refuted) exit 11 ;;
  *) exit 4 ;;
  esac
}

# --- verify --------------------------------------------------------------------------------------------------

ti_verify_stop() {
  printf 'reason=%s\nverify-verdict=%s\n' "$(safe_display "$3")" "$2"
  exit "$1"
}

ti_verify() {
  local record='' base='' head='' dir name key field
  while [[ $# -gt 0 ]]; do
    case "$1" in
    --record) [[ $# -ge 2 ]] || ti_usage; record="$2"; shift 2 ;;
    --base) [[ $# -ge 2 ]] || ti_usage; base="$2"; shift 2 ;;
    --head) [[ $# -ge 2 ]] || ti_usage; head="$2"; shift 2 ;;
    *) ti_usage ;;
    esac
  done
  [[ -n "$record" && -n "$base" && -n "$head" ]] || ti_usage
  command -v php >/dev/null 2>&1 || ti_verify_stop 3 refused 'php is not available'

  { [[ "$record" == /* ]] && ! ti_has_control "$record"; } \
    || ti_verify_stop 3 refused 'the record path must be absolute, with no control character'
  gate_no_symlink "$record" || ti_verify_stop 3 refused 'a component of the record path is a symbolic link'
  [[ -e "$record" ]] || ti_verify_stop 10 missing 'no record at that path'
  [[ -f "$record" ]] || ti_verify_stop 3 refused 'the record is not a regular file'
  dir="$(dirname -- "$record")"
  name="$(basename -- "$record")"
  [[ "$(basename -- "$dir")" == regression ]] || ti_verify_stop 3 refused 'the record is not in a regression directory'
  [[ "$name" =~ ^[0-9a-f]{64}\.json$ ]] || ti_verify_stop 3 refused 'the record file name is not <key>.json'
  key="${name%.json}"
  [[ "$base" =~ $TI_OID_RE && "$head" =~ $TI_OID_RE ]] || ti_verify_stop 3 refused '--base and --head take full commit ids'
  git cat-file -e "$base^{commit}" 2>/dev/null || ti_verify_stop 3 refused 'the --base commit is not available'
  git cat-file -e "$head^{commit}" 2>/dev/null || ti_verify_stop 3 refused 'the --head commit is not available'

  for field in \
    '.schema == 1' \
    '.kind == "regression-proof"' \
    '.verdict == "proven" or .verdict == "refuted" or .verdict == "incomplete"' \
    '.base | type == "string" and test("^[0-9a-f]{40}([0-9a-f]{24})?$")' \
    '.root | type == "string"' \
    '.tests | type == "array" and length > 0 and all(.[]; (.path | type == "string") and (.name | type == "string") and (.blob | type == "string" and test("^[0-9a-f]{40}([0-9a-f]{24})?$")) and (.base_status | type == "string") and (.head_status | type == "string"))' \
    '.base_junit_sha256 | type == "string" and test("^[0-9a-f]{64}$")' \
    '.head_junit_sha256 | type == "string" and test("^[0-9a-f]{64}$")' \
    '.log_sha256 | type == "string" and test("^[0-9a-f]{64}$")'; do
    jq -e "$field" "$record" >/dev/null 2>&1 || ti_verify_stop 13 malformed "the record fails the check $field"
  done

  local -a key_lines=()
  local line
  while IFS= read -r line; do
    key_lines+=("$line")
  done < <(jq -j '.tests[] | .path, "\t", .name, "\t", .blob, "\n"' "$record")
  [[ "$(ti_key "${key_lines[@]}")" == "$key" ]] || ti_verify_stop 13 malformed 'the file name is not the key of the tests the record names'

  local suffix sum
  for suffix in base.xml head.xml log; do
    [[ -f "$dir/$key.$suffix" && ! -L "$dir/$key.$suffix" ]] || ti_verify_stop 13 malformed "the record has no $suffix beside it"
  done
  for field in base.xml:base_junit_sha256 head.xml:head_junit_sha256 log:log_sha256; do
    sum="$(gate_sha256 "$dir/$key.${field%%:*}")" || sum=''
    jq -e --arg s "$sum" --arg f "${field#*:}" '.[$f] == $s' "$record" >/dev/null 2>&1 \
      || ti_verify_stop 13 malformed "the ${field%%:*} beside the record does not match its hash"
  done

  local root base_rows head_rows count index path tname blob bs hs tv verdict=proven
  local -a out=()
  root="$(jq -r '.root' "$record")"
  base_rows="$(ti_junit_rows "$root" "$dir/$key.base.xml")"
  head_rows="$(ti_junit_rows "$root" "$dir/$key.head.xml")"
  count="$(jq '.tests | length' "$record")"
  for ((index = 0; index < count; index++)); do
    path="$(jq -r --argjson i "$index" '.tests[$i].path' "$record")"
    tname="$(jq -r --argjson i "$index" '.tests[$i].name' "$record")"
    bs="$(ti_status_of "$base_rows" "$path" "$tname")"
    hs="$(ti_status_of "$head_rows" "$path" "$tname")"
    jq -e --argjson i "$index" --arg bs "$bs" --arg hs "$hs" '.tests[$i].base_status == $bs and .tests[$i].head_status == $hs' \
      "$record" >/dev/null 2>&1 || ti_verify_stop 13 malformed 'the statuses in the record differ from its JUnit reports'
    tv="$(ti_test_verdict "$bs" "$hs")"
    if [[ "$tv" == refuted ]]; then
      verdict=refuted
    elif [[ "$tv" == incomplete && "$verdict" == proven ]]; then
      verdict=incomplete
    fi
    out+=("$(printf 'test\t%s\tbase=%s\thead=%s\t%s::%s' "$tv" "$bs" "$hs" "$(safe_display "$path")" "$(safe_display "$tname")")")
    out+=("$(printf 'base-message\t%s::%s\t%s' "$(safe_display "$path")" "$(safe_display "$tname")" "$(ti_message_of "$base_rows" "$path" "$tname")")")
  done
  jq -e --arg v "$verdict" '.verdict == $v' "$record" >/dev/null 2>&1 || ti_verify_stop 13 malformed 'the verdict in the record differs from its JUnit reports'

  local merge_base recorded_base
  local -a changed=()
  ti_temp_dir || ti_verify_stop 3 refused 'no temporary directory'
  trap 'rm -rf -- "$TI_TMP"' EXIT
  merge_base="$(git merge-base "$base" "$head" 2>/dev/null)" || ti_verify_stop 12 stale 'the base and the head have no merge base'
  git diff --name-only -z --no-renames "$merge_base" "$head" >"$TI_TMP/changed" 2>/dev/null || ti_verify_stop 3 refused 'git diff failed'
  while IFS= read -r -d '' path; do
    changed+=("$path")
  done <"$TI_TMP/changed"
  for ((index = 0; index < count; index++)); do
    path="$(jq -r --argjson i "$index" '.tests[$i].path' "$record")"
    blob="$(jq -r --argjson i "$index" '.tests[$i].blob' "$record")"
    if ! ti_safe_relative_path "$path" || ! ti_is_test_side "$path"; then
      ti_verify_stop 13 malformed 'the record names a path that is not a test-side path'
    fi
    ti_in_list "$path" ${changed[@]+"${changed[@]}"} || ti_verify_stop 12 stale "the pull request no longer changes $(safe_display "$path")"
    [[ "$(git rev-parse --verify --quiet "$head:$path" 2>/dev/null)" == "$blob" ]] \
      || ti_verify_stop 12 stale "the test changed after the proof: $(safe_display "$path")"
  done
  recorded_base="$(jq -r '.base' "$record")"
  git cat-file -e "$recorded_base^{commit}" 2>/dev/null || ti_verify_stop 12 stale 'the recorded base commit is not available'
  git merge-base --is-ancestor "$recorded_base" "$merge_base" 2>/dev/null \
    || ti_verify_stop 12 stale 'the recorded base is not an ancestor of the current merge base'

  printf '%s\n' "${out[@]}"
  case "$verdict" in
  proven) ti_verify_stop 0 proven 'every test failed on the base and passed on HEAD' ;;
  refuted) ti_verify_stop 11 refuted 'a test passed on the base' ;;
  *) ti_verify_stop 11 incomplete 'a test has no base failure or no HEAD pass' ;;
  esac
}

# --- scan ----------------------------------------------------------------------------------------------------

TI_DECL_RE='^[[:space:]]*(it|test)[[:space:]]*\(|function[[:space:]]+test|#\[([A-Za-z\\]*\\)?Test\]|@test([^A-Za-z]|$)'
TI_SKIP_RE='(->|\.)[[:space:]]*(skip[a-z]*|todo|only)[[:space:]]*\(|marktest(skipped|incomplete)|^[[:space:]]*x(it|test|describe)[[:space:]]*\('
TI_ASSERT_RE='expect[[:space:]]*\(|->[[:space:]]*to[a-z]+[[:space:]]*\(|assert[a-z]*[[:space:]]*\('
TI_LOOSEN_RE='continue-on-error:[[:space:]]*true|if:[[:space:]]*(false|\$\{\{[[:space:]]*false[[:space:]]*\}\})|\|\|[[:space:]]*(true|:|exit[[:space:]]+0)([^A-Za-z0-9]|$)|set[[:space:]]+\+e|paths-ignore'
TI_MIN_RE='--min=([0-9]+)'
TI_LEVEL_RE='^[[:space:]]*level:[[:space:]]*([0-9]+|max)'

TI_SCAN_BASE=''
TI_SCAN_HEAD=''
TI_SCAN_CANDIDATES=0

ti_scan_incomplete() {
  printf 'scan-verdict=incomplete reason=%s\n' "$1"
  exit "${2:-7}"
}

ti_candidate() {
  printf 'candidate\t%s\t%s\t%s\n' "$1" "$2" "$3"
  TI_SCAN_CANDIDATES=$((TI_SCAN_CANDIDATES + 1))
}

# The kind of check a path carries: test, test-config, ci, composer, phpstan, or none.
ti_watch_kind() {
  if [[ "$1" =~ (^|/)phpunit(\.dist)?\.xml(\.dist)?$ || "$1" =~ (^|/)tests/Pest\.php$ ]]; then
    printf 'test-config'
  elif [[ "$1" =~ ^\.github/workflows/[^/]+\.ya?ml$ || "$1" =~ ^\.github/actions/ ]]; then
    printf 'ci'
  elif [[ "$1" == composer.json ]]; then
    printf 'composer'
  elif [[ "$1" =~ (^|/)phpstan(\.dist)?\.neon(\.dist)?$ ]]; then
    printf 'phpstan'
  elif ti_is_test_side "$1"; then
    printf 'test'
  else
    printf 'none'
  fi
}

# A line without its whitespace and its trailing separator, for comparing an edited line with its new form.
ti_norm() {
  local text="${1//[[:space:]]/}"
  text="${text%;}"
  printf '%s' "${text%,}"
}

# The level number of a `level:` line; `max` is level 10.
ti_level_value() {
  [[ "$1" =~ $TI_LEVEL_RE ]] || return 1
  if [[ "${BASH_REMATCH[1]}" == max ]]; then printf '10'; else printf '%s' "${BASH_REMATCH[1]}"; fi
}

# The candidates of a deleted path of a watched kind.
ti_scan_deleted() {
  case "$1" in
  test) ti_candidate test-file-removed "$2" - ;;
  test-config) ti_candidate test-config-changed "$2" - ;;
  ci) ti_candidate ci-changed "$2" - ;;
  phpstan) ti_candidate analysis-level-lowered "$2" - ;;
  esac
}

# Read the zero-context diff of one pre-existing file and list its candidates. The diff text is compared,
# never printed: a candidate names its kind, the path, and a line number only.
ti_scan_file() {
  local kind="$1" old="$2" new="$3" line text in_hunk=0 old_line=0 new_line=0 index found value
  local -a removed=() removed_at=() added=() added_at=()
  local -a pathspec=("$new")
  [[ "$old" == "$new" ]] || pathspec=("$old" "$new")
  GIT_LITERAL_PATHSPECS=1 git diff -M --no-color --no-ext-diff --no-textconv -U0 "$TI_SCAN_BASE" "$TI_SCAN_HEAD" \
    -- "${pathspec[@]}" >"$TI_TMP/diff" 2>/dev/null || ti_scan_incomplete git-error
  while IFS= read -r line || [[ -n "$line" ]]; do
    case "$line" in
    'diff --git '*) in_hunk=0 ;;
    'Binary files '* | 'GIT binary patch'*)
      [[ "$in_hunk" -eq 1 ]] || ti_scan_incomplete "binary-diff path=$new"
      ;;
    '@@ '*)
      [[ "$line" =~ ^@@\ -([0-9]+)(,[0-9]+)?\ \+([0-9]+)(,[0-9]+)?\ @@ ]] || ti_scan_incomplete unparsable-diff
      old_line="${BASH_REMATCH[1]}"
      new_line="${BASH_REMATCH[3]}"
      in_hunk=1
      ;;
    -*)
      if [[ "$in_hunk" -eq 1 ]]; then
        removed+=("${line:1}")
        removed_at+=("$old_line")
        old_line=$((old_line + 1))
      fi
      ;;
    +*)
      if [[ "$in_hunk" -eq 1 ]]; then
        added+=("${line:1}")
        added_at+=("$new_line")
        new_line=$((new_line + 1))
      fi
      ;;
    esac
  done <"$TI_TMP/diff"

  local -a added_norm=()
  for text in ${added[@]+"${added[@]}"}; do
    added_norm+=("$(ti_norm "$text")")
  done

  for index in ${removed[@]+"${!removed[@]}"}; do
    text="${removed[index]}"
    case "$kind" in
    test)
      local norm key
      norm="$(ti_norm "$text")"
      if [[ "$text" =~ $TI_DECL_RE ]]; then
        key="${norm%%,*}"
        found=0
        for value in ${added_norm[@]+"${added_norm[@]}"}; do
          [[ "$value" != "$key"* ]] || { found=1; break; }
        done
        if [[ "$found" -eq 0 ]]; then
          ti_candidate test-case-removed "$old" "${removed_at[index]}"
          continue
        fi
      fi
      if [[ "$text" =~ $TI_ASSERT_RE ]]; then
        found=0
        for value in ${added_norm[@]+"${added_norm[@]}"}; do
          [[ "$value" != *"$norm"* ]] || { found=1; break; }
        done
        [[ "$found" -eq 1 ]] || ti_candidate assertion-changed "$old" "${removed_at[index]}"
      fi
      ;;
    test-config | ci) ti_candidate "$kind-changed" "$old" "${removed_at[index]}" ;;
    esac
  done

  for index in ${added[@]+"${!added[@]}"}; do
    text="${added[index]}"
    case "$kind" in
    test) [[ ! "$text" =~ $TI_SKIP_RE ]] || ti_candidate test-skipped "$new" "${added_at[index]}" ;;
    test-config) [[ "$text" != *'<exclude'* ]] || ti_candidate test-config-changed "$new" "${added_at[index]}" ;;
    ci) [[ ! "$text" =~ $TI_LOOSEN_RE ]] || ti_candidate ci-changed "$new" "${added_at[index]}" ;;
    esac
  done

  if [[ "$kind" == ci || "$kind" == composer ]]; then
    local highest=-1
    for text in ${removed[@]+"${removed[@]}"}; do
      if [[ "$text" =~ $TI_MIN_RE ]] && [[ "${BASH_REMATCH[1]}" -gt "$highest" ]]; then
        highest="${BASH_REMATCH[1]}"
      fi
    done
    for index in ${added[@]+"${!added[@]}"}; do
      if [[ "${added[index]}" =~ $TI_MIN_RE ]] && [[ "${BASH_REMATCH[1]}" -lt "$highest" ]]; then
        ti_candidate threshold-lowered "$new" "${added_at[index]}"
      fi
    done
  fi

  if [[ "$kind" == phpstan ]]; then
    local before='' before_at='' after
    for index in ${removed[@]+"${!removed[@]}"}; do
      if value="$(ti_level_value "${removed[index]}")"; then
        before="$value"
        before_at="${removed_at[index]}"
      fi
    done
    if [[ -n "$before" ]]; then
      found=0
      for index in ${added[@]+"${!added[@]}"}; do
        if after="$(ti_level_value "${added[index]}")"; then
          found=1
          [[ "$after" -ge "$before" ]] || ti_candidate analysis-level-lowered "$new" "${added_at[index]}"
        fi
      done
      [[ "$found" -eq 1 ]] || ti_candidate analysis-level-lowered "$old" "$before_at"
    fi
  fi
}

# A Composer script the head drops, or an entry it drops from a script, compared through jq.
ti_scan_scripts() {
  local base_json head_json count
  base_json="$(git show "$TI_SCAN_BASE:composer.json" 2>/dev/null)" || return 0
  head_json="$(git show "$TI_SCAN_HEAD:composer.json" 2>/dev/null)" || head_json='{}'
  count="$(jq -n --argjson b "$base_json" --argjson h "$head_json" '
    def entries(v): if (v | type) == "array" then v elif v == null then [] else [v] end;
    def words(e): e | tostring | split(" ") | map(select(length > 0));
    def kept($e; $now): any(entries($now)[]; (words($e) - words(.)) == []);
    ($b.scripts // {}) as $bs | ($h.scripts // {}) as $hs
    | [$bs | to_entries[] | .value as $was | $hs[.key] as $now
      | select($now == null or any(entries($was)[]; kept(.; $now) | not))]
    | length' 2>/dev/null)" || ti_scan_incomplete jq-error
  [[ "$count" =~ ^[0-9]+$ ]] || ti_scan_incomplete jq-error
  [[ "$count" -eq 0 ]] || ti_candidate script-removed composer.json -
}

ti_scan() {
  local status old new kind new_kind
  while [[ $# -gt 0 ]]; do
    case "$1" in
    --base) [[ $# -ge 2 ]] || ti_scan_incomplete usage 1; TI_SCAN_BASE="$2"; shift 2 ;;
    --head) [[ $# -ge 2 ]] || ti_scan_incomplete usage 1; TI_SCAN_HEAD="$2"; shift 2 ;;
    *) ti_scan_incomplete usage 1 ;;
    esac
  done
  [[ "$TI_SCAN_BASE" =~ $TI_OID_RE && "$TI_SCAN_HEAD" =~ $TI_OID_RE ]] || ti_scan_incomplete usage 1
  command -v jq >/dev/null 2>&1 || ti_scan_incomplete missing-tool
  git cat-file -e "$TI_SCAN_BASE^{commit}" 2>/dev/null || ti_scan_incomplete unknown-commit
  git cat-file -e "$TI_SCAN_HEAD^{commit}" 2>/dev/null || ti_scan_incomplete unknown-commit
  TI_SCAN_BASE="$(git merge-base "$TI_SCAN_BASE" "$TI_SCAN_HEAD" 2>/dev/null)" || ti_scan_incomplete git-error
  ti_temp_dir || ti_scan_incomplete no-temporary-directory
  trap 'rm -rf -- "$TI_TMP"' EXIT
  git diff -M -z --name-status --no-color "$TI_SCAN_BASE" "$TI_SCAN_HEAD" >"$TI_TMP/names" 2>/dev/null || ti_scan_incomplete git-error
  shopt -s nocasematch

  while IFS= read -r -d '' status; do
    IFS= read -r -d '' old || ti_scan_incomplete unparsable-diff
    new="$old"
    case "$status" in
    R* | C*) IFS= read -r -d '' new || ti_scan_incomplete unparsable-diff ;;
    esac
    if ti_has_control "$old" || ti_has_control "$new"; then
      ti_scan_incomplete unsafe-path
    fi
    kind="$(ti_watch_kind "$old")"
    new_kind="$(ti_watch_kind "$new")"
    case "$status" in
    A* | C*) ;;
    D*) ti_scan_deleted "$kind" "$old" ;;
    T*) [[ "$kind" == none ]] || ti_scan_incomplete "type-change path=$old" ;;
    R*)
      if [[ "$kind" != none && "$new_kind" != "$kind" ]]; then
        ti_scan_deleted "$kind" "$old"
      elif [[ "$kind" != none && "$status" != R100 ]]; then
        ti_scan_file "$kind" "$old" "$new"
      fi
      ;;
    M*) [[ "$kind" == none ]] || ti_scan_file "$kind" "$old" "$new" ;;
    *) ti_scan_incomplete unparsable-diff ;;
    esac
    if [[ "$kind" == composer && "$status" != A* ]]; then
      ti_scan_scripts
    fi
  done <"$TI_TMP/names"

  printf 'scan-verdict=complete\n'
  [[ "$TI_SCAN_CANDIDATES" -eq 0 ]] || exit 5
  exit 0
}


# --- self-test -----------------------------------------------------------------------------------------------

TI_FAILED=0

ti_assert() {
  local label="$1"
  shift
  if "$@"; then
    printf 'ok    %s\n' "$label"
  else
    printf 'FAIL  %s\n' "$label"
    TI_FAILED=1
  fi
}

ti_not() {
  ! "$@"
}

ti_contains() {
  [[ "$1" == *"$2"* ]]
}

ti_equals() {
  [[ "$1" == "$2" ]]
}

ti_selftest_classifier() {
  ti_assert 'classify: a file under tests/ is test-side' ti_is_test_side 'tests/Unit/CalcTest.php'
  ti_assert 'classify: a tests/ directory at any depth is test-side' ti_is_test_side 'packages/billing/tests/fixture.json'
  ti_assert 'classify: a *Test.php file outside tests/ is test-side' ti_is_test_side 'src/Billing/CalcTest.php'
  ti_assert 'classify: a *.spec.ts file is test-side' ti_is_test_side 'web/calc.spec.ts'
  ti_assert 'classify: a *.test.ts file is test-side' ti_is_test_side 'web/calc.test.ts'
  ti_assert 'classify: a production file is not test-side' ti_not ti_is_test_side 'src/Billing/Calc.php'
  ti_assert 'classify: a directory named like tests is not test-side' ti_not ti_is_test_side 'src/contests/Entry.php'
  ti_assert 'path: a path with a tab is unsafe' ti_not ti_safe_relative_path $'tests/a\tb.php'
  ti_assert 'path: a path with an escape character is unsafe' ti_not ti_safe_relative_path $'tests/a\033b.php'
  ti_assert 'path: a path that climbs out is unsafe' ti_not ti_safe_relative_path 'tests/../../etc/passwd'
  ti_assert 'path: an absolute path is unsafe' ti_not ti_safe_relative_path '/etc/passwd'
  ti_assert 'path: a plain relative path is safe' ti_safe_relative_path 'tests/Unit/CalcTest.php'
}

TI_REPO=''

ti_git() {
  git -C "$TI_REPO" -c user.name=t -c user.email=t@example.test -c commit.gpgsign=false -c core.hooksPath=/dev/null "$@"
}

# The fixture: a default branch with a broken production file, a production file the fix deletes, and a test, and
# a branch `fix` that repairs the file, deletes the other one, adds a helper, and extends the test. The stub
# vendor/bin/pest writes a JUnit report whose one test passes only when src/calc.txt reads `fixed`.
ti_selftest_repo() {
  TI_REPO="$1"
  mkdir -p "$TI_REPO/vendor/bin" "$TI_REPO/src" "$TI_REPO/tests"
  printf '/.claude/run/\n/vendor/\n' >"$TI_REPO/.gitignore"
  printf '{}\n' >"$TI_REPO/composer.json"
  printf 'broken\n' >"$TI_REPO/src/calc.txt"
  printf 'legacy\n' >"$TI_REPO/src/legacy.txt"
  mkdir -p "$TI_REPO/src/gone"
  printf 'gone\n' >"$TI_REPO/src/gone/old.txt"
  printf 'it adds\n' >"$TI_REPO/tests/CalcTest.php"
  printf 'other\n' >"$TI_REPO/tests/OtherTest.php"
  git -C "$TI_REPO" init -q
  ti_git add -A
  ti_git commit -q -m base
  ti_git update-ref refs/remotes/origin/master HEAD
  ti_git symbolic-ref refs/remotes/origin/HEAD refs/remotes/origin/master
  ti_git checkout -q -b fix
  printf 'fixed\n' >"$TI_REPO/src/calc.txt"
  ti_git rm -q src/legacy.txt src/gone/old.txt
  printf 'helper\n' >"$TI_REPO/src/helper.txt"
  printf 'it adds\nit adds again\n' >"$TI_REPO/tests/CalcTest.php"
  ti_git add -A
  ti_git commit -q -m fix

  cat >"$TI_REPO/vendor/bin/pest" <<'STUB'
#!/usr/bin/env bash
junit=''
files=()
while [[ $# -gt 0 ]]; do
  case "$1" in
  --log-junit) junit="$2"; shift 2 ;;
  *) files+=("$1"); shift ;;
  esac
done
state="$(cat src/calc.txt 2>/dev/null || echo missing)"
helper=n; [[ -f src/helper.txt ]] && helper=y
legacy=n; [[ -f src/legacy.txt ]] && legacy=y
printf '%s state=%s helper=%s legacy=%s\n' "${files[*]}" "$state" "$helper" "$legacy" >>"$TI_STUB_CALLS"
if [[ -n "${TI_STUB_PROBE:-}" ]]; then bash vendor/probe.sh >>"$TI_STUB_PROBE" 2>&1; fi
if [[ -n "${TI_STUB_KILL:-}" ]]; then kill -TERM "$PPID"; exit 1; fi
[[ -z "${TI_STUB_NO_JUNIT:-}" ]] || exit 2
status=failed
[[ "$state" == fixed ]] && status=passed
[[ -z "${TI_STUB_ALWAYS_PASS:-}" ]] || status=passed
{
  printf '<?xml version="1.0" encoding="UTF-8"?>\n<testsuites><testsuite name="t">\n'
  if [[ "$status" == passed ]]; then
    printf '<testcase name="it adds" file="tests/CalcTest.php::it adds"/>\n'
  else
    printf '<testcase name="it adds" file="tests/CalcTest.php::it adds"><failure type="x">it addsexpected fixed, got %s</failure></testcase>\n' "$state"
  fi
  printf '</testsuite></testsuites>\n'
} >"$junit"
[[ "$status" == passed ]]
STUB
  cat >"$TI_REPO/vendor/probe.sh" <<'PROBE'
lock="$AI_OLYMPUS_LOCK_DIR/ai-olympus-gate.lock"
printf 'holder=%s\n' "$(sed -n 's/^WRAPPER=//p' "$lock/holder" 2>/dev/null)"
rc=0
env -u AI_OLYMPUS_LOCK_HELD_BY bash -c '. "$1/gate-record.sh"; gate_lock_acquire 1 2>/dev/null || exit "$?"; gate_cleanup' _ "$TI_LIB_DIR" || rc=$?
printf 'second-heavy-job=%s\n' "$rc"
PROBE
  chmod +x "$TI_REPO/vendor/bin/pest"
}

TI_OUT=''
TI_RC=0

ti_run() {
  TI_RC=0
  TI_OUT="$(cd "$TI_REPO" && "$@" 2>&1)" || TI_RC=$?
}

ti_prove_calc() {
  ti_run bash "$TI_SELF" prove --command 'vendor/bin/pest' --test 'tests/CalcTest.php::it adds'
}

ti_clean_tree() {
  [[ -z "$(ti_git status --porcelain=v1 --untracked-files=all)" ]] && ti_git diff --quiet HEAD
}

ti_record_path() {
  sed -n 's/^record=//p' <<<"$TI_OUT"
}

ti_record_field() {
  jq -r "$2" "$1"
}

ti_stub_calls() {
  if [[ -f "$TI_STUB_CALLS" ]]; then wc -l <"$TI_STUB_CALLS" | tr -d ' '; else printf '0'; fi
}

ti_selftest_prove() {
  local record
  ti_prove_calc
  record="$(ti_record_path)"
  ti_assert 'prove: a test that fails on the base and passes on HEAD is proven' ti_equals "$TI_RC" 0
  ti_assert 'prove: the record says proven' ti_equals "$(ti_record_field "$record" .verdict)" proven
  ti_assert 'prove: the base run saw the base state of every production path' \
    ti_equals "$(sed -n 1p "$TI_STUB_CALLS")" 'tests/CalcTest.php state=broken helper=n legacy=y'
  ti_assert 'prove: the HEAD run saw HEAD' ti_equals "$(sed -n 2p "$TI_STUB_CALLS")" 'tests/CalcTest.php state=fixed helper=y legacy=n'
  ti_assert 'prove: the tree equals HEAD afterwards, an added, a deleted, and a changed file included' ti_clean_tree
  ti_assert 'prove: the machine lock is held during the base run and the HEAD run' \
    ti_equals "$(grep -c '^holder=test-integrity.sh$' "$TI_STUB_PROBE")" 2
  ti_assert 'prove: a second heavy job waits while the prover holds the lock' \
    ti_equals "$(grep -c '^second-heavy-job=6$' "$TI_STUB_PROBE")" 2
  ti_assert 'prove: the lock is free once the prover ends' ti_not test -d "$AI_OLYMPUS_LOCK_DIR/ai-olympus-gate.lock"
  ti_assert 'prove: the record is written under the evidence directory' ti_contains "$record" '/.claude/run/gates/regression/'

  ti_run bash "$TI_SELF" verify --record "$record" --base "$(ti_git rev-parse origin/master)" --head "$(ti_git rev-parse HEAD)"
  ti_assert 'verify: a proven record is accepted' ti_equals "$TI_RC" 0
  ti_assert 'verify: it prints the base failure message for the reviewer' ti_contains "$TI_OUT" 'expected fixed, got broken'
  ti_assert 'verify: the last line is the verdict' ti_equals "$(tail -n 1 <<<"$TI_OUT")" 'verify-verdict=proven'

  ti_assert 'prove: the prover never starts another wrapper of the machine lock' \
    ti_equals "$(grep -v '^[[:space:]]*#' "$TI_SELF" | grep -c 'run-''gate\|run-''validation\|run-fast''-checks')" 0

  : >"$TI_STUB_CALLS"
  TI_STUB_ALWAYS_PASS=1 ti_prove_calc
  record="$(ti_record_path)"
  ti_assert 'prove: a test that passes on the base is refuted' ti_equals "$TI_RC" 11
  ti_assert 'prove: a refuted run writes no proven record' ti_equals "$(ti_record_field "$record" .verdict)" refuted
  ti_assert 'prove: the tree equals HEAD after a refuted run' ti_clean_tree
  ti_run bash "$TI_SELF" verify --record "$record" --base "$(ti_git rev-parse origin/master)" --head "$(ti_git rev-parse HEAD)"
  ti_assert 'verify: a refuted record is not accepted' ti_equals "$TI_RC" 11

  jq '.verdict = "proven" | .tests[0].base_status = "failed" | .tests[0].verdict = "proven"' "$record" >"$record.edit"
  mv "$record.edit" "$record"
  ti_run bash "$TI_SELF" verify --record "$record" --base "$(ti_git rev-parse origin/master)" --head "$(ti_git rev-parse HEAD)"
  ti_assert 'verify: a record that claims a base failure its base report does not show is malformed' ti_equals "$TI_RC" 13

  TI_STUB_NO_JUNIT=1 ti_prove_calc
  ti_assert 'prove: a runner that writes no report ends incomplete' ti_equals "$TI_RC" 4
  ti_assert 'prove: the tree equals HEAD after a runner error' ti_clean_tree

  TI_STUB_KILL=1 ti_prove_calc
  ti_assert 'prove: a TERM during the base run stops the prover' ti_equals "$TI_RC" 143
  ti_assert 'prove: the tree equals HEAD after a TERM' ti_clean_tree
  ti_assert 'prove: the lock is free after a TERM' ti_not test -d "$AI_OLYMPUS_LOCK_DIR/ai-olympus-gate.lock"
}

ti_selftest_prove_refusals() {
  local calls
  calls="$(ti_stub_calls)"
  printf 'changed\n' >>"$TI_REPO/src/calc.txt"
  ti_prove_calc
  ti_assert 'prove: a changed tracked file is refused' ti_equals "$TI_RC" 3
  ti_assert 'prove: the refused tree keeps its change' ti_equals "$(tail -n 1 "$TI_REPO/src/calc.txt")" changed
  ti_git checkout -q -- src/calc.txt
  printf 'new\n' >"$TI_REPO/src/untracked.txt"
  ti_prove_calc
  ti_assert 'prove: an untracked file is refused' ti_equals "$TI_RC" 3
  ti_assert 'prove: the refused tree keeps the untracked file' test -f "$TI_REPO/src/untracked.txt"
  rm -f "$TI_REPO/src/untracked.txt"

  ti_run bash "$TI_SELF" prove --command 'vendor/bin/pest;id' --test 'tests/CalcTest.php::it adds'
  ti_assert 'prove: a command with ; is refused' ti_equals "$TI_RC" 3
  # shellcheck disable=SC2016
  ti_run bash "$TI_SELF" prove --command 'vendor/bin/pest $(id)' --test 'tests/CalcTest.php::it adds'
  # shellcheck disable=SC2016
  ti_assert 'prove: a command with $( is refused' ti_equals "$TI_RC" 3
  # shellcheck disable=SC2016
  ti_run bash "$TI_SELF" prove --command 'vendor/bin/pest `id`' --test 'tests/CalcTest.php::it adds'
  ti_assert 'prove: a command with a backtick is refused' ti_equals "$TI_RC" 3
  ti_run bash "$TI_SELF" prove --command 'vendor/bin/pest --log-junit other.xml' --test 'tests/CalcTest.php::it adds'
  ti_assert 'prove: a command that sets --log-junit is refused' ti_equals "$TI_RC" 3
  ti_run bash "$TI_SELF" prove --command 'vendor/bin/phpstan analyse' --test 'tests/CalcTest.php::it adds'
  ti_assert 'prove: a runner other than pest or phpunit is unprovable' ti_equals "$TI_RC" 4
  ti_assert 'prove: the unprovable runner is named' ti_contains "$TI_OUT" 'unsupported runner'

  ti_run bash "$TI_SELF" prove --command 'vendor/bin/pest' --test 'src/calc.txt::it adds'
  ti_assert 'prove: a production file as the test is refused' ti_equals "$TI_RC" 3
  ti_run bash "$TI_SELF" prove --command 'vendor/bin/pest' --test 'tests/OtherTest.php::it adds'
  ti_assert 'prove: a test the branch does not change is refused' ti_equals "$TI_RC" 3
  ti_run bash "$TI_SELF" prove --command 'vendor/bin/pest' --test 'tests/CalcTest.php'
  ti_assert 'prove: a test without a name is refused' ti_equals "$TI_RC" 3
  ti_assert 'prove: no refused or unprovable call ran the runner' ti_equals "$(ti_stub_calls)" "$calls"
  ti_assert 'prove: the tree is untouched by every refusal' ti_clean_tree

  ti_git checkout -q -b ignored fix
  printf '/src/legacy.txt\n/src/gone\n' >>"$TI_REPO/.gitignore"
  ti_git commit -q -am ignore
  printf 'mine\n' >"$TI_REPO/src/legacy.txt"
  ti_prove_calc
  ti_assert 'prove: an ignored file at a path the branch deleted is refused' ti_equals "$TI_RC" 3
  ti_assert 'prove: the refused run keeps the ignored file' ti_equals "$(cat "$TI_REPO/src/legacy.txt")" mine
  rm -f "$TI_REPO/src/legacy.txt"
  rm -rf "$TI_REPO/src/gone"
  printf 'mine\n' >"$TI_REPO/src/gone"
  ti_prove_calc
  ti_assert 'prove: an ignored file at a parent of a path the branch deleted is refused' ti_equals "$TI_RC" 3
  ti_assert 'prove: the refused run keeps the ignored parent file' ti_equals "$(cat "$TI_REPO/src/gone")" mine
  rm -f "$TI_REPO/src/gone"
  ti_assert 'prove: no run with an ignored file at a deleted path ran the runner' ti_equals "$(ti_stub_calls)" "$calls"
  ti_git checkout -q fix

  ti_git checkout -q -b deps fix
  printf '{"require": {}}\n' >"$TI_REPO/composer.json"
  ti_git commit -q -am deps
  ti_prove_calc
  ti_assert 'prove: a dependency change is unprovable' ti_equals "$TI_RC" 4
  ti_assert 'prove: the dependency change is named' ti_contains "$TI_OUT" 'dependency change'
  ti_assert 'prove: no swap ran for a dependency change' ti_equals "$(ti_stub_calls)" "$calls"
  ti_git checkout -q -b tests-only origin/master
  printf 'it adds\nmore\n' >"$TI_REPO/tests/CalcTest.php"
  ti_git commit -q -am tests-only
  ti_prove_calc
  ti_assert 'prove: a branch that changes test-side paths only is unprovable' ti_equals "$TI_RC" 4
  ti_git checkout -q fix
}

ti_selftest_verify() {
  local record master head key dir
  ti_prove_calc
  record="$(ti_record_path)"
  master="$(ti_git rev-parse origin/master)"
  head="$(ti_git rev-parse HEAD)"
  key="$(basename "$record" .json)"
  dir="$(dirname "$record")"

  ti_run bash "$TI_SELF" verify --record "$TI_ROOT/none/regression/$key.json" --base "$master" --head "$head"
  ti_assert 'verify: a record that does not exist is missing' ti_equals "$TI_RC" 10
  ln -s "$TI_REPO/.claude/run/gates" "$TI_ROOT/alias"
  ti_run bash "$TI_SELF" verify --record "$TI_ROOT/alias/regression/$key.json" --base "$master" --head "$head"
  ti_assert 'verify: a symbolic link in a component of the path is refused' ti_equals "$TI_RC" 3
  mkdir -p "$TI_ROOT/dirs/regression/$key.json"
  ti_run bash "$TI_SELF" verify --record "$TI_ROOT/dirs/regression/$key.json" --base "$master" --head "$head"
  ti_assert 'verify: a record that is not a regular file is refused' ti_equals "$TI_RC" 3
  mkdir -p "$TI_ROOT/copy/regression"
  local zeros=0000000000000000000000000000000000000000000000000000000000000000 suffix
  for suffix in json base.xml head.xml log; do
    cp "$dir/$key.$suffix" "$TI_ROOT/copy/regression/$zeros.$suffix"
  done
  ti_run bash "$TI_SELF" verify --record "$TI_ROOT/copy/regression/$zeros.json" --base "$master" --head "$head"
  ti_assert 'verify: a file name other than the key of its tests is malformed' ti_equals "$TI_RC" 13
  local spaced="$TI_ROOT/a dir ž/regression"
  mkdir -p "$spaced"
  for suffix in json base.xml head.xml log; do
    cp "$dir/$key.$suffix" "$spaced/$key.$suffix"
  done
  ti_run bash "$TI_SELF" verify --record "$spaced/$key.json" --base "$master" --head "$head"
  ti_assert 'verify: a record below a path with a space and a non-ASCII letter is accepted' ti_equals "$TI_RC" 0
  ti_run bash "$TI_SELF" verify --record "$TI_ROOT/a"$'\t'"dir/regression/$key.json" --base "$master" --head "$head"
  ti_assert 'verify: a record path with a control character is refused' ti_equals "$TI_RC" 3
  printf '<testsuites/>\n' >>"$TI_ROOT/copy/regression/$zeros.base.xml"
  cp "$TI_ROOT/copy/regression/$zeros.base.xml" "$TI_ROOT/tampered.xml"
  cp "$dir/$key.base.xml" "$TI_ROOT/saved.xml"
  cp "$TI_ROOT/tampered.xml" "$dir/$key.base.xml"
  ti_run bash "$TI_SELF" verify --record "$record" --base "$master" --head "$head"
  ti_assert 'verify: a JUnit report that does not match its hash is malformed' ti_equals "$TI_RC" 13
  cp "$TI_ROOT/saved.xml" "$dir/$key.base.xml"

  ti_git checkout -q master 2>/dev/null || ti_git checkout -q -b master origin/master
  printf 'other\n' >"$TI_REPO/src/other.txt"
  ti_git add -A
  ti_git commit -q -m other
  ti_git update-ref refs/remotes/origin/master HEAD
  ti_git checkout -q fix
  ti_git rebase -q origin/master
  master="$(ti_git rev-parse origin/master)"
  head="$(ti_git rev-parse HEAD)"
  ti_run bash "$TI_SELF" verify --record "$record" --base "$master" --head "$head"
  ti_assert 'verify: a rebase that leaves the named tests unchanged keeps the record' ti_equals "$TI_RC" 0

  local orphan
  orphan="$(ti_git commit-tree "$(ti_git rev-parse 'HEAD^{tree}')" -m orphan)"
  jq --arg o "$orphan" '.base = $o' "$record" >"$record.edit"
  cp "$record" "$TI_ROOT/record.saved"
  mv "$record.edit" "$record"
  ti_run bash "$TI_SELF" verify --record "$record" --base "$master" --head "$head"
  ti_assert 'verify: a recorded base that is not an ancestor of the merge base is stale' ti_equals "$TI_RC" 12
  cp "$TI_ROOT/record.saved" "$record"

  printf 'it adds\nit adds again\nand again\n' >"$TI_REPO/tests/CalcTest.php"
  ti_git commit -q -am 'change the test'
  ti_run bash "$TI_SELF" verify --record "$record" --base "$master" --head "$(ti_git rev-parse HEAD)"
  ti_assert 'verify: a test changed after the proof is stale' ti_equals "$TI_RC" 12
}

ti_scan_has() {
  grep -qxF "$(printf 'candidate\t%s\t%s\t%s' "$1" "$2" "$3")" <<<"$TI_OUT"
}

ti_scan_mentions() {
  grep -qF "$1" <<<"$TI_OUT"
}

# The scan fixture: a default branch with tests, a test configuration, two workflows, Composer scripts, and a
# PHPStan level, and a branch `weak` that lowers every one of them.
ti_selftest_scan_repo() {
  TI_REPO="$1"
  mkdir -p "$TI_REPO/tests/Unit" "$TI_REPO/.github/workflows" "$TI_REPO/src"
  printf '%s\n' "<?php" "it('adds', function () {" "    expect(1)->toBe(1);" "});" "test('subtracts', function () {" \
    "    expect(2)->toBe(2);" "});" "it('multiplies', function () {" "    expect(3)->toBe(3);" "});" >"$TI_REPO/tests/Unit/CalcTest.php"
  printf '%s\n' "<?php" "it('one', fn () => expect(1)->toBe(1));" "it('two', fn () => expect(1)->toBe(1));" \
    "it('three', fn () => expect(1)->toBe(1));" >"$TI_REPO/tests/Unit/SkipTest.php"
  printf '%s\n' "<?php" "it('escapes', function () {" "    expect(\"$(printf '\033')]0;pwn$(printf '\007')\")->toBe(1);" "});" >"$TI_REPO/tests/Unit/EscTest.php"
  printf 'gone\n' >"$TI_REPO/tests/Unit/GoneTest.php"
  printf 'moved\n' >"$TI_REPO/tests/Unit/MovedTest.php"
  printf 'out\n' >"$TI_REPO/tests/Unit/OutTest.php"
  printf '%s\n' '<phpunit>' '  <testsuites><testsuite name="all"><directory>tests</directory></testsuite></testsuites>' '</phpunit>' >"$TI_REPO/phpunit.xml"
  printf '%s\n' 'name: ci' 'on: push' 'jobs:' '  test:' '    runs-on: ubuntu-latest' '    steps:' '      - run: vendor/bin/pest --min=100' \
    '      - run: composer audit' >"$TI_REPO/.github/workflows/ci.yml"
  printf 'name: old\n' >"$TI_REPO/.github/workflows/old.yml"
  printf '%s\n' '{"scripts": {"test": ["vendor/bin/pest", "vendor/bin/phpstan"], "lint": "vendor/bin/pint"}}' >"$TI_REPO/composer.json"
  printf '%s\n' 'parameters:' '    level: 8' >"$TI_REPO/phpstan.neon"
  printf 'code\n' >"$TI_REPO/src/Calc.php"
  git -C "$TI_REPO" init -q
  ti_git add -A
  ti_git commit -q -m base
  ti_git branch -q -M master

  ti_git checkout -q -b weak
  printf '%s\n' "<?php" "it('adds', function () {" "    expect(true)->toBeTrue();" "});" "it('multiplies', function () {" \
    "    expect(3)->toBe(3);" "})->skip();" >"$TI_REPO/tests/Unit/CalcTest.php"
  printf '%s\n' "<?php" "it('one', fn () => expect(1)->toBe(1))-> skip ('later');" "it('two', fn () => expect(1)->toBe(1))->Skip();" \
    "it('three', fn () => expect(1)->toBe(1))->group('x')->skip();" >"$TI_REPO/tests/Unit/SkipTest.php"
  printf '%s\n' "<?php" "it('escapes', function () {" "});" >"$TI_REPO/tests/Unit/EscTest.php"
  ti_git rm -q tests/Unit/GoneTest.php
  mkdir -p "$TI_REPO/tests/Feature"
  ti_git mv tests/Unit/MovedTest.php tests/Feature/MovedTest.php
  ti_git mv tests/Unit/OutTest.php src/Out.php
  printf '%s\n' "<?php" "it('is new', function () {" "    expect(1)->toBe(1);" "})->skip();" >"$TI_REPO/tests/Unit/NewTest.php"
  printf '%s\n' '<phpunit>' '  <testsuites><testsuite name="all"><directory>tests</directory></testsuite></testsuites>' \
    '  <exclude>tests/Slow</exclude>' '</phpunit>' >"$TI_REPO/phpunit.xml"
  printf '%s\n' 'name: ci' 'on: push' 'jobs:' '  test:' '    runs-on: ubuntu-latest' '    steps:' '      - run: vendor/bin/pest --min=80' \
    '        continue-on-error: true' '      - run: composer audit' >"$TI_REPO/.github/workflows/ci.yml"
  ti_git rm -q .github/workflows/old.yml
  printf '%s\n' '{"scripts": {"test": ["vendor/bin/pest"], "lint": "vendor/bin/pint"}}' >"$TI_REPO/composer.json"
  printf '%s\n' 'parameters:' '    level: 5' >"$TI_REPO/phpstan.neon"
  ti_git add -A
  ti_git commit -q -m weak

  ti_git checkout -q -b clean master
  printf 'better code\n' >"$TI_REPO/src/Calc.php"
  printf '%s\n' "<?php" "it('is added by the change', function () {" "    expect(1)->toBe(1);" "})->skip();" >"$TI_REPO/tests/Unit/AddedTest.php"
  ti_git add -A
  ti_git commit -q -m clean

  ti_git checkout -q -b extended master
  printf '%s\n' '{"scripts": {"test": ["vendor/bin/pest --compact", "vendor/bin/phpstan"], "lint": "vendor/bin/pint src"}}' >"$TI_REPO/composer.json"
  ti_git commit -q -am extended

  ti_git checkout -q -b binary master
  printf 'one\000two\n' >"$TI_REPO/tests/Unit/fixture.bin"
  ti_git add -A
  ti_git commit -q -m binary-base
  printf 'three\000four\n' >"$TI_REPO/tests/Unit/fixture.bin"
  ti_git commit -q -am binary

  ti_git checkout -q -b control master
  printf 'x\n' >"$TI_REPO/tests/Unit/bad$(printf '\t')name.php"
  ti_git add -A
  ti_git commit -q -m control
}

ti_scan_run() {
  ti_run bash "$TI_SELF" scan --base "$(ti_git rev-parse "$1")" --head "$(ti_git rev-parse "$2")"
}

ti_selftest_scan() {
  ti_selftest_scan_repo "$TI_ROOT/scan"
  ti_scan_run master weak
  ti_assert 'scan: a weakening branch completes with candidates' ti_equals "$TI_RC" 5
  ti_assert 'scan: the last line is the complete verdict' ti_equals "$(tail -n 1 <<<"$TI_OUT")" 'scan-verdict=complete'
  ti_assert 'scan: a deleted test file is a candidate' ti_scan_has test-file-removed tests/Unit/GoneTest.php -
  ti_assert 'scan: a test file moved out of the test side is a candidate' ti_scan_has test-file-removed tests/Unit/OutTest.php -
  ti_assert 'scan: a removed test declaration is a candidate' ti_scan_has test-case-removed tests/Unit/CalcTest.php 5
  ti_assert 'scan: a removed assertion in a pre-existing test is a candidate' ti_scan_has assertion-changed tests/Unit/CalcTest.php 3
  ti_assert 'scan: an added skip is a candidate' ti_scan_has test-skipped tests/Unit/CalcTest.php 7
  ti_assert 'scan: a skip written with spaces is a candidate' ti_scan_has test-skipped tests/Unit/SkipTest.php 2
  ti_assert 'scan: a skip written in another case is a candidate' ti_scan_has test-skipped tests/Unit/SkipTest.php 3
  ti_assert 'scan: a skip at the end of a chain is a candidate' ti_scan_has test-skipped tests/Unit/SkipTest.php 4
  ti_assert 'scan: a declaration extended with a skip is not a removed test or a removed assertion' \
    ti_not grep -q $'test-case-removed\ttests/Unit/SkipTest.php\|assertion-changed\ttests/Unit/SkipTest.php' <<<"$TI_OUT"
  ti_assert 'scan: an added exclude in the test configuration is a candidate' ti_scan_has test-config-changed phpunit.xml 3
  ti_assert 'scan: a deleted workflow is a candidate' ti_scan_has ci-changed .github/workflows/old.yml -
  ti_assert 'scan: a removed workflow line is a candidate' ti_scan_has ci-changed .github/workflows/ci.yml 7
  ti_assert 'scan: an added continue-on-error is a candidate' ti_scan_has ci-changed .github/workflows/ci.yml 8
  ti_assert 'scan: a lowered coverage minimum is a candidate' ti_scan_has threshold-lowered .github/workflows/ci.yml 7
  ti_assert 'scan: a removed Composer script entry is a candidate' ti_scan_has script-removed composer.json -
  ti_assert 'scan: a lowered PHPStan level is a candidate' ti_scan_has analysis-level-lowered phpstan.neon 2
  ti_assert 'scan: a test file moved inside the test side unchanged is no candidate' ti_not ti_scan_mentions MovedTest.php
  ti_assert 'scan: a test the change adds is no candidate' ti_not ti_scan_mentions NewTest.php
  ti_assert 'scan: the output carries no text of a diff line' ti_not ti_scan_mentions 'pwn'
  ti_assert 'scan: the output carries no escape character' ti_not ti_scan_mentions "$(printf '\033')"
  ti_assert 'scan: a removed assertion that carries an escape sequence is still a candidate' \
    ti_scan_has assertion-changed tests/Unit/EscTest.php 3

  ti_scan_run master clean
  ti_assert 'scan: a branch that weakens nothing completes with no candidate' ti_equals "$TI_RC" 0
  ti_assert 'scan: a clean branch prints the complete verdict only' ti_equals "$TI_OUT" 'scan-verdict=complete'

  ti_scan_run master extended
  ti_assert 'scan: a Composer script entry that keeps every word and adds one is no candidate' ti_equals "$TI_OUT" 'scan-verdict=complete'

  ti_scan_run 'binary~1' binary
  ti_assert 'scan: a binary diff of a test-side path is incomplete' ti_equals "$TI_RC" 7
  ti_assert 'scan: the binary verdict names the reason' ti_contains "$TI_OUT" 'scan-verdict=incomplete reason=binary-diff'

  ti_scan_run master control
  ti_assert 'scan: a path with a control character is incomplete' ti_equals "$(tail -n 1 <<<"$TI_OUT")" 'scan-verdict=incomplete reason=unsafe-path'

  mkdir -p "$TI_ROOT/shim"
  # shellcheck disable=SC2016
  printf '#!/usr/bin/env bash\nfor a in "$@"; do [[ "$a" == --name-status ]] && exit 128; done\nexec %s "$@"\n' "$(command -v git)" >"$TI_ROOT/shim/git"
  chmod +x "$TI_ROOT/shim/git"
  TI_RC=0
  TI_OUT="$(cd "$TI_REPO" && PATH="$TI_ROOT/shim:$PATH" bash "$TI_SELF" scan --base "$(ti_git rev-parse master)" --head "$(ti_git rev-parse weak)" 2>&1)" || TI_RC=$?
  ti_assert 'scan: a git error is incomplete, never clean' ti_equals "$(tail -n 1 <<<"$TI_OUT")" 'scan-verdict=incomplete reason=git-error'
  rm -f "$TI_ROOT/shim/git"
  printf '#!/usr/bin/env bash\nexit 5\n' >"$TI_ROOT/shim/jq"
  chmod +x "$TI_ROOT/shim/jq"
  TI_RC=0
  TI_OUT="$(cd "$TI_REPO" && PATH="$TI_ROOT/shim:$PATH" bash "$TI_SELF" scan --base "$(ti_git rev-parse master)" --head "$(ti_git rev-parse weak)" 2>&1)" || TI_RC=$?
  ti_assert 'scan: a jq error is incomplete, never clean' ti_equals "$(tail -n 1 <<<"$TI_OUT")" 'scan-verdict=incomplete reason=jq-error'

  ti_run bash "$TI_SELF" scan --base master --head weak
  ti_assert 'scan: a ref name instead of a commit id is a usage error that ends incomplete' ti_equals "$TI_RC" 1
}

TI_ROOT=''

ti_selftest() {
  ti_selftest_classifier

  TI_ROOT="$(mktemp -d)" || return 1
  TI_ROOT="$(cd "$TI_ROOT" && pwd -P)"
  # shellcheck disable=SC2064
  trap "rm -rf '$TI_ROOT'" EXIT
  export AI_OLYMPUS_LOCK_DIR="$TI_ROOT/lock" TI_LIB_DIR="$TI_DIR" TI_STUB_CALLS="$TI_ROOT/calls" TI_STUB_PROBE="$TI_ROOT/probe"
  export AI_OLYMPUS_GATE_LOCK_TIMEOUT=60 AI_OLYMPUS_GATE_HEARTBEAT_INTERVAL=30
  unset AI_OLYMPUS_LOCK_HELD_BY
  ti_selftest_repo "$TI_ROOT/repo"
  ti_selftest_prove
  unset TI_STUB_PROBE
  ti_selftest_prove_refusals
  ti_selftest_verify
  ti_selftest_scan

  if [[ "$TI_FAILED" -ne 0 ]]; then
    echo 'test-integrity.sh: self-test failed' >&2
    return 1
  fi
  echo 'test-integrity.sh: self-test passed'
}

case "${1:-}" in
prove) shift; ti_prove "$@" ;;
verify) shift; ti_verify "$@" ;;
scan) shift; ti_scan "$@" ;;
--self-test) ti_selftest ;;
*) ti_usage ;;
esac
