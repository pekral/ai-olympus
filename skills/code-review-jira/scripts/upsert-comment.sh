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
#   _cr-comment:actor=<actor-digest>_
#
# The actor half is the first 16 hex characters of the SHA-256 digest of the
# authenticated account e-mail, never the address itself. The marker is readable
# by everyone who can browse the issue, including an external customer on a
# Service Management project, so publishing the address there would expose the
# very field Jira Cloud hides from its own API responses for privacy. The digest
# is stable across runs, which is all the lookup needs.
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
#      That status output is the only identity `acli` exposes — it carries no
#      account ID, and no `acli` subcommand returns one for the current user.
#   2. Derive the actor digest from that e-mail and append the marker line
#      `_cr-comment:actor=<actor-digest>_` to the Wiki Markup source (only when
#      the source does not already carry it). The raw address never leaves this
#      process: it is used only for the local author comparison in step 4.
#   3. Convert the Wiki Markup source to Atlassian Document Format (ADF).
#   4. Read the issue's comments through `acli jira workitem view <KEY>
#      --fields comment --json` and pick the newest one this account authored
#      whose body carries that marker. `comment list` is not used: acli 1.3.x
#      returns the author there as a bare display name and the body as flattened
#      text, so neither half of the match can be made against it. Both halves
#      are load-bearing: the marker is visible text anyone can copy into their
#      own comment, so the author
#      must match too, and the marker is matched against the comment body alone
#      rather than against the whole comment object.
#   5. When a match exists, update it via
#      `acli jira workitem comment update --body-adf`. Otherwise create a fresh
#      comment from the ADF file and immediately update that same new comment
#      through the same `--body-adf` path. Passing ADF to both calls ensures a
#      failed update never leaves Wiki Markup behind. `comment create --json`
#      reports a per-work-item status and no comment ID on acli 1.3.x, so the
#      new ID is resolved by re-reading the comments and taking the comment that
#      was absent before the create, carries the marker, and has this author.
#
# The lookup is fail-safe, never fail-open: an unresolvable account e-mail, an
# `acli` error, or an unexpected JSON shape falls back to creating a new comment
# — the previous behaviour — rather than guessing at a match.
#
# A comment list that omits `author.emailAddress` resolves the same way, and it
# is the common case rather than an exotic one: Jira Cloud hides that field
# whenever the account sets its e-mail visibility to "Only you and admins", or
# the instance hides it site-wide. The author half of the lookup then has
# nothing to match on, so the run creates a second comment instead of updating
# one it cannot prove it owns. A duplicate comment is the cheap failure;
# overwriting a stranger's is not. The run says so on stderr whenever a
# marker-carrying comment has an unresolvable author, so the operator reads a
# degradation rather than a first run.
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
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=jira-actor.sh
source "$SCRIPT_DIR/jira-actor.sh"

AUTH_STATUS="$(acli jira auth status 2>/dev/null || true)"
SITE="$(jira_auth_status_field "$AUTH_STATUS" site)"
if [[ -z "$SITE" ]]; then
  echo "upsert-comment.sh: failed to resolve JIRA site — is acli authenticated? (run: acli jira auth status)" >&2
  exit 3
fi

# The same status output carries the authenticated account e-mail. The marker
# publishes a digest of it rather than the address: JIRA has no hidden-comment
# syntax, so the marker is a visible italic line at the bottom of the body that
# every reader of the issue can see. `php` computes the digest because this
# script already requires it for the ADF conversion, so no further tool has to
# be present for the publisher to work.
EMAIL="$(jira_auth_status_field "$AUTH_STATUS" email)"
ACTOR_ID="$(jira_actor_digest "$EMAIL")"

# An unresolvable identity is not fatal: the script then adds no marker and
# creates a new comment, exactly as it did before.
MARKER_TEXT=""
if [[ -n "$ACTOR_ID" ]]; then
  MARKER_TEXT="cr-comment:actor=${ACTOR_ID}"
  if ! grep -Fq "$MARKER_TEXT" <<<"$BODY"; then
    BODY="${BODY}

_${MARKER_TEXT}_"
  fi
else
  echo "upsert-comment.sh: could not resolve the acli account identity, publishing an unmarked new comment" >&2
fi

# Build valid ADF before the external write. The create call has no dedicated
# `--body-adf` flag, but `--body-file` accepts an ADF document. The update call
# then applies the same payload to that exact new comment ID through the
# explicitly requested `--body-adf` path.
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

