#!/usr/bin/env bash
# post-inline-review.sh — GitHub pull-request review publisher used by CR-track skills. It publishes one review round
# as ONE pull-request review: its inline comments carry the short human findings on the changed lines, and its body
# carries one short visible line for each finding that cannot anchor plus the hidden machine record of the round. It
# also resolves the inline thread of a finding that a later round found fixed.
#
# Usage:
#   post-inline-review.sh post    <NUMBER|URL> --commit-id <SHA> --meta-file <RECORD_FILE> <FINDINGS_FILE|->
#   post-inline-review.sh resolve <NUMBER|URL> --fixed <FINDING_ID>[,<FINDING_ID>...]
#   post-inline-review.sh --self-test
#
# post
#   FINDINGS_FILE  A JSON array, or `-` for stdin; `[]` for a round with no finding. One object per finding:
#                    finding_id            required, `r-` or `f-` and 16 hex digits (finding-records.md)
#                    severity              required, `Critical` or `Moderate`
#                    title                 required, 1 to 200 characters; never rendered, it names the finding in checks
#                    path, line            the file in the pull request and the (last) line; both left out for a
#                                          finding with no line (a functional gap)
#                    start_line            optional, the first line of a multi-line finding, below `line`
#                    side                  optional, `RIGHT` (default, the new file) or `LEFT` (the old file)
#                    problem               required, one or two plain sentences, 4000 characters at most
#                    impact                required except on a `dry` finding, one sentence
#                    faulty_example        optional, the faulty code, 5 lines at most
#                    faulty_example_lang   optional, the fence language of the faulty example (`php`, `bash`, ...)
#                    kind                  optional, `dry`: a DRY / reuse finding, always Moderate
#                    existing              required on a `dry` finding, `<path>:<line>` of the existing implementation
#                    author                optional `@login` of the pull request author; rendered as a mention line
#                    do_not_file_publicly  optional boolean; a security finding that must not reach a public repository
#                    fix, suggestion, rule, provenance, evidence, expected_behavior, test_hint, answered
#                                          optional text (4000 characters at most); accepted and never rendered: the
#                                          full finding lives in the hidden record
#                    raised_by             optional, `splinter`, `leonardo`, or `both`; never rendered
#                    level                 optional, `High`, `Medium`, or `Low`; a Moderate only; never rendered
#   --commit-id    The head SHA the round reviewed. The script refuses a head that is not the current one (exit 4).
#   --meta-file    The hidden machine record of the round: the text templates/pr-comment-output.md renders, which
#                  skills/_shared/check-cr-header.sh accepts. It lists every published finding in full and never a
#                  finding withheld on a public repository.
#
# Behavior of post:
#   1. Validate the findings and the record. A bad document, a record that check-cr-header.sh refuses, or a faulty
#      example of more than 5 lines publishes nothing (exit 1). A `suggestion`, `faulty_example`, `rule`, or text field
#      that holds an agent marker after normalisation is refused, and so is a rendered comment or body that does not hold
#      exactly its own markers.
#   2. Read the pull request head and refuse a `--commit-id` that is not the current head (exit 4).
#   3. Read the visibility of the repository with `gh repo view --json isPrivate`. Only the value `true` is private:
#      anything else, a failed check included, is public. On a public repository a finding marked
#      `do_not_file_publicly` is withheld: it is in no inline comment and in no visible line. A record that names a
#      withheld finding (its finding id or its title) is refused, nothing is posted (exit 1): hidden HTML is public too.
#   4. Read the changed lines of the pull request. A finding whose line (or any line of its range) is not in the diff,
#      or that has no line, is reported as `outside_diff`: it gets one short visible line in the review body, because
#      GitHub rejects the whole review for one comment off the diff.
#   4a. Read the paths of the repository at the reviewed head (`git/trees/<sha>?recursive=1`) and the changed files.
#      The visible text (every inline comment and every visible line, never the hidden record) must name none of the
#      package's own tooling; skills/_shared/check-visible-review-text.php decides, and a name that the reviewed
#      repository holds is the project's own. A refusal publishes nothing (exit 1), a failed lookup exits 3.
#   5. Read the review threads, with every comment of each thread. A finding that has an unresolved inline thread on the
#      same path with the same finding id, whose first comment is an agent comment of this actor, and whose line is the
#      line of the finding or is no longer set (an outdated thread), is reported as `already_open` and is not posted again.
#   6. POST one review, always, a round with no finding included (`event` COMMENT, `commit_id` the reviewed head,
#      `comments[]` one per remaining finding). The body is the visible lines, the record wrapped by
#      `cr_review_meta_wrap` (skills/_shared/cr-header.sh), the `<!-- cr-comment:lines=… -->` fingerprint that
#      skills/_shared/carry-operator-lines.php writes, and `<!-- cr-comment:actor=<login> -->`.
#   An inline comment has one of two shapes, fixed by templates/inline-review-comment.md:
#     - every finding except DRY: `<icon> **Problem:** …`, `**Impact:** …`, and the faulty example in a fenced block;
#     - a `dry` finding: `🟠 **Moderate · DRY:** This repeats the logic of `<existing>`. Please unify it and call the
#       existing implementation instead.`
#   Both end with the `@login` mention line when `author` is set and with two hidden agent markers:
#   `<!-- cr-inline:actor=<login> -->` and `<!-- cr-inline:finding=<finding id> -->`. The first is an agent marker in the
#   sense of `rules/code-review/general.md` *Authorship trust*: a reader never takes the comment as a trusted reviewer
#   request. The icon is 🔴 for Critical and 🟠 for Moderate.
#
# resolve
#   Resolves every unresolved inline thread that this actor opened for one of the given finding ids. A comment is the
#   agent's own only when the actor wrote it and it carries `<!-- cr-inline:actor=<actor> -->`. A thread that holds any
#   other comment (a reply by another author, a comment of the actor without the marker) is not resolved and is
#   reported as `has_reply`: its comments are reviewer comments. Every page of the thread comments is read. Give
#   only the ids that the round found fixed. A thread of a finding that is rejected, deferred, or still open stays unresolved.
#
# Output (stdout, one JSON document):
#   post     {"review_url","review_id","posted":[{finding_id,path,line}],"already_open":[...],"outside_diff":[...],
#             "withheld":[{finding_id}]}  Each `outside_diff` finding has its visible line in the review body. A withheld
#             finding stays out of every public surface.
#   resolve  {"resolved":[{finding_id,thread_id}],"has_reply":[{finding_id,thread_id}],"not_open":[finding ids with no
#            open thread]}
#   stderr   `action=created id=<review id> comments=<n> visible=<n>` for post.
#
# Audit trail:
#   Each write appends its own line to the run's `.audit` file at the moment it happens, through
#   `skills/_shared/audit-trail.sh record`: an `external-write` line with the review URL (or the resolved thread), or a
#   `note` line for a failed attempt, both with the real exit status. The ledger comes from AUDIT_TRAIL_FILE and
#   AUDIT_TRAIL_ROLE. A missing ledger prints a hint on stderr and never changes the output or the exit code.
#
# Exit codes:
#   1  usage / argument / findings-document / record error
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

