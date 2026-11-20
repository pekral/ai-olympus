#!/usr/bin/env bash
# Delete one top-level GitHub issue/PR comment after proving that it belongs to
# the current actor and exact target. Protected IDs make publication-before-
# deletion and preservation of current merge evidence enforceable.
#
# The delete appends its own line to the run's `.audit` file at the moment it
# happens, through audit-trail.sh in this directory: an `external-write` line,
# or a `note` line for a failed attempt, with the acting role and the real exit
# status of the delete request (`AUDIT_TRAIL_FILE`, `AUDIT_TRAIL_ROLE`).
#
# A pull-request body overflow comment is the durable copy of the body's verbatim
# content, so it is deleted only when a newer overflow comment of the same actor
# exists on the same target: the older copy is then superseded. The two-argument
# form deletes only such a superseded copy and needs no protected id.
set -euo pipefail

PROG="${0##*/}"

usage() {
  cat >&2 <<EOF
Usage: $PROG <GitHub issue-or-PR URL> <comment-id> <final-tldr-id> <current-cr-id>
       $PROG <GitHub issue-or-PR URL> <comment-id>

Deletes only an authenticated actor-owned top-level issue/PR comment. The final
TL;DR and current CR evidence must be passed as protected IDs. The two-argument
form deletes only a pull-request body overflow comment that a newer overflow
comment of the same actor supersedes.
EOF
}

if [[ "$#" -ne 4 && "$#" -ne 2 ]]; then
  usage
  exit 1
fi

TARGET_URL="$1"
COMMENT_ID="$2"
shift 2
PROTECTED_IDS=("$@")
PROTECTED_MARKERS=("merge-readiness" "cr-comment")
OVERFLOW_ONLY=0
[[ "${#PROTECTED_IDS[@]}" -eq 0 ]] && OVERFLOW_ONLY=1

if [[ ! "$COMMENT_ID" =~ ^[1-9][0-9]*$ ]]; then
  printf '%s\n' "$PROG: comment id must be a positive integer" >&2
  exit 1
fi

if [[ "$OVERFLOW_ONLY" -eq 0 ]]; then
  for protected_id in "${PROTECTED_IDS[@]}"; do
    if [[ ! "$protected_id" =~ ^[1-9][0-9]*$ ]]; then
      printf '%s\n' "$PROG: every protected id must be a positive integer" >&2
      exit 1
    fi

    if [[ "$COMMENT_ID" == "$protected_id" ]]; then
      printf '%s\n' "$PROG: refusing to delete a protected final comment or current merge-evidence comment" >&2
      exit 4
    fi
  done
fi

