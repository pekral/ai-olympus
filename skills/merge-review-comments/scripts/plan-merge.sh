#!/usr/bin/env bash
# plan-merge.sh — the deterministic half of `skills/merge-review-comments`: it loads the comments of ONE tracker item
# through the package loader, proves whose each comment is, and prints which review comments may be merged, which comment
# carries the merged text, and which comments a delete helper may remove. It writes nothing to a tracker.
#
# Usage:
#   plan-merge.sh plan <GitHub issue URL | JIRA key or URL | Bugsnag URL or triple> [--author <name>]
#   plan-merge.sh coverage <plan-file> <body-file> [<comment-id>...]
#   plan-merge.sh verify-read-back <item> <comment-id> <body-file> [--author <name>]
#   plan-merge.sh compare <plan-before> <plan-after>
#
# plan
#   Prints one JSON document: { tracker, item, actor, complete, unpublishedDataAllowed, deleteSupported,
#   updateSupported, merge: [ids], carrier, delete: [ids], keep: [{id, reason}], operatorCandidates: [ids],
#   comments: [{id, pos, author, createdAt, url, class, reason, reply, operatorLines?, body?}], stop }.
#   A comment is `review` only when the operator's own account wrote it (GitHub: the login that `gh api user` reports plus a
#   write-access association; JIRA: the account ID that jira-actor.sh resolves), it carries that account's own `cr-comment`
#   marker line, and it carries no other agent marker. `operator` is a comment of that account with no agent marker: the
#   skill reads it and decides whether it is a review. Everything else is `other` and never changes. Bugsnag has no account
#   lookup, so the caller names the operator's resolved collaborator name with --author; Bugsnag comments carry no id and no
#   marker, so nothing there is updated or deleted.
#   `operatorLines` of a review comment lists the lines the operator added to it (skills/_shared/operator-lines.sh). It is
#   null when the JIRA document body of the comment could not be read; such a comment is never in `delete`.
#   The plan fails closed. A `stop` text, with exit 5, replaces every list when the comment list may be shorter than the
#   thread, when the actor cannot be resolved, or when fewer than two review comments exist. A GitHub item is planned only
#   when `gh api repos/<o>/<r>/issues/<n>` reports an issue: a pull request exits 1 and an unreadable answer exits 3.
#
# coverage
#   Refuses, with exit 9, a merged body file that carries an agent marker: the publishing wrapper adds the one marker of
#   the comment. Then it lists every number of two digits or more, every `#<n>`, every URL, and every operator line of the
#   named comments of the plan (default: every comment of `merge`) that the merged body file lacks; an operator line must
#   stand in the body as a line of its own. A single digit is not checked, because it matches almost any text. A line
#   fingerprint and a marker of a comment are no content. Exit 0 when nothing is missing, 6 otherwise.
#
# verify-read-back
#   Loads the item again and proves that the carrier comment is readable, is a `review` comment of the operator's account,
#   and holds every number (and, on GitHub, every URL) of the body file that was published. Exit 0 or 7.
#
# compare
#   Compares the plan taken before the writes with a plan taken after them. It prints one `problem:` line for each comment
#   that must stay and changed or vanished (every `other` comment, every comment with a reply, the carrier), and for each
#   comment of `delete` that is still on the item. Exit 0 when there is no problem, 8 otherwise.
#
# Exit codes:
#   1 usage or argument error, or a pull request   2 missing tool   3 loader, account, or item lookup failed
#   4 the Bugsnag operator account was not named   5 the plan stopped   6 coverage gap   7 read-back failed
#   8 the comparison found a problem   9 the merged body carries an agent marker
set -euo pipefail

PROG="${0##*/}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SKILLS="$(cd "$HERE/../.." && pwd)"
JQ_LIB="$SKILLS/_shared"
# shellcheck source=../../_shared/operator-lines.sh
source "$JQ_LIB/operator-lines.sh"

usage() {
  cat >&2 <<EOF
Usage: $PROG plan <item> [--author <name>]
       $PROG coverage <plan-file> <body-file> [<comment-id>...]
       $PROG verify-read-back <item> <comment-id> <body-file> [--author <name>]
       $PROG compare <plan-before> <plan-after>

  item  a GitHub issue URL, a JIRA key or URL, or a Bugsnag error URL or <org>/<project>/<error-id>
EOF
}

