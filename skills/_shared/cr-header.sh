#!/usr/bin/env bash
# cr-header.sh — the one parser of the header block of a code-review comment.
#
# Why this exists
#   Two scripts read the header block of a code-review comment: check-cr-header.sh (the publication check)
#   and extract-findings.sh (the finding records and --header). Each had its own parser, so the two could
#   disagree about what a header line is. This file is the only place that decides it. Both scripts source
#   it; neither parses a header line itself. review-carry-forward.sh sources it too, for the strict view of
#   cr_header_canonical below.
#
# Usage
#   source cr-header.sh        # then: cr_header_parse < body, or cr_header_canonical < body
#   cr-header.sh --self-test
#
# What it reads
#   A header line is `**<Label>:** <value>`, or the same line without the Markdown bold. A line that the line rule
#   of cr_header_fence_step skips is a quotation and is not read. The header block is the lines before the first `## `
#   heading, without the lines of a finding: a finding heading (`### Critical 1. <title>`) opens a finding, and
#   another `###`–`######` heading or a `---` line closes it. The parser reports a header line wherever it stands
#   in the body and marks the ones that stand in the block. A byte that is not valid UTF-8 in a value does not
#   hide its line. It does not judge a label, a value, a duplicate, or a place: the caller decides.
#
#   The block has one boundary for every reader: the first line that the line rule of cr_header_fence_step does not
#   skip and that cr_header_ends_block accepts. cr_header_scan and cr_header_canonical both call cr_header_ends_block and cr_header_fence_step, so a line
#   the lenient view counts as a header-block line is always a line the strict view reads.
#
#   The body is untrusted input (rules/security/general.md). It is read as data: no part of it is executed,
#   expanded, or used to build a command, a path, or a program.
#
# Result of cr_header_parse (parallel arrays, one entry per header line, in document order)
#   CR_HEADER_LABELS  the label as written, without the colon and the bold
#   CR_HEADER_BOLD    1 when the line carries the bold on both sides of the label, otherwise 0
#   CR_HEADER_BLOCK   1 when the line stands in the header block (before the first `## ` heading and outside a
#                     finding), otherwise 0
#   CR_HEADER_VALUES  the value, without the leading and the trailing whitespace
#   CR_HEADER_CRITICAL_HEADINGS  the number of lines that match `^#{3,6}\s+(\S+\s+)?Critical\s+[0-9]+\.` and that
#                     the line rule of cr_header_fence_step does not skip, anywhere in the body
#
# Result of cr_header_canonical (the strict view a decision that skips work relies on)
#   It reads the same block as cr_header_scan. Inside the block it accepts exactly four kinds of line:
#     - a blank line (empty once a trailing carriage return is dropped), which is skipped;
#     - one `# ` title, as the first line with text, which is skipped;
#     - a `---` line that follows a blank line or starts the body (a thematic break), which is skipped;
#     - a line of the exact shape `**<Label>:** <value>` — ASCII colon inside the bold, one space, a value with no
#       leading or trailing whitespace, and a label of ASCII letters, spaces, parentheses, and hyphens.
#   Every other line in the block is rejected, and so is every line the line rule skips there, the opening and closing
#   lines included:
#   prose, a whitespace-only line, a list item, a blockquote, a table row, an HTML comment, a deeper heading, a
#   `---` directly under a line of text (a setext underline, which turns that line into a heading), a `===` line,
#   no bold, a colon outside the bold, extra whitespace, a non-ASCII byte in a label, a Unicode look-alike colon.
#   CR_CANON_LABELS / CR_CANON_VALUES  the canonical lines, in document order
#   CR_CANON_REJECTED                  the 1-based line number of every rejected line
#
# Needs bash only; it uses no other tool, so a caller that runs without jq can use it.

CR_HEADER_LABELS=()
CR_HEADER_BOLD=()
CR_HEADER_BLOCK=()
CR_HEADER_VALUES=()
CR_HEADER_CRITICAL_HEADINGS=0

CR_FENCE_CHAR=''
CR_FENCE_LEN=0
CR_FENCE_INDENT=0
CR_HTML_END=''

