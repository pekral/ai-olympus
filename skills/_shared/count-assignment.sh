#!/usr/bin/env bash
# count-assignment.sh — the one owner of the method `files-and-criteria`: the count of an assignment
# text. `@skills/do-it/SKILL.md` reads its `count=` line as the duration of an item, and
# `skills/github-issue-triage/scripts/derive-effort.sh` reads the same line to size an issue.
#
# The count is a number read from the text. It is not a time, and the script derives no estimate.
#
# Usage
#   count-assignment.sh --assignment <file|->
#   count-assignment.sh --self-test
#
# Method
#   count = files + criteria
#   files     the distinct file paths the text names. A path is a token of segments joined by `/` that
#             ends in a file extension. A URL is not a path.
#   criteria  the top-level bullets under a heading that names a counted section, and nothing else. The
#             counted sections are the business headings of `rules/compound-engineering/assignment.md`
#             in both project languages (ASSIGNMENT_BUSINESS_HEADINGS_RE of domain-vocabulary.sh) and
#             `Acceptance criteria` / `Akceptační kritéria`. A heading counts only when its whole name is
#             one of them. A text with none of these headings counts every bullet of the text. A text
#             with no bullet counts 1: an assignment holds at least one rule.
#   A heading is matched in lower case with ASCII letters only (an all-capitals Czech heading is not a
#   counted heading), after a closing `\r` and a closing run of `#` are removed.
#   Neither files nor criteria read a fenced code block or the `Original assignment` section (the
#   quotation of the operator). A fence that never closes is text (`strip_assignment_fences` of
#   domain-vocabulary.sh, the same fence rule `classify-risk.sh` applies to an assignment).
#
# Output (stdout, key=value, in this order)
#   method=files-and-criteria
#   files=<n>
#   criteria=<n>
#   criteria-source=sections|bullets|minimum
#   count=<files + criteria>
#
# Exit codes
#   0  the count was printed
#   1  usage error or an unreadable assignment
set -euo pipefail
set -f

PROG="${0##*/}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=domain-vocabulary.sh
. "$HERE/domain-vocabulary.sh"

COUNTED_HEADINGS_RE="$ASSIGNMENT_BUSINESS_HEADINGS_RE|acceptance criteria|akceptační kritéria"
ORIGINAL_HEADING_RE='original assignment|původní zadání'

die() {
  echo "$PROG: $1" >&2
  exit 1
}

usage() {
  cat >&2 <<'USAGE'
Usage: count-assignment.sh --assignment <file|->
       count-assignment.sh --self-test
USAGE
}