if [[ "$TARGET_URL" =~ ^https://github\.com/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)/(issues|pull)/([1-9][0-9]*)/?$ ]]; then
  OWNER="${BASH_REMATCH[1]}"
  REPOSITORY="${BASH_REMATCH[2]}"
  NUMBER="${BASH_REMATCH[4]}"
else
  printf '%s\n' "$PROG: target must be one full GitHub issue or pull-request URL" >&2
  exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
NAME_WITH_OWNER="$("$SCRIPT_DIR"/assert-current-repo.sh "$TARGET_URL")"
EXPECTED_NAME="$(printf '%s/%s' "$OWNER" "$REPOSITORY" | tr '[:upper:]' '[:lower:]')"
ACTUAL_NAME="$(printf '%s' "$NAME_WITH_OWNER" | tr '[:upper:]' '[:lower:]')"

if [[ "$ACTUAL_NAME" != "$EXPECTED_NAME" ]]; then
  printf '%s\n' "$PROG: ownership guard returned a different repository" >&2
  exit 4
fi

ACTOR="$(gh api user --jq .login)"
# The comment endpoint carries no repository_url; issue_url names the repository
# and the issue or pull request the comment sits on.
EXPECTED_ISSUE_URL_PREFIX="$(printf '%s' "https://api.github.com/repos/$OWNER/$REPOSITORY/issues/" | tr '[:upper:]' '[:lower:]')"

if [[ "$OVERFLOW_ONLY" -eq 0 ]]; then
  for index in "${!PROTECTED_IDS[@]}"; do
    protected_id="${PROTECTED_IDS[$index]}"
    protected_json="$(gh api "repos/$OWNER/$REPOSITORY/issues/comments/$protected_id")"
    protected_actor="$(printf '%s' "$protected_json" | jq -r '.user.login // empty')"
    protected_issue_url="$(printf '%s' "$protected_json" | jq -r '.issue_url // empty' | tr '[:upper:]' '[:lower:]')"
    protected_body="$(printf '%s' "$protected_json" | jq -r '.body // empty')"
    expected_marker="<!-- ${PROTECTED_MARKERS[$index]}:actor=$ACTOR -->"

    if [[ "$protected_actor" != "$ACTOR" ]]; then
      printf '%s\n' "$PROG: protected comment $protected_id is not owned by the authenticated actor" >&2
      exit 4
    fi

    if [[ "$protected_issue_url" != "$EXPECTED_ISSUE_URL_PREFIX"* || ! "${protected_issue_url#"$EXPECTED_ISSUE_URL_PREFIX"}" =~ ^[1-9][0-9]*$ ]]; then
      printf '%s\n' "$PROG: protected comment $protected_id belongs to a different repository" >&2
      exit 4
    fi

    if [[ "$protected_body" != *"$expected_marker"* ]]; then
      printf '%s\n' "$PROG: protected comment $protected_id does not carry the required ${PROTECTED_MARKERS[$index]} marker" >&2
      exit 4
    fi
  done
fi

COMMENT_JSON="$(gh api "repos/$OWNER/$REPOSITORY/issues/comments/$COMMENT_ID")"
COMMENT_ACTOR="$(printf '%s' "$COMMENT_JSON" | jq -r '.user.login // empty')"
COMMENT_ISSUE_URL="$(printf '%s' "$COMMENT_JSON" | jq -r '.issue_url // empty')"
EXPECTED_ISSUE_URL="https://api.github.com/repos/$OWNER/$REPOSITORY/issues/$NUMBER"

if [[ "$COMMENT_ACTOR" != "$ACTOR" ]]; then
  printf '%s\n' "$PROG: refusing to delete a comment not owned by the authenticated actor" >&2
  exit 4
fi

if [[ "$(printf '%s' "$COMMENT_ISSUE_URL" | tr '[:upper:]' '[:lower:]')" != "$(printf '%s' "$EXPECTED_ISSUE_URL" | tr '[:upper:]' '[:lower:]')" ]]; then
  printf '%s\n' "$PROG: refusing to delete a comment outside the exact target" >&2
  exit 4
fi

# The overflow comment holds the durable copy of the pull-request body's verbatim content.
# Read the body into a variable first: grep -q exits on the first match, which would SIGPIPE jq under pipefail on a large body.
COMMENT_BODY="$(printf '%s' "$COMMENT_JSON" | jq -r '.body // empty')"
OVERFLOW_HEADING='^## Pull-request body overflow[[:space:]]*$'
if grep -Eq "$OVERFLOW_HEADING" <<<"$COMMENT_BODY"; then
  # The copy is superseded only by a newer overflow comment of this actor on the same target.
  # A failed listing counts as no newer comment, so the copy stays.
  NEWER_OVERFLOW=0
  NEWER_OVERFLOW="$(gh api "repos/$OWNER/$REPOSITORY/issues/$NUMBER/comments" --paginate 2>/dev/null | jq -s -r \
    --arg actor "$ACTOR" --argjson id "$COMMENT_ID" \
    '[.[][] | select(.user.login == $actor and .id > $id and ((.body // "") | test("(^|\n)## Pull-request body overflow[ \t]*(\n|$)")))] | length')" || NEWER_OVERFLOW=0
  if [[ ! "$NEWER_OVERFLOW" =~ ^[0-9]+$ || "$NEWER_OVERFLOW" -eq 0 ]]; then
    printf '%s\n' "$PROG: refusing to delete a pull-request body overflow comment" >&2
    exit 4
  fi
elif [[ "$OVERFLOW_ONLY" -eq 1 ]]; then
  printf '%s\n' "$PROG: the two-argument form deletes only a superseded pull-request body overflow comment" >&2
  exit 4
fi

DELETE_RC=0
gh api --method DELETE "repos/$OWNER/$REPOSITORY/issues/comments/$COMMENT_ID" >/dev/null || DELETE_RC=$?
# The audit line is written at the moment of the delete, with the real exit status of the request.
bash "$SCRIPT_DIR/audit-trail.sh" record --target "delete comment $COMMENT_ID on $TARGET_URL" --status "$DELETE_RC" || true
[[ "$DELETE_RC" -eq 0 ]] || exit "$DELETE_RC"

VERIFY_OUTPUT=""
if VERIFY_OUTPUT="$(gh api --include "repos/$OWNER/$REPOSITORY/issues/comments/$COMMENT_ID" 2>&1)"; then
  printf '%s\n' "$PROG: deletion verification failed; the comment is still readable" >&2
  exit 3
fi

if ! grep -Eq '^HTTP/[^ ]+ 404([[:space:]]|$)' <<<"$VERIFY_OUTPUT"; then
  printf '%s\n' "$PROG: deletion could not be verified as HTTP 404" >&2
  exit 3
fi

printf 'deleted id=%s target=%s\n' "$COMMENT_ID" "$TARGET_URL"
