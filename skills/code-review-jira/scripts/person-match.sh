#!/usr/bin/env bash
# person-match.sh — find the JIRA account of a pull-request author inside the current repository
# and the current JIRA project, and keep the match the operator confirmed
# (rules/compound-engineering/tracker.md *Find a person's tracker account within the current project*).
#
# Usage:
#   person-match.sh propose --pr <PR-URL> --jira-key <KEY>
#   person-match.sh record --proposal <ID>
#   person-match.sh lookup --pr <PR-URL> --jira-key <KEY>
#   person-match.sh forget --pr <PR-URL> --jira-key <KEY>
#
# Only the top-level session runs it. No agent's Bash boundary grants it.
#
# Scope, derived here and never taken from an argument:
#   - the repository: the pull request must belong to the current checkout
#     (skills/_shared/assert-current-repo.sh), and its numeric ID comes from `gh api repos/<o>/<r>`;
#   - the JIRA site: the `Site` line of `acli jira auth status`;
#   - the JIRA project: the prefix of the issue key.
#
# propose:
#   Reads the pull-request author through the GitHub loader, the numeric GitHub user ID
#   (`gh api users/<login>`), the access of that account to the repository, and the committer
#   e-mails of the commits of this repository that GitHub reports verified and whose
#   `committer.id` equals that ID (the commits of the pull request and
#   `repos/<o>/<r>/commits?author=<login>`). An unverified commit, a commit of another committer,
#   a GitHub noreply address, a local commit, the profile e-mail, a display name, and tracker text
#   are never used. For each e-mail it
#   searches the issues of the JIRA project where that e-mail is the assignee or the reporter. A
#   candidate needs exactly one account in all those results, whose visible e-mail equals the
#   query, and no result that hides its e-mail or shows another one. A failed read stops the helper (exit 3), and a JIRA search that returns
#   a full page of 50 issues, or commit lists that fill 10 pages of 100, give no candidate. It never calls `acli jira workitem assign`, a GitHub search, the events
#   or repositories of a user, another repository, or another JIRA project.
#   It writes nothing to a tracker. With a candidate it prints the proposal (exit 0) and writes a
#   pending proposal to the state directory that holds no e-mail and expires after one hour. The
#   proposal is the only output that names the e-mail.
#   Without a candidate it prints what is missing (exit 6).
#
# record --proposal <ID>:
#   Stores the pending proposal as a confirmed match, only when it has not expired and its
#   repository and JIRA site are the current ones. Run it only after the operator confirmed the
#   proposal in the chat of the top-level session.
#
# lookup:
#   Prints `account_id=<JIRA account ID>` of the confirmed match for the current scope and the
#   numeric GitHub user ID of the current pull-request author, only while that JIRA account is still
#   the assignee or the reporter of an issue in the project. Otherwise exit 6.
#
# forget:
#   Removes the confirmed match of the current scope and pull-request author.
#
# The store: ${XDG_STATE_HOME:-$HOME/.local/state}/ai-olympus/person-matches.json, one file per
# user, outside every git work tree, mode 0600 in a 0700 directory. It holds IDs, the login and the
# display name at confirmation, the evidence kinds, and the time; never an e-mail. The helper
# refuses a store, a pending proposal, or a directory that is a symbolic link, is not owned by the
# user, or sits inside a git work tree. No message prints the path.
# Every subcommand creates the state directory and removes an expired pending proposal. These
# three paths are the only writes outside the git toplevel (rules/compound-engineering/orchestration.md
# *Bash capability boundary*): person-matches.json, person-match-pending/, and person-matches.lock.
#
# Audit trail: record and forget append their own line through `skills/_shared/audit-trail.sh
# record` (AUDIT_TRAIL_FILE, AUDIT_TRAIL_ROLE). The target names the pull-request author, never an
# e-mail.
#
# Exit codes:
#   0  done (propose: a proposal; lookup: a match)
#   1  usage / argument error
#   2  missing required tool (acli, gh, jq, git)
#   3  a read failed (the pull request, the GitHub account, the repository, the commits, a JIRA
#      search, the JIRA site, or the store)
#   4  the pull request does not belong to the current repository
#   5  the store is not safe (a symbolic link, another owner, or inside a git work tree)
#   6  no candidate, no pending proposal, or no confirmed match
set -euo pipefail

