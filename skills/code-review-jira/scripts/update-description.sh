#!/usr/bin/env bash
# update-description.sh — rewrite the description of one existing JIRA issue into the collapsed shape
# of rules/compound-engineering/assignment.md *The shape in a JIRA description*, keep the previous
# description word for word in its *Original assignment* block, and prove that the write landed.
#
# Only the top-level session runs it, and only when the operator's own request in this run asks for the
# rewrite (L2, rules/compound-engineering/orchestration.md *Externally-visible actions & consent levels*).
# No agent's Bash boundary grants it.
#
# Usage:
#   update-description.sh fingerprint <KEY|URL>
#   update-description.sh write <KEY|URL> <en|cs> <FINGERPRINT> <WIKI-FILE|->
#   update-description.sh --self-test
#
#   fingerprint  Print `fingerprint=<sha256>` of the current description. The caller runs it when it
#                reads the description it rewrites, and passes the value to `write`.
#   write        Write the new text (the Wiki Markup subset of rules/jira/general.md) as the new
#                description, followed by the *Original assignment* block in the tracker language.
#
# Inputs:
#   KEY|URL      One key that matches ^[A-Z][A-Z0-9_]+-[0-9]+$, or https://<host>/browse/<KEY> whose
#                host equals the `site` of `acli jira auth status`. A list of keys is refused.
#   en|cs        The tracker language. It selects the fixed section titles and the original title.
#   FINGERPRINT  The value `fingerprint` printed for the version the rewrite was made from.
#
# What `write` checks before it writes anything:
#   - The new text is one opening paragraph and then only collapsed sections whose titles come from the
#     fixed list of the language, in its order, each once and each with content. It holds no heading,
#     code, mention, link, smart link, URL written as text, media, task list, decision list, layout, or
#     nested section, and at least one collapsed section. The URL test runs on the joined text of each
#     block (every text node is a direct child of a block), so a mark inside a URL does not hide it. It
#     runs on a copy of the converted document in which every string is in Unicode form NFKC with case
#     folding (normalize-adf.php), so a compatibility form reads like its plain form: circled letters
#     or digits, a small full stop or at sign, a full-width scheme, colon, or slash, and the invisible
#     default-ignorable characters. The original document is the one that is written. Without the PHP
#     `Normalizer` form for case-folded NFKC nothing is written and the exit code is 3. The test also
#     removes every remaining format character (Unicode class Cf). It fails closed, because whether the
#     site renders a scheme-less form as a link is not settled. It refuses a scheme (`https://`),
#     `mailto:`, `www` followed by a dot, any host-like token (a character that is no space and no dot,
#     a dot, then two letters or marks that start with a letter or a mark: `☃.com`, `x_evil.com`,
#     `evil.भारत`, `evil.` and a mark and `com`), whatever follows it (nothing, `/path`, `:8080`, `?x=1`,
#     `#x`), an e-mail address (a letter, mark, digit, or one of `._%+-`, then `@`, then a letter or
#     digit), and an IPv4 address (four dotted groups of one to three digits, with or without a path or
#     port). A dot is `.` or U+3002 after the normalisation, and a digit is any Unicode decimal digit or
#     other number. A new text over 65536 bytes is refused with exit 1 before it is converted, and the
#     empty-text check is one linear pattern match. Three false refusals are accepted: a name with a dot
#     such as `Node.js` or `readme.md`, a sentence that ends in a period with no space after it
#     (`Done.Next`), and a four-part version such as `1.2.3.4`. A business-only text carries none of
#     them (`rules/compound-engineering/assignment.md`). Not refused: `v2.0`, `1.2.3`, `10.5`, `4.5/5`,
#     `e.g.`, `i.e.`, `a/nebo`, `24.11.2026`, `wait...and then`, `the .NET export`, `20 m²`, `20 °C`,
#     `krok ① a ②`, and a single-label host such as `localhost`. An IPv6 address is not tested.
#   - The description still has the given fingerprint, read once at the start and once just before the
#     write. A different fingerprint writes nothing.
#   - The previous description holds no top-level *Original assignment* block: all its top-level nodes
#     become the content of the new block. It holds exactly one, as its last node, and everything before
#     it is the shape this script writes (one opening paragraph, then collapsed sections with titles of
#     the fixed list of one language, per description-shape.jq): that block's content is kept unchanged
#     and is never wrapped a second time, and the opening and the sections before it are replaced by the
#     new text. Any other count, position, or shape writes nothing.
#     A description already in the collapsed shape without that block writes nothing.
#   - Every node copied into the block is a node an ADF `expand` accepts. Otherwise nothing is written
#     and the message names the node type. A node is never converted or flattened.
#
# The write is `acli jira workitem edit --key <KEY> --description-file <file> --yes`, and nothing else.
# The read-back goes through load-issue.sh: the rendered original must equal the rendered previous
# description, and the assignment text must open with the new opening paragraph. On a mismatch the
# script reads the description once more. Only when that is still the document the script wrote does it
# write the saved previous description once, read it back, and exit 4. Otherwise it writes nothing more,
# prints the saved previous description to stdout as canonical ADF (`jq -S -c`) so the operator can
# restore it by hand, and exits 4. The edit of acli has no conditional update, so a change made between
# that last read and the write-back is not detected.
#
# Audit trail:
#   Each write (the rewrite and a rollback) appends its own line through
#   skills/_shared/audit-trail.sh record, with the real exit status (AUDIT_TRAIL_FILE, AUDIT_TRAIL_ROLE).
#
# Exit codes:
#   0  the description was rewritten and read back
#   1  usage error, a refused key or URL, an empty new text, or a new text over 65536 bytes
#   2  missing required tool (acli, jq, php)
#   3  an acli read or write failed, or the converter or normaliser failed (including unavailable case-folded NFKC)
#   4  the read-back did not match; the previous description was written back, or nothing was written
#      back (the description is no longer the document this script wrote, or it could not be read
#      again) and the previous description is on stdout as canonical ADF
#   5  the shape was refused (the new text or the previous description); nothing was written
#   6  the description changed since the fingerprint was taken; nothing was written
set -euo pipefail

PROG="${0##*/}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
AUDIT_TRAIL="$SCRIPT_DIR/../../_shared/audit-trail.sh"
LOADER="$SCRIPT_DIR/load-issue.sh"
CONVERTER="$SCRIPT_DIR/wiki-markup-to-adf.php"
NORMALIZER="$SCRIPT_DIR/normalize-adf.php"
MAX_TEXT_BYTES=65536

