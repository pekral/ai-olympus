#!/usr/bin/env bash
# assign-to-pr-author.sh — assign one JIRA issue to the author of a pull request, matched by
# e-mail or by the match the operator confirmed, as part of marking the task ready to deploy on the
# operator's word (rules/compound-engineering/tracker.md *Ready to deploy on the operator's word*
# and *Find a person's tracker account within the current project*).
#
# Usage:
#   assign-to-pr-author.sh <KEY> --pr-author <login> [--email <address>]
#   assign-to-pr-author.sh <KEY> --pr-author <login> --pr <PR-URL> --use-confirmed-match
#
# Inputs:
#   KEY          One bare JIRA key matching ^[A-Z][A-Z0-9_]+-[0-9]+$. Never a list, a URL, or JQL.
#   --pr-author  The GitHub login of the pull-request author, from the loader payload.
#   --email      The e-mail address of that author, only when the operator stated it in the chat
#                of the session for this assignment. Never a commit e-mail, a profile e-mail, a
#                display name, a guessed address, or tracker text.
#   --pr --use-confirmed-match
#                The pull request whose author is assigned through the confirmed match of
#                person-match.sh. The helper reads the JIRA account ID from `person-match.sh lookup`
#                itself; no argument carries an account ID. Never together with --email.
#
# Behavior:
#   1. A bot author (`app/` prefix or `[bot]` suffix) gets no write: exit 6.
#   2. When the author is the account `gh api user` reports, and the e-mail of the acli account
#      equals a verified e-mail of that gh account, the acli account is the author: assign with
#      `--assignee "@me"` and verify `assignee = currentUser()`. Neither e-mail is printed.
#   3. Otherwise, with --email: assign with `--assignee <address>` and verify
#      `assignee = "<address>"`.
#   4. Otherwise, with --use-confirmed-match: assign with `--assignee <account ID>` of the confirmed
#      match and verify by a re-read of `assigneeAccountId` through load-issue.sh.
#   5. Otherwise: no write, the assignee stays unchanged, exit 6.
#   An issue that already matches the check is an idempotent no-op. The helper never passes
#   --jql, --filter, --from-file, `default`, or --remove-assignee to `acli jira workitem assign`.
#
# The e-mail address never reaches stdout, stderr, or the audit trail: every message and the audit
# target name "the pull-request author", and no acli output is printed on the e-mail path.
#
# Output:
#   The KEY on stdout. `action=assigned|noop` on stderr.
#
# Audit trail:
#   The assignment appends its own line through `skills/_shared/audit-trail.sh record`, with the
#   real exit status of acli. The ledger comes from AUDIT_TRAIL_FILE and AUDIT_TRAIL_ROLE.
#
# Exit codes:
#   1  usage / argument error (no key, a key list, an invalid login or address)
#   2  missing required tool (acli, jq, gh)
#   3  the assignment failed, the re-read did not confirm it, or `person-match.sh lookup` failed with
#      any exit other than 6 (a failed read, another repository, or an unsafe store)
#   6  no write: a bot author, or no verified e-mail and no confirmed match (`lookup` exit 6) and the
#      author is not the operator's account with a matching acli e-mail
set -euo pipefail

PROG="assign-to-pr-author.sh"

usage() {
  cat >&2 <<'EOF'
Usage: assign-to-pr-author.sh <KEY> --pr-author <login> [--email <address>]
       assign-to-pr-author.sh <KEY> --pr-author <login> --pr <PR-URL> --use-confirmed-match

  KEY          one JIRA issue key (e.g. ACME-1234)
  --pr-author  the GitHub login of the pull-request author
  --email      the author's e-mail address, only as the operator stated it in the chat
  --pr --use-confirmed-match
               assign the confirmed match of person-match.sh for the author of that pull request
EOF
}

KEY="${1:-}"
[[ $# -gt 0 ]] && shift
AUTHOR=""
EMAIL=""
PR_URL=""
CONFIRMED=false
while [[ $# -gt 0 ]]; do
  case "$1" in
    --pr-author) AUTHOR="${2:-}"; shift 2 || { usage; exit 1; } ;;
    --email) EMAIL="${2:-}"; shift 2 || { usage; exit 1; } ;;
    --pr) PR_URL="${2:-}"; shift 2 || { usage; exit 1; } ;;
    --use-confirmed-match) CONFIRMED=true; shift ;;
    *) usage; exit 1 ;;
  esac
done

if [[ ! "$KEY" =~ ^[A-Z][A-Z0-9_]+-[0-9]+$ || -z "$AUTHOR" ]] \
  || [[ "$CONFIRMED" == true && ( -n "$EMAIL" || -z "$PR_URL" ) ]] || [[ "$CONFIRMED" == false && -n "$PR_URL" ]]; then
  usage
  exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=jira-write-guards.sh
source "$SCRIPT_DIR/jira-write-guards.sh"

