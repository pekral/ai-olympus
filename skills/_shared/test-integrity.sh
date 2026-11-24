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
#   test-integrity.sh --self-test
#
# Test-side paths
#   One pattern, taken from the trigger of the test-value lens in
#   `skills/code-review/references/specialized-reviews.md`: a path under `tests/` at any depth, a `*Test.php`
#   file, a `*.spec.ts` file, and a `*.test.ts` file (TI_TEST_SIDE_RE). Every subcommand classifies a path with it.
#
# SECURITY
#   - A path, a test name, and a message from a JUnit report are data. They are compared, passed as argv
#     elements of their own, and printed only after control characters are removed; nothing reaches a shell.
#   - A path that carries a control character is refused, so no output line can be split or carry a terminal
#     escape sequence.
#
# Exit codes
#   0  the self-test passed
#   1  usage error, or a self-test check failed

set -uo pipefail

TI_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROG='test-integrity.sh'
# shellcheck source=gate-record.sh
. "$TI_DIR/gate-record.sh"

TI_TEST_SIDE_RE='(^|/)tests/|Test\.php$|\.spec\.ts$|\.test\.ts$'

ti_usage() {
  cat >&2 <<'USAGE'
Usage: test-integrity.sh --self-test
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

ti_selftest() {
  ti_selftest_classifier
  if [[ "$TI_FAILED" -ne 0 ]]; then
    echo 'test-integrity.sh: self-test failed' >&2
    return 1
  fi
  echo 'test-integrity.sh: self-test passed'
}

case "${1:-}" in
--self-test) ti_selftest ;;
*) ti_usage ;;
esac
