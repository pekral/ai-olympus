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
#   test-integrity.sh scan --base <commit id> --head <commit id> [--without-review]
#   test-integrity.sh --self-test
#
# prove — the implementer runs it in its own worktree, after it committed the fix and the test
#   1. Refuses (exit 3, the tree untouched) a working tree with any change or untracked file, a command that
#      `validate_command` of project-commands.sh refuses or that sets `--log-junit` itself, and a test that is not
#      a regular test-side file the branch changed since its merge base with the default branch. It also refuses
#      an ignored file at a production path the branch deleted, or at a parent of that path, because the swap
#      would write the base file over it and the restore would delete it, and a production path HEAD has that
#      carries an assume-unchanged or skip-worktree flag or whose bytes differ from the bytes the restore writes
#      (`git cat-file --filters HEAD:<path>`, so a clean filter cannot hide an edit), because git status does not
#      show that edit and the swap would replace it.
#   2. Ends unprovable (exit 4, no swap) when the runner is not `vendor/bin/pest` or `vendor/bin/phpunit`, when the
#      branch changes a dependency manifest or lockfile, or when it changes test-side paths only.
#   3. Holds the machine lock (gate_lock_acquire) for the swap and both runs. It never starts another wrapper of
#      the lock, and a command it runs inherits the lock instead of waiting for it. Once it holds the lock it runs
#      the working-tree checks of step 1 again, so a change made during the wait is refused too.
#   4. Puts every production path the branch changed back to the merge base (`git restore --source=<base>
#      --worktree`, the index untouched), runs the named tests with `--log-junit`, restores HEAD on those paths
#      only, and runs them again. A trap restores HEAD on every exit, a signal included; a tree that does not
#      return to HEAD exits 5.
#      Residual risk: SIGKILL cannot be trapped. A prover killed during the swap leaves the production paths at
#      the base with no message. Every change is committed, so `git status` lists them, the next prove refuses
#      the dirty tree, and restoring each tracked path git status lists from HEAD (`git restore --source=HEAD
#      --worktree -- <path>`) plus removing each untracked file it lists brings the tree back. A whole-tree restore
#      would also replace an assume-unchanged or skip-worktree edit that git status does not list.
#      Residual risk: a write to a swapped path during the base run, by an editor or the operator, is replaced by
#      the restore with no check.
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
#   places a reviewer reads: a removed or moved-out test file (a rename that drops the runner suffix included),
#   a removed test declaration, an added skip, a
#   removed assertion line of a test the base already had, a changed test configuration, a removed or loosened
#   CI step, a removed or loosened Composer script, a lowered `--min=`, and a lowered PHPStan level. A test the change adds
#   is never a candidate, and neither is a test moved inside the test side unchanged. A binary diff on a watched
#   path, a path with a control character, and a git or jq error end the scan incomplete. The list is candidates,
#   never a proof that nothing else was weakened: an early return before the assertions or a removed data-set
#   row is no candidate.
#   --without-review is the mode of a merge path that has no review. It decides on the shape of the change, not
#   on the patterns or the content: it adds one `check-path-changed` candidate for each check path the diff adds,
#   changes, deletes, renames (by the old and by the new path), or copies — a test-side file, a workflow or
#   `.github/actions/**` file, `phpunit.xml*`, `tests/Pest.php`, a `.neon` file or a file whose name names
#   PHPStan (TI_CHECK_CONFIG_RE), a pytest, Jest, Vitest, RSpec, or CTest configuration, a `CMakeLists.txt`, and
#   a pip requirements or constraints file or a Conan `conanfile.txt` (TI_RUNNER_CONFIG_RE, TOOL_LIST_RE), a checker
#   configuration or an `.editorconfig`, `.gitignore`, or `.gitattributes` at any depth (FORMAT_RE) — and for `composer.json` when a script of the base changes. An added test runs code
#   inside the runner, so it is a candidate too. Only a change that touches no check path completes with no
#   candidate, so a change the classifier grades `tests-only` or `formatting-metadata-only` always has one, and
#   neither a file a runner collects by default (RUNNER_COLLECTED_RE) nor a list of tests or of dependencies
#   (TOOL_LIST_RE) is ever graded `documentation-only`. A directory alone never makes a file documentation to the
#   classifier (DOC_RE), so a configuration the scan does not name, such as a Sphinx `conf.py` or a Playwright
#   configuration under `docs/`, is a material file and never FAST alone.
#   Limit: the scan knows the default collection patterns only, so a file that only a pattern the project sets
#   itself collects (a custom doctest glob) is no candidate. That pattern lives in a runner configuration, which
#   is a check path, so the change that sets it takes the review. CTest has no collection pattern: its tests are
#   the `add_test()` calls of a `CMakeLists.txt`, and a dependency list names the checker plugins, so each of
#   them is a check path itself. Gauge is not covered: it collects the `.md` specifications of `specs/` by
#   default, the classifier grades them as documentation, and the scan lists none of them.
#
# Test-side paths
#   One definition: TEST_RE of `domain-vocabulary.sh`, matched on the lower-cased path, which `classify-risk.sh`
#   reads for its `tests-only` signal. It covers a `test/`, `tests/`, or `spec/` directory at any depth, a
#   `*Test.php` file, a `.test.*`, `.spec.*`, or `_test.*` file, a `test_*.py` file, and the PHPUnit and Pest
#   configuration, a pytest doctest file (`test*.txt`) and `conftest.py`, a Jest `__tests__` directory, and a Go
#   `testdata` directory. Every subcommand classifies a path with it, so the scan never sees fewer tests than the
#   classifier counts. The trusted copy reads `domain-vocabulary.sh` beside this script.
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
#           `test TAB <verdict> TAB base=<status> TAB head=<status> TAB <file>::<name>` line per test; `blocked` adds
#           one `blocked-path TAB <restore|remove> TAB <path>` line per path git status lists
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
# shellcheck source=domain-vocabulary.sh
. "$TI_DIR/domain-vocabulary.sh"

TI_DEPENDENCY_RE='(^|/)(composer\.json|composer\.lock|package\.json|package-lock\.json|npm-shrinkwrap\.json|yarn\.lock|pnpm-lock\.yaml)$'
TI_OID_RE='^[0-9a-f]{40}([0-9a-f]{24})?$'
TI_NAME_LIMIT=500

