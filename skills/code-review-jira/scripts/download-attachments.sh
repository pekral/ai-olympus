#!/usr/bin/env bash
# download-attachments.sh — download every attachment of a JIRA issue (and its
# subtasks) into a quarantine directory and hand them to the mandatory security scan.
#
# Usage:
#   download-attachments.sh <KEY|URL> [--dest DIR]
#
# Auth (JIRA Cloud uses HTTP Basic `email:token`):
#   - API token, resolved in this order (first hit wins):
#       1. --token-file FILE   (file holds the token, or an `email:token` line)
#       2. env JIRA_API_TOKEN
#       3. ~/.config/acli/jira_api_token   (chmod 600)
#   - Account email: env JIRA_API_EMAIL, or the `email:token` form of the token file.
#   The token is never passed in argv and never printed; it is written only into a
#   0600 curl --config file. Without a usable token the script exits non-zero with a
#   setup hint — it never falls back to an unauthenticated or silent attempt.
#
# Attachment endpoint: GET https://<site>/rest/api/3/attachment/content/<id>
# (each attachment's contentUrl from load-issue.sh, which serves it through the site host).
#
# Exit codes:
#   1  usage error
#   2  missing tool / missing or unresolvable auth
#   3  inventory load or download failure
set -euo pipefail

PROG="download-attachments.sh"
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SHARED_DIR="${SCRIPT_DIR}/../../_shared"

usage() {
  cat >&2 <<'EOF'
Usage: download-attachments.sh <KEY|URL> [--dest DIR]

  KEY|URL   JIRA issue key (e.g. ACME-1234) or a /browse/<KEY> URL
  --dest    quarantine root (default: a new private folder under $CLAUDE_SCRATCHPAD_DIR)

Auth:
  JIRA_API_EMAIL   account email for HTTP Basic auth
  JIRA_API_TOKEN   API token (or use --token-file / ~/.config/acli/jira_api_token)
  --token-file F   file holding the token, or an `email:token` line
EOF
}

REF=""
DEST=""
TOKEN_FILE=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    --dest) DEST="${2:-}"; shift 2;;
    --token-file) TOKEN_FILE="${2:-}"; shift 2;;
    -h|--help) usage; exit 1;;
    -*) echo "${PROG}: unknown option: $1" >&2; usage; exit 1;;
    *) if [[ -z "$REF" ]]; then REF="$1"; shift; else echo "${PROG}: unexpected argument: $1" >&2; exit 1; fi;;
  esac
done

if [[ -z "$REF" ]]; then usage; exit 1; fi

# shellcheck source=../../_shared/attachments.sh
. "${SHARED_DIR}/attachments.sh"
att_require_tools

# --- resolve auth token + email without ever exposing them in argv/logs ---
# shellcheck source=jira-rest.sh
. "${SCRIPT_DIR}/jira-rest.sh"
jira_rest_resolve_auth "$TOKEN_FILE" || exit 2

# --- load the attachment inventory via the deterministic loader (issue + subtasks) ---
# stderr suppressed: the loader's own diagnostics are noise; its result is validated on the next line.
ISSUE_JSON="$("${SCRIPT_DIR}/load-issue.sh" "$REF" 2>/dev/null || true)"
if [[ -z "$ISSUE_JSON" ]] || ! printf '%s' "$ISSUE_JSON" | jq -e . >/dev/null 2>&1; then
  echo "${PROG}: failed to load JIRA issue inventory for: $REF" >&2
  exit 3
fi

# The API token goes only to the configured site, never to a host the payload names. The loader
# takes the site from JIRA_SITE or the acli profile, never from the URL argument.
SITE="$(printf '%s' "$ISSUE_JSON" | jq -r '.attachmentSite // empty')"
if [[ -z "$SITE" ]]; then
  echo "${PROG}: no configured JIRA site. Export JIRA_SITE=<site>.atlassian.net or log in with acli." >&2
  exit 2
fi

# `referencedBy` names where each file was pasted: `description`, `comment:<id>`, or the same
# prefixed with the subtask key, so the analysis reads a screenshot next to its comment.
INVENTORY="$(printf '%s' "$ISSUE_JSON" | jq -c --arg site "$SITE" '
  def refs($prefix):
    [ ((.descriptionMediaRefs // [])[] | { attachmentId, where: ($prefix + "description") }),
      ((.comments // [])[] | .id as $cid | (.mediaRefs // [])[] | { attachmentId, where: ($prefix + "comment:" + ($cid | tostring)) }) ];
  ( refs("") + ([ (.subtasks // [])[] | refs(.key + ":") ] | add // []) ) as $refs
  | [ (.attachments // [])[], ((.subtasks // [])[] | (.attachments // [])[]) ]
  | map((.id | tostring) as $id
      | { id: $id, name: .name, declaredMime: .mimeType, size: .size, contentUrl: .contentUrl,
          referencedBy: ([ $refs[] | select(.attachmentId == $id) | .where ] | unique) }
      | if ((.contentUrl // "") | test("^https://" + ($site | gsub("\\."; "\\.")) + "/rest/api/3/attachment/content/[0-9]+$"))
        then .
        else . + { blockReason: ("contentUrl is not on the configured site " + $site + " — the API token is never sent to another host") }
        end)
')"

COUNT="$(printf '%s' "$INVENTORY" | jq 'length')"
if [[ "$COUNT" -eq 0 ]]; then
  echo "${PROG}: no attachments on $REF (nothing to download)." >&2
fi

# --- write the 0600 curl auth config (token stays out of argv) ---
CFG="$(mktemp)"
trap 'rm -f "$CFG"' EXIT
chmod 600 "$CFG"
jira_rest_write_config "$CFG"

att_run "$INVENTORY" "$CFG" "$DEST" "jira" "$REF"
