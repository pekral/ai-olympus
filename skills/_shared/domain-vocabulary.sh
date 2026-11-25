#!/usr/bin/env bash
# The globals this library sets are read by the script that sources it.
# shellcheck disable=SC2034
# domain-vocabulary.sh — shared word lists for the sensitive domains.
#
# Sourced, not executed, by `classify-risk.sh` (which tier a change gets) and
# `gate-context.sh` (which project knowledge a task needs). Both must agree on
# what an auth, a data-loss, or a concurrency signal is, so the word lists
# exist here once. A word added here changes both verdicts. `test-integrity.sh`
# sources it too, for TEST_RE, TOOL_LIST_RE, and FORMAT_RE below.
#
# It also holds the two parts of reading an assignment text that `classify-risk.sh`
# and `count-assignment.sh` share: the names of the business-only headings
# (ASSIGNMENT_BUSINESS_HEADINGS_RE) and the fence filter (`strip_assignment_fences`).
# Both sit here, in a file the trusted copy of `classify-risk.sh` already verifies, so
# the merge path needs no new sibling.
#
# Every pattern is lower case. The caller lower-cases its input and matches it
# one line at a time, so `(^|/)` and `$` anchor to one path or one line.
# Words only one caller needs stay in that caller.
#
# DATA_PATH_RE names a data-changing file by its path: a directory whose name holds
# the word `migration` in any form (`migrations/`, `DoctrineMigrations/`,
# `tenant-migrations/`), a timestamped migration file name, a Doctrine `Version<date>`
# file, an SQL file, or a schema file. A path that `git diff` quotes (`"migrations/…"`)
# matches too. classify-risk.sh matches changed paths against it, so a class that is
# only named for a migration (`InstallerAgentMigration.php`) raises no data signal.
# DATA_RE adds the bare word `migration` for task text, where the word is the signal.
# The pattern carries no interval expression (`{4}`): awk reads it too, and an older
# mawk does not support intervals.

AUTH_RE='auth|login|logout|password|credential|secret|token|jwt|oauth|saml|session|crypt|cipher|(^|/)policies/|policy|(^|/)gates?/|permission|privilege|(^|/)acl|(^|/)roles?/|admin|guard|csrf|cors|sanctum|passport|2fa|mfa'
DATA_PATH_RE='migration[^/]*/|(^"?|/)[0-9][0-9][0-9][0-9]_[0-9][0-9]_[0-9][0-9]_[0-9][0-9][0-9][0-9][0-9][0-9]_[^/]*\.php"?$|(^"?|/)version[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]+\.php"?$|\.sql"?$|(^"?|/)schema[./]|truncate|drop_'
DATA_RE="$DATA_PATH_RE|migration"
QUEUE_RE='(^|/)jobs?/|(^|/)queues?/|queue|worker|(^|/)listeners?/|(^|/)events?/|lock|mutex|semaphore|concurren|cache|redis|schedul|cron|broadcast'

# RUNNER_COLLECTED_RE names a file by its lower-cased path that pytest, PHPUnit, Pest, Jest, Vitest,
# Go, or RSpec collects or loads in its default configuration: `test.php` and `tests.php` file names,
# `.test.`, `.spec.`, and `_test.` suffixes, a `test_*.py` file, a pytest doctest file (`test*.txt`)
# and `conftest.py`, a script under a Jest `__tests__` directory, and the PHPUnit and Pest
# configuration files. `classify-risk.sh` never grades such a file as documentation.
# TEST_RE names a test file by its lower-cased path: every RUNNER_COLLECTED_RE file, and a `test`,
# `tests`, `spec`, `__tests__`, or Go `testdata` directory. `classify-risk.sh` reads it for the
# `tests-only` signal of the merge risk, `post-inline-review.sh` reads it to decide whether a
# Walkthrough row may name a test file, and `test-integrity.sh` reads it as its test-side paths, so
# all three give one answer for one path. Both patterns are EREs that bash, awk, and the regular
# expressions of jq all read.
RUNNER_COLLECTED_RE='tests?\.php$|\.test\.[a-z]+$|\.spec\.[a-z]+$|_test\.[a-z]+$|(^|/)test_[^/]*\.py$|(^|/)test[^/]*\.txt$|(^|/)conftest\.py$|(^|/)__tests__/.*\.[cm]?[jt]sx?$|(^|/)phpunit\.xml|(^|/)pest\.php$'
TEST_RE='(^|/)tests?/|(^|/)spec/|(^|/)__tests__/|(^|/)testdata/|'"$RUNNER_COLLECTED_RE"

# TOOL_LIST_RE names a `.txt` file by its lower-cased path that a common tool reads as its list of tests or of
# dependencies: a CMake `CMakeLists.txt`, whose `add_test()` calls are the CTest tests, a pip requirements or
# constraints file (`requirements-dev.txt`, `dev-requirements.txt`, `requirements/base.txt`), and a Conan
# `conanfile.txt`. `classify-risk.sh` never grades such a file as documentation, and `test-integrity.sh` reads
# it as a runner configuration, so it is a check path of the scan.
TOOL_LIST_RE='(^|/)cmakelists\.txt$|(^|/)[^/]*(requirements|constraints)[^/]*\.txt$|(^|/)requirements/[^/]*\.txt$|(^|/)conanfile\.txt$'

# CHECKER_CONFIG_RE names the configuration of a checker by its lower-cased path: Prettier, ESLint,
# StyleCI, Pint, PHP CS Fixer, PHP_CodeSniffer, Rector, and PHPStan. FORMAT_RE adds the editor and
# git metadata files. `classify-risk.sh` reads FORMAT_RE for the `formatting-metadata-only` signal,
# and `test-integrity.sh` reads FORMAT_RE as check paths, so every file the classifier grades as
# formatting or metadata, at every depth, is a check path of the scan.
CHECKER_CONFIG_RE='(^|/)\.(prettierrc|eslintrc|styleci)([.-][a-z0-9]+)*$|(^|/)(pint|\.php-cs-fixer)\.[a-z.]+$|(^|/)(ruleset|phpcs)\.xml(\.dist)?$|(^|/)rector\.php$|(^|/)phpstan\.neon(\.dist)?$'
FORMAT_RE='(^|/)\.(editorconfig|gitignore|gitattributes)([.-][a-z0-9]+)*$|'"$CHECKER_CONFIG_RE"

# The names of the business-only headings of an assignment, in both project languages
# (rules/compound-engineering/assignment.md). `classify-risk.sh` reads them as a heading
# that states what "done" means, and `count-assignment.sh` reads them as the sections whose
# bullets are the criteria. Lower case, and a whole heading name each.
ASSIGNMENT_BUSINESS_HEADINGS_RE='the rules|what the user must be able to do|pravidla|co musí uživatel umět'

# strip_assignment_fences — read an assignment text on stdin and print it without its fenced
# code blocks. A fence opens with a line of three backticks or three tildes and closes with
# the same mark. The marker lines and the lines between them are dropped. A fence that never
# closes is not a fence: its lines are printed at the end, which is their own position, so an
# unclosed fence hides nothing.
strip_assignment_fences() {
  awk '
    match($0, /^[[:space:]]*(```|~~~)/) {
      mark = substr($0, RSTART + RLENGTH - 3, 3)
      if (fence == "") { fence = mark; held = 0 } else if (mark == fence) { fence = "" }
      next
    }
    fence != "" { held++; buffer[held] = $0; next }
    { print }
    END {
      if (fence != "") {
        for (i = 1; i <= held; i++) { print buffer[i] }
      }
    }
  '
}
