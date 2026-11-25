#!/usr/bin/env bash
# post-inline-review.sh — GitHub pull-request review publisher used by CR-track skills. It publishes one review round
# as ONE pull-request review with no inline comment: its body carries the visible layout of templates/review-body.md (a
# TL;DR of at most 500 characters, a collapsed Merge Risk block, a collapsed Walkthrough block, a collapsed Where to test
# block, and a collapsed Actionable comments block that lists every published finding) above the hidden machine record
# of the round. It also resolves an agent inline thread that an earlier release posted, once a later round found its
# finding fixed. A review carries no other code than the suggested code of a finding: the visible body holds no suggestion
# block and no fenced block but the mermaid sequence diagram of the Walkthrough and the suggested code of each entry.
# The file name keeps the word "inline" because the installed copies, the audit lines, and the callers name it.
#
# Usage:
#   post-inline-review.sh post    <NUMBER|URL> --commit-id <SHA> --meta-file <RECORD_FILE> --walkthrough-file <FILE> <FINDINGS_FILE|->
#   post-inline-review.sh resolve <NUMBER|URL> --fixed <FINDING_ID>[,<FINDING_ID>...]
#   post-inline-review.sh --self-test
#
# post
#   FINDINGS_FILE  A JSON array, or `-` for stdin; `[]` for a round with no finding. One object per finding:
#                    finding_id            required, `r-` or `f-` and 16 hex digits (finding-records.md)
#                    severity              required, `Critical` or `Moderate`
#                    title                 required, 1 to 200 characters; the short name of the finding in the Actionable
#                                          comments block, and the name the withheld-finding check looks for in the record
#                    path, line            the file in the pull request and the (last) line, shown as the place of the
#                                          Actionable comments entry; both left out for a finding with no line (a
#                                          functional gap)
#                    start_line            optional, the first line of a multi-line finding, below `line`; never rendered
#                    side                  optional, `RIGHT` (default, the new file) or `LEFT` (the old file); never rendered
#                    problem               required, one or two plain sentences, 4000 characters at most
#                    impact                required except on a `dry` finding, one sentence; never rendered
#                    fix                   required except on a `dry` finding, one plain sentence that says what to change
#                                          and never holds code: no fenced block
#                    suggestion            optional, the code that fixes the finding: the corrected code of the lines from
#                                          `start_line` (or `line`) to `line`, 1 to 4000 characters, with no agent marker.
#                                          Rendered as written under the Fix line, in a fence of the file's language that no
#                                          line of the code closes; left out for a fix that is no code change
#                    kind                  optional, `dry`: a DRY / reuse finding, always Moderate
#                    existing              required on a `dry` finding, `<path>:<line>` of the existing implementation
#                    author                optional `@login` of the pull request author; rendered as the last line of the
#                                          Actionable comments entry of the finding
#                    do_not_file_publicly  optional boolean; a security finding that must not reach a public repository
#                    rule, provenance, evidence, expected_behavior, test_hint, answered
#                                          optional text (4000 characters at most); accepted and never rendered: the
#                                          full finding lives in the hidden record
#                    raised_by             optional, `splinter`, `leonardo`, or `both`; never rendered
#                    level                 optional, `High`, `Medium`, or `Low`; a Moderate only; never rendered
#                  A review carries no other code, so `faulty_example` and `faulty_example_lang` are refused, and so is
#                  a title, problem, impact, fix, or existing value that holds a fenced block.
#   --commit-id    The head SHA the round reviewed. The script refuses a head that is not the current one (exit 4).
#   --meta-file    The hidden machine record of the round: the text templates/pr-comment-output.md renders, which
#                  skills/_shared/check-cr-header.sh accepts. It lists every published finding in full and never a
#                  finding withheld on a public repository. Its `Merge risk:` line gives the grade and the sentence of the
#                  visible Merge Risk block: the record is the one owner of both. A record with no `Merge risk:` line, or
#                  with a suggestion block, is refused. So is a record whose `## Interactive testing` table holds
#                  result rows and every one of them is `Blocked`: a walkthrough that did not run is not reported.
#                  A result row is a data row of the table, never the header row or the separator row, and it
#                  counts as run when its verdict is anything but `Blocked` (`Met`, `Not met`, `Passed`, `Failed`,
#                  `Partial`).
#   --walkthrough-file
#                  A JSON object, the data of the TL;DR and of the Walkthrough block (templates/review-body.md):
#                    tldr          required, what the pull request changes in plain sentences. At most 500 code points
#                                  once the text is normalised: a longer text is refused, never cut (exit 1), so the
#                                  author rewrites it shorter
#                    description   required, the change in one to three plain sentences, 1500 characters at most
#                    title         optional, the name of the change group, shown as `Changes: <title>`
#                    areas         required, 1 to 20 rows: {layer, files[], summary}; every file is a changed file of the
#                                  pull request, and the row shows at most four of them. A row that names a test
#                                  file is refused, unless the pull request changes only tests. A test file is what
#                                  TEST_RE of skills/_shared/domain-vocabulary.sh matches, the one definition that
#                                  skills/_shared/classify-risk.sh reads for the `tests-only` signal too
#                    effort        required: {level 1-5, minutes 1-999}; the label (Trivial, Simple, Moderate, Complex,
#                                  Critical) comes from the level
#                    change_type   required: Feature, Bug fix, Refactor, Performance, Security, Tests, Documentation, Chore
#                    diagram       optional, a mermaid `sequenceDiagram` of 60 lines at most, for a change with a flow
#                    since         optional, the full SHA of the head the previous published review reviewed. Set, the
#                                  walkthrough covers the changes since that commit only: every file of an area is a file
#                                  that `compare/<since>...<head>` changes, the tests-only exception reads that file list,
#                                  and the Walkthrough summary shows `since <short SHA>`. A since that is not an earlier
#                                  commit of the head is refused (exit 1): leave it out and describe the whole pull request
#                    test_routes   optional, 1 to 30 rows {method, path, try}: each page or route the change touches, from
#                                  the route inventory of the security review (never from a running application), as an
#                                  HTTP method (GET, POST, PUT, PATCH, DELETE), a path that starts with `/`, and one short
#                                  line on what to try there. Left out, or empty, when the change touches no page or route
#
# Behavior of post:
#   1. Validate the findings, the walkthrough, and the record. A bad document, a record that check-cr-header.sh
#      refuses, a record with no `Merge risk:` line or with a suggestion block, a finding that holds code or an agent
#      marker after normalisation, and a rendered body that does not hold exactly its own markers publish nothing
#      (exit 1).
#   2. Read the pull request head and refuse a `--commit-id` that is not the current head (exit 4).
#   3. Read the visibility of the repository with `gh repo view --json isPrivate`. Only the value `true` is private:
#      anything else, a failed check included, is public. On a public repository a finding marked
#      `do_not_file_publicly` is withheld: it is in no line of the Actionable comments block and not in its count. The
#      flag holds for the finding id, so every entry that shares the id of a marked entry is withheld too, and it is set
#      on every such entry before a duplicate of one id, path, and line is dropped. A record, a walkthrough, or a
#      published finding that names a withheld finding (its finding id, or the title of any entry of it) is refused,
#      nothing is posted (exit 1): hidden HTML is public too. The check reads the decoded string values of the JSON
#      inputs and compares one scan key, the words a reader sees: control characters, every invisible character (Unicode
#      category Cf and every default ignorable code point), and the inline Markdown markup (backtick, `*`, `_`, `~`, the
#      brackets and the target of every link form, a link definition included) removed, every kind of whitespace
#      collapsed, and the Unicode case folded. So a JSON escape, an invisible character, or other markup hides no title.
#   4. Read the changed files of the pull request and the paths of the repository at the reviewed head
#      (`git/trees/<sha>?recursive=1`). The visible text (the TL;DR, the Merge Risk sentence, the Walkthrough, Where to
#      test, and the Actionable comments; never the hidden record) must name none of the package's own tooling;
#      skills/_shared/check-visible-review-text.php decides, and a name that the reviewed repository holds, or that an
#      added or removed line of the changed files carries, is the project's own. A refusal publishes nothing (exit 1), a
#      failed lookup exits 3.
#   5. POST one review, always, a round with no finding included (`event` COMMENT, `commit_id` the reviewed head, and no
#      `comments` key: the review carries no inline comment). Every published finding, on a changed line or not, is one
#      entry of the Actionable comments block. The body is the visible layout, the record wrapped by
#      `cr_review_meta_wrap` (skills/_shared/cr-header.sh), the `<!-- cr-comment:lines=… -->` fingerprint that
#      skills/_shared/carry-operator-lines.php writes, and `<!-- cr-comment:actor=<login> -->`.
#   An Actionable comments entry is `**<icon> <severity> <n> — <title>** · <file:line>`, the problem, and `**Fix:** …`;
#   a `dry` finding's Fix names the existing implementation when it sets no `fix`. A finding with `suggestion` adds
#   `**Suggested code** (replaces line(s) …):` and the code in a fence of the file's language, one backtick longer than
#   the longest backtick run of the code. When the body would pass 65536 characters, the code of the last entries is left
#   out (their Fix line stays, stderr names the count); the code never fails the publish. The entry ends with the `@login`
#   mention line when `author` is set. The icon is 🔴 for Critical and 🟠 for Moderate.
#   Every value the body renders goes through one encoder (`inert` in JQ_DEFS). A closed code span on one line becomes a
#   `<code>` element whose content encodes every character Markdown or a GitHub autolink reads as syntax as an entity,
#   so it renders as written. Outside it, `&`, `<`, `>`, `|`, and `\` become HTML entities, `![` loses its image markup, a
#   backtick that closes no span on its line becomes an entity, and a line loses its indent, a leading `#`, and a run of
#   `-`, `=`, `*`, or `_`. The output holds no literal backtick, so GitHub pairs no code span another way, a backtick in a
#   link target included. No value renders as HTML, an image, a table cell break, a heading, or a code block. The checks
#   of the walkthrough and the findings (line starts, fences, the diagram comment marker) read the text after the control
#   characters are removed. The mermaid diagram and the suggested code are the values rendered as written, each inside
#   its own fence.
#   The body shows nothing but the layout of templates/review-body.md: the TL;DR, the Merge Risk block, the Walkthrough
#   block, the Where to test block (only when `test_routes` is set), and (when a finding is published) the Actionable
#   comments block, Critical first. It carries no CI result, no pre-merge checks, no recent review information, no
#   button, no share link, and no `cr-inline` marker.
#
# resolve
#   Resolves every unresolved inline thread that this actor opened for one of the given finding ids. Only a review
#   published by an earlier release has such a thread: `post` opens none. A comment is the agent's own only when the
#   actor wrote it and it carries `<!-- cr-inline:actor=<actor> -->`. A thread that holds any other comment (a reply by
#   another author, a comment of the actor without the marker) is not resolved and is reported as `has_reply`: its
#   comments are reviewer comments. Every page of the thread comments is read. Give only the ids that the round found
#   fixed. A thread of a finding that is rejected, deferred, or still open stays unresolved.
#
# Output (stdout, one JSON document):
#   post     {"review_url","review_id","listed":[{finding_id,path,line}],"withheld":[{finding_id}]}  Each `listed`
#            finding is one entry of the Actionable comments block. A withheld finding stays out of every public surface.
#   resolve  {"resolved":[{finding_id,thread_id}],"has_reply":[{finding_id,thread_id}],"not_open":[finding ids with no
#            open thread]}
#   stderr   `action=created id=<review id> listed=<n>` for post.
#
# Audit trail:
#   Each write appends its own line to the run's `.audit` file at the moment it happens, through
#   `skills/_shared/audit-trail.sh record`: an `external-write` line with the review URL (or the resolved thread), or a
#   `note` line for a failed attempt, both with the real exit status. The ledger comes from AUDIT_TRAIL_FILE and
#   AUDIT_TRAIL_ROLE. A missing ledger prints a hint on stderr and never changes the output or the exit code.
#
# Exit codes:
#   1  usage / argument / findings-document / walkthrough / record error
#   2  missing required tool (gh, jq, php)
#   3  GitHub API call failed (nothing was published unless the output says so)
#   4  the commit id is not the current head of the pull request; nothing was published
# The jq programs, the GraphQL documents, and the check expressions are single-quoted on purpose.
# shellcheck disable=SC2016
set -euo pipefail

PROG="post-inline-review.sh"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
AUDIT_TRAIL="$HERE/../../_shared/audit-trail.sh"
CHECK_HEADER="$HERE/../../_shared/check-cr-header.sh"
CARRY_LINES="$HERE/../../_shared/carry-operator-lines.php"
CHECK_VISIBLE="$HERE/../../_shared/check-visible-review-text.php"
# shellcheck source=../../_shared/github-target.sh
source "$HERE/../../_shared/github-target.sh"
# The one encoder of the hidden review record.
# shellcheck source=../../_shared/cr-header.sh
source "$HERE/../../_shared/cr-header.sh"
# The one definition of a test file (TEST_RE).
# shellcheck source=../../_shared/domain-vocabulary.sh
source "$HERE/../../_shared/domain-vocabulary.sh"

usage() {
  cat >&2 <<'EOF'
Usage: post-inline-review.sh post    <NUMBER|URL> --commit-id <SHA> --meta-file <RECORD_FILE> --walkthrough-file <FILE> <FINDINGS_FILE|->
       post-inline-review.sh resolve <NUMBER|URL> --fixed <FINDING_ID>[,<FINDING_ID>...]
       post-inline-review.sh --self-test

  NUMBER|URL    bare pull request number (resolved against the git remote) or a github.com /pull/<N> URL
  FINDINGS_FILE JSON array of findings (`[]` for a round with no finding), or `-` for stdin
  --commit-id   the head SHA the round reviewed; a head that moved is refused
  --meta-file   the hidden machine record of the round (templates/pr-comment-output.md)
  --walkthrough-file  the data of the visible Walkthrough block, a JSON object (templates/review-body.md)
  --fixed       finding ids whose inline threads are resolved
EOF
}

TMP_ROOT=""
cleanup() {
  if [[ -n "$TMP_ROOT" ]]; then
    rm -rf "$TMP_ROOT"
  fi
  return 0
}
trap cleanup EXIT

die() {
  local code="$1"
  shift
  echo "$PROG: $*" >&2
  exit "$code"
}

require_tools() {
  local bin
  for bin in gh jq php; do
    if ! command -v "$bin" >/dev/null 2>&1; then
      die 2 "required tool not found: $bin"
    fi
  done
}

# --- jq programs: program text this package ships, never built from input -------------------------------

# The definitions both normalisers share. A review carries no code, so `nocode` refuses a fenced block.
IFS= read -r -d '' JQ_DEFS <<'JQ' || true
def req($c; $m): if $c then . else error($m) end;
def isint: type == "number" and . == floor and . >= 1;
def isstr($max): type == "string" and length >= 1 and length <= $max;
def printable: explode | map(select((. >= 32 and . != 127) or . == 10 or . == 9)) | implode;
# inert: the one encoder of every value the visible body renders. It removes the control
# characters and works line by line. Outside a code span it encodes `&`, `<`, `>`, `|`, and `\` as HTML entities and `![`
# as `&#33;[`. A closed code span (a single backtick, its content, and the next single backtick of the same line) becomes
# a `<code>` element whose content encodes every character Markdown reads as inline syntax (`&`, `<`, `>`, `\`, `|`, a
# backtick, `*`, `_`, `~`, `[`, `]`, `(`, `)`) and the characters that start a GitHub autolink (`:`, and the dot of
# `www.`) as a numeric entity. A backtick that closes no span (an unpaired single backtick and every run of two or more)
# becomes `&#96;`. So the output holds no literal backtick: no code span is left for GitHub to pair another way, no link,
# emphasis, or autolink starts or ends inside a code element, and an entity renders as its character, so
# `$this->authorize()` and `Collection<int, Order>` read as written. Then it takes the markup off the start
# of the line: the indent, a heading `#`, and a line made only of `-`, `=`, `*`, or `_`. No value then renders as HTML, a
# comment marker, an image, a table cell break, a heading, a setext underline, or an indented code block.
def inert_text: gsub("&"; "&amp;") | gsub("<"; "&lt;") | gsub(">"; "&gt;") | gsub("\\|"; "&#124;") | gsub("\\\\"; "&#92;") | gsub("!\\["; "&#33;[");
def inert_code: gsub("(?<c>[&<>\\\\|`*_~\\[\\]():])"; "&#\(.c | explode[0]);") | gsub("(?<w>[Ww]{3})\\."; "\(.w)&#46;");
def inert_line:
  [scan("`+|[^`]+")] as $parts
  | ([$parts[] | select(. == "`")] | length) as $singles
  | reduce $parts[] as $p ({out: "", inside: false, seen: 0};
      if $p == "`" and .seen < ($singles - $singles % 2) then .out += (if .inside then "</code>" else "<code>" end) | .inside = (.inside | not) | .seen += 1
      elif .inside then .out += ($p | inert_code)
      elif ($p | startswith("`")) then .out += ($p | gsub("`"; "&#96;"))
      else .out += ($p | inert_text) end)
  | .out;
def inert: printable
  | split("\n")
  | map(sub("^[ \t]+"; "") | inert_line | if test("^#") or test("^[-=*_][-=*_ \t]*$") then "&#\(explode[0]);" + .[1:] else . end)
  | join("\n");
# scan_key: the text the withheld-finding check compares, the same for a title and for every scanned value: the words a
# reader sees. It removes the control characters, every invisible character (Unicode category Cf and every default
# ignorable code point), and the inline Markdown markup: an inline link and a full or collapsed reference link become
# their text (nested brackets in the text and balanced or escaped parentheses in the target included), every remaining
# bracket goes (a shortcut reference link and a link definition), and a backtick, `*`, `_`, and `~` go. Every kind of
# whitespace collapses. The comparison folds the Unicode case (`test(...; "i")`).
def scan_key: printable | gsub("[\\p{Cf}\\p{Default_Ignorable_Code_Point}]"; "")
  | gsub("\\[(?<t>(?:[^\\[\\]]|(?<b>\\[(?:[^\\[\\]]|\\g<b>)*\\]))*)\\](?<p>\\((?:\\\\.|[^()\\\\]|\\g<p>)*\\))"; .t)
  | gsub("\\[(?<t>(?:[^\\[\\]]|(?<b>\\[(?:[^\\[\\]]|\\g<b>)*\\]))*)\\]\\[[^\\]]*\\]"; .t)
  | gsub("[\\[\\]`*_~]"; "")
  | gsub("[\\s\\p{Z}]+"; " ");
# re_quote: a literal string as an Oniguruma pattern, every ASCII punctuation character escaped.
def re_quote: gsub("(?<c>[!-/:-@\\[-`{-~])"; "\\\(.c)");
def nocode: type == "string" and ((test("```|~~~")) | not);
def relpath: type == "string" and isstr(500) and (startswith("/") | not) and (explode | all(. >= 32 and . != 127)) and ((split("/") | index("..")) == null);
JQ