# scan_text — read the text on stdin, print `sectioned=<0|1> section-bullets=<n> bullets=<n> files=<n>`.
scan_text() {
  strip_assignment_fences | COUNTED="$COUNTED_HEADINGS_RE" ORIGINAL="$ORIGINAL_HEADING_RE" awk '
    function heading_name(line,    name) {
      name = line
      if (index(name, "<summary>") > 0) {
        sub(/.*<summary>/, "", name)
        sub(/<\/summary>.*/, "", name)
        gsub(/<[^>]*>/, "", name)
      } else {
        sub(/^#+[ ]+/, "", name)
        gsub(/\*\*/, "", name)
      }
      gsub(/[ \t]+#+[ \t]*$/, "", name)
      gsub(/^[ \t]+/, "", name)
      gsub(/[ \t:]+$/, "", name)
      return name
    }
    # Only ASCII letters are folded, so every awk gives one result for one text. A heading of
    # non-ASCII capitals (an all-capitals Czech heading) is therefore not a counted heading.
    function lower_ascii(text,    i, c, p, out) {
      out = ""
      for (i = 1; i <= length(text); i++) {
        c = substr(text, i, 1)
        p = index("ABCDEFGHIJKLMNOPQRSTUVWXYZ", c)
        out = out (p > 0 ? substr("abcdefghijklmnopqrstuvwxyz", p, 1) : c)
      }
      return out
    }
    BEGIN { sec = "none"; sectioned = 0; secbul = 0; bul = 0; counted = "^(" ENVIRON["COUNTED"] ")$"; original = "^(" ENVIRON["ORIGINAL"] ")$" }
    {
      line = $0
      sub(/\r$/, "", line)
      lower = lower_ascii(line)
      if (index(lower, "</details>") > 0) { sec = "none"; next }
      if (index(lower, "<summary>") > 0 || lower ~ /^#+[ ]/ || lower ~ /^\*\*.*\*\*[ ]*:?[ ]*$/) {
        name = lower_ascii(heading_name(line))
        if (name ~ counted) { sec = "count"; sectioned = 1 }
        else if (name ~ original) { sec = "original" }
        else { sec = "other" }
        next
      }
      if (sec == "original") { next }
      if (line ~ /^[ ]?([-*]|[0-9]+[.)])[ ]+[^ ]/) {
        bul++
        if (sec == "count") { secbul++ }
      }
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
  '
}

count() {
  local source_file="$1" scan sectioned secbul bullets files criteria source=sections
  if [[ "$source_file" == - ]]; then
    scan="$(scan_text)"
  else
    [[ -r "$source_file" ]] || die "cannot read the assignment: $source_file"
    scan="$(scan_text <"$source_file")"
  fi
  sectioned="$(sed -n 's/.*sectioned=\([0-9]*\).*/\1/p' <<<"$scan")"
  secbul="$(sed -n 's/.*section-bullets=\([0-9]*\).*/\1/p' <<<"$scan")"
  bullets="$(sed -n 's/.*[ ]bullets=\([0-9]*\).*/\1/p' <<<"$scan")"
  files="$(sed -n 's/.*files=\([0-9]*\).*/\1/p' <<<"$scan")"

  if [[ "$sectioned" -eq 1 ]]; then
    criteria="$secbul"
  else
    criteria="$bullets"
    source=bullets
  fi
  if [[ "$criteria" -lt 1 ]]; then
    criteria=1
    source=minimum
  fi

  printf 'method=files-and-criteria\n'
  printf 'files=%d\n' "$files"
  printf 'criteria=%d\n' "$criteria"
  printf 'criteria-source=%s\n' "$source"
  printf 'count=%d\n' "$((files + criteria))"
}

SELF_TEST_FAILURES=0
SELF_DIR=''
SELF_SCRIPT="$HERE/$PROG"

cleanup_self_test() {
  [[ -z "$SELF_DIR" ]] || rm -rf -- "$SELF_DIR"
}

# expect_value <label> <fixture file> <key> <expected value>
expect_value() {
  local label="$1" file="$2" key="$3" expected="$4" actual
  actual="$("$SELF_SCRIPT" --assignment "$file" 2>&1 | sed -n "s/^${key}=//p")"
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

# section <title> <count> — <count> bullets of one business section, in the details format of an issue body.
section() {
  local title="$1" total="$2" i
  printf '<details open>\n<summary><strong>%s</strong></summary>\n<dl>\n<dd>\n<br>\n\n' "$title"
  for ((i = 1; i <= total; i++)); do
    printf -- '- bullet %d\n' "$i"
  done
  printf '\n</dd>\n</dl>\n</details>\n\n'
}

self_test() {
  trap cleanup_self_test EXIT
  SELF_DIR="$(mktemp -d)"
  local d="$SELF_DIR"

  { echo 'Problem today.'; echo; section 'What the user must be able to do' 2; section 'The rules' 3; } >"$d/five.md"
  expect_value 'the bullets of the two business sections are the criteria' "$d/five.md" criteria 5
  expect_value 'the criteria come from the sections' "$d/five.md" criteria-source sections
  expect_value 'the count of five bullets and no file is 5' "$d/five.md" count 5
  expect_value 'the method is named' "$d/five.md" method files-and-criteria

  { section 'What the user must be able to do' 1; section 'What we are not doing now' 4; section 'Open questions for the owner' 2; } >"$d/other.md"
  expect_value 'a bullet outside the counted sections is not a criterion' "$d/other.md" criteria 1

  { section 'Co musí uživatel umět' 1; section 'Pravidla' 2; section 'Co teď neděláme' 4; } >"$d/czech.md"
  expect_value 'the Czech business headings are counted sections' "$d/czech.md" criteria 3
  expect_value 'the Czech business headings are read as sections' "$d/czech.md" criteria-source sections

  { section 'Akceptační kritéria' 2; section 'Co teď neděláme' 3; } >"$d/czech-ac.md"
  expect_value 'the Czech acceptance heading is a counted section' "$d/czech-ac.md" criteria 2

  { section 'Acceptance criteria' 3; section 'Out of scope' 2; } >"$d/ac.md"
  expect_value 'an Acceptance criteria heading is a counted section' "$d/ac.md" criteria 3

  { section 'How we break the rules of the game' 2; section 'The rules' 1; } >"$d/substring.md"
  expect_value 'a heading that only contains a counted name is not a counted section' "$d/substring.md" criteria 1

  { section 'Rules and limits' 4; } >"$d/prefix.md"
  expect_value 'a heading that starts with a counted name is not a counted section' "$d/prefix.md" criteria-source bullets

  printf '# The rules\n\n- one\n- two\n\n## Out of scope\n\n- three\n' >"$d/markdown.md"
  expect_value 'a markdown heading opens a counted section until the next heading' "$d/markdown.md" criteria 2

  printf '**The rules**\n- one\n- two\n**Not now**\n- three\n' >"$d/bold.md"
  expect_value 'a bold line is a heading' "$d/bold.md" criteria 2

  # shellcheck disable=SC2016
  { section 'The rules' 2; printf 'Touch skills/github-issue-triage/SKILL.md and `tests/Installer/A.php`, then skills/github-issue-triage/SKILL.md again.\n'; } >"$d/files.md"
  expect_value 'a path named twice counts once' "$d/files.md" files 2
  expect_value 'files and criteria add up' "$d/files.md" count 4

  { section 'The rules' 1; printf 'See https://example.com/docs/page.html and http://host/a/b.md for details.\n'; } >"$d/url.md"
  expect_value 'a URL is not a file' "$d/url.md" files 0

  # shellcheck disable=SC2016
  printf '# The rules\n\n- one\n```text\nsrc/Hidden/File.php\n- hidden bullet\n```\n- two\n' >"$d/fence.md"
  expect_value 'a backtick fence is not read for files' "$d/fence.md" files 0
  expect_value 'a backtick fence is not read for bullets' "$d/fence.md" criteria 2

  printf '# The rules\n\n- one\n~~~text\nsrc/Hidden/File.php\n- hidden bullet\n~~~\n- two\n' >"$d/tilde.md"
  expect_value 'a tilde fence is not read for files' "$d/tilde.md" files 0
  expect_value 'a tilde fence is not read for bullets' "$d/tilde.md" criteria 2

  # shellcheck disable=SC2016
  { printf '<details open>\n<summary><strong>The rules</strong></summary>\n\n- first\n```text\n- second\n- third\nsrc/Late/File.php\n'; } >"$d/unclosed.md"
  expect_value 'an unclosed fence is read as text, its bullets count' "$d/unclosed.md" criteria 3
  expect_value 'an unclosed fence is read as text, its files count' "$d/unclosed.md" files 1

  printf '# The rules\n\n- one\n~~~text\n- inside\n```\n- still inside\n~~~\n- after\n' >"$d/mixed.md"
  expect_value 'a fence closes only on its own mark' "$d/mixed.md" criteria 2

  {
    section 'The rules' 1
    printf '<details>\n<summary><strong>Original assignment</strong></summary>\n\n- quoted bullet\nsrc/Quoted/File.php\n\n</details>\n'
  } >"$d/original.md"
  expect_value 'the original assignment adds no file' "$d/original.md" files 0
  expect_value 'the original assignment adds no criterion' "$d/original.md" criteria 1

  { printf -- '- own bullet\n\n'; printf '<details>\n<summary><strong>Original assignment</strong></summary>\n\n'; printf -- '- q1\n- q2\n- q3\n- q4\n- q5\n- q6\n\n</details>\n'; } >"$d/original-fallback.md"
  expect_value 'the original assignment adds no bullet to the fallback count' "$d/original-fallback.md" criteria 1
  expect_value 'the fallback names its source' "$d/original-fallback.md" criteria-source bullets

  printf '# Původní zadání\n\n- one\n- two\n\n# Pravidla\n\n- rule\n' >"$d/czech-original.md"
  expect_value 'the Czech original assignment is skipped' "$d/czech-original.md" criteria 1

  printf '# The rules\r\n\r\n- one\r\n- two\r\n\r\n# Out\r\n\r\n- x\r\n' >"$d/crlf.md"
  expect_value 'a heading and a bullet with a closing carriage return are read' "$d/crlf.md" criteria 2

  printf '# The rules ##\n\n- one\n\n# Out\n\n- x\n' >"$d/closing.md"
  expect_value 'the closing hashes of a heading are not part of its name' "$d/closing.md" criteria 1

  printf '# THE RULES\n\n- one\n- two\n\n# Out\n\n- x\n' >"$d/upper.md"
  expect_value 'an ASCII heading is matched in any case' "$d/upper.md" criteria 2

  printf '# CO MUSÍ UŽIVATEL UMĚT\n\n- one\n- two\n\n# Out\n\n- x\n' >"$d/czech-upper.md"
  expect_value 'an all-capitals Czech heading is not a counted heading, in every awk' "$d/czech-upper.md" criteria-source bullets

  printf 'Intro.\n\n- first\n- second\n* third\n1. fourth\n' >"$d/plain.md"
  expect_value 'a text without the headings counts every bullet' "$d/plain.md" criteria 4
  expect_value 'a text without the headings says so' "$d/plain.md" criteria-source bullets

  printf 'Only a sentence.\n' >"$d/none.md"
  expect_value 'a text without a bullet counts one' "$d/none.md" criteria 1
  expect_value 'a text without a bullet says so' "$d/none.md" criteria-source minimum

  printf -- '  - nested bullet\n- top bullet\n' >"$d/nested.md"
  expect_value 'an indented bullet is no criterion' "$d/nested.md" criteria 1

  expect_value 'the same text gives the same count (first run)' "$d/files.md" count 4
  expect_value 'the same text gives the same count (second run)' "$d/files.md" count 4
  if [[ "$("$SELF_SCRIPT" --assignment - <"$d/five.md" | sed -n 's/^count=//p')" == 5 ]]; then
    printf 'ok    the text can come from stdin\n'
  else
    printf 'FAIL  the text can come from stdin\n' >&2
    SELF_TEST_FAILURES=$((SELF_TEST_FAILURES + 1))
  fi

  expect_exit 'a missing assignment is refused' 1 "$SELF_SCRIPT" --assignment "$d/missing.md"
  expect_exit 'a call with no argument is refused' 1 "$SELF_SCRIPT"
  expect_exit 'an unknown option is refused' 1 "$SELF_SCRIPT" --hours 3

  if [[ "$SELF_TEST_FAILURES" -gt 0 ]]; then
    echo "count-assignment self-test: $SELF_TEST_FAILURES failure(s)" >&2
    return 1
  fi
  echo 'count-assignment self-test: PASS'
}

if [[ "${1:-}" == '--self-test' ]]; then
  self_test
  exit $?
fi

if [[ $# -ne 2 || "$1" != '--assignment' ]]; then
  usage
  exit 1
fi

count "$2"
