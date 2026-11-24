#!/usr/bin/env bash
# delete-owned-comment.sh — delete one JIRA issue comment after proving that it
# belongs to the current acli actor and to the exact issue. The JIRA
# counterpart of skills/_shared/delete-owned-github-comment.sh: protected IDs
# make publication-before-deletion and preservation of current merge evidence
# enforceable.
#
# Usage:
#   delete-owned-comment.sh <KEY|URL> <COMMENT_ID> <FINAL_TLDR_ID> <CURRENT_CR_ID>
#   delete-owned-comment.sh --review-merge <KEY|URL> <COMMENT_ID> <MERGED_REVIEW_ID>
#
# The `--review-merge` form is the one delete of `skills/merge-review-comments`. The merged review comment is
# both protected ID. The owner is the account ID of the authenticated user (jira_actor_account_id); a user it
# cannot resolve refuses the delete. The merged review comment and the target must both be that account's and
# carry this actor's cr-comment marker, and the target only that agent marker. The target is refused when a
# later comment points at it (skills/_shared/comment-reply.jq owns what a reply is), and when it holds a node
# the operator added that the merged comment does not carry verbatim (skills/_shared/operator-lines.sh). A
# view that embeds fewer comments than the issue carries refuses the delete, as an unreadable list does.
#
# Inputs:
#   KEY|URL        Bare JIRA issue key (e.g. ACME-1234), a /browse/<KEY> URL, or
#                  any URL containing ?selectedIssue=<KEY>.
#   COMMENT_ID     The comment to delete.
#   FINAL_TLDR_ID  The final TL;DR this run published and read back.
#   CURRENT_CR_ID  The current CR evidence comment on this issue: the newest
#                  code-review report. The JIRA TL;DR is a new comment, so it is
#                  never that report. Only when the issue carries no code-review
#                  report, pass the final TL;DR ID for both protected slots.
#
# Ownership proof — every condition must hold, or nothing is deleted:
#   1. COMMENT_ID is neither protected ID.
#   2. The actor digest is derived from the e-mail `acli jira auth status`
#      reports, through the same jira-actor.sh function upsert-comment.sh uses
#      to write the `_cr-comment:actor=<actor-digest>_` marker.
#   3. Both protected comments exist on this issue. The author `accountId` of
#      FINAL_TLDR_ID anchors the actor's account: this run published that comment
#      itself and read it back, while acli exposes no account ID for the current
#      user. CURRENT_CR_ID must share that `accountId`. A protected comment's own
#      anchor never depends on a marker being present on it — it comes from the
#      accountId this run read back. So a TL;DR published through the sanctioned
#      JIRA MCP fallback after `upsert-comment.sh` failed still anchors the
#      cleanup of the duplicates that failed run left behind: that fallback TL;DR carries the `cr-comment:actor=` marker too, per `@rules/code-review/general.md`
#      *Authorship trust*, but this step does not depend on it. Pass only a
#      comment ID this run published as FINAL_TLDR_ID.
#   4. The target exists on this issue, carries the marker, and its author
#      `accountId` equals the anchor. The marker alone is visible text anyone
#      can copy, and a display name is not an identity, so neither proves
#      ownership on its own.
#   5. Wherever the response carries `author.emailAddress`, it must equal the
#      acli e-mail. Jira Cloud usually hides it, so it corroborates, never
#      replaces, the checks above.
#
# A comment carrying no marker (for example an empty comment a failed publish
# left behind) cannot be proven and is never deleted here; report it for a
# human. The comments are read through `acli jira workitem view <KEY> --fields
# comment --json`; a comment the view does not embed cannot be proven either.
#
# After `acli jira workitem comment delete --key <KEY> --id <ID>` the issue is
# re-read, and success is reported only when that read still lists both
# protected comments and no longer lists the target — a read that lists neither
# proves nothing.
#
# Audit trail:
#   The write appends its own line to the run's `.audit` file at the moment it
#   happens, through `skills/_shared/audit-trail.sh record`: an `external-write`
#   line, or a `note` line for a failed attempt, both with the acting role and
#   the real exit status of the write. The ledger comes from AUDIT_TRAIL_FILE
#   and AUDIT_TRAIL_ROLE; a missing ledger prints a hint on stderr and never
#   changes the output or the exit codes.
#
# Exit codes:
#   1  usage / argument error
#   2  missing required tool (acli, jq, php)
#   3  JIRA call failed, or the deletion could not be verified
#   4  ownership or protection check refused the deletion
set -euo pipefail

PROG="${0##*/}"

usage() {
  cat >&2 <<'EOF'
Usage: delete-owned-comment.sh <KEY|URL> <COMMENT_ID> <FINAL_TLDR_ID> <CURRENT_CR_ID>
       delete-owned-comment.sh --review-merge <KEY|URL> <COMMENT_ID> <MERGED_REVIEW_ID>

Deletes only an actor-owned comment on the exact JIRA issue. The final TL;DR
and the newest code-review report must be passed as protected IDs; pass the
final TL;DR ID twice only when the issue carries no code-review report. The
--review-merge form deletes only a review comment that no later comment points at.
EOF
}

