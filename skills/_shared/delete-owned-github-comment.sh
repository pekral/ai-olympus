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
# exists on the same target: the older copy is then superseded. An overflow comment
# carries the pr-body-overflow marker (or the legacy agent-note marker) of the actor
# and opens with the heading line (a leading marker-only line is skipped); a heading quoted anywhere else identifies nothing.
# The two-argument form deletes only such a superseded copy, needs no protected id,
# and refuses a comment without an overflow marker or with a merge-readiness or
# cr-comment marker.
set -euo pipefail

PROG="${0##*/}"

usage() {
  cat >&2 <<EOF
Usage: $PROG <GitHub issue-or-PR URL> <comment-id> <final-tldr-id> <current-cr-id>
       $PROG <GitHub issue-or-PR URL> <comment-id>

Deletes only an authenticated actor-owned top-level issue/PR comment. The final
TL;DR and current CR evidence must be passed as protected IDs. The two-argument
form deletes only a pull-request body overflow comment (the pr-body-overflow or
agent-note marker of the actor, opening with the overflow heading) that a newer
pr-body-overflow comment of the same actor supersedes.
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
source "$SCRIPT_DIR/resolve-command-helper.sh" || exit 3
AUDIT_TRAIL="$(resolve_command_helper audit-trail.sh)" || exit 3
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
OVERFLOW_HEADING='## Pull-request body overflow'

# True when the body carries the marker on a line of its own outside a fenced code block.
# A quoted marker or a quoted heading never identifies a comment.
body_has_marker() {
  awk -v marker="$2" '
    /^[ \t]*(```|~~~)/ { fenced = !fenced; next }
    !fenced { sub(/[ \t\r]+$/, ""); if ($0 == marker) found = 1 }
    END { exit !found }
  ' <<<"$1"
}

# True when the first non-blank line of the body is exactly the overflow heading.
# A leading line that holds only an agent marker does not count as content.
body_opens_with_heading() {
  local first_line
  first_line="$(awk '{ sub(/[ \t\r]+$/, "") } NF && $0 !~ /^<!-- [a-z][a-z0-9-]*:actor=[^ ]+ -->$/ { print; exit }' <<<"$1")"
  [[ "$first_line" == "$OVERFLOW_HEADING" ]]
}

# A pull-request body overflow comment of this actor carries the pr-body-overflow marker, or the
# legacy agent-note marker an earlier publish left, and opens with the heading.
# upsert-comment.sh appends the marker after the body, so the heading stays the first non-blank line.
is_overflow_comment() {
  { body_has_marker "$1" "<!-- pr-body-overflow:actor=$ACTOR -->" || body_has_marker "$1" "<!-- agent-note:actor=$ACTOR -->"; } \
    && body_opens_with_heading "$1"
}

if [[ "$OVERFLOW_ONLY" -eq 1 ]]; then
  # The two-argument form never touches merge evidence or a comment without an overflow marker.
  if grep -Eq '<!-- (merge-readiness|cr-comment):actor=' <<<"$COMMENT_BODY"; then
    printf '%s\n' "$PROG: the two-argument form never deletes a merge-readiness or cr-comment comment" >&2
    exit 4
  fi

  if ! is_overflow_comment "$COMMENT_BODY"; then
    printf '%s\n' "$PROG: the two-argument form deletes only a superseded pull-request body overflow comment" >&2
    exit 4
  fi
fi

if [[ "$OVERFLOW_ONLY" -eq 1 ]] || is_overflow_comment "$COMMENT_BODY" || body_opens_with_heading "$COMMENT_BODY"; then
  # The copy is superseded only by a newer overflow comment of this actor on the same target:
  # one that carries the pr-body-overflow marker and opens with the heading.
  # A failed listing counts as no newer comment, so the copy stays.
  NEWER_OVERFLOW=0
  NEWER_BODIES=""
  if NEWER_BODIES="$(gh api "repos/$OWNER/$REPOSITORY/issues/$NUMBER/comments" --paginate 2>/dev/null | jq -s -c \
    --arg actor "$ACTOR" --argjson id "$COMMENT_ID" \
    '.[][] | select(.user.login == $actor and .id > $id) | (.body // "")')"; then
    while IFS= read -r newer_json; do
      [[ -n "$newer_json" ]] || continue
      newer_body="$(printf '%s' "$newer_json" | jq -r '.')"
      if body_has_marker "$newer_body" "<!-- pr-body-overflow:actor=$ACTOR -->" && body_opens_with_heading "$newer_body"; then
        NEWER_OVERFLOW=$((NEWER_OVERFLOW + 1))
      fi
    done <<<"$NEWER_BODIES"
  fi

  if [[ "$NEWER_OVERFLOW" -eq 0 ]]; then
    printf '%s\n' "$PROG: refusing to delete a pull-request body overflow comment" >&2
    exit 4
  fi
fi

DELETE_RC=0
gh api --method DELETE "repos/$OWNER/$REPOSITORY/issues/comments/$COMMENT_ID" >/dev/null || DELETE_RC=$?
# The audit line is written at the moment of the delete, with the real exit status of the request.
bash "$AUDIT_TRAIL" record --target "delete comment $COMMENT_ID on $TARGET_URL" --status "$DELETE_RC" || true
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
