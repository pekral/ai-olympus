#!/usr/bin/env bash
# transition-to-ready-to-deploy.sh — move a JIRA issue to the project's ready-to-deploy status,
# and nothing else.
#
# Status transitions are otherwise human-only (rules/jira/general.md). This script is one of
# three sanctioned exceptions, and it runs only on the operator's own word in the chat of the
# session (rules/compound-engineering/tracker.md *Ready to deploy on the operator's word*). It
# can ONLY land an issue in a ready-to-deploy status. It never sets a resolution and never lands
# a finished status, so a task marked ready to deploy is never reported done or deployed.
#
# Usage:
#   transition-to-ready-to-deploy.sh <KEY|URL> [<STATUS>]
#
# Inputs:
#   KEY|URL  Bare JIRA key (e.g. ACME-1234), a /browse/<KEY> URL, or any URL
#            containing ?selectedIssue=<KEY>.
#   STATUS   Optional exact target status name. Resolution order:
#              1. this argument
#              2. $JIRA_READY_TO_DEPLOY_STATUS
#              3. default "Ready to Deploy"
#
# Ready-to-deploy guard (the deny list wins over every allowed name):
#   1. Refused when the target contains one of: done, closed, resolved, deployed, released,
#      cancel, merge, hotovo, nasazeno, review, progress.
#   2. Otherwise accepted when it equals a built-in name (Ready to Deploy, Ready for Deploy,
#      Připraveno k nasazení, diacritics optional) or an entry of $JIRA_READY_TO_DEPLOY_SYNONYMS
#      (comma-separated), case-insensitively.
#   Anything else is refused with exit 1 and the issue is left untouched.
#
# Behavior:
#   1. Normalise the KEY and validate the target against the guard.
#   2. Read the issue via load-issue.sh. An issue that carries a resolution, or that sits in a
#      finished status (one containing done, closed, resolved, deployed, released, cancel,
#      merged, hotovo, or nasazeno), exits 4 with no transition. An issue already in the target
#      status is an idempotent no-op.
#   3. Run `acli jira workitem transition --key <KEY> --status <target> --yes`.
#   4. Re-read the status and the resolution. Success only when the status equals the target and
#      no resolution is set.
#
# acli shows no workflow post-function or automation rule. The re-read of the resolution is the
# one check that a transition did not finish the issue.
#
# Output:
#   The issue URL on stdout. `action=transitioned|noop` plus the resolved status on stderr.
#
# Audit trail:
#   The transition appends its own line to the run's `.audit` file at the moment it happens,
#   through `skills/_shared/audit-trail.sh record`. The ledger comes from AUDIT_TRAIL_FILE and
#   AUDIT_TRAIL_ROLE; a missing ledger prints a hint on stderr and changes nothing else.
#
# Exit codes:
#   1  usage / argument error, or refused target (not a ready-to-deploy status)
#   2  missing required tool (acli, jq)
#   3  JIRA API call failed (read or transition, for reasons other than 4/5)
#   4  the issue is finished or carries a resolution — before the transition, or after it
#   5  target status not available in this project — discover via MCP / ask
set -euo pipefail

usage() {
  cat >&2 <<'EOF'
Usage: transition-to-ready-to-deploy.sh <KEY|URL> [<STATUS>]

  KEY     JIRA issue key (e.g. ACME-1234)
  URL     /browse/<KEY> URL or any URL containing ?selectedIssue=<KEY>
  STATUS  optional exact target status name (default: $JIRA_READY_TO_DEPLOY_STATUS
          or "Ready to Deploy"); must pass the ready-to-deploy guard
EOF
}

