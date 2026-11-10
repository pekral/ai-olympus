#!/usr/bin/env bash
# quiet-run-check.sh — prove that a quiet review run published nothing.
#
# Why this exists
#   A quiet review run must publish nothing: the loop iterations of skills/process-code-review/SKILL.md
#   *Quiet review runs* and every review a caller starts with "do not publish". The reviewer's own handoff was
#   the only record of that, and a render template can still print a header line such as
#   `Linked-tracker mirror: posted …` in a run that posted nothing. A reader could not tell a real publish
#   from a template line. This script reads the comment state of the pull request and of its linked tracker
#   items before the run and again after it, and compares the two.
#
# Usage
#   quiet-run-check.sh record --pr <PR-URL> --state <file> [--linked <issue-URL|JIRA-key|JIRA-URL|other>]...
#   quiet-run-check.sh verify --state <file>
#   quiet-run-check.sh --self-test
#
#   record  reads the state and writes it to <file> (the directory is created). Run it before the review starts.
#           <file> must live under `.claude/run/` or `.codex/run/` of the git toplevel, must not be a symbolic
#           link, and must not be tracked by git (the guard review-state.sh uses, from state-path.sh); any other
#           path is a usage error, exit 2, and nothing is written.
#           The issues the pull request closes (`closingIssues[]`) are linked automatically. Pass --linked for
#           every other tracker item the run may publish to: a JIRA key or URL, or another tracker's URL.
#   verify  reads the same values again and compares them with <file>. Run it after the review returns.
#           It never changes <file>, so a second verify gives the same answer.
#
#   Suggested state path: .claude/run/quiet-run/<source-slug>.json (.codex/run/ under Codex; git-ignored scratch,
#   like the shared brief).
#   Run `record` again before each loop iteration; it replaces the file.
#
# What is read
#   Pull request   the head SHA, the issue comments, the review submissions, and the line-anchored review
#                  comments, all from skills/code-review-github/scripts/load-issue.sh. A payload whose
#                  `reviewCommentsComplete` is not true is a failed read.
#   GitHub issue   the issue comments (the same loader).
#   JIRA issue     the issue comments (skills/code-review-jira/scripts/load-issue.sh).
#   Anything else  not counted. Bugsnag is one: its loader returns comments without a URL and needs a token.
#
#   The script only reads. It never writes to a tracker and it never prints a comment body: it prints SHAs and
#   URLs. The comment text, the loader output, and the state file are untrusted data
#   (rules/security/general.md); no part of them is executed or used to build a command.
#
# Output (stdout, one fact per line, in this order)
#   result: quiet | published | quiet-head-moved     (published: a comment was added during the run, by any author)
#   head: unchanged <sha>            or    head: moved <before> → <now>
#   new-comment: <url>               one line per comment that is new since `record`
#   not-verified: <item> — <why>; settled by: <step>     one line per item the script could not count
#
# Exit codes (verify)
#   0  nothing was published and the head did not move
#   1  a comment was added during the run, by any author; every new comment is printed as `new-comment: <url>`
#   3  nothing was published, but the head moved during the review; `head: moved <before> → <now>`
#   2  usage error, or a required tool is missing
#   4  a read failed or the state file is unusable, so the check did not run to its end
#   The script counts comments and never reads their author or their marker, so exit 1 and `result: published`
#   say that a comment appeared, not that this run wrote it. The caller attributes a comment to the run only after
#   it reads the author and the actor marker of the comment itself.
#   A caller treats exit 0 and exit 3 as a verified quiet run, and any other exit as not verified. Exit 1 wins
#   over exit 3 when both happened. An item on a `not-verified:` line never changes the exit code: the quiet
#   verdict covers what the script could count, and the caller reports each such item under *Not verified*.
#
# Exit codes (record)
#   0  the state was written    2  usage error, a refused --state path, or a missing tool    4  a read failed; no state was written
set -euo pipefail

