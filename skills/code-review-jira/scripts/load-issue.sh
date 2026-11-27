#!/usr/bin/env bash
# load-issue.sh — single deterministic entry point for loading JIRA issue context.
#
# Usage:
#   load-issue.sh <KEY|URL> [--known-proof]
#
# Accepts:
#   - a bare issue key, e.g. ACME-1234
#   - a /browse/<KEY> URL, e.g. https://your-company.atlassian.net/browse/ACME-1234
#   - any JIRA URL containing ?selectedIssue=<KEY> (atlOrigin and other query
#     params are tolerated and ignored)
#
# Emits one JSON document on stdout with the following stable shape:
#
#   {
#     "key", "url", "summary", "status", "issueType", "priority",
#     "assignee", "assigneeAccountId", "reporter", "reporterAccountId", "creator", "created", "updated",
#     "resolution", "resolutionDate", "dueDate", "environment",
#     "labels", "components", "fixVersions",
#     "parent":  { "key", "summary", "status" } | null,
#     "project": { "key", "name", "typeKey" }   | null,
#     "watchCount": <int>,
#     "timeTracking": { "originalEstimate", "remainingEstimate", "timeSpent", "ratio" },
#     "descriptionAdf":  <raw ADF or null>,
#     "descriptionText": "<flattened plain text>",
#     "descriptionAssignmentText": "<descriptionText without the Original assignment block>",
#     "descriptionOriginalText": "<the text of the Original assignment block>" | null,
#         (the last block counts as the original only when everything before it is the shape
#          update-description.sh writes, per description-shape.jq; otherwise descriptionOriginalText
#          is null and descriptionAssignmentText is the whole descriptionText)
#     "descriptionSections": [ { "title", "text" } ],
#     "descriptionMediaRefs": [ { "adfId", "altText", "attachmentId" } ],
#     "issueLinks":  [ { "id", "type", "direction", "verb", "linkedKey", "linkedSummary", "linkedStatus", "linkedType" } ],
#     "mentionedKeys": [ "<KEY>" ],
#     "mentionedKeysComplete": <bool>,
#     "descriptionTrusted": <bool>,
#     "subtasks":    [ { "key", "summary", "status", "type",
#                        "descriptionText", "descriptionAdf", "descriptionMediaRefs", "descriptionAssignmentText", "descriptionOriginalText", "descriptionSections",
#                        "comments":    [ { "id", "author", "authorAccountId", "body", "mentionAccountIds", "mediaRefs", "created", "visibility" } ],
#                        "commentsComplete": <bool>,
#                        "attachments": [ { "id", "name", "size", "mimeType", "contentUrl", "author", "created" } ] } ],
#     "comments":    [ { "id", "author", "authorAccountId", "body", "mentionAccountIds", "mediaRefs", "created", "visibility" } ],
#     "commentsComplete": <bool>,
#     "attachments": [ { "id", "name", "size", "mimeType", "contentUrl", "author", "created" } ],  # "id" is a string
#     "customFields":  { "customfield_XXXXX": <parsed value>, … },
#     "devSummary":    { "pullRequestCount", "branchCount", "commitCount", "state", "isStale", "byInstance" } | null,
#     "pullRequests":  [ { "number", "title", "url", "state", "headRefName", "baseRefName", "isDraft", "mergedAt", "author" } ],
#     "attachmentSite": "<configured *.atlassian.net site>" | null,
#     "attachmentsFetch": "skills/code-review-jira/scripts/download-attachments.sh <KEY>" | null
#   }
#
# Notes:
#   - A comment `body` is rendered from the ADF document `workitem view` embeds under
#     `.fields.comment.comments[]`, paired to the `comment list` entry by comment `id`. The list
#     body is flattened by acli itself and drops mentions, emoji, links, and the text of every list
#     item, so a decision written as a bullet is missing from it entirely. The flattened list text is
#     only the fallback for a comment the view did not embed. JIRA may embed fewer comments than
#     the issue carries (`.fields.comment.total` above the embedded count); the loader says so on
#     stderr, and the comments past the embedded page keep the flattened text. `id` is the comment
#     ID, which `delete-owned-comment.sh` and `comment update --id` take.
#   - The ADF renderer keeps every node a reader needs: a mention as its `@Name`, an emoji as its
#     text (the character, or the `:shortcode:` when JIRA stores no character), a smart link as its URL, a linked text as `text (url)`, a status or a date as
#     its value, a list item as `- ` / `1. ` indented two spaces per nesting level, a task as
#     `- [ ]` / `- [x]`, a rule as `---`, a table as `| cell | cell |` rows, and an expand as its
#     title followed by its content. The same renderer produces `descriptionText`.
#   - The three assignment fields follow rules/compound-engineering/assignment.md *The shape in a JIRA
#     description*. When the last top-level node of the description is an expand titled exactly
#     `Original assignment` or `Původní zadání`, and every node before it is the shape
#     update-description.sh writes (one opening paragraph, then collapsed sections with a title of
#     the fixed list of one language, per description-shape.jq), that block is the preserved
#     original: `descriptionOriginalText` is its content, and `descriptionAssignmentText` and
#     `descriptionSections` leave it out. Without such a block `descriptionOriginalText` is null and
#     `descriptionAssignmentText` equals `descriptionText`. `descriptionSections` lists every other
#     top-level expand as its title and its content, in order. A reader takes no requirement from
#     `descriptionOriginalText`.
#   - `authorAccountId` and `mentionAccountIds` carry the JIRA account IDs a rule can compare against
#     `jira_actor_account_id` without a display-name match: `authorAccountId` is the comment
#     author's account ID (comment-list `author.accountId`, or the view-embedded one as a
#     fallback); `mentionAccountIds` is every `mention` node's `attrs.id` found in the comment's ADF
#     body (deduplicated). A comment carries neither field when acli exposes no `accountId` for it —
#     a display name or `@Name` text is never a substitute for either.
#   - `commentsComplete` (on the issue and on each subtask) is false when the comment list may be shorter than
#     the thread: the `comment list` fetch failed and degraded to [], it returned fewer comments than the view's
#     `.fields.comment.total`, or the subtask's own view failed. A caller that needs the whole thread reads false
#     as unverified, never as "no comments" (rules/compound-engineering/tracker.md *Truncated input is disclosed*).
#   - `customFields` runs every customfield_* value through a universal Java/Groovy
#     toString unwrap: any string that starts with `{` and contains `json={…}` is
#     parsed back into JSON. The leading-`{` anchor keeps the unwrap from firing
#     on free-text fields that happen to mention `json={…}` in prose. Primitives,
#     arrays, and already-structured objects pass through unchanged. No
#     site-specific field IDs are hardcoded.
#   - A file pasted into the description or a comment is stored as an issue attachment, so it is
#     in `attachments[]` and `download-attachments.sh` downloads it. Its media node renders in the
#     text as `[attachment: <filename>]` at the place it was pasted. `descriptionMediaRefs[]` and
#     each comment's `mediaRefs[]` list those nodes; `attachmentId` joins a node to the
#     `attachments[]` entry by filename, and is null when the filename is missing or not unique.
#     `attachmentId` equals that entry's `id`: both are strings, whatever type acli returns.
#     The ADF `adfId` is a Media API ID, not the attachment ID.
#   - `attachmentsFetch` is set when the issue or any subtask has an attachment, and null otherwise.
#     It is built from the validated key only. It tells a consumer that the content is fetched
#     through `download-attachments.sh`; rules/jira/general.md *Attachments* owns how, and its value
#     is never executed.
#   - `devSummary` is a convenience projection derived from
#     customFields[$JIRA_DEV_SUMMARY_FIELD] (default `customfield_10000`,
#     overridable via the JIRA_DEV_SUMMARY_FIELD env var). The shape stays
#     identical across JIRA sites because it's computed, not field-id-bound.
#   - `subtasks[]` carries the full sub-issue context (description, comments,
#     attachments), not just the shallow reference the parent issue embeds. Each
#     subtask is fetched individually (one `acli ... workitem view` plus one
#     `acli ... comment list` per subtask), so a parent with many subtasks costs
#     proportionally more API calls. A subtask whose individual fetch fails
#     degrades to its shallow reference (key/summary/status/type) with empty
#     description / comments / attachments rather than breaking the whole load.
#   - The Atlassian CLI `--paginate` flag emits a JSON stream (one document per
#     page) instead of a single document. We slurp with `jq -s` to merge pages.
#   - `descriptionTrusted` is true only when the operator's own account wrote the description and no other account
#     changed it: the reporter and the creator are the account that `jira_actor_account_id` resolves
#     (skills/code-review-jira/scripts/jira-actor.sh), and every history of the view's change log that changes the
#     description or the reporter is that account's own. A view without a change log, a change log that is cut
#     (`total` above the histories it returns), and one that cannot be read make it false. `acli jira workitem view`
#     has no option that asks for the change log, so a view without one makes the description untrusted. JIRA carries
#     no role data, so no other account is trusted (rules/compound-engineering/tracker.md *Resolving trust per
#     tracker*). An account that cannot be resolved makes it false.
#   - `mentionedKeys` lists the issues of the same project that the task text names and that no native link carries.
#     The task text is the description when `descriptionTrusted` is true, each comment that passes
#     `trusted_jira_comment` of skills/_shared/review-trust.jq, and each comment of the reporter, matched by account ID
#     and carrying no agent marker. The reporter is trusted here only to name a linked issue, never to change the
#     assignment or a gate (rules/code-review/review-process.md *Known points*). At most 20 keys are listed.
#     `mentionedKeysComplete` is false when the list reached that cap, the operator account could not be resolved,
#     the comment list is not complete, or the shared trust filter is missing, so a short list is never read as a
#     whole one.
#   - `pullRequests` are resolved via `gh search prs <KEY>` and may include PRs
#     across any GitHub repo the current `gh` auth can see.
#
# Known limitations (intentionally out of scope, fall back to JIRA MCP):
#   - issue changelog (`expand=changelog`)
#   - available next transitions
#   - friendly custom-field names (`expand=names`)
#
# Exit codes:
#   1  usage error (missing or unparseable argument)
#   2  missing required tool (acli, jq)
#   3  JIRA fetch failed
set -euo pipefail

