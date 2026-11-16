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