# Print the issue's comments as one JSON array, or nothing when the read fails.
# `jq -s` slurps the stream so a response of several documents still flattens;
# the envelope differs between acli builds, hence the fallbacks and the `[]`
# default for a shape none of them matches.
read_comments() {
  local raw=""
  if ! raw="$(acli jira workitem view "$KEY" --fields comment --json 2>"$LIST_STDERR")"; then
    return 1
  fi

  printf '%s' "$raw" \
    | jq -s 'map(
          if type == "array" then .
          elif type == "object" then (.fields.comment.comments // .comments // .results // .values // [])
          else [] end
        ) | add // []' 2>/dev/null
}

# An author that is a bare display name, or missing, has no e-mail to match.
AUTHOR_EMAIL_JQ='(.author | if type == "object" then (.emailAddress // "") else "" end)'

# Look for a comment this actor already published under the same marker. Every
# failure path here — no marker, an acli error, an unexpected JSON shape, no
# match — resolves to "no existing comment", so the script creates one instead
# of claiming a match it is not sure of.
EXISTING_ID=""
COMMENTS_JSON=""
if [[ -n "$MARKER_TEXT" ]]; then
  if ! COMMENTS_JSON="$(read_comments)"; then
    echo "upsert-comment.sh: comment lookup failed on $KEY, publishing a new comment instead: $(<"$LIST_STDERR")" >&2
    COMMENTS_JSON=""
  fi

  if [[ -n "$COMMENTS_JSON" ]]; then
    # The author is compared on the raw e-mail this process already holds. The
    # marker in the body carries only its digest, so it never identifies the
    # account to a reader; the comparison here needs no digest of its own.
    EXISTING_ID="$(printf '%s' "$COMMENTS_JSON" \
      | jq -r --arg marker "$MARKER_TEXT" --arg marker_email "$EMAIL" '
          map(select(('"${AUTHOR_EMAIL_JQ}"' == $marker_email)
                     and ((.body | tojson) | contains($marker))))
          | sort_by((.updated? // .created? // "") | tostring)
          | last
          | (.id? // empty)
          | tostring' 2>/dev/null || true)"
  fi
fi

# Name the hidden-e-mail degradation instead of letting it pass for a first run.
# A comment already carrying a `cr-comment` marker whose author this response
# does not identify means the update target may well be there and be
# unprovable, so the new comment below is a duplicate rather than a first one.
if [[ ! "$EXISTING_ID" =~ ^[0-9]+$ && -n "$COMMENTS_JSON" ]]; then
  UNVERIFIABLE_MARKED="$(printf '%s' "$COMMENTS_JSON" \
    | jq -r "map(select((${AUTHOR_EMAIL_JQ} == \"\")
                        and ((.body | tojson) | contains(\"cr-comment:actor=\"))))
             | length" 2>/dev/null || true)"

  if [[ "$UNVERIFIABLE_MARKED" =~ ^[1-9][0-9]*$ ]]; then
    echo "upsert-comment.sh: no comment matched this actor's identity — author identity could not be verified from the acli response; posting a new comment instead of updating" >&2
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

  # acli 1.3.x reports no comment ID here. Identify the new comment by what only
  # it can carry: absent before the create, this account's marker in the body,
  # and this account as its author. The highest ID wins, since JIRA assigns them
  # in ascending order. Without a marker and an e-mail there is nothing to match
  # on, so the run fails closed rather than update a comment it cannot prove.
  if [[ ! "$TARGET_ID" =~ ^[0-9]+$ && -n "$MARKER_TEXT" && -n "$EMAIL" ]]; then
    BEFORE_IDS="$(printf '%s' "${COMMENTS_JSON:-[]}" | jq -c '[.[] | (.id? // empty) | tostring]' 2>/dev/null || echo '[]')"
    AFTER_JSON="$(read_comments || true)"
    TARGET_ID="$(printf '%s' "${AFTER_JSON:-[]}" \
      | jq -r --argjson before "$BEFORE_IDS" --arg marker "$MARKER_TEXT" --arg marker_email "$EMAIL" "
          map(select(((.id? // \"\") | tostring) as \$id | (\$before | index(\$id)) == null)
              | select(${AUTHOR_EMAIL_JQ} == \$marker_email)
              | select((.body | tojson) | contains(\$marker)))
          | map((.id | tostring) | select(test(\"^[0-9]+\$\")) | tonumber)
          | max // empty
          | tostring" 2>/dev/null || true)"
  fi

  if [[ ! "$TARGET_ID" =~ ^[0-9]+$ ]]; then
    echo "upsert-comment.sh: created a comment on $KEY but its ID is missing; ADF update aborted" >&2
    echo "upsert-comment.sh: the created comment may remain on $KEY carrying this actor's marker; after a verified publish, remove it only through skills/code-review-jira/scripts/delete-owned-comment.sh" >&2
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
