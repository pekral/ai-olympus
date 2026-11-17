#!/usr/bin/env bash
# derive-effort.sh — derive the effort label of one assignment: `effort: small`, `effort: medium`, or
# `effort: large`. The label is a size, never a time.
#
# Why this exists
#   The triage labels every analysed issue with its size, so small tasks can be picked quickly and large
#   ones split before work starts. The size must be the same every time for the same text, so a script
#   derives it and an agent never guesses a threshold. The script publishes no estimate in hours or days
#   (@rules/general/general.md *Verified statements only*): it prints a count, the method that produced
#   the count, and the label that the thresholds below assign to it.
#
# Method (the same one `@skills/do-it/SKILL.md` calls `files-and-criteria`)
#   count = files + criteria
#   files     the distinct file paths the assignment text names. A path is a token of segments joined by
#             `/` that ends in a file extension. A URL is not a path. A fenced code block and the
#             `Original assignment` section (a quotation of the operator) are not read.
#   criteria  the bullets of the sections `What the user must be able to do`, `The rules`, and
#             `Acceptance criteria`. A text with none of these headings counts every bullet of the text.
#             A text with no bullet counts 1: an assignment holds at least one rule.
#   tier      the `tier=` line of `skills/_shared/classify-risk.sh --assignment <file>`, or the value of
#             `--tier` (the caller already ran the classifier).
#
# Thresholds (defined once, here)
#   count  1-5  -> small     count 6-10 -> medium     count 11 or more -> large
#   A CRITICAL tier raises the size by one step (small -> medium, medium -> large, large stays large).
#   FAST and STANDARD never change it. `large` marks the issue as a candidate for splitting.
#
# Usage
#   derive-effort.sh --assignment <file> [--tier FAST|STANDARD|CRITICAL]
#   derive-effort.sh --self-test
#
# Output (stdout, key=value, in this order)
#   label=effort: <size>
#   size=small|medium|large
#   method=files-and-criteria
#   count=<files + criteria>
#   files=<n>
#   criteria=<n>
#   criteria-source=sections|bullets|minimum
#   tier=FAST|STANDARD|CRITICAL
#   tier-source=classify-risk|argument
#   tier-raise=yes|no
#   split-candidate=yes|no
#   thresholds=small<=5 medium<=10 large>=11
#
# Exit codes
#   0  a label was derived
#   1  usage error, an unreadable assignment, or a classifier that printed no tier
set -euo pipefail
set -f

PROG="${0##*/}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CLASSIFIER="$HERE/../../_shared/classify-risk.sh"

# The thresholds. Nothing else in this script, and no other file, states them.
SMALL_MAX=5
MEDIUM_MAX=10

die() {
  echo "$PROG: $1" >&2
  exit 1
}

usage() {
  cat >&2 <<'USAGE'
Usage: derive-effort.sh --assignment <file> [--tier FAST|STANDARD|CRITICAL]
       derive-effort.sh --self-test
USAGE
}

