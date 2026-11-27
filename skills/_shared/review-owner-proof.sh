#!/usr/bin/env bash
# Read the operator-confirmed identity matches from the same private store as person-match.sh.
# GitHub User and JIRA atlassian account types alone do not establish a human owner.

review_owner_proof() {
  local repo="$1" repo_id="$2" tracker="$3" scope="$4" candidates="$5"
  local state="${XDG_STATE_HOME:-${HOME:-}/.local/state}/ai-olympus" store uid mode actual
  store="$state/person-matches.json"
  [[ "$state" == /* && -d "$state" && ! -L "$state" && -f "$store" && ! -L "$store" ]] || return 1
  actual="$(cd "$state" && pwd -P)" || return 1
  [[ "$actual" == "$state" ]] || return 1
  if env -u GIT_DIR -u GIT_WORK_TREE -u GIT_CEILING_DIRECTORIES     git -C "$state" rev-parse --git-dir >/dev/null 2>&1; then
    return 1
  fi
  uid="$(id -u)"
  mode="$(stat -c '%u:%a' "$state" 2>/dev/null || stat -f '%u:%Lp' "$state")" || return 1
  [[ "$mode" == "$uid:700" ]] || return 1
  mode="$(stat -c '%u:%a' "$store" 2>/dev/null || stat -f '%u:%Lp' "$store")" || return 1
  [[ "$mode" == "$uid:600" ]] || return 1
  jq -er --arg repo "$repo" --arg rid "$repo_id" --arg tracker "$tracker" --arg scope "$scope" --argjson candidates "$candidates" '
    [.matches[] | select((.scope.repository | ascii_downcase) == ($repo | ascii_downcase)
      and .scope.repository_id == $rid and (.confirmed_at | type) == "string")
      | if $tracker == "github" then .github.id
        elif (.scope.site + "/" + .scope.project) == $scope then .jira.account_id else empty end
      | tostring | . as $id | select($candidates | index($id) != null)]
    | unique | select(length > 0) | first' "$store"
}