TI_TMP=''
TI_SWAP_ACTIVE=0
TI_SWAP_CREATED=()
TI_SWAP_RESTORE=()

ti_usage() {
  cat >&2 <<'USAGE'
Usage: test-integrity.sh prove --command "<runner> [<option>...]" --test "<file>::<test name>" [--test ...] [--actor <name>]
       test-integrity.sh verify --record <absolute path> --base <commit id> --head <commit id>
       test-integrity.sh scan --base <commit id> --head <commit id> [--without-review]
       test-integrity.sh --self-test
USAGE
  exit 1
}

ti_lower() {
  printf '%s' "$1" | LC_ALL=C tr '[:upper:]' '[:lower:]'
}

# A path the classifier counts as a test (TEST_RE of domain-vocabulary.sh).
ti_is_test_side() {
  [[ "$(ti_lower "$1")" =~ $TEST_RE ]]
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

# Bring the working tree back to HEAD after a swap: every swapped path HEAD has from HEAD, every path the base
# created and HEAD does not have removed, then a clean status. No other path is written, so a local edit git status
# does not show stays. Returns 1 when the tree did not return to HEAD.
ti_restore() {
  [[ "$TI_SWAP_ACTIVE" -eq 1 ]] || return 0
  TI_SWAP_ACTIVE=0
  local path failed=0 dirty
  if [[ "${#TI_SWAP_RESTORE[@]}" -gt 0 ]]; then
    GIT_LITERAL_PATHSPECS=1 git restore --source=HEAD --worktree -- "${TI_SWAP_RESTORE[@]}" >/dev/null 2>&1 || failed=1
  fi
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

ti_require_clean_status() {
  local dirty
  dirty="$(git status --porcelain=v1 --untracked-files=all 2>/dev/null)" || ti_prove_stop 3 refused 'git status failed'
  [[ -z "$dirty" ]] || ti_prove_stop 3 refused 'the working tree has a change or an untracked file; commit the fix and the test first'
}

# The bytes of a path in the working tree equal the bytes the restore writes for it from the commit $1: the blob
# with its smudge filter and line-ending conversion applied. A clean filter is never applied, so an edit it would
# drop from the blob still counts. A submodule is left to git status.
ti_same_as_commit() {
  local entry blob
  entry="$(GIT_LITERAL_PATHSPECS=1 git ls-tree "$1" -- "$2" 2>/dev/null)" || return 1
  [[ "$entry" =~ ^(100644|100755|120000|160000)\ (blob|commit)\ ([0-9a-f]{40}([0-9a-f]{24})?)$'\t' ]] || return 1
  blob="${BASH_REMATCH[3]}"
  case "${BASH_REMATCH[1]}" in
  160000) return 0 ;;
  120000) [[ -L "$2" && "$(readlink -- "$2")" == "$(git cat-file blob "$blob")" ]] ;;
  *)
    [[ -f "$2" && ! -L "$2" ]] || return 1
    git cat-file --filters "$1:$2" >"$TI_TMP/restored" 2>/dev/null || return 1
    cmp -s -- "$2" "$TI_TMP/restored"
    ;;
  esac
}

# The swap and the restore write only what they can give back: a clean status, every swapped path HEAD has in the
# index with no assume-unchanged or skip-worktree flag (git status shows no edit under either) and with the bytes
# the restore writes from HEAD, and nothing at a path the swap creates. prove runs it before the lock and again once it holds it.
ti_swap_ready() {
  local path entry
  ti_require_clean_status
  for path in ${TI_SWAP_RESTORE[@]+"${TI_SWAP_RESTORE[@]}"}; do
    entry="$(GIT_LITERAL_PATHSPECS=1 git ls-files -v -- "$path" 2>/dev/null)" || entry=''
    [[ "$entry" == 'H '* ]] || ti_prove_stop 3 refused "a production path carries an assume-unchanged or skip-worktree flag: $path"
    ti_same_as_commit "$1" "$path" || ti_prove_stop 3 refused "a production path differs from HEAD although git status shows no change: $path"
  done
  for path in ${TI_SWAP_CREATED[@]+"${TI_SWAP_CREATED[@]}"}; do
    gate_no_symlink "$path" || ti_prove_stop 3 refused "$GATE_REASON"
    ti_swap_target_free "$path" || ti_prove_stop 3 refused "an ignored file sits at a path the branch deleted, or at a parent of it: $path"
  done
}

