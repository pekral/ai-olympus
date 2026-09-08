#!/usr/bin/env bash
# upsert-comment.sh — update-in-place JIRA issue comment publisher used by
# CR-track skills. Each invocation looks for a comment already carrying this
# actor's marker on the target issue and updates the newest match; only when no
# match exists does it create a new comment. One issue therefore keeps one
# permanent CR comment per actor instead of a growing chain.
#
# This reverses the always-new behaviour a previous explicit request
# introduced (see CHANGELOG). The lookup-and-update branch is added on a newer
# explicit request from the same owner; the older CHANGELOG entry stays as
# history.
#
# JIRA has no hidden-comment mechanism, so the marker is a visible but
# unobtrusive italic line at the bottom of the body:
#   _cr-comment:actor=<acli-email>_
#
# Usage:
#   upsert-comment.sh <KEY|URL> <BODY_FILE> [<MARKER_KEY>]
#   <body-producer> | upsert-comment.sh <KEY|URL> - [<MARKER_KEY>]
#
# Inputs:
#   KEY|URL     Bare JIRA issue key (e.g. ACME-1234), a /browse/<KEY> URL,
#               or any URL containing ?selectedIssue=<KEY>.
#   BODY_FILE   Path to a file holding the JIRA Wiki Markup source, or `-` to
#               read from stdin. The helper converts it to ADF before publish.
#   MARKER_KEY  Optional. Accepted for backward compatibility but ignored —
#               the marker namespace is always `cr-comment`, the only namespace
#               this package publishes into.
#
# Behavior:
#   1. Detect the site and the account e-mail from `acli jira auth status`.
#   2. Append the marker line `_cr-comment:actor=<email>_` to the Wiki Markup
#      source (only when the source does not already carry it).
#   3. Convert the Wiki Markup source to Atlassian Document Format (ADF).
#   4. List the issue's comments and pick the newest one whose body carries that
#      marker.
#   5. When a match exists, update it via
#      `acli jira workitem comment update --body-adf`. Otherwise create a fresh
#      comment from the ADF file and immediately update that same new comment
#      through the same `--body-adf` path. Passing ADF to both calls ensures a
#      failed update never leaves Wiki Markup behind.
#
# The lookup is fail-safe, never fail-open: an unresolvable account e-mail, an
# `acli` error, or an unexpected JSON shape falls back to creating a new comment
# — the previous behaviour — rather than guessing at a match.
#
# Output:
#   The published comment URL on stdout. `action=updated id=<id>` (an existing
#   comment was updated) or `action=created id=<id>` (a new one was created) on
#   stderr, for the calling skill to log in its summary line.
#
# Exit codes:
#   1  usage / argument error
#   2  missing required tool (acli, jq, php)
#   3  JIRA API call failed
set -euo pipefail

usage() {
  cat >&2 <<'EOF'
Usage: upsert-comment.sh <KEY|URL> <BODY_FILE|-> [<MARKER_KEY>]

  KEY         JIRA issue key (e.g. ACME-1234)
  URL         /browse/<KEY> URL or any URL containing ?selectedIssue=<KEY>
  BODY_FILE   path to a file containing the comment body, or `-` for stdin
  MARKER_KEY  optional, accepted for backward compatibility but ignored
EOF
}