# shellcheck source=jira-actor.sh
source "$SCRIPT_DIR/jira-actor.sh"

# The fixed titles and the test for the preserved original live in description-shape.jq, which
# load-issue.sh includes too, so the writer and the reader share one definition.
# shellcheck disable=SC2016  # a jq program, expanded by jq, never by the shell
SHAPE_JQ='include "description-shape";
'

usage() {
  cat >&2 <<'EOF'
Usage: update-description.sh fingerprint <KEY|URL>
       update-description.sh write <KEY|URL> <en|cs> <FINGERPRINT> <WIKI-FILE|->
       update-description.sh --self-test
EOF
}

die() {
  local code="$1"
  shift
  echo "$PROG: $*" >&2
  exit "$code"
}

need() {
  command -v "$1" >/dev/null 2>&1 || die 2 "required tool not found: $1"
}

# resolve_key <KEY|URL> — print the key, or exit 1.
resolve_key() {
  local input="$1" host key site

  if [[ "$input" =~ ^[A-Z][A-Z0-9_]+-[0-9]+$ ]]; then
    printf '%s' "$input"
    return 0
  fi
  if [[ "$input" =~ ^https://([a-z0-9.-]+)/browse/([A-Z][A-Z0-9_]+-[0-9]+)$ ]]; then
    host="${BASH_REMATCH[1]}"
    key="${BASH_REMATCH[2]}"
    site="$(jira_auth_status_field "$(acli jira auth status 2>/dev/null || true)" site | tr '[:upper:]' '[:lower:]')"
    site="${site#https://}"
    site="${site%%/*}"
    [[ -n "$site" && "$host" == "$site" ]] || die 1 "refused — the URL host is not the JIRA site of the acli account"
    printf '%s' "$key"
    return 0
  fi
  die 1 "refused — pass one issue key (ACME-1) or one /browse/<KEY> URL of the acli site"
}

# read_description <KEY> — print the description as canonical JSON (null when it has none).
read_description() {
  local raw
  raw="$(acli jira workitem view "$1" --fields description --json 2>/dev/null)" || return 3
  printf '%s' "$raw" | jq -S -c '.fields.description // null' 2>/dev/null || return 3
}

fingerprint_of() {
  printf '%s' "$1" | php -r 'echo hash("sha256", (string) stream_get_contents(STDIN));'
}

# previous_adf <canonical-description-JSON> — print the description as canonical ADF (an empty document for none).
previous_adf() {
  printf '%s' "$1" | jq -S -c 'if . == null then { version: 1, type: "doc", content: [] } else . end'
}

# write_description <KEY> <ADF-file> <what> — run the one sanctioned edit and record its audit line.
write_description() {
  local rc=0
  acli jira workitem edit --key "$1" --description-file "$2" --yes >/dev/null 2>&1 || rc=$?
  bash "$AUDIT_TRAIL" record --target "acli jira workitem edit --key $1 --description-file" --status "$rc" --detail "$3" || true
  return "$rc"
}

cmd_fingerprint() {
  [[ $# -eq 1 ]] || { usage; exit 1; }
  need acli
  need jq
  need php
  local key desc
  key="$(resolve_key "$1")"
  desc="$(read_description "$key")" || die 3 "failed to read the description of $key"
  printf 'fingerprint=%s\n' "$(fingerprint_of "$desc")"
}

cmd_write() {
  [[ $# -eq 4 ]] || { usage; exit 1; }
  local input="$1" lang="$2" expected="$3" source="$4"
  [[ "$lang" == "en" || "$lang" == "cs" ]] || die 1 "the language must be en or cs"
  [[ "$expected" =~ ^[0-9a-f]{64}$ ]] || die 1 "the fingerprint must be the 64 hex characters that \`fingerprint\` printed"
  need acli
  need jq
  need php

  local key text tmp new norm problems prev plan mode pre post opening expected_original title doc_file
  key="$(resolve_key "$input")"
  if [[ "$source" == "-" ]]; then
    text="$(cat)"
  elif [[ -f "$source" ]]; then
    text="$(cat "$source")"
  else
    die 1 "the text file was not found: $source"
  fi
  # The size check comes first and the empty check is one linear pattern match: a substitution over a
  # long text is quadratic in the bash 3.2 of macOS.
  (($(printf '%s' "$text" | wc -c) <= MAX_TEXT_BYTES)) || die 1 "refused — the new text is longer than $MAX_TEXT_BYTES bytes"
  [[ "$text" =~ [^[:space:]] ]] || die 1 "refused — the new text is empty"

  tmp="$(mktemp -d)"
  # shellcheck disable=SC2064
  trap "rm -rf '$tmp'" EXIT

  new="$(printf '%s' "$text" | php "$CONVERTER")" || die 3 "failed to convert the new text to ADF; nothing was written"
  # The URL test runs on a copy of the document with every string in NFKC with case folding, so a host,
  # an at sign, a digit, or a scheme written in a compatibility form reads like its plain form. The
  # original document is the one that is written. Without case-folded NFKC nothing is written.
  norm="$(printf '%s' "$new" | php "$NORMALIZER")" || die 3 "failed to normalise the converted text (the intl extension of php is required); nothing was written"

  problems="$(printf '%s' "$new" | jq -r -L "$SCRIPT_DIR" --arg lang "$lang" "$SHAPE_JQ"'
    .content | assignmentProblems($lang)')" || die 3 "failed to read the converted text"
  # shellcheck disable=SC2016  # a jq program
  link_problem="$(printf '%s' "$norm" | jq -r '
    if ([.. | objects | select((.content? // null) | type == "array")
         | [.content[] | select(type == "object") | if (.type // "") == "text" then (.text // "") elif (.type // "") == "hardBreak" then "\n" else empty end] | join("")
         | gsub("\\p{Cf}"; "")
         | select(test("([a-z][a-z0-9+.-]*://|mailto:|www[.\u3002]|[^\\s.\u3002][.\u3002][\\p{L}\\p{M}][\\p{L}\\p{M}]|[\\p{L}\\p{M}\\p{N}._%+-]@[\\p{L}\\p{N}]|[\\p{Nd}\\p{No}]{1,3}([.\u3002][\\p{Nd}\\p{No}]{1,3}){3})"; "i"))] | length) > 0
    then "a link written as text" else empty end')" || die 3 "failed to read the normalised text"
  if [[ -n "$link_problem" ]]; then
    problems="${problems:+$problems$'\n'}$link_problem"
  fi
  if [[ -n "$problems" ]]; then
    printf '%s\n' "$problems" | sed "s#^#$PROG: refused — #" >&2
    exit 5
  fi

  prev="$(read_description "$key")" || die 3 "failed to read the description of $key"
  [[ "$(fingerprint_of "$prev")" == "$expected" ]] || die 6 "the description of $key changed since the fingerprint was taken; nothing was written"

  plan="$(printf '%s' "$prev" | jq -c -L "$SCRIPT_DIR" "$SHAPE_JQ"'
    def allowed: ["bulletList", "blockquote", "codeBlock", "heading", "mediaGroup", "mediaSingle", "orderedList",
                  "panel", "paragraph", "rule", "table", "multiBodiedExtension", "extensionFrame", "nestedExpand"];
    (if type == "object" then (.content // []) else [] end) as $n
    | [$n | to_entries[] | select(.value | isOriginal) | .key] as $oi
    | (if ($oi | length) == 0 then { mode: (if ($n | length) == 0 then "none" else "created" end), nodes: $n }
       elif ($oi | length) == 1 and $oi[0] == (($n | length) - 1) then
         (if ($n | hasWrittenShape) then { mode: "kept", nodes: ($n[-1].content // []) }
          else { error: "the previous description ends with an Original assignment block, but what precedes it is not the opening paragraph and the collapsed sections this script writes" } end)
       else { error: "the previous description carries an Original assignment block that is not its one last node" } end) as $p
    | if $p.error then $p
      elif $p.mode == "created" and ([$n[] | select((.type // "") == "expand" and ((.attrs.title // "") as $t | allSectionTitles | index($t) != null))] | length) > 0
      then { error: "the previous description is already in the collapsed shape and has no Original assignment block" }
      else ([$p.nodes[] | (.type // "?") | select(. as $t | allowed | index($t) == null)] | unique) as $bad
      | if ($bad | length) > 0 then { error: ("the previous description holds a node a collapsed block cannot keep unchanged: " + ($bad | join(", "))) }
        else $p end
      end')" || die 3 "failed to read the previous description"
  if [[ "$(printf '%s' "$plan" | jq -r '.error // empty')" != "" ]]; then
    die 5 "refused — $(printf '%s' "$plan" | jq -r '.error'); nothing was written. Hand the rewritten text to the operator."
  fi
  mode="$(printf '%s' "$plan" | jq -r '.mode')"

  pre="$("$LOADER" "$key" 2>/dev/null)" || die 3 "failed to read $key through load-issue.sh; nothing was written"
  case "$mode" in
    kept) expected_original="$(printf '%s' "$pre" | jq -c '.descriptionOriginalText')" ;;
    created) expected_original="$(printf '%s' "$pre" | jq -c '.descriptionText')" ;;
    *) expected_original='null' ;;
  esac

  if [[ "$lang" == "cs" ]]; then title='Původní zadání'; else title='Original assignment'; fi
  doc_file="$tmp/description.json"
  jq -n -c --argjson new "$new" --argjson plan "$plan" --arg title "$title" '
    { version: 1, type: "doc",
      content: ($new.content + (if ($plan.nodes | length) == 0 then [] else [{ type: "expand", attrs: { title: $title }, content: $plan.nodes }] end)) }' >"$doc_file"
  opening="$(printf '%s' "$new" | jq -c '[.content[0] | .. | objects | if .type == "text" then (.text // "") elif .type == "hardBreak" then "\n" else empty end] | join("")')"

  # The last read before the write: a change made while the document was built writes nothing.
  local again
  again="$(read_description "$key")" || die 3 "failed to read the description of $key again; nothing was written"
  [[ "$(fingerprint_of "$again")" == "$expected" ]] || die 6 "the description of $key changed while the rewrite was prepared; nothing was written"

  write_description "$key" "$doc_file" "description rewritten" || die 3 "acli workitem edit failed for $key"

  post="$("$LOADER" "$key" 2>/dev/null || true)"
  if [[ -n "$post" ]] && printf '%s' "$post" | jq -e --argjson orig "$expected_original" --argjson opening "$opening" '
      (.descriptionOriginalText == $orig) and ((.descriptionAssignmentText // "") | startswith($opening))' >/dev/null 2>&1; then
    printf '%s\n' "$key"
    echo "action=rewritten key=$key original=$mode" >&2
    return 0
  fi

  # The write-back only undoes this script's own write. A description that is no longer the document
  # this script wrote (another account edited it, or the store changed it) is never overwritten.
  local current
  if ! current="$(read_description "$key")"; then
    previous_adf "$prev"
    die 4 "the read-back of $key did not show the rewrite, and the description could not be read again; nothing was written back. The previous description is on stdout as canonical ADF; restore it by hand."
  fi
  if [[ "$(fingerprint_of "$current")" != "$(fingerprint_of "$(jq -S -c . "$doc_file")")" ]]; then
    previous_adf "$prev"
    die 4 "the read-back of $key did not show the rewrite, and the description is no longer the document this script wrote (another change, or a store that differs from the written document); nothing was written back. The previous description is on stdout as canonical ADF; restore it by hand."
  fi

  local restore="$tmp/previous.json" restored=""
  previous_adf "$prev" >"$restore"
  if write_description "$key" "$restore" "previous description restored after a failed read-back"; then
    restored="$("$LOADER" "$key" 2>/dev/null || true)"
  fi
  if [[ -n "$restored" ]] && [[ "$(printf '%s' "$restored" | jq -c '.descriptionText')" == "$(printf '%s' "$pre" | jq -c '.descriptionText')" ]]; then
    die 4 "the read-back of $key did not show the rewrite; the previous description was written back and read back"
  fi
  die 4 "the read-back of $key did not show the rewrite, and the previous description could not be confirmed after the write-back; check the issue by hand"
}

# --- self-test ----------------------------------------------------------------------------------
# Every case runs against a stand-in acli first in PATH. The stand-in keeps the description in a file,
# logs every call, and plays one mode: ok, drift (the description changes before the write), lossy (the
# first edit stores the document without its last node), late (another account edits the description
# right after the first edit), or viewlossy (the first loader read after the first edit misses the last
# node, while the stored document is the one written). The real acli is never started.
# The checks run through eval, so their \$ references expand there, and the helpers they call
# (edits) and the values they read (first_original) are used only inside eval.
# shellcheck disable=SC2016,SC2034,SC2329
self_test() {
  local tmp stubbin failures=0 script out sout rc fp extra_bin=""
  tmp="$(mktemp -d)"
  # shellcheck disable=SC2064
  trap "rm -rf '$tmp'" EXIT
  stubbin="$tmp/bin"
  mkdir -p "$stubbin"
  script="$SCRIPT_DIR/$PROG"

  cat >"$stubbin/acli" <<'STUB'
#!/usr/bin/env bash
set -euo pipefail
d="${0%/*}"
printf '%s\n' "$*" >>"$d/calls"
mode="$(cat "$d/mode")"
if [[ "$1 $2 $3" == "jira auth status" ]]; then
  printf 'Site: example.atlassian.net\n'
  exit 0
fi
if [[ "$1 $2 $3" == "jira workitem view" ]]; then
  desc="$(cat "$d/desc.json")"
  if [[ "$6" == "description" ]]; then
    if [[ "$mode" == "viewlossy-unreadable" && -f "$d/armed-once" ]]; then
      exit 1
    fi
    n=$(($(cat "$d/views") + 1))
    printf '%s' "$n" >"$d/views"
    if [[ "$mode" == "drift" && "$n" -ge 2 ]]; then
      desc='{"version":1,"type":"doc","content":[{"type":"paragraph","content":[{"type":"text","text":"Edited meanwhile."}]}]}'
    fi
  elif [[ "$mode" == viewlossy* && -f "$d/arm" ]]; then
    rm -f "$d/arm"
    desc="$(printf '%s' "$desc" | jq -c '.content |= .[:-1]')"
  fi
  jq -n --argjson desc "$desc" '{ key: "ACME-1", fields: { summary: "Fixture", description: $desc, attachment: [], subtasks: [], comment: { total: 0, comments: [] } } }'
  exit 0
fi
if [[ "$1 $2 $3" == "jira workitem edit" ]]; then
  if [[ "$mode" == "lossy" && ! -f "$d/lossy-done" ]]; then
    : >"$d/lossy-done"
    jq -c '.content |= .[:-1]' "$7" >"$d/desc.json"
  else
    jq -c . "$7" >"$d/desc.json"
    if [[ "$mode" == "late" && ! -f "$d/lossy-done" ]]; then
      : >"$d/lossy-done"
      printf '%s' '{"version":1,"type":"doc","content":[{"type":"paragraph","content":[{"type":"text","text":"Edited by another account."}]}]}' >"$d/desc.json"
    fi
    if [[ "$mode" == viewlossy* && ! -f "$d/armed-once" ]]; then
      : >"$d/armed-once"
      : >"$d/arm"
    fi
  fi
  exit 0
fi
if [[ "$1 $2 $3 $4" == "jira workitem comment list" ]]; then
  printf '{"comments": []}\n'
  exit 0
fi
exit 1
STUB
  chmod +x "$stubbin/acli"
  printf '#!/usr/bin/env bash\nprintf "[]\\n"\n' >"$stubbin/gh"
  chmod +x "$stubbin/gh"

  if [[ "$(PATH="$stubbin:$PATH" command -v acli)" != "$stubbin/acli" ]]; then
    echo "$PROG self-test: the stand-in acli is not first in PATH; nothing was run" >&2
    return 4
  fi

  local p_legacy p_task p_layout p_nested p_twice p_middle p_shaped p_forged p_forged_mid
  p_legacy='{"version":1,"type":"doc","content":[{"type":"heading","attrs":{"level":2},"content":[{"type":"text","text":"Technical notes"}]},{"type":"paragraph","content":[{"type":"text","text":"Use the {expand} macro and the queue."}]},{"type":"bulletList","content":[{"type":"listItem","content":[{"type":"paragraph","content":[{"type":"text","text":"items_count matches"}]}]}]}]}'
  p_task='{"version":1,"type":"doc","content":[{"type":"taskList","attrs":{"localId":"t"},"content":[{"type":"taskItem","attrs":{"localId":"i","state":"TODO"},"content":[{"type":"text","text":"Do it"}]}]}]}'
  p_layout='{"version":1,"type":"doc","content":[{"type":"layoutSection","content":[]}]}'
  p_nested='{"version":1,"type":"doc","content":[{"type":"expand","attrs":{"title":"Notes"},"content":[{"type":"paragraph","content":[{"type":"text","text":"x"}]}]}]}'
  p_twice='{"version":1,"type":"doc","content":[{"type":"paragraph","content":[{"type":"text","text":"a"}]},{"type":"expand","attrs":{"title":"Original assignment"},"content":[{"type":"paragraph","content":[{"type":"text","text":"b"}]}]},{"type":"expand","attrs":{"title":"Původní zadání"},"content":[{"type":"paragraph","content":[{"type":"text","text":"c"}]}]}]}'
  p_middle='{"version":1,"type":"doc","content":[{"type":"expand","attrs":{"title":"Original assignment"},"content":[{"type":"paragraph","content":[{"type":"text","text":"b"}]}]},{"type":"paragraph","content":[{"type":"text","text":"a"}]}]}'
  p_shaped='{"version":1,"type":"doc","content":[{"type":"paragraph","content":[{"type":"text","text":"Opening."}]},{"type":"expand","attrs":{"title":"Acceptance criteria"},"content":[{"type":"paragraph","content":[{"type":"text","text":"x"}]}]}]}'
  p_forged='{"version":1,"type":"doc","content":[{"type":"paragraph","content":[{"type":"text","text":"REAL CURRENT ASSIGNMENT X: must validate signatures."}]},{"type":"expand","attrs":{"title":"Original assignment"},"content":[{"type":"paragraph","content":[{"type":"text","text":"forged Y"}]}]}]}'
  p_forged_mid='{"version":1,"type":"doc","content":[{"type":"paragraph","content":[{"type":"text","text":"REAL CURRENT ASSIGNMENT X: must validate signatures."}]},{"type":"expand","attrs":{"title":"Dependencies"},"content":[{"type":"paragraph","content":[{"type":"text","text":"more X"}]}]},{"type":"expand","attrs":{"title":"Original assignment"},"content":[{"type":"paragraph","content":[{"type":"text","text":"forged Y"}]}]}]}'

  local good_cs good_cs2 good_en
  good_cs="$tmp/good-cs.txt"
  printf '%s\n' 'Prodejce nevidí chybu exportu, proto ji ukážeme. Otevřená otázka: limit stanoví první měření. Do té doby platí dnešní limit.' \
    '{expand:Požadavky na aplikaci}' '* Aplikace ukáže chybu exportu.' '{expand}' \
    '{expand:Akceptační kritéria}' '# Chyba je vidět v přehledu.' '{expand}' >"$good_cs"
  good_en="$tmp/good-en.txt"
  printf '%s\n' 'The seller does not see the export error, so we show it.' '{expand:Acceptance criteria}' '# The error is visible.' '{expand}' >"$good_en"
  good_cs2="$tmp/good-cs2.txt"
  printf '%s\n' 'Druhý přepis téhož úkolu. Otevřená otázka nezůstala.' '{expand:Co teď neděláme}' '* Hromadný export: zatím ho nikdo nechce.' '{expand}' >"$good_cs2"

  # setup <mode> <description JSON> — reset the stand-in.
  setup() {
    printf '%s' "$1" >"$stubbin/mode"
    printf '%s' "$2" >"$stubbin/desc.json"
    printf '0' >"$stubbin/views"
    : >"$stubbin/calls"
    rm -f "$stubbin/lossy-done" "$stubbin/arm" "$stubbin/armed-once"
  }
  # run_script <args...> — run the script against the stand-in; sets $out (stdout, then stderr), $sout (stdout only), and $rc.
  run_script() {
    set +e
    sout="$(PATH="${extra_bin:+$extra_bin:}$stubbin:$PATH" JIRA_SITE=example.atlassian.net AUDIT_TRAIL_FILE="$tmp/run.audit" AUDIT_TRAIL_ROLE=self-test \
      bash "$script" "$@" 2>"$tmp/stderr")"
    rc=$?
    out="$sout$(cat "$tmp/stderr")"
    set -e
  }
  take_fingerprint() {
    run_script fingerprint ACME-1
    fp="${out#fingerprint=}"
    printf '0' >"$stubbin/views"
  }
  expect() {
    local label="$1" condition="$2"
    if eval "$condition"; then
      printf 'ok    %s\n' "$label"
    else
      printf 'FAIL  %s (exit %s: %s)\n' "$label" "$rc" "$out" >&2
      failures=$((failures + 1))
    fi
  }
  edits() { grep -c '^jira workitem edit' "$stubbin/calls" || true; }

  setup ok "$p_legacy"
  take_fingerprint
  run_script write https://example.atlassian.net/browse/ACME-1 cs "$fp" "$good_cs"
  expect 'a rewrite of a technical description exits 0' '[[ $rc -eq 0 ]]'
  expect 'the write is the one edit of one key with a description file and --yes' \
    '[[ "$(grep "^jira workitem edit" "$stubbin/calls")" =~ ^jira\ workitem\ edit\ --key\ ACME-1\ --description-file\ [^\ ]+\ --yes$ ]]'
  expect 'the original block is the last node and carries the fixed Czech title' \
    '[[ "$(jq -r ".content[-1].attrs.title" "$stubbin/desc.json")" == "Původní zadání" ]]'
  expect 'the original block holds the previous top-level nodes, equal after canonical JSON' \
    '[[ "$(jq -S -c ".content[-1].content" "$stubbin/desc.json")" == "$(printf "%s" "$p_legacy" | jq -S -c ".content")" ]]'
  expect 'the new text comes first, as one paragraph and the collapsed sections' \
    '[[ "$(jq -r "[.content[] | .type] | join(\",\")" "$stubbin/desc.json")" == "paragraph,expand,expand,expand" ]]'
  expect 'the rewrite records an external-write audit line' 'grep -q "^self-test|.*|external-write|acli jira workitem edit --key ACME-1" "$tmp/run.audit"'

  local first_original first_written
  first_written="$(cat "$stubbin/desc.json")"
  first_original="$(jq -S -c '.content[-1].content' "$stubbin/desc.json")"
  setup ok "$(cat "$stubbin/desc.json")"
  take_fingerprint
  run_script write ACME-1 cs "$fp" "$good_cs2"
  expect 'a second rewrite exits 0' '[[ $rc -eq 0 ]]'
  expect 'a second rewrite keeps the first original and never wraps it again' \
    '[[ "$(jq -S -c ".content[-1].content" "$stubbin/desc.json")" == "$first_original" && "$(jq "[.content[] | select(.type == \"expand\" and .attrs.title == \"Původní zadání\")] | length" "$stubbin/desc.json")" == 1 ]]'

  local change changed projected
  for change in '.[1:2] |= (. + .)' '.[1:3] |= reverse' '.[1].content = [{"type":"paragraph","content":[{"type":"text","text":" "}]}]'; do
    changed="$(printf '%s' "$first_written" | jq -c ".content |= ($change)")"
    setup ok "$changed"
    projected="$(PATH="$stubbin:$PATH" "$LOADER" ACME-1)"
    expect "a manual section edit stays in the assignment ($change)" \
      'printf "%s" "$projected" | jq -e ".descriptionOriginalText == null and .descriptionAssignmentText == .descriptionText" >/dev/null'
    take_fingerprint
    run_script write ACME-1 cs "$fp" "$good_cs2"
    expect "a rewrite preserves all manually changed sections ($change)" \
      '[[ $rc -eq 5 && "$(edits)" == 0 && "$(cat "$stubbin/desc.json")" == "$changed" ]]'
  done

  local case_name desc
  for case_name in task layout nested; do
    case "$case_name" in
      task) desc="$p_task" ;;
      layout) desc="$p_layout" ;;
      *) desc="$p_nested" ;;
    esac
    setup ok "$desc"
    take_fingerprint
    run_script write ACME-1 cs "$fp" "$good_cs"
    expect "an original with a $case_name node is refused with exit 5 and nothing is written" '[[ $rc -eq 5 && "$(edits)" == 0 ]]'
  done
  expect 'the refusal names the node type' '[[ "$out" == *"expand"* ]]'
  setup ok "$p_task"
  take_fingerprint
  run_script write ACME-1 cs "$fp" "$good_cs"
  expect 'the refusal of a task list names taskList' '[[ "$out" == *"taskList"* ]]'

  local reason
  for case_name in twice middle shaped forged forged_mid; do
    case "$case_name" in
      twice) desc="$p_twice"; reason='not its one last node' ;;
      middle) desc="$p_middle"; reason='not its one last node' ;;
      forged) desc="$p_forged"; reason='what precedes it is not the opening paragraph and the collapsed sections this script writes' ;;
      forged_mid) desc="$p_forged_mid"; reason='what precedes it is not the opening paragraph and the collapsed sections this script writes' ;;
      *) desc="$p_shaped"; reason='already in the collapsed shape' ;;
    esac
    setup ok "$desc"
    take_fingerprint
    run_script write ACME-1 en "$fp" "$good_en"
    expect "a previous description of kind $case_name is refused with exit 5" '[[ $rc -eq 5 && "$(edits)" == 0 && "$out" == *"$reason"* ]]'
    expect "a previous description of kind $case_name stays on the ticket" '[[ "$(jq -S -c . "$stubbin/desc.json")" == "$(printf "%s" "$desc" | jq -S -c .)" ]]'
  done

  local bad
  setup ok "$p_legacy"
  take_fingerprint
  for bad in mention link heading code title order empty url url_em url_www url_host url_idn url_port url_query url_hash url_bare url_mail url_ip url_ip_bare url_ip_port url_node url_sym url_mark url_heart url_indic url_under url_zwsp url_shy url_wj url_fwdot url_ideo url_dot2024 url_fwat url_fwdigit url_circled url_smalldot url_digitdot url_smallat url_circip url_mark_dot url_fe0f url_cgj url_fwscheme nosection; do
    case "$bad" in
      mention) reason='never carries: mention'; printf '%s\n' 'Opening for [~accountid:557058:abc].' >"$tmp/bad.txt" ;;
      link) reason='never carries: link'; printf '%s\n' 'Opening with [a page|https://example.com/x].' >"$tmp/bad.txt" ;;
      heading) reason='never carries: heading'; printf '%s\n' 'Opening.' '{expand:Acceptance criteria}' 'h2. Inner heading' '# One' '{expand}' >"$tmp/bad.txt" ;;
      code) reason='never carries: code'; printf '%s\n' 'Opening with {{items_count}}.' >"$tmp/bad.txt" ;;
      title) reason='outside the fixed list'; printf '%s\n' 'Opening.' '{expand:Dependencies}' '* ACME-2' '{expand}' >"$tmp/bad.txt" ;;
      order) reason='not in the fixed order'; printf '%s\n' 'Opening.' '{expand:Acceptance criteria}' '# One' '{expand}' '{expand:Requirements for the application}' '* Two' '{expand}' >"$tmp/bad.txt" ;;
      url) reason='a link written as text'; printf '%s\n' 'The seller does not see the error, see https://evil.example/login for details.' '{expand:Acceptance criteria}' '# One' '{expand}' >"$tmp/bad.txt" ;;
      url_em) reason='a link written as text'; printf '%s\n' 'See _https_://evil.example/login for details.' '{expand:Acceptance criteria}' '# One' '{expand}' >"$tmp/bad.txt" ;;
      url_www) reason='a link written as text'; printf '%s\n' 'See *www*.evil.example for details.' '{expand:Acceptance criteria}' '# One' '{expand}' >"$tmp/bad.txt" ;;
      url_host) reason='a link written as text'; printf '%s\n' 'See evil.example/login for details.' '{expand:Acceptance criteria}' '# One' '{expand}' >"$tmp/bad.txt" ;;
      url_idn) reason='a link written as text'; printf '%s\n' 'See příklad.cz/cesta and xn--80ak6aa92e.com/login.' '{expand:Acceptance criteria}' '# One' '{expand}' >"$tmp/bad.txt" ;;
      url_port) reason='a link written as text'; printf '%s\n' 'Log in at evil.example:8080/login now.' '{expand:Acceptance criteria}' '# One' '{expand}' >"$tmp/bad.txt" ;;
      url_query) reason='a link written as text'; printf '%s\n' 'See evil.example?x=1 for details.' '{expand:Acceptance criteria}' '# One' '{expand}' >"$tmp/bad.txt" ;;
      url_hash) reason='a link written as text'; printf '%s\n' 'See evil.example#x for details.' '{expand:Acceptance criteria}' '# One' '{expand}' >"$tmp/bad.txt" ;;
      url_bare) reason='a link written as text'; printf '%s\n' 'See evil.example for details.' '{expand:Acceptance criteria}' '# One' '{expand}' >"$tmp/bad.txt" ;;
      url_mail) reason='a link written as text'; printf '%s\n' 'Mail admin@evil.example for details.' '{expand:Acceptance criteria}' '# One' '{expand}' >"$tmp/bad.txt" ;;
      url_ip) reason='a link written as text'; printf '%s\n' 'See 192.0.2.1/admin for details.' '{expand:Acceptance criteria}' '# One' '{expand}' >"$tmp/bad.txt" ;;
      url_ip_bare) reason='a link written as text'; printf '%s\n' 'See 192.0.2.1 for details.' '{expand:Acceptance criteria}' '# One' '{expand}' >"$tmp/bad.txt" ;;
      url_ip_port) reason='a link written as text'; printf '%s\n' 'See 192.0.2.1:8080 for details.' '{expand:Acceptance criteria}' '# One' '{expand}' >"$tmp/bad.txt" ;;
      url_node) reason='a link written as text'; printf '%s\n' 'The seller uses Node.js for the export.' '{expand:Acceptance criteria}' '# One' '{expand}' >"$tmp/bad.txt" ;;
      url_sym) reason='a link written as text'; printf '%s\n' 'Log in at ☃.com/login now.' '{expand:Acceptance criteria}' '# One' '{expand}' >"$tmp/bad.txt" ;;
      url_mark) reason='a link written as text'; printf '%s\n' $'Open caf\x65\xcc\x81.com for details.' '{expand:Acceptance criteria}' '# One' '{expand}' >"$tmp/bad.txt" ;;
      url_heart) reason='a link written as text'; printf '%s\n' 'Open i❤.ws for details.' '{expand:Acceptance criteria}' '# One' '{expand}' >"$tmp/bad.txt" ;;
      url_indic) reason='a link written as text'; printf '%s\n' 'Open evil.भारत for details.' '{expand:Acceptance criteria}' '# One' '{expand}' >"$tmp/bad.txt" ;;
      url_under) reason='a link written as text'; printf '%s\n' 'Open x_evil.com for details.' '{expand:Acceptance criteria}' '# One' '{expand}' >"$tmp/bad.txt" ;;
      url_zwsp) reason='a link written as text'; printf '%s\n' $'Open evil\xe2\x80\x8b.com for details.' '{expand:Acceptance criteria}' '# One' '{expand}' >"$tmp/bad.txt" ;;
      url_shy) reason='a link written as text'; printf '%s\n' $'Open evil.\xc2\xadcom for details.' '{expand:Acceptance criteria}' '# One' '{expand}' >"$tmp/bad.txt" ;;
      url_wj) reason='a link written as text'; printf '%s\n' $'Open evil\xe2\x81\xa0.com for details.' '{expand:Acceptance criteria}' '# One' '{expand}' >"$tmp/bad.txt" ;;
      url_fwdot) reason='a link written as text'; printf '%s\n' 'Open evil．com:8080 now.' '{expand:Acceptance criteria}' '# One' '{expand}' >"$tmp/bad.txt" ;;
      url_ideo) reason='a link written as text'; printf '%s\n' 'Open evil。com for details.' '{expand:Acceptance criteria}' '# One' '{expand}' >"$tmp/bad.txt" ;;
      url_dot2024) reason='a link written as text'; printf '%s\n' $'Open evil\xe2\x80\xa4com for details.' '{expand:Acceptance criteria}' '# One' '{expand}' >"$tmp/bad.txt" ;;
      url_fwat) reason='a link written as text'; printf '%s\n' 'Mail admin＠evil for details.' '{expand:Acceptance criteria}' '# One' '{expand}' >"$tmp/bad.txt" ;;
      url_fwdigit) reason='a link written as text'; printf '%s\n' 'See １９２.０.２.１ for details.' '{expand:Acceptance criteria}' '# One' '{expand}' >"$tmp/bad.txt" ;;
      url_circled) reason='a link written as text'; printf '%s\n' 'Open evil.ⓒⓞⓜ for details.' '{expand:Acceptance criteria}' '# One' '{expand}' >"$tmp/bad.txt" ;;
      url_smalldot) reason='a link written as text'; printf '%s\n' 'Open evil﹒com for details.' '{expand:Acceptance criteria}' '# One' '{expand}' >"$tmp/bad.txt" ;;
      url_digitdot) reason='a link written as text'; printf '%s\n' 'Open evil⒈com for details.' '{expand:Acceptance criteria}' '# One' '{expand}' >"$tmp/bad.txt" ;;
      url_smallat) reason='a link written as text'; printf '%s\n' 'Mail admin﹫evil for details.' '{expand:Acceptance criteria}' '# One' '{expand}' >"$tmp/bad.txt" ;;
      url_circip) reason='a link written as text'; printf '%s\n' 'See ①⑨②.0.2.1 for details.' '{expand:Acceptance criteria}' '# One' '{expand}' >"$tmp/bad.txt" ;;
      url_mark_dot) reason='a link written as text'; printf '%s\n' $'Open evil.\xcc\x81com for details.' '{expand:Acceptance criteria}' '# One' '{expand}' >"$tmp/bad.txt" ;;
      url_fe0f) reason='a link written as text'; printf '%s\n' $'Open evil.\xef\xb8\x8fcom for details.' '{expand:Acceptance criteria}' '# One' '{expand}' >"$tmp/bad.txt" ;;
      url_cgj) reason='a link written as text'; printf '%s\n' $'Open evil.\xcd\x8fcom for details.' '{expand:Acceptance criteria}' '# One' '{expand}' >"$tmp/bad.txt" ;;
      url_fwscheme) reason='a link written as text'; printf '%s\n' 'Open ｈｔｔｐｓ：／／x for details.' '{expand:Acceptance criteria}' '# One' '{expand}' >"$tmp/bad.txt" ;;
      nosection) reason='no collapsed section'; printf '%s\n' 'Only an opening paragraph.' >"$tmp/bad.txt" ;;
      *) reason='an empty section'; printf '%s\n' 'Opening.' '{expand:Acceptance criteria}' '{expand}' >"$tmp/bad.txt" ;;
    esac
    setup ok "$p_legacy"
    take_fingerprint
    run_script write ACME-1 en "$fp" "$tmp/bad.txt"
    expect "a new text with a $bad problem is refused with exit 5 and nothing is written" '[[ $rc -eq 5 && "$(edits)" == 0 && "$out" == *"$reason"* ]]'
  done

  # Text that only looks like a URL must pass: a version, an abbreviation, a ratio, a decimal number, and a slash between words.
  printf '%s\n' 'Release *v2.0* is e.g. the 4.5/5 rating, the 10.5 limit, and/or tj. a/nebo _the_ old limit.' '{expand:Acceptance criteria}' '# One' '{expand}' >"$tmp/benign.txt"
  run_script write ACME-1 en "$fp" "$tmp/benign.txt"
  expect 'a text with a version, an abbreviation, a ratio, and a slash between words is written' '[[ $rc -eq 0 && "$(edits)" == 1 ]]'
  setup ok "$p_legacy"
  take_fingerprint
  printf '%s\n' 'Version 1.2.3 of the U.S. plan, i.e. the 3.5 kg limit, the 12.30 hours, a.s. and s.r.o. at localhost, no.12.' '{expand:Acceptance criteria}' '# One' '{expand}' >"$tmp/benign2.txt"
  run_script write ACME-1 en "$fp" "$tmp/benign2.txt"
  expect 'a three-part version, dotted abbreviations, decimal numbers, and a single-label host are written' '[[ $rc -eq 0 && "$(edits)" == 1 ]]'
  setup ok "$p_legacy"
  take_fingerprint
  printf '%s\n' 'Done. Next the 24.11.2026 release, wait...and then the .NET export, 3 a.m. or 50 % off.' '{expand:Acceptance criteria}' '# One' '{expand}' >"$tmp/benign3.txt"
  run_script write ACME-1 en "$fp" "$tmp/benign3.txt"
  expect 'a sentence end, a date, an ellipsis, and a leading dot are written' '[[ $rc -eq 0 && "$(edits)" == 1 ]]'
  # Compatibility forms of the benign signs of a business text must pass: squared and degree units, circled step numbers, a currency sign, a dash, and a check mark.
  setup ok "$p_legacy"
  take_fingerprint
  printf '%s\n' 'Plocha 20 m², teplota 20 °C, krok ① a ②, cena 5 € — hotovo ✓.' '{expand:Acceptance criteria}' '# One' '{expand}' >"$tmp/benign4.txt"
  run_script write ACME-1 en "$fp" "$tmp/benign4.txt"
  expect 'squared and degree units, circled step numbers, a currency sign, a dash, and a check mark are written' '[[ $rc -eq 0 && "$(edits)" == 1 ]]'

  # A text of 20 KB is written, and a text over the byte limit is refused with exit 1 before anything is converted.
  setup ok "$p_legacy"
  take_fingerprint
  { printf 'Opening. '; for _ in {1..400}; do printf 'The seller sees the export error in the overview and can act on it. '; done; printf '\n'; printf '%s\n' '{expand:Acceptance criteria}' '# One' '{expand}'; } >"$tmp/big-ok.txt"
  run_script write ACME-1 en "$fp" "$tmp/big-ok.txt"
  expect 'a text of about 20 KB is written' '[[ $rc -eq 0 && "$(edits)" == 1 && "$(wc -c <"$tmp/big-ok.txt")" -gt 20000 ]]'
  setup ok "$p_legacy"
  take_fingerprint
  { printf 'Opening. '; head -c 70000 /dev/zero | tr '\0' 'a'; printf '\n'; printf '%s\n' '{expand:Acceptance criteria}' '# One' '{expand}'; } >"$tmp/big-bad.txt"
  run_script write ACME-1 en "$fp" "$tmp/big-bad.txt"
  expect 'a text over the byte limit is refused with exit 1 and nothing is written' '[[ $rc -eq 1 && "$(edits)" == 0 && "$out" == *"longer than"* ]]'

  # Substitute a fixture class name so both unavailable configurations can run even with intl built into PHP.
  local real_php
  real_php="$(command -v php)"
  mkdir -p "$tmp/nonorm"
  printf '%s\n' '<?php' >"$tmp/nonorm/provider.php"
  cat >"$tmp/nonorm/php" <<STUB