# The blocked verdict names only the paths git status lists: a tracked one to restore from HEAD, an untracked one to
# remove. It never advises a whole-tree restore, which would replace an edit git status does not show.
ti_blocked_message() {
  local entry action
  printf 'regression-proof=blocked\nreason=the working tree did not return to HEAD; every change is committed, so run git restore --source=HEAD --worktree -- <path> for each restore path below and remove each remove path\n'
  while IFS= read -r -d '' entry; do
    action=restore
    [[ "${entry:0:2}" != '??' ]] || action=remove
    printf 'blocked-path\t%s\t%s\n' "$action" "$(safe_display "${entry:3}")"
  done < <(git status --porcelain=v1 -z --no-renames --untracked-files=all 2>/dev/null)
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
  ti_require_clean_status

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
    if git cat-file -e "$head:$path" 2>/dev/null; then
      TI_SWAP_RESTORE+=("$path")
    else
      TI_SWAP_CREATED+=("$path")
    fi
  done
  [[ "${#production[@]}" -gt 0 ]] || ti_prove_stop 4 unprovable 'no production change: the branch changes test-side paths only'
  ti_swap_ready "$head"

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
  ti_swap_ready "$head"
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
TI_LOOSEN_RE='continue-on-error:[[:space:]]*true|if:[[:space:]]*(false|\$\{\{[[:space:]]*false[[:space:]]*\}\})|\|\|[[:space:]]*(true|:|exit[[:space:]]+0)([^A-Za-z0-9]|$)|set[[:space:]]+\+e|paths-ignore|^[[:space:]]*paths:'
TI_RUNNER_NAME_RE='Test\.php$|\.spec\.ts$|\.test\.ts$'
TI_COMMENT_RE='^[[:space:]]*(//|#|/\*|\*)'
TI_MIN_RE='--min=([0-9]+)'
TI_LEVEL_RE='^[[:space:]]*level:[[:space:]]*([0-9]+|max)'
TI_CHECK_CONFIG_RE='\.neon(\.dist)?$|(^|/)[^/]*phpstan[^/]*$'
# The files where pytest, Jest, Vitest, and RSpec read a collection pattern a project sets itself, the CTest
# files, and the lists of tests or of dependencies of TOOL_LIST_RE. Matched on the lower-cased path.
TI_RUNNER_CONFIG_RE='(^|/)(pytest\.ini|tox\.ini|setup\.cfg|pyproject\.toml|package\.json|\.rspec)$|(^|/)(jest|vitest|vite)\.config\.[a-z.]+$|(^|/)vitest\.workspace\.[a-z.]+$|(^|/)ctestcustom\.cmake$|'"$TOOL_LIST_RE"

TI_SCAN_BASE=''
TI_SCAN_HEAD=''
TI_SCAN_CANDIDATES=0
TI_SCAN_WITHOUT_REVIEW=0
TI_SCAN_VERDICT=0

ti_scan_incomplete() {
  TI_SCAN_VERDICT=1
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
  elif [[ "$1" =~ (^|/)\.?phpstan(\.dist)?\.neon(\.dist)?$ ]]; then
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

  local -a added_norm=() added_code_norm=()
  for text in ${added[@]+"${added[@]}"}; do
    added_norm+=("$(ti_norm "$text")")
    [[ "$text" =~ $TI_COMMENT_RE ]] || added_code_norm+=("$(ti_norm "$text")")
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
        for value in ${added_code_norm[@]+"${added_code_norm[@]}"}; do
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

# A Composer script the head drops, an entry it drops from a script, and an entry it keeps but loosens, compared
# through jq. An entry is loosened when the head form gains a TI_LOOSEN_RE ending, or a `--level` / `-l` below the
# base one; a level the base entry left to the configuration counts as lowered unless the head sets `max`.
ti_scan_scripts() {
  local base_json head_json counts
  base_json="$(git show "$TI_SCAN_BASE:composer.json" 2>/dev/null)" || return 0
  head_json="$(git show "$TI_SCAN_HEAD:composer.json" 2>/dev/null)" || head_json='{}'
  counts="$(jq -nr --argjson b "$base_json" --argjson h "$head_json" --arg loosen "$TI_LOOSEN_RE" '
    def entries(v): if (v | type) == "array" then v elif v == null then [] else [v] end;
    def words(e): e | tostring | split(" ") | map(select(length > 0));
    def level(e): [e | tostring | scan("(?:--level=|--level[[:space:]]+|-l[[:space:]]+)([0-9]+|max)") | .[0]
      | if . == "max" then 10 else tonumber end] | last;
    def loosened($e; $n):
      (($n | tostring | test($loosen)) and (($e | tostring | test($loosen)) | not))
      or (level($n) as $after | level($e) as $before
        | $after != null and (($before == null and $after < 10) or ($before != null and $after < $before)));
    def state($e; $now):
      [entries($now)[] | select((words($e) - words(.)) == [])] as $keeping
      | if ($keeping | length) == 0 then "removed"
        elif any($keeping[]; loosened($e; .) | not) then "kept"
        else "loosened" end;
    ($b.scripts // {}) as $bs | ($h.scripts // {}) as $hs
    | [$bs | to_entries[] | .value as $was | $hs[.key] as $now
      | if $now == null then "removed" else entries($was)[] | state(.; $now) end] as $states
    | "\([$states[] | select(. == "removed")] | length) \([$states[] | select(. == "loosened")] | length)"' 2>/dev/null)" \
    || ti_scan_incomplete jq-error
  [[ "$counts" =~ ^([0-9]+)\ ([0-9]+)$ ]] || ti_scan_incomplete jq-error
  [[ "${BASH_REMATCH[1]}" -eq 0 ]] || ti_candidate script-removed composer.json -
  [[ "${BASH_REMATCH[2]}" -eq 0 ]] || ti_candidate script-loosened composer.json -
}

# A script of the base whose value the head changes or drops: `true` or `false`.
ti_scripts_changed() {
  local base_json head_json changed
  base_json="$(git show "$TI_SCAN_BASE:composer.json" 2>/dev/null)" || ti_scan_incomplete git-error
  head_json="$(git show "$TI_SCAN_HEAD:composer.json" 2>/dev/null)" || head_json='{}'
  changed="$(jq -n --argjson b "$base_json" --argjson h "$head_json" '
    ($b.scripts // {}) as $bs | ($h.scripts // {}) as $hs | any($bs | keys[]; $hs[.] != $bs[.])' 2>/dev/null)" \
    || ti_scan_incomplete jq-error
  case "$changed" in
  true) return 0 ;;
  false) return 1 ;;
  *) ti_scan_incomplete jq-error ;;
  esac
}

# A path whose change can switch a check off: a test-side file, a test, runner, or CI configuration, every
# PHPStan configuration, its includes and baseline included, and every checker configuration and editor or git
# metadata file of FORMAT_RE, at every depth.
ti_is_check_path() {
  case "$2" in
  test | test-config | ci | phpstan) return 0 ;;
  esac
  [[ "$1" =~ $TI_CHECK_CONFIG_RE || "$(ti_lower "$1")" =~ $TI_RUNNER_CONFIG_RE || "$(ti_lower "$1")" =~ $FORMAT_RE ]]
}

# --without-review: one check-path-changed candidate for each check path the diff adds, changes, deletes, renames
# (by the old and by the new path), or copies (by the copy), and for composer.json when a script of the base
# changes. It never reads the content, so no added file can look harmless.
ti_scan_shape() {
  local status="$1" old="$2" kind="$3" new="$4" new_kind="$5"
  if [[ "$status" != C* ]]; then
    if ti_is_check_path "$old" "$kind"; then
      ti_candidate check-path-changed "$old" -
    elif [[ "$kind" == composer && "$status" != A* ]] && ti_scripts_changed; then
      ti_candidate check-path-changed "$old" -
    fi
  fi
  if [[ "$new" != "$old" ]] && ti_is_check_path "$new" "$new_kind"; then
    ti_candidate check-path-changed "$new" -
  fi
}