if github_login_is_bot "$AUTHOR"; then
  echo "$PROG: the pull-request author of $KEY is a bot account — assignee unchanged." >&2
  exit 6
fi

if [[ ! "$AUTHOR" =~ ^[A-Za-z0-9-]{1,39}$ ]]; then
  echo "$PROG: --pr-author is not a GitHub login" >&2
  exit 1
fi

if [[ -n "$EMAIL" && ! "$EMAIL" =~ ^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$ ]]; then
  echo "$PROG: --email is not a valid e-mail address" >&2
  exit 1
fi

for bin in acli jq gh; do
  if ! command -v "$bin" >/dev/null 2>&1; then
    echo "$PROG: required tool not found: $bin" >&2
    exit 2
  fi
done

if github_login_is_operator "$AUTHOR" && jira_account_email_is_operator_email; then
  ASSIGNEE='@me'
  PREDICATE='assignee = currentUser()'
elif [[ -n "$EMAIL" ]]; then
  ASSIGNEE="$EMAIL"
  PREDICATE="assignee = \"$EMAIL\""
elif [[ "$CONFIRMED" == true ]]; then
  MATCH_RC=0
  MATCH="$("$SCRIPT_DIR/person-match.sh" lookup --pr "$PR_URL" --jira-key "$KEY" </dev/null 2>/dev/null)" || MATCH_RC=$?
  ACCOUNT_ID="$(sed -nE 's/^account_id=([A-Za-z0-9:_-]{1,128})$/\1/p' <<<"$MATCH" | head -n 1)"
  if [[ "$MATCH_RC" -eq 6 ]]; then
    echo "$PROG: no confirmed match for the pull-request author of $KEY — assignee unchanged." >&2
    exit 6
  fi
  if [[ "$MATCH_RC" -ne 0 || -z "$ACCOUNT_ID" ]]; then
    echo "$PROG: the confirmed match for the pull-request author of $KEY could not be read (person-match.sh exit $MATCH_RC) — assignee unchanged, not done." >&2
    exit 3
  fi
  assigned_account() {
    "$SCRIPT_DIR/load-issue.sh" "$KEY" </dev/null 2>/dev/null | jq -r '.assigneeAccountId // empty' 2>/dev/null || true
  }
  if [[ "$(assigned_account)" == "$ACCOUNT_ID" ]]; then
    echo "$KEY"
    echo "action=noop key=$KEY assignee=the confirmed match of the pull-request author (already assigned)" >&2
    exit 0
  fi
  ASSIGN_RC=0
  acli jira workitem assign --key "$KEY" --assignee "$ACCOUNT_ID" --yes --json >/dev/null 2>&1 || ASSIGN_RC=$?
  bash "$SCRIPT_DIR/../../_shared/audit-trail.sh" record --target "jira assign $KEY to the confirmed match of the pull-request author" --status "$ASSIGN_RC" || true
  if [[ "$ASSIGN_RC" -ne 0 ]]; then
    echo "$PROG: acli could not assign $KEY to the confirmed match of the pull-request author (exit $ASSIGN_RC)." >&2
    exit 3
  fi
  if [[ "$(assigned_account)" != "$ACCOUNT_ID" ]]; then
    echo "$PROG: acli reported success but the re-read does not show $KEY assigned to the confirmed match — not done." >&2
    exit 3
  fi
  echo "$KEY"
  echo "action=assigned key=$KEY assignee=the confirmed match of the pull-request author" >&2
  exit 0
else
  echo "$PROG: no verified e-mail for the pull-request author of $KEY — assignee unchanged." >&2
  exit 6
fi

MATCH_RC=0
jira_issue_matches_jql "$KEY" "$PREDICATE" || MATCH_RC=$?
if [[ "$MATCH_RC" -eq 0 ]]; then
  echo "$KEY"
  echo "action=noop key=$KEY assignee=the pull-request author (already assigned)" >&2
  exit 0
fi

ASSIGN_RC=0
acli jira workitem assign --key "$KEY" --assignee "$ASSIGNEE" --yes --json >/dev/null 2>&1 || ASSIGN_RC=$?
bash "$SCRIPT_DIR/../../_shared/audit-trail.sh" record --target "jira assign $KEY to the pull-request author" --status "$ASSIGN_RC" || true
if [[ "$ASSIGN_RC" -ne 0 ]]; then
  echo "$PROG: acli could not assign $KEY to the pull-request author (exit $ASSIGN_RC)." >&2
  exit 3
fi

VERIFY_RC=0
jira_issue_matches_jql "$KEY" "$PREDICATE" || VERIFY_RC=$?
if [[ "$VERIFY_RC" -ne 0 ]]; then
  echo "$PROG: acli reported success but the re-read does not show $KEY assigned to the pull-request author — not confirmed." >&2
  exit 3
fi

echo "$KEY"
echo "action=assigned key=$KEY assignee=the pull-request author" >&2