IFS= read -r -d '' JQ_NORMALIZE <<'JQ' || true
def optstr: . == null or (type == "string" and length >= 1 and length <= 4000);
def marker_free: . == null or ((type == "string") and ((test("<!--\\s*[a-z][a-z0-9-]*:(actor|finding|lines)=")) | not));
def codespan: gsub("`"; "");
req(type == "array"; "the findings document must be a JSON array")
| req(length <= 100; "at most 100 findings per review")
| map(
    req(type == "object"; "every finding must be an object")
    | req((has("faulty_example") or has("faulty_example_lang")) | not; "a review carries no faulty example code: leave out faulty_example and faulty_example_lang")
    | req(((keys - ["finding_id", "severity", "title", "path", "line", "start_line", "side", "problem", "fix", "suggestion", "do_not_file_publicly", "rule", "provenance", "impact", "evidence", "expected_behavior", "test_hint", "raised_by", "answered", "author", "level", "kind", "existing"]) | length) == 0; "a finding holds an unknown key")
    | req((.finding_id | type) == "string" and (.finding_id | test("^[rf]-[0-9a-f]{16}$")); "finding_id must be r- or f- and 16 hex digits")
    | req(.severity == "Critical" or .severity == "Moderate"; "severity must be Critical or Moderate")
    | req(.title | isstr(200); "title must be 1 to 200 characters")
    | req(.kind == null or .kind == "dry"; "kind must be dry when it is set")
    | req(.kind == null or .severity == "Moderate"; "a dry finding is Moderate; a duplicated security control keeps its severity and the Problem shape")
    | req((.path == null) == (.line == null); "path and line are set together, or both left out for a finding with no line")
    | req(.path == null or (.path | relpath); "path must be a relative repository path")
    | req(.line == null or (.line | isint); "line must be a positive integer")
    | req(.start_line == null or (.line != null and (.start_line | isint) and .start_line < .line); "start_line must be a positive integer below line")
    | req(.side == null or .side == "RIGHT" or .side == "LEFT"; "side must be RIGHT or LEFT")
    | req(.problem | isstr(4000); "problem must be 1 to 4000 characters")
    | req(.kind == "dry" or (.impact | isstr(4000)); "impact must be 1 to 4000 characters")
    | req(.kind != "dry" or ((.existing | type) == "string" and (.existing | test("^[^\\s`]+:[1-9][0-9]*$")) and (.existing | sub(":[0-9]+$"; "") | relpath)); "existing must be the <path>:<line> of the existing implementation")
    | req(.kind == "dry" or .existing == null; "existing belongs to a dry finding")
    | req(.kind == "dry" or (.fix | isstr(4000)); "fix must be 1 to 4000 characters: one plain sentence on what to change")
    | req(.kind != "dry" or (.fix | optstr); "fix must be 1 to 4000 characters when set")
    | req(.suggestion == null or (.suggestion | isstr(4000) and (printable | test("\\S"))); "suggestion must be 1 to 4000 characters of code when set")
    | req(.suggestion == null or (.suggestion | printable | test("<!--\\s*cr-") | not); "suggestion must not contain an agent marker")
    | req(.suggestion == null or (.suggestion | test("[\\x{061C}\\x{200E}\\x{200F}\\x{202A}-\\x{202E}\\x{2066}-\\x{2069}]") | not); "suggestion must not hold a bidirectional control character")
    | req(.do_not_file_publicly == null or (.do_not_file_publicly | type) == "boolean"; "do_not_file_publicly must be a boolean")
    | req((.rule | optstr) and (.provenance | optstr) and (.impact | optstr) and (.evidence | optstr) and (.expected_behavior | optstr) and (.test_hint | optstr) and (.answered | optstr); "rule, provenance, impact, evidence, expected_behavior, test_hint, and answered must be 1 to 4000 characters when set")
    | req([.title, .problem, .impact, .fix, .existing] | map(select(. != null) | printable) | all(nocode); "a review carries no code: title, problem, impact, fix, and existing hold no fenced block")
    | req((.rule | if . == null then null else codespan | printable end) | marker_free; "rule must not contain an agent marker")
    | req(.raised_by == null or .raised_by == "splinter" or .raised_by == "leonardo" or .raised_by == "both"; "raised_by must be splinter, leonardo, or both")
    | req(.level == null or (.severity == "Moderate" and (.level == "High" or .level == "Medium" or .level == "Low")); "level must be High, Medium, or Low and belongs to a Moderate")
    | req(.author == null or ((.author | type) == "string" and (.author | test("^@[A-Za-z0-9][A-Za-z0-9-]{0,38}$"))); "author must be an @login")
    | {
        finding_id,
        severity,
        title: (.title | inert),
        path,
        line,
        start_line,
        side: (.side // "RIGHT"),
        kind,
        existing: (.existing | if . == null then null else "`" + codespan + "`" | inert end),
        problem: (.problem | inert),
        impact: (.impact | if . == null then null else inert end),
        fix: (.fix | if . == null then null else inert end),
        suggestion: (.suggestion | if . == null then null else printable | split("\n") | map(sub("[ \t]+$"; "")) | join("\n") | sub("^\n+"; "") | sub("\\s+$"; "") end),
        lang: (.path | if . == null then "" else split("/") | last
          | if test("\\.blade\\.php$"; "i") then "blade" else (capture("\\.(?<e>[A-Za-z0-9+_-]{1,15})$") | .e | ascii_downcase) // "" end | if . == "suggestion" then "" else . end end),
        withhold: (.do_not_file_publicly == true),
        author
      }
    | req([.problem, .impact, .fix, .existing] | all(marker_free); "a text field must not contain an agent marker")
  )
# The flag holds for the finding id, and no merge drops it: every entry of a marked id carries it before the dedup below
# keeps one entry per id, path, and line.
| ([.[] | select(.withhold) | .finding_id] | unique) as $withheld_ids
| map(.withhold = (.finding_id | IN($withheld_ids[])))
| reduce .[] as $f ({seen: {}, out: []};
    ("\($f.finding_id) \($f.path) \($f.line)") as $k
    | if .seen[$k] then . else .seen[$k] = true | .out += [$f] end)
| .out
JQ

# A thread comment is the agent's own only when the current actor wrote it and it carries the actor's marker.
# The marker alone grants nothing: any other author, and a comment of the actor without the marker, is a
# reviewer comment.
IFS= read -r -d '' JQ_AGENT <<'JQ' || true
def agent_comment($actor):
  ((.author.login // "") == $actor) and ((.body // "") | contains("<!-- cr-inline:actor=\($actor) -->"));
JQ

# Every finding is listed in the Actionable comments block, except a withheld one. The flag holds for the finding id:
# every entry that shares the id of a withheld entry is withheld too.
IFS= read -r -d '' JQ_CLASSIFY <<'JQ' || true
[.[] | select(.withhold) | .finding_id] as $withheld_ids
| map(if (.finding_id | IN($withheld_ids[])) and ($private | not) then {class: "withheld", f: .} else {class: "listed", f: .} end)
JQ

# The Merge Risk block reads its grade and its sentence from the `Merge risk:` line of the hidden record, the one owner
# of both. The line is `**Merge risk:** <low|medium|high> — <one sentence> · impact: … · up to <short SHA>`. The sentence
# ends where the next ` · ` segment of the line starts. The one allowed `Review independence:` line of the record, the
# line of a review that the session which wrote the change ran itself, is shown under the summary; any other value of
# that line is refused.
IFS= read -r -d '' JQ_RISK <<'JQ' || true
"**Review independence:** not independent — helper agents are forbidden for this project" as $self_review
| ($meta | split("\n") | map(sub("\\s+$"; "")) | map(select(startswith("**Review independence:**")))) as $independence_lines
| (if ($independence_lines | length) == 0 then null
   elif $independence_lines == [$self_review] then $self_review
   else error("the record carries a Review independence line other than `\($self_review)`") end) as $independence
| ($meta | split("\n") | map(select(startswith("**Merge risk:** "))) | first // "") as $line
| ($line | capture("^\\*\\*Merge risk:\\*\\* (?<grade>low|medium|high) — (?<rest>.+)$")? // null)
| if . == null then error("the record has no Merge risk line of the form `**Merge risk:** low|medium|high — <sentence>`")
  else
    (.rest | split(" · ") | .[0] | printable | gsub("\\s+"; " ") | ltrimstr(" ") | rtrimstr(" ")) as $sentence
    | if ($sentence | length) < 1 then error("the Merge risk line of the record holds no sentence")
      else {grade, sentence: ($sentence | inert), icon: (if .grade == "low" then "🟢" elif .grade == "medium" then "🟡" else "🔴" end), independence: $independence}
      end
  end
JQ

# The data of the Walkthrough block, checked against the files of the pull request: a row names changed files only.
IFS= read -r -d '' JQ_WALK <<'JQ' || true
def oneline: printable | gsub("\\s+"; " ") | ltrimstr(" ") | rtrimstr(" ");
def cell: oneline | inert;
def level_name: ["Trivial", "Simple", "Moderate", "Complex", "Critical"][. - 1];
def testfile: ascii_downcase | test($test_re);
($files[0] | map(.filename, (.previous_filename // empty)) | map({key: ., value: true}) | from_entries) as $changed
| req(type == "object"; "the walkthrough must be a JSON object")
| req(((keys - ["tldr", "title", "description", "areas", "effort", "change_type", "diagram", "test_routes", "since"]) | length) == 0; "the walkthrough holds an unknown key")
| req(.since == null or (.since | type == "string" and test("^[0-9a-f]{40}$")); "walkthrough since must be a full 40-character commit SHA when set")
| req(.tldr | isstr(1000); "walkthrough tldr must be 1 to 500 characters")
| req(.tldr | printable | split("\n") | all(test("^\\s*[#>|<]") | not); "the walkthrough tldr is plain sentences: no line starts with #, >, |, or <")
| req(.description | isstr(1500); "walkthrough description must be 1 to 1500 characters")
| req(.title == null or (.title | isstr(100)); "walkthrough title must be 1 to 100 characters when set")
| req(.test_routes == null or ((.test_routes | type) == "array" and (.test_routes | length) <= 30
    and (.test_routes | all(type == "object" and ((keys - ["method", "path", "try"]) | length) == 0
      and (.method | IN("GET", "POST", "PUT", "PATCH", "DELETE")) and (.path | isstr(200) and test("^/[^\\s`<>|]*$")) and (.try | isstr(200))))); "test_routes must hold at most 30 rows of a method (GET, POST, PUT, PATCH, DELETE), a path that starts with /, and a line on what to try")
| req([.tldr, .description, .title, (.areas | if type == "array" then .[] | objects | .layer, .summary, (.files | arrays | .[]) else empty end), (.test_routes // [] | .[] | .path, .try)] | map(select(type == "string") | printable) | all(nocode); "the walkthrough carries no code: no fenced block in the tldr, the description, the title, a layer, a summary, a file, or a line to try")
| req((.areas | type) == "array" and (.areas | length) >= 1 and (.areas | length) <= 20; "walkthrough areas must hold 1 to 20 rows")
| req(.areas | all(type == "object" and ((keys - ["layer", "files", "summary"]) | length) == 0 and (.layer | isstr(100)) and (.summary | isstr(300))
    and ((.files | type) == "array") and (.files | length) >= 1 and (.files | length) <= 500 and (.files | all(relpath))); "every walkthrough area needs a layer, a summary, and 1 to 500 relative file paths")
| req([.areas[].files[]] | all(. as $f | $changed[$f] == true); "a walkthrough area names a file that the reviewed changes do not touch")
| req(($changed | keys | all(testfile)) or ([.areas[].files[]] | all(testfile | not)); "a walkthrough area names a test file: the walkthrough lists no test file unless the pull request changes only tests")
| req(.effort | type == "object" and ((keys - ["level", "minutes"]) | length) == 0 and (.level | isint) and .level <= 5 and (.minutes | isint) and .minutes <= 999; "walkthrough effort needs a level of 1 to 5 and minutes of 1 to 999")
| req(.change_type as $c | ["Feature", "Bug fix", "Refactor", "Performance", "Security", "Tests", "Documentation", "Chore"] | any(. == $c); "walkthrough change_type must be Feature, Bug fix, Refactor, Performance, Security, Tests, Documentation, or Chore")
| req(.diagram == null or (.diagram | type == "string" and length <= 4000 and (printable | test("^sequenceDiagram") and nocode and (test("<!--") | not) and ((split("\n") | length) <= 60))); "the walkthrough diagram must be a mermaid sequenceDiagram of 60 lines at most, with no fenced block and no comment marker")
| {
    tldr: (.tldr | printable | gsub("^\\s+|\\s+$"; "") | inert),
    since: (.since | if . == null then null else .[0:7] end),
    title: (.title | if . == null then null else oneline | inert end),
    description: (.description | oneline | inert),
    areas: [.areas[] | {layer: (.layer | cell), summary: (.summary | cell),
        files: ((.files | length) as $n | (.files[0:4] | map("`" + gsub("`"; "") + "`" | inert) | join(", ")) + (if $n > 4 then " and \($n - 4) more" else "" end))}],
    effort: "\(.effort.level) (\(.effort.level | level_name)) | ~\(.effort.minutes) minutes",
    change_type,
    diagram: (.diagram | if . == null then null else printable | rtrimstr("\n") end),
    routes: [(.test_routes // [])[] | "| \("`\(.method) \(.path | gsub("`"; ""))`" | inert) | \(.try | cell) |"]
  }
| req((.tldr | length) >= 1 and (.tldr | length) <= 500; "the walkthrough tldr is \(.tldr | length) characters once normalised; it must hold 1 to 500, so write it shorter")
JQ

# The draft body of the review. It ends with the actor marker; the fingerprint marker is added by
# carry-operator-lines.php before JQ_REVIEW builds the request. Every finding that is not withheld is one Actionable
# comments entry, on a changed line or not: the review carries no inline comment.
IFS= read -r -d '' JQ_PAYLOAD <<'JQ' || true
def icon: if . == "Critical" then "🔴" else "🟠" end;
def oneline: gsub("\\s+"; " ") | ltrimstr(" ") | rtrimstr(" ");
def place: if .line == null then "" else " · " + ("`\(.path | gsub("`"; "")):\(.line)`" | inert) end;
def fix_text: if .kind == "dry" then (.fix // "Use the existing implementation in \(.existing) instead of repeating the logic.") else .fix end;
def mention: if .author == null then "" else "\n\(.author)" end;
# The suggested code of a finding: a fence one backtick longer than the longest backtick run of the code (three at
# least), so no line of the code closes it, and the language of the finding's file. The code renders as written.
def fence($s): ((([$s | match("`+"; "g") | .length] | max) // 0) as $m | [$m, 2] | max + 1) as $n | ("`" * $n);
def code_block: fence(.suggestion) as $f | "\($f)\(.lang)\n\(.suggestion)\n\($f)";
def replaces: if .line == null or .side == "LEFT" then "" elif .start_line != null then " (replaces lines \(.start_line)–\(.line))" else " (replaces line \(.line))" end;
def code_part: if .suggestion == null then "" else "\n**Suggested code**\(replaces):\n\(code_block)" end;
def entry($n): "**\(.severity | icon) \(.severity) \($n) — \(.title | oneline)**\(place)\n\(.problem | oneline)\n**Fix:** \(fix_text | oneline)\(if .show then code_part else "" end)\(mention)";
def collapsed($summary; $content): "<details>\n<summary>\($summary)</summary>\n<dl>\n<dd>\n<br>\n\n\($content)\n\n</dd>\n</dl>\n</details>";
[.[] | select(.class == "listed") | .f] as $listed
# The suggested code fits the budget of the body: it is shown in entry order, Critical first, while the code parts
# shown so far fit `$budget` characters; from the first code part past the budget on, every code part is left out, and
# each entry keeps its Fix sentence.
| ([$listed[] | select(.severity == "Critical")] + [$listed[] | select(.severity == "Moderate")]
   | reduce .[] as $f ({used: 0, full: false, out: []};
       ($f | code_part | length) as $c
       | if $c > 0 and (.full | not) and .used + $c <= $budget then .used += $c | .out += [$f + {show: true}]
         else (if $c > 0 then .full = true else . end) | .out += [$f + {show: false}] end)) as $coded
| [$coded.out[] | select(.severity == "Critical")] as $critical_f
| [$coded.out[] | select(.severity == "Moderate")] as $moderate_f
| ($critical_f | to_entries | map(.key as $i | .value | entry($i + 1))) as $critical
| ($moderate_f | to_entries | map(.key as $i | .value | entry($i + 1))) as $moderate
| ($critical + $moderate) as $entries
| $walk.tldr as $tldr_block
| collapsed("<strong>Merge Risk:</strong> <strong>\($risk.icon) \($risk.grade | .[0:1] | ascii_upcase)\($risk.grade | .[1:])</strong> · up to <code>\($short)</code>"; $risk.sentence) as $risk_block
| (([$walk.description]
    + (if $walk.title == null then [] else ["**Changes: \($walk.title)**"] end)
    + [(["|Layer / File(s)|Summary|", "|:---|:---|"] + [$walk.areas[] | "|**\(.layer)** <br> \(.files)|\(.summary)|"] | join("\n"))]
    + ["**Estimated code review effort:** \($walk.effort)\n**Change:** \($walk.change_type)"]
    + (if $walk.diagram == null then [] else ["### Sequence Diagram(s)", "```mermaid\n\($walk.diagram)\n```"] end))
   | join("\n\n")) as $walk_content
| collapsed("<strong>Walkthrough</strong>" + (if $walk.since == null then "" else " · since <code>\($walk.since)</code>" end); $walk_content) as $walk_block
| (if ($walk.routes | length) == 0 then null else collapsed("<strong>Where to test</strong>"; (["| Method and path | What to try |", "| :--- | :--- |"] + $walk.routes) | join("\n")) end) as $test_block
| (if ($entries | length) == 0 then null
   else collapsed("<strong>Actionable comments (\($entries | length))</strong>"; $entries | join("\n\n")) end) as $actionable_block
| {
    listed: ($entries | length),
    code_used: $coded.used,
    code_shown: ([$coded.out[] | select(.show)] | length),
    code_omitted: ([$coded.out[] | select(.show | not) | select(.suggestion != null)] | length),
    blocks: ((if $walk.diagram == null then [] else ["```mermaid\n\($walk.diagram)\n```"] end) + [$coded.out[] | select(.show) | code_block]),
    body: ([$tldr_block, $risk.independence, $risk_block, $walk_block, $test_block, $actionable_block, ($meta | rtrimstr("\n")), "<!-- cr-comment:actor=\($actor) -->"] | map(select(. != null)) | join("\n\n")),
    visible_text: ([$walk.tldr, $risk.independence, $risk.sentence, $walk_content] + $walk.routes + $entries | map(select(. != null)) | join("\n\n"))
  }
JQ

# The request: the review carries no inline comment, so it has no `comments` key. The final body must hold exactly one
# hidden record, one fingerprint marker, and one actor marker, no inline marker, and no fenced block in the visible part
# but the diagram of the Walkthrough, and it must fit the 65536 characters GitHub accepts.
IFS= read -r -d '' JQ_REVIEW <<'JQ' || true
def count($re): [match($re; "g")] | length;
{commit_id: $commit, event: "COMMENT", body: $body} as $r
| ($body | split("\n<!-- cr-review-meta\n")[0]) as $visible
| if ($body | count("<!--\\s*cr-inline:")) == 0
     and ($body | count("<!--\\s*cr-review-meta")) == 1 and ($body | count("<!--\\s*cr-comment:lines=")) == 1
     and ($body | count("<!--\\s*cr-comment:actor=")) == 1 and ($body | count("<!--\\s*cr-comment:")) == 2
  then . else error("the review body does not hold exactly its own markers") end
# The body lines lose their trailing blanks on the way (the line fingerprint trims them), so each allowed block is
# trimmed the same way, and the longest block is removed first, so a block that holds another one is removed whole.
| if (reduce (.blocks | map(split("\n") | map(sub("[ \t]+$"; "")) | join("\n")) | sort_by(-length) | .[]) as $b ($visible; split($b) | join("")) | count("```|~~~")) == 0
  then . else error("the visible review body holds a fenced block other than the sequence diagram and the suggested code of a finding") end
| if ($body | length) <= 65536 then $r else error("the review body is longer than the 65536 characters GitHub accepts") end
JQ

THREADS_QUERY='query($owner:String!,$repo:String!,$number:Int!,$cursor:String){repository(owner:$owner,name:$repo){pullRequest(number:$number){reviewThreads(first:100,after:$cursor){pageInfo{hasNextPage endCursor} nodes{id isResolved path line originalLine comments(first:100){pageInfo{hasNextPage endCursor} nodes{author{login} body}}}}}}}'
COMMENTS_QUERY='query($threadId:ID!,$cursor:String){node(id:$threadId){... on PullRequestReviewThread{comments(first:100,after:$cursor){pageInfo{hasNextPage endCursor} nodes{author{login} body}}}}}'
RESOLVE_MUTATION='mutation($threadId:ID!){resolveReviewThread(input:{threadId:$threadId}){thread{isResolved}}}'

# Writes the review threads of the pull request, every page, to the file given as $1 as one JSON array.
fetch_threads() {
  local out="$1" cursor="" page=0 resp
  printf '[]' >"$out"
  while :; do
    page=$((page + 1))
    if [[ $page -gt 50 ]]; then
      die 3 "more than 50 pages of review threads on ${NWO}#${NUMBER}; nothing was published"
    fi
    if [[ -n "$cursor" ]]; then
      resp="$(gh api graphql -f query="$THREADS_QUERY" -f owner="$OWNER" -f repo="$REPO" -F number="$NUMBER" -f cursor="$cursor" 2>"$TMP_ROOT/threads.err")" \
        || die 3 "review thread lookup failed on ${NWO}#${NUMBER}, nothing was published: $(cat "$TMP_ROOT/threads.err")"
    else
      resp="$(gh api graphql -f query="$THREADS_QUERY" -f owner="$OWNER" -f repo="$REPO" -F number="$NUMBER" 2>"$TMP_ROOT/threads.err")" \
        || die 3 "review thread lookup failed on ${NWO}#${NUMBER}, nothing was published: $(cat "$TMP_ROOT/threads.err")"
    fi
    printf '%s' "$resp" >"$TMP_ROOT/page.json"
    jq -e '.data.repository.pullRequest.reviewThreads.nodes | type == "array"' "$TMP_ROOT/page.json" >/dev/null 2>&1 \
      || die 3 "review thread lookup on ${NWO}#${NUMBER} returned no thread list, nothing was published"
    jq -s '.[0] + .[1].data.repository.pullRequest.reviewThreads.nodes' "$out" "$TMP_ROOT/page.json" >"$out.next"
    mv "$out.next" "$out"
    if [[ "$(jq -r '.data.repository.pullRequest.reviewThreads.pageInfo.hasNextPage' "$TMP_ROOT/page.json")" != "true" ]]; then
      extend_thread_comments "$out"
      return 0
    fi
    cursor="$(jq -r '.data.repository.pullRequest.reviewThreads.pageInfo.endCursor // empty' "$TMP_ROOT/page.json")"
    [[ -n "$cursor" ]] || die 3 "review thread lookup on ${NWO}#${NUMBER} returned a next page without a cursor, nothing was published"
  done
}

# Pages the comments of every thread whose comment list has a next page, so a reply past the first 100 is seen.
extend_thread_comments() {
  local file="$1" thread_id cursor page resp
  while IFS= read -r thread_id; do
    cursor="$(jq -r --arg id "$thread_id" '.[] | select(.id == $id) | .comments.pageInfo.endCursor // empty' "$file")"
    page=0
    while [[ -n "$cursor" ]]; do
      page=$((page + 1))
      if [[ $page -gt 50 ]]; then
        die 3 "more than 50 pages of comments in one review thread on ${NWO}#${NUMBER}; nothing was published"
      fi
      resp="$(gh api graphql -f query="$COMMENTS_QUERY" -f threadId="$thread_id" -f cursor="$cursor" 2>"$TMP_ROOT/comments.err")" \
        || die 3 "review thread comment lookup failed on ${NWO}#${NUMBER}, nothing was published: $(cat "$TMP_ROOT/comments.err")"
      printf '%s' "$resp" >"$TMP_ROOT/page.json"
      jq -e '.data.node.comments.nodes | type == "array"' "$TMP_ROOT/page.json" >/dev/null 2>&1 \
        || die 3 "review thread comment lookup on ${NWO}#${NUMBER} returned no comment list, nothing was published"
      jq --arg id "$thread_id" --slurpfile page "$TMP_ROOT/page.json" '
        map(if .id == $id
            then .comments.nodes += $page[0].data.node.comments.nodes | .comments.pageInfo = $page[0].data.node.comments.pageInfo
            else . end)' "$file" >"$file.next"
      mv "$file.next" "$file"
      if [[ "$(jq -r '.data.node.comments.pageInfo.hasNextPage' "$TMP_ROOT/page.json")" == "true" ]]; then
        cursor="$(jq -r '.data.node.comments.pageInfo.endCursor // empty' "$TMP_ROOT/page.json")"
      else
        cursor=""
      fi
    done
  done < <(jq -r '.[] | select(.comments.pageInfo.hasNextPage == true) | .id' "$file")
}

read_head() {
  local resp sha
  resp="$(gh api "repos/${NWO}/pulls/${NUMBER}" 2>"$TMP_ROOT/head.err")" \
    || die 3 "pull request lookup failed on ${NWO}#${NUMBER}, nothing was published: $(cat "$TMP_ROOT/head.err")"
  sha="$(printf '%s' "$resp" | jq -r '.head.sha // empty' 2>/dev/null || true)"
  [[ "$sha" =~ ^[0-9a-f]{40}$ ]] || die 3 "pull request ${NWO}#${NUMBER} returned no head SHA, nothing was published"
  HEAD_SHA="$sha"
}

# Renders the draft body for one code budget, checks its visible text for the package's own tooling, and adds the line
# fingerprint: writes draft.json, visible.md, and body.md.
render_review_body() {
  local commit="$1" budget="$2"
  jq --arg actor "$ACTOR" --rawfile meta "$TMP_ROOT/meta.wrapped" --slurpfile risk_doc "$TMP_ROOT/risk.json" --slurpfile walk_doc "$TMP_ROOT/walk.json" \
    --arg short "${commit:0:7}" --argjson budget "$budget" \
    "$JQ_DEFS"$'\n''$risk_doc[0] as $risk | $walk_doc[0] as $walk | '"$JQ_PAYLOAD" "$TMP_ROOT/classified.json" >"$TMP_ROOT/draft.json" 2>"$TMP_ROOT/payload.err" \
    || die 1 "the rendered review was refused, nothing was published: $(sed -E 's/^jq: error \(at [^)]*\): //' "$TMP_ROOT/payload.err" | head -n 1)"
  jq -j '.visible_text' "$TMP_ROOT/draft.json" >"$TMP_ROOT/visible.md"
  local visible_rc=0
  php "$CHECK_VISIBLE" --text "$TMP_ROOT/visible.md" --repo-paths "$TMP_ROOT/repo-paths.txt" --diff-text "$TMP_ROOT/diff-text.txt" >"$TMP_ROOT/visible-check.json" 2>"$TMP_ROOT/visible.err" || visible_rc=$?
  if [[ "$visible_rc" -eq 3 ]]; then
    die 1 "the visible review text names the package tooling $(jq -r '.names | join(", ")' "$TMP_ROOT/visible-check.json" | head -c 300); describe the step in plain words (rules/code-review/general.md *Visible review text names none of the package's own tooling*), nothing was published"
  fi
  [[ "$visible_rc" -eq 0 ]] || die 1 "the check of the visible review text did not pass, nothing was published: $(head -c 300 "$TMP_ROOT/visible.err")"
  jq -j '.body' "$TMP_ROOT/draft.json" >"$TMP_ROOT/body.draft"
  php "$CARRY_LINES" markdown cr-comment "$TMP_ROOT/body.draft" >"$TMP_ROOT/body.md" 2>"$TMP_ROOT/carry.err" \
    || die 1 "the line fingerprint of the review body failed, nothing was published: $(head -c 300 "$TMP_ROOT/carry.err")"
}

cmd_post() {
  local commit="" findings_src="" meta_file="" walk_file="" arg
  while [[ $# -gt 0 ]]; do
    arg="$1"
    case "$arg" in
      --commit-id)
        [[ $# -ge 2 ]] || die 1 "--commit-id needs a value"
        commit="$2"
        shift 2
        ;;
      --meta-file)
        [[ $# -ge 2 ]] || die 1 "--meta-file needs a value"
        meta_file="$2"
        shift 2
        ;;
      --walkthrough-file)
        [[ $# -ge 2 ]] || die 1 "--walkthrough-file needs a value"
        walk_file="$2"
        shift 2
        ;;
      *)
        [[ -z "$findings_src" ]] || die 1 "unexpected argument: $arg"
        findings_src="$arg"
        shift
        ;;
    esac
  done
  [[ -n "$commit" && -n "$findings_src" && -n "$meta_file" && -n "$walk_file" ]] || { usage; exit 1; }
  [[ "$commit" =~ ^[0-9a-f]{40}$ ]] || die 1 "--commit-id must be a full lowercase 40-digit SHA"
  [[ -r "$meta_file" && -s "$meta_file" ]] || die 1 "cannot read the review record: $meta_file"
  cat "$meta_file" >"$TMP_ROOT/meta.md"
  "$CHECK_HEADER" --file "$TMP_ROOT/meta.md" >"$TMP_ROOT/meta-check.json" 2>/dev/null \
    || die 1 "the review record was refused by check-cr-header.sh, nothing was published: $(jq -r '.problems // [] | join("; ")' "$TMP_ROOT/meta-check.json" 2>/dev/null | head -c 300)"
  if grep -Eq '^[[:space:]]*(```|~~~)[[:space:]]*suggestion' "$TMP_ROOT/meta.md"; then
    die 1 "the review record holds a suggestion block: a review carries no code, nothing was published"
  fi
  if ! awk '
    /^## / { inside = ($0 ~ /^## Interactive testing[[:space:]]*$/); header = 0; next }
    inside && /^\|/ {
      if (!header) { header = 1; next }
      n = split($0, cell, "|")
      verdict = cell[4]
      gsub(/^[[:space:]]+|[[:space:]]+$/, "", verdict)
      if (verdict ~ /^:?-+:?$/) next
      if (verdict == "Blocked") blocked++
      else ran++
    }
    END { exit (blocked > 0 && ran == 0) ? 1 : 0 }
  ' "$TMP_ROOT/meta.md"; then
    die 1 "the review record reports interactive testing that did not run: every result row is Blocked, so leave the section out; nothing was published"
  fi
  jq -n -c --rawfile meta "$TMP_ROOT/meta.md" "$JQ_DEFS"$'\n'"$JQ_RISK" >"$TMP_ROOT/risk.json" 2>"$TMP_ROOT/risk.err" \
    || die 1 "the Merge Risk block could not be read from the review record, nothing was published: $(sed -E 's/^jq: error \(at [^)]*\): //' "$TMP_ROOT/risk.err" | head -n 1)"
  [[ -r "$walk_file" && -s "$walk_file" ]] || die 1 "cannot read the walkthrough file: $walk_file"

  if [[ "$findings_src" == "-" ]]; then
    cat >"$TMP_ROOT/findings.raw"
  else
    [[ -r "$findings_src" ]] || die 1 "cannot read the findings file: $findings_src"
    cat "$findings_src" >"$TMP_ROOT/findings.raw"
  fi
  jq "$JQ_DEFS"$'\n'"$JQ_NORMALIZE" "$TMP_ROOT/findings.raw" >"$TMP_ROOT/findings.json" 2>"$TMP_ROOT/normalize.err" \
    || die 1 "invalid findings document: $(sed -E 's/^jq: error \(at [^)]*\): //' "$TMP_ROOT/normalize.err" | head -n 1)"

  github_actor_resolve || exit 3
  read_head
  if [[ "$HEAD_SHA" != "$commit" ]]; then
    echo "$PROG: the commit id ${commit} is not the current head ${HEAD_SHA} of ${NWO}#${NUMBER}; nothing was published" >&2
    exit 4
  fi

  local private_value="" private=false
  private_value="$(gh repo view "$NWO" --json isPrivate --jq .isPrivate 2>/dev/null || true)"
  if [[ "$private_value" == "true" ]]; then
    private=true
  fi

  gh api "repos/${NWO}/pulls/${NUMBER}/files" --paginate >"$TMP_ROOT/files.raw" 2>"$TMP_ROOT/files.err" \
    || die 3 "changed file lookup failed on ${NWO}#${NUMBER}, nothing was published: $(cat "$TMP_ROOT/files.err")"
  jq -s 'add // []' "$TMP_ROOT/files.raw" >"$TMP_ROOT/files.json" 2>/dev/null \
    || die 3 "changed file lookup on ${NWO}#${NUMBER} returned no file list, nothing was published"
  gh api "repos/${NWO}/git/trees/${commit}?recursive=1" >"$TMP_ROOT/tree.json" 2>"$TMP_ROOT/tree.err" \
    || die 3 "repository tree lookup failed on ${NWO}@${commit}, nothing was published: $(cat "$TMP_ROOT/tree.err")"
  jq -r -s '(.[0].tree // [])[].path, (.[1][] | .filename, (.previous_filename // empty))' "$TMP_ROOT/tree.json" "$TMP_ROOT/files.json" >"$TMP_ROOT/repo-paths.txt" 2>/dev/null \
    || die 3 "repository tree lookup on ${NWO}@${commit} returned no path list, nothing was published"
  jq -r '.[] | (.patch // "") | split("\n")[] | select(test("^[+-]")) | .[1:]' "$TMP_ROOT/files.json" >"$TMP_ROOT/diff-text.txt" 2>/dev/null \
    || die 3 "changed file lookup on ${NWO}#${NUMBER} returned no patch text, nothing was published"
  # A walkthrough with `since` covers the changes since the previous published review: its areas are checked against
  # the files the compare of that commit and the head changes, never against the whole pull request. A compare file that
  # is no file of the pull request (a merge of the default branch brought it in) stays out of the scope.
  cp "$TMP_ROOT/files.json" "$TMP_ROOT/scope.json"
  local since="" compare_status=""
  since="$(jq -r 'if type == "object" then .since // empty | strings else empty end' "$walk_file" 2>/dev/null || true)"
  if [[ -n "$since" ]]; then
    [[ "$since" =~ ^[0-9a-f]{40}$ ]] || die 1 "the walkthrough was refused, nothing was published: walkthrough since must be a full 40-character commit SHA when set"
    gh api "repos/${NWO}/compare/${since}...${commit}" >"$TMP_ROOT/compare.json" 2>"$TMP_ROOT/compare.err" \
      || die 3 "compare lookup of ${since}...${commit} failed on ${NWO}, nothing was published: $(cat "$TMP_ROOT/compare.err")"
    compare_status="$(jq -r '.status // empty' "$TMP_ROOT/compare.json" 2>/dev/null || true)"
    [[ "$compare_status" == "ahead" ]] \
      || die 1 "the walkthrough was refused, nothing was published: since ${since} is not an earlier commit of the head ${commit} (compare status: ${compare_status:-none}); leave since out and describe the whole pull request"
    jq --slurpfile pr "$TMP_ROOT/files.json" '($pr[0] | map(.filename, (.previous_filename // empty))) as $names
      | [(.files // [])[] | select((.filename | IN($names[])) or (.previous_filename | IN($names[])))]' "$TMP_ROOT/compare.json" >"$TMP_ROOT/scope.json" 2>/dev/null \
      || die 3 "compare lookup of ${since}...${commit} on ${NWO} returned no file list, nothing was published"
  fi
  jq --arg test_re "$TEST_RE" --slurpfile files "$TMP_ROOT/scope.json" "$JQ_DEFS"$'\n'"$JQ_WALK" "$walk_file" >"$TMP_ROOT/walk.json" 2>"$TMP_ROOT/walk.err" \
    || die 1 "the walkthrough was refused, nothing was published: $(sed -E 's/^jq: error \(at [^)]*\): //' "$TMP_ROOT/walk.err" | head -n 1)"

  jq --argjson private "$private" "$JQ_CLASSIFY" "$TMP_ROOT/findings.json" >"$TMP_ROOT/classified.json"

  # Hidden HTML is public, and the walkthrough and the other findings are visible: a record, a walkthrough, or a published
  # finding that names a withheld finding by its id or its title is refused. The check reads the decoded string values
  # of the JSON inputs, never their bytes, so a JSON escape (`\/`, `\"`, `\\`, `\uXXXX`) hides nothing, and it
  # compares one scan key (scan_key) on both sides, with the Unicode case folded. It reads the title of every raw entry of
  # a withheld id, a duplicate the dedup dropped included, and every raw entry whose id is not withheld.
  local leaked
  leaked="$(jq -n -r --rawfile meta "$TMP_ROOT/meta.md" --slurpfile walk "$walk_file" --slurpfile raw "$TMP_ROOT/findings.raw" \
    --slurpfile classified "$TMP_ROOT/classified.json" "$JQ_DEFS"$'\n''
    ([$classified[0][] | select(.class == "withheld") | .f.finding_id] | unique) as $ids
    | ([$meta] + [$walk[0] | .. | strings] + [$raw[0][] | select((.finding_id | IN($ids[])) | not) | .. | strings]
       | map(scan_key) | join("\n")) as $m
    | [$raw[0][] | select(.finding_id | IN($ids[])) | {id: .finding_id, t: (.title | scan_key | ltrimstr(" ") | rtrimstr(" "))}
       | select(.id as $id | .t as $t | ($m | test($id | re_quote; "i")) or ($t != "" and ($m | test($t | re_quote; "i")))) | .id]
    | unique | join(",")')"
  [[ -z "$leaked" ]] || die 1 "the review record, the walkthrough, or a published finding names the withheld finding(s) ${leaked}; a finding marked do_not_file_publicly stays out of every public text on a public repository, nothing was published"

  cr_review_meta_wrap <"$TMP_ROOT/meta.md" >"$TMP_ROOT/meta.wrapped"
  # The suggested code never fails the publish: when the body is longer than the 65536 characters GitHub accepts, the
  # body is rendered again with a code budget smaller by the excess, so code parts are left out until it fits, and the
  # last pass shows no code at all.
  local budget=65536 pass body_len code_used
  for pass in 1 2 3 4; do
    [[ "$pass" -lt 4 ]] || budget=0
    render_review_body "$commit" "$budget"
    body_len="$(jq -R -s 'length' "$TMP_ROOT/body.md")"
    code_used="$(jq '.code_used' "$TMP_ROOT/draft.json")"
    if [[ "$body_len" -le 65536 || "$code_used" -eq 0 ]]; then
      break
    fi
    budget=$((code_used - (body_len - 65536) - 256))
    [[ "$budget" -gt 0 ]] || budget=0
  done
  jq --arg commit "$commit" --rawfile body "$TMP_ROOT/body.md" "$JQ_REVIEW" "$TMP_ROOT/draft.json" >"$TMP_ROOT/payload.json" 2>"$TMP_ROOT/payload.err" \
    || die 1 "the rendered review was refused, nothing was published: $(sed -E 's/^jq: error \(at [^)]*\): //' "$TMP_ROOT/payload.err" | head -n 1)"
  if [[ "$(jq '.code_omitted' "$TMP_ROOT/draft.json")" -gt 0 ]]; then
    echo "$PROG: the suggested code of $(jq '.code_omitted' "$TMP_ROOT/draft.json") finding(s) was left out to keep the review body within 65536 characters" >&2
  fi

  local listed
  listed="$(jq '.listed' "$TMP_ROOT/draft.json")"

  read_head
  if [[ "$HEAD_SHA" != "$commit" ]]; then
    echo "$PROG: the head of ${NWO}#${NUMBER} moved to ${HEAD_SHA} while the review was prepared; nothing was published" >&2
    exit 4
  fi
  local review_url="" review_id="" write_rc=0 response=""
  response="$(gh api "repos/${NWO}/pulls/${NUMBER}/reviews" -X POST --input - <"$TMP_ROOT/payload.json" 2>"$TMP_ROOT/post.err")" || write_rc=$?
  review_url="$(printf '%s' "$response" | jq -r '.html_url // empty' 2>/dev/null || true)"
  if [[ -z "$review_url" ]]; then
    [[ "$write_rc" -ne 0 ]] || write_rc=1
    bash "$AUDIT_TRAIL" record --target "pull request review on ${NWO}#${NUMBER}" --status "$write_rc" || true
    die 3 "review request failed on ${NWO}#${NUMBER}: $(printf '%s' "$response" | jq -r '.message // empty' 2>/dev/null || true) $(head -c 300 "$TMP_ROOT/post.err")"
  fi
  review_id="$(printf '%s' "$response" | jq -r '.id // empty')"
  bash "$AUDIT_TRAIL" record --target "$review_url" --status 0 --detail "created review with ${listed} listed findings and no inline comment" || true
  echo "action=created id=${review_id} listed=${listed}" >&2

  jq --arg url "$review_url" --arg id "$review_id" '
    def pick($c): [.[] | select(.class == $c) | .f | {finding_id, path, line}];
    {
      review_url: $url,
      review_id: $id,
      listed: pick("listed"),
      withheld: [.[] | select(.class == "withheld") | .f | {finding_id}]
    }' "$TMP_ROOT/classified.json"
}

cmd_resolve() {
  local fixed="" arg
  while [[ $# -gt 0 ]]; do
    arg="$1"
    case "$arg" in
      --fixed)
        [[ $# -ge 2 ]] || die 1 "--fixed needs a value"
        fixed="$2"
        shift 2
        ;;
      *) die 1 "unexpected argument: $arg" ;;
    esac
  done
  [[ -n "$fixed" ]] || { usage; exit 1; }
  printf '%s' "$fixed" | grep -Eq '^[rf]-[0-9a-f]{16}(,[rf]-[0-9a-f]{16})*$' || die 1 "--fixed must be a comma-separated list of finding ids"

  github_actor_resolve || exit 3
  fetch_threads "$TMP_ROOT/threads.json"

  jq -n --arg ids "$fixed" '$ids | split(",")' >"$TMP_ROOT/fixed.json"
  jq --slurpfile fixed "$TMP_ROOT/fixed.json" --arg actor "$ACTOR" "$JQ_AGENT"'
    [.[]
      | select(.isResolved | not)
      | select(.comments.nodes[0] | agent_comment($actor))
      | (.comments.nodes[0].body // "") as $body
      | ($body | capture("<!-- cr-inline:finding=(?<id>[rf]-[0-9a-f]{16}) -->")? // null) as $m
      | select($m != null and ($fixed[0] | index($m.id)) != null)
      | {finding_id: $m.id, thread_id: .id,
         reply: ((.comments.pageInfo.hasNextPage // false) or any(.comments.nodes[]; agent_comment($actor) | not))}]' \
    "$TMP_ROOT/threads.json" >"$TMP_ROOT/matched.json"
  jq '[.[] | select(.reply | not) | {finding_id, thread_id}]' "$TMP_ROOT/matched.json" >"$TMP_ROOT/targets.json"
  jq '[.[] | select(.reply) | {finding_id, thread_id}]' "$TMP_ROOT/matched.json" >"$TMP_ROOT/replies.json"

  local count i thread_id finding_id rc failed=0
  count="$(jq 'length' "$TMP_ROOT/targets.json")"
  : >"$TMP_ROOT/resolved.ids"
  i=0
  while [[ "$i" -lt "$count" ]]; do
    thread_id="$(jq -r ".[$i].thread_id" "$TMP_ROOT/targets.json")"
    finding_id="$(jq -r ".[$i].finding_id" "$TMP_ROOT/targets.json")"
    rc=0
    gh api graphql -f query="$RESOLVE_MUTATION" -f threadId="$thread_id" >"$TMP_ROOT/resolve.out" 2>"$TMP_ROOT/resolve.err" || rc=$?
    if [[ "$rc" -eq 0 ]] && jq -e '.data.resolveReviewThread.thread.isResolved == true' "$TMP_ROOT/resolve.out" >/dev/null 2>&1; then
      bash "$AUDIT_TRAIL" record --target "resolve review thread ${finding_id} on ${NWO}#${NUMBER}" --status 0 || true
      printf '%s\n' "$i" >>"$TMP_ROOT/resolved.ids"
    else
      [[ "$rc" -ne 0 ]] || rc=1
      bash "$AUDIT_TRAIL" record --target "resolve review thread ${finding_id} on ${NWO}#${NUMBER}" --status "$rc" || true
      echo "$PROG: resolving the thread of ${finding_id} failed on ${NWO}#${NUMBER}: $(head -c 300 "$TMP_ROOT/resolve.err")" >&2
      failed=$((failed + 1))
    fi
    i=$((i + 1))
  done

  jq -n --slurpfile targets "$TMP_ROOT/targets.json" --slurpfile replies "$TMP_ROOT/replies.json" --slurpfile fixed "$TMP_ROOT/fixed.json" \
    --rawfile closed "$TMP_ROOT/resolved.ids" '
    ($closed | split("\n") | map(select(. != "") | tonumber)) as $idx
    | [$idx[] as $i | $targets[0][$i]] as $resolved
    | {resolved: $resolved,
       has_reply: $replies[0],
       not_open: [$fixed[0][] | select(. as $id | ([$targets[0][], $replies[0][] | .finding_id] | index($id)) == null)]}'
  if [[ "$failed" -gt 0 ]]; then
    exit 3
  fi
}

# --- self-test ------------------------------------------------------------------------------------------

self_test() {
  local script tmp bin failures=0 checks=0
  script="$HERE/$(basename "${BASH_SOURCE[0]}")"
  tmp="$(mktemp -d)"
  TMP_ROOT="$tmp"
  bin="$tmp/bin"
  mkdir -p "$bin"

  local sha="aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa" other="bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"
  printf '%s' "$sha" >"$tmp/head"

  cat >"$bin/gh" <<'STUB'
#!/usr/bin/env bash
# Stand-in for gh: every answer comes from a fixture file, every write is recorded.
# The jq programs, the GraphQL documents, and the check expressions are single-quoted on purpose.
# shellcheck disable=SC2016
set -euo pipefail
case "$*" in
  "api user --jq .login") printf '%s\n' "$GH_ACTOR" ;;
  "repo view "*)
    if [[ "${GH_VISIBILITY_FAILS:-0}" == "1" ]]; then echo 'gh: Not Found' >&2; exit 1; fi
    printf '%s\n' "$GH_PRIVATE"
    ;;
  "api graphql "*resolveReviewThread*)
    printf '%s\n' "$*" >>"$GH_RESOLVES"
    if [[ "${GH_RESOLVE_FAILS:-0}" == "1" ]]; then echo 'gh: boom' >&2; exit 1; fi
    echo '{"data":{"resolveReviewThread":{"thread":{"isResolved":true}}}}'
    ;;
  "api graphql "*"node(id"*) cat "$GH_NODE_PAGE" ;;
  "api graphql "*)
    printf 'x\n' >>"${GH_GRAPHQL_CALLS:-/dev/null}"
    if [[ "$*" == *"cursor="* ]]; then cat "$GH_THREADS_2"; else cat "$GH_THREADS_1"; fi
    ;;
  "api repos/o/r/pulls/7/files"*) cat "$GH_FILES" ;;
  "api repos/o/r/compare/"*)
    if [[ "${GH_COMPARE_FAILS:-0}" == "1" ]]; then echo 'gh: Not Found' >&2; exit 1; fi
    cat "$GH_COMPARE"
    ;;
  "api repos/o/r/git/trees/"*)
    if [[ "${GH_TREE_FAILS:-0}" == "1" ]]; then echo 'gh: Not Found' >&2; exit 1; fi
    cat "$GH_TREE"
    ;;
  "api repos/o/r/pulls/7/reviews"*)
    cat >"$GH_REVIEW"
    printf 'x\n' >>"$GH_REVIEW_CALLS"
    if [[ "${GH_POST_FAILS:-0}" == "1" ]]; then echo '{"message":"Unprocessable Entity"}'; exit 1; fi
    echo '{"id":99,"html_url":"https://github.com/o/r/pull/7#pullrequestreview-99"}'
    ;;
  "api repos/o/r/pulls/7") printf '{"head":{"sha":"%s"}}\n' "$(cat "$GH_HEAD")" ;;
  *) echo "gh stub: unexpected call: $*" >&2; exit 1 ;;
esac
STUB
  chmod +x "$bin/gh"

  # One file: new lines 10-12 are added (RIGHT 10-12), old line 11 is context in the second hunk, old line 30 is removed.
  cat >"$tmp/files.json" <<'JSON'
[{"filename":"src/A.php","patch":"@@ -9,2 +9,5 @@ class A\n ctx9\n+add10\n+add11\n+add12\n ctx13\n@@ -29,3 +32,2 @@\n ctx32\n-gone30\n ctx33\n\\ No newline at end of file"}]
JSON
  printf '[]' >"$tmp/pages.empty"
  printf '{"tree":[{"path":"src","type":"tree"},{"path":"src/A.php","type":"blob"}],"truncated":false}' >"$tmp/tree.json"
  printf '{"tree":[{"path":"src/A.php","type":"blob"},{"path":"skills/_shared/run-gate.sh","type":"blob"}],"truncated":false}' >"$tmp/tree.package"
  printf '{"data":{"repository":{"pullRequest":{"reviewThreads":{"pageInfo":{"hasNextPage":false,"endCursor":null},"nodes":[]}}}}}' >"$tmp/threads.empty"
  cp "$tmp/threads.empty" "$tmp/threads.1"
  cp "$tmp/threads.empty" "$tmp/threads.2"

  local id1="r-1111111111111111" id2="r-2222222222222222" id3="r-3333333333333333" id4="r-4444444444444444"
  local id5="r-5555555555555555" id6="r-6666666666666666" id7="r-7777777777777777"
  local out rc

  # The hidden record of the round: a header block check-cr-header.sh accepts, and values that hold `-->`, `--!>`,
  # `&lt;`, and `<!--`, which the wrap must neutralise and the unwrap must give back byte for byte.
  cat >"$tmp/meta.md" <<'MD'
# Code Review

**Status:** needs-fix
**Counts:** Critical 1 · Moderate 1
**Merge verdict:** no — assignment met: yes · open Critical: 1 · one open Critical finding
**Security assessment:** untrusted data: none · public exposure: none · regression test: not required · automatic rule: not applicable
**Merge risk:** medium — The change adds a sign-in path over workspace membership. · impact: STANDARD (production-code-changed) · up to aaaaaaa
**Reviewed revision:** aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
**Last updated:** 2026-11-15T10:00:00Z
**Not verified:** a value with --> and --!> and &lt; and <!-- inside

## Findings

### 🔴 Critical 1. Unescaped output

- **Location:** `src/A.php:11`
- **Faulty Example:** The value of the request reaches the page without escaping.
MD
  printf '**Status:** clean\n' >"$tmp/meta-bad.md"
  grep -v '^\*\*Merge risk:' "$tmp/meta.md" >"$tmp/meta-norisk.md"
  awk '/^\*\*Last updated:\*\*/ { print "**Review independence:** not independent — helper agents are forbidden for this project" } { print }' "$tmp/meta.md" >"$tmp/meta-self-review.md"
  awk '/^\*\*Last updated:\*\*/ { print "**Review independence:** independent" } { print }' "$tmp/meta.md" >"$tmp/meta-self-review-forged.md"
  { cat "$tmp/meta.md"; printf '\n- **Suggested fix:**\n  ```suggestion\n  echo e($v);\n  ```\n'; } >"$tmp/meta-suggestion.md"
  local walk_head=$'\n## Interactive testing\n\n| Scenario | Viewport | Verdict | Observation |\n|---|---|---|---|\n'
  { cat "$tmp/meta.md"; printf '%s' "$walk_head"; printf '| Open the page | desktop | Blocked | No browser was available. |\n| Open the page | mobile | Blocked | No browser was available. |\n'; } >"$tmp/meta-walk-blocked.md"
  { cat "$tmp/meta.md"; printf '%s' "$walk_head"; printf '| Open the page | desktop | Met | The page opens. |\n| Save the form | desktop | Blocked | No test account. |\n'; } >"$tmp/meta-walk-ran.md"
  { cat "$tmp/meta.md"; printf '%s' "$walk_head"; printf '| Open the page | desktop | Failed | The page shows an error. |\n| Save the form | desktop | Blocked | No test account. |\n'; } >"$tmp/meta-walk-failed.md"
  { cat "$tmp/meta.md"; printf '%s' "$walk_head"; printf '| Open the page | desktop | Passed | The page opens. |\n| Save the form | desktop | Blocked | No test account. |\n'; } >"$tmp/meta-walk-passed.md"

  # The data of the Walkthrough block: one area over the one file of the pull request, and a diagram.
  local diagram='sequenceDiagram\n  participant Owner\n  participant Colleague\n  Owner->>Colleague: Send invitation'
  printf '{"tldr":"Workspace owners can now invite a colleague by e-mail. The colleague joins the workspace through a one-time link.","title":"Workspace invitations","description":"The change lets a workspace owner invite a colleague by e-mail.","areas":[{"layer":"Controllers","files":["src/A.php"],"summary":"Send and accept an invitation."}],"effort":{"level":3,"minutes":25},"change_type":"Feature","diagram":"%s","test_routes":[{"method":"GET","path":"/invitations/{token}","try":"Open the link and set a password."},{"method":"POST","path":"/workspace/invitations","try":"Send an invitation from the settings."}]}' "$diagram" >"$tmp/walk.json"
  printf '{"tldr":"A small fix.","description":"The change lets a workspace owner invite a colleague by e-mail.","areas":[{"layer":"Controllers","files":["src/A.php"],"summary":"Send and accept an invitation."}],"effort":{"level":1,"minutes":5},"change_type":"Bug fix"}' >"$tmp/walk-nodiagram.json"

  # run_post <findings json> [env assignments...]: runs `post` against the stub, sets $out, $rc.
  run_post() {
    local findings="$1"
    shift
    : >"$tmp/review.calls"
    : >"$tmp/graphql.calls"
    : >"$tmp/ledger.audit"
    rm -f "$tmp/review.json"
    set +e
    out="$(env "$@" PATH="$bin:$PATH" GH_ACTOR=bot GH_PRIVATE="${GH_PRIVATE_VALUE:-true}" GH_FILES="${GH_FILES_FILE:-$tmp/files.json}" GH_COMPARE="${GH_COMPARE_FILE:-$tmp/compare.ahead}" GH_TREE="${GH_TREE_FILE:-$tmp/tree.json}" \
      GH_THREADS_1="$tmp/threads.1" GH_THREADS_2="$tmp/threads.2" GH_HEAD="$tmp/head" GH_REVIEW="$tmp/review.json" \
      GH_REVIEW_CALLS="$tmp/review.calls" GH_GRAPHQL_CALLS="$tmp/graphql.calls" GH_RESOLVES="$tmp/resolves" GH_NODE_PAGE="$tmp/node.page" AUDIT_TRAIL_FILE="$tmp/ledger.audit" AUDIT_TRAIL_ROLE=leonardo \
      bash "$script" post https://github.com/o/r/pull/7 --commit-id "${COMMIT_ID:-$sha}" --meta-file "${META_FILE:-$tmp/meta.md}" --walkthrough-file "${WALK_FILE:-$tmp/walk.json}" - <<<"$findings" 2>"$tmp/stderr")"
    rc=$?
    set -e
  }

  check() {
    local label="$1" cond="$2"
    checks=$((checks + 1))
    if [[ "$cond" != "yes" ]]; then
      echo "self-test FAIL: $label" >&2
      failures=$((failures + 1))
    fi
  }
  yes_if() { if "$@" >/dev/null 2>&1; then echo yes; else echo no; fi; }
  local bt='```'
  review_has() { jq -e --arg bt "$bt" "$1" "$tmp/review.json" >/dev/null 2>&1; }
  out_has() { printf '%s' "$out" | jq -e "$1" >/dev/null 2>&1; }
  record_of() { jq -j '.body' "$tmp/review.json" | cr_review_meta_unwrap review; }
  # The visible part of the review body: everything above the hidden record.
  visible_of() { jq -j '.body | split("\n\n<!-- cr-review-meta\n")[0]' "$tmp/review.json"; }

  local f_in f_out f_mod
  f_in="{\"finding_id\":\"$id1\",\"severity\":\"Critical\",\"title\":\"Unescaped output\",\"path\":\"src/A.php\",\"line\":11,\"problem\":\"The value reaches the page unescaped.\",\"impact\":\"An attacker runs a script in the page.\",\"fix\":\"Escape the value before it is printed.\",\"rule\":\"@rules/security/backend.md#xss\",\"provenance\":\"regression — introduced in this revision\",\"evidence\":\"logic-trace — line 11\",\"expected_behavior\":\"The value is escaped.\",\"test_hint\":\"Feature test.\",\"raised_by\":\"splinter\",\"answered\":\"https://github.com/o/r/pull/7#issuecomment-1\"}"
  f_out="{\"finding_id\":\"$id2\",\"severity\":\"Moderate\",\"title\":\"Duplicate query\",\"path\":\"src/A.php\",\"line\":100,\"problem\":\"Outside the diff.\",\"impact\":\"Two queries per page.\",\"fix\":\"Reuse the existing query.\"}"
  f_mod="{\"finding_id\":\"$id6\",\"severity\":\"Moderate\",\"title\":\"Query in a loop\",\"path\":\"src/A.php\",\"line\":10,\"problem\":\"One query per row.\",\"impact\":\"The page slows down with the row count.\",\"fix\":\"Load the rows with one query.\",\"author\":\"@octocat\",\"level\":\"High\"}"
  local entry_in='**🔴 Critical 1 — Unescaped output** · <code>src/A.php&#58;11</code>
The value reaches the page unescaped.
**Fix:** Escape the value before it is printed.'

  # 1. a finding on a changed line is an Actionable comments entry of one review that carries no inline comment, and
  # the body holds the hidden record
  run_post "[$f_in]"
  check 'post exits 0' "$([[ $rc -eq 0 ]] && echo yes || echo no)"
  check 'one review request' "$([[ "$(wc -l <"$tmp/review.calls" | tr -d ' ')" == "1" ]] && echo yes || echo no)"
  check 'event is COMMENT' "$(yes_if review_has '.event == "COMMENT"')"
  check 'commit_id is the reviewed head' "$(yes_if review_has ".commit_id == \"$sha\"")"
  check 'the review request carries no inline comment: it has no comments key' "$(yes_if review_has 'has("comments") | not')"
  check 'post reads no review thread' "$([[ ! -s "$tmp/graphql.calls" ]] && echo yes || echo no)"
  check 'a finding on a changed line is an entry of the Actionable comments block, byte for byte' \
    "$(yes_if jq -e --arg want "$entry_in" '.body | contains("<strong>Actionable comments (1)</strong>") and contains($want)' "$tmp/review.json")"
  check 'the entry renders no Rule, Provenance, Raised by, Level, Evidence, Expected behavior, Test hint, Impact, Suggested fix, or suggestion block' \
    "$(visible_of | grep -qE '\*\*(Rule|Provenance|Raised by|Level|Evidence|Expected behavior|Test hint|Impact|Suggested fix|Answered|Author):|suggestion' && echo no || echo yes)"
  check 'the review body holds no cr-inline marker' "$(yes_if review_has '[.body | match("<!--\\s*cr-inline:"; "g")] | length == 0')"
  check 'the review body holds one hidden record, one fingerprint marker, and the cr-comment actor marker' \
    "$(yes_if review_has '([.body | match("(?m)^<!-- cr-review-meta$"; "g")] | length == 1) and (.body | test("(?m)^<!-- cr-comment:lines=[0-9a-f,]* -->\n<!-- cr-comment:actor=bot -->$"))')"
  check 'the hidden record comes back byte for byte, --> and --!> and &lt; and <!-- included' \
    "$([[ "$(record_of)" == "$(cat "$tmp/meta.md")"$'\n\n<!-- cr-comment:lines='*$'\n<!-- cr-comment:actor=bot -->' ]] && echo yes || echo no)"
  check 'the hidden block holds no raw --> of the record' "$(yes_if review_has '[.body | match("-->"; "g")] | length == 3')"
  check 'output lists the finding' "$(yes_if out_has ".listed[0].finding_id == \"$id1\" and (.listed | length) == 1 and .review_url != null")"
  check 'the review is one external-write audit line' "$([[ "$(grep -c '|external-write|https://github.com/o/r/pull/7#pullrequestreview-99|' "$tmp/ledger.audit")" == "1" ]] && echo yes || echo no)"

  # 2. a stale commit id is refused and nothing is posted
  COMMIT_ID="$other" run_post "[$f_in]"
  check 'a stale commit id exits 4' "$([[ $rc -eq 4 ]] && echo yes || echo no)"
  check 'a stale commit id posts nothing' "$([[ ! -s "$tmp/review.calls" ]] && echo yes || echo no)"

  # 3. the visible body is the approved layout, byte for byte: the findings count, Merge Risk, Walkthrough, and the
  # Actionable comments of every published finding, Critical first, on a changed line or not.
  local want_visible
  want_visible="Workspace owners can now invite a colleague by e-mail. The colleague joins the workspace through a one-time link.

<details>
<summary><strong>Merge Risk:</strong> <strong>🟡 Medium</strong> · up to <code>aaaaaaa</code></summary>
<dl>
<dd>
<br>

The change adds a sign-in path over workspace membership.

</dd>
</dl>
</details>

<details>
<summary><strong>Walkthrough</strong></summary>
<dl>
<dd>
<br>

The change lets a workspace owner invite a colleague by e-mail.

**Changes: Workspace invitations**

|Layer / File(s)|Summary|
|:---|:---|
|**Controllers** <br> <code>src/A.php</code>|Send and accept an invitation.|

**Estimated code review effort:** 3 (Moderate) | ~25 minutes
**Change:** Feature

### Sequence Diagram(s)

${bt}mermaid
sequenceDiagram
  participant Owner
  participant Colleague
  Owner->>Colleague: Send invitation
${bt}

</dd>
</dl>
</details>

<details>
<summary><strong>Where to test</strong></summary>
<dl>
<dd>
<br>

| Method and path | What to try |
| :--- | :--- |
| <code>GET /invitations/{token}</code> | Open the link and set a password. |
| <code>POST /workspace/invitations</code> | Send an invitation from the settings. |

</dd>
</dl>
</details>

<details>
<summary><strong>Actionable comments (3)</strong></summary>
<dl>
<dd>
<br>

**🔴 Critical 1 — Unescaped output** · <code>src/A.php&#58;11</code>
The value reaches the page unescaped.
**Fix:** Escape the value before it is printed.

**🟠 Moderate 1 — Query in a loop** · <code>src/A.php&#58;10</code>
One query per row.
**Fix:** Load the rows with one query.
@octocat

**🟠 Moderate 2 — Duplicate query** · <code>src/A.php&#58;100</code>
Outside the diff.
**Fix:** Reuse the existing query.

</dd>
</dl>
</details>"
  run_post "[$f_mod,$f_in,$f_out]"
  check 'the visible body is the approved layout, byte for byte' "$([[ $rc -eq 0 ]] && [[ "$(visible_of)" == "$want_visible" ]] && echo yes || echo no)"
  check 'the summary paragraph opens the review body, with no label and no findings count line' "$(yes_if review_has '(.body | startswith("Workspace owners can now invite")) and (.body | contains("Actionable comments posted") | not)')"
  check 'the visible order is the summary, Merge Risk, Walkthrough, Where to test, Actionable comments' \
    "$(yes_if review_has '.body | (index("Workspace owners can now") < index("Merge Risk:</strong>")) and (index("Merge Risk:</strong>") < index("<strong>Walkthrough</strong>")) and (index("<strong>Walkthrough</strong>") < index("<strong>Where to test</strong>")) and (index("<strong>Where to test</strong>") < index("<strong>Actionable comments"))')"
  check 'the Where to test block lists the method and the path of each route with one line to try' \
    "$(yes_if review_has '.body | contains("| Method and path | What to try |\n| :--- | :--- |\n| <code>GET /invitations/{token}</code> | Open the link and set a password. |\n| <code>POST /workspace/invitations</code> | Send an invitation from the settings. |\n")')"
  check 'the visible body shows no CI line, pre-merge checks, recent review info, button, or share link' \
    "$(yes_if review_has '.body | split("\n\n<!-- cr-review-meta\n")[0] | test("(?i)CI\\*\\*|pre-merge|recent review|explain change|share|re-?run|\\*\\*TL;DR") | not')"
  check 'the only fenced block of the visible body is the sequence diagram' \
    "$(yes_if review_has '.body | split("\n\n<!-- cr-review-meta\n")[0] | ([match("```|~~~"; "g")] | length == 2) and contains("```mermaid\nsequenceDiagram")')"
  check 'the Actionable comments hold no fenced block and no suggestion block' \
    "$(yes_if review_has '.body | split("\n\n<!-- cr-review-meta\n")[0] | split("<strong>Actionable comments")[1] | (test("```|~~~|suggestion") | not)')"
  check 'every actionable comment ends with a Fix sentence' "$(yes_if review_has '.body | [match("\\*\\*Fix:\\*\\* [^\n]+"; "g")] | length == 3')"
  check 'output lists every finding, on a changed line or not' "$(yes_if out_has "[.listed[].finding_id] == [\"$id6\", \"$id1\", \"$id2\"]")"
  check 'a review with three findings carries no inline comment' "$(yes_if review_has 'has("comments") | not')"
  check 'the review stderr names the listed count and no inline count' "$(grep -q 'listed=3$' "$tmp/stderr" && ! grep -q 'comments=' "$tmp/stderr" && echo yes || echo no)"

  # 4. a round with no finding, or with no anchored finding, still posts exactly one review
  run_post '[]'
  check 'a round with no finding posts one review with no inline comment' "$([[ $rc -eq 0 && "$(wc -l <"$tmp/review.calls" | tr -d ' ')" == "1" ]] && yes_if review_has '(has("comments") | not) and (.body | contains("\n\n<!-- cr-review-meta\n"))' || echo no)"
  check 'a round with no finding shows the summary, Merge Risk, and Walkthrough, and no Actionable comments block' \
    "$(yes_if review_has '.body | startswith("Workspace owners") and contains("<summary><strong>Merge Risk:</strong>") and contains("<strong>Walkthrough</strong>") and (contains("Actionable comments") | not)')"
  run_post "[$f_out]"
  check 'a finding outside the diff posts one review with no inline comment' "$([[ $rc -eq 0 && "$(wc -l <"$tmp/review.calls" | tr -d ' ')" == "1" ]] && review_has 'has("comments") | not' && yes_if out_has '.review_url != null and (.listed | length) == 1' || echo no)"
  check 'an outside-diff finding is a line of the Actionable comments block' "$(yes_if review_has '.body | contains("**🟠 Moderate 1 — Duplicate query** · <code>src/A.php&#58;100</code>\nOutside the diff.\n**Fix:** Reuse the existing query.")')"
  local f_gap_noline
  f_gap_noline="{\"finding_id\":\"$id4\",\"severity\":\"Critical\",\"title\":\"Criterion 2 unmet\",\"problem\":\"The export ignores the selection.\",\"impact\":\"Every order is exported.\",\"fix\":\"Export only the selected orders.\"}"
  run_post "[$f_gap_noline]"
  check 'a finding with no line is an Actionable comments entry without a place' \
    "$(yes_if review_has '.body | contains("**🔴 Critical 1 — Criterion 2 unmet**\nThe export ignores the selection.\n**Fix:** Export only the selected orders.")')"
  WALK_FILE="$tmp/walk-nodiagram.json" run_post '[]'
  WALK_FILE="$tmp/walk-nodiagram.json" run_post '[]'
  check 'a change that touches no page or route has no Where to test block' "$(yes_if review_has '.body | contains("Where to test") | not')"
  check 'a walkthrough with no diagram renders no sequence diagram and no fenced block in the body' \
    "$(yes_if review_has '(.body | split("\n\n<!-- cr-review-meta\n")[0] | test("```|~~~|Sequence Diagram") | not) and (.body | contains("**Estimated code review effort:** 1 (Trivial) | ~5 minutes\n**Change:** Bug fix"))')"

  # 4a. the TL;DR holds 500 code points at most, counted once the text is normalised, and is never cut
  local tl_under tl_at tl_over tl_wide tl_marker
  tl_under="$(printf 'a%.0s' $(seq 1 499))"
  tl_at="$(printf 'a%.0s' $(seq 1 500))"
  tl_over="$(printf 'a%.0s' $(seq 1 501))"
  tl_wide="$(printf 'é%.0s' $(seq 1 500))"
  tl_marker="$(printf 'a%.0s' $(seq 1 495))"
  walk_with_tldr() {
    jq -c --arg t "$1" '.tldr = $t' "$tmp/walk.json" >"$tmp/walk-tldr.json"
  }
  walk_with_tldr "$tl_under"
  WALK_FILE="$tmp/walk-tldr.json" run_post '[]'
  check 'a TL;DR of 499 characters is published whole' "$([[ $rc -eq 0 ]] && yes_if jq -e --arg t "$tl_under" '.body | startswith($t + "\n\n<details>")' "$tmp/review.json" || echo no)"
  walk_with_tldr "$tl_at"
  WALK_FILE="$tmp/walk-tldr.json" run_post '[]'
  check 'a TL;DR of exactly 500 characters is published whole' "$([[ $rc -eq 0 ]] && yes_if jq -e --arg t "$tl_at" '.body | startswith($t + "\n\n<details>")' "$tmp/review.json" || echo no)"
  walk_with_tldr "$tl_over"
  WALK_FILE="$tmp/walk-tldr.json" run_post '[]'
  check 'a TL;DR of 501 characters is refused, not cut, and nothing is posted' "$([[ $rc -eq 1 && ! -s "$tmp/review.calls" ]] && grep -q 'tldr is 501 characters once normalised' "$tmp/stderr" && echo yes || echo no)"
  walk_with_tldr "$tl_wide"
  WALK_FILE="$tmp/walk-tldr.json" run_post '[]'
  check 'the TL;DR limit counts characters, not bytes: 500 two-byte characters pass' "$([[ $rc -eq 0 ]] && echo yes || echo no)"
  walk_with_tldr "${tl_marker}-->"
  WALK_FILE="$tmp/walk-tldr.json" run_post '[]'
  check 'the TL;DR limit counts the neutralised text: a marker closer that grows to 501 characters is refused' "$([[ $rc -eq 1 && ! -s "$tmp/review.calls" ]] && grep -q 'tldr is 501 characters' "$tmp/stderr" && echo yes || echo no)"
  walk_with_tldr "  $tl_under  "
  WALK_FILE="$tmp/walk-tldr.json" run_post '[]'
  check 'the TL;DR limit counts the trimmed text: padding around 499 characters passes' "$([[ $rc -eq 0 ]] && echo yes || echo no)"
  walk_with_tldr ""
  WALK_FILE="$tmp/walk-tldr.json" run_post '[]'
  check 'an empty TL;DR is refused' "$([[ $rc -eq 1 && ! -s "$tmp/review.calls" ]] && echo yes || echo no)"
  walk_with_tldr "The change adds a thing."$'\n\n'"\`\`\`php"$'\n'"echo 1;"$'\n'"\`\`\`"
  WALK_FILE="$tmp/walk-tldr.json" run_post '[]'
  check 'a TL;DR with a fenced block is refused' "$([[ $rc -eq 1 && ! -s "$tmp/review.calls" ]] && grep -q 'the walkthrough carries no code' "$tmp/stderr" && echo yes || echo no)"

  walk_with_tldr "The change adds a thing."$'\n'"# A heading"
  WALK_FILE="$tmp/walk-tldr.json" run_post '[]'
  check 'a TL;DR with a heading, a quote, a table, or a tag line is refused: it holds plain sentences' "$([[ $rc -eq 1 && ! -s "$tmp/review.calls" ]] && grep -q 'tldr is plain sentences' "$tmp/stderr" && echo yes || echo no)"

  # 5. a multi-line finding, a removed line on the LEFT side, and a range that crosses a hunk gap are each one entry at
  # their last line
  local f_multi f_left f_gap
  f_multi="{\"finding_id\":\"$id3\",\"severity\":\"Moderate\",\"title\":\"Range\",\"path\":\"src/A.php\",\"line\":12,\"start_line\":10,\"problem\":\"p\",\"impact\":\"i\",\"fix\":\"f\"}"
  f_left="{\"finding_id\":\"$id4\",\"severity\":\"Critical\",\"title\":\"Removed guard\",\"path\":\"src/A.php\",\"line\":30,\"side\":\"LEFT\",\"problem\":\"p\",\"impact\":\"i\",\"fix\":\"f\"}"
  f_gap="{\"finding_id\":\"$id2\",\"severity\":\"Moderate\",\"title\":\"Gap\",\"path\":\"src/A.php\",\"line\":33,\"start_line\":12,\"problem\":\"p\",\"impact\":\"i\",\"fix\":\"f\"}"
  run_post "[$f_multi,$f_left,$f_gap]"
  check 'a multi-line finding, a removed line, and a range across a hunk gap are entries at their last line, with no inline comment' \
    "$([[ $rc -eq 0 ]] && review_has '(has("comments") | not) and (.body | contains("**🔴 Critical 1 — Removed guard** · <code>src/A.php&#58;30</code>") and contains("**🟠 Moderate 1 — Range** · <code>src/A.php&#58;12</code>") and contains("**🟠 Moderate 2 — Gap** · <code>src/A.php&#58;33</code>"))' && echo yes || echo no)"

  # 8. visibility: public withholds a do_not_file_publicly finding everywhere, private posts it, a failed check is public
  local f_sec
  f_sec="{\"finding_id\":\"$id3\",\"severity\":\"Critical\",\"title\":\"Hard-coded token\",\"path\":\"src/A.php\",\"line\":10,\"problem\":\"p\",\"impact\":\"i\",\"fix\":\"f\",\"do_not_file_publicly\":true}"
  GH_PRIVATE_VALUE=true run_post "[$f_sec]"
  check 'a private repository posts a security finding with no inline comment' "$([[ $rc -eq 0 ]] && yes_if review_has 'has("comments") | not' || echo no)"
  check 'a private repository lists the security finding in the Actionable comments' "$(yes_if review_has '.body | contains("<strong>Actionable comments (1)</strong>") and contains("Hard-coded token")')"
  GH_PRIVATE_VALUE=false run_post "[$f_sec,$f_in]"
  check 'a public repository withholds the marked finding' "$(yes_if out_has ".withheld[0].finding_id == \"$id3\" and (.listed | length) == 1")"
  check 'a withheld finding is in no visible line, not in the count, and not in the hidden record' \
    "$(yes_if review_has ".body | ((contains(\"$id3\") or contains(\"Hard-coded token\")) | not) and contains(\"<strong>Actionable comments (1)</strong>\")")"
  run_post "[$f_sec]" GH_VISIBILITY_FAILS=1
  check 'a failed visibility check counts as public' "$([[ $rc -eq 0 ]] && review_has "(has(\"comments\") | not) and (.body | contains(\"$id3\") | not) and (.body | contains(\"Actionable comments\") | not)" && out_has ".withheld[0].finding_id == \"$id3\"" && echo yes || echo no)"
  { cat "$tmp/meta.md"; printf '\n### 🔴 Critical 2. Hard-coded token\n'; } >"$tmp/meta-leak.md"
  META_FILE="$tmp/meta-leak.md" GH_PRIVATE_VALUE=false run_post "[$f_sec,$f_in]"
  check 'a record that names a withheld finding by its title is refused and nothing is posted' "$([[ $rc -eq 1 && ! -s "$tmp/review.calls" ]] && grep -q "names the withheld finding(s) $id3" "$tmp/stderr" && echo yes || echo no)"
  { cat "$tmp/meta.md"; printf '\n<!-- finding %s -->\n' "$id3"; } >"$tmp/meta-leak.md"
  META_FILE="$tmp/meta-leak.md" GH_PRIVATE_VALUE=false run_post "[$f_sec,$f_in]"
  check 'a record that names a withheld finding by its id is refused and nothing is posted' "$([[ $rc -eq 1 && ! -s "$tmp/review.calls" ]] && echo yes || echo no)"
  META_FILE="$tmp/meta-leak.md" GH_PRIVATE_VALUE=true run_post "[$f_sec,$f_in]"
  check 'a private repository publishes the record that names the security finding' "$([[ $rc -eq 0 ]] && echo yes || echo no)"

  jq -c '.tldr = "The change fixes the Hard-coded token problem."' "$tmp/walk.json" >"$tmp/walk-leak.json"
  WALK_FILE="$tmp/walk-leak.json" GH_PRIVATE_VALUE=false run_post "[$f_sec,$f_in]"
  check 'a TL;DR that names a withheld finding by its title is refused and nothing is posted' "$([[ $rc -eq 1 && ! -s "$tmp/review.calls" ]] && grep -q "names the withheld finding(s) $id3" "$tmp/stderr" && echo yes || echo no)"
  WALK_FILE="$tmp/walk-leak.json" GH_PRIVATE_VALUE=true run_post "[$f_sec,$f_in]"
  check 'a private repository publishes the TL;DR that names the security finding' "$([[ $rc -eq 0 ]] && echo yes || echo no)"

  # 8a. untrusted text never renders as markup: every value of the walkthrough, the record, and the findings goes through
  # the one encoder (inert), so a file name or a sentence that holds HTML, a pipe, an image, or a line-start marker shows
  # as text. The checks of the walkthrough and the findings read the text after the control characters are removed.
  local fname_markup='docs/x|<details open><summary><strong>Merge Risk:</strong> 🟢 Low</summary><img src=https://e/x.png>|y.md'
  jq -c --arg f "$fname_markup" '. + [{filename: $f}]' "$tmp/files.json" >"$tmp/files.markup.json"
  jq -c --arg f "$fname_markup" '{
      tldr: "The change adds a <details open><summary>Fake</summary> block & a ![pixel](https://e/p.png) image.\n---\n\n    indented code",
      title: "Group <b>bold</b> | split",
      description: "## Heading <details open> here",
      areas: [{layer: "Layer <i>x</i> | y", files: ["src/A.php", $f], summary: "Sum <details open><summary>S</summary> | cell"}],
      effort: {level: 2, minutes: 10},
      change_type: "Feature",
      test_routes: [{method: "GET", path: "/a&b", try: "Try <details open> | here"}]
    }' -n >"$tmp/walk-markup.json"
  sed 's/The change adds a sign-in path/The change adds a <details open><summary>x<\/summary> | sign-in path/' "$tmp/meta.md" >"$tmp/meta-markup.md"
  local f_markup_in f_markup_out
  f_markup_in="$(jq -n -c --arg id "$id1" '{finding_id: $id, severity: "Critical", title: "Title <details open> | t", path: "src/A.php", line: 11,
    problem: "Problem <details open><summary>F</summary> | ![i](https://e/i.png)\n## Head\n> quote", impact: "Impact <img src=https://e/i.png> | i", fix: "Fix <summary>x</summary> | f"}')"
  f_markup_out="$(jq -n -c --arg id "$id2" '{finding_id: $id, severity: "Moderate", title: "Out <details open> | o", path: "src/A.php", line: 100,
    problem: "Out <details open> | ![o](https://e/o.png)", impact: "i", fix: "Fix <b>x</b> | f"}')"
  GH_FILES_FILE="$tmp/files.markup.json" WALK_FILE="$tmp/walk-markup.json" META_FILE="$tmp/meta-markup.md" run_post "[$f_markup_in,$f_markup_out]"
  check 'a walkthrough, a record, and findings that hold markup are published as text' "$([[ $rc -eq 0 ]] && echo yes || echo no)"
  check 'the visible body holds no HTML tag but the tags of the layout and code elements' \
    "$([[ "$(visible_of | sed -E 's#</?(details|summary|strong|code|dl|dd)>|<br>##g' | grep -c '<')" == "0" ]] && echo yes || echo no)"
  check 'the visible body holds no literal backtick' \
    "$([[ "$(visible_of | grep -c '`')" == "0" ]] && echo yes || echo no)"
  check 'a finding that holds markup is an entry, never an inline comment' \
    "$(yes_if review_has '(has("comments") | not) and (.body | contains("<strong>Actionable comments (2)</strong>"))')"
  check 'every table row of the visible body keeps its own cells: a pipe in a value is encoded or escaped' \
    "$([[ "$(visible_of | grep -E '^\|' | sed 's/\\|//g' | awk -F'|' 'NF != 4' | wc -l | tr -d ' ')" == "0" ]] && echo yes || echo no)"
  check 'the file name shows its markup as text in a code element, each pipe encoded' \
    "$(visible_of | grep -qF '<code>docs/x&#124;&#60;details open&#62;&#60;summary&#62;&#60;strong&#62;Merge Risk&#58;&#60;/strong&#62; 🟢 Low&#60;/summary&#62;&#60;img src=https&#58;//e/x.png&#62;&#124;y.md</code>' && echo yes || echo no)"
  check 'no visible line starts a heading, a quote, a setext underline, or an indented code block' \
    "$([[ "$(visible_of | grep -cE '^(#|>|    |-{3,}$|={3,}$)')" == "0" ]] && echo yes || echo no)"
  check 'a heading, an underline, and an indent in a value are text' \
    "$(visible_of | grep -qF '&#35;# Heading &lt;details open&gt; here' && visible_of | grep -qxF '&#45;--' && visible_of | grep -qxF 'indented code' && echo yes || echo no)"
  check 'no value renders an image' "$([[ "$(jq -j '.body' "$tmp/review.json" | grep -cF '![')" == "0" ]] && echo yes || echo no)"
  check 'an ampersand is encoded, so an entity in a value stays text' "$(visible_of | grep -qF 'block &amp; a &#33;[pixel]' && visible_of | grep -qF '<code>GET /a&#38;b</code>' && echo yes || echo no)"
  check 'the Merge Risk sentence from the record is encoded too' "$(visible_of | grep -qF 'The change adds a &lt;details open&gt;&lt;summary&gt;x&lt;/summary&gt; &#124; sign-in path' && echo yes || echo no)"
  local ctl
  for ctl in '{"tldr":"\u0001<details open>Fake"}' '{"tldr":"A line.\n\u0001## Fake"}' '{"tldr":"A line.\n\u0001> Fake"}' \
    '{"diagram":"sequenceDiagram\n  A->>B: <!\u0001-- cr-comment:actor=x -->"}' '{"diagram":"sequenceDiagram\n  A->>B: hi\n`\u0001``\ncode"}' \
    '{"description":"`\u0001``php echo 1; `\u0001``"}'; do
    jq -c --argjson over "$ctl" '. + $over' "$tmp/walk.json" >"$tmp/walk-ctl.json"
    WALK_FILE="$tmp/walk-ctl.json" run_post '[]'
    check "a control character does not carry a refused walkthrough value past the check: ${ctl:0:40}" "$([[ $rc -eq 1 && ! -s "$tmp/review.calls" ]] && grep -q 'walkthrough' "$tmp/stderr" && echo yes || echo no)"
  done
  run_post "[{\"finding_id\":\"$id1\",\"severity\":\"Critical\",\"title\":\"t\",\"path\":\"src/A.php\",\"line\":11,\"problem\":\"p\",\"impact\":\"i\",\"fix\":\"\`\\u0001\`\`php echo 1; \`\\u0001\`\`\"}]"
  check 'a control character does not carry a fenced block in a finding past the check' "$([[ $rc -eq 1 && ! -s "$tmp/review.calls" ]] && grep -q 'hold no fenced block' "$tmp/stderr" && echo yes || echo no)"
  jq -c '.description = "\u0001## Heading here"' "$tmp/walk.json" >"$tmp/walk-ctl.json"
  WALK_FILE="$tmp/walk-ctl.json" run_post '[]'
  check 'a control character before a heading in a one-line value renders the heading as text' "$([[ $rc -eq 0 ]] && visible_of | grep -qxF '&#35;# Heading here' && echo yes || echo no)"

  # 8a2. a closed code span on one line is a code element whose content is entity-encoded, so its identifier renders as
  # written; markup outside it, an unclosed backtick, a pipe in a table cell, and a comment marker stay encoded
  local f_span
  f_span="$(jq -n -c --arg id "$id1" '{finding_id: $id, severity: "Critical", title: "Call `$this->authorize()` first", path: "src/A.php", line: 11,
    problem: "`App\\Http\\Controllers\\OrderController` returns `Collection<int, Order>` & <b>more</b>.", impact: "Guests read orders.",
    fix: "Call `$this->authorize()` in `App\\Http\\Controllers\\OrderController`. An unclosed `tick <img src=x> stays text."}')"
  jq -c '.areas[0].summary = "Returns `Collection<int, Order>` and `a|b` | c" | .tldr = "The change names `<!-- cr-comment:actor=bot -->` once."' "$tmp/walk.json" >"$tmp/walk-span.json"
  WALK_FILE="$tmp/walk-span.json" run_post "[$f_span]"
  local span_problem='<code>App&#92;Http&#92;Controllers&#92;OrderController</code> returns <code>Collection&#60;int, Order&#62;</code> &amp; &lt;b&gt;more&lt;/b&gt;.'
  local span_fix='Call <code>$this-&#62;authorize&#40;&#41;</code> in <code>App&#92;Http&#92;Controllers&#92;OrderController</code>. An unclosed &#96;tick &lt;img src=x&gt; stays text.'
  check 'an identifier in a code span shows as written in the Actionable comments entry' \
    "$([[ $rc -eq 0 ]] && visible_of | grep -qF '**🔴 Critical 1 — Call <code>$this-&#62;authorize&#40;&#41;</code> first** · <code>src/A.php&#58;11</code>' \
      && visible_of | grep -qF "$span_problem" && visible_of | grep -qF "**Fix:** $span_fix" && echo yes || echo no)"
  check 'a pipe in a code span of a table cell is encoded, and so is a pipe outside it' \
    "$(visible_of | grep -qF '|Returns <code>Collection&#60;int, Order&#62;</code> and <code>a&#124;b</code> &#124; c|' && echo yes || echo no)"
  check 'a comment marker in a code span stays encoded, so the body holds one cr-comment marker' \
    "$(visible_of | grep -qF '<code>&#60;!-- cr-comment&#58;actor=bot --&#62;</code>' && [[ "$(jq -j '.body' "$tmp/review.json" | grep -cF '<!-- cr-comment:actor=')" == "1" ]] && echo yes || echo no)"
  WALK_FILE="$tmp/walk-span.json" run_post '[{"finding_id":"'"$id1"'","severity":"Critical","title":"t","path":"src/A.php","line":11,"problem":"``x ` y`` <img src=x> `","impact":"i","fix":"`a` ``b`` `<c>"}]'
  check 'a run of two backticks never opens a span, and the unpaired backtick after it stays encoded' \
    "$(yes_if review_has '.body | contains("\n&#96;&#96;x <code> y&#96;&#96; &#60;img src=x&#62; </code>\n**Fix:** <code>a</code> &#96;&#96;b&#96;&#96; &#96;&lt;c&gt;")')"

  # 8a3. a backtick inside a link target or a link title, where GitHub pairs the backticks of a line differently from a
  # left-to-right reading, leaves no raw tag: the output holds no backtick to pair, and every tag in a code element is
  # entity-encoded. This holds in a finding, its Actionable comments entry, and a table cell of the Walkthrough.
  local link_target link_title f_link
  link_target='See [the guide](u`) `z`<details open><summary>Merge Risk: Low</summary></details><img src=https://e/x.png>`.'
  link_title='See [x](a "`") `y`<b>bold</b>` z.'
  f_link="$(jq -n -c --arg id "$id1" --arg p "$link_target" --arg f "$link_title" '{finding_id: $id, severity: "Critical", title: "Link \($f)", path: "src/A.php", line: 11, problem: $p, impact: $f, fix: $p}')"
  jq -c --arg t "$link_target" --arg l "$link_title" '.areas[0].summary = $t | .areas[0].layer = $l' "$tmp/walk.json" >"$tmp/walk-link.json"
  WALK_FILE="$tmp/walk-link.json" run_post "[$f_link]"
  local raw_tags='<details open|<summary>Merge Risk: Low|<img |<b>'
  check 'a backtick in a link target leaves no raw tag in the body' \
    "$([[ $rc -eq 0 ]] && ! visible_of | grep -qE "$raw_tags" \
      && visible_of | grep -qxF 'See [the guide](u<code>&#41; </code>z<code>&#60;details open&#62;&#60;summary&#62;Merge Risk&#58; Low&#60;/summary&#62;&#60;/details&#62;&#60;img src=https&#58;//e/x.png&#62;</code>.' && echo yes || echo no)"
  check 'a backtick in a link title leaves no raw tag in the body' \
    "$(visible_of | grep -qF '**🔴 Critical 1 — Link See [x](a "<code>"&#41; </code>y<code>&#60;b&#62;bold&#60;/b&#62;</code> z.**' && echo yes || echo no)"
  check 'a backtick in a link target of a table cell leaves no raw tag and keeps the cells' \
    "$(visible_of | grep -E '^\|' | grep -qF 'See [the guide](u<code>&#41; </code>z<code>&#60;details open&#62;' \
      && [[ "$(visible_of | grep -E '^\|' | awk -F'|' 'NF != 4' | wc -l | tr -d ' ')" == "0" ]] \
      && [[ "$(visible_of | grep -v '^```' | grep -c '`')" == "0" ]] && echo yes || echo no)"

  # 8b. every finding with an author ends its Actionable comments entry with the mention, on a changed line or not
  local f_test_gap f_dry_out
  f_test_gap="{\"finding_id\":\"$id2\",\"severity\":\"Moderate\",\"title\":\"Missing test\",\"path\":\"src/A.php\",\"line\":100,\"problem\":\"No test covers the export.\",\"impact\":\"A regression ships unseen.\",\"fix\":\"Add a feature test for the export.\",\"author\":\"@octocat\"}"
  f_dry_out="{\"finding_id\":\"$id5\",\"severity\":\"Moderate\",\"title\":\"Repeated total\",\"problem\":\"The total is computed again.\",\"kind\":\"dry\",\"existing\":\"app/Totals.php:42\",\"author\":\"@octocat\"}"
  run_post "[$f_mod,$f_test_gap,$f_dry_out]"
  check 'an outside-diff finding with an author ends its Actionable comments entry with the mention' \
    "$(yes_if review_has '.body | contains("**🟠 Moderate 2 — Missing test** · <code>src/A.php&#58;100</code>\nNo test covers the export.\n**Fix:** Add a feature test for the export.\n@octocat\n")')"
  check 'a DRY finding with no line and an author ends its entry with the mention' \
    "$(yes_if review_has '.body | contains("**🟠 Moderate 3 — Repeated total**\nThe total is computed again.\n**Fix:** Use the existing implementation in <code>app/Totals.php&#58;42</code> instead of repeating the logic.\n@octocat\n")')"
  check 'a finding on a changed line with an author ends its entry with the mention' \
    "$(yes_if review_has '.body | contains("**🟠 Moderate 1 — Query in a loop** · <code>src/A.php&#58;10</code>\nOne query per row.\n**Fix:** Load the rows with one query.\n@octocat\n")')"
  check 'the visible body mentions the author once for each finding that sets it' \
    "$([[ "$(visible_of | grep -cxF '@octocat')" == "3" ]] && echo yes || echo no)"

  # 8c. the withheld-finding check reads the decoded string values: a JSON escape hides no title, in any field of the
  # walkthrough or in another published finding
  local f_wh wfield
  f_wh="$(jq -n -c --arg id "$id3" '{finding_id: $id, severity: "Critical", title: "Guest reaches the \"export\" page at /admin\\export", path: "src/A.php", line: 10, problem: "p", impact: "i", fix: "f", do_not_file_publicly: true}')"
  local esc='Guest reaches the \"export\" page at \/admin\\export'
  for wfield in tldr description title summary try; do
    case "$wfield" in
      tldr) printf '{"tldr":"Note: %s.","description":"d","areas":[{"layer":"L","files":["src/A.php"],"summary":"s"}],"effort":{"level":2,"minutes":10},"change_type":"Feature"}' "$esc" ;;
      description) printf '{"tldr":"t","description":"%s","areas":[{"layer":"L","files":["src/A.php"],"summary":"s"}],"effort":{"level":2,"minutes":10},"change_type":"Feature"}' "$esc" ;;
      title) printf '{"tldr":"t","title":"%s","description":"d","areas":[{"layer":"L","files":["src/A.php"],"summary":"s"}],"effort":{"level":2,"minutes":10},"change_type":"Feature"}' "$esc" ;;
      summary) printf '{"tldr":"t","description":"d","areas":[{"layer":"L","files":["src/A.php"],"summary":"%s"}],"effort":{"level":2,"minutes":10},"change_type":"Feature"}' "$esc" ;;
      try) printf '{"tldr":"t","description":"d","areas":[{"layer":"L","files":["src/A.php"],"summary":"s"}],"effort":{"level":2,"minutes":10},"change_type":"Feature","test_routes":[{"method":"GET","path":"/x","try":"%s"}]}' "$esc" ;;
    esac >"$tmp/walk-escaped.json"
    WALK_FILE="$tmp/walk-escaped.json" GH_PRIVATE_VALUE=false run_post "[$f_wh,$f_in]"
    check "a walkthrough $wfield that names a withheld title through JSON escapes is refused and nothing is posted" \
      "$([[ $rc -eq 1 && ! -s "$tmp/review.calls" ]] && grep -q "names the withheld finding(s) $id3" "$tmp/stderr" && echo yes || echo no)"
  done
  printf '{"tldr":"Note: %s.","description":"d","areas":[{"layer":"L","files":["src/A.php"],"summary":"s"}],"effort":{"level":2,"minutes":10},"change_type":"Feature"}' "$esc" >"$tmp/walk-escaped.json"
  WALK_FILE="$tmp/walk-escaped.json" GH_PRIVATE_VALUE=true run_post "[$f_wh,$f_in]"
  check 'a private repository publishes the escaped walkthrough that names the security finding' "$([[ $rc -eq 0 ]] && echo yes || echo no)"
  printf '{"tldr":"Note: Guest\\u200b reaches the \\"export\\" page at /admin\\\\export.","description":"d","areas":[{"layer":"L","files":["src/A.php"],"summary":"s"}],"effort":{"level":2,"minutes":10},"change_type":"Feature"}' >"$tmp/walk-escaped.json"
  WALK_FILE="$tmp/walk-escaped.json" GH_PRIVATE_VALUE=false run_post "[$f_wh,$f_in]"
  check 'a zero-width character inside a withheld title does not hide it' "$([[ $rc -eq 1 && ! -s "$tmp/review.calls" ]] && grep -q "names the withheld finding(s) $id3" "$tmp/stderr" && echo yes || echo no)"
  local f_names_wh
  f_names_wh="$(jq -n -c --arg id "$id2" '{finding_id: $id, severity: "Moderate", title: "Related", path: "src/A.php", line: 100, problem: "See GUEST reaches  the \"export\" page at /admin\\export.", impact: "i", fix: "f"}')"
  GH_PRIVATE_VALUE=false run_post "[$f_wh,$f_names_wh]"
  check 'a published finding that names a withheld title is refused and nothing is posted' "$([[ $rc -eq 1 && ! -s "$tmp/review.calls" ]] && grep -q "names the withheld finding(s) $id3" "$tmp/stderr" && echo yes || echo no)"
  GH_PRIVATE_VALUE=false run_post "[$f_wh,$f_in]"
  check 'a walkthrough and findings that do not name the withheld finding are published' "$([[ $rc -eq 0 ]] && out_has ".withheld[0].finding_id == \"$id3\"" && echo yes || echo no)"

  # 8c2. the check compares the words a reader sees: Markdown inline markup (a code span, bold, emphasis, strikethrough,
  # an inline link with parentheses in its target, a shortcut reference link and its definition), every invisible
  # character (Unicode category Cf, a default ignorable code point, a no-break space), and a Unicode case difference hide
  # no title, on either side
  local f_wh_span f_wh_plain f_wh_accent wh_walk
  f_wh_span="$(jq -n -c --arg id "$id3" '{finding_id: $id, severity: "Critical", title: "Guest reaches the export page at `/admin/export`", path: "src/A.php", line: 10, problem: "p", impact: "i", fix: "f", do_not_file_publicly: true}')"
  f_wh_plain="$(jq -n -c --arg id "$id3" '{finding_id: $id, severity: "Critical", title: "Guest reaches the export page at /admin/export", path: "src/A.php", line: 10, problem: "p", impact: "i", fix: "f", do_not_file_publicly: true}')"
  f_wh_accent="$(jq -n -c --arg id "$id3" '{finding_id: $id, severity: "Critical", title: "Guest reaches the éxport page", path: "src/A.php", line: 10, problem: "p", impact: "i", fix: "f", do_not_file_publicly: true}')"
  # Each case is the title kind and the TL;DR as a JSON string, so an invisible character is written as its escape.
  for wh_walk in \
    'span|"Note: Guest reaches the export page at /admin/export."' \
    'plain|"Note: Guest reaches the **export** page at `/admin/export`."' \
    'plain|"Note: ~~Guest~~ reaches the _export_ page at [/admin/export](https://e/x)."' \
    'plain|"Note: Guest reaches the export⁢ page at /admin/export."' \
    'plain|"Note: Guest reaches the ex͏port page at /admin/export."' \
    'plain|"Note: Guest reaches the export page at /admin/export."' \
    'plain|"Note: [Guest reaches](https://e/(a)) the export page at /admin/export."' \
    'plain|"Note: [Guest reaches] the export page at /admin/export.\n\n[guest reaches]: https://e/x"' \
    'accent|"Note: GUEST REACHES THE ÉXPORT PAGE."'; do
    jq -c --argjson t "${wh_walk#*|}" '.tldr = $t' "$tmp/walk.json" >"$tmp/walk-markup-leak.json"
    case "${wh_walk%%|*}" in
      span) WALK_FILE="$tmp/walk-markup-leak.json" GH_PRIVATE_VALUE=false run_post "[$f_wh_span,$f_in]" ;;
      accent) WALK_FILE="$tmp/walk-markup-leak.json" GH_PRIVATE_VALUE=false run_post "[$f_wh_accent,$f_in]" ;;
      *) WALK_FILE="$tmp/walk-markup-leak.json" GH_PRIVATE_VALUE=false run_post "[$f_wh_plain,$f_in]" ;;
    esac
    check "a walkthrough that names a withheld title in other markup or with an invisible character is refused: ${wh_walk#*|}" \
      "$([[ $rc -eq 1 && ! -s "$tmp/review.calls" ]] && grep -q "names the withheld finding(s) $id3" "$tmp/stderr" && echo yes || echo no)"
  done

  # 8c3. the flag holds for the finding id: an entry that shares the id of a withheld entry, on another line, is withheld
  # too, and every entry whose id is not withheld is scanned
  local f_wh_a f_wh_b f_names_span
  f_wh_a="$(jq -n -c --arg id "$id3" '{finding_id: $id, severity: "Critical", title: "Guest reads the export", path: "src/A.php", line: 10, problem: "p", impact: "i", fix: "f", do_not_file_publicly: true}')"
  f_wh_b="$(jq -n -c --arg id "$id3" '{finding_id: $id, severity: "Critical", title: "Guest reads the export", path: "src/A.php", line: 11, problem: "p2", impact: "i2", fix: "f2"}')"
  GH_PRIVATE_VALUE=false run_post "[$f_wh_a,$f_wh_b,$f_mod]"
  check 'an entry that shares the id of a withheld entry is withheld too, in no visible line' \
    "$([[ $rc -eq 0 ]] && out_has "(.withheld | length) == 2 and all(.withheld[]; .finding_id == \"$id3\") and (.listed | length) == 1" \
      && review_has "(has(\"comments\") | not) and (.body | contains(\"$id3\") | not)" \
      && review_has '.body | (contains("Guest reads the export") | not) and contains("<strong>Actionable comments (1)</strong>")' && echo yes || echo no)"
  GH_PRIVATE_VALUE=true run_post "[$f_wh_a,$f_wh_b]"
  check 'a private repository lists both entries of the id' "$([[ $rc -eq 0 ]] && out_has '(.listed | length) == 2 and (.withheld | length) == 0' && echo yes || echo no)"
  f_names_span="$(jq -n -c --arg id "$id2" '{finding_id: $id, severity: "Moderate", title: "Related", path: "src/A.php", line: 100, problem: "See **guest reads** the `export`.", impact: "i", fix: "f"}')"
  GH_PRIVATE_VALUE=false run_post "[$f_wh_a,$f_wh_b,$f_names_span]"
  check 'a published entry beside two entries of a withheld id that names the title in markup is refused' \
    "$([[ $rc -eq 1 && ! -s "$tmp/review.calls" ]] && grep -q "names the withheld finding(s) $id3" "$tmp/stderr" && echo yes || echo no)"

  # 8c4. no dedup drops the flag: two entries of one id on one path and line are withheld whichever of them carries the
  # flag, and the title of the duplicate the dedup dropped is scanned too
  local f_dup_plain f_dup_flag f_dup_other dup_order
  f_dup_plain="$(jq -n -c --arg id "$id3" '{finding_id: $id, severity: "Critical", title: "Guest reads the export", path: "src/A.php", line: 10, problem: "p", impact: "i", fix: "f"}')"
  f_dup_flag="$(jq -n -c --arg id "$id3" '{finding_id: $id, severity: "Critical", title: "Guest reads the export", path: "src/A.php", line: 10, problem: "p2", impact: "i2", fix: "f2", do_not_file_publicly: true}')"
  for dup_order in "$f_dup_plain,$f_dup_flag" "$f_dup_flag,$f_dup_plain"; do
    GH_PRIVATE_VALUE=false run_post "[$dup_order,$f_mod]"
    check "two entries of one id on one path and line are withheld whichever of them carries the flag: ${dup_order:0:60}" \
      "$([[ $rc -eq 0 ]] && out_has "(.withheld | length) == 1 and .withheld[0].finding_id == \"$id3\" and (.listed | length) == 1" \
        && review_has "(has(\"comments\") | not) and (.body | contains(\"$id3\") | not)" \
        && review_has '.body | (contains("Guest reads the export") | not) and contains("<strong>Actionable comments (1)</strong>")' && echo yes || echo no)"
  done
  f_dup_other="$(jq -n -c --arg id "$id3" '{finding_id: $id, severity: "Critical", title: "Admin token leaks", path: "src/A.php", line: 10, problem: "p2", impact: "i2", fix: "f2", do_not_file_publicly: true}')"
  jq -c '.tldr = "The change stops the admin token leaks."' "$tmp/walk.json" >"$tmp/walk-dup.json"
  WALK_FILE="$tmp/walk-dup.json" GH_PRIVATE_VALUE=false run_post "[$f_dup_plain,$f_dup_other]"
  check 'the title of a dropped duplicate of a withheld id is scanned too' \
    "$([[ $rc -eq 1 && ! -s "$tmp/review.calls" ]] && grep -q "names the withheld finding(s) $id3" "$tmp/stderr" && echo yes || echo no)"

  # 9. a failed write exits 3 and leaves a note line, never an external-write line
  run_post "[$f_in]" GH_POST_FAILS=1
  check 'a failed review request exits 3' "$([[ $rc -eq 3 ]] && echo yes || echo no)"
  check 'a failed review request leaves a note line' "$([[ "$(grep -c '|note|' "$tmp/ledger.audit")" == "1" && "$(grep -c '|external-write|' "$tmp/ledger.audit")" == "0" ]] && echo yes || echo no)"

  # 10. an invalid document, walkthrough, or record publishes nothing
  META_FILE="$tmp/meta-bad.md" run_post "[$f_in]"
  check 'a record that check-cr-header.sh refuses publishes nothing' "$([[ $rc -eq 1 && ! -s "$tmp/review.calls" ]] && grep -q 'refused by check-cr-header.sh' "$tmp/stderr" && echo yes || echo no)"
  META_FILE="$tmp/does-not-exist.md" run_post "[$f_in]"
  check 'a missing record publishes nothing' "$([[ $rc -eq 1 && ! -s "$tmp/review.calls" ]] && echo yes || echo no)"
  META_FILE="$tmp/meta-norisk.md" run_post "[$f_in]"
  check 'a record with no Merge risk line publishes nothing' "$([[ $rc -eq 1 && ! -s "$tmp/review.calls" ]] && grep -q 'Merge Risk block could not be read' "$tmp/stderr" && echo yes || echo no)"
  META_FILE="$tmp/meta-self-review.md" run_post "[$f_in]"
  check 'a review the session wrote itself says so in the visible body, under the summary' \
    "$([[ $rc -eq 0 ]] && visible_of | grep -qxF '**Review independence:** not independent — helper agents are forbidden for this project' && yes_if review_has '.body | index("**Review independence:** not independent") < index("Merge Risk:</strong>")' || echo no)"
  META_FILE="$tmp/meta-self-review-forged.md" run_post "[$f_in]"
  check 'a record with another Review independence line publishes nothing' "$([[ $rc -eq 1 && ! -s "$tmp/review.calls" ]] && grep -q 'Review independence line' "$tmp/stderr" && echo yes || echo no)"
  run_post "[$f_in]"
  check 'a review with no Review independence line shows none' "$([[ $rc -eq 0 ]] && ! visible_of | grep -qF 'Review independence' && echo yes || echo no)"
  META_FILE="$tmp/meta-suggestion.md" run_post "[$f_in]"
  check 'a record that holds a suggestion block publishes nothing' "$([[ $rc -eq 1 && ! -s "$tmp/review.calls" ]] && grep -q 'holds a suggestion block' "$tmp/stderr" && echo yes || echo no)"
  META_FILE="$tmp/meta-walk-blocked.md" run_post "[$f_in]"
  check 'a record whose interactive testing rows are all Blocked publishes nothing' "$([[ $rc -eq 1 && ! -s "$tmp/review.calls" ]] && grep -q 'interactive testing that did not run' "$tmp/stderr" && echo yes || echo no)"
  META_FILE="$tmp/meta-walk-ran.md" run_post "[$f_in]"
  check 'a record whose interactive testing ran a scenario is published' "$([[ $rc -eq 0 && "$(wc -l <"$tmp/review.calls" | tr -d ' ')" == "1" ]] && echo yes || echo no)"
  META_FILE="$tmp/meta-walk-failed.md" run_post "[$f_in]"
  check 'a record whose interactive testing has a Failed row beside a Blocked row is published' "$([[ $rc -eq 0 && "$(wc -l <"$tmp/review.calls" | tr -d ' ')" == "1" ]] && echo yes || echo no)"
  META_FILE="$tmp/meta-walk-passed.md" run_post "[$f_in]"
  check 'a record whose interactive testing has a Passed row beside a Blocked row is published' "$([[ $rc -eq 0 && "$(wc -l <"$tmp/review.calls" | tr -d ' ')" == "1" ]] && echo yes || echo no)"
  jq -c '. + [{filename: "tests/Feature/ATest.php"}]' "$tmp/files.json" >"$tmp/files.with-test.json"
  jq -c '.areas += [{layer: "Tests", files: ["tests/Feature/ATest.php"], summary: "The happy path."}]' "$tmp/walk.json" >"$tmp/walk-test-row.json"
  GH_FILES_FILE="$tmp/files.with-test.json" WALK_FILE="$tmp/walk-test-row.json" run_post "[$f_in]"
  check 'a walkthrough row that names a test file is refused and nothing is posted' "$([[ $rc -eq 1 && ! -s "$tmp/review.calls" ]] && grep -q 'names a test file' "$tmp/stderr" && echo yes || echo no)"
  GH_FILES_FILE="$tmp/files.with-test.json" run_post "[$f_in]"
  check 'a walkthrough with no test file is published for a pull request that changes a test too' "$([[ $rc -eq 0 ]] && echo yes || echo no)"
  printf '[{"filename":"tests/Feature/ATest.php","patch":"@@ -1 +1 @@\\n+x"}]' >"$tmp/files.only-test.json"
  jq -c '.areas = [{layer: "Tests", files: ["tests/Feature/ATest.php"], summary: "The happy path."}]' "$tmp/walk.json" >"$tmp/walk-only-test.json"
  GH_FILES_FILE="$tmp/files.only-test.json" WALK_FILE="$tmp/walk-only-test.json" run_post "[]"
  check 'a pull request that changes only tests may list its test files' "$([[ $rc -eq 0 ]] && echo yes || echo no)"
  printf '[{"filename":"tests/Feature/ATest.php","patch":"@@ -1 +1 @@\\n+x"},{"filename":"phpunit.xml","patch":"@@ -1 +1 @@\\n+x"}]' >"$tmp/files.tests-and-config.json"
  GH_FILES_FILE="$tmp/files.tests-and-config.json" WALK_FILE="$tmp/walk-only-test.json" run_post "[]"
  check 'a pull request that changes tests and phpunit.xml only is a tests-only pull request, as classify-risk.sh grades it' "$([[ $rc -eq 0 ]] && echo yes || echo no)"
  # 10b. a walkthrough with since covers the changes since the previous published review only
  local since_sha="cccccccccccccccccccccccccccccccccccccccc"
  printf '{"status":"ahead","files":[{"filename":"src/A.php"}]}' >"$tmp/compare.ahead"
  printf '{"status":"diverged","files":[{"filename":"src/A.php"}]}' >"$tmp/compare.diverged"
  printf '{"status":"ahead","files":[{"filename":"tests/Feature/ATest.php"}]}' >"$tmp/compare.only-test"
  jq -c --arg s "$since_sha" '.since = $s' "$tmp/walk.json" >"$tmp/walk-since.json"
  WALK_FILE="$tmp/walk-since.json" run_post "[]"
  check 'a walkthrough with since is published and its summary names the earlier commit' "$([[ $rc -eq 0 ]] && yes_if jq -e '.body | contains("<summary><strong>Walkthrough</strong> · since <code>ccccccc</code></summary>")' "$tmp/review.json" || echo no)"
  WALK_FILE="$tmp/walk.json" run_post "[]"
  check 'a walkthrough without since shows no since in its summary' "$([[ $rc -eq 0 ]] && yes_if jq -e '.body | contains("<summary><strong>Walkthrough</strong></summary>")' "$tmp/review.json" || echo no)"
  GH_FILES_FILE="$tmp/files.with-test.json" GH_COMPARE_FILE="$tmp/compare.ahead" WALK_FILE="$(jq -c --arg s "$since_sha" '.since = $s | .areas = [{layer: "Tests", files: ["tests/Feature/ATest.php"], summary: "s"}]' "$tmp/walk.json" >"$tmp/walk-since-test.json" && echo "$tmp/walk-since-test.json")" run_post "[]"
  check 'a walkthrough with since may not name a file the changes since that commit do not touch' "$([[ $rc -eq 1 && ! -s "$tmp/review.calls" ]] && grep -q 'reviewed changes do not touch' "$tmp/stderr" && echo yes || echo no)"
  GH_FILES_FILE="$tmp/files.with-test.json" GH_COMPARE_FILE="$tmp/compare.only-test" WALK_FILE="$tmp/walk-since-test.json" run_post "[]"
  check 'changes since the earlier commit that touch only tests may list their test files' "$([[ $rc -eq 0 ]] && echo yes || echo no)"
  printf '{"status":"ahead","files":[{"filename":"src/A.php"},{"filename":"src/Merged.php"}]}' >"$tmp/compare.merged-in"
  jq -c --arg s "$since_sha" '.since = $s | .areas = [{layer: "Source", files: ["src/Merged.php"], summary: "s"}]' "$tmp/walk.json" >"$tmp/walk-since-merged.json"
  GH_COMPARE_FILE="$tmp/compare.merged-in" WALK_FILE="$tmp/walk-since-merged.json" run_post "[]"
  check 'a walkthrough with since may not name a file of the compare that is no file of the pull request' "$([[ $rc -eq 1 && ! -s "$tmp/review.calls" ]] && grep -q 'reviewed changes do not touch' "$tmp/stderr" && echo yes || echo no)"
  GH_COMPARE_FILE="$tmp/compare.diverged" WALK_FILE="$tmp/walk-since.json" run_post "[]"
  check 'a since that is not an earlier commit of the head is refused and nothing is posted' "$([[ $rc -eq 1 && ! -s "$tmp/review.calls" ]] && grep -q 'is not an earlier commit of the head' "$tmp/stderr" && echo yes || echo no)"
  WALK_FILE="$tmp/walk-since.json" run_post "[]" GH_COMPARE_FAILS=1
  check 'a failed compare lookup exits 3 and posts nothing' "$([[ $rc -eq 3 && ! -s "$tmp/review.calls" ]] && echo yes || echo no)"
  jq -c '.since = "abc"' "$tmp/walk.json" >"$tmp/walk-since-bad.json"
  WALK_FILE="$tmp/walk-since-bad.json" run_post "[]"
  check 'a since that is no full commit SHA is refused and nothing is posted' "$([[ $rc -eq 1 && ! -s "$tmp/review.calls" ]] && grep -q 'full 40-character commit SHA' "$tmp/stderr" && echo yes || echo no)"
  WALK_FILE="$tmp/does-not-exist.json" run_post "[$f_in]"
  check 'a missing walkthrough file publishes nothing' "$([[ $rc -eq 1 && ! -s "$tmp/review.calls" ]] && grep -q 'cannot read the walkthrough file' "$tmp/stderr" && echo yes || echo no)"
  set +e
  bash "$script" post https://github.com/o/r/pull/7 --commit-id "$sha" --meta-file "$tmp/meta.md" - <<<"[]" >/dev/null 2>&1
  rc=$?
  set -e
  check 'a post without --walkthrough-file is a usage error' "$([[ $rc -eq 1 ]] && echo yes || echo no)"
  local walk_base='"tldr":"t","description":"d","areas":[{"layer":"L","files":["src/A.php"],"summary":"s"}],"effort":{"level":2,"minutes":10},"change_type":"Feature"' wbad
  for wbad in \
    '[]' \
    '{"tldr":"t","areas":[{"layer":"L","files":["src/A.php"],"summary":"s"}],"effort":{"level":2,"minutes":10},"change_type":"Feature"}' \
    "{$walk_base,\"extra\":1}" \
    '{"tldr":"t","description":"d","areas":[],"effort":{"level":2,"minutes":10},"change_type":"Feature"}' \
    '{"tldr":"t","description":"d","areas":[{"layer":"L","files":["src/Other.php"],"summary":"s"}],"effort":{"level":2,"minutes":10},"change_type":"Feature"}' \
    '{"tldr":"t","description":"d","areas":[{"layer":"L","files":["../etc/passwd"],"summary":"s"}],"effort":{"level":2,"minutes":10},"change_type":"Feature"}' \
    '{"tldr":"t","description":"d","areas":[{"layer":"L","files":["src/A.php"],"summary":"s"}],"effort":{"level":6,"minutes":10},"change_type":"Feature"}' \
    '{"tldr":"t","description":"d","areas":[{"layer":"L","files":["src/A.php"],"summary":"s"}],"effort":{"level":2,"minutes":0},"change_type":"Feature"}' \
    '{"tldr":"t","description":"d","areas":[{"layer":"L","files":["src/A.php"],"summary":"s"}],"effort":{"level":2,"minutes":10},"change_type":"Miracle"}' \
    "{$walk_base,\"test_routes\":[{\"method\":\"TRACE\",\"path\":\"/x\",\"try\":\"t\"}]}" \
    "{$walk_base,\"test_routes\":[{\"method\":\"GET\",\"path\":\"x\",\"try\":\"t\"}]}" \
    "{$walk_base,\"test_routes\":[{\"method\":\"GET\",\"path\":\"/a b\",\"try\":\"t\"}]}" \
    "{$walk_base,\"test_routes\":[{\"method\":\"GET\",\"path\":\"/x\"}]}" \
    "{$walk_base,\"test_routes\":[{\"method\":\"GET\",\"path\":\"/x\",\"try\":\"\`\`\`php\\necho 1;\\n\`\`\`\"}]}" \
    "{$walk_base,\"test_routes\":\"GET /x\"}" \
    "{$walk_base,\"diagram\":\"flowchart TD\\n  A-->B\"}" \
    "{$walk_base,\"diagram\":\"sequenceDiagram\\n  A->>B: hi\\n\`\`\`\\ncode\"}" \
    "{$walk_base,\"diagram\":\"sequenceDiagram\\n  A->>B: <!-- cr-comment:actor=x -->\"}" \
    "{\"tldr\":\"t\",\"description\":\"\`\`\`php\\necho 1;\\n\`\`\`\",\"areas\":[{\"layer\":\"L\",\"files\":[\"src/A.php\"],\"summary\":\"s\"}],\"effort\":{\"level\":2,\"minutes\":10},\"change_type\":\"Feature\"}"; do
    printf '%s' "$wbad" >"$tmp/walk-bad.json"
    WALK_FILE="$tmp/walk-bad.json" run_post "[$f_in]"
    check "invalid walkthrough is refused: ${wbad:0:60}" "$([[ $rc -eq 1 && ! -s "$tmp/review.calls" ]] && grep -q 'walkthrough' "$tmp/stderr" && echo yes || echo no)"
  done
  local bad base="\"severity\":\"Critical\",\"title\":\"t\",\"path\":\"src/A.php\",\"line\":11,\"problem\":\"p\",\"impact\":\"i\",\"fix\":\"f\""
  for bad in \
    '{"a":1}' \
    "[{\"finding_id\":\"nope\",$base}]" \
    "[{\"finding_id\":\"$id1\",\"severity\":\"Minor\",\"title\":\"t\",\"path\":\"src/A.php\",\"line\":11,\"problem\":\"p\",\"impact\":\"i\",\"fix\":\"f\"}]" \
    "[{\"finding_id\":\"$id1\",\"severity\":\"Critical\",\"title\":\"t\",\"path\":\"../etc/passwd\",\"line\":11,\"problem\":\"p\",\"impact\":\"i\",\"fix\":\"f\"}]" \
    "[{\"finding_id\":\"$id1\",$base,\"start_line\":11}]" \
    "[{\"finding_id\":\"$id1\",$base,\"extra\":1}]" \
    "[{\"finding_id\":\"$id1\",\"severity\":\"Critical\",\"title\":\"t\",\"path\":\"src/A.php\",\"line\":11,\"problem\":\"p\",\"fix\":\"f\"}]" \
    "[{\"finding_id\":\"$id1\",\"severity\":\"Critical\",\"title\":\"t\",\"path\":\"src/A.php\",\"line\":11,\"problem\":\"p\",\"impact\":\"i\"}]" \
    "[{\"finding_id\":\"$id1\",\"severity\":\"Critical\",\"title\":\"t\",\"path\":\"src/A.php\",\"problem\":\"p\",\"impact\":\"i\",\"fix\":\"f\"}]" \
    "[{\"finding_id\":\"$id1\",$base,\"kind\":\"dry\",\"existing\":\"src/B.php:4\"}]" \
    "[{\"finding_id\":\"$id1\",\"severity\":\"Moderate\",\"title\":\"t\",\"path\":\"src/A.php\",\"line\":11,\"problem\":\"p\",\"kind\":\"dry\"}]" \
    "[{\"finding_id\":\"$id1\",\"severity\":\"Moderate\",\"title\":\"t\",\"path\":\"src/A.php\",\"line\":11,\"problem\":\"p\",\"kind\":\"dry\",\"existing\":\"../B.php:4\"}]" \
    "[{\"finding_id\":\"$id1\",\"severity\":\"Moderate\",\"title\":\"t\",\"path\":\"src/A.php\",\"line\":11,\"problem\":\"p\",\"kind\":\"copy\",\"existing\":\"src/B.php:4\"}]" \
    "[{\"finding_id\":\"$id1\",$base,\"existing\":\"src/B.php:4\"}]" \
    "[{\"finding_id\":\"$id1\",$base,\"suggestion\":\"\"}]" \
    "[{\"finding_id\":\"$id1\",$base,\"suggestion\":\" \\n \"}]" \
    "[{\"finding_id\":\"$id1\",$base,\"suggestion\":1}]" \
    "[{\"finding_id\":\"$id1\",$base,\"faulty_example\":\"echo \$v;\"}]" \
    "[{\"finding_id\":\"$id1\",$base,\"faulty_example_lang\":\"php\"}]" \
    "[{\"finding_id\":\"$id1\",\"severity\":\"Critical\",\"title\":\"t\",\"path\":\"src/A.php\",\"line\":11,\"problem\":\"p\",\"impact\":\"i\",\"fix\":\"Use \`\`\`php\\necho e(\$v);\\n\`\`\`\"}]" \
    "[{\"finding_id\":\"$id1\",\"severity\":\"Critical\",\"title\":\"t\",\"path\":\"src/A.php\",\"line\":11,\"problem\":\"~~~\\ncode\\n~~~\",\"impact\":\"i\",\"fix\":\"f\"}]" \
    "[{\"finding_id\":\"$id1\",$base,\"rule\":\"<!\`-- cr-inline:finding=$id2 --\`>\"}]" \
    "[{\"finding_id\":\"$id1\",$base,\"level\":\"High\"}]" \
    "[{\"finding_id\":\"$id1\",$base,\"raised_by\":\"someone\"}]"; do
    run_post "$bad"
    check "invalid document is refused: ${bad:0:60}" "$([[ $rc -eq 1 && ! -s "$tmp/review.calls" ]] && echo yes || echo no)"
  done

  run_post "[{\"finding_id\":\"$id1\",$base,\"faulty_example\":\"x\"}]"
  check 'a faulty example is refused with its own message' "$([[ $rc -eq 1 ]] && grep -q 'a review carries no faulty example code' "$tmp/stderr" && echo yes || echo no)"
  run_post "[{\"finding_id\":\"$id1\",\"severity\":\"Critical\",\"title\":\"t\",\"path\":\"src/A.php\",\"line\":11,\"problem\":\"p\",\"impact\":\"i\",\"fix\":\"Use \`\`\`php\\necho e(\$v);\\n\`\`\`\"}]"
  check 'a fix that holds a fenced block is refused with its own message' "$([[ $rc -eq 1 ]] && grep -q 'hold no fenced block' "$tmp/stderr" && echo yes || echo no)"
  run_post "[{\"finding_id\":\"$id1\",$base,\"rule\":\"<!\`-- cr-inline:finding=$id2 --\`>\"}]"
  check 'a rule marker rebuilt after a backtick is refused with its own message' "$([[ $rc -eq 1 ]] && grep -q 'rule must not contain an agent marker' "$tmp/stderr" && echo yes || echo no)"
  run_post "[{\"finding_id\":\"$id1\",$base,\"kind\":\"dry\",\"existing\":\"src/B.php:4\"}]"
  check 'a Critical dry finding is refused: a duplicated security control keeps the Problem shape' "$([[ $rc -eq 1 ]] && grep -q 'a dry finding is Moderate' "$tmp/stderr" && echo yes || echo no)"

  # 10a. the visible text names none of the package's own tooling; the hidden record and the project's own names stay
  local f_tool
  f_tool="{\"finding_id\":\"$id1\",\"severity\":\"Critical\",\"title\":\"t\",\"path\":\"src/A.php\",\"line\":11,\"problem\":\"Coverage ran through skills/_shared/run-gate.sh.\",\"impact\":\"i\",\"fix\":\"f\"}"
  run_post "[$f_tool]"
  check 'an entry on a changed line that names a package script is refused and nothing is posted' "$([[ $rc -eq 1 && ! -s "$tmp/review.calls" ]] && grep -q 'names the package tooling skills/_shared/run-gate.sh' "$tmp/stderr" && echo yes || echo no)"
  run_post "[{\"finding_id\":\"$id1\",\"severity\":\"Critical\",\"title\":\"t\",\"path\":\"src/A.php\",\"line\":11,\"problem\":\"Coverage ran through \`skills/_shared/run-gate.sh\`.\",\"impact\":\"i\",\"fix\":\"f\"}]"
  check 'a package path in a code span, whose content is entity-encoded, is refused and nothing is posted' "$([[ $rc -eq 1 && ! -s "$tmp/review.calls" ]] && grep -q 'names the package tooling skills/_shared/run-gate.sh' "$tmp/stderr" && echo yes || echo no)"
  run_post "[{\"finding_id\":\"$id2\",\"severity\":\"Moderate\",\"title\":\"t\",\"path\":\"src/A.php\",\"line\":100,\"problem\":\"The grade of classify-risk.sh is wrong.\",\"impact\":\"i\",\"fix\":\"f\"}]"
  check 'an Actionable comments entry that names a package script is refused and nothing is posted' "$([[ $rc -eq 1 && ! -s "$tmp/review.calls" ]] && grep -q 'names the package tooling classify-risk.sh' "$tmp/stderr" && echo yes || echo no)"
  run_post "[{\"finding_id\":\"$id2\",\"severity\":\"Moderate\",\"title\":\"t\",\"path\":\"src/A.php\",\"line\":100,\"problem\":\"p\",\"impact\":\"i\",\"fix\":\"Run run-gate.sh again.\"}]"
  check 'a Fix sentence that names a package script is refused and nothing is posted' "$([[ $rc -eq 1 && ! -s "$tmp/review.calls" ]] && grep -q 'names the package tooling run-gate.sh' "$tmp/stderr" && echo yes || echo no)"
  printf '{"tldr":"t","description":"The change reruns classify-risk.sh.","areas":[{"layer":"L","files":["src/A.php"],"summary":"s"}],"effort":{"level":2,"minutes":10},"change_type":"Chore"}' >"$tmp/walk-tool.json"
  WALK_FILE="$tmp/walk-tool.json" run_post '[]'
  check 'a Walkthrough that names a package script is refused and nothing is posted' "$([[ $rc -eq 1 && ! -s "$tmp/review.calls" ]] && grep -q 'names the package tooling classify-risk.sh' "$tmp/stderr" && echo yes || echo no)"
  sed 's/The change adds a sign-in path/The change reruns run-gate.sh and adds a sign-in path/' "$tmp/meta.md" >"$tmp/meta-risk-tool.md"
  META_FILE="$tmp/meta-risk-tool.md" run_post '[]'
  check 'a Merge Risk sentence that names a package script is refused and nothing is posted' "$([[ $rc -eq 1 && ! -s "$tmp/review.calls" ]] && grep -q 'names the package tooling run-gate.sh' "$tmp/stderr" && echo yes || echo no)"
  GH_TREE_FILE="$tmp/tree.package" run_post "[$f_tool]"
  check 'a script name that the reviewed repository holds is the project own and is posted' "$([[ $rc -eq 0 ]] && review_has '.body | contains("skills/_shared/run-gate.sh")' && echo yes || echo no)"
  printf '[{"filename":"src/A.php","patch":"@@ -9,2 +9,4 @@ class A\\n ctx9\\n+$p = \x27.claude/run/state.json\x27;\\n+add11\\n ctx12"}]' >"$tmp/files.diff-name"
  run_post "[{\"finding_id\":\"$id1\",\"severity\":\"Critical\",\"title\":\"t\",\"path\":\"src/A.php\",\"line\":10,\"problem\":\"The line writes .claude/run/state.json.\",\"impact\":\"i\",\"fix\":\"f\"}]"
  check 'an installed-directory name of the package is refused when no changed line carries it' "$([[ $rc -eq 1 && ! -s "$tmp/review.calls" ]] && grep -q 'names the package tooling .claude/run/state.json' "$tmp/stderr" && echo yes || echo no)"
  GH_FILES_FILE="$tmp/files.diff-name" run_post "[{\"finding_id\":\"$id1\",\"severity\":\"Critical\",\"title\":\"t\",\"path\":\"src/A.php\",\"line\":10,\"problem\":\"The line writes .claude/run/state.json.\",\"impact\":\"i\",\"fix\":\"f\"}]"
  check 'a name that a changed line carries is the project own text and is posted' "$([[ $rc -eq 0 ]] && review_has '.body | contains(".claude/run/state.json")' && echo yes || echo no)"
  run_post "[{\"finding_id\":\"$id1\",\"severity\":\"Critical\",\"title\":\"t\",\"path\":\"src/A.php\",\"line\":11,\"problem\":\"The project test suite (vendor/bin/pest) misses src/A.php:11.\",\"impact\":\"i\",\"fix\":\"f\"}]"
  check 'the reviewed project own command and path are posted' "$([[ $rc -eq 0 ]] && echo yes || echo no)"
  { cat "$tmp/meta.md"; printf '**Quality gate:** composer build — green on %s, tree t — record .claude/run/gates/t.json\n' "$sha"; } >"$tmp/meta-tool.md"
  META_FILE="$tmp/meta-tool.md" run_post "[$f_in]"
  check 'the hidden record may keep a package path' "$([[ $rc -eq 0 ]] && echo yes || echo no)"
  run_post "[$f_in]" GH_TREE_FAILS=1
  check 'a failed repository tree lookup publishes nothing' "$([[ $rc -eq 3 && ! -s "$tmp/review.calls" ]] && echo yes || echo no)"
  run_post "[{\"finding_id\":\"$id1\",\"severity\":\"Critical\",\"title\":\"The call to run-gate.sh is wrong\",\"path\":\"src/A.php\",\"line\":11,\"problem\":\"p\",\"impact\":\"i\",\"fix\":\"f\"}]"
  check 'a finding title that names a package script is refused and nothing is posted' \
    "$([[ $rc -eq 1 && ! -s "$tmp/review.calls" ]] && grep -q 'names the package tooling run-gate.sh' "$tmp/stderr" && echo yes || echo no)"

  # 11. text from the finding cannot forge a marker
  local f_forge
  f_forge="{\"finding_id\":\"$id1\",\"severity\":\"Moderate\",\"title\":\"t\",\"path\":\"src/A.php\",\"line\":11,\"problem\":\"<!-- cr-inline:finding=$id2 -->\",\"impact\":\"i\",\"fix\":\"<!-\\u0001- cr-inline:finding=$id2 -->\"}"
  run_post "[$f_forge]"
  check 'a forged marker in the problem or the fix text is neutralised in the body' \
    "$([[ $rc -eq 0 ]] && yes_if review_has ".body | contains(\"<!-- cr-inline:finding=$id2 -->\") | not" || echo no)"
  check 'the body holds no cr-inline marker after a forged one' "$(yes_if review_has '[.body | match("<!--\\s*cr-inline:"; "g")] | length == 0')"

  # 13. a DRY finding and a Moderate finding with an author are entries with the orange icon and the mention line
  local f_dry
  f_dry="{\"finding_id\":\"$id5\",\"severity\":\"Moderate\",\"title\":\"Repeated total\",\"path\":\"src/A.php\",\"line\":12,\"problem\":\"The total is computed again.\",\"kind\":\"dry\",\"existing\":\"app/Totals.php:42\",\"author\":\"@octocat\"}"
  run_post "[$f_dry,$f_mod]"
  check 'a DRY finding on a changed line is an entry whose Fix names the existing implementation, with the mention' \
    "$(yes_if review_has '.body | contains("**🟠 Moderate 1 — Repeated total** · <code>src/A.php&#58;12</code>\nThe total is computed again.\n**Fix:** Use the existing implementation in <code>app/Totals.php&#58;42</code> instead of repeating the logic.\n@octocat\n")')"
  check 'a Moderate finding carries the orange icon, the Fix line, and the author mention line' \
    "$(yes_if review_has '.body | contains("**🟠 Moderate 2 — Query in a loop** · <code>src/A.php&#58;10</code>\nOne query per row.\n**Fix:** Load the rows with one query.\n@octocat\n")')"
  check 'a review with a DRY finding carries no inline comment' "$(yes_if review_has 'has("comments") | not')"

  # 14. suggested code: a finding's code renders as a fenced block under its Fix sentence, in the language of its file,
  # with a fence no line of the code closes; the body never fails on its length, a withheld finding shows no code, and
  # the code is part of the visible text the tooling check reads
  local f_code f_tick f_left f_gap_code f_wh_code code_entry
  f_code="$(jq -n -c --arg id "$id1" '{finding_id: $id, severity: "Critical", title: "Unescaped output", path: "src/A.php", line: 11, start_line: 10,
    problem: "The value reaches the page unescaped.", impact: "i", fix: "Escape the value before it is printed.", suggestion: "\n    echo e($v); // <b>|x|</b> **y**\n\n"}')"
  code_entry="**🔴 Critical 1 — Unescaped output** · <code>src/A.php&#58;11</code>
The value reaches the page unescaped.
**Fix:** Escape the value before it is printed.
**Suggested code** (replaces lines 10–11):
${bt}php
    echo e(\$v); // <b>|x|</b> **y**
${bt}"
  run_post "[$f_code]"
  check 'a suggestion renders as a fenced block in the language of the file, as written, under the Fix line' \
    "$([[ $rc -eq 0 ]] && jq -e --arg want "$code_entry" '.body | contains($want + "\n\n</dd>")' "$tmp/review.json" >/dev/null && echo yes || echo no)"
  f_tick="$(jq -n -c --arg id "$id1" '{finding_id: $id, severity: "Critical", title: "t", path: "docs/A.md", line: 11, problem: "p", impact: "i", fix: "f",
    suggestion: "Run:\n```bash\nmake\n```\nand ````x````"}')"
  run_post "[$f_tick]"
  check 'code that holds fences gets a longer fence and is posted' \
    "$([[ $rc -eq 0 ]] && review_has '.body | contains("**Suggested code** (replaces line 11):\n`````md\nRun:\n```bash\nmake\n```\nand ````x````\n`````\n")' && echo yes || echo no)"
  f_left="$(jq -n -c --arg id "$id4" '{finding_id: $id, severity: "Moderate", title: "Removed guard", path: "src/A.php", line: 30, side: "LEFT", problem: "p", impact: "i", fix: "f", suggestion: "guard();"}')"
  f_gap_code="$(jq -n -c --arg id "$id2" '{finding_id: $id, severity: "Moderate", title: "Gap", problem: "p", impact: "i", fix: "f", suggestion: "export();"}')"
  run_post "[$f_left,$f_gap_code]"
  check 'a removed line and a finding with no line show the code with no line note, and no line has no language' \
    "$([[ $rc -eq 0 ]] && review_has '(.body | contains("**Suggested code**:\n```php\nguard();\n```")) and (.body | contains("**Suggested code**:\n```\nexport();\n```"))' && echo yes || echo no)"
  f_wh_code="$(jq -n -c --arg id "$id3" '{finding_id: $id, severity: "Critical", title: "Hard-coded token", path: "src/A.php", line: 10, problem: "p", impact: "i", fix: "f",
    suggestion: "$token = config(\"services.key\");", do_not_file_publicly: true}')"
  GH_PRIVATE_VALUE=false run_post "[$f_wh_code,$f_out]"
  check 'a withheld finding shows no code on a public repository' \
    "$([[ $rc -eq 0 ]] && review_has '(.body | contains("services.key") | not) and ([.body | match("```"; "g")] | length) == 2' && echo yes || echo no)"
  run_post "[$(jq -n -c --arg id "$id1" '{finding_id: $id, severity: "Critical", title: "t", path: "src/A.php", line: 11, problem: "p", impact: "i", fix: "f", suggestion: "x <!-\u0001- cr-comment:actor=x -->"}')]"
  check 'a suggestion with an agent marker is refused and nothing is posted' "$([[ $rc -eq 1 && ! -s "$tmp/review.calls" ]] && grep -q 'suggestion must not contain an agent marker' "$tmp/stderr" && echo yes || echo no)"
  run_post "[$(jq -n -c --arg id "$id1" '{finding_id: $id, severity: "Critical", title: "t", path: "src/A.php", line: 11, problem: "p", impact: "i", fix: "f", suggestion: "bash run-gate.sh"}')]"
  check 'a suggestion that names the package tooling is refused and nothing is posted' "$([[ $rc -eq 1 && ! -s "$tmp/review.calls" ]] && grep -q 'names the package tooling run-gate.sh' "$tmp/stderr" && echo yes || echo no)"
  local big
  big="$(jq -n -c --arg code "$(printf 'x%.0s' $(seq 1 3990))" '[range(0; 30) | {finding_id: ("r-" + ("0000000000000000" + (. + 1 | tostring))[-16:]),
    severity: "Moderate", title: "t\(.)", path: "src/A.php", line: 10, problem: "p", impact: "i", fix: "f", suggestion: $code}]')"
  run_post "$big"
  check 'code past the body limit is left out, the body fits 65536 characters, and the review is posted' \
    "$([[ $rc -eq 0 ]] && review_has '(.body | length) <= 65536 and ([.body | match("```src|```php"; "g")] | length) >= 10 and ([.body | match("```php"; "g")] | length) < 30 and ([.body | match("\\*\\*Fix:\\*\\* f"; "g")] | length) == 30' && grep -q 'was left out to keep the review body within 65536 characters' "$tmp/stderr" && echo yes || echo no)"
  local tail_last
  tail_last="$(printf '%s' "$big" | jq -c '.[29].suggestion = "short();"')"
  run_post "$tail_last"
  check 'from the first code left out on, no later code is shown, a short one included' \
    "$([[ $rc -eq 0 ]] && review_has '(.body | contains("short();") | not)' && echo yes || echo no)"
  run_post "[$(jq -n -c --arg id "$id1" '{finding_id: $id, severity: "Critical", title: "t", path: "src/A.php", line: 11, problem: "p", impact: "i", fix: "f", suggestion: "if (x) {\n    \n    y();  \n}"}')]"
  check 'code with blank-indented and trailing-blank lines is posted with those blanks trimmed' \
    "$([[ $rc -eq 0 ]] && review_has '.body | contains("```php\nif (x) {\n\n    y();\n}\n```")' && echo yes || echo no)"
  jq -c '.diagram = "sequenceDiagram\n  participant Owner \n  Owner->>Owner: hi"' "$tmp/walk.json" >"$tmp/walk-trail.json"
  WALK_FILE="$tmp/walk-trail.json" run_post "[$f_in]"
  check 'a diagram line with a trailing blank is posted' "$([[ $rc -eq 0 ]] && echo yes || echo no)"
  run_post "[$(jq -n -c --arg id "$id1" --arg d "$(jq -r .diagram "$tmp/walk.json")" '{finding_id: $id, severity: "Critical", title: "t", path: "docs/A.md", line: 11, problem: "p", impact: "i", fix: "f", suggestion: ("```mermaid\n" + $d + "\n```")}')]"
  check 'code that holds the Walkthrough diagram block word for word is posted' "$([[ $rc -eq 0 ]] && echo yes || echo no)"
  run_post "[$(jq -n -c --arg id "$id1" '{finding_id: $id, severity: "Critical", title: "t", path: "src/A.php", line: 11, problem: "p", impact: "i", fix: "f", suggestion: "if ($a ‮) {}"}')]"
  check 'a suggestion with a bidirectional control character is refused and nothing is posted' \
    "$([[ $rc -eq 1 && ! -s "$tmp/review.calls" ]] && grep -q 'bidirectional control character' "$tmp/stderr" && echo yes || echo no)"
  run_post "[$(jq -n -c --arg id "$id1" '{finding_id: $id, severity: "Critical", title: "t", path: "src/A.suggestion", line: 11, problem: "p", impact: "i", fix: "f", suggestion: "x"}')]"
  check 'a file named .suggestion gives the fence no suggestion language' \
    "$([[ $rc -eq 0 ]] && review_has '(.body | test("```suggestion") | not) and (.body | contains("```\nx\n```"))' && echo yes || echo no)"

  # 12. resolve: only the threads of this actor for the given ids (an earlier release opened them), and a failed
  # mutation is a failure
  cat >"$tmp/threads.1" <<JSON
{"data":{"repository":{"pullRequest":{"reviewThreads":{"pageInfo":{"hasNextPage":false,"endCursor":null},"nodes":[
 {"id":"TA","isResolved":false,"path":"src/A.php","line":11,"originalLine":11,"comments":{"nodes":[{"author":{"login":"bot"},"body":"<!-- cr-inline:actor=bot -->\n<!-- cr-inline:finding=$id1 -->"}]}},
 {"id":"TB","isResolved":false,"path":"src/A.php","line":12,"originalLine":12,"comments":{"nodes":[{"author":{"login":"bot"},"body":"<!-- cr-inline:actor=bot -->\n<!-- cr-inline:finding=$id2 -->"}]}},
 {"id":"TC","isResolved":false,"path":"src/A.php","line":13,"originalLine":13,"comments":{"nodes":[{"author":{"login":"stranger"},"body":"<!-- cr-inline:actor=bot -->\n<!-- cr-inline:finding=$id1 -->"}]}},
 {"id":"TD","isResolved":true,"path":"src/A.php","line":14,"originalLine":14,"comments":{"nodes":[{"author":{"login":"bot"},"body":"<!-- cr-inline:actor=bot -->\n<!-- cr-inline:finding=$id1 -->"}]}},
 {"id":"TE","isResolved":false,"path":"src/A.php","line":15,"originalLine":15,"comments":{"nodes":[{"author":{"login":"human"},"body":"please rename"}]}},
 {"id":"TF","isResolved":false,"path":"src/A.php","line":16,"originalLine":16,"comments":{"nodes":[{"author":{"login":"bot"},"body":"<!-- cr-inline:actor=bot -->\n<!-- cr-inline:finding=$id5 -->"},{"author":{"login":"human"},"body":"this is incomplete, also validate X"}]}},
 {"id":"TG","isResolved":false,"path":"src/A.php","line":17,"originalLine":17,"comments":{"nodes":[{"author":{"login":"bot"},"body":"<!-- cr-inline:actor=bot -->\n<!-- cr-inline:finding=$id6 -->"},{"author":{"login":"bot"},"body":"operator reply under the shared account, no marker"}]}},
 {"id":"TH","isResolved":false,"path":"src/A.php","line":18,"originalLine":18,"comments":{"pageInfo":{"hasNextPage":true,"endCursor":"k1"},"nodes":[{"author":{"login":"bot"},"body":"<!-- cr-inline:actor=bot -->\n<!-- cr-inline:finding=$id7 -->"}]}}]}}}}}
JSON
  cat >"$tmp/node.page" <<'JSON'
{"data":{"node":{"comments":{"pageInfo":{"hasNextPage":false,"endCursor":null},"nodes":[{"author":{"login":"human"},"body":"a late reply on page two"}]}}}}
JSON
  : >"$tmp/resolves"
  : >"$tmp/ledger.audit"
  set +e
  out="$(PATH="$bin:$PATH" GH_ACTOR=bot GH_THREADS_1="$tmp/threads.1" GH_THREADS_2="$tmp/threads.2" GH_RESOLVES="$tmp/resolves" GH_NODE_PAGE="$tmp/node.page" \
    AUDIT_TRAIL_FILE="$tmp/ledger.audit" AUDIT_TRAIL_ROLE=leonardo bash "$script" resolve https://github.com/o/r/pull/7 --fixed "$id1,$id4,$id5,$id6,$id7" 2>"$tmp/stderr")"
  rc=$?
  set -e
  check 'resolve exits 0' "$([[ $rc -eq 0 ]] && echo yes || echo no)"
  check 'resolve closes only the open thread of this actor for a fixed id' "$([[ "$(wc -l <"$tmp/resolves" | tr -d ' ')" == "1" ]] && grep -q 'threadId=TA' "$tmp/resolves" && echo yes || echo no)"
  check 'resolve reports the resolved thread and the id with no open thread' "$(yes_if out_has ".resolved[0].thread_id == \"TA\" and .not_open == [\"$id4\"]")"
  check 'a thread with a reply by another author is reported as has_reply and not resolved' "$(yes_if out_has ".has_reply | map(.thread_id) | contains([\"TF\"])")"
  check 'a reply of the actor without its marker counts as a reviewer comment' "$(yes_if out_has ".has_reply | map(.thread_id) | contains([\"TG\"])")"
  check 'a reply on a later page of the thread comments is found' "$(yes_if out_has ".has_reply | map(.thread_id) | contains([\"TH\"]) and length == 3")"
  check 'a thread with a reply is not an unopened finding' "$(yes_if out_has ".not_open == [\"$id4\"]")"
  check 'resolve leaves one external-write audit line' "$([[ "$(grep -c '|external-write|resolve review thread' "$tmp/ledger.audit")" == "1" ]] && echo yes || echo no)"
  : >"$tmp/resolves"
  : >"$tmp/ledger.audit"
  set +e
  out="$(PATH="$bin:$PATH" GH_ACTOR=bot GH_RESOLVE_FAILS=1 GH_THREADS_1="$tmp/threads.1" GH_THREADS_2="$tmp/threads.2" GH_RESOLVES="$tmp/resolves" GH_NODE_PAGE="$tmp/node.page" \
    AUDIT_TRAIL_FILE="$tmp/ledger.audit" AUDIT_TRAIL_ROLE=leonardo bash "$script" resolve https://github.com/o/r/pull/7 --fixed "$id1" 2>"$tmp/stderr")"
  rc=$?
  set -e
  check 'a failed resolve exits 3 and leaves a note line' "$([[ $rc -eq 3 && "$(grep -c '|note|' "$tmp/ledger.audit")" == "1" ]] && echo yes || echo no)"
  set +e
  bash "$script" resolve https://github.com/o/r/pull/7 --fixed "not-an-id" >/dev/null 2>&1
  rc=$?
  set -e
  check 'resolve refuses a malformed id list' "$([[ $rc -eq 1 ]] && echo yes || echo no)"

  if [[ "$failures" -gt 0 ]]; then
    echo "self-test: $failures of $checks checks failed" >&2
    exit 1
  fi
  echo "self-test: $checks checks passed"
}

main() {
  case "${1:-}" in
    --self-test)
      self_test
      return 0
      ;;
    post | resolve) ;;
    *)
      usage
      exit 1
      ;;
  esac
  local sub="$1"
  shift
  if [[ $# -lt 1 ]]; then
    usage
    exit 1
  fi
  require_tools
  TMP_ROOT="$(mktemp -d)"
  github_target_resolve "$1" pull || exit 1
  shift
  if [[ "$sub" == "post" ]]; then
    cmd_post "$@"
  else
    cmd_resolve "$@"
  fi
}

main "$@"