PROG="person-match.sh"
PENDING_TTL=3600
SEARCH_LIMIT=50
COMMIT_PAGES=10
NOREPLY_RE='@users\.noreply\.github\.com$'
EMAIL_RE='^[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}$'
ACCOUNT_RE='^[A-Za-z0-9:_-]{1,128}$'

usage() {
  cat >&2 <<'EOF'
Usage: person-match.sh propose --pr <PR-URL> --jira-key <KEY>
       person-match.sh record --proposal <ID>
       person-match.sh lookup --pr <PR-URL> --jira-key <KEY>
       person-match.sh forget --pr <PR-URL> --jira-key <KEY>
EOF
}

fail() {
  echo "$PROG: $2" >&2
  exit "$1"
}

SUBCOMMAND="${1:-}"
[[ $# -gt 0 ]] && shift
PR_URL=""
KEY=""
PROPOSAL=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    --pr) PR_URL="${2:-}"; shift 2 || { usage; exit 1; } ;;
    --jira-key) KEY="${2:-}"; shift 2 || { usage; exit 1; } ;;
    --proposal) PROPOSAL="${2:-}"; shift 2 || { usage; exit 1; } ;;
    *) usage; exit 1 ;;
  esac
done

case "$SUBCOMMAND" in
  propose | lookup | forget)
    if [[ -n "$PROPOSAL" || ! "$KEY" =~ ^[A-Z][A-Z0-9_]+-[0-9]+$ \
      || ! "$PR_URL" =~ ^https://github\.com/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)/pull/([0-9]+)$ ]]; then
      usage
      exit 1
    fi
    NWO="${BASH_REMATCH[1]}/${BASH_REMATCH[2]}"
    ;;
  record)
    [[ -z "$PR_URL" && -z "$KEY" && "$PROPOSAL" =~ ^[0-9a-f]{12}$ ]] || { usage; exit 1; }
    ;;
  *) usage; exit 1 ;;
esac

for bin in acli gh jq git; do
  command -v "$bin" >/dev/null 2>&1 || fail 2 "required tool not found: $bin"
done

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SHARED_DIR="$SCRIPT_DIR/../../_shared"
# shellcheck source=jira-write-guards.sh
source "$SCRIPT_DIR/jira-write-guards.sh"

# --- The store: per user, outside every git work tree, owned by the user, never a link. ---

owner_uid() {
  stat -c %u "$1" 2>/dev/null || stat -f %u "$1"
}

inside_work_tree() {
  local probe="$1"
  while [[ ! -e "$probe" ]]; do
    probe="$(dirname "$probe")"
  done
  [[ -d "$probe" ]] || probe="$(dirname "$probe")"
  probe="$(cd "$probe" && pwd -P)"
  [[ "$(env -u GIT_DIR -u GIT_WORK_TREE -u GIT_CEILING_DIRECTORIES git -C "$probe" rev-parse --is-inside-work-tree 2>/dev/null || true)" == true \
    || "$(env -u GIT_DIR -u GIT_WORK_TREE -u GIT_CEILING_DIRECTORIES git -C "$probe" rev-parse --is-inside-git-dir 2>/dev/null || true)" == true ]]
}

safe_path() {
  local path="$1"
  [[ ! -L "$path" ]] || fail 5 "the match store is a symbolic link — refused."
  if [[ -e "$path" && "$(owner_uid "$path")" != "$(id -u)" ]]; then
    fail 5 "the match store is not owned by the current user — refused."
  fi
}