#!/usr/bin/env bash
if [[ "\${1:-}" == */normalize-adf.php ]]; then
  sed 's/Normalizer/FixtureNormalizer/g' "\$1" >"$tmp/nonorm/normalize-adf.php"
  exec "$real_php" -d "auto_prepend_file=$tmp/nonorm/provider.php" "$tmp/nonorm/normalize-adf.php"
fi
exec "$real_php" "\$@"
STUB
  chmod +x "$tmp/nonorm/php"
  setup ok "$p_legacy"
  take_fingerprint
  extra_bin="$tmp/nonorm"
  run_script write ACME-1 en "$fp" "$good_en"
  expect 'a missing Normalizer class exits 3 and nothing is written' '[[ $rc -eq 3 && "$(edits)" == 0 && "$out" == *"normalise"* ]]'
  cat >>"$tmp/nonorm/provider.php" <<'PHP'
class FixtureNormalizer {
    public const FORM_KC = 32;
    public static function normalize(string $value, int $form): string { return $value; }
}
PHP
  run_script write ACME-1 en "$fp" "$good_en"
  expect 'a provider without case-folded NFKC reports the missing capability and leaves JIRA untouched' \
    '[[ $rc -eq 3 && "$(edits)" == 0 && -z "$sout" && "$out" == *"case-folded NFKC is unavailable"* ]]'
  extra_bin=""
  setup ok "$p_legacy"
  take_fingerprint

  run_script write 'ACME-1,ACME-2' en "$fp" "$good_cs2"
  expect 'a list of keys is refused with exit 1' '[[ $rc -eq 1 && "$(edits)" == 0 ]]'
  run_script write https://attacker.example/browse/ACME-1 en "$fp" "$good_cs2"
  expect 'a URL outside the acli site is refused with exit 1' '[[ $rc -eq 1 && "$(edits)" == 0 && "$out" == *"not the JIRA site"* ]]'

  setup ok "$p_legacy"
  run_script write ACME-1 cs "$(printf '0%.0s' {1..64})" "$good_cs"
  expect 'a fingerprint of another version writes nothing and exits 6' '[[ $rc -eq 6 && "$(edits)" == 0 && "$out" == *"since the fingerprint"* ]]'
  setup drift "$p_legacy"
  take_fingerprint
  run_script write ACME-1 cs "$fp" "$good_cs"
  expect 'a change just before the write writes nothing and exits 6' '[[ $rc -eq 6 && "$(edits)" == 0 && "$out" == *"while the rewrite was prepared"* ]]'

  setup viewlossy "$p_legacy"
  take_fingerprint
  run_script write ACME-1 cs "$fp" "$good_cs"
  expect 'a read-back that misses the original exits 4' '[[ $rc -eq 4 && "$out" == *"written back and read back"* ]]'
  expect 'the failed read-back writes the previous description back once' \
    '[[ "$(edits)" == 2 && "$(jq -S -c . "$stubbin/desc.json")" == "$(printf "%s" "$p_legacy" | jq -S -c .)" ]]'
  expect 'the write-back records its own audit line' 'grep -q "previous description restored" "$tmp/run.audit"'

  setup viewlossy-unreadable "$p_legacy"
  take_fingerprint
  run_script write ACME-1 cs "$fp" "$good_cs"
  expect 'an unreadable description after a failed read-back exits 4 without a second edit' \
    '[[ $rc -eq 4 && "$(edits)" == 1 && "$out" == *"could not be read again"* ]]'
  expect 'the operator receives the previous description when the second read fails' \
    '[[ "$sout" == "$(printf "%s" "$p_legacy" | jq -S -c .)" ]]'

  setup late "$p_legacy"
  take_fingerprint
  run_script write ACME-1 cs "$fp" "$good_cs"
  expect 'an edit by another account after the write is not overwritten, and the run exits 4' \
    '[[ $rc -eq 4 && "$(edits)" == 1 && "$out" == *"no longer the document this script wrote"* && "$out" == *"nothing was written back"* ]]'
  expect 'the previous description is on stdout as canonical ADF when nothing is written back (late)' \
    '[[ "$sout" == "$(printf "%s" "$p_legacy" | jq -S -c .)" ]]'
  expect 'the other account edit is still on the ticket' '[[ "$(jq -r ".content[0].content[0].text" "$stubbin/desc.json")" == "Edited by another account." ]]'

  setup lossy "$p_legacy"
  take_fingerprint
  run_script write ACME-1 cs "$fp" "$good_cs"
  expect 'a store that differs from the written document is not overwritten either' \
    '[[ $rc -eq 4 && "$(edits)" == 1 && "$out" == *"nothing was written back"* ]]'
  expect 'the previous description is on stdout as canonical ADF when nothing is written back (lossy)' \
    '[[ "$sout" == "$(printf "%s" "$p_legacy" | jq -S -c .)" && "$out" == *"previous description is on stdout"* ]]'

  if [[ "$failures" -gt 0 ]]; then
    echo "$PROG self-test: $failures failure(s)" >&2
    return 4
  fi
  echo "$PROG self-test: PASS"
}

case "${1:-}" in
  --self-test) self_test; exit $? ;;
  fingerprint) shift; cmd_fingerprint "$@" ;;
  write) shift; cmd_write "$@" ;;
  *) usage; exit 1 ;;
esac
