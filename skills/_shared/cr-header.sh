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
# The hidden review record (cr_review_meta_wrap / cr_review_meta_unwrap)
#   On GitHub a code-review round publishes one pull-request review. Its body carries the machine text of
#   skills/code-review-github/templates/pr-comment-output.md (the record) as one hidden block, so no reader sees
#   it on the page. This file is the one owner of that encoding: the publisher wraps, every reader unwraps.
#     <!-- cr-review-meta
#     <the record, every `&` written `&amp;`, every `<` written `&lt;`, every `>` written `&gt;`>
#     -->
#   The control characters except the tab and the line feed are removed first, the carriage return included
#   (the `printable` rule of post-inline-review.sh, as extract-findings.sh applies it with `tr`). No raw `<` or `>`
#   survives inside the block, so a `-->`, a `--!>`, or a second `<!-- cr-review-meta` in the record cannot end
#   the block or open another one. The decoding turns `&lt;` and `&gt;` back first and `&amp;` last, so the pair
#   is a bijection: a literal `&lt;` in the record comes back as `&lt;`.
#   cr_review_meta_unwrap <home> reads a body on standard input:
#     comment  a legacy `cr-comment` pull-request comment or a JIRA comment: the body passes unchanged.
#     review   a pull-request review body: it must hold exactly one block, opened by a line that is exactly
#              `<!-- cr-review-meta` that the line rule of cr_header_fence_step does not skip, and closed by a line
#              that is exactly `-->`, and no
#              raw `<` or `>` inside it. It prints the decoded record, then each `<!-- cr-comment:<key>=… -->` marker
#              line of the body. Zero blocks, two blocks, an unclosed block, or a raw `<` or `>` inside the block is
#              not a record: nothing is printed and the function returns 3 (fail closed).
#
# Command line (besides --self-test)
#   cr-header.sh --report <loader-file>      the newest trusted code-review report of a pull request, unwrapped:
#                                            `newest_trusted_report` of review-trust.jq over the output of
#                                            skills/code-review-github/scripts/load-issue.sh, then the unwrap of
#                                            its home. Exit 3 when no report passes or the report is not a record.
#
# The header functions need bash only, so a caller that runs without jq can use them. The record pair also uses
# `tr` and `sed`, and --report uses jq.

CR_HEADER_LABELS=()
CR_HEADER_BOLD=()
CR_HEADER_BLOCK=()
CR_HEADER_VALUES=()
CR_HEADER_CRITICAL_HEADINGS=0

CR_FENCE_CHAR=''
CR_FENCE_LEN=0
CR_FENCE_BOUND=0
CR_LIST_OFFSET=0
CR_PREV_TEXT=0
CR_TAINT_BACKTICK=0
CR_TAINT_TILDE=0
CR_HTML_END=''
TAB=$'\t'

# The block starts that the line rule below and check-pr-body.sh both read. This file is the one owner of the patterns.
THEMATIC_BREAK='^ {0,3}(\*([[:space:]]*\*){2,}|-([[:space:]]*-){2,}|_([[:space:]]*_){2,})[[:space:]]*$'
QUOTE_START='^ {0,3}>'
ATX_ANY='^ {0,3}#{1,6}([[:space:]]|$)'
LIST_ITEM_PARSE='^( {0,3})([-*+]|[0-9]{1,9}[.)])([[:space:]]*)(.*)$'
LIST_ITEM_WITH_TEXT='^ {0,3}([-*+]|1[.)])[[:space:]]+[^[:space:]]'
SETEXT_UNDERLINE='^ {0,3}(=+|-+)[[:space:]]*$'
HTML_BLOCK_START='^ {0,3}</?(address|article|aside|base|basefont|blockquote|body|caption|center|col|colgroup|dd|details|dialog|dir|div|dl|dt|fieldset|figcaption|figure|footer|form|frame|frameset|h[1-6]|head|header|hr|html|iframe|legend|li|link|main|menu|menuitem|nav|noframes|ol|optgroup|option|p|param|search|section|summary|table|tbody|td|tfoot|th|thead|title|tr|track|ul)([[:space:]>]|/>|$)'
HTML_LONE_TAG='^ {0,3}(<[a-z][a-z0-9-]*([[:space:]]+[^<>]*)?/?>|</[a-z][a-z0-9-]*[[:space:]]*>)[[:space:]]*$'