# cr_header_fence_reset — start a new walk of the line rule below. Every reader calls it before its first line.
cr_header_fence_reset() {
  CR_FENCE_CHAR=''
  CR_FENCE_LEN=0
  CR_FENCE_INDENT=0
  CR_HTML_END=''
}

# cr_header_fence_step <line> — the one line rule that decides which lines a reader skips. Call it once per line, in
# order, after cr_header_fence_reset. It succeeds for a line the reader skips and fails for a line the reader reads.
#   A skipped region opens on a line, outside a skipped region and outside an HTML region, of 0–3 spaces, then three or
#   more backticks or three or more tildes; after backticks the rest of the line holds no backtick. That line and the
#   lines after it are skipped. The region closes:
#     - on a line of 0–3 spaces, then the opening character at least as many times as it opened, then only spaces or
#       tabs; that line is skipped too;
#     - before the first non-blank line that starts with fewer spaces than the opening line; that line is read;
#     - at the end of the body.
#   An HTML region opens on a line, outside a skipped region, of 0–3 spaces and `<`. Its lines are read, and no skipped
#   region opens inside it. It ends after the line that holds `-->` when the opening line starts with `<!--`; `?>` for
#   `<?`; `]]>` for `<![CDATA[`; `>` for `<!` and a letter; `</pre>`, `</script>`, `</style>`, or `</textarea>`, after
#   lowercasing, when the opening line starts with `<pre`, `<script`, `<style`, or `<textarea`, after lowercasing, and
#   then whitespace, `>`, or the end of the line; and at the next blank line after any other opening line. The end can
#   be on the opening line.
#   State: CR_FENCE_CHAR, CR_FENCE_LEN, CR_FENCE_INDENT (the skipped region), CR_HTML_END (the HTML region).
cr_header_fence_step() {
  local open_re='^( {0,3})(`{3,}|~{3,})(.*)$' close_re='^ {0,3}(`+|~+)[[:blank:]]*$' run lead
  if [[ -n "$CR_FENCE_CHAR" ]]; then
    lead="${1%%[! ]*}"
    if [[ ! "$1" =~ [^[:space:]] || ${#lead} -ge $CR_FENCE_INDENT ]]; then
      if [[ "$1" =~ $close_re ]]; then
        run="${BASH_REMATCH[1]}"
        if [[ "${run:0:1}" == "$CR_FENCE_CHAR" && ${#run} -ge $CR_FENCE_LEN ]]; then
          CR_FENCE_CHAR=''
          CR_FENCE_LEN=0
          CR_FENCE_INDENT=0
        fi
      fi
      return 0
    fi
    CR_FENCE_CHAR=''
    CR_FENCE_LEN=0
    CR_FENCE_INDENT=0
  fi
  if [[ -n "$CR_HTML_END" ]]; then
    cr_header_html_end "$1"
    return 1
  fi
  if [[ "$1" == *'<'* && "$1" =~ ^\ {0,3}\< ]]; then
    cr_header_html_open "$1"
    return 1
  fi
  [[ ( "$1" == *'```'* || "$1" == *'~~~'* ) && "$1" =~ $open_re ]] || return 1
  run="${BASH_REMATCH[2]}"
  [[ "${run:0:1}" != '`' || "${BASH_REMATCH[3]}" != *'`'* ]] || return 1
  CR_FENCE_CHAR="${run:0:1}"
  CR_FENCE_LEN=${#run}
  CR_FENCE_INDENT=${#BASH_REMATCH[1]}
  return 0
}

# cr_header_html_open <line> — the line opens an HTML region (cr_header_fence_step); set its end and test the line.
cr_header_html_open() {
  local tag="${1#"${1%%[! ]*}"}"
  case "$tag" in
  '<!--'*) CR_HTML_END='-->' ;;
  '<?'*) CR_HTML_END='?>' ;;
  '<![CDATA['*) CR_HTML_END=']]>' ;;
  '<!'[A-Za-z]*) CR_HTML_END='>' ;;
  *)
    if [[ "$tag" =~ ^\<([Pp][Rr][Ee]|[Ss][Cc][Rr][Ii][Pp][Tt]|[Ss][Tt][Yy][Ll][Ee]|[Tt][Ee][Xx][Tt][Aa][Rr][Ee][Aa])([[:space:]>]|$) ]]; then
      CR_HTML_END='raw'
    else
      CR_HTML_END='blank'
      return 0
    fi
    ;;
  esac
  cr_header_html_end "$1"
}

# cr_header_html_end <line> — end the HTML region after this line when the line holds its end.
cr_header_html_end() {
  local lower
  case "$CR_HTML_END" in
  blank) [[ "$1" =~ [^[:space:]] ]] || CR_HTML_END='' ;;
  raw)
    lower="$(printf '%s' "$1" | tr '[:upper:]' '[:lower:]')"
    [[ "$lower" != *'</pre>'* && "$lower" != *'</script>'* && "$lower" != *'</style>'* && "$lower" != *'</textarea>'* ]] \
      || CR_HTML_END=''
    ;;
  *) [[ "$1" != *"$CR_HTML_END"* ]] || CR_HTML_END='' ;;
  esac
}

