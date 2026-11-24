#!/usr/bin/env bash
# mark-ready-to-deploy.sh — mark one pull request and the tasks it links ready to deploy, and hand
# each item back to the pull-request author, on the operator's own word in the chat of the session
# (rules/compound-engineering/tracker.md *Ready to deploy on the operator's word*).
#
# Usage:
#   mark-ready-to-deploy.sh <PR-URL> [--jira-key <KEY>]... [--jira-status <name>] [--email <address>]
#
# Inputs:
#   PR-URL      https://github.com/<owner>/<repo>/pull/<N> of the current repository.
#   --jira-key  A JIRA key the operator named in the chat, or the run's own source item. Repeatable.
#   --jira-status  The exact ready-to-deploy status name the operator named, when the project's
#               column is not called "Ready to Deploy". The helper's guard still applies.
#   --email     The pull-request author's e-mail address, only as the operator stated it in the chat
#               for this assignment. It reaches only assign-to-pr-author.sh and is never printed.
#
# The item set, derived from trusted data only:
#   - the pull request;
#   - its closing issues in the same repository (any other repository: "found, not written");
#   - the --jira-key keys, plus the trailing `(<KEY>)` reference of the pull-request title and the
#     /browse/<KEY> links of its body whose host is the site of `acli jira auth status`, when the
#     author is the operator's `gh` account or holds OWNER, MEMBER, or COLLABORATOR. Every other
#     key-shaped token and link, and every key of another author: "found, not written".
#
# The writes:
#   - GitHub label: the repository label whose name equals one of "ready to deploy", "ready for
#     deploy", "ready-to-deploy", or "připraveno k nasazení" (case-insensitive, exact). A missing
#     label is reported and never created. When a default-branch workflow runs on a `labeled` event
#     and names the label, no label is applied.
#   - GitHub assignee: the pull-request author, added through `--add-assignee`; an existing
#     assignee is never removed, and a bot author gets no write.
#   - JIRA: transition-to-ready-to-deploy.sh, then assign-to-pr-author.sh, one key per call.
#   Every GitHub write runs through `skills/_shared/audit-trail.sh run --` and is followed by a
#   loader re-read. A write the re-read does not show is reported as not done. A failed write never
#   rolls back another one. This script merges nothing, deploys nothing, promotes no Draft, sets no
#   resolution, and writes nothing to Bugsnag.
#
# Output (stdout): one line per item and write, `<item> | <write> | <result>`.
#
# Exit codes:
#   0  every write this run attempted was confirmed by a re-read (skips are reported, not failures)
#   1  usage / argument error
#   2  missing required tool (gh, jq, git)
#   3  the pull request could not be read, or at least one write is not done
#   4  the pull request does not belong to the current repository
set -euo pipefail

PROG="mark-ready-to-deploy.sh"
LABEL_NAMES=('ready to deploy' 'ready for deploy' 'ready-to-deploy' 'připraveno k nasazení')

usage() {
  cat >&2 <<'EOF'
Usage: mark-ready-to-deploy.sh <PR-URL> [--jira-key <KEY>]... [--jira-status <name>] [--email <address>]
EOF
}

PR_URL="${1:-}"
[[ $# -gt 0 ]] && shift
JIRA_KEYS=()
JIRA_STATUS=()
EMAIL=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    --jira-key)
      [[ "${2:-}" =~ ^[A-Z][A-Z0-9_]+-[0-9]+$ ]] || { usage; exit 1; }
      JIRA_KEYS+=("$2")
      shift 2
      ;;
    --jira-status) [[ -n "${2:-}" ]] || { usage; exit 1; }; JIRA_STATUS=("$2"); shift 2 ;;
    --email) EMAIL="${2:-}"; shift 2 || { usage; exit 1; } ;;
    *) usage; exit 1 ;;
  esac
done