# scan_text <file> — print `sectioned=<0|1> section-bullets=<n> bullets=<n> files=<n>`.
scan_text() {
  awk '
    function is_heading(line, lower) {
      return (index(line, "<summary>") > 0 || line ~ /^#+[ ]/ || line ~ /^\*\*.*\*\*[ ]*$/)
    }
    BEGIN { sec = "none"; fence = 0; sectioned = 0; secbul = 0; bul = 0 }
    {
      line = $0
      if (line ~ /^[ ]*```/) { fence = 1 - fence; next }
      if (fence == 1) { next }
      lower = tolower(line)
      if (index(lower, "</details>") > 0) { sec = "none"; next }
      if (is_heading(lower)) {
        if (index(lower, "what the user must be able to do") > 0 || index(lower, "the rules") > 0 || index(lower, "acceptance criteria") > 0) {
          sec = "count"; sectioned = 1
        } else if (index(lower, "original assignment") > 0) {
          sec = "original"
        } else {
          sec = "other"
        }
        next
      }
      if (line ~ /^[ ]?([-*]|[0-9]+[.)])[ ]+[^ ]/) {
        bul++
        if (sec == "count") { secbul++ }
      }
      if (sec == "original") { next }
      rest = line
      offset = 0
      while (match(rest, /[A-Za-z0-9._-]+(\/[A-Za-z0-9._-]+)+\.[A-Za-z0-9]+/)) {
        token = substr(rest, RSTART, RLENGTH)
        before = (RSTART + offset > 1) ? substr(line, RSTART + offset - 1, 1) : ""
        before2 = (RSTART + offset > 2) ? substr(line, RSTART + offset - 2, 2) : ""
        if (before != ":" && before2 != "//") { names[token] = 1 }
        offset += RSTART + RLENGTH - 1
        rest = substr(rest, RSTART + RLENGTH)
      }
    }
    END {
      nfiles = 0
      for (name in names) { nfiles++ }
      printf "sectioned=%d section-bullets=%d bullets=%d files=%d\n", sectioned, secbul, bul, nfiles
    }
  ' "$1"
}

# size_of <count> <tier> — print `<size> <raised yes|no>`.
size_of() {
  local count="$1" tier="$2" size raised=no
  if ((count <= SMALL_MAX)); then
    size=small
  elif ((count <= MEDIUM_MAX)); then
    size=medium
  else
    size=large
  fi
  if [[ "$tier" == CRITICAL && "$size" != large ]]; then
    raised=yes
    if [[ "$size" == small ]]; then size=medium; else size=large; fi
  fi
  printf '%s %s' "$size" "$raised"
}

derive() {
  local file="$1" tier="$2" tier_source=argument scan sectioned secbul bullets files criteria source count result size raised split=no
  [[ -r "$file" ]] || die "cannot read the assignment: $file"

  if [[ -z "$tier" ]]; then
    [[ -x "$CLASSIFIER" ]] || die "classifier not found: $CLASSIFIER"
    tier="$("$CLASSIFIER" --assignment "$file" | sed -n 's/^tier=//p' | head -n 1)"
    tier_source=classify-risk
  fi
  case "$tier" in
  FAST | STANDARD | CRITICAL) ;;
  *) die "no valid tier: ${tier:-empty}" ;;
  esac

  scan="$(scan_text "$file")"
  sectioned="$(sed -n 's/.*sectioned=\([0-9]*\).*/\1/p' <<<"$scan")"
  secbul="$(sed -n 's/.*section-bullets=\([0-9]*\).*/\1/p' <<<"$scan")"
  bullets="$(sed -n 's/.*[ ]bullets=\([0-9]*\).*/\1/p' <<<"$scan")"
  files="$(sed -n 's/.*files=\([0-9]*\).*/\1/p' <<<"$scan")"

  if [[ "$sectioned" -eq 1 ]]; then
    criteria="$secbul"
    source=sections
  else
    criteria="$bullets"
    source=bullets
  fi
  if [[ "$criteria" -lt 1 ]]; then
    criteria=1
    source=minimum
  fi

  count=$((files + criteria))
  result="$(size_of "$count" "$tier")"
  size="${result% *}"
  raised="${result#* }"
  [[ "$size" != large ]] || split=yes

  printf 'label=effort: %s\n' "$size"
  printf 'size=%s\n' "$size"
  printf 'method=files-and-criteria\n'
  printf 'count=%d\n' "$count"
  printf 'files=%d\n' "$files"
  printf 'criteria=%d\n' "$criteria"
  printf 'criteria-source=%s\n' "$source"
  printf 'tier=%s\n' "$tier"
  printf 'tier-source=%s\n' "$tier_source"
  printf 'tier-raise=%s\n' "$raised"
  printf 'split-candidate=%s\n' "$split"
  printf 'thresholds=small<=%d medium<=%d large>=%d\n' "$SMALL_MAX" "$MEDIUM_MAX" "$((MEDIUM_MAX + 1))"
}

SELF_TEST_FAILURES=0
SELF_DIR=''
SELF_SCRIPT="$HERE/$PROG"

cleanup_self_test() {
  [[ -z "$SELF_DIR" ]] || rm -rf -- "$SELF_DIR"
}

# expect_value <label> <fixture file> <tier or empty> <key> <expected value>
expect_value() {
  local label="$1" file="$2" tier="$3" key="$4" expected="$5" actual
  if [[ -n "$tier" ]]; then
    actual="$("$SELF_SCRIPT" --assignment "$file" --tier "$tier" 2>&1 | sed -n "s/^${key}=//p")"
  else
    actual="$("$SELF_SCRIPT" --assignment "$file" 2>&1 | sed -n "s/^${key}=//p")"
  fi
  if [[ "$actual" == "$expected" ]]; then
    printf 'ok    %s\n' "$label"
  else
    printf 'FAIL  %s\n        expected: %s=%s\n        actual:   %s\n' "$label" "$key" "$expected" "$actual" >&2
    SELF_TEST_FAILURES=$((SELF_TEST_FAILURES + 1))
  fi
}

# expect_exit <label> <expected exit code> <command...>
expect_exit() {
  local label="$1" expected="$2" code=0
  shift 2
  "$@" >/dev/null 2>&1 || code=$?
  if [[ "$code" -eq "$expected" ]]; then
    printf 'ok    %s\n' "$label"
  else
    printf 'FAIL  %s (exit %s, expected %s)\n' "$label" "$code" "$expected" >&2
    SELF_TEST_FAILURES=$((SELF_TEST_FAILURES + 1))
  fi
}

# bullets_text <n> — n bullets of one business section, in the details format of an issue body.
section() {
  local title="$1" count="$2" i
  printf '<details open>\n<summary><strong>%s</strong></summary>\n<dl>\n<dd>\n<br>\n\n' "$title"
  for ((i = 1; i <= count; i++)); do
    printf -- '- bullet %d\n' "$i"
  done
  printf '\n</dd>\n</dl>\n</details>\n\n'
}

self_test() {
  trap cleanup_self_test EXIT
  SELF_DIR="$(mktemp -d)"
  local d="$SELF_DIR"

  { echo 'Problem today.'; echo; section 'What the user must be able to do' 2; section 'The rules' 3; } >"$d/five.md"
  expect_value 'five bullets and no file is a count of 5' "$d/five.md" FAST count 5
  expect_value 'a count of 5 is small' "$d/five.md" FAST label 'effort: small'
  expect_value 'the criteria come from the sections' "$d/five.md" FAST criteria-source sections

  { section 'What the user must be able to do' 3; section 'The rules' 3; } >"$d/six.md"
  expect_value 'a count of 6 is medium' "$d/six.md" STANDARD label 'effort: medium'
  expect_value 'a STANDARD tier does not raise the size' "$d/six.md" STANDARD tier-raise no

  { section 'What the user must be able to do' 5; section 'The rules' 5; } >"$d/ten.md"
  expect_value 'a count of 10 is medium' "$d/ten.md" STANDARD label 'effort: medium'
  expect_value 'a medium issue is no split candidate' "$d/ten.md" STANDARD split-candidate no

  { section 'What the user must be able to do' 5; section 'The rules' 6; } >"$d/eleven.md"
  expect_value 'a count of 11 is large' "$d/eleven.md" STANDARD label 'effort: large'
  expect_value 'a large issue is a split candidate' "$d/eleven.md" STANDARD split-candidate yes

  expect_value 'a FAST tier does not change the size' "$d/eleven.md" FAST label 'effort: large'
  expect_value 'a CRITICAL tier raises small to medium' "$d/five.md" CRITICAL label 'effort: medium'
  expect_value 'a CRITICAL tier raises medium to large' "$d/six.md" CRITICAL label 'effort: large'
  expect_value 'a CRITICAL tier keeps large' "$d/eleven.md" CRITICAL label 'effort: large'
  expect_value 'a raised size says so' "$d/six.md" CRITICAL tier-raise yes
  expect_value 'a count of 5 and a CRITICAL tier is no split candidate' "$d/five.md" CRITICAL split-candidate no

  { section 'What the user must be able to do' 1; section 'What we are not doing now' 4; section 'Open questions for the owner' 2; } >"$d/other.md"
  expect_value 'a bullet outside the counted sections is not a criterion' "$d/other.md" FAST criteria 1

  # shellcheck disable=SC2016
  { section 'The rules' 2; printf 'Touch skills/github-issue-triage/SKILL.md and `tests/Installer/A.php`, then skills/github-issue-triage/SKILL.md again.\n'; } >"$d/files.md"
  expect_value 'a path named twice counts once' "$d/files.md" FAST files 2
  expect_value 'files and criteria add up' "$d/files.md" FAST count 4

  { section 'The rules' 1; printf 'See https://example.com/docs/page.html and http://host/a/b.md for details.\n'; } >"$d/url.md"
  expect_value 'a URL is not a file' "$d/url.md" FAST files 0

  # shellcheck disable=SC2016
  { section 'The rules' 1; printf '```text\nsrc/Hidden/File.php\n```\n'; } >"$d/fence.md"
  expect_value 'a fenced block is not read for files' "$d/fence.md" FAST files 0

  {
    section 'The rules' 1
    printf '<details>\n<summary><strong>Original assignment</strong></summary>\n\n- quoted bullet\nsrc/Quoted/File.php\n\n</details>\n'
  } >"$d/original.md"
  expect_value 'the original assignment is not counted as a file' "$d/original.md" FAST files 0
  expect_value 'the original assignment is not counted as a criterion' "$d/original.md" FAST criteria 1

  printf 'Intro.\n\n- first\n- second\n* third\n1. fourth\n' >"$d/plain.md"
  expect_value 'a text without the headings counts every bullet' "$d/plain.md" FAST criteria 4
  expect_value 'a text without the headings says so' "$d/plain.md" FAST criteria-source bullets

  printf 'Only a sentence.\n' >"$d/none.md"
  expect_value 'a text without a bullet counts one' "$d/none.md" FAST criteria 1
  expect_value 'a text without a bullet says so' "$d/none.md" FAST criteria-source minimum
  expect_value 'the tier given by the caller is named' "$d/none.md" FAST tier-source argument

  printf -- '  - nested bullet\n- top bullet\n' >"$d/nested.md"
  expect_value 'an indented bullet is no criterion' "$d/nested.md" FAST criteria 1

  expect_value 'the same text always gives the same label (first run)' "$d/eleven.md" STANDARD label 'effort: large'
  expect_value 'the same text always gives the same label (second run)' "$d/eleven.md" STANDARD label 'effort: large'
  expect_value 'the method is named' "$d/five.md" FAST method files-and-criteria
  expect_value 'the thresholds are printed' "$d/five.md" FAST thresholds 'small<=5 medium<=10 large>=11'

  expect_value 'the tier comes from the classifier when none is given' "$d/five.md" '' tier-source classify-risk

  expect_exit 'a missing assignment is refused' 1 "$SELF_SCRIPT" --assignment "$d/missing.md" --tier FAST
  expect_exit 'an unknown tier is refused' 1 "$SELF_SCRIPT" --assignment "$d/five.md" --tier HUGE
  expect_exit 'a call with no argument is refused' 1 "$SELF_SCRIPT"
  expect_exit 'an unknown option is refused' 1 "$SELF_SCRIPT" --hours 3

  if [[ "$SELF_TEST_FAILURES" -gt 0 ]]; then
    echo "derive-effort self-test: $SELF_TEST_FAILURES failure(s)" >&2
    return 1
  fi
  echo 'derive-effort self-test: PASS'
}

assignment='' tier=''
if [[ "${1:-}" == '--self-test' ]]; then
  self_test
  exit $?
fi

while [[ $# -gt 0 ]]; do
  case "$1" in
  --assignment)
    [[ $# -ge 2 ]] || { usage; exit 1; }
    assignment="$2"
    shift 2
    ;;
  --tier)
    [[ $# -ge 2 ]] || { usage; exit 1; }
    tier="$2"
    shift 2
    ;;
  *)
    usage
    exit 1
    ;;
  esac
done

[[ -n "$assignment" ]] || { usage; exit 1; }
derive "$assignment" "$tier"