REVIEW_MERGE=0
if [[ "${1:-}" == "--review-merge" ]]; then
  REVIEW_MERGE=1
  shift
  if [[ "$#" -ne 3 ]]; then
    usage
    exit 1
  fi
  set -- "$1" "$2" "$3" "$3"
elif [[ "$#" -ne 4 ]]; then
  usage
  exit 1
fi

INPUT="$1"
COMMENT_ID="$2"
FINAL_TLDR_ID="$3"
CURRENT_CR_ID="$4"

for id in "$COMMENT_ID" "$FINAL_TLDR_ID" "$CURRENT_CR_ID"; do
  if [[ ! "$id" =~ ^[1-9][0-9]*$ ]]; then
    printf '%s\n' "$PROG: every comment id must be a positive integer" >&2
    exit 1
  fi
done

if [[ "$COMMENT_ID" == "$FINAL_TLDR_ID" || "$COMMENT_ID" == "$CURRENT_CR_ID" ]]; then
  printf '%s\n' "$PROG: refusing to delete a protected final comment or current merge-evidence comment" >&2
  exit 4
fi

KEY=""
if [[ "$INPUT" =~ ^[A-Z][A-Z0-9_]+-[0-9]+$ ]]; then
  KEY="$INPUT"
elif [[ "$INPUT" == *"/browse/"* ]]; then
  KEY="$(printf '%s' "$INPUT" | sed -nE 's#.*/browse/([A-Z][A-Z0-9_]+-[0-9]+).*#\1#p')"
elif [[ "$INPUT" == *"selectedIssue="* ]]; then
  KEY="$(printf '%s' "$INPUT" | sed -nE 's#.*selectedIssue=([A-Z][A-Z0-9_]+-[0-9]+).*#\1#p')"
fi

if [[ -z "$KEY" ]]; then
  printf '%s\n' "$PROG: could not extract a JIRA key from input: $INPUT" >&2
  exit 1
fi

for bin in acli jq php; do
  if ! command -v "$bin" >/dev/null 2>&1; then
    printf '%s\n' "$PROG: required tool not found: $bin" >&2
    exit 2
  fi
done

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=jira-actor.sh
source "$SCRIPT_DIR/jira-actor.sh"

EMAIL="$(jira_auth_status_field "$(acli jira auth status 2>/dev/null || true)" email)"
DIGEST="$(jira_actor_digest "$EMAIL")"
if [[ -z "$DIGEST" ]]; then
  printf '%s\n' "$PROG: could not resolve the acli account identity; ownership cannot be proven" >&2
  exit 3
fi
MARKER="cr-comment:actor=${DIGEST}"