if [[ $# -lt 1 || $# -gt 2 || -z "${1:-}" ]]; then
  usage
  exit 1
fi

for bin in acli jq; do
  if ! command -v "$bin" >/dev/null 2>&1; then
    echo "transition-to-ready-to-deploy.sh: required tool not found: $bin" >&2
    exit 2
  fi
done

INPUT="$1"
TARGET="${2:-${JIRA_READY_TO_DEPLOY_STATUS:-Ready to Deploy}}"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=jira-write-guards.sh
source "$SCRIPT_DIR/jira-write-guards.sh"

KEY="$(jira_extract_key "$INPUT")"
if [[ -z "$KEY" ]]; then
  echo "transition-to-ready-to-deploy.sh: could not extract JIRA key from input: $INPUT" >&2
  exit 1
fi

lower() { printf '%s' "$1" | tr '[:upper:]' '[:lower:]'; }

# A target must name none of DENIED_WORDS; "merge" also refuses Ready to Merge. An issue whose
# current status names one of FINISHED_WORDS is finished; Code Review and Ready to Merge are not.
DENIED_WORDS=('done' closed resolved deployed released cancel merge hotovo nasazeno review progress)
FINISHED_WORDS=('done' closed resolved deployed released cancel merged hotovo nasazeno)

# $1 = a lower-cased name; the remaining arguments = the words it must not contain.
contains_word() {
  local name="$1" word
  shift
  for word in "$@"; do
    [[ "$name" == *"$word"* ]] && return 0
  done
  return 1
}

target_lower="$(lower "$TARGET")"
if contains_word "$target_lower" "${DENIED_WORDS[@]}"; then
  echo "transition-to-ready-to-deploy.sh: refused — '$TARGET' names a finished, review, or in-progress status. This script only transitions to a ready-to-deploy status (rules/jira/general.md)." >&2
  exit 1
fi

case "$target_lower" in
  'ready to deploy' | 'ready for deploy' | 'připraveno k nasazení' | 'pripraveno k nasazeni') ;;
  *)
    if ! jira_name_in_list "$target_lower" "${JIRA_READY_TO_DEPLOY_SYNONYMS:-}"; then
      echo "transition-to-ready-to-deploy.sh: refused — '$TARGET' is not a ready-to-deploy status. Every other transition is human-only (rules/jira/general.md)." >&2
      exit 1
    fi
    ;;
esac

if ! ISSUE_JSON="$("$SCRIPT_DIR/load-issue.sh" "$KEY")"; then
  echo "transition-to-ready-to-deploy.sh: failed to read the current status of $KEY" >&2
  exit 3
fi
CURRENT_STATUS="$(printf '%s' "$ISSUE_JSON" | jq -r '.status // empty')"
CURRENT_RESOLUTION="$(printf '%s' "$ISSUE_JSON" | jq -r '.resolution // empty')"
ISSUE_URL="$(printf '%s' "$ISSUE_JSON" | jq -r '.url // empty')"

if [[ -n "$CURRENT_RESOLUTION" ]] || contains_word "$(lower "$CURRENT_STATUS")" "${FINISHED_WORDS[@]}"; then
  echo "transition-to-ready-to-deploy.sh: $KEY is already finished ('${CURRENT_STATUS:-?}', resolution '${CURRENT_RESOLUTION:-none}') — no transition; a human owns this issue." >&2
  exit 4
fi

if [[ "$(lower "$CURRENT_STATUS")" == "$target_lower" ]]; then
  echo "${ISSUE_URL:-$KEY}"
  echo "action=noop status=${CURRENT_STATUS} (already ready to deploy)" >&2
  exit 0
fi

TRANSITION_RC=0
TRANSITION_ERR="$(acli jira workitem transition --key "$KEY" --status "$TARGET" --yes 2>&1 >/dev/null)" || TRANSITION_RC=$?
# The audit line is written at the moment of the write, with the real exit status of `acli`.
bash "$SCRIPT_DIR/../../_shared/audit-trail.sh" record --target "jira transition $KEY to $TARGET" --status "$TRANSITION_RC" || true

if [[ "$TRANSITION_RC" -eq 0 ]]; then
  if ! NEW_JSON="$("$SCRIPT_DIR/load-issue.sh" "$KEY" 2>/dev/null)"; then
    echo "transition-to-ready-to-deploy.sh: acli reported success but $KEY could not be read again — not confirmed." >&2
    exit 3
  fi
  NEW_STATUS="$(printf '%s' "$NEW_JSON" | jq -r '.status // empty')"
  NEW_RESOLUTION="$(printf '%s' "$NEW_JSON" | jq -r '.resolution // empty')"
  if [[ -n "$NEW_RESOLUTION" ]]; then
    echo "transition-to-ready-to-deploy.sh: $KEY reached '${NEW_STATUS:-?}' with the resolution '$NEW_RESOLUTION' — the workflow finished the issue; a human must check it." >&2
    exit 4
  fi
  if [[ "$(lower "$NEW_STATUS")" == "$target_lower" ]]; then
    echo "${ISSUE_URL:-$KEY}"
    echo "action=transitioned from=${CURRENT_STATUS:-?} to=${NEW_STATUS}" >&2
    exit 0
  fi
  echo "transition-to-ready-to-deploy.sh: acli reported success but $KEY is still '${NEW_STATUS:-?}', not '$TARGET' — not confirmed." >&2
  exit 5
fi

if grep -qiE 'not (found|available|valid)|no transition|invalid|does not exist' <<<"$TRANSITION_ERR"; then
  echo "transition-to-ready-to-deploy.sh: status '$TARGET' is not available for $KEY (current: ${CURRENT_STATUS:-?}). Discover the real status via JIRA MCP, or ask a human." >&2
  exit 5
fi

echo "transition-to-ready-to-deploy.sh: acli transition failed for $KEY: $TRANSITION_ERR" >&2
exit 3