if [[ $# -lt 2 || $# -gt 3 || -z "${1:-}" || -z "${2:-}" ]]; then
  usage
  exit 1
fi

INPUT="$1"
BODY_SRC="$2"
# $3 (MARKER_KEY) accepted for backward compatibility but not used.

for bin in acli jq php; do
  if ! command -v "$bin" >/dev/null 2>&1; then
    echo "upsert-comment.sh: required tool not found: $bin" >&2
    exit 2
  fi
done

KEY=""
if [[ "$INPUT" =~ ^[A-Z][A-Z0-9_]+-[0-9]+$ ]]; then
  KEY="$INPUT"
elif [[ "$INPUT" == *"/browse/"* ]]; then
  KEY="$(printf '%s' "$INPUT" | sed -nE 's#.*/browse/([A-Z][A-Z0-9_]+-[0-9]+).*#\1#p')"
elif [[ "$INPUT" == *"selectedIssue="* ]]; then
  KEY="$(printf '%s' "$INPUT" | sed -nE 's#.*selectedIssue=([A-Z][A-Z0-9_]+-[0-9]+).*#\1#p')"
fi

if [[ -z "$KEY" ]]; then
  echo "upsert-comment.sh: could not extract JIRA key from input: $INPUT" >&2
  exit 1
fi

if [[ "$BODY_SRC" == "-" ]]; then
  BODY="$(cat)"
else
  if [[ ! -r "$BODY_SRC" ]]; then
    echo "upsert-comment.sh: cannot read body file: $BODY_SRC" >&2
    exit 1
  fi
  BODY="$(cat "$BODY_SRC")"
fi

if [[ -z "$BODY" ]]; then
  echo "upsert-comment.sh: refusing to publish an empty comment" >&2
  exit 1
fi

# Resolve the site from `acli jira auth status` to build the output URL.
# The installed acli build prints them as human-readable lines:
#   ✓ Authenticated
#     Site: your-org.atlassian.net
#     Email: someone@example.com
AUTH_STATUS="$(acli jira auth status 2>/dev/null || true)"
SITE="$(printf '%s' "$AUTH_STATUS" | awk -F': *' 'tolower($0) ~ /site:/ { gsub(/[[:space:]]+$/, "", $2); print $2; exit }')"
if [[ -z "$SITE" ]]; then
  echo "upsert-comment.sh: failed to resolve JIRA site — is acli authenticated? (run: acli jira auth status)" >&2
  exit 3
fi

# The same status output carries the authenticated account e-mail, which is the
# actor half of the marker. JIRA has no hidden-comment syntax, so the marker is
# a visible italic line at the bottom of the body — the JIRA counterpart of the
# GitHub helper's HTML comment. An unresolvable e-mail is not fatal: the script
# then adds no marker and creates a new comment, exactly as it did before.
EMAIL="$(printf '%s' "$AUTH_STATUS" | awk -F': *' 'tolower($0) ~ /email:/ { gsub(/[[:space:]]+$/, "", $2); print $2; exit }')"
MARKER_TEXT=""
if [[ -n "$EMAIL" ]]; then
  MARKER_TEXT="cr-comment:actor=${EMAIL}"
  if ! grep -Fq "$MARKER_TEXT" <<<"$BODY"; then
    BODY="${BODY}

_${MARKER_TEXT}_"
  fi
else
  echo "upsert-comment.sh: could not resolve the acli account e-mail, publishing an unmarked new comment" >&2
fi

# Build valid ADF before the external write. The create call has no dedicated
# `--body-adf` flag, but `--body-file` accepts an ADF document. The update call
# then applies the same payload to that exact new comment ID through the
# explicitly requested `--body-adf` path.
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ADF_FILE_TMP="$(mktemp)"
CREATE_STDERR="$(mktemp)"
LIST_STDERR="$(mktemp)"
UPDATE_STDERR="$(mktemp)"
trap 'rm -f "$ADF_FILE_TMP" "$CREATE_STDERR" "$LIST_STDERR" "$UPDATE_STDERR"' EXIT

if ! printf '%s' "$BODY" | php "$SCRIPT_DIR/wiki-markup-to-adf.php" > "$ADF_FILE_TMP"; then
  echo "upsert-comment.sh: failed to convert the JIRA comment to ADF" >&2
  exit 3
fi

if ! jq -e '.version == 1 and .type == "doc" and (.content | type == "array")' "$ADF_FILE_TMP" >/dev/null; then
  echo "upsert-comment.sh: converter produced invalid ADF" >&2
  exit 3
fi

# Look for a comment this actor already published under the same marker. Every
# failure path here — no marker, an acli error, an unexpected JSON shape, no
# match — resolves to "no existing comment", so the script creates one instead
# of claiming a match it is not sure of.
EXISTING_ID=""
if [[ -n "$MARKER_TEXT" ]]; then
  LIST_JSON=""
  if ! LIST_JSON="$(acli jira workitem comment list --key "$KEY" --json --paginate 2>"$LIST_STDERR")"; then
    echo "upsert-comment.sh: comment lookup failed on $KEY, publishing a new comment instead: $(<"$LIST_STDERR")" >&2
    LIST_JSON=""
  fi

  if [[ -n "$LIST_JSON" ]]; then
    # Flatten first, match second. `--paginate` may emit one JSON document per
    # page, so `jq -s` slurps the whole stream before any envelope key is read;
    # a single-document response slurps to a one-element stream and behaves the
    # same. The envelope key differs between acli builds, hence the three
    # fallbacks and the `[]` default for a shape none of them matches.
    EXISTING_ID="$(printf '%s' "$LIST_JSON" \
      | jq -s 'map(
            if type == "array" then .
            elif type == "object" then (.comments // .results // .values // [])
            else [] end
          ) | add // []' \
      | jq -r --arg marker "$MARKER_TEXT" '
          map(select(tojson | contains($marker)))
          | sort_by((.updated? // .created? // "") | tostring)
          | last
          | (.id? // empty)
          | tostring' 2>/dev/null || true)"
  fi
fi

if [[ "$EXISTING_ID" =~ ^[0-9]+$ ]]; then
  TARGET_ID="$EXISTING_ID"
  ACTION="updated"
else
  if ! CREATE_JSON="$(acli jira workitem comment create --key "$KEY" --body-file "$ADF_FILE_TMP" --json 2>"$CREATE_STDERR")"; then
    echo "upsert-comment.sh: acli comment create failed on $KEY: $(<"$CREATE_STDERR")" >&2
    exit 3
  fi

  # Read the new comment's ID from whichever shape this acli build returns. The explicit paths come
  # first; the recursive search is the fallback so a renamed envelope key aborts nothing. An aborted
  # publish is not a neutral outcome — it is what tempts a caller to improvise a raw plain-text
  # `acli` write, which is the exact failure this helper exists to prevent.
  TARGET_ID="$(printf '%s' "$CREATE_JSON" | jq -r '
    ( .id? // .commentId? // .comment.id? // .comments[0].id? // .results[0].id?
      // ([.. | objects | .id? | select(type == "string" or type == "number")] | first)
      // empty
    ) | tostring' 2>/dev/null || true)"

  if [[ ! "$TARGET_ID" =~ ^[0-9]+$ ]]; then
    echo "upsert-comment.sh: created a comment on $KEY but its ID is missing; ADF update aborted" >&2
    echo "upsert-comment.sh: do not fall back to a raw acli write — use the JIRA MCP server with an ADF payload" >&2
    exit 3
  fi

  ACTION="created"
fi

if ! acli jira workitem comment update --key "$KEY" --id "$TARGET_ID" --body-adf "$ADF_FILE_TMP" >/dev/null 2>"$UPDATE_STDERR"; then
  if [[ "$ACTION" == "created" ]]; then
    # The comment this run just created may render as Wiki Markup. Remove it rather than leave an
    # unformatted comment behind for a reader to find, then fail loudly. A comment an earlier run
    # published is never deleted here — a failed update leaves the previous body in place.
    acli jira workitem comment delete --key "$KEY" --id "$TARGET_ID" >/dev/null 2>&1 || true
    echo "upsert-comment.sh: ADF update failed on $KEY comment $TARGET_ID: $(<"$UPDATE_STDERR")" >&2
    echo "upsert-comment.sh: the created comment was removed; do not fall back to a raw acli write — use the JIRA MCP server with an ADF payload" >&2
  else
    echo "upsert-comment.sh: ADF update failed on $KEY comment $TARGET_ID: $(<"$UPDATE_STDERR")" >&2
    echo "upsert-comment.sh: the existing comment was left unchanged; do not fall back to a raw acli write — use the JIRA MCP server with an ADF payload" >&2
  fi
  exit 3
fi

echo "https://${SITE}/browse/${KEY}?focusedCommentId=${TARGET_ID}"
echo "action=${ACTION} id=${TARGET_ID}" >&2