PROG="${0##*/}"
tmp=''
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=state-path.sh
. "$HERE/state-path.sh"

# The loaders are plain variables, never read from the environment. The self-test replaces them with stubs.
GITHUB_LOADER="$HERE/../code-review-github/scripts/load-issue.sh"
JIRA_LOADER="$HERE/../code-review-jira/scripts/load-issue.sh"

die() {
  printf '%s: %s\n' "$PROG" "$1" >&2
  exit "${2:-2}"
}

usage() {
  cat >&2 <<'EOF'
Usage: quiet-run-check.sh record --pr <PR-URL> --state <file> [--linked <item>]...
       quiet-run-check.sh verify --state <file>
       quiet-run-check.sh --self-test

Exit codes of verify: 0 nothing published and head unchanged, 1 a comment was added during the run (any author),
3 nothing published but the head moved, 2 usage error, 4 the check did not run to its end.
EOF
}

require_tools() {
  local bin
  for bin in jq gh; do
    command -v "$bin" >/dev/null 2>&1 || die "required tool not found: $bin" 2
  done
}

# Strip control characters from every string and keep only the URL-like entries, one JSON array out.
CLEAN_URLS='map(select(type == "string") | gsub("[\u0000-\u001f\u007f]"; "")) | map(select(length > 0)) | unique'