STATE_BASE="${XDG_STATE_HOME:-}"
[[ -n "$STATE_BASE" ]] || STATE_BASE="${HOME:-}/.local/state"
[[ "$STATE_BASE" == /* ]] || fail 5 "the user state directory is not an absolute path — refused."
STATE_DIR="$STATE_BASE/ai-olympus"
STORE="$STATE_DIR/person-matches.json"
PENDING_DIR="$STATE_DIR/person-match-pending"

if inside_work_tree "$STATE_DIR"; then
  fail 5 "the match store would sit inside a git work tree — refused."
fi
safe_path "$STATE_DIR"
safe_path "$PENDING_DIR"
safe_path "$STORE"
(umask 077 && mkdir -p "$PENDING_DIR")
chmod 700 "$STATE_DIR" "$PENDING_DIR"
safe_path "$STATE_DIR"
safe_path "$PENDING_DIR"
if inside_work_tree "$STATE_DIR"; then
  fail 5 "the match store sits inside a git work tree — refused."
fi

write_private() {
  local target="$1" content="$2" tmp
  tmp="$(umask 077 && mktemp "$(dirname "$target")/.write.XXXXXX")"
  printf '%s\n' "$content" >"$tmp"
  chmod 600 "$tmp"
  mv -f "$tmp" "$target"
}

# Sets STORE_JSON. A store that cannot be read, or holds no list of matches, stops the helper.
read_store() {
  STORE_JSON='{"matches":[]}'
  [[ -f "$STORE" ]] || return 0
  STORE_JSON="$(jq -ce 'select(type == "object" and (.matches | type) == "array")' "$STORE" 2>/dev/null)" \
    || fail 3 "the match store could not be read — nothing matched or changed."
}

LOCK="$STATE_DIR/person-matches.lock"
release_lock() { rmdir "$LOCK" 2>/dev/null || true; }
take_lock() {
  local tries=0
  until mkdir "$LOCK" 2>/dev/null; do
    sleep 0.2
    tries=$((tries + 1))
    [[ "$tries" -lt 50 ]] || fail 5 "the match store is locked by another write."
  done
  trap release_lock EXIT
}

audit() {
  bash "$SHARED_DIR/audit-trail.sh" record --target "$1" --status "$2" || true
}

for pending in "$PENDING_DIR"/*.json; do
  [[ -f "$pending" && ! -L "$pending" ]] || continue
  created="$(jq -r '.created_epoch // 0' "$pending" 2>/dev/null || printf '0')"
  [[ "$created" =~ ^[0-9]+$ && $(($(date +%s) - created)) -le "$PENDING_TTL" ]] || rm -f "$pending"
done

# --- The scope: the current repository, the acli JIRA site, the project of the key. ---

current_site() {
  local site
  site="$(jira_auth_status_field "$(acli jira auth status 2>/dev/null || true)" site | tr '[:upper:]' '[:lower:]')"
  site="${site#https://}"
  site="${site%%/*}"
  [[ "$site" =~ ^[a-z0-9.-]+$ ]] || fail 3 "the JIRA site of the acli account could not be read."
  printf '%s' "$site"
}

repository_id() {
  local id
  "$SHARED_DIR/assert-current-repo.sh" "$1" >/dev/null 2>&1 || fail 4 "the pull request does not belong to the current repository — nothing matched."
  id="$(gh api "repos/$1" 2>/dev/null | jq -r '.id // empty' 2>/dev/null || true)"
  [[ "$id" =~ ^[0-9]+$ ]] || fail 3 "the repository ID could not be read."
  printf '%s' "$id"
}

if [[ "$SUBCOMMAND" == record ]]; then
  PENDING="$PENDING_DIR/$PROPOSAL.json"
  safe_path "$PENDING"
  [[ -f "$PENDING" ]] || fail 6 "no pending proposal $PROPOSAL — it expired, or it was never made. Run propose again."
  PENDING_JSON="$(jq -c . "$PENDING" 2>/dev/null)" || fail 6 "the pending proposal $PROPOSAL cannot be read. Run propose again."
  SCOPE_REPO="$(jq -r '.scope.repository // empty' <<<"$PENDING_JSON")"
  [[ "$SCOPE_REPO" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]] || fail 6 "the pending proposal $PROPOSAL is not valid. Run propose again."
  if [[ "$(repository_id "$SCOPE_REPO")" != "$(jq -r '.scope.repository_id' <<<"$PENDING_JSON")" \
    || "$(current_site)" != "$(jq -r '.scope.site' <<<"$PENDING_JSON")" ]]; then
    fail 6 "the pending proposal $PROPOSAL belongs to another repository or JIRA site — nothing recorded."
  fi
  take_lock
  read_store
  STORE_NEW="$(jq -c --argjson p "$PENDING_JSON" --arg at "$(date -u +%Y-%m-%dT%H:%M:%SZ)" '
    .matches |= ([.[] | select((.scope == $p.scope and .github.id == $p.github.id) | not)]
      + [{scope: $p.scope, github: $p.github, jira: $p.jira, evidence: $p.evidence, confirmed_at: $at}])' <<<"$STORE_JSON")"
  write_private "$STORE" "$STORE_NEW"
  rm -f "$PENDING"
  LOGIN="$(jq -r '.github.login' <<<"$PENDING_JSON")"
  audit "person-match record: the confirmed match of the pull-request author $LOGIN ($(jq -r '.scope.repository + " / " + .scope.project' <<<"$PENDING_JSON"))" 0
  echo "recorded: GitHub $LOGIN ↔ JIRA account $(jq -r '.jira.account_id' <<<"$PENDING_JSON") for $(jq -r '.scope.repository + " / " + .scope.site + " / " + .scope.project' <<<"$PENDING_JSON")"
  exit 0
fi

SITE="$(current_site)"
REPO_ID="$(repository_id "$NWO")"
PROJECT="${KEY%-*}"

if ! PR_JSON="$("$SCRIPT_DIR/../../code-review-github/scripts/load-issue.sh" "$PR_URL" 2>/dev/null)" \
  || [[ "$(jq -r '.kind' <<<"$PR_JSON")" != pr ]]; then
  fail 3 "the pull request could not be read."
fi
LOGIN="$(jq -r '.author // empty' <<<"$PR_JSON")"
ASSOCIATION="$(jq -r '.authorAssociation // "not available"' <<<"$PR_JSON")"
PR_NUMBER="$(jq -r '.number' <<<"$PR_JSON")"
if github_login_is_bot "$LOGIN"; then
  fail 6 "the pull-request author is a bot account — nothing matched."
fi
[[ "$LOGIN" =~ ^[A-Za-z0-9-]{1,39}$ ]] || fail 3 "the pull-request author is not a GitHub login."
GITHUB_ID="$(gh api "users/$LOGIN" 2>/dev/null | jq -r '.id // empty' 2>/dev/null || true)"
[[ "$GITHUB_ID" =~ ^[0-9]+$ ]] || fail 3 "the GitHub user ID of the pull-request author could not be read."

SCOPE="$(jq -cn --arg rid "$REPO_ID" --arg repo "$NWO" --arg site "$SITE" --arg project "$PROJECT" \
  '{repository_id: $rid, repository: $repo, site: $site, project: $project}')"

# The JIRA accounts that are the assignee or the reporter of an issue of the project for one JQL
# user value. Sets PROJECT_ACCOUNTS (one JSON object per account: {account_id, display_name, email,
# issues}) and PROJECT_FULL_PAGE (1 when a search returned a full page). A failed or unreadable
# search stops the helper.
project_accounts() {
  local value="$1" field raw rows="" issues
  PROJECT_FULL_PAGE=0
  for field in assignee reporter; do
    raw="$(acli jira workitem search --jql "project = $PROJECT AND $field = \"$value\" ORDER BY updated DESC" \
      --fields "$field" --limit "$SEARCH_LIMIT" --json 2>/dev/null)" \
      || fail 3 "the JIRA search of project $PROJECT failed — nothing matched."
    issues="$(jq -e '[.. | objects | select(.fields? | type == "object")] | length' <<<"$raw" 2>/dev/null)" \
      || fail 3 "the JIRA search of project $PROJECT could not be read — nothing matched."
    [[ "$issues" -lt "$SEARCH_LIMIT" ]] || PROJECT_FULL_PAGE=1
    rows+="$(jq -c --arg field "$field" '[.. | objects | .fields? | objects | .[$field]? | objects
      | {account_id: (.accountId // ""), display_name: (.displayName // ""), email: (.emailAddress // "")}]
      | .[]' <<<"$raw")"$'\n'
  done
  PROJECT_ACCOUNTS="$(jq -sc 'group_by(.account_id) | map(.[0] + {issues: length}) | .[]' <<<"$rows")" \
    || fail 3 "the JIRA search of project $PROJECT could not be read — nothing matched."
}

if [[ "$SUBCOMMAND" == lookup || "$SUBCOMMAND" == forget ]]; then
  read_store
  ENTRY="$(jq -c --argjson scope "$SCOPE" --arg gid "$GITHUB_ID" \
    'first(.matches[] | select(.scope == $scope and .github.id == $gid)) // empty' <<<"$STORE_JSON")"
  if [[ "$SUBCOMMAND" == forget ]]; then
    [[ -n "$ENTRY" ]] || fail 6 "no confirmed match for the pull-request author in this scope."
    take_lock
    read_store
    write_private "$STORE" "$(jq -c --argjson scope "$SCOPE" --arg gid "$GITHUB_ID" \
      '.matches |= [.[] | select((.scope == $scope and .github.id == $gid) | not)]' <<<"$STORE_JSON")"
    audit "person-match forget: the confirmed match of the pull-request author $LOGIN ($NWO / $PROJECT)" 0
    echo "forgotten: the confirmed match of GitHub $LOGIN for $NWO / $SITE / $PROJECT"
    exit 0
  fi
  [[ -n "$ENTRY" ]] || fail 6 "no confirmed match for the pull-request author in this scope — run propose."
  ACCOUNT_ID="$(jq -r '.jira.account_id' <<<"$ENTRY")"
  [[ "$ACCOUNT_ID" =~ $ACCOUNT_RE ]] || fail 6 "the confirmed match is not valid — run propose again."
  project_accounts "$ACCOUNT_ID"
  if [[ -z "$(jq -r --arg id "$ACCOUNT_ID" 'select(.account_id == $id) | .account_id' <<<"$PROJECT_ACCOUNTS")" ]]; then
    fail 6 "the confirmed JIRA account has no issue left in project $PROJECT — run propose again."
  fi
  echo "account_id=$ACCOUNT_ID"
  exit 0
fi

# --- propose ---

PERMISSION="$(gh api "repos/$NWO/collaborators/$LOGIN/permission" 2>/dev/null | jq -r '.permission // empty' 2>/dev/null || true)"
[[ "$PERMISSION" =~ ^[a-z]+$ ]] || PERMISSION="not available"

# Every page of both commit lists, up to COMMIT_PAGES pages of 100. A failed or unreadable page
# stops the helper, and a list that fills every page gives no candidate.
COMMIT_ROWS=""
COMMITS_FULL=0
for list in "repos/$NWO/pulls/$PR_NUMBER/commits?per_page=100" "repos/$NWO/commits?author=$LOGIN&per_page=100"; do
  page=1
  while :; do
    raw="$(gh api "$list&page=$page" 2>/dev/null)" || fail 3 "the commits of $NWO could not be read — nothing matched."
    page_size="$(jq -e 'if type == "array" then length else error end' <<<"$raw" 2>/dev/null)" \
      || fail 3 "the commits of $NWO could not be read — nothing matched."
    COMMIT_ROWS+="$(jq -r --arg id "$GITHUB_ID" '.[]
      | select(.commit.verification.verified? == true and (.committer.id? | tostring) == $id)
      | .commit.committer.email // empty' <<<"$raw")"$'\n'
    [[ "$page_size" -ge 100 ]] || break
    if [[ "$page" -ge "$COMMIT_PAGES" ]]; then
      COMMITS_FULL=1
      break
    fi
    page=$((page + 1))
  done
done
COMMIT_EMAILS="$(tr '[:upper:]' '[:lower:]' <<<"$COMMIT_ROWS" | { grep -Ev "$NOREPLY_RE" || true; } | { grep -E "$EMAIL_RE" || true; } \
  | sort | uniq -c | awk '{print $2 "|" $1}')"

MISSING=()
ACCOUNTS="[]"
REJECTED=0
HIDDEN=0
FULL_PAGE=0
if [[ "$COMMITS_FULL" -eq 1 ]]; then
  MISSING+=("the commits of $NWO fill $COMMIT_PAGES pages of 100, so the read can miss another e-mail")
fi
if [[ -z "$COMMIT_EMAILS" ]]; then
  MISSING+=("no verified commit of $NWO committed by GitHub user ID $GITHUB_ID carries a public committer e-mail")
else
  while IFS='|' read -r email commits; do
    [[ -n "$email" ]] || continue
    project_accounts "$email"
    [[ "$PROJECT_FULL_PAGE" -eq 0 ]] || FULL_PAGE=1
    while IFS= read -r account; do
      [[ -n "$account" ]] || continue
      shown="$(jq -r '.email | ascii_downcase' <<<"$account")"
      if [[ -z "$shown" ]]; then
        HIDDEN=1
        continue
      fi
      if [[ "$shown" != "$email" ]] || [[ ! "$(jq -r '.account_id' <<<"$account")" =~ $ACCOUNT_RE ]]; then
        REJECTED=1
        continue
      fi
      ACCOUNTS="$(jq -c --argjson a "$account" --arg email "$email" --arg commits "$commits" \
        '. + [$a + {query: $email, commits: ($commits | tonumber)}]' <<<"$ACCOUNTS")"
    done <<<"$PROJECT_ACCOUNTS"
  done <<<"$COMMIT_EMAILS"
fi

DISTINCT="$(jq -r '[.[].account_id] | unique | length' <<<"$ACCOUNTS")"
if [[ -n "$COMMIT_EMAILS" && "$REJECTED" -eq 1 ]]; then
  MISSING+=("a JIRA account that the search returned shows another e-mail than the commit e-mail")
fi
if [[ "$HIDDEN" -eq 1 ]]; then
  MISSING+=("a JIRA account that the search returned hides its e-mail, so it cannot be shown to own the commit e-mail")
fi
if [[ -n "$COMMIT_EMAILS" && "$DISTINCT" -eq 0 && "$REJECTED" -eq 0 && "$HIDDEN" -eq 0 ]]; then
  MISSING+=("no JIRA account of project $PROJECT is the assignee or the reporter of an issue for the commit e-mail")
fi
if [[ "$FULL_PAGE" -eq 1 ]]; then
  MISSING+=("a JIRA search of project $PROJECT returned a full page of $SEARCH_LIMIT issues, so the result can hide another account")
fi
if [[ "$DISTINCT" -gt 1 ]]; then
  MISSING+=("the JIRA search of project $PROJECT returned more than one account")
fi

if [[ "${#MISSING[@]}" -gt 0 ]]; then
  echo "no candidate — $KEY stays unassigned. Missing:"
  printf -- '- %s\n' "${MISSING[@]}"
  echo "- the assignable-user lookup of JIRA is not available through acli, so only the issues of project $PROJECT count"
  exit 6
fi

PROPOSAL="$(printf '%s|%s|%s|%s' "$SCOPE" "$GITHUB_ID" "$(jq -r '.[0].account_id' <<<"$ACCOUNTS")" "$(od -An -N8 -tx1 /dev/urandom | tr -d ' \n')" \
  | { sha256sum 2>/dev/null || shasum -a 256; } | cut -c1-12)"
CREATED="$(date +%s)"
PENDING_JSON="$(jq -cn --arg id "$PROPOSAL" --argjson created "$CREATED" --argjson scope "$SCOPE" \
  --arg gid "$GITHUB_ID" --arg login "$LOGIN" --argjson accounts "$ACCOUNTS" '{
    proposal: $id, created_epoch: $created, scope: $scope,
    github: {id: $gid, login: $login},
    jira: {account_id: $accounts[0].account_id, display_name: $accounts[0].display_name},
    evidence: ["github-author", "github-user-id", "repository-access", "verified-commit-email", "jira-project-issues"]}')"
write_private "$PENDING_DIR/$PROPOSAL.json" "$PENDING_JSON"

jq -rn --argjson p "$PENDING_JSON" --argjson accounts "$ACCOUNTS" --arg pr "$PR_URL" --arg association "$ASSOCIATION" \
  --arg permission "$PERMISSION" --argjson ttl "$PENDING_TTL" '
  def clean: tostring | gsub("[\u0000-\u001f\u007f-\u009f​-‏ -‮⁠-⁩﻿]"; "");
  def fenced: clean as $v
    | ([$v | match("`+"; "g") | .length] | max // 0) as $run
    | ("`" * ([3, $run + 1] | max)) as $fence
    | "\($fence)text\n\($v)\n\($fence)";
  "Proposed match \($p.proposal) for \($p.scope.repository) / \($p.scope.site) / \($p.scope.project) — expires in \($ttl / 60) minutes.",
  "",
  "- GitHub login `\($p.github.login)` — looked up: the author of \($pr).",
  "- GitHub user ID \($p.github.id) — looked up: the GitHub account of that login. The match key is this ID.",
  "- Repository access — looked up: association \($association | clean), permission \($permission | clean).",
  "- E-mail — derived: the committer e-mail of \([$accounts[] | .commits] | max) verified commit(s) of \($p.scope.repository) committed by user ID \($p.github.id).",
  ([$accounts[] | .query] | unique[] | fenced),
  "- JIRA account ID \($p.jira.account_id) — looked up: the assignee or the reporter of \([$accounts[] | .issues] | add) issue(s) of project \($p.scope.project) for that e-mail.",
  "- JIRA display name — looked up: shown by JIRA for that account.",
  ($p.jira.display_name | fenced),
  "",
  "Nothing is assigned or recorded yet. Confirm this match in the chat to record it."'