# cr_header_ends_block <line> — true when <line>, a line the line rule does not skip, ends the header block: a heading
# (`## ` or `##` and a tab). The one boundary of the block; cr_header_scan and cr_header_canonical both call it.
cr_header_ends_block() {
  [[ "$1" == '##'* && "$1" =~ ^##[[:space:]] ]]
}

# cr_header_parse — read a comment body on standard input and fill the four arrays above.
cr_header_parse() {
  local i value retrim=0
  local -a trimmed=()

  CR_HEADER_LABELS=()
  CR_HEADER_BOLD=()
  CR_HEADER_BLOCK=()
  CR_HEADER_VALUES=()
  CR_HEADER_CRITICAL_HEADINGS=0

  cr_header_scan

  # The scan trims ASCII whitespace only. When a value still starts or ends with a non-ASCII byte, every value is
  # trimmed again in the caller's locale, so a non-ASCII space the locale counts as whitespace is dropped as before.
  # The values are walked with `for … in` and written to a new array, never read by index: bash 3.2 walks the whole
  # array on every indexed read. The trim leaves a value that starts and ends with an ASCII character as it is.
  [[ "$retrim" -eq 1 ]] || return 0
  i=0
  for value in "${CR_HEADER_VALUES[@]}"; do
    value="${value#"${value%%[![:space:]]*}"}"
    trimmed[i]="${value%"${value##*[![:space:]]}"}"
    i=$((i + 1))
  done
  CR_HEADER_VALUES=("${trimmed[@]}")
}

# cr_header_scan — the body is read in the C locale, byte by byte. Under a UTF-8 locale a line that carries a byte
# which is not valid UTF-8 matches no pattern at all, so its header line would be lost; the C locale also makes
# every match cheaper. Bash restores the caller's locale when the function returns. A Markdown heading and the line
# rule of cr_header_fence_step take ASCII whitespace only. The four arrays and `retrim` belong to the caller.
cr_header_scan() {
  local LC_ALL=C
  local line value count=0 in_block=1 in_finding=0 shape='^(\*\*)?([^*:]+):(\*\*)?[[:space:]]*(.*)$'
  local finding='^#{3,6}[[:space:]]+([^[:space:]]+[[:space:]]+)?(Critical|Moderate)[[:space:]]+[0-9]+\.'
  local high=$'[\x80-\xff]'

  cr_header_fence_reset
  while IFS= read -r line || [[ -n "$line" ]]; do
    line="${line%$'\r'}"
    # Each regular expression runs only on a line that can match it: a glob test is cheaper.
    ! cr_header_fence_step "$line" || continue
    if [[ "$line" == '#'* ]]; then
      if cr_header_ends_block "$line"; then
        in_block=0
        continue
      fi
      # A finding heading (`### Critical 1. <title>`) opens a finding; another deeper heading closes it, and so does
      # a `---` line. A header line inside a finding is not in the header block, as in extract-findings.sh.
      if [[ "$line" =~ $finding ]]; then
        in_finding=1
        [[ "${BASH_REMATCH[2]}" != Critical ]] || CR_HEADER_CRITICAL_HEADINGS=$((CR_HEADER_CRITICAL_HEADINGS + 1))
      elif [[ "$line" =~ ^#{3,6}[[:space:]] ]]; then
        in_finding=0
      fi
    elif [[ "$line" == '---'* && "$line" =~ ^---[[:space:]]*$ ]]; then
      in_finding=0
    fi
    if [[ "$line" == *:* && "$line" =~ $shape ]]; then
      value="${BASH_REMATCH[4]}"
      value="${value%"${value##*[![:space:]]}"}"
      # An entry is written at its index, never appended with +=: bash 3.2 walks the whole array on every
      # append, so a body with many header lines would take quadratic time.
      CR_HEADER_LABELS[count]="${BASH_REMATCH[2]}"
      if [[ -n "${BASH_REMATCH[1]}" && -n "${BASH_REMATCH[3]}" ]]; then
        CR_HEADER_BOLD[count]=1
      else
        CR_HEADER_BOLD[count]=0
      fi
      CR_HEADER_BLOCK[count]=$((in_block && !in_finding))
      CR_HEADER_VALUES[count]="$value"
      if [[ "$value" == $high* || "$value" == *$high ]]; then
        retrim=1
      fi
      count=$((count + 1))
    fi
  done
}

CR_CANON_LABELS=()
CR_CANON_VALUES=()
CR_CANON_REJECTED=()

# cr_header_canonical — read a comment body on standard input and fill the three CR_CANON_ arrays above.
cr_header_canonical() {
  local LC_ALL=C
  local line n=0 titled=0 labels=0 rejected=0 after_blank=1
  local shape='^[*][*]([A-Za-z][A-Za-z ()-]*):[*][*] ([^[:space:]](.*[^[:space:]])?)$'
  CR_CANON_LABELS=()
  CR_CANON_VALUES=()
  CR_CANON_REJECTED=()
  cr_header_fence_reset
  while IFS= read -r line || [[ -n "$line" ]]; do
    line="${line%$'\r'}"
    n=$((n + 1))
    if ! cr_header_fence_step "$line"; then
      cr_header_ends_block "$line" && break
      if [[ -z "$line" ]]; then
        after_blank=1
        continue
      fi
      if [[ "$titled" -eq 0 && "$line" =~ ^#\ [^[:space:]] ]]; then
        titled=1
        after_blank=0
        continue
      fi
      titled=1
      if [[ "$line" =~ ^---[[:space:]]*$ && "$after_blank" -eq 1 ]]; then
        after_blank=0
        continue
      fi
      after_blank=0
      if [[ "$line" =~ $shape ]]; then
        CR_CANON_LABELS[labels]="${BASH_REMATCH[1]}"
        CR_CANON_VALUES[labels]="${BASH_REMATCH[2]}"
        labels=$((labels + 1))
        continue
      fi
    fi
    # A fence line, a line inside a fence, and every other line of the block.
    titled=1
    after_blank=0
    CR_CANON_REJECTED[rejected]="$n"
    rejected=$((rejected + 1))
  done
}

cr_header_self_test() {
  local failures=0 fence tmp
  fence=$'\x60\x60\x60'
  tmp="$(mktemp -d)"
  # shellcheck disable=SC2064
  trap "rm -rf '$tmp'" EXIT

  # expect <label> <expected> <body> — the arrays, flattened to `label|bold|block|value` lines, must equal <expected>.
  expect() {
    local label="$1" expected="$2" body="$3" actual="" i
    cr_header_parse <<<"$body"
    for ((i = 0; i < ${#CR_HEADER_LABELS[@]}; i++)); do
      actual+="${CR_HEADER_LABELS[$i]}|${CR_HEADER_BOLD[$i]}|${CR_HEADER_BLOCK[$i]}|${CR_HEADER_VALUES[$i]}"$'\n'
    done
    if [[ "$actual" == "$expected" ]]; then
      printf 'ok    %s\n' "$label"
    else
      printf 'FAIL  %s: expected "%s", got "%s"\n' "$label" "$expected" "$actual" >&2
      failures=$((failures + 1))
    fi
  }

  expect 'a bold header line is read with its label, its bold, and its value' $'Status|1|1|needs-fix\n' '**Status:** needs-fix'
  expect 'a line without the bold is read, and is marked as not bold' $'Status|0|1|needs-fix\n' 'Status: needs-fix'
  expect 'a bold on one side only is not a full bold' $'Status|0|1|needs-fix\n' '**Status: needs-fix'
  expect 'a value with colons keeps them and loses the whitespace around it' $'Last updated|1|1|2026-10-01T10:00:00Z\n' $'**Last updated:**   2026-10-01T10:00:00Z \t'
  expect 'a repeated header line is reported every time' $'Status|1|1|a\nStatus|1|1|b\n' $'**Status:** a\n**Status:** b'
  expect 'the lines come out in document order' $'Counts|1|1|x\nStatus|1|1|y\n' $'**Counts:** x\n**Status:** y'
  expect 'a line without a colon is not a header line' '' $'just some prose\n# Code Review'
  expect 'an empty value is a header line with an empty value' $'Status|1|1|\n' '**Status:**'
  expect 'a trailing carriage return is dropped' $'Status|1|1|clean\n' $'**Status:** clean\r'
  expect 'a line inside a fenced code block is not read' $'After|1|1|y\n' "$(printf '%stext\n**Before:** x\n%s\n**After:** y\n' "$fence" "$fence")"
  expect 'a tilde fence is a fence too' $'After|1|1|y\n' "$(printf '~~~\n**Before:** x\n~~~\n**After:** y\n')"
  # The line rule of cr_header_fence_step.
  expect 'fence: an opener indented by four spaces is not a fence' $'Status|1|1|a\nLate|1|1|b\n' \
    "$(printf '**Status:** a\n    %s\n**Late:** b\n' "$fence")"
  expect 'fence: a backtick info string that holds a backtick is not a fence' $'Status|1|1|a\nLate|1|1|b\n' \
    "$(printf '**Status:** a\n%sx`y\n**Late:** b\n' "$fence")"
  expect 'fence: a three-backtick line inside a four-backtick fence does not close it' $'After|1|1|y\n' \
    "$(printf '%s\n%s\n**In:** x\n%s\n**After:** y\n' "$fence\`" "$fence" "$fence\`")"
  expect 'fence: a tilde info string may hold a backtick' $'After|1|1|y\n' "$(printf '~~~x`y\n**In:** x\n~~~\n**After:** y\n')"
  expect 'fence: an unclosed fence runs to the end of the body' $'Status|1|1|a\n' \
    "$(printf '**Status:** a\n%s\n**In:** x\n## TL;DR\n**Late:** y\n' "$fence")"
  expect 'fence: a closing run longer than the opener closes the fence' $'After|1|1|y\n' \
    "$(printf '%s\n**In:** x\n%s``\n**After:** y\n' "$fence" "$fence")"
  expect 'fence: a line with text after the run does not close the fence' $'After|1|1|z\n' \
    "$(printf '%s\n**In:** x\n%sx\n**Still:** y\n%s\n**After:** z\n' "$fence" "$fence" "$fence")"
  expect 'fence: a run of the other character does not close the fence' $'After|1|1|y\n' \
    "$(printf '%s\n~~~\n**In:** x\n%s\n**After:** y\n' "$fence" "$fence")"
  expect 'fence: a closer indented by three spaces closes the fence' $'After|1|1|y\n' \
    "$(printf '%s\n**In:** x\n   %s\n**After:** y\n' "$fence" "$fence")"
  expect 'fence: a closer followed by a tab closes the fence' $'After|1|1|y\n' \
    "$(printf '%s\n**In:** x\n%s\t\n**After:** y\n' "$fence" "$fence")"
  expect 'fence: a line indented less than the opener closes the fence and is read' $'After|1|1|y\n' \
    "$(printf '  %s\n  **In:** x\n\n**After:** y\n' "$fence")"
  expect 'fence: a blank line does not close an indented fence' $'After|1|1|y\n' \
    "$(printf '  %s\n\n  **In:** x\n  %s\n**After:** y\n' "$fence" "$fence")"
  expect 'fence: no fence opens inside an HTML comment' $'Late|1|1|y\n' \
    "$(printf '<!--\n%s\n-->\n\n**Late:** y\n' "$fence")"
  expect 'fence: no fence opens inside an HTML block before the blank line' $'Late|1|1|y\n' \
    "$(printf '<div>\n%s\n</div>\n\n**Late:** y\n' "$fence")"
  expect 'fence: no fence opens inside a pre block across a blank line' $'Late|1|1|y\n' \
    "$(printf '<PRE>\n\n%s\n</pre>\n\n**Late:** y\n' "$fence")"
  expect 'fence: a fence opens again after the HTML block ends' $'Late|1|1|y\n' \
    "$(printf '<div>\n\n%s\n**In:** x\n%s\n**Late:** y\n' "$fence" "$fence")"
  expect 'a line after the first section heading is reported outside the header block' $'Status|1|1|a\nLate|1|0|b\n' $'**Status:** a\n## TL;DR\n**Late:** b'
  expect 'a heading of a deeper level does not end the header block' $'Status|1|1|a\nLate|1|1|b\n' $'**Status:** a\n### Sub\n**Late:** b'
  expect 'a section heading inside a fence does not end the header block' $'Status|1|1|a\nLate|1|1|b\n' "$(printf '**Status:** a\n%s\n## quoted\n%s\n**Late:** b\n' "$fence" "$fence")"
  expect 'an empty body has no header line' '' ''
  expect 'a header line inside a finding is outside the header block' $'Status|1|1|a\nLate|1|0|b\n' $'**Status:** a\n### 🔴 Critical 1. A title\n**Late:** b'
  expect 'a finding heading without the icon opens a finding too' $'Status|1|1|a\nLate|1|0|b\n' $'**Status:** a\n#### Moderate 2. A title\n**Late:** b'
  expect 'a rule line closes the finding, and the header block goes on' $'Status|1|1|a\nIn|1|0|b\nAfter|1|1|c\n' \
    $'**Status:** a\n### Critical 1. T\n**In:** b\n---\n**After:** c'
  expect 'a deeper heading closes the finding, and the header block goes on' $'Status|1|1|a\nIn|1|0|b\nAfter|1|1|c\n' \
    $'**Status:** a\n### Critical 1. T\n**In:** b\n### Notes\n**After:** c'
  expect 'a section heading ends the header block after a closed finding too' $'In|1|0|b\nLate|1|0|c\n' \
    $'### Critical 1. T\n**In:** b\n---\n## TL;DR\n**Late:** c'
  expect 'a heading that only names a severity does not end the header block' $'Status|1|1|a\nLate|1|1|b\n' $'**Status:** a\n### Critical findings\n**Late:** b'

  # The two cases below need a UTF-8 locale: under it, a regular expression matches nothing on a line that carries
  # a byte which is not valid UTF-8. A system without a UTF-8 locale has no such failure to test.
  local utf8='' candidate
  for candidate in C.UTF-8 C.utf8 en_US.UTF-8 en_US.utf8; do
    if (LC_ALL="$candidate" && [[ $'\xc3\xa9' == ? ]]) 2>/dev/null; then
      utf8="$candidate"
      break
    fi
  done
  # expect_utf8 <label> <expected> <body> — expect, run under the UTF-8 locale.
  expect_utf8() {
    local LC_ALL="$utf8"
    expect "$@"
  }
  if [[ -n "$utf8" ]]; then
    expect_utf8 'a value with a byte that is not valid UTF-8 is read' $'Status|1|1|a\xffb\nCounts|1|1|c\n' \
      $'**Status:** a\xffb \n**Counts:** c'
    expect_utf8 'a non-ASCII space the locale counts as whitespace is trimmed' $'Status|1|1|a\n' $'**Status:**\xe2\x80\x83a\xe2\x80\x83'
  else
    printf 'skip  %s\n' 'no UTF-8 locale: the cases for a byte that is not valid UTF-8'
  fi

  # canonical <label> <expected> <body> — the canonical view, flattened to `label=value` lines and `rejected:<n>` lines.
  canonical() {
    local label="$1" expected="$2" body="$3" actual="" i
    cr_header_canonical <<<"$body"
    for ((i = 0; i < ${#CR_CANON_LABELS[@]}; i++)); do
      actual+="${CR_CANON_LABELS[$i]}=${CR_CANON_VALUES[$i]}"$'\n'
    done
    for ((i = 0; i < ${#CR_CANON_REJECTED[@]}; i++)); do
      actual+="rejected:${CR_CANON_REJECTED[$i]}"$'\n'
    done
    if [[ "$actual" == "$expected" ]]; then
      printf 'ok    %s\n' "$label"
    else
      printf 'FAIL  %s: expected "%s", got "%s"\n' "$label" "$expected" "$actual" >&2
      failures=$((failures + 1))
    fi
  }
  canonical 'a canonical header block is read line by line' $'Status=clean\nCounts=Critical 0 \xc2\xb7 Moderate 0\nLate=x\n' \
    $'# Code Review\n\n**Status:** clean\n**Counts:** Critical 0 \xc2\xb7 Moderate 0\n\n---\n\n**Late:** x'
  canonical 'a line between --- and the first section heading is read' $'Status=a\nMode=HOTFIX\n' \
    $'**Status:** a\n\n---\n**Mode:** HOTFIX\n## TL;DR'
  canonical 'a setext underline under a header line is rejected' $'Status=a\nrejected:2\n' $'**Status:** a\n---'
  canonical 'a === underline is rejected' $'Status=a\nrejected:2\n' $'**Status:** a\n==='
  canonical 'a table row is rejected' $'rejected:1\n' '| a | b |'
  canonical 'a whitespace-only line is rejected' $'rejected:1\n' '  '
  canonical 'a section heading inside a fence does not end the block' $'Mode=HOTFIX\nrejected:1\nrejected:2\nrejected:3\n' \
    "$(printf '%stext\n## Inside\n%s\n**Mode:** HOTFIX\n## TL;DR' "$fence" "$fence")"
  canonical 'a level-2 heading with a tab ends the block in both views' $'Status=a\n' $'**Status:** a\n##\tTL;DR\n**Mode:** b'
  canonical 'the block ends at the first section heading' $'Status=a\n' $'**Status:** a\n## TL;DR\n- **Mode:** b'
  canonical 'a line without the bold is rejected' $'rejected:1\n' 'Status: a'
  canonical 'a colon outside the bold is rejected' $'rejected:1\n' '**Mode**: HOTFIX'
  canonical 'a bold on one side is rejected' $'rejected:1\n' '**Status: a'
  canonical 'two spaces after the label are rejected' $'rejected:1\n' '**Status:**  a'
  canonical 'a leading space is rejected' $'rejected:1\n' ' **Status:** a'
  canonical 'trailing whitespace is rejected' $'rejected:1\n' '**Status:** a '
  canonical 'an empty value is rejected' $'rejected:1\n' '**Status:**'
  canonical 'a list item is rejected' $'rejected:1\n' '- **Mode:** HOTFIX'
  canonical 'a blockquote is rejected' $'rejected:1\n' '> **Mode:** HOTFIX'
  canonical 'an HTML comment is rejected' $'rejected:1\n' '<!-- **Mode:** HOTFIX -->'
  canonical 'a fence is rejected with every line inside it' $'rejected:1\nrejected:2\nrejected:3\n' \
    "$(printf '%stext\n**Mode:** HOTFIX\n%s' "$fence" "$fence")"
  canonical 'a full-width colon is rejected' $'rejected:1\n' $'**Counts\xef\xbc\x9a** Critical 0'
  canonical 'a zero-width space in the label is rejected' $'rejected:1\n' $'**Mo\xe2\x80\x8bde:** HOTFIX'
  canonical 'a second title is rejected' $'rejected:2\n' $'# Code Review\n# Again'
  canonical 'a heading of a deeper level is rejected' $'rejected:1\n' '### Critical 1. Early'
  canonical 'a carriage return is dropped before the check' $'Status=a\n' $'**Status:** a\r'

  local injected="\$(touch $tmp/pwned)"
  expect 'instruction-like text in a value is read as data' "Status|1|1|$injected"$'\n' "**Status:** $injected"
  if [[ -e "$tmp/pwned" ]]; then
    printf 'FAIL  %s\n' 'a value was executed' >&2
    failures=$((failures + 1))
  else
    printf 'ok    %s\n' 'a value is never executed'
  fi

  if [[ "$failures" -gt 0 ]]; then
    echo "cr-header self-test: $failures failure(s)" >&2
    return 4
  fi
  echo 'cr-header self-test: PASS'
  return 0
}

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  set -euo pipefail
  if [[ "${1:-}" == "--self-test" ]]; then
    cr_header_self_test
    exit $?
  fi
  echo 'Usage: cr-header.sh --self-test (the file is meant to be sourced)' >&2
  exit 1
fi