ti_scan() {
  local status old new kind new_kind
  while [[ $# -gt 0 ]]; do
    case "$1" in
    --base) [[ $# -ge 2 ]] || ti_scan_incomplete usage 1; TI_SCAN_BASE="$2"; shift 2 ;;
    --head) [[ $# -ge 2 ]] || ti_scan_incomplete usage 1; TI_SCAN_HEAD="$2"; shift 2 ;;
    --without-review) TI_SCAN_WITHOUT_REVIEW=1; shift ;;
    *) ti_scan_incomplete usage 1 ;;
    esac
  done
  [[ "$TI_SCAN_BASE" =~ $TI_OID_RE && "$TI_SCAN_HEAD" =~ $TI_OID_RE ]] || ti_scan_incomplete usage 1
  command -v jq >/dev/null 2>&1 || ti_scan_incomplete missing-tool
  git cat-file -e "$TI_SCAN_BASE^{commit}" 2>/dev/null || ti_scan_incomplete unknown-commit
  git cat-file -e "$TI_SCAN_HEAD^{commit}" 2>/dev/null || ti_scan_incomplete unknown-commit
  TI_SCAN_BASE="$(git merge-base "$TI_SCAN_BASE" "$TI_SCAN_HEAD" 2>/dev/null)" || ti_scan_incomplete git-error
  ti_temp_dir || ti_scan_incomplete no-temporary-directory
  # A stop before the verdict (an unbound variable under set -u) ends incomplete, never with exit 0.
  trap 'rm -rf -- "$TI_TMP"; [[ "$TI_SCAN_VERDICT" -eq 1 ]] || { printf "scan-verdict=incomplete reason=internal-error\n"; exit 7; }' EXIT
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
      elif [[ "$kind" == test && "$old" =~ $TI_RUNNER_NAME_RE && ! "$new" =~ $TI_RUNNER_NAME_RE ]]; then
        ti_scan_deleted test "$old"
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
    if [[ "$TI_SCAN_WITHOUT_REVIEW" -eq 1 ]]; then
      ti_scan_shape "$status" "$old" "$kind" "$new" "$new_kind"
    fi
  done <"$TI_TMP/names"

  TI_SCAN_VERDICT=1
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
  local sample
  for sample in "${TI_SHARED_TEST_PATHS[@]}"; do
    ti_assert "classify: $sample is test-side, as the classifier counts it" ti_is_test_side "$sample"
  done
  ti_assert 'path: a path with a tab is unsafe' ti_not ti_safe_relative_path $'tests/a\tb.php'
  ti_assert 'path: a path with an escape character is unsafe' ti_not ti_safe_relative_path $'tests/a\033b.php'
  ti_assert 'path: a path that climbs out is unsafe' ti_not ti_safe_relative_path 'tests/../../etc/passwd'
  ti_assert 'path: an absolute path is unsafe' ti_not ti_safe_relative_path '/etc/passwd'
  ti_assert 'path: a plain relative path is safe' ti_safe_relative_path 'tests/Unit/CalcTest.php'
  local name
  for name in .phpstan.neon .phpstan.neon.dist .phpstan.dist.neon phpstan.dist.neon packages/app/phpstan.neon; do
    ti_assert "kind: $name is a PHPStan configuration" ti_equals "$(ti_watch_kind "$name")" phpstan
  done
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

ti_wait_for_file() {
  local tries=0
  until [[ -f "$1" ]] && grep -q -- "$2" "$1"; do
    tries=$((tries + 1))
    [[ "$tries" -le 300 ]] || return 1
    sleep 0.1
  done
}

ti_change_calc() {
  printf 'changed\n' >>"$TI_REPO/src/calc.txt"
}

ti_write_ignored_legacy() {
  printf 'mine\n' >"$TI_REPO/src/legacy.txt"
}

# Run prove while another holder keeps the machine lock, run the given change once prove waits for the lock, and
# release the lock: what prove decides after the wait is in TI_RC and TI_OUT.
ti_prove_while_locked() {
  local holder prover
  rm -f -- "$TI_ROOT/held" "$TI_ROOT/release" "$TI_ROOT/wait.out"
  # shellcheck disable=SC2016
  env -u AI_OLYMPUS_LOCK_HELD_BY bash -c '. "$1/gate-record.sh"; gate_lock_acquire 5 || exit 1; printf "held\n" >"$2/held"
    until [[ -f "$2/release" ]]; do sleep 0.1; done; gate_cleanup' _ "$TI_DIR" "$TI_ROOT" &
  holder=$!
  ti_wait_for_file "$TI_ROOT/held" held || { TI_RC=99; return; }
  (cd "$TI_REPO" && bash "$TI_SELF" prove --command 'vendor/bin/pest' --test 'tests/CalcTest.php::it adds' >"$TI_ROOT/wait.out" 2>&1) &
  prover=$!
  ti_wait_for_file "$TI_ROOT/wait.out" 'waiting for the machine lock' || TI_RC=98
  "$1"
  : >"$TI_ROOT/release"
  TI_RC=0
  wait "$prover" || TI_RC=$?
  wait "$holder" || true
  TI_OUT="$(cat "$TI_ROOT/wait.out")"
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
  ti_prove_while_locked ti_write_ignored_legacy
  ti_assert 'prove: an ignored file written at a deleted path during the lock wait is refused' ti_equals "$TI_RC" 3
  ti_assert 'prove: the ignored file written during the lock wait is kept' ti_equals "$(cat "$TI_REPO/src/legacy.txt")" mine
  rm -f "$TI_REPO/src/legacy.txt"
  ti_git checkout -q fix

  local flag
  for flag in assume-unchanged skip-worktree; do
    ti_git update-index "--$flag" src/calc.txt
    printf 'LOCAL\n' >"$TI_REPO/src/calc.txt"
    ti_prove_calc
    ti_assert "prove: a production path flagged $flag is refused" ti_equals "$TI_RC" 3
    ti_assert "prove: the refused run keeps the $flag edit" ti_equals "$(cat "$TI_REPO/src/calc.txt")" LOCAL
    ti_git update-index "--no-$flag" src/calc.txt
    ti_git checkout -q -- src/calc.txt
  done
  ti_git config core.trustctime false
  ti_git config core.checkStat minimal
  touch -t 202001010000 "$TI_REPO/src/calc.txt"
  ti_git update-index -q --refresh
  printf 'fixxd\n' >"$TI_REPO/src/calc.txt"
  touch -t 202001010000 "$TI_REPO/src/calc.txt"
  ti_prove_calc
  ti_assert 'prove: a production path whose bytes differ from HEAD under a clean status is refused' ti_equals "$TI_RC" 3
  ti_assert 'prove: the refused run keeps the edit git status does not show' ti_equals "$(cat "$TI_REPO/src/calc.txt")" fixxd
  printf 'fixed\n' >"$TI_REPO/src/calc.txt"
  ti_git config --unset core.trustctime
  ti_git config --unset core.checkStat
  ti_git update-index -q --really-refresh
  ti_assert 'prove: no run with a hidden edit of a production path ran the runner' ti_equals "$(ti_stub_calls)" "$calls"
  ti_git checkout -q -b filtered fix
  printf 'src/calc.txt filter=strip\n' >"$TI_REPO/.gitattributes"
  ti_git add .gitattributes
  ti_git commit -q -m filtered
  ti_git config filter.strip.clean "sed '/# local\$/d'"
  ti_git config filter.strip.smudge cat
  printf 'fixed\nsetting # local\n' >"$TI_REPO/src/calc.txt"
  ti_git add src/calc.txt
  ti_assert 'prove: a clean filter hides the local line from git status' ti_equals "$(ti_git status --porcelain=v1)" ''
  ti_prove_calc
  ti_assert 'prove: a production path whose local line a clean filter hides is refused' ti_equals "$TI_RC" 3
  ti_assert 'prove: the refused run keeps the line a clean filter hides' \
    ti_equals "$(cat "$TI_REPO/src/calc.txt")" "$(printf 'fixed\nsetting # local')"
  printf 'fixed\n' >"$TI_REPO/src/calc.txt"
  ti_git add src/calc.txt
  ti_git config --unset filter.strip.clean
  ti_git config --unset filter.strip.smudge
  ti_git checkout -q fix
  ti_assert 'prove: no run with a line a clean filter hides ran the runner' ti_equals "$(ti_stub_calls)" "$calls"
  ti_prove_while_locked ti_change_calc
  ti_assert 'prove: a change made during the lock wait is refused' ti_equals "$TI_RC" 3
  ti_assert 'prove: the change made during the lock wait is kept' ti_equals "$(tail -n 1 "$TI_REPO/src/calc.txt")" changed
  ti_git checkout -q -- src/calc.txt
  ti_assert 'prove: no run that saw a change after the lock wait ran the runner' ti_equals "$(ti_stub_calls)" "$calls"

  ti_git update-index --assume-unchanged composer.json
  printf 'LOCAL\n' >"$TI_REPO/composer.json"
  ti_prove_calc
  ti_assert 'prove: a flagged file outside the production paths does not stop the proof' ti_equals "$TI_RC" 0
  ti_assert 'prove: the restore keeps the edit of a flagged file outside the production paths' \
    ti_equals "$(cat "$TI_REPO/composer.json")" LOCAL
  ti_git update-index --no-assume-unchanged composer.json
  ti_git checkout -q -- composer.json
  calls="$(ti_stub_calls)"

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

# The blocked verdict lists each path git status shows and no whole-tree restore; following it keeps an
# assume-unchanged edit outside those paths.
ti_selftest_blocked_message() {
  local action path
  ti_git update-index --assume-unchanged composer.json
  printf 'LOCAL\n' >"$TI_REPO/composer.json"
  printf 'broken\n' >"$TI_REPO/src/calc.txt"
  printf 'base\n' >"$TI_REPO/src/legacy.txt"
  TI_OUT="$(cd "$TI_REPO" && ti_blocked_message)"
  ti_assert 'prove: the blocked message names a changed path to restore' ti_scan_mentions $'blocked-path\trestore\tsrc/calc.txt'
  ti_assert 'prove: the blocked message names an untracked path to remove' ti_scan_mentions $'blocked-path\tremove\tsrc/legacy.txt'
  ti_assert 'prove: the blocked message advises no whole-tree restore' ti_not ti_scan_mentions ':/'
  ti_assert 'prove: the blocked message never names a path git status does not list' ti_not ti_scan_mentions composer.json
  while IFS=$'\t' read -r _ action path; do
    if [[ "$action" == restore ]]; then
      ti_git restore --source=HEAD --worktree -- "$path"
    else
      rm -f -- "$TI_REPO/$path"
    fi
  done < <(grep '^blocked-path' <<<"$TI_OUT")
  ti_assert 'prove: following the blocked message keeps an assume-unchanged edit' ti_equals "$(cat "$TI_REPO/composer.json")" LOCAL
  ti_git update-index --no-assume-unchanged composer.json
  ti_git checkout -q -- composer.json
  ti_assert 'prove: following the blocked message brings the tree back to HEAD' ti_clean_tree
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
  printf 'suffix\n' >"$TI_REPO/tests/Unit/SuffixTest.php"
  printf '%s\n' "<?php" "it('notes', function () {" "    expect(4)->toBe(4);" "});" >"$TI_REPO/tests/Unit/CommentTest.php"
  printf '%s\n' '<phpunit>' '  <testsuites><testsuite name="all"><directory>tests</directory></testsuite></testsuites>' '</phpunit>' >"$TI_REPO/phpunit.xml"
  printf '%s\n' 'name: ci' 'on: push' 'jobs:' '  test:' '    runs-on: ubuntu-latest' '    steps:' '      - run: vendor/bin/pest --min=100' \
    '      - run: composer audit' >"$TI_REPO/.github/workflows/ci.yml"
  printf 'name: old\n' >"$TI_REPO/.github/workflows/old.yml"
  printf '%s\n' 'name: t' 'on:' '  pull_request:' 'jobs: {}' >"$TI_REPO/.github/workflows/trigger.yml"
  printf '%s\n' '{"scripts": {"test": ["vendor/bin/pest", "vendor/bin/phpstan"], "lint": "vendor/bin/pint"}}' >"$TI_REPO/composer.json"
  printf '%s\n' 'parameters:' '    level: 8' >"$TI_REPO/phpstan.neon"
  printf '%s\n' 'parameters:' '    level: 8' >"$TI_REPO/.phpstan.neon.dist"
  mkdir -p "$TI_REPO/packages/app"
  printf '%s\n' '<phpunit>' '  <testsuites><testsuite name="all"><directory>tests</directory></testsuite></testsuites>' '</phpunit>' \
    >"$TI_REPO/packages/app/phpunit.xml.dist"
  printf '%s\n' 'parameters:' '    level: 8' >"$TI_REPO/packages/app/phpstan.neon.dist"
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
  ti_git mv tests/Unit/SuffixTest.php tests/Unit/Suffix.php
  printf '%s\n' "<?php" "it('notes', function () {" "    // expect(4)->toBe(4);" "});" >"$TI_REPO/tests/Unit/CommentTest.php"
  printf '%s\n' 'name: t' 'on:' '  pull_request:' "    paths: ['docs/**']" 'jobs: {}' >"$TI_REPO/.github/workflows/trigger.yml"
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

  ti_git checkout -q -b quiet master
  printf '%s\n' "<?php" "it('adds', function () {" "    return;" "    expect(1)->toBe(1);" "});" "test('subtracts', function () {" \
    "    expect(2)->toBe(2);" "});" "it('multiplies', function () {" "    expect(3)->toBe(3);" "});" >"$TI_REPO/tests/Unit/CalcTest.php"
  mkdir -p "$TI_REPO/tests/Feature"
  ti_git mv tests/Unit/MovedTest.php tests/Feature/MovedTest.php
  printf '%s\n' '{"scripts": {"test": ["vendor/bin/pest", "vendor/bin/phpstan"], "lint": "vendor/bin/pint --test"}}' >"$TI_REPO/composer.json"
  printf '%s\n' 'name: ci' 'on: push' 'jobs:' '  test:' '    runs-on: ubuntu-latest' '    timeout-minutes: 5' '    steps:' \
    '      - run: vendor/bin/pest --min=100' '      - run: composer audit' >"$TI_REPO/.github/workflows/ci.yml"
  printf '%s\n' '<phpunit>' '  <testsuites><testsuite name="all"><directory>tests</directory></testsuite></testsuites>' \
    '  <php/>' '</phpunit>' >"$TI_REPO/phpunit.xml"
  printf '%s\n' "<?php" "it('is new', fn () => expect(1)->toBe(1));" >"$TI_REPO/tests/Unit/QuietNewTest.php"
  ti_git add -A
  ti_git commit -q -m quiet

  ti_git checkout -q -b dependency master
  printf '%s\n' '{"require": {"php": "^8.3"}, "scripts": {"test": ["vendor/bin/pest", "vendor/bin/phpstan"], "lint": "vendor/bin/pint"}}' >"$TI_REPO/composer.json"
  ti_git commit -q -am dependency

  ti_git checkout -q -b production master
  printf 'better code\n' >"$TI_REPO/src/Calc.php"
  ti_git commit -q -am production

  ti_git checkout -q -b added-checks master
  printf '%s\n' '<phpunit>' '  <testsuites><testsuite name="all"><directory>empty</directory></testsuite></testsuites>' '</phpunit>' \
    >"$TI_REPO/packages/app/phpunit.xml"
  printf '%s\n' 'parameters:' '    level: 0' >"$TI_REPO/packages/app/phpstan.neon"
  printf '%s\n' 'parameters:' '    level: 0' >"$TI_REPO/.phpstan.dist.neon"
  printf '%s\n' 'parameters:' '    ignoreErrors: []' >"$TI_REPO/phpstan-baseline.neon"
  printf '%s\n' '<?php' 'uses()->in(__DIR__);' >"$TI_REPO/tests/Pest.php"
  ti_git add -A
  ti_git commit -q -m added-checks

  ti_git checkout -q -b added-test master
  printf '%s\n' '<?php' 'register_shutdown_function(static fn () => exit(0));' "it('is new', fn () => expect(1)->toBe(1));" \
    >"$TI_REPO/tests/Unit/ZzTest.php"
  ti_git add -A
  ti_git commit -q -m added-test

  ti_git checkout -q -b renamed-in master
  ti_git mv src/Calc.php tests/bootstrap.php
  ti_git commit -q -m renamed-in

  ti_git checkout -q -b renamed-out master
  ti_git mv tests/Unit/OutTest.php src/Out.php
  ti_git commit -q -m renamed-out

  ti_git checkout -q -b dotted-level master
  printf '%s\n' 'parameters:' '    level: 0' >"$TI_REPO/.phpstan.neon.dist"
  ti_git commit -q -am dotted-level

  ti_git checkout -q -b loosened-or master
  printf '%s\n' '{"scripts": {"test": ["vendor/bin/pest || true", "vendor/bin/phpstan"], "lint": "vendor/bin/pint"}}' >"$TI_REPO/composer.json"
  ti_git commit -q -am loosened-or

  ti_git checkout -q -b loosened-level master
  printf '%s\n' '{"scripts": {"test": ["vendor/bin/pest", "vendor/bin/phpstan analyse --level=0"], "lint": "vendor/bin/pint"}}' >"$TI_REPO/composer.json"
  ti_git commit -q -am loosened-level

  ti_git checkout -q -b raised-level master
  printf '%s\n' '{"scripts": {"test": ["vendor/bin/pest", "vendor/bin/phpstan --level=max"], "lint": "vendor/bin/pint"}}' >"$TI_REPO/composer.json"
  ti_git commit -q -am raised-level

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

ti_scan_shape_run() {
  ti_run bash "$TI_SELF" scan --base "$(ti_git rev-parse "$1")" --head "$(ti_git rev-parse "$2")" --without-review
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
  ti_assert 'scan: a test file renamed without the runner suffix is a candidate' ti_scan_has test-file-removed tests/Unit/SuffixTest.php -
  ti_assert 'scan: an assertion that moved into a comment is a candidate' ti_scan_has assertion-changed tests/Unit/CommentTest.php 3
  ti_assert 'scan: an added paths filter on a workflow trigger is a candidate' ti_scan_has ci-changed .github/workflows/trigger.yml 4
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

  ti_scan_shape_run master quiet
  ti_assert 'scan --without-review: a change to pre-existing checks completes with candidates' ti_equals "$TI_RC" 5
  ti_assert 'scan --without-review: a changed pre-existing test is a candidate' ti_scan_has check-path-changed tests/Unit/CalcTest.php -
  ti_assert 'scan --without-review: a renamed test is a candidate by its old path' ti_scan_has check-path-changed tests/Unit/MovedTest.php -
  ti_assert 'scan --without-review: a renamed test is a candidate by its new path' ti_scan_has check-path-changed tests/Feature/MovedTest.php -
  ti_assert 'scan --without-review: a changed Composer script is a candidate' ti_scan_has check-path-changed composer.json -
  ti_assert 'scan --without-review: a changed workflow is a candidate' ti_scan_has check-path-changed .github/workflows/ci.yml -
  ti_assert 'scan --without-review: a changed test configuration is a candidate' ti_scan_has check-path-changed phpunit.xml -
  ti_assert 'scan --without-review: an added test is a candidate' ti_scan_has check-path-changed tests/Unit/QuietNewTest.php -
  ti_scan_shape_run master clean
  ti_assert 'scan --without-review: a change that adds a test beside production code is a candidate' \
    ti_equals "$TI_OUT" "$(printf 'candidate\tcheck-path-changed\ttests/Unit/AddedTest.php\t-\nscan-verdict=complete')"
  ti_scan_shape_run master production
  ti_assert 'scan --without-review: a change that touches no test-side or check path is no candidate' ti_equals "$TI_OUT" 'scan-verdict=complete'
  ti_scan_shape_run master dependency
  ti_assert 'scan --without-review: a composer.json change that keeps every script is no candidate' ti_equals "$TI_OUT" 'scan-verdict=complete'
  ti_scan_shape_run master added-checks
  ti_assert 'scan --without-review: an added phpunit.xml beside a phpunit.xml.dist is a candidate' \
    ti_scan_has check-path-changed packages/app/phpunit.xml -
  ti_assert 'scan --without-review: an added phpstan.neon beside a phpstan.neon.dist is a candidate' \
    ti_scan_has check-path-changed packages/app/phpstan.neon -
  ti_assert 'scan --without-review: an added dotted PHPStan configuration is a candidate' ti_scan_has check-path-changed .phpstan.dist.neon -
  ti_assert 'scan --without-review: an added PHPStan baseline is a candidate' ti_scan_has check-path-changed phpstan-baseline.neon -
  ti_assert 'scan --without-review: an added tests/Pest.php is a candidate' ti_scan_has check-path-changed tests/Pest.php -
  ti_scan_shape_run master added-test
  ti_assert 'scan --without-review: a change that only adds a test completes with candidates' ti_equals "$TI_RC" 5
  ti_assert 'scan --without-review: an added test that ends the runner is a candidate' ti_scan_has check-path-changed tests/Unit/ZzTest.php -
  ti_scan_shape_run master renamed-in
  ti_assert 'scan --without-review: a production file renamed into the test side is a candidate by its new path' \
    ti_equals "$TI_OUT" "$(printf 'candidate\tcheck-path-changed\ttests/bootstrap.php\t-\nscan-verdict=complete')"
  ti_scan_shape_run master renamed-out
  ti_assert 'scan --without-review: a test renamed out of the test side is a candidate by its old path' \
    ti_scan_has check-path-changed tests/Unit/OutTest.php -

  ti_scan_run master dotted-level
  ti_assert 'scan: a lowered level in a dotted PHPStan configuration is a candidate' \
    ti_scan_has analysis-level-lowered .phpstan.neon.dist 2

  ti_scan_run master loosened-or
  ti_assert 'scan: a Composer script entry that gains an or-true is a loosened script' \
    ti_equals "$TI_OUT" "$(printf 'candidate\tscript-loosened\tcomposer.json\t-\nscan-verdict=complete')"
  ti_scan_run master loosened-level
  ti_assert 'scan: a Composer script entry that sets a low analysis level is a loosened script' \
    ti_equals "$TI_OUT" "$(printf 'candidate\tscript-loosened\tcomposer.json\t-\nscan-verdict=complete')"
  ti_scan_run master raised-level
  ti_assert 'scan: a Composer script entry that raises the analysis level to max is no candidate' ti_equals "$TI_OUT" 'scan-verdict=complete'

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

# Paths the classifier discounts as a test, as formatting or metadata, or as documentation. Each one must be a
# check path of the shape mode, so the merge paths without a review and the classifier never disagree on what a
# test or a check is.
TI_SHARED_TEST_PATHS=(test/bootstrap.php test/unit/add.test.js spec/add_spec.rb resources/js/calc.test.js src/calc_test.go
  test_x.py test/fixtures/expected.json tests/Unit/CalcTest.php src/Billing/CalcTest.php web/calc.spec.ts phpunit.xml.dist
  src/__tests__/calc.ts pkg/testdata/golden.txt)
TI_SHARED_CHECKER_PATHS=(resources/.eslintrc.json .prettierrc pint.json phpcs.xml ruleset.xml.dist rector.php .php-cs-fixer.dist.php
  app/.gitignore resources/.editorconfig tests/.gitattributes src/lib/.gitattributes .gitignore .editorconfig)
# Files a runner collects or loads in its default configuration that the documentation pattern of the classifier
# also matches: pytest doctest files and conftest.py, pytest, Jest, Vitest, and Go tests under docs/. PHPUnit,
# Pest, and RSpec collect only under tests/ or spec/ or by the Test.php suffix, so they add no such path.
TI_SHARED_RUNNER_PATHS=(app/test_add.txt test_add.txt docs/test_doc.py docs/doc_test.py docs/conftest.py
  docs/calc.test.js docs/calc.spec.ts docs/__tests__/calc.js docs/calc_test.go)
# The files where a runner reads a collection pattern the project sets itself, or its tests (CTest).
TI_SHARED_RUNNER_CONFIG_PATHS=(pytest.ini tox.ini setup.cfg pyproject.toml package.json .rspec jest.config.js
  vitest.config.ts vite.config.mjs docs/pytest.ini src/CTestCustom.cmake)
# The `.txt` files a tool reads as its list of tests or of dependencies (TOOL_LIST_RE), which the documentation
# pattern of the classifier also matches.
TI_SHARED_TOOL_LIST_PATHS=(src/CMakeLists.txt CMakeLists.txt app/requirements-dev.txt app/constraints.txt
  app/dev-requirements.txt app/requirements/base.txt app/conanfile.txt)

# Configurations and build files in a documentation directory that the scan does not name. The classifier grades
# each one as a material file, so no merge path without a review takes it with no candidate.
TI_SHARED_DOCS_DIR_PATHS=(src/docs/conf.py docs/Makefile resources/docs/playwright.config.ts app/doc/.mocharc.yml)

# A path the trusted classifier grades `tests-only`, `formatting-metadata-only`, or `documentation-only` when it is
# the only change. With a signal name as the second argument, only that signal counts.
ti_classifier_discounts() {
  local out signals="${2:-tests-only|formatting-metadata-only|documentation-only}"
  out="$(printf '%s\n' "$1" | bash "$TI_DIR/classify-risk.sh" --files - 2>/dev/null)" || return 1
  grep -qE "^signal=-[0-9]+\\|($signals)\\|" <<<"$out"
}

# A path the classifier discounts is a check-path-changed candidate of the last shape scan.
ti_discount_has_candidate() {
  ! ti_classifier_discounts "$1" || ti_scan_has check-path-changed "$1" -
}

# Every path the classifier discounts as a test, as formatting, or as documentation is a check-path-changed
# candidate of the shape mode, whether the change edits it or deletes it, and no file a runner collects by default
# is documentation to the classifier.
ti_selftest_shared_definition() {
  local path
  TI_REPO="$TI_ROOT/shared"
  for path in "${TI_SHARED_TEST_PATHS[@]}" "${TI_SHARED_CHECKER_PATHS[@]}" "${TI_SHARED_RUNNER_PATHS[@]}" \
    "${TI_SHARED_RUNNER_CONFIG_PATHS[@]}" "${TI_SHARED_TOOL_LIST_PATHS[@]}" "${TI_SHARED_DOCS_DIR_PATHS[@]}"; do
    mkdir -p "$TI_REPO/$(dirname -- "$path")"
    printf 'base\n' >"$TI_REPO/$path"
  done
  git -C "$TI_REPO" init -q
  ti_git add -A
  ti_git commit -q -m base
  ti_git branch -q -M master
  ti_git checkout -q -b shapes
  for path in "${TI_SHARED_TEST_PATHS[@]}" "${TI_SHARED_CHECKER_PATHS[@]}" "${TI_SHARED_RUNNER_PATHS[@]}" \
    "${TI_SHARED_RUNNER_CONFIG_PATHS[@]}" "${TI_SHARED_TOOL_LIST_PATHS[@]}" "${TI_SHARED_DOCS_DIR_PATHS[@]}"; do
    printf 'exit\n' >"$TI_REPO/$path"
  done
  rm -f -- "$TI_REPO/spec/add_spec.rb"
  ti_git add -A
  ti_git commit -q -m shapes
  ti_scan_shape_run master shapes
  ti_assert 'scan --without-review: the shared shapes complete with candidates' ti_equals "$TI_RC" 5
  for path in "${TI_SHARED_TEST_PATHS[@]}" "${TI_SHARED_CHECKER_PATHS[@]}" "${TI_SHARED_RUNNER_PATHS[@]}"; do
    ti_assert "classify-risk: $path alone is graded tests-only, formatting, or documentation" ti_classifier_discounts "$path"
    if ti_classifier_discounts "$path"; then
      ti_assert "scan --without-review: $path, which the classifier discounts, is a candidate" \
        ti_scan_has check-path-changed "$path" -
    fi
  done
  for path in "${TI_SHARED_RUNNER_PATHS[@]}" "${TI_SHARED_TOOL_LIST_PATHS[@]}"; do
    ti_assert "classify-risk: $path, which a runner or a tool reads by default, is never documentation" \
      ti_not ti_classifier_discounts "$path" documentation-only
  done
  for path in "${TI_SHARED_RUNNER_CONFIG_PATHS[@]}" "${TI_SHARED_TOOL_LIST_PATHS[@]}"; do
    ti_assert "scan --without-review: the runner configuration $path is a candidate" ti_scan_has check-path-changed "$path" -
  done
  for path in "${TI_SHARED_DOCS_DIR_PATHS[@]}"; do
    ti_assert "classify-risk: $path, which the scan does not name, is a material file" ti_not ti_classifier_discounts "$path"
    ti_assert "scan --without-review: $path is a candidate or a material file" ti_discount_has_candidate "$path"
  done
}

# A stop before the verdict, here a vocabulary without FORMAT_RE under set -u, ends incomplete with exit 7.
ti_selftest_scan_stop() {
  mkdir -p "$TI_ROOT/stoplib"
  cp "$TI_DIR"/*.sh "$TI_ROOT/stoplib/"
  printf 'unset FORMAT_RE\n' >>"$TI_ROOT/stoplib/domain-vocabulary.sh"
  TI_REPO="$TI_ROOT/shared"
  ti_run bash "$TI_ROOT/stoplib/test-integrity.sh" scan --base "$(ti_git rev-parse master)" \
    --head "$(ti_git rev-parse shapes)" --without-review
  ti_assert 'scan: a stop before the verdict exits 7' ti_equals "$TI_RC" 7
  ti_assert 'scan: a stop before the verdict ends with the incomplete verdict' \
    ti_equals "$(tail -n 1 <<<"$TI_OUT")" 'scan-verdict=incomplete reason=internal-error'
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
  ti_selftest_blocked_message
  ti_selftest_verify
  ti_selftest_scan
  ti_selftest_shared_definition
  ti_selftest_scan_stop

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