read_comments() {
  local raw=""
  if ! raw="$(acli jira workitem view "$KEY" --fields comment --json 2>/dev/null)"; then
    return 1
  fi

  # The reply check of --review-merge needs every comment: a view that embeds fewer than the issue carries is no read.
  if [[ "$REVIEW_MERGE" -eq 1 ]] && ! printf '%s' "$raw" | jq -e -s 'all(.[] | select(type == "object");
      ((.fields.comment.total // 0) | tonumber? // 0) <= ((.fields.comment.comments // []) | length))' >/dev/null 2>&1; then
    printf '%s\n' "$PROG: the view of $KEY embeds fewer comments than the issue carries" >&2
    return 1
  fi

  printf '%s' "$raw" | jq -c -s 'map(
      if type == "array" then .
      elif type == "object" then (.fields.comment.comments // .comments // [])
      else [] end
    ) | add // []' 2>/dev/null
}

# Print `<accountId>\t<emailAddress>` of a comment that exists on the issue and,
# when a marker is given, carries it; print nothing otherwise.
owned_identity() {
  printf '%s' "$COMMENTS" | jq -r --arg id "$1" --arg marker "$2" '
    map(select(((.id // "") | tostring) == $id)) | first
    | select(. != null)
    | select($marker == "" or ((.body | tojson) | contains($marker)))
    | [ (.author.accountId? // ""), (.author.emailAddress? // "") ] | @tsv' 2>/dev/null || true
}

# Refuse unless the comment is on the issue, carries the required marker,
# belongs to the anchor account, and shows no conflicting e-mail.
require_owned() {
  local id="$1" what="$2" marker="$3" identity account email
  identity="$(owned_identity "$id" "$marker")"
  account="${identity%%$'\t'*}"
  email="${identity#*$'\t'}"

  if [[ -z "$identity" || -z "$account" ]]; then
    if [[ -n "$marker" ]]; then
      printf '%s\n' "$PROG: $what $id is not on $KEY or does not carry this actor's cr-comment marker" >&2
    else
      printf '%s\n' "$PROG: $what $id is not on $KEY or has no resolvable author account" >&2
    fi
    exit 4
  fi

  if [[ -n "$email" && "$email" != "$EMAIL" ]]; then
    printf '%s\n' "$PROG: $what $id is authored by another account" >&2
    exit 4
  fi

  if [[ -n "${ANCHOR_ACCOUNT:-}" && "$account" != "$ANCHOR_ACCOUNT" ]]; then
    printf '%s\n' "$PROG: $what $id is not owned by the authenticated actor" >&2
    exit 4
  fi

  ANCHOR_ACCOUNT="$account"
}

if ! COMMENTS="$(read_comments)" || [[ -z "$COMMENTS" ]]; then
  printf '%s\n' "$PROG: failed to read the comments of $KEY" >&2
  exit 3
fi

ANCHOR_ACCOUNT=""
if [[ "$REVIEW_MERGE" -eq 1 ]]; then
  # The owner is the authenticated account itself, never the author of a comment the caller names.
  ANCHOR_ACCOUNT="$(jira_actor_account_id)"
  if [[ -z "$ANCHOR_ACCOUNT" ]]; then
    printf '%s\n' "$PROG: could not resolve the account ID of the acli user; ownership cannot be proven" >&2
    exit 3
  fi
  require_owned "$FINAL_TLDR_ID" "merged review comment" "$MARKER"
else
  require_owned "$FINAL_TLDR_ID" "protected comment" ""
  require_owned "$CURRENT_CR_ID" "protected comment" ""
fi
require_owned "$COMMENT_ID" "comment" "$MARKER"

if [[ "$REVIEW_MERGE" -eq 1 ]]; then
  REVIEW_VERDICT="$(printf '%s' "$COMMENTS" | jq -L "$SCRIPT_DIR/../../_shared" -r --arg actor "$ANCHOR_ACCOUNT" --arg id "$COMMENT_ID" '
    include "comment-reply";
    jira_acli_normalised($actor) as $all
    | ($all | map(select(.id == $id)) | first) as $original
    | if $original == null then "unknown"
      elif ([$original.body | scan("([a-z][a-z0-9-]*):actor=") | .[0]] | map(select(. != "cr-comment")) | length) > 0 then "other-marker"
      elif has_reply($original; $all) then "reply"
      else "clear" end' 2>/dev/null || printf 'unknown')"
  if [[ "$REVIEW_VERDICT" != "clear" ]]; then
    printf '%s\n' "$PROG: a comment that has a reply, or that carries another agent marker, is never deleted (check: $REVIEW_VERDICT)" >&2
    exit 4
  fi
  # shellcheck source=../../_shared/operator-lines.sh
  source "$SCRIPT_DIR/../../_shared/operator-lines.sh"
  LINES_DIR="$(mktemp -d)"
  trap 'rm -rf "$LINES_DIR"' EXIT
  for pair in "target:$COMMENT_ID" "merged:$FINAL_TLDR_ID"; do
    printf '%s' "$COMMENTS" | jq -c --arg id "${pair#*:}" 'map(select(((.id // "") | tostring) == $id)) | first | .body' >"$LINES_DIR/${pair%%:*}.json"
  done
  if ! MISSING_LINES="$(operator_lines adf "$LINES_DIR/target.json" "$LINES_DIR/merged.json")"; then
    printf '%s\n' "$PROG: the operator lines of comment $COMMENT_ID could not be read; nothing is deleted" >&2
    exit 3
  fi
  if [[ -n "$MISSING_LINES" ]]; then
    printf '%s\n' "$PROG: comment $COMMENT_ID holds a line the operator added that the merged review comment does not carry; nothing is deleted" >&2
    exit 4
  fi
fi

# The audit line is written at the moment of the delete, with the real exit status of `acli`.
DELETE_RC=0
acli jira workitem comment delete --key "$KEY" --id "$COMMENT_ID" >/dev/null 2>&1 || DELETE_RC=$?
bash "$SCRIPT_DIR/../../_shared/audit-trail.sh" record --target "jira comment $COMMENT_ID delete on $KEY" --status "$DELETE_RC" || true
if [[ "$DELETE_RC" -ne 0 ]]; then
  printf '%s\n' "$PROG: acli comment delete failed on $KEY comment $COMMENT_ID" >&2
  exit 3
fi

if ! COMMENTS="$(read_comments)" || [[ -z "$COMMENTS" ]]; then
  printf '%s\n' "$PROG: deletion could not be verified; the comments of $KEY are unreadable" >&2
  exit 3
fi

for protected_id in "$FINAL_TLDR_ID" "$CURRENT_CR_ID"; do
  if ! printf '%s' "$COMMENTS" | jq -e --arg id "$protected_id" 'any(.[]; ((.id // "") | tostring) == $id)' >/dev/null; then
    printf '%s\n' "$PROG: deletion could not be verified; the re-read of $KEY lacks protected comment $protected_id" >&2
    exit 3
  fi
done

if printf '%s' "$COMMENTS" | jq -e --arg id "$COMMENT_ID" 'any(.[]; ((.id // "") | tostring) == $id)' >/dev/null; then
  printf '%s\n' "$PROG: deletion verification failed; comment $COMMENT_ID is still on $KEY" >&2
  exit 3
fi

printf 'deleted id=%s key=%s\n' "$COMMENT_ID" "$KEY"
