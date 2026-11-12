#!/usr/bin/env bash
# upsert-comment.sh — append-only Bugsnag error comment publisher used by
# CR-track skills. Every invocation POSTs a fresh comment via the Data Access
# API, mirroring code-review-github/scripts/upsert-comment.sh: each CR run owns
# its own self-contained entry instead of editing an earlier one in place.
# Bugsnag renders comment bodies as plain text (no hidden HTML markers), so —
# unlike the GitHub publisher — no invisible per-actor marker is appended; the
# authenticated token already identifies the author.
#
# Usage:
#   upsert-comment.sh <URL|ORG/PROJECT/ERROR_ID> <BODY_FILE>
#   <body-producer> | upsert-comment.sh <URL|ORG/PROJECT/ERROR_ID> -
#
# Inputs:
#   URL|TRIPLE  an app.bugsnag.com error URL, or <org-slug>/<project-slug>/<error-id>
#   BODY_FILE   path to a file holding the comment body, or `-` to read stdin.
#
# Auth:
#   Reads a Data Access API token from BUGSNAG_TOKEN (BUGSNAG_AUTH_TOKEN alias).
#   Never read from a file, never written anywhere by this script. The shared
#   parse / HTTP / slug-resolution helpers live in _lib.sh alongside this script.
#
# Output:
#   The created comment id on stdout. `action=created` on stderr for the calling
#   skill to log in its summary line.
#
# Audit trail:
#   The POST appends its own line to the run's `.audit` file at the moment it
#   happens, through `skills/_shared/audit-trail.sh record`: an `external-write`
#   line with the error reference and the new comment id, or a `note` line for a
#   failed attempt, both with the acting role and the real exit status. The
#   ledger comes from AUDIT_TRAIL_FILE and AUDIT_TRAIL_ROLE; a missing ledger
#   prints a hint on stderr and never changes the output or the exit code.
#
# Exit codes:
#   1  usage / argument error
#   2  missing required tool (curl, jq) or missing BUGSNAG_TOKEN
#   3  Bugsnag API call failed
set -euo pipefail

PROG="${0##*/}"
# shellcheck source=_lib.sh
source "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/_lib.sh"
AUDIT_TRAIL="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/../../_shared/audit-trail.sh"

usage() {
  cat >&2 <<'EOF'
Usage: upsert-comment.sh <URL|ORG/PROJECT/ERROR_ID> <BODY_FILE|->

  URL|TRIPLE  app.bugsnag.com error URL, or <org-slug>/<project-slug>/<error-id>
  BODY_FILE   path to a file containing the comment body, or `-` for stdin

Auth: export BUGSNAG_TOKEN with a Data Access API token.
EOF
}

if [[ $# -ne 2 || -z "${1:-}" || -z "${2:-}" ]]; then
  usage
  exit 1
fi

INPUT="$1"
BODY_SRC="$2"

bsnag_require_tools
bsnag_require_token

parsed="$(bsnag_parse_ref "$INPUT")" || exit 1
ORG_SLUG="$(printf '%s' "$parsed" | awk '{print $1}')"
PROJ_SLUG="$(printf '%s' "$parsed" | awk '{print $2}')"
ERROR_ID="$(printf '%s' "$parsed" | awk '{print $3}')"

if [[ "$BODY_SRC" == "-" ]]; then
  BODY="$(cat)"
else
  if [[ ! -r "$BODY_SRC" ]]; then
    echo "${PROG}: cannot read body file: $BODY_SRC" >&2
    exit 1
  fi
  BODY="$(cat "$BODY_SRC")"
fi
if [[ -z "$BODY" ]]; then
  echo "${PROG}: refusing to publish an empty comment" >&2
  exit 1
fi

# --- resolve org id -> project id (slugs are not API keys) ------------------
ORG_ID="$(bsnag_resolve_org_id "$ORG_SLUG")"
PROJ_ID="$(bsnag_resolve_project_json "$ORG_ID" "$PROJ_SLUG" | jq -r '.id')"

# --- POST a fresh comment ---------------------------------------------------
POST_RC=0
RESPONSE="$(jq -n --arg message "$BODY" '{message:$message}' | curl -sS -w $'\n%{http_code}' \
  -X POST \
  -H "Authorization: token ${TOKEN}" -H "X-Version: 2" -H "Content-Type: application/json" \
  --data @- \
  "${API}/projects/${PROJ_ID}/errors/${ERROR_ID}/comments")" || POST_RC=$?
if [[ "$POST_RC" -ne 0 ]]; then
  bash "$AUDIT_TRAIL" record --target "bugsnag comment on ${ORG_SLUG}/${PROJ_SLUG}/${ERROR_ID}" --status "$POST_RC" || true
  exit "$POST_RC"
fi
HTTP="${RESPONSE##*$'\n'}"
BODY_OUT="${RESPONSE%$'\n'*}"
if [[ "$HTTP" -lt 200 || "$HTTP" -ge 300 ]]; then
  bash "$AUDIT_TRAIL" record --target "bugsnag comment on ${ORG_SLUG}/${PROJ_SLUG}/${ERROR_ID}" --status 3 --detail "HTTP $HTTP" || true
  echo "${PROG}: comment POST failed (HTTP $HTTP) on ${ORG_SLUG}/${PROJ_SLUG}/${ERROR_ID}" >&2
  exit 3
fi
NEW_ID="$(printf '%s' "$BODY_OUT" | jq -r '.id // empty')"
bash "$AUDIT_TRAIL" record --target "bugsnag comment ${NEW_ID} on ${ORG_SLUG}/${PROJ_SLUG}/${ERROR_ID}" --status 0 --detail created || true
printf '%s\n' "$NEW_ID"
echo "action=created id=${NEW_ID}" >&2