PROG="${0##*/}"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

usage() {
  cat >&2 <<'EOF'
Usage: load-issue.sh <KEY|URL> [--known-proof]
       load-issue.sh --self-test

  KEY    bare JIRA work-item key (e.g. ACME-1234)
  URL    /browse/<KEY> URL or any URL with ?selectedIssue=<KEY>

Env:
  JIRA_SITE                  override the JIRA host (e.g. your-company.atlassian.net)
  JIRA_DEV_SUMMARY_FIELD     customfield id feeding devSummary (default: customfield_10000)
EOF
}

# --- self-test ----------------------------------------------------------
# Exercises authorAccountId / mentionAccountIds end to end through a stubbed
# acli (no network access): a comment whose comment-list author carries an
# accountId, a mention node in its ADF body, and a second comment that falls
# back to the view-embedded author when the comment-list one carries none.
SELF_TEST_TMP=""
cleanup_self_test() {
  if [[ -n "$SELF_TEST_TMP" ]]; then
    rm -rf "$SELF_TEST_TMP"
  fi
}

self_test() {
  local tmp stubbin script out err_file rc failures=0

  tmp="$(mktemp -d)"
  SELF_TEST_TMP="$tmp"
  trap cleanup_self_test EXIT
  stubbin="$tmp/bin"
  mkdir -p "$stubbin"
  err_file="$tmp/err"
  script="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/$PROG"

  cat >"$stubbin/curl" <<'STUB'
#!/usr/bin/env bash
set -euo pipefail
cat >/dev/null
case "${!#}" in
  'https://example.atlassian.net/rest/api/3/myself')
    printf '%s\n' '{"accountId":"actor-1","accountType":"atlassian","active":true,"emailAddress":"operator@example.test"}'
    ;;
  'https://example.atlassian.net/rest/api/3/issue/10001?fields=*all&expand=changelog')
    cat "${0%/*}/known-issue.json"
    ;;
  *) exit 1 ;;
esac
STUB
  chmod +x "$stubbin/curl"

  # The stub reads the case it plays from the `mode` file beside it: ok, list-fails, short, subtasks,
  # subtask-list-fails, attachments, or subtask-attachments.
  printf 'ok' >"$stubbin/mode"
  cat >"$stubbin/acli" <<'STUB'