# cr_header_fence_reset — start a new walk of the line rule below. Every reader calls it before its first line.
cr_header_fence_reset() {
  CR_FENCE_CHAR=''
  CR_FENCE_LEN=0
  CR_FENCE_BOUND=0
  CR_LIST_OFFSET=0
  CR_PREV_TEXT=0
  CR_TAINT_BACKTICK=0
  CR_TAINT_TILDE=0
  CR_HTML_END=''
}

# ci_match <text> <regex> — succeed when <text> matches <regex> with the letters of both read in either case,
# and leave the groups in BASH_REMATCH. HTML reads a tag name in either case. The match runs in the shell
# under LC_ALL=C, so a letter is one of the 52 ASCII letters, byte by byte, and no process starts for a line.
# nocasematch is set for this one match only: every other match of the check is case-sensitive.
ci_match() {
  local status=1

  shopt -s nocasematch
  [[ "$1" =~ $2 ]] && status=0
  shopt -u nocasematch
  return "$status"
}

# detab <text> — set DETABBED to <text> with each tab in its leading whitespace read as four spaces.
detab() {
  local text="$1" lead

  if [[ "$text" == *"$TAB"* ]]; then
    lead="${text%%[! "$TAB"]*}"
    text="${lead//$TAB/    }${text#"$lead"}"
  fi
  DETABBED="$text"
}