if [[ ! "$PR_URL" =~ ^https://github\.com/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)/pull/([0-9]+)$ ]]; then
  usage
  exit 1
fi
NWO="${BASH_REMATCH[1]}/${BASH_REMATCH[2]}"

for bin in gh jq git; do
  if ! command -v "$bin" >/dev/null 2>&1; then
    echo "$PROG: required tool not found: $bin" >&2
    exit 2
  fi
done

SKILLS_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
GH_LOADER="$SKILLS_DIR/code-review-github/scripts/load-issue.sh"
JIRA_DIR="$SKILLS_DIR/code-review-jira/scripts"
AUDIT="$SKILLS_DIR/_shared/audit-trail.sh"
# shellcheck source=../../code-review-jira/scripts/jira-write-guards.sh
source "$JIRA_DIR/jira-write-guards.sh"

if ! "$SKILLS_DIR/_shared/assert-current-repo.sh" "$PR_URL" >/dev/null; then
  echo "$PROG: $PR_URL does not belong to the current repository — nothing written." >&2
  exit 4
fi

if ! PR_JSON="$("$GH_LOADER" "$PR_URL")" || [[ "$(jq -r '.kind' <<<"$PR_JSON")" != pr ]]; then
  echo "$PROG: could not read the pull request $PR_URL" >&2
  exit 3
fi

lower() { printf '%s' "$1" | tr '[:upper:]' '[:lower:]'; }

AUTHOR="$(jq -r '.author // empty' <<<"$PR_JSON")"
ASSOCIATION="$(jq -r '.authorAssociation // empty' <<<"$PR_JSON")"
IS_BOT=false
github_login_is_bot "$AUTHOR" && IS_BOT=true
TRUSTED=false
if github_login_is_operator "$AUTHOR" \
  || [[ " OWNER MEMBER COLLABORATOR " == *" $ASSOCIATION "* && -n "$ASSOCIATION" ]]; then
  TRUSTED=true
fi

REPORT=()
NOT_DONE=0
report() { REPORT+=("$1 | $2 | $3"); }

# --- The label: exact name, never created, never applied when a workflow reacts to it. ---
LABEL=""
LABEL_SKIP=""
if ! REPO_LABELS="$(gh label list --repo "$NWO" --limit 1000 --json name --jq '.[].name' 2>/dev/null)"; then
  LABEL_SKIP="not done — the repository labels could not be read"
  NOT_DONE=1
else
  while IFS= read -r name; do
    for candidate in "${LABEL_NAMES[@]}"; do
      if [[ -n "$name" && "$(lower "$name")" == "$candidate" ]]; then
        LABEL="$name"
        break 2
      fi
    done
  done <<<"$REPO_LABELS"
  [[ -n "$LABEL" ]] || LABEL_SKIP="missing — the repository has no ready-to-deploy label, and none was created"
fi

if [[ -n "$LABEL" ]]; then
  DEFAULT_BRANCH="$(git symbolic-ref --short refs/remotes/origin/HEAD 2>/dev/null | sed 's@^origin/@@' || true)"
  if [[ -z "$DEFAULT_BRANCH" ]] || ! WORKFLOWS="$(git ls-tree -r --name-only "origin/$DEFAULT_BRANCH" -- .github/workflows 2>/dev/null)"; then
    LABEL_SKIP="not applied — the default-branch workflows could not be read"
  else
    while IFS= read -r workflow; do
      [[ "$workflow" == *.yml || "$workflow" == *.yaml ]] || continue
      content="$(git show "origin/$DEFAULT_BRANCH:$workflow" 2>/dev/null || true)"
      if grep -qiE '(^|[^[:alnum:]_])labeled([^[:alnum:]_]|$)' <<<"$content" && grep -qiF -- "$LABEL" <<<"$content"; then
        LABEL_SKIP="not applied — the default-branch workflow $workflow runs on a labeled event that names it; the operator decides"
        break
      fi
    done <<<"$WORKFLOWS"
  fi
fi

# jq tests over a loader payload; `$label` and `$author` are jq variables, not shell ones.
# shellcheck disable=SC2016
HAS_LABEL='.labels | index($label) != null'
# shellcheck disable=SC2016
HAS_AUTHOR='.assignees | index($author) != null'

# $1 = item URL, $2 = jq test over the loader payload. Exit 0 when the re-read shows it.
reread_shows() {
  local json
  json="$("$GH_LOADER" "$1" 2>/dev/null)" || return 1
  jq -e --arg label "$LABEL" --arg author "$AUTHOR" "$2" <<<"$json" >/dev/null
}

# $1 = item URL, $2 = pr|issue, $3 = number.
write_github_item() {
  local url="$1" kind="$2" number="$3" json rc
  if ! json="$("$GH_LOADER" "$url" 2>/dev/null)"; then
    report "$url" "label and assignee" "not done — the item could not be read"
    NOT_DONE=1
    return 0
  fi

  if [[ -n "$LABEL_SKIP" ]]; then
    report "$url" "label" "$LABEL_SKIP"
  elif jq -e --arg label "$LABEL" "$HAS_LABEL" <<<"$json" >/dev/null; then
    report "$url" "label \"$LABEL\"" "already present"
  else
    rc=0
    bash "$AUDIT" run -- gh "$kind" edit "$number" --repo "$NWO" --add-label "$LABEL" >/dev/null 2>&1 || rc=$?
    if reread_shows "$url" "$HAS_LABEL"; then
      report "$url" "label \"$LABEL\"" "applied"
    else
      report "$url" "label \"$LABEL\"" "not done — the re-read does not show it (write exit $rc)"
      NOT_DONE=1
    fi
  fi

  if [[ "$IS_BOT" == true ]]; then
    report "$url" "assignee" "unchanged — the pull-request author is a bot account"
  elif jq -e --arg author "$AUTHOR" "$HAS_AUTHOR" <<<"$json" >/dev/null; then
    report "$url" "assignee $AUTHOR" "already assigned"
  else
    rc=0
    bash "$AUDIT" run -- gh "$kind" edit "$number" --repo "$NWO" --add-assignee "$AUTHOR" >/dev/null 2>&1 || rc=$?
    if reread_shows "$url" "$HAS_AUTHOR"; then
      report "$url" "assignee $AUTHOR" "assigned"
    else
      report "$url" "assignee $AUTHOR" "not done — the re-read does not show it (write exit $rc)"
      NOT_DONE=1
    fi
  fi
}

write_github_item "$PR_URL" pr "${PR_URL##*/}"

SAME_REPO_PREFIX="$(lower "https://github.com/$NWO/issues/")"
while IFS= read -r issue_url; do
  [[ -n "$issue_url" ]] || continue
  issue_number="${issue_url##*/}"
  if [[ "$(lower "$issue_url")" == "$SAME_REPO_PREFIX$issue_number" && "$issue_number" =~ ^[0-9]+$ ]]; then
    write_github_item "$issue_url" issue "$issue_number" </dev/null
  else
    report "$issue_url" "-" "found, not written — another repository"
  fi
done < <(jq -r '.closingIssues[]?.url // empty' <<<"$PR_JSON")

# --- JIRA: the operator's keys, plus the trailing title key and the JIRA-site links of a trusted author. ---
JIRA_SITE_HOST="$(lower "$(jira_auth_status_field "$(acli jira auth status 2>/dev/null || true)" site | sed -E 's#^https?://##; s#/.*$##')")"
TITLE="$(jq -r '.title // ""' <<<"$PR_JSON")"
TITLE_KEY="$(sed -nE 's/.*\(([A-Z][A-Z0-9_]+-[0-9]+)\)[[:space:]]*$/\1/p' <<<"$TITLE")"
ELIGIBLE_KEYS=()
FOUND_KEYS=()
while IFS= read -r key; do
  [[ -n "$key" ]] || continue
  if [[ "$key" == "$TITLE_KEY" ]]; then
    ELIGIBLE_KEYS+=("$key")
  else
    FOUND_KEYS+=("$key|not the trailing tracker reference of the title")
  fi
done < <(grep -oE '[A-Z][A-Z0-9_]+-[0-9]+' <<<"$TITLE" || true)
while IFS= read -r link; do
  [[ -n "$link" ]] || continue
  key="${link##*/browse/}"
  host="$(lower "$(sed -nE 's#^https?://([^/:@]+)(:[0-9]+)?/browse/[A-Z][A-Z0-9_]+-[0-9]+$#\1#p' <<<"$link")")"
  if [[ -n "$JIRA_SITE_HOST" && "$host" == "$JIRA_SITE_HOST" ]]; then
    ELIGIBLE_KEYS+=("$key")
  else
    FOUND_KEYS+=("$key|the link is not on the JIRA site of the acli account")
  fi
done < <(jq -r '.body // ""' <<<"$PR_JSON" | grep -oE '[^[:space:]()<>"]*/browse/[A-Z][A-Z0-9_]+-[0-9]+' || true)

while IFS= read -r key; do
  [[ -n "$key" ]] || continue
  if [[ " ${JIRA_KEYS[*]:-} " == *" $key "* ]]; then
    continue
  elif [[ "$TRUSTED" == true ]]; then
    JIRA_KEYS+=("$key")
  else
    report "JIRA $key" "-" "found, not written — the pull-request author is not a trusted author"
  fi
done < <(printf '%s\n' "${ELIGIBLE_KEYS[@]:-}" | sort -u)

while IFS='|' read -r key reason; do
  [[ -n "$key" ]] || continue
  [[ " ${JIRA_KEYS[*]:-} ${ELIGIBLE_KEYS[*]:-} " == *" $key "* ]] && continue
  report "JIRA $key" "-" "found, not written — $reason"
done < <(printf '%s\n' "${FOUND_KEYS[@]:-}" | sort -t'|' -k1,1 -u)

ASSIGN_ARGS=(--pr-author "$AUTHOR")
[[ -z "$EMAIL" ]] || ASSIGN_ARGS+=(--email "$EMAIL")

while IFS= read -r key; do
  [[ -n "$key" ]] || continue
  rc=0
  "$JIRA_DIR/transition-to-ready-to-deploy.sh" "$key" "${JIRA_STATUS[@]:-}" </dev/null >/dev/null || rc=$?
  case "$rc" in
    0) report "JIRA $key" "status" "$("$JIRA_DIR/load-issue.sh" "$key" </dev/null 2>/dev/null | jq -r '.status // "ready to deploy"'), confirmed by a re-read" ;;
    1) report "JIRA $key" "status" "not done — the target is not a ready-to-deploy status" ;;
    4) report "JIRA $key" "status" "not done — the issue is finished or carries a resolution; a human owns it" ;;
    5) report "JIRA $key" "status" "not done — the ready-to-deploy status is not available or did not land" ;;
    *) report "JIRA $key" "status" "not done — the JIRA call failed (exit $rc)" ;;
  esac
  [[ "$rc" -eq 0 ]] || NOT_DONE=1

  if [[ "$IS_BOT" == true ]]; then
    report "JIRA $key" "assignee" "unchanged — the pull-request author is a bot account"
    continue
  fi
  rc=0
  "$JIRA_DIR/assign-to-pr-author.sh" "$key" "${ASSIGN_ARGS[@]}" </dev/null >/dev/null || rc=$?
  case "$rc" in
    0) report "JIRA $key" "assignee" "$("$JIRA_DIR/load-issue.sh" "$key" </dev/null 2>/dev/null | jq -r '.assignee // "the pull-request author"'), confirmed by a re-read" ;;
    6) report "JIRA $key" "assignee" "unchanged — no verified e-mail for the pull-request author" ;;
    *) report "JIRA $key" "assignee" "not done — the assignment failed or was not confirmed (exit $rc)"; NOT_DONE=1 ;;
  esac
done < <(printf '%s\n' "${JIRA_KEYS[@]:-}" | sort -u)

printf '%s\n' "${REPORT[@]}"
[[ "$NOT_DONE" -eq 0 ]] || exit 3