if ! command -v jq >/dev/null 2>&1; then
  printf '%s\n' "$PROG: required tool not found: jq" >&2
  exit 2
fi

TMP_DIR="$(mktemp -d)"
trap 'rm -rf "$TMP_DIR"' EXIT

# detect_tracker <item> — prints github, jira, or bugsnag; exits 1 for anything else, a pull request included.
detect_tracker() {
  local item="$1"
  if [[ "$item" =~ ^https://(www\.)?github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/pull/[1-9][0-9]*/?$ ]]; then
    printf '%s\n' "$PROG: a pull request is not a tracker item of this skill; name the issue" >&2
    exit 1
  elif [[ "$item" =~ ^https://(www\.)?github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/issues/[1-9][0-9]*/?$ ]]; then
    printf 'github\n'
  elif [[ "$item" =~ ^[A-Z][A-Z0-9_]+-[0-9]+$ || "$item" == *"/browse/"* || "$item" == *"selectedIssue="* ]]; then
    printf 'jira\n'
  elif [[ "$item" =~ ^https://(www\.)?app\.bugsnag\.com/ || "$item" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+/[0-9a-f]{24}$ ]]; then
    printf 'bugsnag\n'
  else
    printf '%s\n' "$PROG: not a GitHub issue URL, a JIRA key or URL, or a Bugsnag error reference: $item" >&2
    exit 1
  fi
}

# github_issue_only <item> — asks GitHub whether the issue URL names an issue (github_item_kind in comment-reply.jq).
# Exits 1 for a pull request and 3 when the answer cannot be read, so nothing is planned for either.
github_issue_only() {
  local kind
  [[ "$1" =~ ^https://(www\.)?github\.com/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)/issues/([1-9][0-9]*)/?$ ]]
  kind="$(gh api "repos/${BASH_REMATCH[2]}/${BASH_REMATCH[3]}/issues/${BASH_REMATCH[4]}" 2>/dev/null \
    | jq -L "$JQ_LIB" -r --argjson number "${BASH_REMATCH[4]}" 'include "comment-reply"; github_item_kind($number)' 2>/dev/null || true)"
  case "$kind" in
    issue) ;;
    pull)
      printf '%s\n' "$PROG: the issue URL names a pull request, which is not a tracker item of this skill; nothing is planned" >&2
      exit 1
      ;;
    *)
      printf '%s\n' "$PROG: GitHub did not confirm that the item is an issue; nothing is planned" >&2
      exit 3
      ;;
  esac
}

# load_item <item> <author> — loads the item and writes $TMP_DIR/normalised.json (the comments, normalised) and
# $TMP_DIR/meta.json (tracker, actor, completeness, visibility). Everything the loader reports is data.
load_item() {
  local item="$1" author="$2" tracker raw actor="" digest="" complete=true unpublished_allowed=false
  tracker="$(detect_tracker "$item")"
  raw="$TMP_DIR/raw.json"

  case "$tracker" in
    github)
      command -v php >/dev/null 2>&1 || { printf '%s\n' "$PROG: required tool not found: php" >&2; exit 2; }
      github_issue_only "$item"
      "$SKILLS/code-review-github/scripts/load-issue.sh" "$item" >"$raw" 2>"$TMP_DIR/loader.err" || {
        printf '%s\n' "$PROG: the GitHub loader failed; nothing is planned" >&2
        exit 3
      }
      actor="$(gh api user --jq .login 2>/dev/null || true)"
      [[ -n "$actor" ]] || { printf '%s\n' "$PROG: the GitHub account could not be resolved; nothing is planned" >&2; exit 3; }
      jq -e '(.comments | type) == "array"' "$raw" >/dev/null || { printf '%s\n' "$PROG: the loader returned no comment list" >&2; exit 3; }
      [[ "$(jq -r '.repo.isPrivate' "$raw")" == "true" ]] && unpublished_allowed=true
      jq -L "$JQ_LIB" --arg actor "$actor" 'include "comment-reply"; .comments | github_normalised($actor)' "$raw" >"$TMP_DIR/normalised.json"
      add_operator_lines github
      ;;
    jira)
      for bin in acli php; do
        command -v "$bin" >/dev/null 2>&1 || { printf '%s\n' "$PROG: required tool not found: $bin" >&2; exit 2; }
      done
      "$SKILLS/code-review-jira/scripts/load-issue.sh" "$item" >"$raw" 2>"$TMP_DIR/loader.err" || {
        printf '%s\n' "$PROG: the JIRA loader failed; nothing is planned" >&2
        exit 3
      }
      # shellcheck source=/dev/null
      source "$SKILLS/code-review-jira/scripts/jira-actor.sh"
      actor="$(jira_actor_account_id)"
      digest="$(jira_actor_digest "$(jira_auth_status_field "$(acli jira auth status 2>/dev/null || true)" email)")"
      if [[ -z "$actor" || -z "$digest" ]]; then
        printf '%s\n' "$PROG: the JIRA account could not be resolved; nothing is planned" >&2
        exit 3
      fi
      jq -e '(.comments | type) == "array"' "$raw" >/dev/null || { printf '%s\n' "$PROG: the loader returned no comment list" >&2; exit 3; }
      [[ "$(jq -r '.commentsComplete' "$raw")" == "true" ]] || complete=false
      unpublished_allowed=true
      jq -L "$JQ_LIB" --arg actor "$actor" 'include "comment-reply"; .comments | jira_normalised($actor)' "$raw" >"$TMP_DIR/normalised.json"
      # The operator lines need the document body, which the loader renders to text; the delete helper reads the same view.
      if ! acli jira workitem view "$(jq -r '.key' "$raw")" --fields comment --json >"$TMP_DIR/view.json" 2>/dev/null; then
        printf '%s\n' "$PROG: the JIRA comment documents could not be read; nothing is planned" >&2
        exit 3
      fi
      add_operator_lines jira
      ;;
    bugsnag)
      if [[ -z "$author" ]]; then
        printf '%s\n' "$PROG: Bugsnag shows no account lookup; name the operator's resolved collaborator name with --author" >&2
        exit 4
      fi
      "$SKILLS/code-review-bugsnag/scripts/load-issue.sh" "$item" >"$raw" 2>"$TMP_DIR/loader.err" || {
        printf '%s\n' "$PROG: the Bugsnag loader failed; nothing is planned" >&2
        exit 3
      }
      actor="$author"
      unpublished_allowed=true
      grep -qi 'truncated' "$TMP_DIR/loader.err" && complete=false
      jq -e '(.comments | type) == "array"' "$raw" >/dev/null || { printf '%s\n' "$PROG: the loader returned no comment list" >&2; exit 3; }
      jq -e '.commentCount == (.comments | length)' "$raw" >/dev/null || complete=false
      jq -c '.comments | to_entries | map(.key as $pos | .value
        | { id: ("pos-" + ($pos | tostring)), pos: $pos, author: .author, authorKey: .author, createdAt: (.createdAt // null),
            updatedAt: (.updatedAt // null), url: null, body: (.body // ""), mentions: [], parentId: null, restricted: false, agent: false })' "$raw" >"$TMP_DIR/normalised.json"
      ;;
  esac

  jq -n --arg tracker "$tracker" --arg item "$item" --arg actor "$actor" --arg digest "$digest" \
    --argjson complete "$complete" --argjson unpublished_allowed "$unpublished_allowed" \
    '{tracker: $tracker, item: $item, actor: $actor, digest: $digest, complete: $complete, unpublishedDataAllowed: $unpublished_allowed}' >"$TMP_DIR/meta.json"
}

# add_operator_lines <github|jira> — adds `operatorLines` to every normalised comment that carries a review marker. On JIRA
# the unit is a node of the document of $TMP_DIR/view.json; a comment whose document is missing gets null.
add_operator_lines() {
  local tracker="$1" id file lines="$TMP_DIR/operator-lines.jsonl" found
  : >"$lines"
  while IFS= read -r id; do
    file="$TMP_DIR/comment-$id"
    found=1
    if [[ "$tracker" == "github" ]]; then
      jq -r --arg id "$id" '.[] | select(.id == $id) | .body' "$TMP_DIR/normalised.json" >"$file"
    else
      jq -c --arg id "$id" '[.fields.comment.comments // [] | .[] | select(((.id // "") | tostring) == $id) | .body
        | select(type == "object")] | first // empty' "$TMP_DIR/view.json" >"$file"
      [[ -s "$file" ]] || found=0
    fi
    if [[ "$found" -eq 0 ]]; then
      jq -n -c --arg id "$id" '{id: $id, lines: null}' >>"$lines"
    elif ! operator_lines "$([[ "$tracker" == "github" ]] && printf markdown || printf adf)" "$file" >"$file.lines"; then
      printf '%s\n' "$PROG: the operator lines of comment $id could not be read; nothing is planned" >&2
      exit 3
    else
      jq -R . "$file.lines" | jq -s -c --arg id "$id" '{id: $id, lines: .}' >>"$lines"
    fi
  done < <(jq -r '.[] | select(.id != null and (.body | contains("cr-comment:"))) | .id' "$TMP_DIR/normalised.json")
  jq --slurpfile found "$lines" '($found | map({key: .id, value: .lines}) | from_entries) as $byId
    | map(. + {operatorLines: (.id as $i | if $i != null and ($byId | has($i)) then $byId[$i] else [] end)})' \
    "$TMP_DIR/normalised.json" >"$TMP_DIR/normalised.next" && mv "$TMP_DIR/normalised.next" "$TMP_DIR/normalised.json"
}

# The classification and the plan, in one jq program over the normalised comments. The single quotes keep the shell
# out of its `$` variables.
# shellcheck disable=SC2016
PLAN_JQ='
include "review-trust";
include "comment-reply";

def own_marker($ns): if $meta.tracker == "github" then "<!-- " + $ns + ":actor=" + $meta.actor + " -->" else $ns + ":actor=" + $meta.digest end;
def has_own_cr:
  if $meta.tracker == "github"
  then (.body | marker_line(own_marker("cr-comment")))
  else (.body | contains(own_marker("cr-comment"))) end;
def mine:
  if $meta.tracker == "github" then (.authorKey == $meta.actor and (.authorAssociation | write_access))
  else .authorKey == $meta.actor end;

def classified:
  if (mine | not) then . + {class: "other", reason: "not written by the operator account, or the account holds no write access"}
  elif (.restricted == true) then . + {class: "other", reason: "restricted visibility"}
  elif (.id == null) then . + {class: "other", reason: "no comment id"}
  elif $meta.tracker == "bugsnag" then . + {class: "operator", reason: "operator account, no marker on this tracker"}
  elif ($meta.tracker == "jira" and (.body | jira_summary)) then . + {class: "other", reason: "create-only attributed report"}
  elif (has_own_cr and (.body | other_agent_marker | not)) then . + {class: "review", reason: "review comment of the operator account"}
  elif (.body | agent_marker | not) then . + {class: "operator", reason: "operator account, no agent marker"}
  else . + {class: "other", reason: "carries another agent marker"} end;

. as $all
| ($all | map(classified)) as $c
| ($c | map(select(.class == "review"))) as $reviews
| ($reviews | map(select(. as $r | has_reply($r; $c)) | .id)) as $replied
| ($c | map(select(.class == "operator") | .id)) as $operators
| (if $meta.tracker == "bugsnag" then null
   else (($reviews | map(select(.id as $i | $replied | index($i))) | last // ($reviews | last)) | .id // null) end) as $carrier
| ($reviews | map(.id)) as $reviewIds
| ($meta.tracker != "bugsnag") as $writable
| (if ($meta.complete | not) then "the comment list may be shorter than the thread; nothing is merged or deleted"
   elif (($reviewIds | length) + ($operators | length)) < 2 then "fewer than two review comments of the operator account or an agent"
   else null end) as $stop
| $meta + {
    deleteSupported: $writable,
    updateSupported: $writable,
    stop: $stop,
    merge: (if $stop then [] else ($c | map(select(.class == "review" or .class == "operator") | .id)) end),
    carrier: (if $stop then null else $carrier end),
    delete: (if $stop or ($writable | not) then []
             else ($reviews | map(select(.operatorLines != null) | .id
                    | select(. as $i | $i != $carrier and ($replied | index($i) | not)))) end),
    operatorCandidates: (if $stop then [] else $operators end),
    keep: ($c | map(select(.class == "other") | {id, reason})
           + ($reviews | map(select(.id as $i | $replied | index($i)) | {id, reason: "has a reply, never deleted"}))
           + ($reviews | map(select(.operatorLines == null) | {id, reason: "its document could not be read, so its operator lines are unknown"}))
           + (if $stop then [] else ($reviews | map(select(.id == $carrier and ($replied | index($carrier) | not)) | {id, reason: "carries the merged text"})) end)),
    comments: ($c | map({id, pos, author, createdAt, updatedAt, url, class, reason, bodyLength: (.body | length),
                         reply: (if .class == "review" then (. as $r | has_reply($r; $c)) else null end)}
                        + (if .class == "review" then {operatorLines} else {} end)
                        + (if .class == "review" or .class == "operator" then {body} else {} end)))
  }
'

# tokens <body-file> — numbers of two digits or more, `#<n>`, and URLs of a body, one per line, with the marker and line
# fingerprint lines and the list numbers of the body left out.
tokens() {
  grep -Ev ':(actor|lines)=' "$1" \
    | sed -E 's/^[[:space:]]*[0-9]+[.)][[:space:]]+//' \
    | grep -Eo 'https?://[^[:space:])>\]|"]+|#[0-9]+|[0-9]{2,}' \
    | sort -u || true
}

body_text_of() {
  jq -r --arg id "$2" '.comments[] | select(.id == $id) | .body // ""' "$1"
}

cmd_plan() {
  local item="" author=""
  [[ $# -ge 1 ]] || { usage; exit 1; }
  item="$1"
  shift
  while [[ $# -gt 0 ]]; do
    case "$1" in
      --author) [[ $# -ge 2 ]] || { usage; exit 1; }; author="$2"; shift 2 ;;
      *) usage; exit 1 ;;
    esac
  done
  load_item "$item" "$author"
  jq -L "$JQ_LIB" --argjson meta "$(cat "$TMP_DIR/meta.json")" "$PLAN_JQ" "$TMP_DIR/normalised.json" >"$TMP_DIR/plan.json"
  jq 'del(.digest)' "$TMP_DIR/plan.json"
  if [[ "$(jq -r '.stop // empty' "$TMP_DIR/plan.json")" != "" ]]; then
    exit 5
  fi
}

cmd_coverage() {
  [[ $# -ge 2 ]] || { usage; exit 1; }
  local plan="$1" body="$2" ids missing=0 token source_file
  shift 2
  [[ -r "$plan" && -r "$body" ]] || { printf '%s\n' "$PROG: cannot read the plan or the body file" >&2; exit 1; }
  if [[ $# -gt 0 ]]; then
    ids="$(printf '%s\n' "$@" | jq -R . | jq -s -c .)"
  else
    ids="$(jq -c '.merge' "$plan")"
  fi
  if jq -Rs -e -L "$JQ_LIB" 'include "review-trust"; agent_marker' "$body" >/dev/null; then
    grep -Eo '[a-z][a-z0-9-]*:actor=[^[:space:]]*' "$body" | sed 's/^/refused: the merged text carries an agent marker: /'
    exit 9
  fi
  source_file="$TMP_DIR/source.txt"
  jq -r --argjson ids "$ids" '.comments[] | select(.id as $i | $ids | index($i)) | .body // empty' "$plan" >"$source_file"
  # An operator line counts only as a line of its own, compared with the whitespace collapsed, as the delete helpers compare it.
  jq -r -n --argjson ids "$ids" --slurpfile plan "$plan" --rawfile body "$body" '
    def norm: gsub("\\s+"; " ") | ltrimstr(" ") | rtrimstr(" ");
    ($body | split("\n") | map(norm)) as $have
    | $plan[0].comments[] | select(.id as $i | $ids | index($i)) | (.operatorLines // [])[]
    | select(norm as $line | $have | index([$line]) | not) | "missing line: " + .' >"$TMP_DIR/missing-lines"
  if [[ -s "$TMP_DIR/missing-lines" ]]; then
    cat "$TMP_DIR/missing-lines"
    missing=1
  fi
  tokens "$source_file" >"$TMP_DIR/source.tokens"
  while IFS= read -r token; do
    [[ -n "$token" ]] || continue
    if ! grep -Fq -- "$token" "$body"; then
      printf 'missing: %s\n' "$token"
      missing=1
    fi
  done <"$TMP_DIR/source.tokens"
  [[ "$missing" -eq 0 ]] || exit 6
}

cmd_verify_read_back() {
  [[ $# -ge 3 ]] || { usage; exit 1; }
  local item="$1" id="$2" body="$3" author="" tracker token read_back="$TMP_DIR/readback.txt" class
  shift 3
  while [[ $# -gt 0 ]]; do
    case "$1" in
      --author) [[ $# -ge 2 ]] || { usage; exit 1; }; author="$2"; shift 2 ;;
      *) usage; exit 1 ;;
    esac
  done
  [[ -r "$body" ]] || { printf '%s\n' "$PROG: cannot read the body file" >&2; exit 1; }
  load_item "$item" "$author"
  jq -L "$JQ_LIB" --argjson meta "$(cat "$TMP_DIR/meta.json")" "$PLAN_JQ" "$TMP_DIR/normalised.json" >"$TMP_DIR/plan.json"
  tracker="$(jq -r '.tracker' "$TMP_DIR/plan.json")"
  class="$(jq -r --arg id "$id" '.comments[] | select(.id == $id) | .class' "$TMP_DIR/plan.json")"
  if [[ "$class" != "review" ]]; then
    printf '%s\n' "$PROG: read-back failed: comment $id is not a readable review comment of the operator account" >&2
    exit 7
  fi
  body_text_of "$TMP_DIR/plan.json" "$id" >"$read_back"
  tokens "$body" >"$TMP_DIR/expected.tokens"
  while IFS= read -r token; do
    [[ -n "$token" ]] || continue
    if [[ "$tracker" != "github" && "$token" == http* ]]; then
      continue
    fi
    if ! grep -Fq -- "$token" "$read_back"; then
      printf '%s\n' "$PROG: read-back failed: comment $id lacks $token" >&2
      exit 7
    fi
  done <"$TMP_DIR/expected.tokens"
  printf 'read-back ok id=%s\n' "$id"
}

cmd_compare() {
  [[ $# -eq 2 && -r "$1" && -r "$2" ]] || { usage; exit 1; }
  local problems
  problems="$(jq -r -n --slurpfile before "$1" --slurpfile after "$2" '
    ($before[0]) as $b | ($after[0]) as $a
    | ($a.comments | map({key: .id, value: .}) | from_entries) as $now
    | [ $b.comments[]
        | select(.class == "other" or (.class == "review" and (.id as $i | $b.delete | index($i) | not)))
        | . as $c
        | if ($now[$c.id] == null) then "problem: comment \($c.id) must stay and is gone"
          elif ($c.class == "other" and ($now[$c.id].bodyLength != $c.bodyLength or $now[$c.id].updatedAt != $c.updatedAt))
            then "problem: comment \($c.id) must stay unchanged and changed"
          else empty end ],
      [ $b.delete[] | select(. as $i | $now[$i] != null) | "problem: comment \(.) was to be deleted and is still on the item" ],
      (if $b.carrier != null and $now[$b.carrier] == null then ["problem: the carrier comment \($b.carrier) is gone"] else [] end)
    | .[]')"
  if [[ -n "$problems" ]]; then
    printf '%s\n' "$problems"
    exit 8
  fi
  printf '%s\n' 'compare ok'
}

[[ $# -ge 1 ]] || { usage; exit 1; }
SUBCOMMAND="$1"
shift
case "$SUBCOMMAND" in
  plan) cmd_plan "$@" ;;
  coverage) cmd_coverage "$@" ;;
  verify-read-back) cmd_verify_read_back "$@" ;;
  compare) cmd_compare "$@" ;;
  *) usage; exit 1 ;;
esac