# cr_header_fence_step <line> — the one line rule that decides which lines a reader skips. Call it once per line, in
# order, after cr_header_fence_reset. It succeeds for a line the reader skips and fails for a line the reader reads.
#   A skipped region opens on a line, outside a skipped region and outside an HTML region, of 0–3 spaces, then three or
#   more backticks or three or more tildes; after backticks the rest of the line holds no backtick. That line and the
#   lines after it are skipped. The region closes:
#     - on a line of 0–3 spaces, then the opening character at least as many times as it opened, then only spaces or
#       tabs; that line is skipped too;
#     - when a list item may be open at the opening line (CR_LIST_OFFSET, below): before the first non-blank line that
#       starts with fewer spaces than the opening line; that line is read;
#     - at the end of the body.
#   When no list item is open at the opening line the region stays open whatever the indent of its lines, so a line of
#   less indent than the opening line is skipped too, and only the closing line ends the region. After a region that
#   ended on a line of less indent that still stands at the content of the open list item (so GitHub may hold the region
#   open), a bare line of the same character and at least the same length (CR_TAINT_BACKTICK, CR_TAINT_TILDE hold the
#   shortest such length) opens no region and is read: it may be the closing line of that region, which must not open
#   another that hides what follows it. A region that ended on a line less indented than every open item ended on GitHub
#   too, and leaves no such mark.
#   A list item may be open from the line of a list item until a line that GitHub cannot keep in it (CR_LIST_OFFSET is
#   the content offset of the outermost open item, 0 when none is open). The rule is built to err toward "open", because
#   an open item gives the closing rule above, which reads more lines, and a wrong "closed" would skip lines that
#   GitHub renders:
#     - a line is a list item when LIST_ITEM_PARSE matches, a space or the end of the line follows the marker, and
#       THEMATIC_BREAK does not match. Directly after paragraph text, a marker that is indented as much as the open item
#       content (or an item when none is open) is a list item only when LIST_ITEM_WITH_TEXT matches, because any
#       other item cannot interrupt a paragraph. A marker indented less than that is a sibling item, empty or not;
#     - a line ends every open item only when it starts less indented than CR_LIST_OFFSET (tabs counted as four spaces)
#       and cannot be a lazy continuation: a fence opener, an ATX heading, a thematic break, a quote, or any line
#       when the line before was not paragraph text (a blank line, a fence, an ATX heading, an empty item). A plain
#       line, a setext underline, an HTML tag line, and a backtick line that opens no fence never end an item.
#   An HTML region opens on a line, outside a skipped region, of 0–3 spaces and `<`, unless GitHub starts no HTML block
#   there (cr_header_html_open): a tag that is no block tag and not alone on its line, or alone on a line that continues
#   a paragraph, is paragraph text. Its lines are read, and no skipped region opens inside it. It ends after the line that holds `-->` when the opening line starts with `<!--`; `?>` for
#   `<?`; `]]>` for `<![CDATA[`; `>` for `<!` and a letter; `</pre>`, `</script>`, `</style>`, or `</textarea>`, after
#   lowercasing, when the opening line starts with `<pre`, `<script`, `<style`, or `<textarea`, after lowercasing, and
#   then whitespace, `>`, or the end of the line; and at the next blank line after any other opening line. The end can
#   be on the opening line.
#   State: CR_FENCE_CHAR, CR_FENCE_LEN, CR_FENCE_BOUND (the skipped region), CR_TAINT_BACKTICK, CR_TAINT_TILDE, CR_LIST_OFFSET and CR_PREV_TEXT (the open
#   list items; CR_PREV_TEXT is 0 only when the line before was certainly not paragraph text, and 2 in the paragraph of a quote), CR_HTML_END (the HTML region).
cr_header_fence_step() {
  local open_re='^( {0,3})(`{3,}|~{3,})(.*)$' close_re='^ {0,3}(`+|~+)[[:blank:]]*$' run lead='' indent=0 opener=0 prev=$CR_PREV_TEXT
  if [[ -n "$CR_FENCE_CHAR" ]]; then
    CR_PREV_TEXT=0
    [[ "$1" != *[![:space:]]* ]] || lead="${1%%[! ]*}"
    if [[ "$CR_FENCE_BOUND" -eq 0 || "$1" != *[![:space:]]* || ${#lead} -ge $CR_FENCE_BOUND ]]; then
      if [[ "$1" =~ $close_re ]]; then
        run="${BASH_REMATCH[1]}"
        if [[ "${run:0:1}" == "$CR_FENCE_CHAR" && ${#run} -ge $CR_FENCE_LEN ]]; then
          CR_FENCE_CHAR=''
          CR_FENCE_LEN=0
          CR_FENCE_BOUND=0
        fi
      fi
      return 0
    fi
    detab "$1"
    lead="${DETABBED%%[! ]*}"
    if [[ ${#lead} -ge "$CR_LIST_OFFSET" ]]; then
      if [[ "$CR_FENCE_CHAR" == '`' ]]; then
        [[ "$CR_TAINT_BACKTICK" -gt 0 && "$CR_TAINT_BACKTICK" -le "$CR_FENCE_LEN" ]] || CR_TAINT_BACKTICK=$CR_FENCE_LEN
      else
        [[ "$CR_TAINT_TILDE" -gt 0 && "$CR_TAINT_TILDE" -le "$CR_FENCE_LEN" ]] || CR_TAINT_TILDE=$CR_FENCE_LEN
      fi
    fi
    CR_FENCE_CHAR=''
    CR_FENCE_LEN=0
    CR_FENCE_BOUND=0
  fi
  if [[ -n "$CR_HTML_END" ]]; then
    CR_PREV_TEXT=0
    cr_header_html_end "$1"
    return 1
  fi
  if [[ ( "$1" == *'```'* || "$1" == *'~~~'* ) && "$1" =~ $open_re ]]; then
    run="${BASH_REMATCH[2]}"
    indent=${#BASH_REMATCH[1]}
    [[ "${run:0:1}" != '`' || "${BASH_REMATCH[3]}" != *'`'* ]] || run=''
    # A bare line of the character and the length of a region that ended on a line of less indent may be the closing line
    # of that region as GitHub reads it, so it opens no region.
    if [[ -n "$run" && -z "${BASH_REMATCH[3]//[[:blank:]]/}" ]]; then
      if [[ "${run:0:1}" == '`' ]]; then
        [[ "$CR_TAINT_BACKTICK" -eq 0 || ${#run} -lt "$CR_TAINT_BACKTICK" ]] || run=''
      else
        [[ "$CR_TAINT_TILDE" -eq 0 || ${#run} -lt "$CR_TAINT_TILDE" ]] || run=''
      fi
    fi
    [[ -z "$run" ]] || opener=1
  fi
  case "$1" in
  '') CR_PREV_TEXT=0 ;;
  # A bullet with text at indent 0 is a list item of content offset 2, unless it is a thematic break (`- - -`).
  '- '[!\ ]* | '* '[!\ ]* | '+ '[!\ ]*)
    if [[ "${1:2}" == *[!"${1:0:1} "]* ]]; then
      CR_LIST_OFFSET=2
      CR_PREV_TEXT=1
    else
      cr_header_list_step "$1" 0 "$opener"
    fi
    ;;
  [-*+0-9\>\<\`~=_#]*) cr_header_list_step "$1" 0 "$opener" ;;
  [!\ $'\t']*)
    if [[ "$CR_LIST_OFFSET" -gt 0 && "$CR_PREV_TEXT" -eq 0 ]]; then
      CR_LIST_OFFSET=0
    fi
    [[ "$CR_PREV_TEXT" -eq 2 ]] || CR_PREV_TEXT=1
    ;;
  *)
    if [[ "$1" != *[![:space:]]* ]]; then
      CR_PREV_TEXT=0
    else
      detab "$1"
      lead="${DETABBED%%[! ]*}"
      cr_header_list_step "$DETABBED" "${#lead}" "$opener"
    fi
    ;;
  esac
  if [[ "$opener" -eq 0 ]]; then
    if [[ "$1" == *'<'* && "$1" =~ ^\ {0,3}\< ]]; then
      cr_header_html_open "$1" "$prev" || :
    fi
    return 1
  fi
  CR_FENCE_CHAR="${run:0:1}"
  CR_FENCE_LEN=${#run}
  CR_FENCE_BOUND=0
  [[ "$CR_LIST_OFFSET" -eq 0 ]] || CR_FENCE_BOUND=$indent
  CR_PREV_TEXT=0
  return 0
}

# cr_header_list_step <line> <its indent> <1 when the line opens a fence> — keep CR_LIST_OFFSET and CR_PREV_TEXT for the
# line rule above. <line> is not blank, and a leading tab counts as four spaces in the indent. The patterns run only on
# a line whose first character can start a block.
cr_header_list_step() {
  local item=0 text=1 shape=0 first="${1:$2:1}" indent marker spaces rest offset
  if [[ "$first" == [-*+0-9] && "$2" -lt 4 && "$1" =~ $LIST_ITEM_PARSE && ( ${#BASH_REMATCH[3]} -gt 0 || -z "${BASH_REMATCH[4]}" ) ]]; then
    indent=${#BASH_REMATCH[1]}
    marker="${BASH_REMATCH[2]}"
    spaces=${#BASH_REMATCH[3]}
    rest="${BASH_REMATCH[4]}"
    if [[ "$marker" != [0-9]* && -n "$rest" && -z "${rest//[$marker$'\t ']/}" && "$1" =~ $THEMATIC_BREAK ]]; then
      first='thematic'
    elif [[ "$CR_PREV_TEXT" -eq 1 && "$indent" -ge "$CR_LIST_OFFSET" && ! "$1" =~ $LIST_ITEM_WITH_TEXT ]]; then
      first='text'
    else
      item=1
    fi
  fi
  if [[ "$item" -eq 1 ]]; then
    text=1
    if [[ -z "$rest" || "$spaces" -gt 4 ]]; then
      # An item that starts with an empty line, or with five spaces (an indented code block), holds no paragraph yet.
      text=0
      spaces=1
    fi
    offset=$((indent + ${#marker} + spaces))
    if [[ "$CR_LIST_OFFSET" -eq 0 || "$indent" -lt "$CR_LIST_OFFSET" ]]; then
      CR_LIST_OFFSET=$offset
    fi
    CR_PREV_TEXT=$text
    return 0
  fi
  if [[ "$3" -eq 1 || "$first" == 'thematic' ]]; then
    shape=1
  elif [[ "$first" == '<' && "$2" -lt 4 ]]; then
    ! cr_header_html_starts "$1" "$CR_PREV_TEXT" || shape=1
  elif [[ "$2" -lt 4 ]]; then
    case "$first" in
    '>' | '#' | '_' | '-' | '*')
      if [[ "$1" =~ $QUOTE_START || "$1" =~ $ATX_ANY || "$1" =~ $THEMATIC_BREAK ]]; then
        shape=1
      fi
      ;;
    esac
  fi
  if [[ ( "$shape" -eq 1 || "$CR_PREV_TEXT" -eq 0 ) && "$2" -lt "$CR_LIST_OFFSET" ]]; then
    CR_LIST_OFFSET=0
  fi
  if [[ "$2" -lt 4 && "$1" =~ $QUOTE_START ]]; then
    # The paragraph of a quote is no paragraph that a list item interrupts: GitHub starts any item after it. A quote whose
    # line is empty or holds a fence, a heading, or a thematic break has no paragraph that a later line continues.
    rest="${1#*>}"
    rest="${rest# }"
    text=2
    if [[ "$rest" != *[![:space:]]* || "$rest" == '```'* || "$rest" == '~~~'* || "$rest" == '#'* || "$rest" == '>'* ]] || [[ "$rest" =~ $THEMATIC_BREAK ]]; then
      text=0
    fi
  elif [[ "$shape" -eq 1 ]]; then
    text=0
  elif [[ "$CR_LIST_OFFSET" -gt 0 ]]; then
    # Inside an open item every other line is text, so a line after it is a lazy continuation.
    text=1
  elif [[ "$CR_PREV_TEXT" -eq 1 && "$2" -lt 4 && "$1" =~ $SETEXT_UNDERLINE ]]; then
    text=0
  elif [[ "$2" -ge 4 && "$CR_PREV_TEXT" -eq 0 ]]; then
    text=0
  fi
  [[ "$text" -ne 1 || "$CR_PREV_TEXT" -ne 2 || "$shape" -eq 1 ]] || text=2
  CR_PREV_TEXT=$text
}

# cr_header_html_starts <line> <1 when the line before was paragraph text> — succeed when GitHub starts an HTML block at
# <line>, which starts with 0–3 spaces and `<`: a comment, an instruction, a CDATA section, a declaration, a raw tag
# (`pre`, `script`, `style`, `textarea`), a block tag, or a tag alone on a line that does not continue a paragraph.
# Any other line that starts with a tag is paragraph text.
cr_header_html_starts() {
  local tag="${1#"${1%%[! ]*}"}"
  case "$tag" in
  '<!--'* | '<?'* | '<![CDATA['* | '<!'[A-Za-z]*) return 0 ;;
  esac
  [[ "$tag" =~ ^\<([Pp][Rr][Ee]|[Ss][Cc][Rr][Ii][Pp][Tt]|[Ss][Tt][Yy][Ll][Ee]|[Tt][Ee][Xx][Tt][Aa][Rr][Ee][Aa])([[:space:]>]|$) ]] && return 0
  ci_match "$1" "$HTML_BLOCK_START" && return 0
  [[ "${2:-0}" -ne 1 ]] && ci_match "$1" "$HTML_LONE_TAG" && return 0
  return 1
}

# cr_header_html_open <line> <1 when the line before was paragraph text> — the line opens an HTML region
# (cr_header_fence_step); set its end and test the line. It fails, and opens no region, for a line at which GitHub starts
# no HTML block (cr_header_html_starts).
cr_header_html_open() {
  local tag="${1#"${1%%[! ]*}"}"
  cr_header_html_starts "$1" "${2:-0}" || return 1
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

CR_REVIEW_META_OPEN='<!-- cr-review-meta'
CR_REVIEW_META_CLOSE='-->'

# cr_review_meta_wrap — read a record on standard input and print it as the hidden block of a review body.
# The trailing line feeds of the record are dropped, so the block always ends with one line feed before `-->`.
cr_review_meta_wrap() {
  local encoded
  encoded="$(LC_ALL=C tr -d '\000-\010\013-\037\177' | LC_ALL=C sed -e 's/&/\&amp;/g' -e 's/</\&lt;/g' -e 's/>/\&gt;/g')"
  printf '%s\n%s\n%s\n' "$CR_REVIEW_META_OPEN" "$encoded" "$CR_REVIEW_META_CLOSE"
}

# cr_review_meta_unwrap <review|comment> — the record of one body (see the header of this file).
cr_review_meta_unwrap() {
  local LC_ALL=C
  local home="${1:-}" line inside=0 blocks=0 record='' markers='' marker_re='^<!-- cr-comment:[a-z]+=[^<>]* -->$'
  case "$home" in
  comment)
    cat
    return 0
    ;;
  review) ;;
  *) return 1 ;;
  esac
  cr_header_fence_reset
  while IFS= read -r line || [[ -n "$line" ]]; do
    line="${line%$'\r'}"
    if [[ "$inside" -eq 1 ]]; then
      if [[ "$line" == "$CR_REVIEW_META_CLOSE" ]]; then
        inside=0
        cr_header_fence_reset
      elif [[ "$line" == *'<'* || "$line" == *'>'* ]]; then
        return 3
      else
        record+="$line"$'\n'
      fi
      continue
    fi
    ! cr_header_fence_step "$line" || continue
    if [[ "$line" == "$CR_REVIEW_META_OPEN" ]]; then
      blocks=$((blocks + 1))
      inside=1
    elif [[ "$line" =~ $marker_re ]]; then
      markers+="$line"$'\n'
    fi
  done
  [[ "$inside" -eq 0 && "$blocks" -eq 1 ]] || return 3
  printf '%s' "$record" | sed -e 's/&lt;/</g' -e 's/&gt;/>/g' -e 's/&amp;/\&/g'
  [[ -z "$markers" ]] || printf '\n%s' "$markers"
}

# cr_review_meta_report <loader-file> — the newest trusted report of a pull request, unwrapped (--report).
cr_review_meta_report() {
  local here entry home
  here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
  [[ -r "${1:-}" ]] || return 1
  entry="$(jq -c -L "$here" 'include "review-trust"; newest_trusted_report' "$1" 2>/dev/null)" || return 3
  [[ -n "$entry" ]] || return 3
  home="$(jq -r '.home' <<<"$entry")"
  jq -j '.body' <<<"$entry" | cr_review_meta_unwrap "$home"
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

  # reads <label> <body> — the line rule reads the last line of <body>, a probe that GitHub renders and no reader may skip.
  reads() {
    local label="$1" l verdict=skipped
    cr_header_fence_reset
    while IFS= read -r l; do
      if cr_header_fence_step "$l"; then verdict='skipped'; else verdict='read'; fi
    done <<<"$2"
    if [[ "$verdict" == 'read' ]]; then
      printf 'ok    %s\n' "$label"
    else
      printf 'FAIL  %s: the last line was skipped\n' "$label" >&2
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
  expect 'fence: a line indented less than the opener stays inside the fence' $'After|1|1|y\n' \
    "$(printf '  %s\n**In:** x\n  %s\n**After:** y\n' "$fence" "$fence")"
  expect 'fence: an unindented line inside an indented fence is quoted, and the closer ends the fence' $'After|1|1|y\n' \
    "$(printf ' %s\n  **In:** x\n**Mid:** x\n %s\n**After:** y\n' "$fence" "$fence")"
  expect 'fence: an indented closer opens no fence, so a header line after it is read' $'After|1|1|y\n' \
    "$(printf '  %s\nx\n  %s\n**After:** y\n  %s\n' "$fence" "$fence" "$fence")"
  expect 'fence: the end of a list item closes the fence of that item' $'After|1|1|y\n' \
    "$(printf -- '- item\n\n  %s\n  **In:** x\n**After:** y\n' "$fence")"
  expect 'fence: a line indented as the list item content stays inside its fence' $'After|1|1|y\n' \
    "$(printf -- '1. item\n\n   %s\n   **In:** x\n   %s\n**After:** y\n' "$fence" "$fence")"
  expect 'fence: a thematic break is not a list item, so its fence has no bound' $'After|1|1|y\n' \
    "$(printf -- '- - -\n\n  %s\n**In:** x\n  %s\n**After:** y\n' "$fence" "$fence")"
  expect 'fence: a setext dash underline is not a list item, so its fence has no bound' $'After|1|1|y\n' \
    "$(printf -- 'text\n-\n\n  %s\n**In:** x\n  %s\n**After:** y\n' "$fence" "$fence")"
  expect 'fence: an ordered marker above 1 after paragraph text is not a list item, so its fence has no bound' $'After|1|1|y\n' \
    "$(printf -- 'text\n2. foo\n\n   %s\n**In:** x\n   %s\n**After:** y\n' "$fence" "$fence")"
  expect 'fence: a lazy continuation line keeps the list item of its fence' $'After|1|1|y\n' \
    "$(printf -- '- item\nlazy\n  %s\ncode\n\n**After:** y\n' "$fence")"
  expect 'fence: a nested bullet item leaves the outer item as the bound of a fence' $'After|1|1|y\n' \
    "$(printf -- '- a\n  - b\n\n  %s\ncode\n\n**After:** y\n' "$fence")"
  expect 'fence: a nested bullet item under an ordered item leaves the outer item as the bound of a fence' $'After|1|1|y\n' \
    "$(printf -- '1. a\n   - b\n\n   %s\ncode\n\n**After:** y\n' "$fence")"
  # Lines that GitHub keeps inside an open list item never end it for the rule, so the fence of the item keeps its closing
  # rule and the probe after the item is read.
  reads 'fence: an empty sibling item after an item with text keeps the list item of the fence' \
    "$(printf -- '-  two\n-\n  %s\ncode\n  ## X' "$fence")"
  reads 'fence: an empty sibling item after an item with indented content keeps the list item of the fence' \
    "$(printf -- '*\n   code\n-\n   %s\nx\n  ## X' "$fence")"
  reads 'fence: a fence on the marker line is bound by its item' "$(printf -- '- %s\n   %s\n  ## X' "$fence" "$fence")"
  reads 'fence: a tab-indented line inside an empty item keeps the list item of the fence' \
    "$(printf -- '-\n\tx\n  %s\ncode\n## X' "$fence")"
  reads 'fence: a tab-indented line after a blank line in an item keeps the list item of the fence' \
    "$(printf -- '- a\n\n\tb\n\n  %s\ncode\n## X' "$fence")"
  reads 'fence: an ordered item above 1 after a quoted fence is a list item' \
    "$(printf -- '> %s\n2. foo\n   %s\nx\n## X' "$fence" "$fence")"
  reads 'fence: a === line in an item is a lazy line' "$(printf -- '* x\n===\n   %s\nx\n## X' "$fence")"
  reads 'fence: a = line in an item is a lazy line' "$(printf -- '* x\n=\n   %s\nx\n## X' "$fence")"
  reads 'fence: a tag alone on a line is a lazy line of an item' "$(printf -- '- a\n<span>x</span>\n\n  %s\ncode\n## X' "$fence")"
  reads 'fence: a backtick line that opens no fence is a lazy line of an item' \
    "$(printf -- '- a\n%sx`y\n  %s\ncode\n## X' "$fence" "$fence")"
  reads 'fence: a paragraph line indented by four spaces in an item is text, so a lazy line follows it' \
    "$(printf -- '- a\n\n    b\nc\n  %s\ncode\n## X' "$fence")"
  reads 'fence: an item after the paragraph of a quote is a list item' \
    "$(printf -- '> q\nlazy\n*\n   %s\n\n  code\nx\n## X' "$fence")"
  reads 'fence: a tag that continues a paragraph opens no HTML region' \
    "$(printf -- '<span>x</span>\n* x\n   %s\n\n  %s\n## X' "$fence" "$fence")"
  # A line that GitHub keeps as paragraph text never makes the rule read a fence as it did before the closing-fence fix,
  # and a region that ended early leaves no closing line that opens a region of its own.
  reads 'fence: an inline tag line then an indented fence, its closer, and a heading read the heading' \
    "$(printf -- 'para\n<span>x</span>\n\n  %s\ncode\n  %s\n  ## SENT' "$fence" "$fence")"
  reads 'fence: an inline tag with text after it, an indented fence, its closer, and a heading read the heading' \
    "$(printf -- 'para\n<b>x</b> more\n\n  %s\ncode\n  %s\n  ## SENT' "$fence" "$fence")"
  reads 'fence: a fence indented more than its list item, its closer, and a heading read the heading' \
    "$(printf -- '- a\n\n   %s\n  x\n   %s\n   ## SENT' "$fence" "$fence")"
  reads 'fence: an unindented fence after an inline tag line, its closer, and a heading read the heading' \
    "$(printf -- 'para\n<b>x</b> more\n%s\ncode\n%s\n  ## SENT' "$fence" "$fence")"
  reads 'fence: a list item that ends a fence on less indent leaves a later bare fence an opener' \
    "$(printf -- '* x\n  ~~~\ntext\n\n~~~\ncode\n~~~\n  ## SENT')"
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

  # The hidden review record: a round trip, the strict unwrap, and the newest trusted report.
  # meta <label> <expected-exit> <expected-output> <home> <body>
  meta() {
    local label="$1" want_rc="$2" want="$3" home="$4" body="$5" got rc=0
    got="$(
      printf '%s' "$body" | cr_review_meta_unwrap "$home"
      printf '|%s' "$?"
    )"
    rc="${got##*|}"
    got="${got%|*}"
    if [[ "$rc" -eq "$want_rc" && "$got" == "$want" ]]; then
      printf 'ok    %s\n' "$label"
    else
      printf 'FAIL  %s: expected exit %s and "%s", got exit %s and "%s"\n' "$label" "$want_rc" "$want" "$rc" "$got" >&2
      failures=$((failures + 1))
    fi
  }
  local record wrapped
  record=$'**Status:** clean\n**Not verified:** a --&gt; b · c --!> d · e &amp;lt; f · <!-- g --> · a\ttab\n- **Faulty Example:** `if ($a < $b && $c > $d)`'
  wrapped="$(printf '%s' "$record" | cr_review_meta_wrap)"
  meta 'review meta: a record with --&gt;, --!>, &amp;lt;, <!--, and --> comes back byte for byte' 0 "$record"$'\n' review \
    "$(printf 'visible line\n\n%s\n' "$wrapped")"
  local inner
  inner="$(printf '%s\n' "$wrapped" | sed '1d;$d')"
  if [[ -n "$inner" && "$inner" != *'<'* && "$inner" != *'>'* ]]; then
    printf 'ok    %s\n' 'review meta: the wrapped block holds no raw < or > of the record'
  else
    printf 'FAIL  %s\n' 'review meta: the wrapped block holds a raw < or > of the record' >&2
    failures=$((failures + 1))
  fi
  meta 'review meta: a carriage return, NUL-free controls, and a bell are removed by the wrap' 0 $'a b\n' review \
    "$(printf 'a\r\a b' | cr_review_meta_wrap)"
  meta 'review meta: the cr-comment markers follow the record' 0 $'**Status:** clean\n\n<!-- cr-comment:lines=ab12 -->\n<!-- cr-comment:actor=bot -->\n' review \
    "$(printf '%s\n\n<!-- cr-comment:lines=ab12 -->\n<!-- cr-comment:actor=bot -->\n' "$(printf '**Status:** clean' | cr_review_meta_wrap)")"
  meta 'review meta: a legacy comment passes unchanged' 0 $'**Status:** clean\n<!-- cr-comment:actor=bot -->' comment \
    $'**Status:** clean\n<!-- cr-comment:actor=bot -->'
  meta 'review meta: a review body without a block is not a record' 3 '' review $'**Status:** clean\n<!-- cr-comment:actor=bot -->'
  meta 'review meta: two blocks are not a record' 3 '' review "$(printf '%s\n%s\n' "$wrapped" "$wrapped")"
  meta 'review meta: an unclosed block is not a record' 3 '' review $'<!-- cr-review-meta\n**Status:** clean\n'
  meta 'review meta: a raw < inside the block is not a record' 3 '' review $'<!-- cr-review-meta\n<b>x</b>\n-->\n'
  meta 'review meta: an opening line inside a fence is quoted, not a block' 3 '' review \
    "$(printf '%s\n<!-- cr-review-meta\n**Status:** clean\n-->\n%s\n' "$fence" "$fence")"
  meta 'review meta: an opening line with text after it is not a block' 3 '' review $'<!-- cr-review-meta x\n**Status:** clean\n-->\n'
  meta 'review meta: an unknown home is refused' 1 '' other 'x'

  local loader="$tmp/loader.json" report rc
  jq -n -c --arg legacy $'**Status:** needs-fix\n<!-- cr-comment:actor=bot -->' --arg review "$(printf '%s\n\n<!-- cr-comment:actor=bot -->\n' "$(printf '**Status:** clean' | cr_review_meta_wrap)")" \
    '{kind: "pr", comments: [{author: "bot", authorAssociation: "OWNER", body: $legacy, createdAt: "2026-11-01T10:00:00Z"}],
      reviews: [{author: "bot", authorAssociation: "OWNER", state: "COMMENTED", body: $review, submittedAt: "2026-11-02T10:00:00Z"},
                {author: "bot", authorAssociation: "OWNER", state: "PENDING", body: $review, submittedAt: null},
                {author: "mallory", authorAssociation: "NONE", state: "COMMENTED", body: $review, submittedAt: "2026-11-03T10:00:00Z"}]}' >"$loader"
  report="$(cr_review_meta_report "$loader")" || true
  if [[ "$report" == $'**Status:** clean\n\n<!-- cr-comment:actor=bot -->' ]]; then
    printf 'ok    %s\n' 'review meta: the newest trusted report is the review record, and a stranger or a pending review is not read'
  else
    printf 'FAIL  %s: got "%s"\n' 'review meta: the newest trusted report is the review record' "$report" >&2
    failures=$((failures + 1))
  fi
  jq -c '.reviews = []' "$loader" >"$tmp/legacy.json"
  report="$(cr_review_meta_report "$tmp/legacy.json")" || true
  if [[ "$report" == $'**Status:** needs-fix\n<!-- cr-comment:actor=bot -->' ]]; then
    printf 'ok    %s\n' 'review meta: a pull request with only a legacy cr-comment still yields that comment'
  else
    printf 'FAIL  %s: got "%s"\n' 'review meta: a legacy cr-comment is still read' "$report" >&2
    failures=$((failures + 1))
  fi
  jq -c '.comments = [] | .reviews = [.reviews[2]]' "$loader" >"$tmp/none.json"
  rc=0
  cr_review_meta_report "$tmp/none.json" >/dev/null || rc=$?
  if [[ "$rc" -eq 3 ]]; then
    printf 'ok    %s\n' 'review meta: no trusted report exits 3'
  else
    printf 'FAIL  %s: got exit %s\n' 'review meta: no trusted report exits 3' "$rc" >&2
    failures=$((failures + 1))
  fi

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
  case "${1:-}" in
  --self-test)
    cr_header_self_test
    exit $?
    ;;
  --report)
    [[ -r "${2:-}" ]] || { echo 'Usage: cr-header.sh --report <loader-file>' >&2; exit 1; }
    cr_review_meta_report "$2" || { echo 'cr-header.sh: no trusted code-review report, or the newest one is not a record' >&2; exit 3; }
    exit 0
    ;;
  esac
  echo 'Usage: cr-header.sh --self-test | --report <loader-file> (the file is meant to be sourced)' >&2
  exit 1
fi
