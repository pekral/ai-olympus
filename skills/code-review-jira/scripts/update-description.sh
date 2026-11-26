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
#     code, mention, link, smart link, media, task list, decision list, layout, or
#     nested section, and at least one collapsed section.
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
# script writes the saved previous description once, reads it back, and exits 4.
#
# Audit trail:
#   Each write (the rewrite and a rollback) appends its own line through
#   skills/_shared/audit-trail.sh record, with the real exit status (AUDIT_TRAIL_FILE, AUDIT_TRAIL_ROLE).
#
# Exit codes:
#   0  the description was rewritten and read back
#   1  usage error, or a refused key or URL
#   2  missing required tool (acli, jq, php)
#   3  an acli read or write failed, or the converter failed
#   4  the read-back did not match; the previous description was written back
#   5  the shape was refused (the new text or the previous description); nothing was written
#   6  the description changed since the fingerprint was taken; nothing was written
set -euo pipefail

PROG="${0##*/}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
AUDIT_TRAIL="$SCRIPT_DIR/../../_shared/audit-trail.sh"
LOADER="$SCRIPT_DIR/load-issue.sh"
CONVERTER="$SCRIPT_DIR/wiki-markup-to-adf.php"

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

  local key text tmp new problems prev plan mode pre post opening expected_original title doc_file
  key="$(resolve_key "$input")"
  if [[ "$source" == "-" ]]; then
    text="$(cat)"
  elif [[ -f "$source" ]]; then
    text="$(cat "$source")"
  else
    die 1 "the text file was not found: $source"
  fi
  [[ -n "${text//[[:space:]]/}" ]] || die 1 "refused — the new text is empty"

  tmp="$(mktemp -d)"
  # shellcheck disable=SC2064
  trap "rm -rf '$tmp'" EXIT

  new="$(printf '%s' "$text" | php "$CONVERTER")" || die 3 "failed to convert the new text to ADF; nothing was written"

  problems="$(printf '%s' "$new" | jq -r -L "$SCRIPT_DIR" --arg lang "$lang" "$SHAPE_JQ"'
    (.content // []) as $c
    | ($c[1:] | map(select((.type // "") == "expand") | (.attrs.title // ""))) as $titles
    | ($titles | map(. as $t | sectionTitles($lang) | index($t))) as $ix
    | [ (if ($c | length) == 0 or ($c[0].type // "") != "paragraph" then "the new text does not open with one paragraph" else empty end),
        (if ($c | length) < 2 then "the new text has no collapsed section" else empty end),
        ($c[1:][] | select((.type // "") != "expand") | "a node outside a collapsed section after the opening paragraph: \(.type // "?")"),
        ($titles[] | select(. as $t | sectionTitles($lang) | index($t) == null) | "a section title outside the fixed list: \(.)"),
        (if ([range(1; $ix | length) | select($ix[.] != null and $ix[. - 1] != null and $ix[.] <= $ix[. - 1])] | length) > 0
         then "the sections are not in the fixed order, or one repeats" else empty end),
        ($c[1:][] | select((.type // "") == "expand") | select([.content[]? | .. | objects | select(.type == "text")] | length == 0)
         | "an empty section: \(.attrs.title // "?")"),
        ([.. | objects | (.type // "") | select(IN("heading", "codeBlock", "mention", "inlineCard", "blockCard", "embedCard",
            "media", "mediaSingle", "mediaGroup", "mediaInline", "taskList", "decisionList", "layoutSection", "nestedExpand"))]
         | unique[] | "a node the new text never carries: \(.)"),
        ([.. | objects | .marks? // empty | .[] | (.type // "") | select(IN("link", "code"))] | unique[] | "a mark the new text never carries: \(.)"),
        ($c[1:][] | select((.type // "") == "expand") | .content[]? | .. | objects | select((.type // "") == "expand")
         | "a collapsed section inside a collapsed section")
      ] | unique | .[]')" || die 3 "failed to read the converted text"
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

  local restore="$tmp/previous.json" restored=""
  printf '%s' "$prev" | jq -c 'if . == null then { version: 1, type: "doc", content: [] } else . end' >"$restore"
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
# logs every call, and plays one mode: ok, drift (the description changes before the write), or lossy
# (the first edit loses the last node). The real acli is never started.
# The checks run through eval, so their \$ references expand there, and the helpers they call
# (edits) and the values they read (first_original) are used only inside eval.
# shellcheck disable=SC2016,SC2034,SC2329
self_test() {
  local tmp stubbin failures=0 script out rc fp
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
    n=$(($(cat "$d/views") + 1))
    printf '%s' "$n" >"$d/views"
    if [[ "$mode" == "drift" && "$n" -ge 2 ]]; then
      desc='{"version":1,"type":"doc","content":[{"type":"paragraph","content":[{"type":"text","text":"Edited meanwhile."}]}]}'
    fi
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
    rm -f "$stubbin/lossy-done"
  }
  # run_script <args...> — run the script against the stand-in; sets $out and $rc.
  run_script() {
    set +e
    out="$(PATH="$stubbin:$PATH" JIRA_SITE=example.atlassian.net AUDIT_TRAIL_FILE="$tmp/run.audit" AUDIT_TRAIL_ROLE=self-test \
      bash "$script" "$@" 2>&1)"
    rc=$?
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

  local first_original
  first_original="$(jq -S -c '.content[-1].content' "$stubbin/desc.json")"
  setup ok "$(cat "$stubbin/desc.json")"
  take_fingerprint
  run_script write ACME-1 cs "$fp" "$good_cs2"
  expect 'a second rewrite exits 0' '[[ $rc -eq 0 ]]'
  expect 'a second rewrite keeps the first original and never wraps it again' \
    '[[ "$(jq -S -c ".content[-1].content" "$stubbin/desc.json")" == "$first_original" && "$(jq "[.content[] | select(.type == \"expand\" and .attrs.title == \"Původní zadání\")] | length" "$stubbin/desc.json")" == 1 ]]'

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
  for bad in mention link heading code title order empty nosection; do
    case "$bad" in
      mention) reason='never carries: mention'; printf '%s\n' 'Opening for [~accountid:557058:abc].' >"$tmp/bad.txt" ;;
      link) reason='never carries: link'; printf '%s\n' 'Opening with [a page|https://example.com/x].' >"$tmp/bad.txt" ;;
      heading) reason='never carries: heading'; printf '%s\n' 'Opening.' '{expand:Acceptance criteria}' 'h2. Inner heading' '# One' '{expand}' >"$tmp/bad.txt" ;;
      code) reason='never carries: code'; printf '%s\n' 'Opening with {{items_count}}.' >"$tmp/bad.txt" ;;
      title) reason='outside the fixed list'; printf '%s\n' 'Opening.' '{expand:Dependencies}' '* ACME-2' '{expand}' >"$tmp/bad.txt" ;;
      order) reason='not in the fixed order'; printf '%s\n' 'Opening.' '{expand:Acceptance criteria}' '# One' '{expand}' '{expand:Requirements for the application}' '* Two' '{expand}' >"$tmp/bad.txt" ;;
      nosection) reason='no collapsed section'; printf '%s\n' 'Only an opening paragraph.' >"$tmp/bad.txt" ;;
      *) reason='an empty section'; printf '%s\n' 'Opening.' '{expand:Acceptance criteria}' '{expand}' >"$tmp/bad.txt" ;;
    esac
    run_script write ACME-1 en "$fp" "$tmp/bad.txt"
    expect "a new text with a $bad problem is refused with exit 5 and nothing is written" '[[ $rc -eq 5 && "$(edits)" == 0 && "$out" == *"$reason"* ]]'
  done

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

  setup lossy "$p_legacy"
  take_fingerprint
  run_script write ACME-1 cs "$fp" "$good_cs"
  expect 'a read-back that misses the original exits 4' '[[ $rc -eq 4 && "$out" == *"written back and read back"* ]]'
  expect 'the failed read-back writes the previous description back once' \
    '[[ "$(edits)" == 2 && "$(jq -S -c . "$stubbin/desc.json")" == "$(printf "%s" "$p_legacy" | jq -S -c .)" ]]'
  expect 'the write-back records its own audit line' 'grep -q "previous description restored" "$tmp/run.audit"'

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