usage() {
  cat >&2 <<'EOF'
Usage: post-inline-review.sh post    <NUMBER|URL> --commit-id <SHA> --meta-file <RECORD_FILE> <FINDINGS_FILE|->
       post-inline-review.sh resolve <NUMBER|URL> --fixed <FINDING_ID>[,<FINDING_ID>...]
       post-inline-review.sh --self-test

  NUMBER|URL    bare pull request number (resolved against the git remote) or a github.com /pull/<N> URL
  FINDINGS_FILE JSON array of findings (`[]` for a round with no finding), or `-` for stdin
  --commit-id   the head SHA the round reviewed; a head that moved is refused
  --meta-file   the hidden machine record of the round (templates/pr-comment-output.md)
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

IFS= read -r -d '' JQ_NORMALIZE <<'JQ' || true
def req($c; $m): if $c then . else error($m) end;
def isint: type == "number" and . == floor and . >= 1;
def isstr($max): type == "string" and length >= 1 and length <= $max;
def printable: explode | map(select((. >= 32 and . != 127) or . == 10 or . == 9)) | implode;
def prose: printable | gsub("<!--"; "&lt;!--") | gsub("-->"; "--&gt;");
def optstr: . == null or (type == "string" and length >= 1 and length <= 4000);
def marker_free: . == null or ((type == "string") and ((test("<!--\\s*[a-z][a-z0-9-]*:(actor|finding|lines)=")) | not));
def codespan: gsub("`"; "");
def relpath: type == "string" and isstr(500) and (startswith("/") | not) and (explode | all(. >= 32 and . != 127)) and ((split("/") | index("..")) == null);
req(type == "array"; "the findings document must be a JSON array")
| req(length <= 100; "at most 100 findings per review")
| map(
    req(type == "object"; "every finding must be an object")
    | req(((keys - ["finding_id", "severity", "title", "path", "line", "start_line", "side", "problem", "fix", "suggestion", "do_not_file_publicly", "rule", "provenance", "impact", "evidence", "faulty_example", "faulty_example_lang", "expected_behavior", "test_hint", "raised_by", "answered", "author", "level", "kind", "existing"]) | length) == 0; "a finding holds an unknown key")
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
    | req(.fix | optstr; "fix must be 1 to 4000 characters when set")
    | req(.suggestion == null or ((.suggestion | type) == "string" and (.suggestion | length) <= 4000); "suggestion must be a string of 4000 characters at most")
    | req(.suggestion == null or (.side // "RIGHT") == "RIGHT"; "a suggestion needs side RIGHT")
    | req(.do_not_file_publicly == null or (.do_not_file_publicly | type) == "boolean"; "do_not_file_publicly must be a boolean")
    | req((.rule | optstr) and (.provenance | optstr) and (.impact | optstr) and (.evidence | optstr) and (.faulty_example | optstr) and (.expected_behavior | optstr) and (.test_hint | optstr) and (.answered | optstr); "rule, provenance, impact, evidence, faulty_example, expected_behavior, test_hint, and answered must be 1 to 4000 characters when set")
    | req(.faulty_example == null or ((.faulty_example | rtrimstr("\n") | split("\n") | length) <= 5); "faulty_example must be 5 lines at most")
    | req(.faulty_example_lang == null or ((.faulty_example_lang | type) == "string" and (.faulty_example_lang | test("^[A-Za-z0-9_+.-]{1,20}$"))); "faulty_example_lang must be a fence language of 1 to 20 characters")
    | req((.rule | if . == null then null else codespan | printable end) | marker_free; "rule must not contain an agent marker")
    | req(.raised_by == null or .raised_by == "splinter" or .raised_by == "leonardo" or .raised_by == "both"; "raised_by must be splinter, leonardo, or both")
    | req(.level == null or (.severity == "Moderate" and (.level == "High" or .level == "Medium" or .level == "Low")); "level must be High, Medium, or Low and belongs to a Moderate")
    | req(.author == null or ((.author | type) == "string" and (.author | test("^@[A-Za-z0-9][A-Za-z0-9-]{0,38}$"))); "author must be an @login")
    | {
        finding_id,
        severity,
        title: (.title | prose),
        title_key: (.title | printable | ascii_downcase | gsub("\\s+"; " ") | ltrimstr(" ") | rtrimstr(" ")),
        path,
        line,
        start_line,
        side: (.side // "RIGHT"),
        kind,
        existing: (.existing | if . == null then null else codespan | prose end),
        problem: (.problem | prose),
        impact: (.impact | if . == null then null else prose end),
        faulty_example: (.faulty_example | if . == null then null else printable end),
        faulty_example_lang,
        suggestion: (.suggestion | if . == null then null else printable end),
        withhold: (.do_not_file_publicly == true),
        author
      }
    | req(.suggestion | marker_free; "suggestion must not contain an agent marker")
    | req(.faulty_example | marker_free; "faulty_example must not contain an agent marker")
    | req([.problem, .impact, .existing] | all(marker_free); "a text field must not contain an agent marker")
  )
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

# Every changed line of one pull-request file, as "<SIDE>\t<line>\t<path>" strings.
IFS= read -r -d '' JQ_CLASSIFY <<'JQ' || true
def file_anchors:
  . as $f
  | ($f.patch // "") | split("\n")
  | reduce .[] as $l ({o: 0, n: 0, out: []};
      if ($l | startswith("@@")) then
        ($l | capture("^@@ -(?<o>[0-9]+)(,[0-9]+)? \\+(?<n>[0-9]+)(,[0-9]+)? @@")) as $m
        | .o = ($m.o | tonumber) | .n = ($m.n | tonumber)
      elif ($l | startswith("+")) then .out += ["RIGHT\t\(.n)\t\($f.filename)"] | .n += 1
      elif ($l | startswith("-")) then .out += ["LEFT\t\(.o)\t\($f.previous_filename // $f.filename)"] | .o += 1
      elif ($l | startswith("\\")) or $l == "" then .
      else .out += ["RIGHT\t\(.n)\t\($f.filename)", "LEFT\t\(.o)\t\($f.previous_filename // $f.filename)"] | .o += 1 | .n += 1
      end)
  | .out;
([$files[0][] | file_anchors] | add // [] | map({key: ., value: true}) | from_entries) as $set
| def covered:
    . as $f
    | if $f.line == null then false
      else [range(($f.start_line // $f.line); $f.line + 1)] | all(. as $i | ($set["\($f.side)\t\($i)\t\($f.path)"] // false))
      end;
  def open_here($f):
    any($threads[0][];
      (.isResolved | not)
      and .path == $f.path
      and (.line == null or .line == $f.line)
      and (.comments.nodes[0] | agent_comment($actor))
      and ((.comments.nodes[0].body // "") | contains("<!-- cr-inline:finding=\($f.finding_id) -->")));
  map(
    if .withhold and ($private | not) then {class: "withheld", f: .}
    elif (covered | not) then {class: "outside_diff", f: .}
    elif open_here(.) then {class: "already_open", f: .}
    else {class: "post", f: .}
    end)
JQ

# The inline comments and the draft body of the review. The draft body ends with the actor marker; the fingerprint
# marker is added by carry-operator-lines.php before JQ_REVIEW builds the request.
IFS= read -r -d '' JQ_PAYLOAD <<'JQ' || true
def fence($s): ([($s | [match("`+"; "g").length] | max) // 0, 2] | max + 1) as $n | ("`" * $n);
def icon: if . == "Critical" then "🔴" else "🟠" end;
def oneline: gsub("\\s+"; " ") | ltrimstr(" ") | rtrimstr(" ");
def dry_line: "🟠 **Moderate · DRY:** This repeats the logic of `\(.existing)`. Please unify it and call the existing implementation instead.";
def example:
  if .faulty_example == null then empty
  else (.faulty_example | rtrimstr("\n")) as $x | "\(fence($x))\(.faulty_example_lang // "")\n\($x)\n\(fence($x))" end;
def markers: "<!-- cr-inline:actor=\($actor) -->\n<!-- cr-inline:finding=\(.finding_id) -->";
def render:
  ((if .kind == "dry" then [dry_line]
    else ["\(.severity | icon) **Problem:** \(.problem)", "**Impact:** \(.impact)", example] end)
   + [(.author // empty), markers])
  | join("\n\n");
def where: if .line == null then "(no line)" else "(`\(.path | gsub("`"; "")):\(.line)`, outside the diff)" end;
def visible:
  (if .kind == "dry" then dry_line else "\(.severity | icon) **Problem:** \(.problem | oneline) **Impact:** \(.impact | oneline)" end)
  + " " + where + (if .author == null then "" else " " + .author end)
  + "\n<!-- cr-inline:finding=\(.finding_id) -->";
[.[] | select(.class == "post") | .f] as $posted
| [.[] | select(.class == "outside_diff") | .f] as $outside
| {
    visible: ($outside | length),
    comments: [$posted[] | {path, line, side, body: render} + (if .start_line == null then {} else {start_line, start_side: .side} end)],
    body: (([$outside[] | visible] + [($meta | rtrimstr("\n")), "<!-- cr-comment:actor=\($actor) -->"]) | join("\n\n")),
    visible_text: ([$posted[] | render] + [$outside[] | visible] | join("\n\n"))
  }
| if [.comments[].body | [match("<!--\\s*cr-inline:"; "g")] | length] | all(. == 2)
  then . else error("a rendered comment does not hold exactly its own cr-inline markers") end
JQ

# The request: the final body must hold exactly one hidden record, one fingerprint marker, one actor marker, and one
# finding marker for each visible line, and it must fit the 65536 characters GitHub accepts.
IFS= read -r -d '' JQ_REVIEW <<'JQ' || true
def count($re): [match($re; "g")] | length;
{commit_id: $commit, event: "COMMENT", body: $body, comments: .comments} as $r
| if ($body | count("<!--\\s*cr-inline:")) == .visible and ($body | count("<!--\\s*cr-inline:finding=")) == .visible
     and ($body | count("<!--\\s*cr-review-meta")) == 1 and ($body | count("<!--\\s*cr-comment:lines=")) == 1
     and ($body | count("<!--\\s*cr-comment:actor=")) == 1 and ($body | count("<!--\\s*cr-comment:")) == 2
  then . else error("the review body does not hold exactly its own markers") end
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

cmd_post() {
  local commit="" findings_src="" meta_file="" arg
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
      *)
        [[ -z "$findings_src" ]] || die 1 "unexpected argument: $arg"
        findings_src="$arg"
        shift
        ;;
    esac
  done
  [[ -n "$commit" && -n "$findings_src" && -n "$meta_file" ]] || { usage; exit 1; }
  [[ "$commit" =~ ^[0-9a-f]{40}$ ]] || die 1 "--commit-id must be a full lowercase 40-digit SHA"
  [[ -r "$meta_file" && -s "$meta_file" ]] || die 1 "cannot read the review record: $meta_file"
  cat "$meta_file" >"$TMP_ROOT/meta.md"
  "$CHECK_HEADER" --file "$TMP_ROOT/meta.md" >"$TMP_ROOT/meta-check.json" 2>/dev/null \
    || die 1 "the review record was refused by check-cr-header.sh, nothing was published: $(jq -r '.problems // [] | join("; ")' "$TMP_ROOT/meta-check.json" 2>/dev/null | head -c 300)"

  if [[ "$findings_src" == "-" ]]; then
    cat >"$TMP_ROOT/findings.raw"
  else
    [[ -r "$findings_src" ]] || die 1 "cannot read the findings file: $findings_src"
    cat "$findings_src" >"$TMP_ROOT/findings.raw"
  fi
  jq "$JQ_NORMALIZE" "$TMP_ROOT/findings.raw" >"$TMP_ROOT/findings.json" 2>"$TMP_ROOT/normalize.err" \
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
  fetch_threads "$TMP_ROOT/threads.json"

  jq --slurpfile files "$TMP_ROOT/files.json" --slurpfile threads "$TMP_ROOT/threads.json" \
    --arg actor "$ACTOR" --argjson private "$private" "$JQ_AGENT"$'\n'"$JQ_CLASSIFY" "$TMP_ROOT/findings.json" >"$TMP_ROOT/classified.json"

  # Hidden HTML is public: a record that names a withheld finding by its id or its title is refused.
  local leaked
  leaked="$(jq -r --rawfile meta "$TMP_ROOT/meta.md" '
    ($meta | ascii_downcase | gsub("\\s+"; " ")) as $m
    | [.[] | select(.class == "withheld") | .f
       | .finding_id as $id | .title_key as $t
       | select(($m | contains($id)) or ($t != "" and ($m | contains($t)))) | $id]
    | join(",")' "$TMP_ROOT/classified.json")"
  [[ -z "$leaked" ]] || die 1 "the review record names the withheld finding(s) ${leaked}; a finding marked do_not_file_publicly stays out of the record on a public repository, nothing was published"

  cr_review_meta_wrap <"$TMP_ROOT/meta.md" >"$TMP_ROOT/meta.wrapped"
  jq --arg actor "$ACTOR" --rawfile meta "$TMP_ROOT/meta.wrapped" "$JQ_PAYLOAD" "$TMP_ROOT/classified.json" >"$TMP_ROOT/draft.json" 2>"$TMP_ROOT/payload.err" \
    || die 1 "the rendered review was refused, nothing was published: $(sed -E 's/^jq: error \(at [^)]*\): //' "$TMP_ROOT/payload.err" | head -n 1)"
  jq -j '.visible_text' "$TMP_ROOT/draft.json" >"$TMP_ROOT/visible.md"
  local visible_rc=0
  php "$CHECK_VISIBLE" --text "$TMP_ROOT/visible.md" --repo-paths "$TMP_ROOT/repo-paths.txt" >"$TMP_ROOT/visible-check.json" 2>"$TMP_ROOT/visible.err" || visible_rc=$?
  if [[ "$visible_rc" -eq 3 ]]; then
    die 1 "the visible review text names the package tooling $(jq -r '.names | join(", ")' "$TMP_ROOT/visible-check.json" | head -c 300); describe the step in plain words (rules/code-review/general.md *Visible review text names none of the package's own tooling*), nothing was published"
  fi
  [[ "$visible_rc" -eq 0 ]] || die 1 "the check of the visible review text did not pass, nothing was published: $(head -c 300 "$TMP_ROOT/visible.err")"
  jq -j '.body' "$TMP_ROOT/draft.json" >"$TMP_ROOT/body.draft"
  php "$CARRY_LINES" markdown cr-comment "$TMP_ROOT/body.draft" >"$TMP_ROOT/body.md" 2>"$TMP_ROOT/carry.err" \
    || die 1 "the line fingerprint of the review body failed, nothing was published: $(head -c 300 "$TMP_ROOT/carry.err")"
  jq --arg commit "$commit" --rawfile body "$TMP_ROOT/body.md" "$JQ_REVIEW" "$TMP_ROOT/draft.json" >"$TMP_ROOT/payload.json" 2>"$TMP_ROOT/payload.err" \
    || die 1 "the rendered review was refused, nothing was published: $(sed -E 's/^jq: error \(at [^)]*\): //' "$TMP_ROOT/payload.err" | head -n 1)"

  local to_post visible
  to_post="$(jq '.comments | length' "$TMP_ROOT/payload.json")"
  visible="$(jq '.visible' "$TMP_ROOT/draft.json")"

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
  bash "$AUDIT_TRAIL" record --target "$review_url" --status 0 --detail "created review with ${to_post} inline comments and ${visible} visible lines" || true
  echo "action=created id=${review_id} comments=${to_post} visible=${visible}" >&2

  jq --arg url "$review_url" --arg id "$review_id" '
    def pick($c): [.[] | select(.class == $c) | .f | {finding_id, path, line}];
    {
      review_url: $url,
      review_id: $id,
      posted: pick("post"),
      already_open: pick("already_open"),
      outside_diff: pick("outside_diff"),
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
    if [[ "$*" == *"cursor="* ]]; then cat "$GH_THREADS_2"; else cat "$GH_THREADS_1"; fi
    ;;
  "api repos/o/r/pulls/7/files"*) cat "$GH_FILES" ;;
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
**Reviewed revision:** aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
**Last updated:** 2026-11-15T10:00:00Z
**Not verified:** a value with --> and --!> and &lt; and <!-- inside

## Findings

### 🔴 Critical 1. Unescaped output

- **Location:** `src/A.php:11`
- **Faulty Example:** `if ($a < $b) { echo $v; }`
MD
  printf '**Status:** clean\n' >"$tmp/meta-bad.md"

  # run_post <label> <findings json> [env assignments...]: runs `post` against the stub, sets $out, $rc.
  run_post() {
    local findings="$1"
    shift
    : >"$tmp/review.calls"
    : >"$tmp/ledger.audit"
    rm -f "$tmp/review.json"
    set +e
    out="$(env "$@" PATH="$bin:$PATH" GH_ACTOR=bot GH_PRIVATE="${GH_PRIVATE_VALUE:-true}" GH_FILES="$tmp/files.json" GH_TREE="${GH_TREE_FILE:-$tmp/tree.json}" \
      GH_THREADS_1="$tmp/threads.1" GH_THREADS_2="$tmp/threads.2" GH_HEAD="$tmp/head" GH_REVIEW="$tmp/review.json" \
      GH_REVIEW_CALLS="$tmp/review.calls" GH_RESOLVES="$tmp/resolves" GH_NODE_PAGE="$tmp/node.page" AUDIT_TRAIL_FILE="$tmp/ledger.audit" AUDIT_TRAIL_ROLE=leonardo \
      bash "$script" post https://github.com/o/r/pull/7 --commit-id "${COMMIT_ID:-$sha}" --meta-file "${META_FILE:-$tmp/meta.md}" - <<<"$findings" 2>"$tmp/stderr")"
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

  local f_in f_out
  f_in="{\"finding_id\":\"$id1\",\"severity\":\"Critical\",\"title\":\"Unescaped output\",\"path\":\"src/A.php\",\"line\":11,\"problem\":\"The value reaches the page unescaped.\",\"impact\":\"An attacker runs a script in the page.\",\"faulty_example\":\"echo \$v;\",\"faulty_example_lang\":\"php\",\"fix\":\"Escape it.\",\"suggestion\":\"echo e(\$v);\",\"rule\":\"@rules/security/backend.md#xss\",\"provenance\":\"regression — introduced in this revision\",\"evidence\":\"logic-trace — line 11\",\"expected_behavior\":\"The value is escaped.\",\"test_hint\":\"Feature test.\",\"raised_by\":\"splinter\",\"answered\":\"https://github.com/o/r/pull/7#issuecomment-1\"}"
  f_out="{\"finding_id\":\"$id2\",\"severity\":\"Moderate\",\"title\":\"Duplicate query\",\"path\":\"src/A.php\",\"line\":100,\"problem\":\"Outside the diff.\",\"impact\":\"Two queries per page.\"}"
  local shape_a
  shape_a="🔴 **Problem:** The value reaches the page unescaped.

**Impact:** An attacker runs a script in the page.

${bt}php
echo \$v;
${bt}

<!-- cr-inline:actor=bot -->
<!-- cr-inline:finding=$id1 -->"
  body_has() { jq -e --arg bt "$bt" "$1" "$tmp/review.json" >/dev/null 2>&1; }
  record_of() { jq -j '.body' "$tmp/review.json" | cr_review_meta_unwrap review; }

  # 1. a finding on a changed line is one short inline comment of one review, and the body holds the hidden record
  run_post "[$f_in]"
  check 'post exits 0' "$([[ $rc -eq 0 ]] && echo yes || echo no)"
  check 'one review request' "$([[ "$(wc -l <"$tmp/review.calls" | tr -d ' ')" == "1" ]] && echo yes || echo no)"
  check 'event is COMMENT' "$(yes_if review_has '.event == "COMMENT"')"
  check 'commit_id is the reviewed head' "$(yes_if review_has ".commit_id == \"$sha\"")"
  check 'comment anchors path, line, and side' "$(yes_if review_has '.comments[0] | .path == "src/A.php" and .line == 11 and .side == "RIGHT"')"
  check 'the inline comment is the Problem, Impact, and faulty example shape, byte for byte' \
    "$(yes_if jq -e --arg want "$shape_a" '.comments[0].body == $want' "$tmp/review.json")"
  check 'the inline comment renders no Rule, Provenance, Raised by, Level, Evidence, Expected behavior, Test hint, Suggested fix, or suggestion block' \
    "$(yes_if review_has '.comments[0].body | (test("\\*\\*(Rule|Provenance|Raised by|Level|Evidence|Expected behavior|Test hint|Suggested fix|Answered|Author):") or contains("suggestion") or contains("Escape it.")) | not')"
  check 'comment carries both agent markers' "$(yes_if review_has ".comments[0].body | contains(\"<!-- cr-inline:actor=bot -->\") and contains(\"<!-- cr-inline:finding=$id1 -->\")")"
  check 'every inline comment holds exactly two cr-inline markers and the review body none' "$(yes_if review_has '(.comments | all(.body | [match("<!--\\s*cr-inline:"; "g")] | length == 2)) and ([.body | match("<!--\\s*cr-inline:"; "g")] | length == 0)')"
  check 'the review body holds one hidden record, one fingerprint marker, and the cr-comment actor marker' \
    "$(yes_if review_has '([.body | match("(?m)^<!-- cr-review-meta$"; "g")] | length == 1) and (.body | test("(?m)^<!-- cr-comment:lines=[0-9a-f,]* -->\n<!-- cr-comment:actor=bot -->$"))')"
  check 'the hidden record comes back byte for byte, --> and --!> and &lt; and <!-- included' \
    "$([[ "$(record_of)" == "$(cat "$tmp/meta.md")"$'\n\n<!-- cr-comment:lines='*$'\n<!-- cr-comment:actor=bot -->' ]] && echo yes || echo no)"
  check 'the hidden block holds no raw --> of the record' "$(yes_if review_has '[.body | match("-->"; "g")] | length == 3')"
  check 'output lists the posted finding' "$(yes_if out_has ".posted[0].finding_id == \"$id1\" and .review_url != null")"
  check 'the review is one external-write audit line' "$([[ "$(grep -c '|external-write|https://github.com/o/r/pull/7#pullrequestreview-99|' "$tmp/ledger.audit")" == "1" ]] && echo yes || echo no)"

  # 2. a stale commit id is refused and nothing is posted
  COMMIT_ID="$other" run_post "[$f_in]"
  check 'a stale commit id exits 4' "$([[ $rc -eq 4 ]] && echo yes || echo no)"
  check 'a stale commit id posts nothing' "$([[ ! -s "$tmp/review.calls" ]] && echo yes || echo no)"

  # 3. an outside-diff finding is one short visible line of the review body; the rest of the round is still inline
  run_post "[$f_in,$f_out]"
  check 'outside-diff finding is reported' "$(yes_if out_has ".outside_diff[0].finding_id == \"$id2\"")"
  check 'outside-diff finding is not an inline comment' "$(yes_if review_has '.comments | length == 1')"
  check 'outside-diff finding is one visible line of the review body' \
    "$(yes_if review_has ".body | startswith(\"🟠 **Problem:** Outside the diff. **Impact:** Two queries per page. (\" + \$bt[0:1] + \"src/A.php:100\" + \$bt[0:1] + \", outside the diff)\n<!-- cr-inline:finding=$id2 -->\n\n<!-- cr-review-meta\n\")")"

  # 4. a round with no finding, or with no anchored finding, still posts exactly one review
  run_post '[]'
  check 'a round with no finding posts one review with no inline comment' "$([[ $rc -eq 0 && "$(wc -l <"$tmp/review.calls" | tr -d ' ')" == "1" ]] && yes_if review_has '.comments == [] and (.body | startswith("<!-- cr-review-meta\n"))' || echo no)"
  check 'a round with no finding carries only hidden text in the review body' \
    "$(yes_if review_has '.body | split("\n") | map(select(. != "")) | (.[0] == "<!-- cr-review-meta") and (.[-1] == "<!-- cr-comment:actor=bot -->")')"
  run_post "[$f_out]"
  check 'only outside-diff findings post one review with no inline comment' "$([[ $rc -eq 0 && "$(wc -l <"$tmp/review.calls" | tr -d ' ')" == "1" ]] && yes_if out_has '.review_url != null and (.posted | length) == 0' || echo no)"
  local f_gap_noline
  f_gap_noline="{\"finding_id\":\"$id4\",\"severity\":\"Critical\",\"title\":\"Criterion 2 unmet\",\"problem\":\"The export ignores the selection.\",\"impact\":\"Every order is exported.\"}"
  run_post "[$f_gap_noline]"
  check 'a finding with no line is one visible line marked (no line)' \
    "$(yes_if review_has ".body | startswith(\"🔴 **Problem:** The export ignores the selection. **Impact:** Every order is exported. (no line)\n<!-- cr-inline:finding=$id4 -->\")")"

  # 5. multi-line finding, removed line on the LEFT side, a range that crosses a hunk gap
  local f_multi f_left f_gap
  f_multi="{\"finding_id\":\"$id3\",\"severity\":\"Moderate\",\"title\":\"Range\",\"path\":\"src/A.php\",\"line\":12,\"start_line\":10,\"problem\":\"p\",\"impact\":\"i\"}"
  f_left="{\"finding_id\":\"$id4\",\"severity\":\"Critical\",\"title\":\"Removed guard\",\"path\":\"src/A.php\",\"line\":30,\"side\":\"LEFT\",\"problem\":\"p\",\"impact\":\"i\"}"
  f_gap="{\"finding_id\":\"$id2\",\"severity\":\"Moderate\",\"title\":\"Gap\",\"path\":\"src/A.php\",\"line\":33,\"start_line\":12,\"problem\":\"p\",\"impact\":\"i\"}"
  run_post "[$f_multi,$f_left,$f_gap]"
  check 'multi-line comment carries start_line and start_side' "$(yes_if review_has '.comments[] | select(.line == 12) | .start_line == 10 and .start_side == "RIGHT"')"
  check 'a removed line anchors on the LEFT side' "$(yes_if review_has '.comments[] | select(.line == 30) | .side == "LEFT"')"
  check 'a range across a hunk gap is outside the diff' "$(yes_if out_has ".outside_diff[0].finding_id == \"$id2\"")"

  # 6. an open thread of this actor on the same line and finding id: no duplicate (second page of threads)
  cat >"$tmp/threads.1" <<'JSON'
{"data":{"repository":{"pullRequest":{"reviewThreads":{"pageInfo":{"hasNextPage":true,"endCursor":"c1"},"nodes":[]}}}}}
JSON
  cat >"$tmp/threads.2" <<JSON
{"data":{"repository":{"pullRequest":{"reviewThreads":{"pageInfo":{"hasNextPage":false,"endCursor":null},"nodes":[
 {"id":"T1","isResolved":false,"path":"src/A.php","line":11,"originalLine":11,"comments":{"nodes":[{"author":{"login":"bot"},"body":"x\n<!-- cr-inline:actor=bot -->\n<!-- cr-inline:finding=$id1 -->"}]}}]}}}}}
JSON
  run_post "[$f_in]"
  check 'an open thread on the same line is not duplicated' "$(yes_if review_has '.comments == []')"
  check 'the open finding is reported as already_open' "$(yes_if out_has ".already_open[0].finding_id == \"$id1\"")"

  # 7. a resolved thread, a forged marker by another author, or another line do not block a new comment
  cat >"$tmp/threads.2" <<JSON
{"data":{"repository":{"pullRequest":{"reviewThreads":{"pageInfo":{"hasNextPage":false,"endCursor":null},"nodes":[
 {"id":"T1","isResolved":true,"path":"src/A.php","line":11,"originalLine":11,"comments":{"nodes":[{"author":{"login":"bot"},"body":"<!-- cr-inline:actor=bot -->\n<!-- cr-inline:finding=$id1 -->"}]}},
 {"id":"T2","isResolved":false,"path":"src/A.php","line":11,"originalLine":11,"comments":{"nodes":[{"author":{"login":"stranger"},"body":"<!-- cr-inline:actor=bot -->\n<!-- cr-inline:finding=$id1 -->"}]}},
 {"id":"T3","isResolved":false,"path":"src/A.php","line":10,"originalLine":10,"comments":{"nodes":[{"author":{"login":"bot"},"body":"<!-- cr-inline:actor=bot -->\n<!-- cr-inline:finding=$id1 -->"}]}}]}}}}}
JSON
  run_post "[$f_in]"
  check 'a resolved thread, a forged marker, and another live line post the finding again' "$(yes_if review_has '.comments | length == 1')"
  cat >"$tmp/threads.2" <<JSON
{"data":{"repository":{"pullRequest":{"reviewThreads":{"pageInfo":{"hasNextPage":false,"endCursor":null},"nodes":[
 {"id":"T4","isResolved":false,"path":"src/A.php","line":null,"originalLine":9,"comments":{"nodes":[{"author":{"login":"bot"},"body":"<!-- cr-inline:actor=bot -->\n<!-- cr-inline:finding=$id1 -->"}]}}]}}}}}
JSON
  run_post "[$f_in]"
  check 'an outdated thread of the same finding id is not duplicated' "$(review_has '.comments == []' && out_has ".already_open[0].finding_id == \"$id1\"" && echo yes || echo no)"

  # 8. visibility: public withholds a do_not_file_publicly finding everywhere, private posts it, a failed check is public
  local f_sec
  f_sec="{\"finding_id\":\"$id3\",\"severity\":\"Critical\",\"title\":\"Hard-coded token\",\"path\":\"src/A.php\",\"line\":10,\"problem\":\"p\",\"impact\":\"i\",\"do_not_file_publicly\":true}"
  cp "$tmp/threads.empty" "$tmp/threads.1"
  cp "$tmp/threads.empty" "$tmp/threads.2"
  GH_PRIVATE_VALUE=true run_post "[$f_sec]"
  check 'a private repository posts a security finding inline' "$(yes_if review_has ".comments[0].body | contains(\"$id3\")")"
  GH_PRIVATE_VALUE=false run_post "[$f_sec,$f_in]"
  check 'a public repository withholds the marked finding' "$(yes_if out_has ".withheld[0].finding_id == \"$id3\" and (.posted | length) == 1")"
  check 'a withheld finding is in no inline comment' "$(yes_if review_has ".comments | length == 1 and (.[0].body | contains(\"$id3\") | not)")"
  check 'a withheld finding is in no visible line and not in the hidden record' "$(yes_if review_has ".body | (contains(\"$id3\") or contains(\"Hard-coded token\")) | not")"
  run_post "[$f_sec]" GH_VISIBILITY_FAILS=1
  check 'a failed visibility check counts as public' "$([[ $rc -eq 0 ]] && review_has ".comments == [] and (.body | contains(\"$id3\") | not)" && out_has ".withheld[0].finding_id == \"$id3\"" && echo yes || echo no)"
  { cat "$tmp/meta.md"; printf '\n### 🔴 Critical 2. Hard-coded token\n'; } >"$tmp/meta-leak.md"
  META_FILE="$tmp/meta-leak.md" GH_PRIVATE_VALUE=false run_post "[$f_sec,$f_in]"
  check 'a record that names a withheld finding by its title is refused and nothing is posted' "$([[ $rc -eq 1 && ! -s "$tmp/review.calls" ]] && grep -q "names the withheld finding(s) $id3" "$tmp/stderr" && echo yes || echo no)"
  { cat "$tmp/meta.md"; printf '\n<!-- finding %s -->\n' "$id3"; } >"$tmp/meta-leak.md"
  META_FILE="$tmp/meta-leak.md" GH_PRIVATE_VALUE=false run_post "[$f_sec,$f_in]"
  check 'a record that names a withheld finding by its id is refused and nothing is posted' "$([[ $rc -eq 1 && ! -s "$tmp/review.calls" ]] && echo yes || echo no)"
  META_FILE="$tmp/meta-leak.md" GH_PRIVATE_VALUE=true run_post "[$f_sec,$f_in]"
  check 'a private repository publishes the record that names the security finding' "$([[ $rc -eq 0 ]] && echo yes || echo no)"

  # 9. a failed write exits 3 and leaves a note line, never an external-write line
  run_post "[$f_in]" GH_POST_FAILS=1
  check 'a failed review request exits 3' "$([[ $rc -eq 3 ]] && echo yes || echo no)"
  check 'a failed review request leaves a note line' "$([[ "$(grep -c '|note|' "$tmp/ledger.audit")" == "1" && "$(grep -c '|external-write|' "$tmp/ledger.audit")" == "0" ]] && echo yes || echo no)"

  # 10. an invalid document or record publishes nothing
  META_FILE="$tmp/meta-bad.md" run_post "[$f_in]"
  check 'a record that check-cr-header.sh refuses publishes nothing' "$([[ $rc -eq 1 && ! -s "$tmp/review.calls" ]] && grep -q 'refused by check-cr-header.sh' "$tmp/stderr" && echo yes || echo no)"
  META_FILE="$tmp/does-not-exist.md" run_post "[$f_in]"
  check 'a missing record publishes nothing' "$([[ $rc -eq 1 && ! -s "$tmp/review.calls" ]] && echo yes || echo no)"
  local bad base="\"severity\":\"Critical\",\"title\":\"t\",\"path\":\"src/A.php\",\"line\":11,\"problem\":\"p\",\"impact\":\"i\""
  for bad in \
    '{"a":1}' \
    "[{\"finding_id\":\"nope\",$base}]" \
    "[{\"finding_id\":\"$id1\",\"severity\":\"Minor\",\"title\":\"t\",\"path\":\"src/A.php\",\"line\":11,\"problem\":\"p\",\"impact\":\"i\"}]" \
    "[{\"finding_id\":\"$id1\",\"severity\":\"Critical\",\"title\":\"t\",\"path\":\"../etc/passwd\",\"line\":11,\"problem\":\"p\",\"impact\":\"i\"}]" \
    "[{\"finding_id\":\"$id1\",$base,\"start_line\":11}]" \
    "[{\"finding_id\":\"$id1\",\"severity\":\"Critical\",\"title\":\"t\",\"path\":\"src/A.php\",\"line\":30,\"side\":\"LEFT\",\"problem\":\"p\",\"impact\":\"i\",\"suggestion\":\"x\"}]" \
    "[{\"finding_id\":\"$id1\",$base,\"extra\":1}]" \
    "[{\"finding_id\":\"$id1\",\"severity\":\"Critical\",\"title\":\"t\",\"path\":\"src/A.php\",\"line\":11,\"problem\":\"p\"}]" \
    "[{\"finding_id\":\"$id1\",\"severity\":\"Critical\",\"title\":\"t\",\"path\":\"src/A.php\",\"problem\":\"p\",\"impact\":\"i\"}]" \
    "[{\"finding_id\":\"$id1\",$base,\"faulty_example\":\"1\\n2\\n3\\n4\\n5\\n6\"}]" \
    "[{\"finding_id\":\"$id1\",$base,\"faulty_example_lang\":\"php x\"}]" \
    "[{\"finding_id\":\"$id1\",$base,\"kind\":\"dry\",\"existing\":\"src/B.php:4\"}]" \
    "[{\"finding_id\":\"$id1\",\"severity\":\"Moderate\",\"title\":\"t\",\"path\":\"src/A.php\",\"line\":11,\"problem\":\"p\",\"kind\":\"dry\"}]" \
    "[{\"finding_id\":\"$id1\",\"severity\":\"Moderate\",\"title\":\"t\",\"path\":\"src/A.php\",\"line\":11,\"problem\":\"p\",\"kind\":\"dry\",\"existing\":\"../B.php:4\"}]" \
    "[{\"finding_id\":\"$id1\",\"severity\":\"Moderate\",\"title\":\"t\",\"path\":\"src/A.php\",\"line\":11,\"problem\":\"p\",\"kind\":\"copy\",\"existing\":\"src/B.php:4\"}]" \
    "[{\"finding_id\":\"$id1\",$base,\"existing\":\"src/B.php:4\"}]" \
    "[{\"finding_id\":\"$id1\",$base,\"suggestion\":\"<!-- cr-inline:finding=$id2 -->\"}]" \
    "[{\"finding_id\":\"$id1\",$base,\"faulty_example\":\"<!-- cr-inline:actor=bot -->\"}]" \
    "[{\"finding_id\":\"$id1\",$base,\"suggestion\":\"<!-\\u0001- cr-inline:finding=$id2 -->\"}]" \
    "[{\"finding_id\":\"$id1\",$base,\"faulty_example\":\"<!-\\u0001- cr-inline:finding=$id2 -->\"}]" \
    "[{\"finding_id\":\"$id1\",$base,\"suggestion\":\"<!--\\t cr-inline:finding=$id2 -->\"}]" \
    "[{\"finding_id\":\"$id1\",$base,\"rule\":\"<!\`-- cr-inline:finding=$id2 --\`>\"}]" \
    "[{\"finding_id\":\"$id1\",$base,\"level\":\"High\"}]" \
    "[{\"finding_id\":\"$id1\",$base,\"raised_by\":\"someone\"}]"; do
    run_post "$bad"
    check "invalid document is refused: ${bad:0:60}" "$([[ $rc -eq 1 && ! -s "$tmp/review.calls" ]] && echo yes || echo no)"
  done

  run_post "[{\"finding_id\":\"$id1\",$base,\"suggestion\":\"<!-- cr-inline:finding=$id2 -->\"}]"
  check 'a suggestion with a marker is refused with its own message' "$([[ $rc -eq 1 ]] && grep -q 'suggestion must not contain an agent marker' "$tmp/stderr" && echo yes || echo no)"
  run_post "[{\"finding_id\":\"$id1\",$base,\"faulty_example\":\"<!-\\u0001- cr-inline:finding=$id2 -->\"}]"
  check 'a faulty_example marker rebuilt after a control character is refused with its own message' "$([[ $rc -eq 1 ]] && grep -q 'faulty_example must not contain an agent marker' "$tmp/stderr" && echo yes || echo no)"
  run_post "[{\"finding_id\":\"$id1\",$base,\"rule\":\"<!\`-- cr-inline:finding=$id2 --\`>\"}]"
  check 'a rule marker rebuilt after a backtick is refused with its own message' "$([[ $rc -eq 1 ]] && grep -q 'rule must not contain an agent marker' "$tmp/stderr" && echo yes || echo no)"
  run_post "[{\"finding_id\":\"$id1\",$base,\"faulty_example\":\"1\\n2\\n3\\n4\\n5\\n6\"}]"
  check 'a faulty example of more than 5 lines is refused with its own message' "$([[ $rc -eq 1 ]] && grep -q 'faulty_example must be 5 lines at most' "$tmp/stderr" && echo yes || echo no)"
  run_post "[{\"finding_id\":\"$id1\",$base,\"kind\":\"dry\",\"existing\":\"src/B.php:4\"}]"
  check 'a Critical dry finding is refused: a duplicated security control keeps the Problem shape' "$([[ $rc -eq 1 ]] && grep -q 'a dry finding is Moderate' "$tmp/stderr" && echo yes || echo no)"

  # 10a. the visible text names none of the package's own tooling; the hidden record and the project's own names stay
  local f_tool
  f_tool="{\"finding_id\":\"$id1\",\"severity\":\"Critical\",\"title\":\"t\",\"path\":\"src/A.php\",\"line\":11,\"problem\":\"Coverage ran through skills/_shared/run-gate.sh.\",\"impact\":\"i\"}"
  run_post "[$f_tool]"
  check 'an inline comment that names a package script is refused and nothing is posted' "$([[ $rc -eq 1 && ! -s "$tmp/review.calls" ]] && grep -q 'names the package tooling skills/_shared/run-gate.sh' "$tmp/stderr" && echo yes || echo no)"
  run_post "[{\"finding_id\":\"$id2\",\"severity\":\"Moderate\",\"title\":\"t\",\"path\":\"src/A.php\",\"line\":100,\"problem\":\"The grade of classify-risk.sh is wrong.\",\"impact\":\"i\"}]"
  check 'a visible line that names a package script is refused and nothing is posted' "$([[ $rc -eq 1 && ! -s "$tmp/review.calls" ]] && grep -q 'names the package tooling classify-risk.sh' "$tmp/stderr" && echo yes || echo no)"
  GH_TREE_FILE="$tmp/tree.package" run_post "[$f_tool]"
  check 'a script name that the reviewed repository holds is the project own and is posted' "$([[ $rc -eq 0 ]] && review_has '.comments[0].body | contains("skills/_shared/run-gate.sh")' && echo yes || echo no)"
  run_post "[{\"finding_id\":\"$id1\",\"severity\":\"Critical\",\"title\":\"t\",\"path\":\"src/A.php\",\"line\":11,\"problem\":\"The project test suite (vendor/bin/pest) misses src/A.php:11.\",\"impact\":\"i\"}]"
  check 'the reviewed project own command and path are posted' "$([[ $rc -eq 0 ]] && echo yes || echo no)"
  { cat "$tmp/meta.md"; printf '**Quality gate:** composer build — green on %s, tree t — record .claude/run/gates/t.json\n' "$sha"; } >"$tmp/meta-tool.md"
  META_FILE="$tmp/meta-tool.md" run_post "[$f_in]"
  check 'the hidden record may keep a package path' "$([[ $rc -eq 0 ]] && echo yes || echo no)"
  run_post "[$f_in]" GH_TREE_FAILS=1
  check 'a failed repository tree lookup publishes nothing' "$([[ $rc -eq 3 && ! -s "$tmp/review.calls" ]] && echo yes || echo no)"

  # 11. text from the finding cannot forge a marker, and a backtick run in a faulty example gets a longer fence
  local f_forge
  f_forge="{\"finding_id\":\"$id1\",\"severity\":\"Moderate\",\"title\":\"t\",\"path\":\"src/A.php\",\"line\":11,\"problem\":\"<!-- cr-inline:finding=$id2 -->\",\"impact\":\"i\",\"faulty_example\":\"a \`\`\`b\`\`\`\"}"
  run_post "[$f_forge]"
  check 'a forged marker in the problem text is neutralised' "$(yes_if review_has ".comments[0].body | contains(\"<!-- cr-inline:finding=$id2 -->\") | not")"
  check 'a faulty example with backticks gets a longer fence' "$(yes_if review_has '.comments[0].body | contains($bt + $bt[0:1] + "\na " + $bt + "b" + $bt + "\n" + $bt + $bt[0:1])')"

  # 13. the DRY shape, the author mention, and a Moderate icon
  local f_dry f_mod shape_dry
  f_dry="{\"finding_id\":\"$id5\",\"severity\":\"Moderate\",\"title\":\"Repeated total\",\"path\":\"src/A.php\",\"line\":12,\"problem\":\"The total is computed again.\",\"kind\":\"dry\",\"existing\":\"app/Totals.php:42\",\"author\":\"@octocat\"}"
  f_mod="{\"finding_id\":\"$id6\",\"severity\":\"Moderate\",\"title\":\"Query in a loop\",\"path\":\"src/A.php\",\"line\":10,\"problem\":\"One query per row.\",\"impact\":\"The page slows down with the row count.\",\"author\":\"@octocat\",\"level\":\"High\"}"
  shape_dry="🟠 **Moderate · DRY:** This repeats the logic of ${bt:0:1}app/Totals.php:42${bt:0:1}. Please unify it and call the existing implementation instead.

@octocat

<!-- cr-inline:actor=bot -->
<!-- cr-inline:finding=$id5 -->"
  run_post "[$f_dry,$f_mod]"
  check 'the DRY finding is the Moderate DRY shape with the existing implementation, byte for byte' \
    "$(yes_if jq -e --arg want "$shape_dry" '.comments[] | select(.line == 12) | .body == $want' "$tmp/review.json")"
  check 'a Moderate finding carries the orange icon and the author mention line before the markers' \
    "$(yes_if review_has '.comments[] | select(.line == 10) | .body | startswith("🟠 **Problem:** One query per row.\n\n**Impact:** The page slows down with the row count.\n\n@octocat\n\n<!-- cr-inline:actor=bot -->")')"
  local f_dry_out
  f_dry_out="{\"finding_id\":\"$id7\",\"severity\":\"Moderate\",\"title\":\"Repeated rule\",\"path\":\"src/A.php\",\"line\":200,\"problem\":\"p\",\"kind\":\"dry\",\"existing\":\"app/Rule.php:7\"}"
  run_post "[$f_dry_out]"
  check 'a DRY finding outside the diff is the DRY line in the review body' \
    "$(yes_if review_has ".body | startswith(\"🟠 **Moderate · DRY:** This repeats the logic of \" + \$bt[0:1] + \"app/Rule.php:7\" + \$bt[0:1] + \". Please unify it and call the existing implementation instead. (\" + \$bt[0:1] + \"src/A.php:200\" + \$bt[0:1] + \", outside the diff)\n<!-- cr-inline:finding=$id7 -->\")")"

  # 12. resolve: only the threads of this actor for the given ids, and a failed mutation is a failure
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