# collect_github <github.com issue or PR URL> — one JSON document on stdout:
#   { "kind", "headSha", "closing": [url], "urls": [url], "count": n }
collect_github() {
  local url="$1" payload

  payload="$("$GITHUB_LOADER" "$url")" || return 4
  printf '%s' "$payload" | jq -e 'type == "object" and (.kind == "issue" or (.kind == "pr" and .reviewCommentsComplete == true))' \
    >/dev/null 2>&1 || return 4

  printf '%s' "$payload" | jq -c "
    [ (.comments // [])[].url, (.reviews // [])[].url, (.reviewComments // [])[].url ] as \$all
    | {
        kind: .kind,
        headSha: (if .kind == \"pr\" then (.headRefOid // null) else null end),
        closing: ([ (.closingIssues // [])[].url ] | $CLEAN_URLS),
        urls: (\$all | $CLEAN_URLS),
        count: (\$all | length)
      }"
}

# collect_jira <key|url> — { "urls": [url], "count": n }. The loader degrades a failed fetch to an empty list.
collect_jira() {
  local payload
  payload="$("$JIRA_LOADER" "$1")" || return 4
  printf '%s' "$payload" | jq -c "
    (.url // \"\") as \$base
    | [ (.comments // [])[] | (\$base + \"?focusedCommentId=\" + ((.id // \"\") | tostring)) ] as \$all
    | { urls: (\$all | $CLEAN_URLS), count: (\$all | length) }" 2>/dev/null || return 4
}

# classify <item> — prints github | jira | unsupported
classify() {
  local item="$1"
  if [[ "$item" =~ ^https?://(www\.)?github\.com/[^/]+/[^/]+/(issues|pull)/[0-9]+ ]]; then
    printf 'github'
  elif [[ "$item" =~ ^[A-Z][A-Z0-9]+-[0-9]+$ || "$item" =~ ^https?://[^/]+/browse/[A-Z][A-Z0-9]+-[0-9]+ || "$item" =~ ^https?://[^/]+/.*selectedIssue=[A-Z][A-Z0-9]+-[0-9]+ ]]; then
    printf 'jira'
  else
    printf 'unsupported'
  fi
}

# unsupported_reason <item> — the reason and the step that settles it, for the Not verified section.
unsupported_reason() {
  if [[ "$1" =~ bugsnag ]]; then
    printf 'the Bugsnag loader returns comments without a URL and needs a data-access token, so this script does not count them; settled by: open the error in the Bugsnag dashboard and check that its comment list has no entry newer than the start of the run'
  else
    printf 'this script counts the comments of a GitHub issue or pull request and of a JIRA issue only; settled by: open the item and check that its comment list has no entry newer than the start of the run'
  fi
}

# collect_linked <item> — one JSON document on stdout:
#   { "input", "kind", "urls", "count", "reason" }
collect_linked() {
  local item="$1" kind data
  kind="$(classify "$item")"
  case "$kind" in
    github)
      data="$(collect_github "$item")" || return 4
      printf '%s' "$data" | jq -c --arg input "$item" '{ input: $input, kind: "github", urls: .urls, count: .count, reason: null }'
      ;;
    jira)
      data="$(collect_jira "$item")" || return 4
      printf '%s' "$data" | jq -c --arg input "$item" '{ input: $input, kind: "jira", urls: .urls, count: .count, reason: null }'
      ;;
    *)
      jq -nc --arg input "$item" --arg reason "$(unsupported_reason "$item")" \
        '{ input: $input, kind: "unsupported", urls: [], count: 0, reason: $reason }'
      ;;
  esac
}

# snapshot <pr-url> <linked-item>... — the whole state as one JSON document on stdout.
snapshot() {
  local pr_url="$1" pr_data item items linked_json='[]' one
  shift

  pr_data="$(collect_github "$pr_url")" || return 4
  printf '%s' "$pr_data" | jq -e '.kind == "pr" and (.headSha | type == "string" and test("^[0-9a-f]{40}$"))' >/dev/null 2>&1 || return 4

  # The issues the pull request closes come first, then the explicit items, without duplicates.
  items="$(
    {
      printf '%s' "$pr_data" | jq -r '.closing[]'
      for item in "$@"; do
        printf '%s\n' "$item"
      done
    } | awk 'NF && !seen[$0]++'
  )"

  while IFS= read -r item; do
    [[ -n "$item" ]] || continue
    one="$(collect_linked "$item")" || return 4
    linked_json="$(printf '%s' "$linked_json" | jq -c --argjson one "$one" '. + [$one]')"
  done <<<"$items"

  printf '%s' "$pr_data" | jq -c --argjson linked "$linked_json" --arg url "$pr_url" '
    { version: 1, pr: { url: $url, headSha: .headSha, urls: .urls, count: .count }, linked: $linked }'
}

cmd_record() {
  local pr='' state='' state_dir tmp doc problem
  local -a linked_items=()

  while [[ $# -gt 0 ]]; do
    case "$1" in
      --pr) [[ $# -ge 2 ]] || { usage; exit 2; }; pr="$2"; shift 2 ;;
      --state) [[ $# -ge 2 ]] || { usage; exit 2; }; state="$2"; shift 2 ;;
      --linked) [[ $# -ge 2 ]] || { usage; exit 2; }; linked_items+=("$2"); shift 2 ;;
      *) usage; exit 2 ;;
    esac
  done
  [[ -n "$pr" && -n "$state" ]] || { usage; exit 2; }
  [[ "$pr" =~ ^https?://(www\.)?github\.com/[^/]+/[^/]+/pull/[0-9]+ ]] || die "--pr must be a github.com pull request URL" 2
  require_tools

  problem="$(state_path_problem "$state" create)" || die "$problem" 2
  doc="$(snapshot "$pr" ${linked_items[@]+"${linked_items[@]}"})" || die "a read failed; no state was recorded" 4

  state_dir="$(dirname "$state")"
  mkdir -p "$state_dir" || die "cannot create $state_dir" 4
  tmp="$(mktemp "$state_dir/.quiet-run.XXXXXX")" || die "cannot write in $state_dir" 4
  if ! { printf '%s\n' "$doc" >"$tmp" && mv "$tmp" "$state"; }; then
    rm -f "$tmp"
    die "cannot write $state" 4
  fi

  printf 'recorded: %s\n' "$state"
}

# new_urls <before-json-array> <after-json-array> — the URLs only the after state has, one per line.
new_urls() {
  jq -nr --argjson before "$1" --argjson after "$2" '($after - $before)[]'
}

cmd_verify() {
  local state='' doc pr_url pr_data head_before head_now published=0 moved=0
  local lines='' linked_count i entry kind input url before_urls before_count after after_urls after_count added

  while [[ $# -gt 0 ]]; do
    case "$1" in
      --state) [[ $# -ge 2 ]] || { usage; exit 2; }; state="$2"; shift 2 ;;
      *) usage; exit 2 ;;
    esac
  done
  [[ -n "$state" ]] || { usage; exit 2; }
  require_tools

  [[ -f "$state" ]] || die "the state file does not exist: $state" 4
  doc="$(cat "$state")" || die "cannot read $state" 4
  printf '%s' "$doc" | jq -e '.version == 1 and (.pr.headSha | type == "string") and (.pr.urls | type == "array") and (.linked | type == "array")' >/dev/null 2>&1 \
    || die "the state file is not a recorded state: $state" 4

  pr_url="$(printf '%s' "$doc" | jq -r '.pr.url')"
  pr_data="$(collect_github "$pr_url")" || die "a read failed; the check did not run to its end" 4
  head_before="$(printf '%s' "$doc" | jq -r '.pr.headSha')"
  head_now="$(printf '%s' "$pr_data" | jq -r '.headSha // ""')"
  [[ "$head_now" =~ ^[0-9a-f]{40}$ ]] || die "the head SHA could not be read; the check did not run to its end" 4
  [[ "$head_now" == "$head_before" ]] || moved=1

  # The pull request: a comment is new when its URL was not recorded, or when the count grew without a new URL.
  before_urls="$(printf '%s' "$doc" | jq -c '.pr.urls')"
  before_count="$(printf '%s' "$doc" | jq -r '.pr.count')"
  after_urls="$(printf '%s' "$pr_data" | jq -c '.urls')"
  after_count="$(printf '%s' "$pr_data" | jq -r '.count')"
  added="$(new_urls "$before_urls" "$after_urls")"
  if [[ -n "$added" ]]; then
    published=1
    while IFS= read -r url; do
      lines="${lines}new-comment: $url"$'\n'
    done <<<"$added"
  elif [[ "$after_count" -gt "$before_count" ]]; then
    published=1
    lines="${lines}new-comment: $pr_url (the comment count grew from $before_count to $after_count; the loader returned no URL for it)"$'\n'
  fi

  linked_count="$(printf '%s' "$doc" | jq -r '.linked | length')"
  for ((i = 0; i < linked_count; i++)); do
    entry="$(printf '%s' "$doc" | jq -c ".linked[$i]")"
    kind="$(printf '%s' "$entry" | jq -r '.kind')"
    input="$(printf '%s' "$entry" | jq -r '.input')"

    if [[ "$kind" == "unsupported" ]]; then
      lines="${lines}not-verified: $input — $(printf '%s' "$entry" | jq -r '.reason')"$'\n'
      continue
    fi

    after="$(collect_linked "$input")" || die "a read failed for $input; the check did not run to its end" 4
    before_urls="$(printf '%s' "$entry" | jq -c '.urls')"
    before_count="$(printf '%s' "$entry" | jq -r '.count')"
    after_urls="$(printf '%s' "$after" | jq -c '.urls')"
    after_count="$(printf '%s' "$after" | jq -r '.count')"

    # The JIRA loader degrades a failed comment fetch to an empty list, so an empty list proves nothing: not an
    # empty list after the run (a comment may have been hidden), and not an empty list recorded before it (every
    # comment the second read returns would then look new).
    if [[ "$kind" == "jira" && ( "$after_count" -eq 0 || "$before_count" -eq 0 ) ]]; then
      lines="${lines}not-verified: $input — the JIRA loader returns an empty comment list when its fetch fails, so an empty list read before or after the run does not prove the comment state; settled by: list the comments of the JIRA issue with acli and check that none is newer than the start of the run"$'\n'
      continue
    fi

    added="$(new_urls "$before_urls" "$after_urls")"
    if [[ -n "$added" ]]; then
      published=1
      while IFS= read -r url; do
        lines="${lines}new-comment: $url"$'\n'
      done <<<"$added"
    elif [[ "$after_count" -gt "$before_count" ]]; then
      published=1
      lines="${lines}new-comment: $input (the comment count grew from $before_count to $after_count; the loader returned no URL for it)"$'\n'
    fi
  done

  if [[ "$published" -eq 1 ]]; then
    printf 'result: published\n'
  elif [[ "$moved" -eq 1 ]]; then
    printf 'result: quiet-head-moved\n'
  else
    printf 'result: quiet\n'
  fi
  if [[ "$moved" -eq 1 ]]; then
    printf 'head: moved %s → %s\n' "$head_before" "$head_now"
  else
    printf 'head: unchanged %s\n' "$head_now"
  fi
  printf '%s' "$lines"

  if [[ "$published" -eq 1 ]]; then
    return 1
  elif [[ "$moved" -eq 1 ]]; then
    return 3
  fi
  return 0
}

self_test() {
  local failures=0 checks=0 script="$HERE/$PROG"
  local sha_a="aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa" sha_b="bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb"
  local pr='https://github.com/acme/widgets/pull/7' issue='https://github.com/acme/widgets/issues/3'
  local out rc state

  command -v jq >/dev/null 2>&1 || { echo "quiet-run-check self-test: jq is required" >&2; return 2; }
  tmp="$(mktemp -d)"
  trap 'rm -rf "$tmp"' EXIT

  mkdir -p "$tmp/fx"
  # The state file must live under .claude/run/ of a git toplevel, so the tests run in a throw-away repository.
  git init -q "$tmp/repo"
  state="$tmp/repo/.claude/run/quiet-run/state.json"

  # Stub loaders: each serves the fixture file for its input, so a test rewrites the fixture between steps.
  cat >"$tmp/github-loader.sh" <<EOF
#!/usr/bin/env bash
case "\$1" in
  "$pr") cat "$tmp/fx/pr.json" ;;
  "$issue") cat "$tmp/fx/issue.json" ;;
  *) exit 3 ;;
esac
EOF
  cat >"$tmp/jira-loader.sh" <<EOF
#!/usr/bin/env bash
[[ -f "$tmp/fx/jira.fail" ]] && exit 3
cat "$tmp/fx/jira.json"
EOF
  chmod +x "$tmp/github-loader.sh" "$tmp/jira-loader.sh"
  GITHUB_LOADER="$tmp/github-loader.sh"
  JIRA_LOADER="$tmp/jira-loader.sh"

  # fixtures <head-sha> <issue-comment-ids> <review-ids> <review-comment-ids> — rewrite the fixtures
  # A comment id n is the URL https://github.com/acme/widgets/pull/7#issuecomment-n.
  fixtures() {
    local head="$1" ids="$2" reviews="$3" inline="$4" id
    {
      printf '{"kind":"pr","number":7,"repo":{"nameWithOwner":"acme/widgets"},"headRefOid":"%s",' "$head"
      printf '"comments":['
      local first=1
      for id in $ids; do
        [[ $first -eq 1 ]] || printf ','
        first=0
        printf '{"url":"https://github.com/acme/widgets/pull/7#issuecomment-%s"}' "$id"
      done
      printf '],"reviews":['
      first=1
      for id in $reviews; do
        [[ $first -eq 1 ]] || printf ','
        first=0
        printf '{"url":"https://github.com/acme/widgets/pull/7#pullrequestreview-%s"}' "$id"
      done
      printf '],"reviewComments":['
      first=1
      for id in $inline; do
        [[ $first -eq 1 ]] || printf ','
        first=0
        printf '{"url":"https://github.com/acme/widgets/pull/7#discussion_r%s"}' "$id"
      done
      printf '],"reviewCommentsComplete":true,"closingIssues":[{"url":"%s"}]}' "$issue"
    } >"$tmp/fx/pr.json"
  }
  issue_fixture() {
    local ids="$1" id first=1
    {
      printf '{"kind":"issue","number":3,"repo":{"nameWithOwner":"acme/widgets"},"comments":['
      for id in $ids; do
        [[ $first -eq 1 ]] || printf ','
        first=0
        printf '{"url":"https://github.com/acme/widgets/issues/3#issuecomment-%s"}' "$id"
      done
      printf ']}'
    } >"$tmp/fx/issue.json"
  }
  jira_fixture() {
    local ids="$1" id first=1
    {
      printf '{"key":"ACME-9","url":"https://acme.atlassian.net/browse/ACME-9","comments":['
      for id in $ids; do
        [[ $first -eq 1 ]] || printf ','
        first=0
        printf '{"id":"%s"}' "$id"
      done
      printf ']}'
    } >"$tmp/fx/jira.json"
  }

  expect() {
    local label="$1" want_rc="$2" want_text="${3:-}" got_rc="$4" output="$5"
    checks=$((checks + 1))
    if [[ "$got_rc" -eq "$want_rc" && ( -z "$want_text" || "$output" == *"$want_text"* ) ]]; then
      printf 'ok    %-70s exit %s\n' "$label" "$got_rc"
    else
      printf 'FAIL  %-70s expected exit %s with [%s], got exit %s: %s\n' "$label" "$want_rc" "$want_text" "$got_rc" "$output" >&2
      failures=$((failures + 1))
    fi
  }
  # run_verify <label> <want_rc> <want_text> — verify in the current process, so the stubs stay in force.
  run_verify() {
    set +e
    out="$(cmd_verify --state "$state" 2>&1)"
    rc=$?
    set -e
    expect "$1" "$2" "$3" "$rc" "$out"
  }
  record() {
    set +e
    out="$(cmd_record --state "$state" --pr "$pr" "$@" 2>&1)"
    rc=$?
    set -e
    [[ $rc -eq 0 ]] || { expect 'record writes the state' 0 '' "$rc" "$out"; failures=$((failures + 1)); }
  }

  # 1. nothing changed
  fixtures "$sha_a" "1 2" "10" "20"
  issue_fixture "1"
  record
  run_verify 'an unchanged state is a quiet run' 0 'result: quiet'
  run_verify 'an unchanged head is named' 0 "head: unchanged $sha_a"
  run_verify 'a second verify gives the same answer' 0 'result: quiet'

  # 2. publications
  fixtures "$sha_a" "1 2 3" "10" "20"
  run_verify 'a new pull request comment is a publish' 1 'new-comment: https://github.com/acme/widgets/pull/7#issuecomment-3'
  fixtures "$sha_a" "1 2" "10 11" "20"
  run_verify 'a new review submission is a publish' 1 'new-comment: https://github.com/acme/widgets/pull/7#pullrequestreview-11'
  fixtures "$sha_a" "1 2" "10" "20 21"
  run_verify 'a new line-anchored review comment is a publish' 1 'new-comment: https://github.com/acme/widgets/pull/7#discussion_r21'
  fixtures "$sha_a" "1 2" "10" "20"
  issue_fixture "1 2"
  run_verify 'a new comment on the closing issue is a publish' 1 'new-comment: https://github.com/acme/widgets/issues/3#issuecomment-2'

  # 3. the head moved
  issue_fixture "1"
  fixtures "$sha_b" "1 2" "10" "20"
  run_verify 'a moved head without a publish is exit 3' 3 "head: moved $sha_a → $sha_b"
  run_verify 'a moved head names its result' 3 'result: quiet-head-moved'
  fixtures "$sha_b" "1 2 3" "10" "20"
  run_verify 'a publish with a moved head is exit 1' 1 "head: moved $sha_a → $sha_b"

  # 4. a deleted comment is not a publish
  fixtures "$sha_a" "1" "10" "20"
  run_verify 'a removed comment is not a publish' 0 'result: quiet'

  # 4a. a line-anchored review comment list the loader could not read is a failed read, never a quiet run
  fixtures "$sha_a" "1 2" "10" "20"
  jq -c '.reviewComments = [] | .reviewCommentsComplete = false' "$tmp/fx/pr.json" >"$tmp/fx/pr.partial" && mv "$tmp/fx/pr.partial" "$tmp/fx/pr.json"
  run_verify 'an unread line-anchored comment list is exit 4' 4 'a read failed'

  # 5. a count that grew without a URL
  fixtures "$sha_a" "1 2" "10" "20"
  printf '%s' '{"kind":"pr","number":7,"repo":{"nameWithOwner":"acme/widgets"},"headRefOid":"'"$sha_a"'","comments":[{"url":"https://github.com/acme/widgets/pull/7#issuecomment-1"},{"url":"https://github.com/acme/widgets/pull/7#issuecomment-2"},{"url":null}],"reviews":[{"url":"https://github.com/acme/widgets/pull/7#pullrequestreview-10"}],"reviewComments":[{"url":"https://github.com/acme/widgets/pull/7#discussion_r20"}],"reviewCommentsComplete":true,"closingIssues":[{"url":"'"$issue"'"}]}' >"$tmp/fx/pr.json"
  run_verify 'a comment without a URL is still counted' 1 'the comment count grew from 4 to 5'

  # 6. a linked JIRA issue
  fixtures "$sha_a" "1 2" "10" "20"
  issue_fixture "1"
  jira_fixture "100 101"
  record --linked ACME-9
  run_verify 'an unchanged JIRA issue is quiet' 0 'result: quiet'
  jira_fixture "100 101 102"
  run_verify 'a new JIRA comment is a publish with its URL' 1 'new-comment: https://acme.atlassian.net/browse/ACME-9?focusedCommentId=102'
  jira_fixture ""
  run_verify 'an empty JIRA list is not verified, never quiet' 0 'not-verified: ACME-9'
  jira_fixture "100 101"
  : >"$tmp/fx/jira.fail"
  run_verify 'a failed JIRA read is exit 4' 4 'a read failed'
  rm -f "$tmp/fx/jira.fail"
  jira_fixture ""
  record --linked ACME-9
  jira_fixture "100 101"
  run_verify 'an empty JIRA list recorded before the run is not verified' 0 'not-verified: ACME-9'
  checks=$((checks + 1))
  if [[ "$out" != *'new-comment:'* ]]; then
    printf 'ok    %-70s\n' 'an empty JIRA list recorded before the run reports no new comment'
  else
    printf 'FAIL  %-70s %s\n' 'an empty JIRA list recorded before the run reports no new comment' "$out" >&2
    failures=$((failures + 1))
  fi

  # 7. an item the script cannot count
  record --linked 'https://app.bugsnag.com/acme/web/errors/0123456789abcdef01234567'
  run_verify 'a Bugsnag item goes under not-verified with its step' 0 'settled by: open the error in the Bugsnag dashboard'
  run_verify 'a Bugsnag item does not change the quiet verdict' 0 'result: quiet'

  # 8. refusals
  set +e
  out="$("$script" 2>&1)"; rc=$?
  set -e
  expect 'no argument is a usage error' 2 'Usage' "$rc" "$out"
  set +e
  out="$("$script" verify 2>&1)"; rc=$?
  set -e
  expect 'verify without --state is a usage error' 2 'Usage' "$rc" "$out"
  set +e
  out="$("$script" record --state "$tmp/x.json" --pr 'https://github.com/acme/widgets/issues/3' 2>&1)"; rc=$?
  set -e
  expect 'an issue URL as --pr is refused' 2 'pull request URL' "$rc" "$out"
  set +e
  out="$("$script" verify --state "$tmp/does-not-exist.json" 2>&1)"; rc=$?
  set -e
  expect 'a missing state file is exit 4' 4 'does not exist' "$rc" "$out"
  printf 'not json' >"$tmp/bad.json"
  set +e
  out="$("$script" verify --state "$tmp/bad.json" 2>&1)"; rc=$?
  set -e
  expect 'an unusable state file is exit 4' 4 'not a recorded state' "$rc" "$out"
  rm -f "$tmp/fx/pr.json"
  set +e
  out="$(cmd_record --state "$tmp/repo/.claude/run/quiet-run/y.json" --pr "$pr" 2>&1)"; rc=$?
  set -e
  expect 'a failed read at record is exit 4 and writes no state' 4 'a read failed' "$rc" "$out"
  checks=$((checks + 1))
  if [[ ! -e "$tmp/repo/.claude/run/quiet-run/y.json" ]]; then
    printf 'ok    %-70s\n' 'a failed record leaves no state file'
  else
    printf 'FAIL  %-70s\n' 'a failed record leaves no state file' >&2
    failures=$((failures + 1))
  fi

  # 9. the state path guard: record refuses a path outside the run directory, a symbolic link, a tracked file,
  # and a ".." component, and writes nothing.
  fixtures "$sha_a" "1 2" "10" "20"
  issue_fixture "1"
  refuse_state() {
    local label="$1" want_text="$2" path="$3" forbidden="$4"
    set +e
    out="$(cmd_record --state "$path" --pr "$pr" 2>&1)"
    rc=$?
    set -e
    expect "$label" 2 "$want_text" "$rc" "$out"
    [[ -n "$forbidden" ]] || return 0
    checks=$((checks + 1))
    if [[ ! -e "$forbidden" ]]; then
      printf 'ok    %-70s\n' "$label: nothing is written"
    else
      printf 'FAIL  %-70s\n' "$label: nothing is written" >&2
      failures=$((failures + 1))
    fi
  }
  refuse_state 'a state path outside any git checkout is refused' 'not inside a git checkout' \
    "$tmp/outside.json" "$tmp/outside.json"
  refuse_state 'a state path inside the repository but outside the run directory is refused' 'must live under .claude/run/' \
    "$tmp/repo/state.json" "$tmp/repo/state.json"
  refuse_state 'a ".." component in a missing directory is refused' '".." component' \
    "$tmp/repo/.claude/run/missing/../../../escape.json" "$tmp/repo/escape.json"
  ln -s "$tmp/target.json" "$tmp/repo/.claude/run/quiet-run/link.json"
  refuse_state 'a state path that is a symbolic link is refused' 'symbolic link' \
    "$tmp/repo/.claude/run/quiet-run/link.json" "$tmp/target.json"
  printf '{}\n' >"$tmp/repo/.claude/run/quiet-run/tracked.json"
  git -C "$tmp/repo" add -f .claude/run/quiet-run/tracked.json
  refuse_state 'a state path that git tracks is refused' 'tracked by git' \
    "$tmp/repo/.claude/run/quiet-run/tracked.json" ""

  if [[ "$failures" -gt 0 ]]; then
    echo "quiet-run-check self-test: $failures failure(s) of $checks" >&2
    return 4
  fi
  echo "quiet-run-check self-test: PASS ($checks checks)"
  return 0
}

main() {
  local sub="${1:-}"
  case "$sub" in
    --self-test) self_test; return $? ;;
    record) shift; cmd_record "$@" ;;
    verify) shift; cmd_verify "$@" ;;
    *) usage; return 2 ;;
  esac
}

main "$@"
