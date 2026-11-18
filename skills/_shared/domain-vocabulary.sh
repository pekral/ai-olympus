#!/usr/bin/env bash
# The globals this library sets are read by the script that sources it.
# shellcheck disable=SC2034
# domain-vocabulary.sh — shared word lists for the sensitive domains.
#
# Sourced, not executed, by `classify-risk.sh` (which tier a change gets) and
# `gate-context.sh` (which project knowledge a task needs). Both must agree on
# what an auth, a data-loss, or a concurrency signal is, so the word lists
# exist here once. A word added here changes both verdicts.
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

# TEST_RE names a test file by its lower-cased path: a `test` or `tests` directory, a `spec`
# directory, `test.php` and `tests.php` file names, `.test.`, `.spec.`, and `_test.` suffixes, a
# `test_*.py` file, and the PHPUnit and Pest configuration files. `classify-risk.sh` reads it for
# the `tests-only` signal of the merge risk, and `post-inline-review.sh` reads it to decide whether a
# Walkthrough row may name a test file, so both give one answer for one path. The pattern is an ERE
# that bash, awk, and the regular expressions of jq all read.
TEST_RE='(^|/)tests?/|(^|/)spec/|tests?\.php$|\.test\.[a-z]+$|\.spec\.[a-z]+$|_test\.[a-z]+$|(^|/)test_[a-z0-9_]+\.py$|(^|/)phpunit\.xml|(^|/)pest\.php$'

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