#!/usr/bin/env bash
set -euo pipefail
mode="$(cat "${0%/*}/mode")"
if [[ "$1 $2 $3" == "jira auth status" ]]; then
  printf '%s\n' 'Site: example.atlassian.net' 'Email: operator@example.test'
  exit 0
fi
if [[ "$1 $2 $3 $4" == "jira workitem view TEST-1" && "$mode" == known ]]; then
  printf '%s\n' '{"id":"10001","key":"TEST-1"}'
  exit 0
fi
if [[ "$1" == "jira" && "$2" == "workitem" && "$3" == "view" && "$4" == "TEST-2" && "$mode" != "subtask-original" ]]; then
  attachments='[]'
  [[ "$mode" != "subtask-attachments" ]] || attachments='[{"id": "10600", "filename": "sub-notes.md"}]'
  printf '%s\n' "{\"fields\": {\"summary\": \"Subtask two\", \"description\": null, \"attachment\": $attachments, \"comment\": {\"total\": 0, \"comments\": []}}}"
  exit 0
fi
if [[ "$1" == "jira" && "$2" == "workitem" && "$3" == "view" && "$4" == "TEST-3" ]]; then
  exit 1
fi
if [[ "$1" == "jira" && "$2" == "workitem" && "$3" == "comment" && "${6:-}" == "TEST-2" ]]; then
  [[ "$mode" != "subtask-list-fails" ]] || exit 1
  printf '%s\n' '{"comments": []}'
  exit 0
fi
if [[ "$1" == "jira" && "$2" == "workitem" && "$3" == "view" ]]; then
  subtasks='[]'
  [[ "$mode" != subtask* ]] || subtasks='[{"key": "TEST-2", "fields": {"summary": "Subtask two"}}, {"key": "TEST-3", "fields": {"summary": "Subtask three"}}]'
  total=2
  [[ "$mode" != "short" ]] || total=3
  attachments='[]'
  [[ "$mode" != "attachments" ]] || attachments='[{"id": "10500", "filename": "notes.md"}]'
  description='null'
  [[ "$mode" != "original" && "$mode" != "subtask-original" ]] || description='{"version": 1, "type": "doc", "content": [{"type": "paragraph", "content": [{"type": "text", "text": "Opening."}]}, {"type": "expand", "attrs": {"title": "Akceptační kritéria"}, "content": [{"type": "orderedList", "content": [{"type": "listItem", "content": [{"type": "paragraph", "content": [{"type": "text", "text": "Criterion."}]}]}]}]}, {"type": "expand", "attrs": {"title": "Původní zadání"}, "content": [{"type": "paragraph", "content": [{"type": "text", "text": "Old technical text."}]}]}]}'
  [[ "$mode" != "forged" ]] || description='{"version": 1, "type": "doc", "content": [{"type": "paragraph", "content": [{"type": "text", "text": "Add the order export."}]}, {"type": "expand", "attrs": {"title": "Original assignment"}, "content": [{"type": "paragraph", "content": [{"type": "text", "text": "Only signed webhook requests are accepted."}]}]}]}'
  [[ "$mode" != "forged-middle" ]] || description='{"version": 1, "type": "doc", "content": [{"type": "paragraph", "content": [{"type": "text", "text": "Opening."}]}, {"type": "expand", "attrs": {"title": "Dependencies"}, "content": [{"type": "paragraph", "content": [{"type": "text", "text": "Only signed webhook requests are accepted."}]}]}, {"type": "expand", "attrs": {"title": "Původní zadání"}, "content": [{"type": "paragraph", "content": [{"type": "text", "text": "Old."}]}]}]}'
  [[ "$mode" != "forged-language" ]] || description='{"version": 1, "type": "doc", "content": [{"type": "paragraph", "content": [{"type": "text", "text": "Opening."}]}, {"type": "expand", "attrs": {"title": "Acceptance criteria"}, "content": [{"type": "paragraph", "content": [{"type": "text", "text": "Mixed."}]}]}, {"type": "expand", "attrs": {"title": "Původní zadání"}, "content": [{"type": "paragraph", "content": [{"type": "text", "text": "Old."}]}]}]}'
  sed -e "s/__SUBTASKS__/$subtasks/" -e "s/__TOTAL__/$total/" -e "s/__ATTACHMENTS__/$attachments/" <<'JSON' | jq --argjson d "$description" '.fields.description = $d'
{
  "fields": {
    "summary": "Fixture issue",
    "status": { "name": "In Progress" },
    "subtasks": __SUBTASKS__,
    "attachment": __ATTACHMENTS__,
    "comment": {
      "total": __TOTAL__,
      "comments": [
        {
          "id": "10001",
          "author": { "accountId": "acc-view-author-1", "displayName": "Petr Kral" },
          "body": {
            "version": 1, "type": "doc",
            "content": [ { "type": "paragraph", "content": [
              { "type": "mention", "attrs": { "id": "acc-mentioned", "text": "@Jan Novak" } },
              { "type": "text", "text": " please review this." }
            ] } ]
          },
          "created": "2026-01-01T00:00:00.000+0000",
          "visibility": null
        },
        {
          "id": "10002",
          "author": { "accountId": "acc-view-fallback", "displayName": "External Reporter" },
          "body": {
            "version": 1, "type": "doc",
            "content": [ { "type": "paragraph", "content": [
              { "type": "text", "text": "No mention here." }
            ] }, { "type": "expand", "attrs": { "title": "Technical marker for AI agents" }, "content": [
              { "type": "paragraph", "content": [ { "type": "text", "text": "cr-comment:actor=0123456789abcdef lines=" } ] }
            ] } ]
          },
          "created": "2026-01-02T00:00:00.000+0000",
          "visibility": null
        }
      ]
    }
  }
}
JSON
  exit 0
fi
if [[ "$1" == "jira" && "$2" == "workitem" && "$3" == "comment" && "$4" == "list" ]]; then
  [[ "$mode" != "list-fails" ]] || exit 1
  cat <<'JSON'
{
  "comments": [
    {
      "id": "10001",
      "author": { "accountId": "acc-operator", "displayName": "Petr Kral" },
      "body": "flattened text mentioning Jan Novak",
      "created": "2026-01-01T00:00:00.000+0000",
      "visibility": null
    },
    {
      "id": "10002",
      "author": { "displayName": "External Reporter" },
      "body": "No mention here (flattened).",
      "created": "2026-01-02T00:00:00.000+0000",
      "visibility": null
    }
  ]
}
JSON
  exit 0
fi
exit 1
STUB
  chmod +x "$stubbin/acli"

  cat >"$stubbin/gh" <<'STUB'
#!/usr/bin/env bash
printf '%s\n' '[]'
STUB
  chmod +x "$stubbin/gh"

  if [[ "$(PATH="$stubbin:$PATH" command -v acli)" != "$stubbin/acli" ]]; then
    echo "load-issue.sh self-test: the stand-in acli is not first in PATH; nothing was run" >&2
    return 4
  fi

  set +e
  out="$(PATH="$stubbin:$PATH" "$script" TEST-1 2>"$err_file")"
  rc=$?
  set -e

  if [[ "$rc" -ne 0 ]]; then
    printf 'FAIL  %-60s exit %s (stderr: %s)\n' 'load succeeds against the stubbed acli' "$rc" "$(cat "$err_file")" >&2
    failures=$((failures + 1))
  else
    printf 'ok    %-60s exit 0\n' 'load succeeds against the stubbed acli'
  fi

  check() {
    local label="$1" filter="$2" expected="$3" actual
    actual="$(printf '%s' "$out" | jq -r "$filter" 2>/dev/null || echo '<jq error>')"
    if [[ "$actual" != "$expected" ]]; then
      printf 'FAIL  %-60s got %s, expected %s\n' "$label" "$actual" "$expected" >&2
      failures=$((failures + 1))
      return
    fi
    printf 'ok    %-60s %s\n' "$label" "$actual"
  }

  check 'comment count' '.comments | length' '2'
  check 'authorAccountId taken from the comment-list author' '.comments[0].authorAccountId' 'acc-operator'
  check 'mentionAccountIds extracted from the ADF body' '.comments[0].mentionAccountIds | join(",")' 'acc-mentioned'
  check 'authorAccountId falls back to the view-embedded author' '.comments[1].authorAccountId' 'acc-view-fallback'
  check 'mentionAccountIds is empty with no mention node' '.comments[1].mentionAccountIds | length' '0'
  check 'a marker inside a collapsed block stays in the body' '.comments[1].body | contains("cr-comment:actor=0123456789abcdef")' 'true'
  check 'a whole comment thread is complete' '.commentsComplete' 'true'
  check 'an issue without attachments has no attachmentsFetch' '.attachmentsFetch' 'null'

  # load_case <mode> — load the fixture again with the stub playing <mode>; the result goes to $out.
  load_case() {
    printf '%s' "$1" >"$stubbin/mode"
    out="$(PATH="$stubbin:$PATH" "$script" TEST-1 2>/dev/null || true)"
  }
  load_case list-fails
  check 'a failed comment list degrades to an empty list' '.comments | length' '0'
  check 'a failed comment list is reported as incomplete' '.commentsComplete' 'false'
  load_case short
  check 'fewer comments than the view total are reported as incomplete' '.commentsComplete' 'false'
  load_case subtasks
  check 'a subtask with a whole comment list is complete' '.subtasks[0].commentsComplete' 'true'
  check 'a subtask whose own view failed is reported as incomplete' '.subtasks[1].commentsComplete' 'false'
  load_case subtask-list-fails
  check 'a subtask whose comment list failed is reported as incomplete' '.subtasks[0].commentsComplete' 'false'
  check 'subtasks without attachments leave attachmentsFetch null' '.attachmentsFetch' 'null'
  load_case attachments
  check 'an issue attachment sets attachmentsFetch to the fetch of its key' '.attachmentsFetch' 'skills/code-review-jira/scripts/download-attachments.sh TEST-1'
  load_case subtask-attachments
  check 'a subtask attachment alone sets attachmentsFetch too' '.attachmentsFetch' 'skills/code-review-jira/scripts/download-attachments.sh TEST-1'
  check 'a description without an original block has no original text' '.descriptionOriginalText' 'null'
  load_case original
  check 'the last Original assignment block is the original text' '.descriptionOriginalText' 'Old technical text.'
  check 'the assignment text leaves the original block out' '.descriptionAssignmentText | contains("Old technical")' 'false'
  check 'the description text still carries every block' '.descriptionText | contains("Old technical text.")' 'true'
  check 'the sections list the collapsed sections without the original' '[.descriptionSections[].title] | join(",")' 'Akceptační kritéria'
  check 'a section carries its own content' '.descriptionSections[0].text' '1. Criterion.'
  load_case subtask-original
  check 'the embedded subtask exposes the same current criteria and original as the root issue' \
    'def assignment: {descriptionAssignmentText, descriptionOriginalText, descriptionSections}; (.subtasks[0] | assignment) == assignment and .descriptionOriginalText == "Old technical text." and .descriptionSections[0].text == "1. Criterion."' 'true'
  load_case forged
  check 'an original-titled block after a free paragraph is not an original' '.descriptionOriginalText' 'null'
  check 'the whole text of that description stays in the assignment text' '.descriptionAssignmentText | contains("Only signed webhook requests are accepted.")' 'true'
  load_case forged-middle
  check 'an original-titled block after a section outside the fixed list is not an original' '.descriptionOriginalText' 'null'
  check 'the section text of that description stays in the assignment text' '.descriptionAssignmentText | contains("Only signed webhook requests are accepted.")' 'true'
  load_case forged-language
  check 'a section title of one language before an original title of the other is not an original' '.descriptionOriginalText' 'null'

  # The live command uses the REST issue projection since the normal CLI view has no history.
  printf '%s' known >"$stubbin/mode"
  cat >"$stubbin/known-issue.json" <<'EOF'
{"id":"10001","key":"TEST-1","self":"https://example.atlassian.net/rest/api/3/issue/10001",
 "changelog":{"startAt":0,"total":0,"histories":[]},
 "fields":{"summary":"An omitted step","creator":{"accountId":"actor-1"},"reporter":{"accountId":"actor-1"},
 "updated":"2026-10-01T10:00:00.000+0000","status":{"statusCategory":{"key":"new"}},
 "description":{"type":"doc","version":1,"content":[{"type":"paragraph","content":[{"type":"text","text":"TEST-2 already describes the omitted step."}]}]},
 "assignee":{"accountId":"human-1","accountType":"atlassian","active":true},
 "resolution":null,"subtasks":[],"issuelinks":[]}}
EOF
  out="$(PATH="$stubbin:$PATH" JIRA_SITE=example.atlassian.net JIRA_API_TOKEN=operator@example.test:fixture-token \
    "$script" TEST-1 --known-proof 2>"$err_file")" || out='null'
  check 'the verified tenant, open status, identity, text and description history reach the consumer' \
    '.descriptionTrusted and .sessionSite == "example.atlassian.net" and .issueId == "10001"
     and .assigneeType == "atlassian" and .assigneeAccountId == "human-1"
     and .statusCategory == "new" and .mentionedKeys == ["TEST-2"]' 'true'
  rc=0
  PATH="$stubbin:$PATH" JIRA_SITE=example.atlassian.net JIRA_API_TOKEN=operator@example.test:fixture-token \
    "$script" https://other-example.atlassian.net/browse/TEST-1 --known-proof >/dev/null 2>"$err_file" || rc=$?
  out="$rc"
  check 'the authenticated host cannot be relabelled by a ticket URL' '.' '3'
  jq -c '.changelog.total = 1' "$stubbin/known-issue.json" >"$stubbin/incomplete.json"
  mv "$stubbin/incomplete.json" "$stubbin/known-issue.json"
  rc=0
  PATH="$stubbin:$PATH" JIRA_SITE=example.atlassian.net JIRA_API_TOKEN=operator@example.test:fixture-token \
    "$script" TEST-1 --known-proof >/dev/null 2>"$err_file" || rc=$?
  out="$rc"
  check 'an incomplete issue history does not prove the text' '.' '3'

  if [[ "$failures" -gt 0 ]]; then
    echo "load-issue.sh self-test: $failures failure(s)" >&2
    return 4
  fi
  echo 'load-issue.sh self-test: PASS'
  return 0
}

if [[ "${1:-}" == "--self-test" ]]; then
  self_test
  exit $?
fi

if [[ $# -lt 1 || $# -gt 2 || -z "${1:-}" || ( $# -eq 2 && "$2" != --known-proof ) ]]; then
  usage
  exit 1
fi

INPUT="$1"
KNOWN_PROOF="${2:-}"

for bin in acli jq; do
  if ! command -v "$bin" >/dev/null 2>&1; then
    echo "load-issue.sh: required tool not found: $bin" >&2
    exit 2
  fi
done

extract_key_from_url() {
  local url="$1"
  local key
  key="$(printf '%s' "$url" | grep -oE '[?&]selectedIssue=[A-Z][A-Z0-9_]+-[0-9]+' | sed -nE '1s/^[?&]selectedIssue=//p')"
  if [[ -n "$key" ]]; then
    printf '%s' "$key"
    return 0
  fi
  key="$(printf '%s' "$url" | grep -oE '/browse/[A-Z][A-Z0-9_]+-[0-9]+' | sed -nE '1s#^/browse/##p')"
  if [[ -n "$key" ]]; then
    printf '%s' "$key"
    return 0
  fi
  return 1
}

extract_host_from_url() {
  local url="$1"
  printf '%s' "$url" | sed -nE 's#^https?://([^/]+)/.*#\1#p'
}

KEY=""
HOST_FROM_URL=""

if [[ "$INPUT" =~ ^[A-Z][A-Z0-9_]+-[0-9]+$ ]]; then
  KEY="$INPUT"
elif [[ "$INPUT" =~ ^https?:// ]]; then
  if ! KEY="$(extract_key_from_url "$INPUT")"; then
    echo "load-issue.sh: could not extract a JIRA key from URL: $INPUT" >&2
    exit 1
  fi
  HOST_FROM_URL="$(extract_host_from_url "$INPUT")"
else
  echo "load-issue.sh: argument must be a bare key or a URL: $INPUT" >&2
  exit 1
fi

# The configured site: JIRA_SITE, then the site of the current acli profile.
resolve_configured_site() {
  if [[ -n "${JIRA_SITE:-}" ]]; then
    printf '%s' "$JIRA_SITE"
    return 0
  fi
  local config="${HOME}/.config/acli/jira_config.yaml"
  if [[ -f "$config" ]]; then
    awk '/^current_profile:/ { cp=$2 } /^[[:space:]]*-[[:space:]]*site:/ { site=$3 } /^[[:space:]]*cloud_id:/ { if ($2 ":" account_id == cp) { print site; exit } cid=$2 } /^[[:space:]]*account_id:/ { account_id=$2; if (cid ":" account_id == cp) { print site; exit } }' "$config" | head -n1
  fi
}

resolve_host() {
  if [[ -n "$HOST_FROM_URL" ]]; then
    printf '%s' "$HOST_FROM_URL"
    return 0
  fi
  resolve_configured_site
}

HOST="$(resolve_host || true)"
# The attachment download host comes only from the configured site, never from the URL argument:
# the URL can come from untrusted text, and download-attachments.sh sends the API token to this host.
CONTENT_HOST="$(resolve_configured_site || true)"
CONTENT_HOST="$(printf '%s' "$CONTENT_HOST" | LC_ALL=C tr '[:upper:]' '[:lower:]')"
if [[ ! "$CONTENT_HOST" =~ ^[a-z0-9][a-z0-9-]*\.atlassian\.net$ ]]; then
  CONTENT_HOST=""
fi
DEV_FIELD="${JIRA_DEV_SUMMARY_FIELD:-customfield_10000}"

VIEW_JSON="$(acli jira workitem view "$KEY" --fields '*all' --json 2>/dev/null || true)"
if [[ -n "$KNOWN_PROOF" ]]; then
  # The normal acli view cannot request the changelog. Use the REST projection for a review decision.
  # shellcheck source=jira-known-proof.sh
  . "$SCRIPT_DIR/jira-known-proof.sh"
  if ! VIEW_JSON="$(jira_known_view "$KEY" "$HOST" "$CONTENT_HOST" "$VIEW_JSON")"; then
    echo "load-issue.sh: could not verify the tenant, issue, and description history for $KEY" >&2
    exit 3
  fi
fi
if [[ -z "$VIEW_JSON" ]] || ! printf '%s' "$VIEW_JSON" | jq -e . >/dev/null 2>&1; then
  echo "load-issue.sh: failed to fetch JIRA issue $KEY" >&2
  exit 3
fi

# The issue view can embed fewer comments than the issue carries. Disclose it rather than let a
# flattened fallback body pass for the full ADF one.
VIEW_COMMENT_GAP="$(printf '%s' "$VIEW_JSON" | jq -r '((.fields.comment.total // 0) | tonumber? // 0) - ((.fields.comment.comments // []) | length)' 2>/dev/null || true)"
if [[ "$VIEW_COMMENT_GAP" =~ ^[1-9][0-9]*$ ]]; then
  echo "load-issue.sh: the issue view embedded $VIEW_COMMENT_GAP fewer comments than $KEY carries; those comments keep the flattened comment-list text" >&2
fi

# `|| printf` would append the fallback to whatever jq had already written, leaving two JSON
# documents in one variable and breaking the later JSON projection — the load died where it was
# documented to degrade. Take whatever the pipeline produced, then validate it.
# The acli exit status is read on its own: piped into `jq -s`, a failed fetch would come out as a valid empty list.
# A failed or unparseable fetch degrades to an empty list, and COMMENTS_FETCHED records that it did.
merge_comment_pages() {
  jq -s '{ comments: ([ .[].comments // [] ] | add // []) }' 2>/dev/null
}
COMMENTS_FETCHED=false
COMMENTS_JSON='{"comments": []}'
if COMMENTS_RAW="$(acli jira workitem comment list --key "$KEY" --json --paginate 2>/dev/null)" \
  && COMMENTS_PARSED="$(printf '%s' "$COMMENTS_RAW" | merge_comment_pages)" && [[ -n "$COMMENTS_PARSED" ]]; then
  COMMENTS_JSON="$COMMENTS_PARSED"
  COMMENTS_FETCHED=true
fi

# Fetch the full context of every subtask (description, comments, attachments).
# The parent issue only embeds a shallow subtask reference, so each subtask is
# loaded individually and accumulated into an object keyed by subtask key. A
# failed individual fetch is skipped, leaving that subtask with its shallow
# reference only.
SUBTASK_DETAILS='{}'
SUBTASK_KEYS="$(printf '%s' "$VIEW_JSON" | jq -r '(.fields.subtasks // [])[] | .key // empty' 2>/dev/null || true)"
if [[ -n "$SUBTASK_KEYS" ]]; then
  while IFS= read -r SUBTASK_KEY; do
    [[ -z "$SUBTASK_KEY" ]] && continue
    SUBTASK_VIEW="$(acli jira workitem view "$SUBTASK_KEY" --fields '*all' --json 2>/dev/null || true)"
    if [[ -z "$SUBTASK_VIEW" ]] || ! printf '%s' "$SUBTASK_VIEW" | jq -e . >/dev/null 2>&1; then
      continue
    fi
    SUBTASK_FETCHED=false
    SUBTASK_COMMENTS='{"comments": []}'
    if SUBTASK_RAW="$(acli jira workitem comment list --key "$SUBTASK_KEY" --json --paginate 2>/dev/null)" \
      && SUBTASK_PARSED="$(printf '%s' "$SUBTASK_RAW" | merge_comment_pages)" && [[ -n "$SUBTASK_PARSED" ]]; then
      SUBTASK_COMMENTS="$SUBTASK_PARSED"
      SUBTASK_FETCHED=true
    fi
    SUBTASK_DETAILS="$(printf '%s\n' "$SUBTASK_DETAILS" "$SUBTASK_VIEW" "$SUBTASK_COMMENTS" | jq -cn \
      --arg key "$SUBTASK_KEY" --argjson fetched "$SUBTASK_FETCHED" \
      'input as $acc | input as $view | input as $commentsResp
       | $acc + { ($key): { view: $view, comments: ($commentsResp.comments // []), fetched: $fetched } }' 2>/dev/null || printf '%s' "$SUBTASK_DETAILS")"
  done <<< "$SUBTASK_KEYS"
fi

PRS_JSON='[]'
if command -v gh >/dev/null 2>&1; then
  PRS_JSON="$(gh search prs "$KEY" \
      --json number,title,url,state,headRefName,baseRefName,isDraft,mergedAt,author \
      --limit 50 2>/dev/null || printf '[]')"
  if ! printf '%s' "$PRS_JSON" | jq -e . >/dev/null 2>&1; then
    PRS_JSON='[]'
  fi
fi

DOC="$(printf '%s\n' "$VIEW_JSON" "$COMMENTS_JSON" "$SUBTASK_DETAILS" "$PRS_JSON" | jq -n -L "$SCRIPT_DIR" \
  --arg key "$KEY" \
  --arg host "$HOST" \
  --arg contentHost "$CONTENT_HOST" \
  --arg devField "$DEV_FIELD" \
  --argjson commentsFetched "$COMMENTS_FETCHED" '
include "description-shape";

def tryParseTrimEnd:
  . as $s
  | { s: $s, parsed: null }
  | until(.parsed != null or (.s | length) == 0;
      .s |= .[0:length-1]
      | .parsed = (.s | fromjson? // null))
  | .parsed;

def unwrapJavaToString:
  if type == "string" and test("^\\{.*json=\\{") then
    (capture("json=(?<j>\\{.*\\})") | .j) as $candidate
    | (($candidate | fromjson?) // ($candidate | tryParseTrimEnd) // .)
  else .
  end;

# `adfInline` renders one inline node. Nothing a reader needs is dropped: a mention keeps its
# `@Name`, an emoji its text or `:shortcode:`, a smart link its URL, a linked text its target, and a status
# lozenge or a date its value — the flattened `comment list` text loses exactly these nodes.
def adfInline:
  if type != "object" then ""
  elif .type == "text" then
    (.text // "") as $t
    | ([(.marks // [])[] | select(.type == "link") | .attrs.href // empty] | first) as $href
    | if $href != null and $href != $t then $t + " (" + $href + ")" else $t end
  elif .type == "hardBreak" then "\n"
  elif .type == "mention" then (.attrs.text // "")
  elif .type == "emoji" then (.attrs.text // .attrs.shortName // "")
  elif (.type // "") | IN("inlineCard","blockCard","embedCard") then (.attrs.url // "")
  elif .type == "status" then (.attrs.text // "")
  elif (.type // "") | IN("media","mediaInline") then "[attachment: " + (.attrs.alt // .attrs.url // "unnamed") + "]"
  elif .type == "date" then
    ((.attrs.timestamp // "") | tostring) as $raw
    | ($raw | tonumber? // null) as $ms
    | if $ms == null then $raw
      else (try ($ms / 1000 | floor | strftime("%Y-%m-%d")) catch $raw) end
  else ((.content // []) | map(adfInline) | join(""))
  end;

# `adfBlock($indent)` renders one block node as lines. A list item carries its `- ` / `1. ` marker,
# and a nested list is indented by two spaces per level, so a decision written as a bullet survives.
def adfBlock($indent):
  if type != "object" then ""
  elif (.type // "") | IN("bulletList","orderedList","taskList","decisionList") then
    .type as $listType
    | ((.attrs.order // 1) | tonumber? // 1) as $start
    | (.content // []) | to_entries
    | map(
        (if $listType == "orderedList" then (($start + .key) | tostring) + ". "
         elif $listType == "taskList" then (if .value.attrs.state == "DONE" then "- [x] " else "- [ ] " end)
         else "- " end) as $marker
        | .value as $item
        | ($item.content // []) as $children
        | if ($item.type // "") | IN("taskItem","decisionItem") then
            $indent + $marker + ($item | adfInline) + "\n"
          elif ($children | length) > 0 and ($children[0].type // "") == "paragraph" then
            $indent + $marker + ($children[0] | adfInline) + "\n"
            + ($children[1:] | map(adfBlock($indent + "  ")) | join(""))
          else
            $indent + $marker + "\n" + ($children | map(adfBlock($indent + "  ")) | join(""))
          end)
    | join("")
  elif .type == "listItem" then ({ type: "bulletList", content: [.] } | adfBlock($indent))
  elif (.type // "") | IN("paragraph","heading","codeBlock") then $indent + adfInline + "\n"
  elif .type == "rule" then $indent + "---\n"
  elif (.type // "") | IN("blockCard","embedCard") then $indent + adfInline + "\n"
  elif (.type // "") | IN("mediaSingle","mediaGroup") then $indent + ((.content // []) | map(adfInline) | join(" ")) + "\n"
  elif .type == "table" then
    (.content // [])
    | map($indent + "| "
          + ((.content // [])
             | map((.content // []) | map(adfBlock("")) | join(" ")
                   | gsub("\\s*\n\\s*"; " ") | sub("^\\s+"; "") | sub("\\s+$"; ""))
             | join(" | "))
          + " |\n")
    | join("")
  elif (.type // "") | IN("expand","nestedExpand") then
    (if (.attrs.title // "") != "" then $indent + .attrs.title + "\n" else "" end)
    + ((.content // []) | map(adfBlock($indent)) | join(""))
  elif (.type // "") | IN("text","hardBreak","mention","emoji","inlineCard","status","date","media","mediaInline") then adfInline
  else ((.content // []) | map(adfBlock($indent)) | join(""))
  end;

def adfText: adfBlock("");

def adfPlain: adfText | gsub("\n\n+"; "\n\n") | sub("\n+$"; "");

# The comment body prefers the ADF document `workitem view` embeds for the same comment `id`. The
# `comment list` body is the flattening acli does itself, which drops mentions, emoji, and every list item —
# a decision written as a bullet disappears from it entirely. The flattened text stays the fallback
# for a comment the view did not embed.

# `attachmentId` is the id of an attachment as a string, or null when it has none. acli returns the id
# as a number or as a string depending on the endpoint, so `attachments[].id` and the `attachmentId`
# of a media reference both go through it and a consumer can join them with `==`.
def attachmentId: if (.id // null) == null then null else (.id | tostring) end;

# `attachmentContentUrl` serves the attachment content through the configured JIRA site. acli can
# return the URL on an internal Atlassian host (`*.prod.atl-paas.net`) that a client outside Atlassian
# cannot reach; the path is the same on the site. Only such an internal URL is rewritten, and only to
# the configured `*.atlassian.net` site; every other URL stays as returned.
def attachmentContentUrl:
  (.content // null) as $u
  | if $u == null or $contentHost == "" then $u
    else ($u | capture("^https://[a-z0-9.-]+\\.prod\\.atl-paas\\.net(?<path>/rest/api/3/attachment/content/[0-9]+)$")? // null) as $m
    | if $m == null then $u else "https://" + $contentHost + $m.path end
    end;

# `mediaRefs($atts)` lists every media node of an ADF document. `attachmentId` joins the node to the
# issue attachment whose filename equals the `alt` of the node, and stays null when no attachment or more
# than one attachment carries that filename.
def mediaRefs($atts):
  [ .. | objects | select((.type // "") | IN("media","mediaInline"))
    | (.attrs.alt // null) as $alt
    | ([$atts[] | select($alt != null and .filename == $alt) | attachmentId]) as $hits
    | { adfId: (.attrs.id // null),
        altText: $alt,
        attachmentId: (if ($hits | length) == 1 then $hits[0] else null end) } ];

def commentOut($viewIdx; $atts):
  . as $c
  | (($c.id // "") | tostring) as $id
  | ($viewIdx[$id] // {}) as $v
  | {
      id: (if $id == "" then null else $id end),
      author: (if ($c.author | type) == "object" then ($c.author.displayName // null) else $c.author end),
      authorAccountId: ($c.author.accountId? // $v.author.accountId? // null),
      body: (if ($v.body | type) == "object" then ($v.body | adfPlain)
             elif ($c.body | type) == "object" then ($c.body | adfPlain)
             else $c.body end),
      mentionAccountIds: ([($v.body // $c.body) | .. | objects | select(.type == "mention") | .attrs.id? // empty] | unique),
      mediaRefs: (($v.body // $c.body) as $b | if ($b | type) == "object" then ($b | mediaRefs($atts)) else [] end),
      created: ($c.created // $v.created // null),
      visibility: (if ($c.visibility | type) == "object" then $c.visibility.value else ($c.visibility // $v.visibility // null) end)
    };

# A comment list is complete only when its fetch did not degrade to [] and it holds at least the number of
# comments the issue view reports in `.fields.comment.total`.
def commentsComplete($fetched; $list; $fields):
  $fetched == true and (($list | length) >= ((($fields.comment.total // 0) | tonumber? // 0)));

def viewCommentIdx:
  ((.comment.comments // []) | map({ key: ((.id // "") | tostring), value: { body: .body, created: .created, visibility: (.visibility.value // null), author: .author } }) | from_entries);

def description($attachments):
  . as $doc
  | (if type == "object" then (.content // []) else [] end) as $nodes
  | (if $nodes | hasWrittenShape then $nodes[-1] else null end) as $original
  | (if $original == null then $nodes else $nodes[:-1] end) as $own
  | (if $doc == null then "" else ($doc | adfPlain) end) as $text
  | { descriptionAdf: $doc, descriptionText: $text, descriptionMediaRefs: mediaRefs($attachments),
      descriptionAssignmentText: (if $original == null then $text else ({type:"doc", content:$own} | adfPlain) end),
      descriptionOriginalText: (if $original == null then null else ({type:"doc", content:$original.content} | adfPlain) end),
      descriptionSections: [$own[] | select(type == "object" and .type == "expand")
        | {title: (.attrs.title // ""), text: ({type:"doc", content:(.content // [])} | adfPlain)}] };

input as $view | input as $commentsResp | input as $subtaskDetails | input as $prs
| ($view.fields // {}) as $f
| ($f.attachment // []) as $att
| ($f.subtasks // []) as $sub
| ($f.issuelinks // []) as $links
| ($f | viewCommentIdx) as $viewCommentIdx
| ($f | to_entries
       | map(select(.key | startswith("customfield_")))
       | map({ key: .key, value: (.value | unwrapJavaToString) })
       | from_entries) as $cf
| ($cf[$devField] // null) as $devRaw
| (if ($devRaw | type) == "object" and ($devRaw.cachedValue.summary // null) != null
    then $devRaw.cachedValue.summary
    else null
   end) as $devCached
| ($f.description // null) as $desc
| {
    key: $key,
    url: (if $host != "" then "https://" + $host + "/browse/" + $key else null end),
    summary: ($f.summary // null),
    status: ($f.status.name // null),
    statusCategory: ($f.status.statusCategory.key // null),
    issueId: ($view.id // null),
    sessionSite: ($view.knownSite // null),
    assigneeType: ($f.assignee.accountType // null),
    assigneeActive: $f.assignee.active,
    issueType: ($f.issuetype.name // null),
    priority: ($f.priority.name // null),
    assignee: ($f.assignee.displayName // null),
    assigneeAccountId: ($f.assignee.accountId // null),
    reporter: ($f.reporter.displayName // null),
    reporterAccountId: ($f.reporter.accountId // null),
    creator: ($f.creator.displayName // null),
    created: ($f.created // null),
    updated: ($f.updated // null),
    resolution: ($f.resolution.name // null),
    resolutionDate: ($f.resolutiondate // null),
    dueDate: ($f.duedate // null),
    environment: ($f.environment // null),
    labels: ($f.labels // []),
    components: ($f.components // [] | map(.name)),
    fixVersions: ($f.fixVersions // [] | map(.name)),
    parent: (if $f.parent then { key: $f.parent.key, summary: ($f.parent.fields.summary // null), status: ($f.parent.fields.status.name // null) } else null end),
    project: (if $f.project then { key: $f.project.key, name: $f.project.name, typeKey: ($f.project.projectTypeKey // null) } else null end),
    watchCount: ($f.watches.watchCount // 0),
    timeTracking: {
      originalEstimate: ($f.timetracking.originalEstimate // null),
      remainingEstimate: ($f.timetracking.remainingEstimate // null),
      timeSpent: ($f.timetracking.timeSpent // null),
      ratio: ($f.workratio // null)
    },
    issueLinks: ($links | map(
      if .outwardIssue then
        { id: .id, type: (.type.name // null), direction: "outward",
          verb: (.type.outward // null),
          linkedKey: .outwardIssue.key,
          linkedSummary: (.outwardIssue.fields.summary // null),
          linkedStatus: (.outwardIssue.fields.status.name // null),
          linkedType: (.outwardIssue.fields.issuetype.name // null) }
      else
        { id: .id, type: (.type.name // null), direction: "inward",
          verb: (.type.inward // null),
          linkedKey: (.inwardIssue.key // null),
          linkedSummary: (.inwardIssue.fields.summary // null),
          linkedStatus: (.inwardIssue.fields.status.name // null),
          linkedType: (.inwardIssue.fields.issuetype.name // null) }
      end)),
    subtasks: ($sub | map(. as $st
      | ($subtaskDetails[$st.key] // null) as $d
      | ($d.view.fields // null) as $sf
      | ($sf.description // null) as $sdesc
      | {
          key: $st.key,
          summary: ($st.fields.summary // null),
          status: ($st.fields.status.name // null),
          type: ($st.fields.issuetype.name // null),
          comments: (if $d == null then [] else (($sf | viewCommentIdx) as $subIdx | ($d.comments // []) | map(commentOut($subIdx; $sf.attachment // []))) end),
          commentsComplete: (if $d == null then false else commentsComplete($d.fetched; $d.comments // []; $sf) end),
          attachments: (if $sf == null then [] else (($sf.attachment // []) | map({
            id: attachmentId,
            name: (.filename // null),
            size: (.size // null),
            mimeType: (.mimeType // null),
            contentUrl: attachmentContentUrl,
            author: (if (.author | type) == "object" then (.author.displayName // null) else .author end),
            created: (.created // null)
          })) end)
        } + ($sdesc | description($sf.attachment // [])))),
    comments: ($commentsResp.comments // [] | map(commentOut($viewCommentIdx; $att))),
    commentsComplete: commentsComplete($commentsFetched; $commentsResp.comments // []; $f),
    attachments: ($att | map({
      id: attachmentId,
      name: (.filename // null),
      size: (.size // null),
      mimeType: (.mimeType // null),
      contentUrl: attachmentContentUrl,
      author: (if (.author | type) == "object" then (.author.displayName // null) else .author end),
      created: (.created // null)
    })),
    customFields: $cf,
    devSummary: (if $devCached == null then null else {
      pullRequestCount: ($devCached.pullrequest.overall.count // 0),
      branchCount: ($devCached.branch.overall.count // 0),
      commitCount: ($devCached.repository.overall.count // 0),
      state: ($devCached.pullrequest.overall.state // null),
      isStale: (($devCached.pullrequest.overall.stateCount // 0) == 0),
      byInstance: ($devCached.pullrequest.byInstanceType // null)
    } end),
    attachmentSite: (if $contentHost == "" then null else $contentHost end),
    pullRequests: ($prs | map({
      number: .number,
      title: .title,
      url: .url,
      state: .state,
      headRefName: .headRefName,
      baseRefName: .baseRefName,
      isDraft: .isDraft,
      mergedAt: .mergedAt,
      author: (.author.login // null)
    }))
  } + ($desc | description($att))
| .attachmentsFetch = (if ((.attachments | length) + ([.subtasks[].attachments[]] | length)) > 0
    then "skills/code-review-jira/scripts/download-attachments.sh " + $key
    else null end)
')"

# The issues of the same project that the task text names (the header above). The trust test is the shared one, so
# a change to it reaches this list too. Keys the issue already links as its parent, a subtask, or an issue link are
# left out, so the list holds only what the native links do not.
SHARED_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/../../_shared"
# shellcheck source=jira-actor.sh
. "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/jira-actor.sh"
if [[ -n "$KNOWN_PROOF" ]]; then
  ACTOR_ID="$(jq -r '.knownActor' <<<"$VIEW_JSON")"
else
  ACTOR_ID="$(jira_actor_account_id || true)"
fi
# Who wrote and who changed the description: the creator and the change log of the view, when the view carries them.
DESCRIPTION_META="$(printf '%s' "$VIEW_JSON" | jq -c '{creator: (.fields.creator.accountId? // null), changelog: (.changelog? // null)}' 2>/dev/null || true)"
[[ -n "$DESCRIPTION_META" ]] || DESCRIPTION_META='null'
if [[ -f "$SHARED_DIR/review-trust.jq" ]] && OUT="$(printf '%s' "$DOC" | jq -L "$SHARED_DIR" --arg actor "$ACTOR_ID" --arg mode "$KNOWN_PROOF" --argjson meta "$DESCRIPTION_META" '
  include "review-trust";
  . as $o
  # The description is the text of the operator account alone: it created the issue, it is the reporter, and it made
  # every change of the description or the reporter in a complete change log. What the view does not prove is untrusted.
  | ($actor != "" and ($o.reporterAccountId | type) == "string" and $o.reporterAccountId == $actor
     and ($meta | type) == "object" and $meta.creator == $actor
     and ($meta.changelog | type) == "object" and ($meta.changelog.histories | type) == "array"
     and ($meta.changelog.startAt // 0) == 0
     and ($meta.changelog.total // ($meta.changelog.histories | length)) == ($meta.changelog.histories | length)
     and all($meta.changelog.histories[];
       .author.accountId? == $actor
       or ([(.items // [])[] | ((.fieldId // .field // "") | ascii_downcase)] | any(. == "description" or . == "reporter") | not))) as $trusted
  | (($o.key | sub("-[0-9]+$"; "")) as $project
     | [ [ if $trusted then $o.descriptionText else empty end ]
         + [ $o.comments[]
             | select(if $mode == "--known-proof" then trusted_jira_comment($actor; null)
                      else trusted_jira_comment($actor; $o.assignee)
                        or ((.body | type) == "string" and (.body | agent_marker | not)
                            and ($o.reporterAccountId | type) == "string" and $o.reporterAccountId != ""
                            and .authorAccountId == $o.reporterAccountId)
                      end)
             | .body ]
         | .[] | select(type == "string") | scan("(?<![A-Za-z0-9_-])(\($project)-[0-9]{1,9})(?![0-9A-Za-z_-])") | .[0] ]
     | unique
     | map(select(. != $o.key and . != ($o.parent.key // "")
                  and (. as $k | ([$o.subtasks[].key] + [$o.issueLinks[].linkedKey]) | index([$k]) == null)))) as $mentioned
  | .descriptionTrusted = $trusted
  | .mentionedKeys = $mentioned[0:20]
  | .mentionedKeysComplete = (($mentioned | length) <= 20 and $actor != "" and $o.commentsComplete == true)' 2>/dev/null)"; then
  printf '%s\n' "$OUT"
else
  printf '%s' "$DOC" | jq '. + {descriptionTrusted: false, mentionedKeys: [], mentionedKeysComplete: false}'
fi
